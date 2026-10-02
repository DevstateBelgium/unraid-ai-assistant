#!/bin/bash
# gap-check.sh - cron every 15 minutes. Pure shell first; Claude only when something looks wrong:
#   - containers in a restart loop (state "restarting", or RestartCount grew since the last check) or unhealthy
#   - new syslog lines (since the previous check, tracked by inode + offset) matching error/crit patterns
#     (kernel I/O, BTRFS/XFS/EXT4, ata, segfault, oom, call trace, hung task), minus known noise
#   - RAM filesystems (/var/log, /, /run, /tmp) nearly full (warn >= LOGFS_WARN_PCT, alert >= 95%) and log floods
#     (/var/log or one file in it grew by more than LOGFS_GROWTH_MB since the last check), with the likely source
# One Claude run explains all new anomalies and produces ONE "AI: ..." notification (event "AI Assistant").
# Dedupe: each anomaly signature is reported at most once per 6 h.
# Noise: lines "- noise: <ERE>" in $MEM_DIR/logs-noise.md (memory) + a built-in list.
# Test overrides: AIA_SYSLOG (default /var/log/syslog), AIA_DOCKER_BIN, AIA_VARINI, AIA_DF_BIN, AIA_LOGFS_ROOT, plus those of notify-explain.sh.
set -u
LOG_NAME=notify
_D=$(dirname "$(readlink -f "$0")")
AIA_SOURCE_ONLY=1 . "$_D/notify-explain.sh"     # shared helpers (gate, claude runner, answer shaping, notify)

SYSLOG=${AIA_SYSLOG:-/var/log/syslog}
DOCKER=${AIA_DOCKER_BIN:-docker}
MAX_READ=2097152                                  # never scan more than 2 MiB of new syslog per check
MAX_ANOM=15

glog() { log "gap: $*"; }

aia_gate || exit 0
[ "${GAP_CHECKS:-yes}" = yes ] || exit 0
mkdir -p "$RUN_DIR" "$LOG_DIR" 2>/dev/null
exec 9>"$RUN_DIR/gap-check.lock"
flock -n 9 || exit 0

DEDUPE_FILE=$RUN_DIR/gap-dedupe
RESTARTS_FILE=$RUN_DIR/gap-restarts
SYSLOG_POS=$RUN_DIR/gap-syslog
TMP=$(mktemp -d "$RUN_DIR/gap.XXXXXX") || exit 0
trap 'cleanup_out; rm -rf "$TMP"' EXIT

ANOM=()          # human lines for the prompt
SIGS=()          # one signature per anomaly (same index)

SEVS=()          # "warning" or "alert" per anomaly (same index)

add_anom() { ANOM+=("$1"); SIGS+=("$(hash_of "$2")"); SEVS+=("${3:-warning}"); }

# ---- 1. docker -----------------------------------------------------------------------------------
array_started() { [ "$(sed -n 's/^mdState="\{0,1\}\([A-Za-z_]*\)"\{0,1\}.*$/\1/p' "$VAR_INI" 2>/dev/null | head -n1)" = STARTED ]; }

check_docker() {
  if ! array_started; then glog "array not started, docker checks skipped"; return 0; fi
  command -v "$DOCKER" >/dev/null 2>&1 || { glog "docker not installed, docker checks skipped"; return 0; }
  local ids
  ids=$(timeout 30 "$DOCKER" ps -aq 2>/dev/null) || { glog "docker not reachable, docker checks skipped"; return 0; }
  [ -n "$ids" ] || { : > "$RESTARTS_FILE"; return 0; }
  local out
  # name, restart count, state, health ("-" when the container has no healthcheck)
  # shellcheck disable=SC2086
  out=$(timeout 30 "$DOCKER" inspect -f '{{.Name}}|{{.RestartCount}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}' $ids 2>/dev/null) \
    || { glog "docker inspect failed, docker checks skipped"; return 0; }
  declare -A prev=()
  local n c s h
  if [ -f "$RESTARTS_FILE" ]; then while IFS='|' read -r n c; do prev[$n]=$c; done < "$RESTARTS_FILE"; fi
  : > "$RESTARTS_FILE.new"
  while IFS='|' read -r n c s h; do
    n=${n#/}; [ -n "$n" ] || continue
    printf '%s|%s\n' "$n" "$c" >> "$RESTARTS_FILE.new"
    if [ "$s" = restarting ]; then
      add_anom "container '$n' is in a restart loop (state: restarting, restart count $c)" "restarting:$n"
    elif [ -n "${prev[$n]:-}" ] && [[ $c =~ ^[0-9]+$ ]] && [ "$c" -gt "${prev[$n]}" ]; then
      add_anom "container '$n' restarted $((c - prev[$n])) time(s) since the last check (restart count $c, state $s)" "restarted:$n"
    fi
    if [ "$h" = unhealthy ]; then add_anom "container '$n' is unhealthy (healthcheck failing, state $s)" "unhealthy:$n"; fi
  done <<< "$out"
  mv -f "$RESTARTS_FILE.new" "$RESTARTS_FILE"
}

# ---- 2. syslog -----------------------------------------------------------------------------------
SYSLOG_PATTERN='I/O error|blk_update_request|Buffer I/O error|BTRFS[^a-z]*(error|critical|warning: csum)|XFS \(.*\): (Corruption|metadata I/O error|Internal error|xfs_)|EXT4-fs (error|warning)|REISERFS error|ata[0-9.]+: (exception|failed command|error|hard resetting link|link is slow)|SError:|segfault|general protection fault|Out of memory|oom-kill|Killed process|Call Trace|blocked for more than|kernel BUG|Oops:|Machine check|Hardware Error|mce:.*(error|hardware)|Kernel panic|md: .*(read|write) error|md[0-9]+p[0-9]+: .*error|critical medium error|unrecovered read error|NMI|nvidia.*(Xid|fell off)|Unable to handle kernel|task .* blocked|soft lockup|hard LOCKUP|DMAR:.*fault'
BUILTIN_NOISE='ai-assistant|nginx.*(deprecat|\[warn\])|php-fpm.*(deprecat|WARNING)|crond.*(deprecat)|\[ai-assistant'

noise_file() {  # -> $TMP/noise.pat (built-in + user "- noise:" lines; invalid regexes dropped)
  local pat=$TMP/noise.pat line re
  printf '%s\n' "$BUILTIN_NOISE" > "$pat"
  if [ -r "$MEM_DIR/logs-noise.md" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
      [[ $line =~ ^[[:space:]]*-[[:space:]]*noise:[[:space:]]*(.+)$ ]] || continue
      re=${BASH_REMATCH[1]}; re=${re%"${re##*[![:space:]]}"}
      [ -n "$re" ] || continue
      echo x | grep -E -e "$re" >/dev/null 2>&1; [ $? -le 1 ] || { glog "ignoring invalid noise regex: $re"; continue; }
      printf '%s\n' "$re" >> "$pat"
    done < "$MEM_DIR/logs-noise.md"
  fi
  printf '%s' "$pat"
}

check_syslog() {
  [ -r "$SYSLOG" ] || { glog "syslog not readable, skipped"; return 0; }
  local ino size pino= poff= start
  ino=$(stat -c %i "$SYSLOG") size=$(stat -c %s "$SYSLOG")
  SYSLOG_NEW_POS="$ino $size"
  if [ -r "$SYSLOG_POS" ]; then read -r pino poff < "$SYSLOG_POS"; fi
  if [ -z "$pino" ] || ! [[ $poff =~ ^[0-9]+$ ]]; then glog "syslog: no previous position, starting at the end"; return 0; fi
  if [ "$pino" = "$ino" ] && [ "$poff" -le "$size" ]; then start=$poff
  else start=0; glog "syslog rotated or truncated, reading from the start"; fi
  [ "$start" -lt "$size" ] || return 0
  [ $((size - start)) -gt "$MAX_READ" ] && start=$((size - MAX_READ))
  SYSLOG_READ=1
  tail -c +$((start + 1)) "$SYSLOG" | head -c "$MAX_READ" | grep -a -E -i -e "$SYSLOG_PATTERN" 2>/dev/null \
    | grep -a -E -v -f "$(noise_file)" 2>/dev/null > "$TMP/sys.hits"
  [ -s "$TMP/sys.hits" ] || return 0
  # one anomaly per distinct normalised line (no timestamp/host, digits folded)
  local line norm
  declare -A done_=()
  while IFS= read -r line; do
    norm=$(printf '%s' "$line" | sed -E 's/^([A-Z][a-z]{2} +[0-9]+ [0-9:]+|[0-9T:.+-]{19,}) +[^ ]+ +//; s/\[[ 0-9.]+\]//g; s/[0-9]+/#/g' | cut -c1-200)
    [ -n "${done_[$norm]:-}" ] && continue
    done_[$norm]=1
    add_anom "syslog: $(printf '%s' "$line" | cut -c1-300)" "syslog:$norm"
  done < "$TMP/sys.hits"
}

# ---- 3. RAM filesystems and log flood ---------------------------------------------------------------
# Unraid keeps / (rootfs), /run, /tmp and /var/log in RAM. A runaway log (a browser tab looping on a 404 makes nginx
# log every request) fills /var/log in hours and Unraid never warns. Two cheap checks, no Claude involved:
#   - usage: df of the RAM filesystems; >= LOGFS_WARN_PCT (default 80) is a warning, >= 95 an alert
#   - growth: du of /var/log and its 10 biggest files are remembered between checks (inode + size, so a rotated
#     file is not "new"); growth > LOGFS_GROWTH_MB in total, or half of that in one file, is a "log flood".
#     The source is then guessed from the last 2000 lines of the fastest-growing file(s).
# Test overrides: AIA_DF_BIN (default df), AIA_LOGFS_ROOT (prefix for /var/log).
DF=${AIA_DF_BIN:-df}
LOGDIR=${AIA_LOGFS_ROOT:-}/var/log
LOGFS_STATE=$RUN_DIR/gap-logfs
LOGFS_ALERT_PCT=95
LOGFS_WARN=${LOGFS_WARN_PCT:-80};     [[ $LOGFS_WARN =~ ^[0-9]+$ ]]   || LOGFS_WARN=80
LOGFS_GROW=${LOGFS_GROWTH_MB:-10};    [[ $LOGFS_GROW =~ ^[0-9]+$ ]]   || LOGFS_GROW=10
{ [ "$LOGFS_WARN" -ge 50 ] && [ "$LOGFS_WARN" -le 99 ]; } || LOGFS_WARN=80
{ [ "$LOGFS_GROW" -ge 1 ] && [ "$LOGFS_GROW" -le 1000 ]; } || LOGFS_GROW=10
LOGFS_NEW_STATE=0
mb() { printf '%s' $(( ($1 + 524288) / 1048576 )); }

check_logfs_usage() {
  local out fs blocks used avail cap mnt pct level
  declare -A seen_=()
  out=$(timeout 15 "$DF" -P /var/log / /run /tmp 2>/dev/null) || { glog "df failed, filesystem usage check skipped"; return 0; }
  while read -r fs blocks used avail cap mnt; do
    [[ $cap =~ ^([0-9]+)%$ ]] || continue
    pct=${BASH_REMATCH[1]}
    case $mnt in /|/var/log|/run|/tmp) ;; *) continue ;; esac
    [ -n "${seen_[$mnt]:-}" ] && continue          # a /tmp that is not a separate filesystem reports "/"
    seen_[$mnt]=1
    if [ "$pct" -ge "$LOGFS_ALERT_PCT" ]; then level=alert
    elif [ "$pct" -ge "$LOGFS_WARN" ]; then level=warn
    else continue; fi
    [[ $used =~ ^[0-9]+$ && $avail =~ ^[0-9]+$ ]] || { used=0; avail=0; }
    add_anom "RAM filesystem $mnt ($fs) is ${pct}% full ($((used / 1024)) MB used, $((avail / 1024)) MB free), level $level; at 100% logging and services start failing" \
             "logfs:$mnt:$level" "$([ "$level" = alert ] && echo alert || echo warning)"
  done <<< "$out"
}

mask_text() {  # credentials / secrets out of text that came from logs; fails closed
  local r
  r=$(php -r '@include $argv[1]; $s = stream_get_contents(STDIN); echo function_exists("aia_mask") ? aia_mask($s) : "";' -- "$EMHTTP/hooks/hooklib.php" 2>/dev/null)
  if [ -n "$r" ]; then printf '%s' "$r"; else printf '(details withheld: masking unavailable)'; fi
}

LOG_NORM='s/^[A-Z][a-z]{2} +[0-9]+ [0-9:]+ +[^ ]+ +//; s/^[0-9]{4}[-\/][0-9]{2}[-\/][0-9]{2}[ T][0-9:.+-]+ +//; s/\?[^ "'"'"',]+//g; s/\*[0-9]+/*#/g; s/[0-9A-Fa-f]{12,}/#/g; s/[0-9]+/#/g'
US=$'\037'
LOG_QSTRIP='s/\?[^ "'"'"',]+/?.../g'

top_of() { sort | uniq -c | sort -rn | head -n1 | sed -E 's/^ *([0-9]+) /\1\t/'; }   # stdin -> "count<TAB>line"

# describe_source FILE -> SRC_NORM, SRC_TEXT (one line, no query strings, masked)
describe_source() {
  local f=$1 n cnt norm raw t p r c
  SRC_NORM=; SRC_TEXT=
  tail -n 2000 "$f" 2>/dev/null | tr -d '\000' | cut -c1-600 > "$TMP/sample"
  n=$(wc -l < "$TMP/sample"); [ "$n" -gt 0 ] || return 0
  sed -E "$LOG_NORM" "$TMP/sample" | cut -c1-200 > "$TMP/sample.norm"
  t=$(paste -d "$US" "$TMP/sample.norm" "$TMP/sample" | awk -F'\037' '{c[$1]++; if (!($1 in r)) r[$1]=$2}
        END {for (k in c) if (c[k] > m) {m = c[k]; b = k} if (m) printf "%d\037%s\037%s\n", m, b, r[b]}')
  [ -n "$t" ] || return 0
  IFS=$'\037' read -r cnt norm raw <<< "$t"
  SRC_NORM=$norm
  raw=$(printf '%s' "$raw" | sed -E "$LOG_QSTRIP" | cut -c1-250)
  SRC_TEXT="$cnt of the last $n lines are the same line: \"$raw\""
  if [ "$(grep -c 'request: "' "$TMP/sample")" -ge 20 ]; then      # nginx error log (also when it lands in syslog)
    p=$(sed -n -E 's/.*request: "[A-Z]+ ([^ ?"]+)[^"]*".*/\1/p' "$TMP/sample" | cut -c1-200 | top_of)
    r=$(sed -n -E 's/.*referrer: "([^?#"]*)[^"]*".*/\1/p' "$TMP/sample" | cut -c1-200 | top_of)
    c=$(sed -n -E 's/.*client: ([0-9A-Fa-f.:]+).*/\1/p' "$TMP/sample" | top_of)
    SRC_TEXT="$SRC_TEXT; nginx: top request path ${p#*$'\t'} (${p%%$'\t'*}x), top referrer ${r#*$'\t'} (${r%%$'\t'*}x), top client ${c#*$'\t'} (${c%%$'\t'*}x)"
  fi
  SRC_TEXT=$(printf '%s' "$SRC_TEXT" | mask_text)
}

check_logfs_growth() {
  [ -d "$LOGDIR" ] || return 0
  local total tag a b rest pn=0 pmin= ptotal= size ino path base g thr_f grow_kb tflag=0 any=0 nsrc=0
  declare -A pino=()
  total=$(du -sk "$LOGDIR" 2>/dev/null | cut -f1)
  [[ $total =~ ^[0-9]+$ ]] || return 0
  find "$LOGDIR" -type f -printf '%s %i %p\n' 2>/dev/null | sort -rn | head -n 10 > "$TMP/files.cur"
  { printf 'T %s\n' "$total"; sed 's/^/F /' "$TMP/files.cur"; } > "$LOGFS_STATE.new"; LOGFS_NEW_STATE=1
  [ -r "$LOGFS_STATE" ] || { glog "log growth: no previous sizes, baseline recorded"; return 0; }
  while read -r tag a b rest; do
    case $tag in
      T) ptotal=$a ;;
      F) [[ $a =~ ^[0-9]+$ ]] || continue
         pino[$b]=$a; pn=$((pn + 1))
         { [ -z "$pmin" ] || [ "$a" -lt "$pmin" ]; } && pmin=$a ;;
    esac
  done < "$LOGFS_STATE"
  [[ $ptotal =~ ^[0-9]+$ ]] || return 0
  grow_kb=$((total - ptotal)); thr_f=$((LOGFS_GROW * 524288))
  [ "$grow_kb" -gt $((LOGFS_GROW * 1024)) ] && tflag=1
  : > "$TMP/files.grow"
  while read -r size ino path; do
    if [ -n "${pino[$ino]:-}" ]; then base=${pino[$ino]}
    elif [ "$pn" -ge 10 ]; then base=$pmin             # not in the remembered top 10, so no bigger than its smallest entry
    else base=0; fi
    g=$((size - base))
    [ "$g" -gt 0 ] && printf '%s %s %s\n' "$g" "$size" "$path" >> "$TMP/files.grow"
  done < "$TMP/files.cur"
  sort -rn "$TMP/files.grow" -o "$TMP/files.grow"
  glog "log growth: $LOGDIR ${ptotal} KB -> ${total} KB (limits: $LOGFS_GROW MB total, $((thr_f / 1048576)) MB per file)"
  while read -r g size path; do
    if [ "$g" -gt "$thr_f" ] || { [ "$tflag" = 1 ] && [ $((g / 1024 * 4)) -ge "$grow_kb" ]; }; then
      [ "$nsrc" -lt 2 ] || break
      nsrc=$((nsrc + 1)); any=1
      describe_source "$path"
      add_anom "log flood: $LOGDIR grew by $(( (grow_kb + 512) / 1024 )) MB since the last check (now $(( (total + 512) / 1024 )) MB); fastest-growing file $path grew by $(mb "$g") MB (now $(mb "$size") MB). ${SRC_TEXT:-No repeated line found in the sample.}" \
               "loggrowth:${SRC_NORM:-$path}"
    fi
  done < "$TMP/files.grow"
  if [ "$tflag" = 1 ] && [ "$any" = 0 ]; then
    add_anom "log flood: $LOGDIR grew by $(( (grow_kb + 512) / 1024 )) MB since the last check (now $(( (total + 512) / 1024 )) MB), spread over many small files; no single file stands out" "loggrowth:total"
  fi
}

SYSLOG_NEW_POS=; SYSLOG_READ=0
check_docker
check_syslog
check_logfs_usage
check_logfs_growth
commit_pos() {
  [ -n "$SYSLOG_NEW_POS" ] && printf '%s\n' "$SYSLOG_NEW_POS" > "$SYSLOG_POS"
  [ "$LOGFS_NEW_STATE" = 1 ] && mv -f "$LOGFS_STATE.new" "$LOGFS_STATE"
  return 0
}
state_set gap_last:i="$(now)"

if [ "${#ANOM[@]}" -eq 0 ]; then glog "no anomalies"; commit_pos; exit 0; fi

# ---- 3. dedupe per signature ---------------------------------------------------------------------
NEW_A=(); NEW_S=(); NEW_L=()
for i in "${!ANOM[@]}"; do
  if key_recent "$DEDUPE_FILE" "${SIGS[$i]}" "$DEDUPE_TTL"; then glog "skip (reported <6h ago): ${ANOM[$i]:0:120}"
  else NEW_A+=("${ANOM[$i]}"); NEW_S+=("${SIGS[$i]}"); NEW_L+=("${SEVS[$i]}"); fi
done
if [ "${#NEW_A[@]}" -eq 0 ]; then glog "all ${#ANOM[@]} anomaly(ies) already reported within 6h"; commit_pos; exit 0; fi
if [ "${#NEW_A[@]}" -gt "$MAX_ANOM" ]; then
  extra=$(( ${#NEW_A[@]} - MAX_ANOM )); NEW_A=("${NEW_A[@]:0:MAX_ANOM}"); NEW_S=("${NEW_S[@]:0:MAX_ANOM}"); NEW_L=("${NEW_L[@]:0:MAX_ANOM}")
  NEW_A+=("(and $extra more similar anomalies not listed)")
fi

glog "${#NEW_A[@]} new anomaly(ies), asking claude:"
for a in "${NEW_A[@]}"; do glog "  - ${a:0:200}"; done

# ---- 4. one Claude run, one notification -----------------------------------------------------------
list=$(printf -- '- %s\n' "${NEW_A[@]}")
# severity: warning, or alert when a RAM filesystem is >= 95% full. Same ladder as notify-explain.sh (its warning ->
# normal, alert -> warning), shifted one step because this check already is a warning: warning -> warning, alert -> alert.
imp=warning; for s in "${NEW_L[@]}"; do [ "$s" = alert ] && imp=alert; done
prompt=$(render_prompt gapcheck-prompt.md "ANOMALIES=$list" "IMPORTANCE=$imp")
[ -n "$prompt" ] || { glog "fail: prompt template missing"; exit 0; }
t0=$(now)
run_claude "$prompt"; rc=$?
dt=$(( $(now) - t0 ))
# a failed run keeps the syslog position and the signatures unmarked, so the next check retries
if [ "$rc" = 124 ] || [ "$rc" = 137 ]; then
  glog "fail: claude timed out after ${dt}s, no notification sent (will retry next check)"
  log_jsonl kind=gap status=timeout anomalies:i="${#NEW_A[@]}"; exit 0
fi
ans=$(extract_answer "$CLAUDE_OUT" 2>/dev/null)
if [ "$rc" != 0 ] || [ -z "$ans" ]; then
  glog "fail: claude rc=$rc, no usable answer (${dt}s), no notification sent (will retry next check)"
  log_jsonl kind=gap status=failed anomalies:i="${#NEW_A[@]}"; exit 0
fi
a_subj=$(printf '%s' "$ans" | json_get - subject); a_desc=$(printf '%s' "$ans" | json_get - desc)
a_msg=$(printf '%s' "$ans" | json_get - message)
if send_ai_notification "$a_subj" "$a_desc" "$a_msg" "$imp"; then
  for s in "${NEW_S[@]}"; do key_mark "$DEDUPE_FILE" "$s" "$DEDUPE_TTL"; done
  commit_pos
  state_set notify_last:i="$(now)"
  glog "done in ${dt}s: sent 'AI: $a_subj'"
  log_jsonl kind=gap id="gap-$(now)" event="gap-check" subject="${NEW_A[0]:0:200}" importance="$imp" status=explained \
    ai_subject="$a_subj" ai_description="$a_desc" ai_message="$a_msg" ai_importance="$imp" anomalies:i="${#NEW_A[@]}"
else
  glog "fail: notify command failed"
  log_jsonl kind=gap status=notify_failed anomalies:i="${#NEW_A[@]}"
fi
exit 0

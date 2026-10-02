#!/bin/bash
# gap-check.sh - cron every 15 minutes. Pure shell first; Claude only when something looks wrong:
#   - containers in a restart loop (state "restarting", or RestartCount grew since the last check) or unhealthy
#   - new syslog lines (since the previous check, tracked by inode + offset) matching error/crit patterns
#     (kernel I/O, BTRFS/XFS/EXT4, ata, segfault, oom, call trace, hung task), minus known noise
# One Claude run explains all new anomalies and produces ONE "AI: ..." notification (event "AI Assistant").
# Dedupe: each anomaly signature is reported at most once per 6 h.
# Noise: lines "- noise: <ERE>" in $MEM_DIR/logs-noise.md (memory) + a built-in list.
# Test overrides: AIA_SYSLOG (default /var/log/syslog), AIA_DOCKER_BIN, AIA_VARINI, plus those of notify-explain.sh.
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

add_anom() { ANOM+=("$1"); SIGS+=("$(hash_of "$2")"); }

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

SYSLOG_NEW_POS=; SYSLOG_READ=0
check_docker
check_syslog
commit_pos() { [ -n "$SYSLOG_NEW_POS" ] && printf '%s\n' "$SYSLOG_NEW_POS" > "$SYSLOG_POS"; }
state_set gap_last:i="$(now)"

if [ "${#ANOM[@]}" -eq 0 ]; then glog "no anomalies"; commit_pos; exit 0; fi

# ---- 3. dedupe per signature ---------------------------------------------------------------------
NEW_A=(); NEW_S=()
for i in "${!ANOM[@]}"; do
  if key_recent "$DEDUPE_FILE" "${SIGS[$i]}" "$DEDUPE_TTL"; then glog "skip (reported <6h ago): ${ANOM[$i]:0:120}"
  else NEW_A+=("${ANOM[$i]}"); NEW_S+=("${SIGS[$i]}"); fi
done
if [ "${#NEW_A[@]}" -eq 0 ]; then glog "all ${#ANOM[@]} anomaly(ies) already reported within 6h"; commit_pos; exit 0; fi
if [ "${#NEW_A[@]}" -gt "$MAX_ANOM" ]; then
  extra=$(( ${#NEW_A[@]} - MAX_ANOM )); NEW_A=("${NEW_A[@]:0:MAX_ANOM}"); NEW_S=("${NEW_S[@]:0:MAX_ANOM}")
  NEW_A+=("(and $extra more similar anomalies not listed)")
fi

glog "${#NEW_A[@]} new anomaly(ies), asking claude:"
for a in "${NEW_A[@]}"; do glog "  - ${a:0:200}"; done

# ---- 4. one Claude run, one notification -----------------------------------------------------------
list=$(printf -- '- %s\n' "${NEW_A[@]}")
prompt=$(render_prompt gapcheck-prompt.md "ANOMALIES=$list")
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
if send_ai_notification "$a_subj" "$a_desc" "$a_msg" warning; then
  for s in "${NEW_S[@]}"; do key_mark "$DEDUPE_FILE" "$s" "$DEDUPE_TTL"; done
  commit_pos
  state_set notify_last:i="$(now)"
  glog "done in ${dt}s: sent 'AI: $a_subj'"
  log_jsonl kind=gap id="gap-$(now)" event="gap-check" subject="${NEW_A[0]:0:200}" importance=warning status=explained \
    ai_subject="$a_subj" ai_description="$a_desc" ai_message="$a_msg" ai_importance=warning anomalies:i="${#NEW_A[@]}"
else
  glog "fail: notify command failed"
  log_jsonl kind=gap status=notify_failed anomalies:i="${#NEW_A[@]}"
fi
exit 0

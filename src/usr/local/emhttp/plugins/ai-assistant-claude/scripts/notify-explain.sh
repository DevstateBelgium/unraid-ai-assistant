#!/bin/bash
# notify-explain.sh - cron every minute. Explains NEW Unraid warning/alert notifications with a headless,
# read-only Claude run and posts the explanation as a second notification (event "AI Assistant").
#   notify-explain.sh            one pass (flock'ed; exits fast when disabled / not logged in / nothing new)
# The top half of this file is also a small library for gap-check.sh (sourced with AIA_SOURCE_ONLY=1).
# Test overrides: AIA_NOTIFY_DIR (default /tmp/notifications), AIA_NOTIFY_BIN (the `notify` command),
# AIA_DYNAMIX_CFG, AIA_CLAUDE_TIMEOUT (default 180 s).
set -u
LOG_NAME=${LOG_NAME:-notify}
_SELF=$(readlink -f "${BASH_SOURCE[0]}")
. "$(dirname "$_SELF")/common.sh"
load_cfg

NOTIFY_DIR=${AIA_NOTIFY_DIR:-/tmp/notifications}
NOTIFY_BIN=${AIA_NOTIFY_BIN:-/usr/local/emhttp/webGui/scripts/notify}
DYNAMIX_CFG=${AIA_DYNAMIX_CFG:-/boot/config/plugins/dynamix/dynamix.cfg}
NOTIFY_JSONL=$LOG_DIR/notify.jsonl
OWN_EVENT="AI Assistant"
DEDUPE_TTL=21600          # same event+subject (or anomaly signature) within 6 h -> skip
RATE_WINDOW=3600
RATE_MAX=${AIA_RATE_MAX:-6}
STALE_AFTER=7200          # an unseen file older than 2 h is history, not news (seen list was trimmed)

now() { printf '%(%s)T' -1; }

# ---- gate: ENABLED, binary present, logged in (credentials file; no process start) ----------------
aia_gate() {
  [ "${ENABLED:-no}" = yes ] || return 1
  [ -f "$RAM_BIN/claude" ] || return 1
  [ -s "$CONFIG_DIR/.credentials.json" ] || return 1
  return 0
}

# ---- time-stamped key files: "<epoch> <key>" per line -------------------------------------------
key_recent() {  # key_recent FILE KEY TTL -> 0 if KEY was recorded within TTL seconds
  local f=$1 k=$2 ttl=$3 t=$(now) ts key
  [ -r "$f" ] || return 1
  while read -r ts key; do
    [ "$key" = "$k" ] && [ $((t - ts)) -lt "$ttl" ] && return 0
  done < "$f"
  return 1
}
key_mark() {  # key_mark FILE KEY KEEP_SECONDS : append and prune old entries
  local f=$1 k=$2 keep=$3 t=$(now) tmp
  printf '%s %s\n' "$t" "$k" >> "$f"
  tmp=$f.tmp$$
  awk -v t="$t" -v keep="$keep" '$1 + keep >= t' "$f" > "$tmp" 2>/dev/null && mv -f "$tmp" "$f"
  rm -f "$tmp"
}
key_count() {  # key_count FILE WINDOW -> entries newer than WINDOW seconds
  local f=$1 w=$2 t=$(now)
  [ -r "$f" ] || { echo 0; return; }
  awk -v t="$t" -v w="$w" '$1 + w >= t {n++} END {print n+0}' "$f"
}
hash_of() { printf '%s' "$1" | cksum | awk '{print $1 "-" $2}'; }

# ---- claude -----------------------------------------------------------------------------------
resolve_model() {  # SCOUT_MODEL; "inherit"/empty follows MAIN_MODEL (empty = Claude's default, no --model)
  local m=${SCOUT_MODEL:-}
  case "$m" in ''|inherit) m=${MAIN_MODEL:-} ;; esac
  printf '%s' "$m"
}
lang_name() {  # WebGUI locale (dynamix.cfg, e.g. nl_NL) -> language name for the prompt, English if unknown
  local loc code
  loc=$(sed -n 's/^locale="\{0,1\}\([A-Za-z_]*\)"\{0,1\}[[:space:]]*$/\1/p' "$DYNAMIX_CFG" 2>/dev/null | head -n1)
  code=${loc%%_*}
  case "$code" in
    nl) echo Dutch ;; fr) echo French ;; de) echo German ;; es) echo Spanish ;; it) echo Italian ;;
    pt) echo Portuguese ;; pl) echo Polish ;; sv) echo Swedish ;; da) echo Danish ;; no|nb) echo Norwegian ;;
    cs) echo Czech ;; ru) echo Russian ;; tr) echo Turkish ;; hu) echo Hungarian ;; ro) echo Romanian ;;
    *) echo English ;;
  esac
}
CLAUDE_OUT=
cleanup_out() { [ -n "$CLAUDE_OUT" ] && rm -f "$CLAUDE_OUT"; CLAUDE_OUT=; }
trap cleanup_out EXIT

# run_claude PROMPT : headless, read-only (permission-mode dontAsk + the rendered settings.json allow list),
# as RUN_USER, cwd WORK_DIR, hard timeout. stream-json goes to $CLAUDE_OUT. Returns claude's status (124 = timeout).
run_claude() {
  local prompt=$1 cwd=${WORK_DIR:-$RAM/work} model line
  case "$cwd" in /mnt|/mnt/*|'') cwd=$RAM/work ;; esac   # never keep a cwd on /mnt
  [ -s "$CONFIG_DIR/settings.json" ] || { [ -x "$SCRIPTS/render-config.sh" ] && "$SCRIPTS/render-config.sh" >/dev/null 2>&1; }
  prep_ram
  [ -d "$cwd" ] || cwd=/
  local -a args=(-p "$prompt" --output-format stream-json --verbose --permission-mode dontAsk)
  model=$(resolve_model); [ -n "$model" ] && args+=(--model "$model")
  line=$(claude_cmdline "${args[@]}")
  cleanup_out
  CLAUDE_OUT=$(mktemp "$RUN_DIR/claude-out.XXXXXX") || return 1
  ( cd "$cwd" && exec timeout -k 5 "${AIA_CLAUDE_TIMEOUT:-180}" bash -c "$line" </dev/null >"$CLAUDE_OUT" 2>>"$LOG_DIR/notify.err" 9>&- )
}

# extract_answer FILE : the final `result` text of a stream-json run, masked and shaped as JSON
#   {"subject":"<=60 chars","desc":"...","message":"line\nline"}; exit 1 when claude did not answer.
read -r -d '' PHP_ANSWER <<'PHPEOF'
@include $argv[2];
$res = null; $err = false;
foreach (@file($argv[1], FILE_IGNORE_NEW_LINES) ?: array() as $ln) {
    $ev = json_decode($ln, true);
    if (is_array($ev) && isset($ev['type']) && $ev['type'] === 'result') {
        $res = isset($ev['result']) && is_string($ev['result']) ? $ev['result'] : '';
        $err = !empty($ev['is_error']) || (isset($ev['subtype']) && $ev['subtype'] !== 'success');
    }
}
if ($res === null || $err || trim($res) === '') exit(1);
if (function_exists('aia_mask')) $res = aia_mask($res);
$res = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $res);
function clip($s, $n) { return preg_match('/^.{0,' . $n . '}/su', $s, $m) ? $m[0] : substr($s, 0, $n); }
$lines = array();
foreach (preg_split('/\R/u', $res) as $l) {
    $l = trim($l);
    if ($l === '' || strpos($l, '```') === 0) continue;
    $lines[] = $l;
}
if (!$lines) exit(1);
$subject = trim(preg_replace('/^(#+\s*|subject:\s*)/i', '', $lines[0]), " *\t");
$subject = clip($subject, 60);
$rest = array();
foreach (array_slice($lines, 1, 6) as $l) $rest[] = clip(ltrim($l, "-* \t"), 300);
if ($subject === '') exit(1);
echo json_encode(array('subject' => $subject, 'desc' => clip($rest ? $rest[0] : $subject, 200),
                       'message' => clip(implode("\n", $rest), 1200)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
PHPEOF
extract_answer() { php -r "$PHP_ANSWER" -- "$1" "$EMHTTP/hooks/hooklib.php"; }

# send_ai_notification SUBJECT DESC MESSAGE IMPORTANCE [LINK] : our own notification (ignored by this script's scan)
send_ai_notification() {
  local s=$1 d=$2 m=$3 imp=$4 link=${5:-}
  m=${m//$'\n'/\\n}   # notify turns a literal \n into a line break
  local -a a=(-e "$OWN_EVENT" -s "AI: $s" -d "$d" -m "$m" -i "$imp")
  [ -n "$link" ] && a+=(-l "$link")
  "$NOTIFY_BIN" "${a[@]}" >/dev/null 2>&1
}

# log_jsonl KEY=VAL... : one record for the UI (rotated)
log_jsonl() {
  mkdir -p "$LOG_DIR" 2>/dev/null
  json_obj t:i="$(now)" "$@" >> "$NOTIFY_JSONL" 2>/dev/null
  if [ "$(stat -c %s "$NOTIFY_JSONL" 2>/dev/null || echo 0)" -gt 524288 ]; then
    tail -n 200 "$NOTIFY_JSONL" > "$NOTIFY_JSONL.tmp" && mv -f "$NOTIFY_JSONL.tmp" "$NOTIFY_JSONL"
  fi
}

# render_prompt TEMPLATE K=V... (untrusted values are length-limited by the callers)
render_prompt() { local t=$1; shift; php "$LIB_PHP" render "$EMHTTP/templates/$t" "DEVICE_NAME=$DEVICE" "MEMORY_DIR=$MEM_DIR" "LANGUAGE=$(lang_name)" "$@"; }

[ -n "${AIA_SOURCE_ONLY:-}" ] && return 0

# =====================================================================================================
# notify-explain main
# =====================================================================================================
aia_gate || exit 0
[ "${EXPLAIN_NOTIFICATIONS:-yes}" = yes ] || exit 0
mkdir -p "$RUN_DIR" "$LOG_DIR" 2>/dev/null
exec 9>"$RUN_DIR/notify-explain.lock"
flock -n 9 || exit 0

SEEN_FILE=$RUN_DIR/notify-seen
DEDUPE_FILE=$RUN_DIR/notify-dedupe
RATE_FILE=$RUN_DIR/notify-rate
declare -A SEEN=() NEWF=()

first=0
if [ -f "$SEEN_FILE" ]; then
  while IFS= read -r id; do [ -n "$id" ] && SEEN[$id]=1; done < "$SEEN_FILE"
else
  first=1
fi

for f in "$NOTIFY_DIR"/unread/*.notify "$NOTIFY_DIR"/archive/*.notify; do
  [ -f "$f" ] || continue
  id=${f##*/}
  [ -n "${SEEN[$id]:-}" ] && continue
  [ -n "${NEWF[$id]:-}" ] && continue
  NEWF[$id]=$f
done

if [ "$first" = 1 ]; then   # first run: everything that exists is history, never explain it
  : > "$SEEN_FILE"
  for id in "${!NEWF[@]}"; do printf '%s\n' "$id" >> "$SEEN_FILE"; done
  log "first run: marked ${#NEWF[@]} existing notification(s) as seen, nothing explained"
  exit 0
fi
[ "${#NEWF[@]}" -gt 0 ] || exit 0

# oldest first
mapfile -t ORDER < <(for id in "${!NEWF[@]}"; do printf '%s\t%s\n' "$(stat -c %Y "${NEWF[$id]}" 2>/dev/null || echo 0)" "$id"; done | sort -n | cut -f2-)

parse_notify() {  # parse_notify FILE -> N_* variables (value syntax: key="value", \" unescaped)
  N_event= N_subject= N_description= N_importance= N_message= N_link= N_timestamp=
  local line k v
  while IFS= read -r line || [ -n "$line" ]; do
    line=${line%$'\r'}
    [[ $line =~ ^([a-z_]+)=(.*)$ ]] || continue
    k=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}
    v=${v#\"}; v=${v%\"}; v=${v//\\\"/\"}; v=${v//\\\\/\\}
    case $k in
      event) N_event=$v ;; subject) N_subject=$v ;; description) N_description=$v ;;
      importance) N_importance=$v ;; message) N_message=$v ;; link) N_link=$v ;; timestamp) N_timestamp=$v ;;
    esac
  done < "$1"
}
clipn() { local s=${1//$'\n'/ }; printf '%s' "${s:0:$2}"; }

for id in "${ORDER[@]}"; do
  f=${NEWF[$id]}
  printf '%s\n' "$id" >> "$SEEN_FILE"     # mark first: a crash or timeout must never cause a retry loop
  parse_notify "$f"
  tag="[$id]"
  if [ "$N_event" = "$OWN_EVENT" ]; then log "$tag skip: own notification"; continue; fi
  case "$N_importance" in
    warning|alert) ;;
    *) log "$tag skip: importance '${N_importance:-?}' (only warning/alert)"; continue ;;
  esac
  age=$(( $(now) - $(stat -c %Y "$f" 2>/dev/null || now) ))
  if [ "$age" -gt "$STALE_AFTER" ]; then log "$tag skip: stale (${age}s old)"; continue; fi
  dkey=$(hash_of "$(printf '%s|%s' "$N_event" "$N_subject" | tr 'A-Z' 'a-z')")
  if key_recent "$DEDUPE_FILE" "$dkey" "$DEDUPE_TTL"; then log "$tag skip: duplicate of '$N_event' / '$N_subject' within 6h"; continue; fi
  if [ "$(key_count "$RATE_FILE" "$RATE_WINDOW")" -ge "$RATE_MAX" ]; then log "$tag skip: rate limit ($RATE_MAX explanations/hour reached)"; continue; fi

  key_mark "$RATE_FILE" "$id" "$RATE_WINDOW"
  log "$tag explain: $N_importance '$N_event' / '$N_subject'"
  ts_h=$(date -d "@${N_timestamp:-0}" '+%F %T' 2>/dev/null || echo "${N_timestamp:-unknown}")
  prompt=$(render_prompt notify-prompt.md "TIMESTAMP=$ts_h" "EVENT=$(clipn "$N_event" 200)" "IMPORTANCE=$N_importance" \
           "SUBJECT=$(clipn "$N_subject" 300)" "DESCRIPTION=$(clipn "$N_description" 600)" "MESSAGE=$(clipn "$N_message" 1500)")
  [ -n "$prompt" ] || { log "$tag fail: prompt template missing or unreadable"; continue; }
  t0=$(now)
  run_claude "$prompt"; rc=$?
  dt=$(( $(now) - t0 ))
  if [ "$rc" = 124 ] || [ "$rc" = 137 ]; then
    log "$tag fail: claude timed out after ${dt}s, no notification sent"
    log_jsonl id="$id" event="$N_event" subject="$N_subject" importance="$N_importance" status=timeout; continue
  fi
  ans=$(extract_answer "$CLAUDE_OUT" 2>/dev/null)
  if [ "$rc" != 0 ] || [ -z "$ans" ]; then
    log "$tag fail: claude rc=$rc, no usable answer (${dt}s), no notification sent"
    log_jsonl id="$id" event="$N_event" subject="$N_subject" importance="$N_importance" status=failed; continue
  fi
  a_subj=$(printf '%s' "$ans" | json_get - subject); a_desc=$(printf '%s' "$ans" | json_get - desc)
  a_msg=$(printf '%s' "$ans" | json_get - message)
  imp=normal; [ "$N_importance" = alert ] && imp=warning
  if send_ai_notification "$a_subj" "$a_desc" "$a_msg" "$imp" "$N_link"; then
    key_mark "$DEDUPE_FILE" "$dkey" "$DEDUPE_TTL"
    state_set notify_last:i="$(now)"
    log "$tag done in ${dt}s: sent 'AI: $a_subj' ($imp)"
    log_jsonl id="$id" event="$N_event" subject="$N_subject" importance="$N_importance" status=explained \
      ai_subject="$a_subj" ai_description="$a_desc" ai_message="$a_msg" ai_importance="$imp"
  else
    log "$tag fail: notify command failed"
    log_jsonl id="$id" event="$N_event" subject="$N_subject" importance="$N_importance" status=notify_failed
  fi
done

# keep the seen list bounded (last 500 ids)
if [ "$(wc -l < "$SEEN_FILE")" -gt 600 ]; then
  tail -n 500 "$SEEN_FILE" > "$SEEN_FILE.tmp" && mv -f "$SEEN_FILE.tmp" "$SEEN_FILE"
fi
exit 0

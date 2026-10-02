#!/bin/bash
# discover.sh - first-run / re-run discovery of the Unraid server (read-only exploration that builds
# the assistant's memory). CLI (see DESIGN.md):
#   discover.sh preflight
#   discover.sh start [--as-root] [--reason initial|upgrade|manual]
#   discover.sh status
#   discover.sh cancel
# Every command prints ONE line of JSON on stdout. Events: $RAM/logs/discovery.jsonl (raw claude
# stream-json, one event per line) + discovery.t (receive epoch per line, same line numbers).
set -u

SELF="$(readlink -f "$0")"
SELF_DIR="$(dirname "$SELF")"
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"

# ---- paths / config (contract: scripts/common.sh provides load_cfg and AIA_EMHTTP/AIA_FLASH/AIA_RAM) ----
if [ -r "$SELF_DIR/common.sh" ]; then
  # shellcheck disable=SC1091
  . "$SELF_DIR/common.sh"
fi
: "${AIA_EMHTTP:=$(dirname "$SELF_DIR")}"
: "${AIA_FLASH:=/boot/config/plugins/ai-assistant-claude}"
: "${AIA_RAM:=/var/lib/ai-assistant-claude}"
export AIA_EMHTTP AIA_FLASH AIA_RAM

if ! type load_cfg >/dev/null 2>&1; then
  # Fallback (only when common.sh is absent): safe KEY="value" parser, default.cfg then flash cfg.
  load_cfg() {
    local f k v line
    for f in "$AIA_EMHTTP/default.cfg" "$AIA_FLASH/ai-assistant-claude.cfg"; do
      [ -r "$f" ] || continue
      while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        case "$line" in ''|'#'*|';'*) continue ;; esac
        k="${line%%=*}"; v="${line#*=}"
        [[ "$k" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
        v="${v#\"}"; v="${v%\"}"; v="${v#\'}"; v="${v%\'}"
        printf -v "$k" '%s' "$v"
      done < "$f"
    done
  }
fi
load_cfg 2>/dev/null || true
: "${RUN_USER:=root}"; : "${WORK_DIR:=$AIA_RAM/work}"; : "${MAIN_MODEL:=}"; : "${DEVICE_NAME:=}"
: "${DISCOVERY_VERSION:=}"; CLAUDE_TZ="${CFG_TZ:-${TZ:-}}"   # cfg TZ lands in CFG_TZ (see common.sh)
[ -n "$DEVICE_NAME" ] || DEVICE_NAME="$(hostname 2>/dev/null)-assistant"

RUN_DIR="$AIA_RAM/run"; LOG_DIR="$AIA_RAM/logs"; MEM_DIR="$AIA_RAM/memory"
JSONL="$LOG_DIR/discovery.jsonl"; TIMES="$LOG_DIR/discovery.t"; ERRLOG="$LOG_DIR/discovery.err"
PIDFILE="$RUN_DIR/discovery.pid"; LOCKFILE="$RUN_DIR/discovery.lock"; MARKER="$RUN_DIR/discovery.exit"
META="$RUN_DIR/discovery.meta"
CLAUDE_BIN="${AIA_CLAUDE_BIN:-$AIA_RAM/bin/claude}"
UNRAID_VERSION_FILE="${AIA_UNRAID_VERSION_FILE:-/etc/unraid-version}"

# ---- helpers ----
jesc() { # JSON string escape (no surrounding quotes)
  local s="$1"
  s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; s="${s//$'\n'/\\n}"; s="${s//$'\r'/\\r}"; s="${s//$'\t'/\\t}"
  printf '%s' "$s"
}
log() { mkdir -p "$LOG_DIR" 2>/dev/null; printf '%s discover: %s\n' "$(date '+%F %T')" "$*" >> "$LOG_DIR/discover.log" 2>/dev/null; }
unraid_version() { sed -n 's/^version="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$UNRAID_VERSION_FILE" 2>/dev/null | head -n1; }
now() { printf '%(%s)T' -1; }

as_user() { # run a command as RUN_USER (plain exec when root or already that user)
  if [ "$RUN_USER" = root ] || [ "$(id -u)" != 0 ] || [ "$(id -un 2>/dev/null)" = "$RUN_USER" ]; then
    "$@"
  elif command -v runuser >/dev/null 2>&1; then
    runuser -u "$RUN_USER" -- "$@"
  else
    su -s /bin/bash "$RUN_USER" -c "$(printf '%q ' "$@")"
  fi
}

set_cfg_key() { # atomic cfg update: set_cfg_key KEY VALUE (flash cfg, only simple values)
  if type aia_cfg_set >/dev/null 2>&1; then aia_cfg_set "$1" "$2"; return; fi
  if type cfg_set >/dev/null 2>&1; then cfg_set "$1" "$2"; return; fi
  local f="$AIA_FLASH/ai-assistant-claude.cfg" tmp
  [[ "$1" =~ ^[A-Z_][A-Z0-9_]*$ ]] || return 1
  case "$2" in *'"'*|*$'\n'*|*'\'*) return 1 ;; esac
  mkdir -p "$AIA_FLASH" || return 1
  tmp="$(mktemp "$f.XXXXXX")" || return 1
  { [ -r "$f" ] && grep -v "^$1=" "$f"; printf '%s="%s"\n' "$1" "$2"; } > "$tmp" 2>/dev/null
  mv -f "$tmp" "$f"
}

pid_alive() { # pid_alive PID -> is it our discovery runner?
  local p="$1"
  [ -n "$p" ] && [ "$p" -gt 1 ] 2>/dev/null && kill -0 "$p" 2>/dev/null && tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null | grep -q 'discover\.sh'
}
read_pid() { [ -r "$PIDFILE" ] && head -n1 "$PIDFILE" 2>/dev/null | tr -dc '0-9'; }

# ---- preflight ----
cmd_preflight() {
  local missing=() out disk
  id -u "$RUN_USER" >/dev/null 2>&1 || { printf '{"ok":false,"missing":["user %s does not exist"],"run_user":"%s"}\n' "$(jesc "$RUN_USER")" "$(jesc "$RUN_USER")"; return 0; }
  as_user test -S /var/run/docker.sock -a -r /var/run/docker.sock -a -w /var/run/docker.sock 2>/dev/null || {
    # no docker at all is not an access problem
    [ -S /var/run/docker.sock ] && missing+=("docker.sock"); }
  as_user test -r /boot/config -a -x /boot/config 2>/dev/null || missing+=("/boot/config")
  as_user test -r /var/log/syslog 2>/dev/null || missing+=("/var/log/syslog")
  as_user test -r /var/local/emhttp/var.ini 2>/dev/null || missing+=("/var/local/emhttp/var.ini")
  if command -v smartctl >/dev/null 2>&1; then
    disk="$(lsblk -dno NAME,TYPE 2>/dev/null | awk '$2=="disk"{print $1; exit}')"
    if [ -n "$disk" ]; then
      out="$(as_user smartctl -H "/dev/$disk" 2>&1)"
      if printf '%s' "$out" | grep -qiE 'permission denied|operation not permitted|open device.*failed'; then missing+=("smartctl"); fi
    fi
  fi
  if command -v virsh >/dev/null 2>&1; then
    out="$(as_user virsh -c qemu:///system list 2>&1)"
    if printf '%s' "$out" | grep -qiE 'permission denied|authentication failed|polkit|not authorized'; then missing+=("virsh"); fi
  fi
  local list="" m
  for m in "${missing[@]+"${missing[@]}"}"; do list="${list:+$list,}\"$(jesc "$m")\""; done
  if [ "${#missing[@]}" -eq 0 ]; then
    printf '{"ok":true,"missing":[],"run_user":"%s"}\n' "$(jesc "$RUN_USER")"
  else
    printf '{"ok":false,"missing":[%s],"run_user":"%s"}\n' "$list" "$(jesc "$RUN_USER")"
  fi
}

# ---- prompt / allowed tools ----
render_prompt() { # render_prompt REASON PREV_VERSION CUR_VERSION -> stdout
  AIA_P_REASON="$1" AIA_P_PREV="$2" AIA_P_CUR="$3" AIA_P_MEM="$MEM_DIR" AIA_P_WORK="$WORK_DIR" AIA_P_DEV="$DEVICE_NAME" \
  php -r '
    $t = file_get_contents($argv[1]);
    $r = getenv("AIA_P_REASON"); $prev = getenv("AIA_P_PREV"); $cur = getenv("AIA_P_CUR");
    $note = "";
    if ($r === "upgrade") {
      $note = "\n## This is an UPGRADE re-check\nThe previous discovery ran on Unraid " . ($prev !== "" ? $prev : "(unknown)") . "; the server now runs " . $cur . ".\n"
        . "Your existing memory is already in your context (MEMORY.md and the files it indexes). Do NOT redo everything:\n"
        . "launch fewer scouts, only for what an Unraid upgrade may change (Unraid/kernel/docker/libvirt versions, plugin versions and\n"
        . "compatibility, pool/share/network config, new warnings in the syslog, quick SMART health), update only the files that changed,\n"
        . "and record the version change in system.md as a dated line \"YYYY-MM-DD: " . ($prev !== "" ? $prev : "unknown") . " -> " . $cur . "\"\n"
        . "(keep earlier history lines). Update the Unraid version and kernel fields in system.md. Keep MEMORY.md consistent.\n\n";
    } elseif ($r === "manual") {
      $note = "\n## This is a MANUAL re-run\nMemory may already exist (it is in your context). Refresh every topic, correct anything stale or wrong, keep valid details.\n\n";
    }
    $map = array("{{UPGRADE_NOTE}}" => $note, "{{REASON}}" => $r, "{{MEMORY_DIR}}" => getenv("AIA_P_MEM"), "{{WORK_DIR}}" => getenv("AIA_P_WORK"),
                 "{{DEVICE_NAME}}" => getenv("AIA_P_DEV"), "{{EMHTTP}}" => getenv("AIA_EMHTTP"), "{{UNRAID_VERSION}}" => $cur);
    echo strtr($t, $map);
  ' -- "$AIA_EMHTTP/templates/discovery-prompt.md"
}

allowed_tools_nul() { # NUL-separated extra --allowedTools: ONLY the memory-dir edit rule.
  # The read-only allow/ask/deny rules, additionalDirectories and hooks come from the rendered
  # $AIA_RAM/config/settings.json (templates/settings.base.json). A CLI allow rule never overrides an
  # "ask"/"deny" rule from settings, so nothing else is repeated here (repeating the whole allow list
  # would only duplicate it). Edit(//abs/**) also covers Write/MultiEdit; // = absolute path.
  printf '%s\0' "Edit(//$(printf '%s' "$MEM_DIR" | sed 's#^/*##')/**)"
}

# ---- start ----
cmd_start() {
  local as_root=0 reason="initial"
  while [ $# -gt 0 ]; do
    case "$1" in
      --as-root) as_root=1 ;;
      --reason) shift; reason="${1:-initial}" ;;
      --reason=*) reason="${1#--reason=}" ;;
    esac
    shift
  done
  case "$reason" in initial|upgrade|manual) ;; *) printf '{"ok":false,"error":"bad reason"}\n'; return 0 ;; esac
  [ -x "$CLAUDE_BIN" ] || [ -f "$CLAUDE_BIN" ] || { printf '{"ok":false,"error":"claude binary not found at %s"}\n' "$(jesc "$CLAUDE_BIN")"; return 0; }
  id -u "$RUN_USER" >/dev/null 2>&1 || { printf '{"ok":false,"error":"RUN_USER %s does not exist"}\n' "$(jesc "$RUN_USER")"; return 0; }
  [ -r "$AIA_EMHTTP/templates/discovery-prompt.md" ] || { printf '{"ok":false,"error":"discovery-prompt.md missing"}\n'; return 0; }
  mkdir -p "$RUN_DIR" "$LOG_DIR" "$MEM_DIR" 2>/dev/null || { printf '{"ok":false,"error":"cannot create runtime dirs"}\n'; return 0; }

  local p; p="$(read_pid)"
  if pid_alive "$p"; then printf '{"ok":false,"error":"discovery already running","pid":%s}\n' "$p"; return 0; fi
  if ! ( flock -n 9 ) 9>"$LOCKFILE" 2>/dev/null; then printf '{"ok":false,"error":"discovery already running"}\n'; return 0; fi

  rm -f "$PIDFILE" "$MARKER" "$RUN_DIR/discovery.rc"
  [ -s "$JSONL" ] && mv -f "$JSONL" "$JSONL.prev"; : > "$JSONL"; : > "$TIMES"; : > "$ERRLOG"
  chmod 644 "$JSONL" "$TIMES" "$ERRLOG" 2>/dev/null
  printf 'reason=%s\nas_root=%s\nstarted=%s\nrun_user=%s\n' "$reason" "$as_root" "$(now)" "$RUN_USER" > "$META"
  state_set discovery_state=running   # rc.ai-assistant status reads this

  # memory dir must be writable by whoever runs the exploration
  if [ "$as_root" = 0 ] && [ "$RUN_USER" != root ] && [ "$(id -u)" = 0 ]; then
    chown -R "$RUN_USER": "$MEM_DIR" 2>/dev/null
    [ -d "$WORK_DIR" ] && chown "$RUN_USER": "$WORK_DIR" 2>/dev/null
  fi

  setsid bash "$SELF" _run "$reason" "$as_root" </dev/null >>"$LOG_DIR/discover.log" 2>&1 &
  local i
  for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    p="$(read_pid)"; [ -n "$p" ] && break
    [ -e "$MARKER" ] && break
    sleep 0.2
  done
  p="$(read_pid)"
  if [ -n "$p" ] || [ -e "$MARKER" ]; then
    printf '{"ok":true,"pid":%s}\n' "${p:-0}"
  else
    state_set discovery_state=failed
    printf '{"ok":false,"error":"discovery process did not start (see discover.log)"}\n'
  fi
}

# ---- _run (internal; runs detached under setsid) ----
cmd_run() {
  local reason="${1:-initial}" as_root="${2:-0}"
  umask 022
  exec 9>"$LOCKFILE"
  flock -n 9 || { log "another discovery holds the lock"; exit 3; }
  echo "$$" > "$PIDFILE"
  local cancelled=0
  trap 'cancelled=1' TERM INT HUP

  local cur prev cwd home user_cmd=() CMD=() ALLOW=() item
  cur="$(unraid_version)"; prev="$DISCOVERY_VERSION"
  cwd="$WORK_DIR"
  case "$cwd" in /mnt/*|/mnt) cwd="$AIA_RAM/work" ;; esac   # never keep a cwd on /mnt
  mkdir -p "$cwd" 2>/dev/null || cwd=/
  # the read-only permission rules live in the rendered settings.json; make sure it exists (render-config.sh is idempotent)
  if [ ! -s "$AIA_RAM/config/settings.json" ] && [ -x "$SELF_DIR/render-config.sh" ]; then "$SELF_DIR/render-config.sh" >/dev/null 2>&1; fi
  local prompt; prompt="$(render_prompt "$reason" "$prev" "$cur")"
  while IFS= read -r -d '' item; do ALLOW+=("$item"); done < <(allowed_tools_nul)

  local runas="$RUN_USER"; [ "$as_root" = 1 ] && runas=root
  home="$(getent passwd "$runas" 2>/dev/null | cut -d: -f6)"; [ -d "${home:-}" ] || home="$AIA_RAM/work"
  CMD=(env "CLAUDE_CONFIG_DIR=$AIA_RAM/config" "HOME=$home" "DISABLE_AUTOUPDATER=1" "AIA_DISCOVERY=1" "AIA_RAM=$AIA_RAM" "AIA_FLASH=$AIA_FLASH" "AIA_EMHTTP=$AIA_EMHTTP")
  [ -n "$CLAUDE_TZ" ] && CMD+=("TZ=$CLAUDE_TZ")
  CMD+=("$CLAUDE_BIN" -p "$prompt" --output-format stream-json --verbose --permission-mode dontAsk --allowedTools "${ALLOW[@]}")
  [ -n "$MAIN_MODEL" ] && CMD+=(--model "$MAIN_MODEL")

  log "start reason=$reason as=$runas cwd=$cwd model=${MAIN_MODEL:-default} unraid=$cur"
  cd "$cwd" || cd /
  rm -f "$RUN_DIR/discovery.rc"
  (
    if [ "$runas" = root ] || [ "$(id -u)" != 0 ] || [ "$(id -un 2>/dev/null)" = "$runas" ]; then
      "${CMD[@]}" 2>>"$ERRLOG" </dev/null
    elif command -v runuser >/dev/null 2>&1; then
      runuser -u "$runas" -- "${CMD[@]}" 2>>"$ERRLOG" </dev/null
    else
      su -s /bin/bash "$runas" -c "$(printf '%q ' "${CMD[@]}")" 2>>"$ERRLOG" </dev/null
    fi
    echo "$?" > "$RUN_DIR/discovery.rc"
  ) | { while IFS= read -r line || [ -n "$line" ]; do
          printf '%s\n' "$line" >> "$JSONL"; printf '%(%s)T\n' -1 >> "$TIMES"
        done; } &
  local wpid=$!
  while kill -0 "$wpid" 2>/dev/null; do wait "$wpid" 2>/dev/null; done
  wait "$wpid" 2>/dev/null

  local rc=143
  [ -r "$RUN_DIR/discovery.rc" ] && rc="$(tr -dc '0-9' < "$RUN_DIR/discovery.rc")"; [ -n "$rc" ] || rc=1
  # files created by a root-run exploration must belong to RUN_USER again
  if [ "$as_root" = 1 ] && [ "$RUN_USER" != root ] && [ "$(id -u)" = 0 ]; then
    chown -R "$RUN_USER": "$MEM_DIR" "$AIA_RAM/config" 2>/dev/null
    [ -d "$AIA_RAM/work" ] && chown -R "$RUN_USER": "$AIA_RAM/work" 2>/dev/null
  fi
  printf 'rc=%s\nt=%s\ncancelled=%s\n' "$rc" "$(now)" "$cancelled" > "$MARKER"
  rm -f "$PIDFILE"
  local st; st="$(status_state)"
  log "finished rc=$rc cancelled=$cancelled state=$st"
  if [ "$st" = done ]; then state_set discovery_state=done; else state_set discovery_state=failed; fi
  if [ "$st" = done ]; then
    set_cfg_key DISCOVERY_DONE yes && set_cfg_key DISCOVERY_VERSION "$cur" || log "could not update cfg"
    if [ -f "$SELF_DIR/sync-state.sh" ]; then bash "$SELF_DIR/sync-state.sh" >/dev/null 2>&1 || true; fi
  fi
  exit 0
}

# ---- status ----
read -r -d '' PHP_STATUS <<'PHPEOF'
// argv: mode jsonl times errlog shell rc cancelled memdir hooklib reason started finished
$mode = $argv[1]; $jf = $argv[2]; $tf = $argv[3]; $ef = $argv[4]; $shell = $argv[5];
$rc = (int)$argv[6]; $cancelled = $argv[7] === '1'; $mem = rtrim($argv[8], '/'); $hl = $argv[9];
$reason = isset($argv[10]) ? $argv[10] : ''; $started = (int)(isset($argv[11]) ? $argv[11] : 0); $finished = (int)(isset($argv[12]) ? $argv[12] : 0);
@include $hl;
if (!function_exists('aia_mask')) { function aia_mask($s) { return $s; } }
function clip($s, $n) { $s = trim(preg_replace('/\s+/', ' ', (string)$s)); return strlen($s) > $n ? substr($s, 0, $n - 3) . '...' : $s; }
$lines = is_readable($jf) ? file($jf, FILE_IGNORE_NEW_LINES) : array();
$times = is_readable($tf) ? file($tf, FILE_IGNORE_NEW_LINES) : array();
if (!is_array($lines)) $lines = array(); if (!is_array($times)) $times = array();
$steps = array(); $pending = array(); $result = null; $lastT = $started; $denied = 0; $nev = 0; $seenInit = false;
foreach ($lines as $i => $ln) {
    $ev = json_decode($ln, true);
    if (!is_array($ev)) continue;
    $nev++;
    $t = isset($times[$i]) && ctype_digit(trim($times[$i])) ? (int)$times[$i] : $lastT; $lastT = $t;
    $type = isset($ev['type']) ? $ev['type'] : '';
    $parent = isset($ev['parent_tool_use_id']) ? $ev['parent_tool_use_id'] : null;
    if ($type === 'system') {
        $st = isset($ev['subtype']) ? $ev['subtype'] : '';
        if ($st === 'init') {
            // subagents/retries emit their own init events: only the first one is a real "started"
            if ($seenInit || $parent !== null) continue;
            $seenInit = true;
            $steps[] = array('t' => $t, 'kind' => 'text', 'text' => 'Discovery started' . (isset($ev['model']) ? ' (model: ' . $ev['model'] . ')' : ''));
        } elseif ($st === 'permission_denied') {
            $denied++;
            $steps[] = array('t' => $t, 'kind' => 'text', 'text' => 'Denied (read-only run): ' . clip(aia_mask(isset($ev['tool_name']) ? $ev['tool_name'] : json_encode($ev)), 150));
        }
    } elseif ($type === 'assistant' && isset($ev['message']['content']) && is_array($ev['message']['content'])) {
        foreach ($ev['message']['content'] as $b) {
            if (!is_array($b) || !isset($b['type'])) continue;
            if ($b['type'] === 'text') {
                if ($parent !== null) continue;
                $tx = clip(aia_mask(isset($b['text']) ? $b['text'] : ''), 300);
                if ($tx !== '') $steps[] = array('t' => $t, 'kind' => 'text', 'text' => $tx);
            } elseif ($b['type'] === 'tool_use') {
                $name = isset($b['name']) ? $b['name'] : '?'; $in = isset($b['input']) && is_array($b['input']) ? $b['input'] : array();
                $pre = $parent !== null ? '[scout] ' : '';
                if ($name === 'Agent' || $name === 'Task') {
                    $d = isset($in['description']) ? $in['description'] : (isset($in['subagent_type']) ? $in['subagent_type'] : 'subagent');
                    $s = array('t' => $t, 'kind' => 'scout', 'text' => 'Scout: ' . clip(aia_mask($d), 200));
                } elseif ($name === 'Write' || $name === 'Edit' || $name === 'MultiEdit' || $name === 'NotebookEdit') {
                    $p = isset($in['file_path']) ? $in['file_path'] : '';
                    if ($mem !== '' && strpos($p, $mem . '/') === 0) $s = array('t' => $t, 'kind' => 'memory', 'text' => 'Memory updated: ' . substr($p, strlen($mem) + 1));
                    else $s = array('t' => $t, 'kind' => 'tool', 'text' => $pre . 'Editing: ' . clip($p, 150));
                } elseif ($name === 'Bash') {
                    $c = isset($in['command']) ? $in['command'] : '';
                    $s = array('t' => $t, 'kind' => 'tool', 'text' => $pre . 'Running: ' . clip(aia_mask($c), 140));
                } elseif ($name === 'Read') {
                    $s = array('t' => $t, 'kind' => 'tool', 'text' => $pre . 'Reading: ' . clip(isset($in['file_path']) ? $in['file_path'] : '', 150));
                } elseif ($name === 'Grep' || $name === 'Glob') {
                    $s = array('t' => $t, 'kind' => 'tool', 'text' => $pre . 'Searching: ' . clip(aia_mask(isset($in['pattern']) ? $in['pattern'] : ''), 120));
                } else {
                    $s = array('t' => $t, 'kind' => 'tool', 'text' => $pre . 'Using: ' . $name);
                }
                $steps[] = $s;
                if (isset($b['id'])) $pending[$b['id']] = count($steps) - 1;
            }
        }
    } elseif ($type === 'user' && isset($ev['message']['content']) && is_array($ev['message']['content'])) {
        foreach ($ev['message']['content'] as $b) {
            if (!is_array($b) || !isset($b['type']) || $b['type'] !== 'tool_result') continue;
            $id = isset($b['tool_use_id']) ? $b['tool_use_id'] : null;
            if ($id !== null && isset($pending[$id]) && !empty($b['is_error'])) {
                $k = $pending[$id];
                $c = isset($b['content']) ? (is_array($b['content']) ? json_encode($b['content']) : $b['content']) : '';
                if ($steps[$k]['kind'] === 'memory') $steps[$k]['text'] = 'Memory write rejected: ' . substr($steps[$k]['text'], strlen('Memory updated: ')) . ' - ' . clip(aia_mask($c), 120);
                else $steps[$k]['text'] .= ' (failed: ' . clip(aia_mask($c), 100) . ')';
            }
        }
    } elseif ($type === 'result') {
        $result = $ev;
        if (isset($ev['permission_denials']) && is_array($ev['permission_denials'])) $denied = max($denied, count($ev['permission_denials']));
    }
}
$summary = ''; $error = '';
$resErr = false;
if ($result !== null) {
    $summary = isset($result['result']) && is_string($result['result']) ? aia_mask($result['result']) : '';
    $resErr = !empty($result['is_error']) || (isset($result['subtype']) && $result['subtype'] !== 'success');
    if ($denied > 0) $steps[] = array('t' => $lastT, 'kind' => 'text', 'text' => $denied . ' tool call(s) were denied (the exploration is read-only).');
    $steps[] = array('t' => $lastT, 'kind' => 'text', 'text' => 'Discovery ' . ($resErr ? 'ended with an error' : 'finished'));
}
if ($shell === 'running') $state = 'running';
elseif ($shell === 'none') $state = ($nev > 0 && $result !== null && !$resErr) ? 'done' : 'none';
elseif ($shell === 'dead') { $state = 'failed'; $error = 'Discovery process vanished (reboot or crash)'; }
else { // exited
    if ($cancelled) { $state = 'failed'; $error = 'Cancelled by user'; }
    elseif ($rc === 0 && $result !== null && !$resErr) $state = 'done';
    else {
        $state = 'failed';
        if ($result !== null && $resErr) { $error = clip($summary !== '' ? $summary : (isset($result['subtype']) ? $result['subtype'] : 'error'), 300); }
        else {
            $tail = '';
            if (is_readable($ef)) { $e = trim((string)@file_get_contents($ef, false, null, max(0, (int)@filesize($ef) - 600))); $tail = clip(aia_mask($e), 300); }
            $error = $tail !== '' ? $tail : ($rc === 0 ? 'claude finished without a result event' : 'claude exited with code ' . $rc);
        }
    }
}
if ($mode === 'state') { echo $state; exit(0); }
$total = count($steps); $omitted = 0;
if ($total > 400) {   // keep the output bounded (UI polls every second): first step ("Discovery started") + the last 399
    $omitted = $total - 400;
    $steps = array_merge(array_slice($steps, 0, 1), array_slice($steps, -399));
}
$out = array('state' => $state, 'steps' => $steps, 'summary' => $summary, 'error' => $error, 'reason' => $reason,
             'started' => $started, 'finished' => $finished, 'events' => $nev, 'denied' => $denied,
             'steps_total' => $total, 'steps_omitted' => $omitted);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
PHPEOF

status_core() { # status_core MODE
  local mode="$1" shell="none" rc=0 canc=0 p fin=0 reason="" started=0 line
  p="$(read_pid)"
  if pid_alive "$p"; then shell=running
  elif [ -r "$MARKER" ]; then
    shell=exit
    while IFS='=' read -r k v; do
      case "$k" in rc) rc="${v//[^0-9]/}" ;; t) fin="${v//[^0-9]/}" ;; cancelled) canc="${v//[^0-9]/}" ;; esac
    done < "$MARKER"
  elif [ -n "$p" ]; then shell=dead
  fi
  if [ -r "$META" ]; then
    while IFS='=' read -r k v; do
      case "$k" in reason) reason="$v" ;; started) started="${v//[^0-9]/}" ;; esac
    done < "$META"
  fi
  php -r "$PHP_STATUS" -- "$mode" "$JSONL" "$TIMES" "$ERRLOG" "$shell" "${rc:-0}" "${canc:-0}" "$MEM_DIR" "$AIA_EMHTTP/hooks/hooklib.php" "$reason" "${started:-0}" "${fin:-0}"
}
status_state() { status_core state; }
cmd_status() {
  local out; out="$(status_core json 2>/dev/null)"
  [ -n "$out" ] || out='{"state":"none","steps":[],"summary":"","error":""}'
  printf '%s\n' "$out"
}

# ---- cancel ----
cmd_cancel() {
  local p i; p="$(read_pid)"
  if ! pid_alive "$p"; then printf '{"ok":true,"cancelled":false}\n'; return 0; fi
  kill -TERM -- "-$p" 2>/dev/null || kill -TERM "$p" 2>/dev/null
  for i in $(seq 1 50); do pid_alive "$p" || break; sleep 0.2; done
  if pid_alive "$p"; then
    kill -KILL -- "-$p" 2>/dev/null; sleep 0.5
    rm -f "$PIDFILE"
    [ -e "$MARKER" ] || printf 'rc=137\nt=%s\ncancelled=1\n' "$(now)" > "$MARKER"
    state_set discovery_state=failed
  fi
  printf '{"ok":true,"cancelled":true}\n'
}

case "${1:-}" in
  preflight) cmd_preflight ;;
  start) shift; cmd_start "$@" ;;
  status) cmd_status ;;
  cancel) cmd_cancel ;;
  _run) shift; cmd_run "$@" ;;
  *) echo '{"ok":false,"error":"usage: discover.sh preflight|start [--as-root] [--reason initial|upgrade|manual]|status|cancel"}'; exit 2 ;;
esac

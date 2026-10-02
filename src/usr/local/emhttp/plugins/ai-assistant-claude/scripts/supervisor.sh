#!/bin/bash
# Supervised `claude remote-control` device (daemon; started by rc.ai-assistant via setsid, pid in
# run/supervisor.pid). Adapted from the proven cc-host-remote.sh. Restarts the device whenever it
# exits; before each (re)start it renders the managed config, optionally updates the binary (nothing
# is running then, so nothing is disrupted) and answers the "Enable Remote Control? (y/n)" prompt.
# Update safety: after an update, if the new binary fails to connect twice (network OK, logged in) we
# roll back to claude.prev and remember the bad version so it is not installed again.
# It never touches /mnt: cwd is WORK_DIR (RAM by default), so the array can stop underneath it.
set -u
LOG_NAME=supervisor
. "$(dirname "$(readlink -f "$0")")/common.sh"
cd / || exit 1   # never keep a cwd on /mnt (blocks array stop), whatever the caller had
SELF=$(readlink -f "$0")
# Be a session leader: the TERM handler and the watchdog kill "everything in my session", which must
# never include the caller's shell. (setsid forks when already a group leader, hence the re-exec.)
[ "$(ps -o sid= -p $$ | tr -d ' ')" = "$$" ] || exec setsid "$SELF" "$@"
SID=$$
load_cfg; prep_ram
old=$(cat "$RUN_DIR/supervisor.pid" 2>/dev/null || true)
if [ -n "$old" ] && [ "$old" != "$$" ] && kill -0 "$old" 2>/dev/null && grep -q supervisor.sh "/proc/$old/cmdline" 2>/dev/null; then
  echo "supervisor already running (pid $old)"; exit 0
fi
echo $$ > "$RUN_DIR/supervisor.pid"

OUT=$RUN_DIR/rc-out.txt; IN=$RUN_DIR/rc-in; RESULT=$RUN_DIR/connect.result
PENDING=$FLASH_BIN/update.pending   # exists while an update is unproven; holds the failure count
F=; SP=

kill_session() {  # TERM, then KILL, every process in our session except ourselves
  local p left
  for p in $(pgrep -s "$SID"); do [ "$p" = "$$" ] || kill -TERM "$p" 2>/dev/null; done
  sleep 2
  for p in $(pgrep -s "$SID"); do [ "$p" = "$$" ] || kill -KILL "$p" 2>/dev/null; done
}
# `script` runs in the background and we `wait` for it: with a foreground child bash would defer this
# trap until the child exited, so a TERM from rc stop would silently do nothing (incident 2026-09-27).
on_term() {
  log "TERM received, shutting down"
  [ -n "$F" ] && kill "$F" 2>/dev/null
  kill_session
  state_set connected:b=false running:b=false
  timeout 25 "$SCRIPTS/sync-state.sh" --force >/dev/null 2>&1   # shutdown also lands here (rc.6 TERMs everything)
  rm -f "$RUN_DIR/supervisor.pid"
  exit 0
}
trap on_term TERM INT

# bash defers traps while a foreground child runs; sleep in the background and wait so TERM acts at once
nap() { sleep "$1" & wait $!; }

is_logged_in() {  # unknown output = assume yes, so a changed CLI never locks us out
  local j; j=$(claude_run auth status --json 2>/dev/null)
  [ -z "$j" ] && return 0
  [ "$(printf '%s' "$j" | json_get - loggedIn 2>/dev/null)" != false ]
}
net_ok() { curl -s -o /dev/null -m 8 -I https://api.anthropic.com/ ; }

# Unraid upgraded since the last discovery? Ask the discovery script (written elsewhere) to refresh the
# memory. Once per supervisor start and per version (marker), never in the restart loop.
check_upgrade() {
  local uv; uv=$(unraid_version)
  [ "${DISCOVERY_DONE:-no}" = yes ] && [ -n "$uv" ] && [ "$uv" != "${DISCOVERY_VERSION:-}" ] || return 0
  [ -e "$RUN_DIR/upgrade-discovery.$uv" ] && return 0
  [ -x "$SCRIPTS/discover.sh" ] || return 0
  : > "$RUN_DIR/upgrade-discovery.$uv"
  log "Unraid $uv differs from discovery version '${DISCOVERY_VERSION:-}', starting re-discovery"
  setsid "$SCRIPTS/discover.sh" start --reason upgrade >/dev/null 2>&1 < /dev/null &
}

do_update() {
  [ "${AUTO_UPDATE:-yes}" = yes ] || return 0
  local j
  timeout 900 "$SCRIPTS/install-claude.sh" --auto > "$RUN_DIR/update.json" 2>/dev/null &
  wait $! || { log "update check failed, keeping $(claude_version)"; return 0; }
  j=$(cat "$RUN_DIR/update.json")
  if [ "$(printf '%s' "$j" | json_get - updated 2>/dev/null)" = true ] && [ -n "$(printf '%s' "$j" | json_get - previous 2>/dev/null)" ]; then
    log "updated claude $(printf '%s' "$j" | json_get - previous) -> $(printf '%s' "$j" | json_get - version); on probation"
    echo 0 > "$PENDING"
  fi
}

# After a start attempt: settle the probation of a fresh update.
settle_probation() {  # $1 = connect result (ok|timeout|exited)
  [ -e "$PENDING" ] || return 0
  if [ "$1" = ok ]; then rm -f "$PENDING"; log "new version proven, probation over"; return 0; fi
  net_ok || { log "no network, not blaming the new version"; return 0; }
  local n; n=$(( $(cat "$PENDING" 2>/dev/null || echo 0) + 1 ))
  echo "$n" > "$PENDING"; log "new version failed to connect ($n/2)"
  if [ "$n" -ge 2 ]; then
    j=$("$SCRIPTS/install-claude.sh" --rollback 2>/dev/null)
    log "ROLLBACK: $j"; rm -f "$PENDING"
    state_set rolled_back:b=true
  fi
}

check_upgrade
while true; do
  load_cfg; prep_ram
  [ "$ENABLED" = yes ] || { log "ENABLED=no, supervisor exiting"; state_set running:b=false connected:b=false; rm -f "$RUN_DIR/supervisor.pid"; exit 0; }

  if [ ! -x "$RAM_BIN/claude" ]; then
    log "no binary yet, installing"; "$SCRIPTS/install-claude.sh" >/dev/null 2>&1
    [ -x "$RAM_BIN/claude" ] || { state_set connected:b=false error="Claude Code is not installed"; nap 60; continue; }
  fi
  if ! is_logged_in; then
    state_set running:b=true connected:b=false error="not logged in"; log "not logged in, waiting"; nap 60; continue
  fi
  do_update
  "$SCRIPTS/render-config.sh" >/dev/null 2>&1
  # onboarding + folder trust, otherwise the first start blocks on interactive dialogs
  php "$LIB_PHP" ensure-claude-json "$CONFIG_DIR/.claude.json" "$WORK_DIR"
  [ "$RUN_USER" != root ] && chown "$RUN_USER:$(id -g "$RUN_USER")" "$CONFIG_DIR/.claude.json" 2>/dev/null

  log "starting $(claude_version) as $RUN_USER, device '$DEVICE', cwd $WORK_DIR"
  rm -f "$OUT" "$IN" "$RESULT"; mkfifo -m 600 "$IN"
  : > "$OUT"; chmod 600 "$OUT"   # the pty log can contain session URLs
  ( exec 3>"$IN"; a=0
    for i in $(seq 1 120); do sleep 1
      [ $a = 0 ] && grep -aq 'Enable Remote Control? (y/n)' "$OUT" 2>/dev/null && { printf 'y\n' >&3; a=1; log "answered prompt"; }
      [ $a = 1 ] && [ "$i" -gt 5 ] && break
      # once remote-control has been enabled it no longer asks: do not sit out the 120 s prompt window
      grep -aq 'Connected' "$OUT" 2>/dev/null && break; done
    for i in $(seq 1 90); do
      grep -aq 'Connected' "$OUT" 2>/dev/null && { echo ok > "$RESULT"; state_set connected:b=true error=""; log "connected"; exec sleep infinity; }
      sleep 1; done
    echo timeout > "$RESULT"; log "not connected after 90s, restarting"
    for p in $(pgrep -s "$SID" -f 'claude remote-control'); do kill -TERM "$p" 2>/dev/null; done
    exec sleep infinity ) 8>&- &
  F=$!
  cmd=$(claude_cmdline remote-control --name "$DEVICE" --spawn "$SPAWN_MODE" --capacity "$CAPACITY" --permission-mode "$(permission_mode "${DEVICE_PERMISSION_MODE:-default}")")
  ( cd "$WORK_DIR" 2>/dev/null || cd / ; exec script -qefc "$cmd" "$OUT" < "$IN" >/dev/null ) &
  SP=$!
  state_set running:b=true connected:b=false error="" started:i="$(date +%s)" device="$DEVICE" pid:i=$$
  wait "$SP"; rc=$?
  kill "$F" 2>/dev/null; F=
  state_set connected:b=false
  res=$(cat "$RESULT" 2>/dev/null || echo exited)
  # a device that dies before connecting usually printed why (e.g. a refused env var): surface it
  if [ "$res" != ok ]; then
    why=$(strip_ansi < "$OUT" 2>/dev/null | grep -aiE 'error|must|requires|denied|failed' | grep -v '^Script ' | tail -n 1 | cut -c1-300)
    [ "$why" ] && { log "device error: $why"; state_set error="$why"; }
  fi
  settle_probation "$res"
  log "device exited ($rc, $res), restarting in 10s"
  nap 10
done

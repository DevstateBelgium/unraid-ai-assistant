#!/bin/bash
# Non-interactive `claude auth login` for the WebGUI: URL out, code in.
#   login.sh start        -> {"ok":true,"url":"https://claude.com/cai/oauth/authorize?..."}
#   login.sh code <code>  -> {"ok":true,"email":"..."} | {"ok":false,"error":"..."}
#   login.sh cancel       -> {"ok":true}
#   login.sh status       -> {"ok":true,"state":"none|waiting|done","logged_in":bool,"email":"","org":""}
#   login.sh logout       -> {"ok":true}
# Runs `claude auth login` as RUN_USER on a pty (script) fed from a fifo: the same trick the proven
# `cc` launcher uses. One login at a time (flock), self-destructs after 15 minutes, and the code is
# never logged or echoed back.
set -u
LOG_NAME=login
cd / || exit 1
. "$(dirname "$(readlink -f "$0")")/common.sh"
load_cfg
prep_ram
L=$RUN_DIR/login; mkdir -p "$L"
OUT=$L/out; IN=$L/in; PIDF=$L/pid
AUTH_CACHE=$RUN_DIR/auth.json

# auth_refresh: ask claude who is logged in and cache it for rc status (it takes a second or two)
auth_refresh() {
  local j
  j=$(claude_run auth status --json 2>/dev/null) || true
  [ -n "$j" ] && php "$LIB_PHP" valid - <<< "$j" && printf '%s\n' "$j" > "$AUTH_CACHE.tmp" && mv -f "$AUTH_CACHE.tmp" "$AUTH_CACHE"
  return 0
}
auth_field() { json_get "$AUTH_CACHE" "$1" 2>/dev/null || true; }

kill_login() {
  local p; p=$(cat "$PIDF" 2>/dev/null)
  [ -n "$p" ] && kill -TERM -- "-$p" 2>/dev/null   # whole process group (setsid made $p the leader)
  sleep 0.3; [ -n "$p" ] && kill -KILL -- "-$p" 2>/dev/null
  rm -f "$PIDF" "$IN" "$OUT"
}

case "${1:-status}" in
  start)
    [ -x "$RAM_BIN/claude" ] || { json_obj ok:b=false error="Claude Code is not installed yet"; exit 0; }
    exec 8>"$RUN_DIR/login.lock"
    flock -n 8 || { kill_login; flock -w 5 8 || { json_obj ok:b=false error="another login is starting"; exit 0; }; }
    kill_login
    mkfifo -m 600 "$IN"; : > "$OUT"; chmod 600 "$OUT"
    cmd=$(claude_cmdline auth login)
    # fd 9 is the fifo opened read+write so it never sees EOF while we wait for the code; wide tty so
    # the URL is not wrapped; `timeout` is the 15-minute self-destruct.
    setsid bash -c 'exec 9<>"$1"; cd /; timeout 900 script -qefc "stty cols 2000 2>/dev/null; $2" "$3" <&9 >/dev/null 2>&1; echo "EXIT $?" >> "$3"' _ "$IN" "$cmd" "$OUT" 8>&- &
    echo $! > "$PIDF"
    for _ in $(seq 1 30); do
      url=$(strip_ansi < "$OUT" 2>/dev/null | grep -ao 'https://claude\.com/cai/oauth/authorize?[^[:space:][:cntrl:]]*' | head -1)
      [ -n "$url" ] && { log "login started"; json_obj ok:b=true url="$url"; exit 0; }
      sleep 1
    done
    kill_login; log "login produced no URL"; json_obj ok:b=false error="login did not produce a URL (offline?)" ;;

  code)
    code=${2:-}
    [[ $code =~ ^[A-Za-z0-9_#.-]{10,400}$ ]] || { json_obj ok:b=false error="that does not look like a login code"; exit 0; }
    p=$(cat "$PIDF" 2>/dev/null)
    { [ -n "$p" ] && kill -0 "$p" 2>/dev/null && [ -p "$IN" ]; } || { json_obj ok:b=false error="no login in progress, start it again"; exit 0; }
    timeout 5 bash -c 'printf "%s\r" "$1" > "$2"' _ "$code" "$IN" || { json_obj ok:b=false error="could not hand the code to the login process"; exit 0; }
    for _ in $(seq 1 40); do
      txt=$(strip_ansi < "$OUT" 2>/dev/null)
      if grep -q 'Login successful' <<< "$txt"; then
        kill_login; auth_refresh
        "$SCRIPTS/sync-state.sh" --force >/dev/null 2>&1   # credentials to flash right away
        log "login successful"
        json_obj ok:b=true email="$(auth_field email)"; exit 0
      fi
      if grep -qiE 'invalid|expired|failed|error' <<< "$(tail -n 3 <<< "$txt")"; then
        kill_login; log "login failed"; json_obj ok:b=false error="login failed: the code was rejected or expired, start again"; exit 0
      fi
      sleep 1
    done
    log "login: no answer"; json_obj ok:b=false error="no answer from the login flow (try again)" ;;

  cancel) kill_login; log "login cancelled"; json_obj ok:b=true ;;

  status)
    auth_refresh
    p=$(cat "$PIDF" 2>/dev/null); st=none
    [ -n "$p" ] && kill -0 "$p" 2>/dev/null && st=waiting
    lin=$(auth_field loggedIn); [ "$st" = none ] && [ "$lin" = true ] && st=done
    json_obj ok:b=true state="$st" logged_in:b="${lin:-false}" email="$(auth_field email)" org="$(auth_field orgName)" ;;

  logout)
    kill_login
    claude_run auth logout >/dev/null 2>&1
    # remove every copy, otherwise boot/sync would restore the old credentials
    rm -f "$CONFIG_DIR/.credentials.json" "$FLASH_STATE/config/.credentials.json" "$AUTH_CACHE"
    state_set connected:b=false
    log "logged out"
    json_obj ok:b=true ;;

  *) json_obj ok:b=false error="usage: login.sh start|code <code>|cancel|status|logout"; exit 2 ;;
esac

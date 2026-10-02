#!/bin/bash
# Shared helpers for the AI Assistant scripts (source this, do not execute it).
# Path roots are overridable (AIA_EMHTTP / AIA_FLASH / AIA_RAM) so everything can be tested in a sandbox.
# Callers are expected to run with `set -u`; nothing here relies on cron's PATH.
export PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin

PLUGIN=ai-assistant-claude
EMHTTP=${AIA_EMHTTP:-/usr/local/emhttp/plugins/$PLUGIN}
FLASH=${AIA_FLASH:-/boot/config/plugins/$PLUGIN}
RAM=${AIA_RAM:-/var/lib/ai-assistant-claude}
SCRIPTS=$EMHTTP/scripts
LIB_PHP=$SCRIPTS/lib.php
CFG_FILE=$FLASH/$PLUGIN.cfg
FLASH_BIN=$FLASH/bin
FLASH_STATE=$FLASH/state
RAM_BIN=$RAM/bin
CONFIG_DIR=$RAM/config          # = CLAUDE_CONFIG_DIR
MEM_DIR=$RAM/memory
RUN_DIR=$RAM/run
LOG_DIR=$RAM/logs
HOME_BASE=$RAM/home
STATE_JSON=$RUN_DIR/state.json
UNRAID_VERSION_FILE=${AIA_UNRAID_VERSION_FILE:-/etc/unraid-version}
VAR_INI=${AIA_VARINI:-/var/local/emhttp/var.ini}
LOG_NAME=${LOG_NAME:-plugin}

# ---- config -------------------------------------------------------------------------------------
# The cfg is parsed, never sourced: it is written by the WebGUI and must not be able to run code.
# TZ lands in CFG_TZ because assigning the shell variable TZ would change this script's own clock.
CFG_KEYS=" ENABLED DEVICE_NAME RUN_USER WORK_DIR SPAWN_MODE CAPACITY MAIN_MODEL WORKER_MODEL SCOUT_MODEL AUTO_UPDATE HISTORY_DIR EXPLAIN_NOTIFICATIONS GAP_CHECKS CHAT_PERMISSION_MODE DEVICE_PERMISSION_MODE EXTRA_BLOCKED_PATTERNS DISCOVERY_DONE DISCOVERY_VERSION TZ "
load_cfg() {
  local f line k v
  for f in "$EMHTTP/default.cfg" "$CFG_FILE"; do
    [ -r "$f" ] || continue
    while IFS= read -r line || [ -n "$line" ]; do
      line=${line%$'\r'}
      [[ $line =~ ^[[:space:]]*([A-Z_]+)=(.*)$ ]] || continue
      k=${BASH_REMATCH[1]}; v=${BASH_REMATCH[2]}
      [[ $CFG_KEYS == *" $k "* ]] || continue
      v=${v#\"}; v=${v%\"}; v=${v//\\\"/\"}; v=${v//\\\\/\\}
      [ "$k" = TZ ] && k=CFG_TZ
      printf -v "$k" '%s' "$v"
    done < "$f"
  done
  [ "${RUN_USER:-}" ] || RUN_USER=root
  if ! id -u "$RUN_USER" >/dev/null 2>&1; then log "RUN_USER '$RUN_USER' does not exist, falling back to root"; RUN_USER=root; fi
  # the shipped default names the real RAM path; follow RAM so sandboxes/relocation work
  { [ -z "${WORK_DIR:-}" ] || [ "$WORK_DIR" = /var/lib/ai-assistant-claude/work ]; } && WORK_DIR=$RAM/work
  [[ ${CAPACITY:-} =~ ^[0-9]+$ ]] || CAPACITY=32
  case "${SPAWN_MODE:-}" in same-dir|worktree|session) ;; *) SPAWN_MODE=same-dir ;; esac
  DEVICE=${DEVICE_NAME:-}
  [ "$DEVICE" ] || DEVICE="$(hostname)-assistant"
  return 0
}

# ---- logging ------------------------------------------------------------------------------------
# One log per script family; rotated at 1 MB because the RAM disk is small.
log() {
  local f=$LOG_DIR/$LOG_NAME.log sz
  mkdir -p "$LOG_DIR" 2>/dev/null
  sz=$(stat -c %s "$f" 2>/dev/null || echo 0)
  [ "$sz" -gt 1048576 ] && mv -f "$f" "$f.1" 2>/dev/null
  printf '%s [%s] %s\n' "$(date '+%F %T')" "$LOG_NAME" "$*" >> "$f" 2>/dev/null
  return 0
}

# ---- JSON helpers (PHP-CLI, no jq) --------------------------------------------------------------
# json_obj k=v k:b=true k:i=5 ... -> one line of JSON
json_obj() { php "$LIB_PHP" obj "$@"; }
# json_get <file|-> a.b.c -> scalar on stdout, exit 1 if missing
json_get() { php "$LIB_PHP" get "$@"; }
# state_set k=v ... -> merge into run/state.json (atomic, flock'ed)
state_set() { php "$LIB_PHP" state-set "$STATE_JSON" "$@" >/dev/null 2>&1; }

# ---- RAM tree and ownership ---------------------------------------------------------------------
# run_user_ids sets RU_UID/RU_GID; chown only matters when RUN_USER is not root.
prep_ram() {
  local uh=$HOME_BASE/${RUN_USER:-root}
  mkdir -p "$RAM_BIN" "$CONFIG_DIR/agents" "$MEM_DIR" "$RAM/work" "$RUN_DIR" "$LOG_DIR" "$uh" "$RUN_DIR/user"
  chmod 755 "$RAM" "$RAM_BIN" 2>/dev/null
  [ -d "${WORK_DIR:-}" ] || mkdir -p "$WORK_DIR" 2>/dev/null
  [ "${RUN_USER:-root}" = root ] && return 0
  local u=$RUN_USER g
  g=$(id -g "$u")
  # run/ stays root-owned (pid files, locks); only run/user is the user's scratch space
  chown -R "$u:$g" "$CONFIG_DIR" "$MEM_DIR" "$LOG_DIR" "$HOME_BASE/$u" "$RUN_DIR/user" 2>/dev/null
  chown "$u:$g" "$RAM/work" "$WORK_DIR" 2>/dev/null
  return 0
}

# ---- running claude as RUN_USER -----------------------------------------------------------------
# Why setpriv (fallback su): no PAM/login shell, no extra tty handling, signals reach claude directly.
# Why `env`: a clean, explicit environment; HOME points into RAM so claude never writes to /root or
# the user's real home (and never to flash/array).
claude_cmdline() {  # args = claude arguments; prints a shell-quoted command line
  local uh=$HOME_BASE/${RUN_USER:-root} env_q pre=
  local -a ev=(HOME="$uh" CLAUDE_CONFIG_DIR="$CONFIG_DIR" PATH="$RAM_BIN:$PATH" TERM=xterm-256color
               LANG=C.UTF-8 DISABLE_AUTOUPDATER=1)
               # NOT CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC: remote-control refuses to start with it (needs feature flags)
  [ "${CFG_TZ:-}" ] && ev+=(TZ="$CFG_TZ")
  printf -v env_q '%q ' env "${ev[@]}" "$RAM_BIN/claude" "$@"
  if [ "${RUN_USER:-root}" != root ]; then
    if command -v setpriv >/dev/null 2>&1; then
      printf -v pre '%q ' setpriv --reuid="$(id -u "$RUN_USER")" --regid="$(id -g "$RUN_USER")" --init-groups
    else
      printf 'su -s /bin/bash %q -c %q' "$RUN_USER" "$env_q"; return 0
    fi
  fi
  printf '%s%s' "$pre" "$env_q"
}
permission_mode() {  # whitelist for --permission-mode; anything else falls back to default (bypassPermissions/dontAsk are never offered)
  case "$1" in default|acceptEdits|auto|plan) printf %s "$1" ;; *) printf default ;; esac
}

# claude_run args... : run synchronously as RUN_USER in a neutral cwd (never /mnt), 30 s limit
claude_run() { ( cd / && timeout "${CLAUDE_RUN_TIMEOUT:-30}" bash -c "$(claude_cmdline "$@")" ); }

strip_ansi() { sed 's/\x1b\[[0-9;?]*[a-zA-Z]//g; s/\x1b\][^\x07]*\x07//g' | tr -d '\r'; }
unraid_version() { ( . "$UNRAID_VERSION_FILE" 2>/dev/null && echo "${version:-}" ); }
claude_version() {  # prefers the version file (no exec needed), else asks the binary
  local v; v=$(cat "$RAM_BIN/claude.version" 2>/dev/null || cat "$FLASH_BIN/claude.version" 2>/dev/null)
  [ "$v" ] || v=$(HOME=/tmp timeout 10 "$RAM_BIN/claude" --version 2>/dev/null | awk '{print $1; exit}')
  echo "$v"
}
supervisor_pid() {  # prints pid if the supervisor is alive
  local p; p=$(cat "$RUN_DIR/supervisor.pid" 2>/dev/null)
  [ "$p" ] && kill -0 "$p" 2>/dev/null && tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null | grep -q supervisor.sh && echo "$p"
}

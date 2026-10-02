#!/bin/bash
# Mirror the small persistent state RAM -> FLASH/state, and the session history to HISTORY_DIR.
#   sync-state.sh [--force] [--history-only]
# chat-index.json (conversation titles, modes, archived flags) is small and only rewritten on change.
# Flash wear: only files whose content changed are written (cmp), each via tmp + mv so a power loss
# never leaves a half-written credentials file. Never copied to flash: run/, logs/, projects/, work/.
# .claude.json changes on every claude run (counters), so it is throttled to once an hour unless --force
# (stopping / stopped / shutdown events pass --force).
# History (projects/) is copied only while the array is Started, newest file wins, both directions, so
# sessions started with the array stopped are merged into HISTORY_DIR later. Never symlinks.
set -u
LOG_NAME=sync
. "$(dirname "$(readlink -f "$0")")/common.sh"
load_cfg
FORCE=0; HIST_ONLY=0
for a in "$@"; do case "$a" in --force) FORCE=1 ;; --history-only) HIST_ONLY=1 ;; esac; done

# The state must have been restored from flash first, otherwise an empty RAM tree could be mirrored
# over (and delete) the good copy on flash.
[ -e "$RUN_DIR/restored" ] || { json_obj ok:b=false error="state not restored yet"; exit 0; }
exec 9>"$RUN_DIR/sync.lock"
flock -w 120 9 || { json_obj ok:b=false error="sync busy"; exit 0; }

copied=0
umask 077   # credentials: keep the temp file private (FAT ignores modes, RAM copies of flash honour them)

# cp_changed <src> <dst>: copy file if different, atomically
cp_changed() {
  [ -f "$1" ] || return 0
  cmp -s "$1" "$2" 2>/dev/null && return 0
  mkdir -p "$(dirname "$2")" 2>/dev/null
  if cp -f "$1" "$2.tmp$$" && mv -f "$2.tmp$$" "$2"; then copied=$((copied+1)); log "synced ${2#$FLASH_STATE/}"
  else rm -f "$2.tmp$$"; log "ERROR copying $1 -> $2 (flash full or read-only?)"; fi
}

# mirror_dir <srcdir> <dstdir>: cp_changed for every file, then drop files that no longer exist in src
mirror_dir() {
  local f rel
  [ -d "$1" ] || return 0
  while IFS= read -r -d '' f; do rel=${f#"$1"/}; cp_changed "$f" "$2/$rel"; done < <(find "$1" -type f -print0)
  [ -d "$2" ] || return 0
  while IFS= read -r -d '' f; do
    rel=${f#"$2"/}
    case "$rel" in *.tmp[0-9]*) rm -f "$f"; continue ;; esac
    [ -e "$1/$rel" ] || { rm -f "$f"; copied=$((copied+1)); log "removed ${f#$FLASH_STATE/} (deleted in RAM)"; }
  done < <(find "$2" -type f -print0)
}

# deleted conversations wait 7 days in projects-trash/ (RAM only, never mirrored), then go for good
if [ -d "$CONFIG_DIR/projects-trash" ]; then
  find "$CONFIG_DIR/projects-trash" -mindepth 1 -maxdepth 1 -type d -mtime +7 -exec rm -rf -- {} + 2>/dev/null
fi

if [ $HIST_ONLY = 0 ]; then
  for f in .credentials.json settings.json settings.user.json CLAUDE.md chat-index.json; do
    cp_changed "$CONFIG_DIR/$f" "$FLASH_STATE/config/$f"
  done
  # .claude.json throttle: flash mtime has 2 s resolution, plenty for an hourly check
  cj=$FLASH_STATE/config/.claude.json
  if [ $FORCE = 1 ] || [ ! -e "$cj" ] || [ $(( $(date +%s) - $(stat -c %Y "$cj" 2>/dev/null || echo 0) )) -ge 3600 ]; then
    cp_changed "$CONFIG_DIR/.claude.json" "$cj"
  fi
  mirror_dir "$CONFIG_DIR/agents" "$FLASH_STATE/config/agents"
  mirror_dir "$MEM_DIR" "$FLASH_STATE/memory"
fi

# ---- history (array dependent) ----
umask 022
hist=skipped
if [ -n "${HISTORY_DIR:-}" ] && [[ $HISTORY_DIR == /mnt/* ]] && grep -q 'mdState="STARTED"' "$VAR_INI" 2>/dev/null; then
  if mkdir -p "$HISTORY_DIR/projects" 2>/dev/null; then
    mkdir -p "$CONFIG_DIR/projects"
    # -u: only files that are newer or missing; transcripts are append-only so newest == most complete
    cp -au "$HISTORY_DIR/projects/." "$CONFIG_DIR/projects/" 2>/dev/null
    cp -au "$CONFIG_DIR/projects/." "$HISTORY_DIR/projects/" 2>/dev/null
    [ "$RUN_USER" != root ] && chown -R "$RUN_USER:$(id -g "$RUN_USER")" "$CONFIG_DIR/projects" 2>/dev/null
    hist=ok
  else hist=failed; log "cannot create HISTORY_DIR $HISTORY_DIR"; fi
fi

state_set last_sync:i="$(date +%s)"
json_obj ok:b=true copied:i=$copied history="$hist"

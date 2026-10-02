#!/bin/bash
# Install / update / roll back the native Claude Code binary: FLASH/bin/claude (cache) + RAM/bin/claude.
#   install-claude.sh            install if missing, else update if a newer version exists
#   install-claude.sh --auto     like the above but skip a version we rolled back from (supervisor)
#   install-claude.sh --force    reinstall the latest even if it is current
#   install-claude.sh --check    only report installed/latest, change nothing
#   install-claude.sh --rollback swap claude.prev back in (supervisor, after a failed update)
# Prints ONE line of JSON: {"ok","version","previous","updated","error"}.
#
# Why we download the binary ourselves instead of running https://claude.ai/install.sh or `claude update`:
# the official installer downloads exactly this (latest -> manifest.json -> sha256 -> binary) and then
# runs `claude install`, which writes a launcher + shell integration into ~/.local of whoever runs it.
# `claude update` likewise updates in place under ~/.local. We want the binary on flash (cache) and in
# RAM (execution), shared by every RUN_USER, with HOME inside RAM; so we do the verified download and
# the placement ourselves. Updates compare the version file against the manifest `latest` pointer.
set -u
LOG_NAME=install
. "$(dirname "$(readlink -f "$0")")/common.sh"
load_cfg
BASE_URL=${AIA_DOWNLOAD_BASE:-https://downloads.claude.ai/claude-code-releases}

MODE=install; AUTO=0
# flags may be combined: `--check --auto` reports update_available=false for a version we rolled back from
for _a in "$@"; do
  case "$_a" in --auto) AUTO=1 ;; --force) MODE=force ;; --check) MODE=check ;; --rollback) MODE=rollback ;; *) echo '{"ok":false,"error":"bad argument"}'; exit 2 ;; esac
done
[ "$AUTO" = 1 ] && [ "$MODE" = install ] && MODE=auto

out() { json_obj ok:b="$1" version="${2:-}" previous="${3:-}" updated:b="${4:-false}" error="${5:-}"; }
fail() { log "ERROR: $*"; out false "$(claude_version)" "" false "$*"; exit 1; }

mkdir -p "$FLASH_BIN" "$RAM_BIN" "$RUN_DIR" "$LOG_DIR" 2>/dev/null
exec 9>"$RUN_DIR/install.lock"
flock -w 600 9 || fail "another install is running"
TMP=$(mktemp -d "$RAM/tmp.XXXXXX") || fail "cannot create temp dir"
trap 'rm -rf "$TMP"' EXIT

cur_version() { cat "$FLASH_BIN/claude.version" 2>/dev/null || cat "$RAM_BIN/claude.version" 2>/dev/null || true; }

# ---- rollback -----------------------------------------------------------------------------------
if [ "$MODE" = rollback ]; then
  [ -s "$FLASH_BIN/claude.prev" ] || fail "no previous binary to roll back to"
  bad=$(cur_version); prev=$(cat "$FLASH_BIN/claude.prev.version" 2>/dev/null || true)
  # remember the bad version so --auto does not install it again
  printf '%s\n' "$bad" > "$FLASH_BIN/claude.bad.tmp" && mv -f "$FLASH_BIN/claude.bad.tmp" "$FLASH_BIN/claude.bad"
  cp "$FLASH_BIN/claude.prev" "$FLASH_BIN/claude.new" && mv -f "$FLASH_BIN/claude.new" "$FLASH_BIN/claude" || fail "flash rollback failed"
  cp "$FLASH_BIN/claude.prev" "$RAM_BIN/claude.new" && chmod 755 "$RAM_BIN/claude.new" && mv -f "$RAM_BIN/claude.new" "$RAM_BIN/claude" || fail "RAM rollback failed"
  [ "$prev" ] && { printf '%s\n' "$prev" > "$FLASH_BIN/claude.version"; printf '%s\n' "$prev" > "$RAM_BIN/claude.version"; }
  log "rolled back $bad -> ${prev:-previous}"
  out true "$prev" "$bad" true ""; exit 0
fi

# ---- preconditions ------------------------------------------------------------------------------
[ "$(uname -m)" = x86_64 ] || fail "unsupported architecture $(uname -m) (x86_64 only)"
command -v curl >/dev/null 2>&1 || fail "curl is required"
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum is required"
if [ -f /lib/libc.musl-x86_64.so.1 ] || ldd /bin/ls 2>&1 | grep -q musl; then PLATFORM=linux-x64-musl; else PLATFORM=linux-x64; fi

dl() { curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 15 "$@"; }

latest=$(dl -m 30 "$BASE_URL/latest" 2>/dev/null | tr -d '[:space:]')
[[ $latest =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] || fail "could not determine the latest version (offline or blocked?)"
have=$(cur_version)
have_bin=0; [ -s "$FLASH_BIN/claude" ] && [ -s "$RAM_BIN/claude" ] && have_bin=1

if [ "$MODE" = check ]; then
  ua=false; [ "$have" != "$latest" ] && ua=true
  [ "$AUTO" = 1 ] && [ "$latest" = "$(cat "$FLASH_BIN/claude.bad" 2>/dev/null)" ] && ua=false
  json_obj ok:b=true version="$have" latest="$latest" installed:b=$([ $have_bin = 1 ] && echo true || echo false) update_available:b=$ua; exit 0
fi
if [ $have_bin = 1 ] && [ "$have" = "$latest" ] && [ "$MODE" != force ]; then
  # flash survives reboots but RAM may have lost a copy: make sure both exist (boot also does this)
  out true "$have" "" false ""; exit 0
fi
if [ "$MODE" = auto ] && [ "$latest" = "$(cat "$FLASH_BIN/claude.bad" 2>/dev/null)" ]; then
  log "skipping $latest: we rolled back from it"; out true "$have" "" false ""; exit 0
fi

# ---- download + verify --------------------------------------------------------------------------
manifest=$(dl -m 60 "$BASE_URL/$latest/manifest.json" 2>/dev/null) || fail "could not download manifest for $latest"
# same bash-only extraction the official installer uses; [^{}] stays inside the platform object
flat=$(printf '%s' "$manifest" | tr -d '\n\r\t' | sed 's/ \+/ /g')
sum=
if [[ $flat =~ \"$PLATFORM\"[[:space:]]*:[[:space:]]*[{][^{}]*\"checksum\"[[:space:]]*:[[:space:]]*\"([a-f0-9]{64})\" ]]; then sum=${BASH_REMATCH[1]}; fi
[ "$sum" ] || fail "platform $PLATFORM not in the manifest for $latest"

log "downloading $latest ($PLATFORM)"
dl -m 900 -o "$TMP/claude" "$BASE_URL/$latest/$PLATFORM/claude" || fail "download failed"
got=$(sha256sum "$TMP/claude" | cut -d' ' -f1)
[ "$got" = "$sum" ] || fail "checksum mismatch (expected $sum, got $got)"
chmod 755 "$TMP/claude"
# smoke test with a throw-away HOME so a broken build never touches real state
nv=$(HOME="$TMP" DISABLE_AUTOUPDATER=1 timeout 30 "$TMP/claude" --version 2>/dev/null | awk '{print $1; exit}')
[ "$nv" ] || fail "downloaded binary does not run"
[ "$nv" = "$latest" ] || log "note: binary reports $nv, manifest said $latest"

# ---- place: flash first (cache), then RAM -------------------------------------------------------
# Flash is FAT32: no exec bit, so it is only a cache. Keep claude.prev for the supervisor's rollback.
if [ -s "$FLASH_BIN/claude" ]; then
  cp -f "$FLASH_BIN/claude" "$FLASH_BIN/claude.prev.tmp" && mv -f "$FLASH_BIN/claude.prev.tmp" "$FLASH_BIN/claude.prev"
  [ "$have" ] && printf '%s\n' "$have" > "$FLASH_BIN/claude.prev.version"
fi
cp "$TMP/claude" "$FLASH_BIN/claude.new" && sync -f "$FLASH_BIN/claude.new" 2>/dev/null
cmp -s "$TMP/claude" "$FLASH_BIN/claude.new" || { rm -f "$FLASH_BIN/claude.new"; fail "flash write failed (disk full?)"; }
mv -f "$FLASH_BIN/claude.new" "$FLASH_BIN/claude"
printf '%s\n' "$nv" > "$FLASH_BIN/claude.version"
# RAM: rename over the old file; a running claude keeps its inode, so no "text file busy"
[ -s "$RAM_BIN/claude" ] && cp -f "$RAM_BIN/claude" "$RAM_BIN/claude.prev" 2>/dev/null
cp "$TMP/claude" "$RAM_BIN/claude.new" && chmod 755 "$RAM_BIN/claude.new" && mv -f "$RAM_BIN/claude.new" "$RAM_BIN/claude" || fail "RAM write failed"
printf '%s\n' "$nv" > "$RAM_BIN/claude.version"
log "installed $nv (was ${have:-none})"
out true "$nv" "$have" "$([ "$have" ] && echo true || echo false)" ""

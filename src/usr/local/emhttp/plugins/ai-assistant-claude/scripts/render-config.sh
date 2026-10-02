#!/bin/bash
# Write the plugin-managed files into CLAUDE_CONFIG_DIR from templates/ + cfg. Idempotent: a file is
# only rewritten when its content changes. A missing template is logged and skipped (never fatal).
#   settings.json        = templates/settings.base.json deep-merged with config/settings.user.json
#                          (the user's "Advanced" overrides, mirrored to flash), then model=MAIN_MODEL
#   CLAUDE.md            = managed block between <!-- aia:begin --> / <!-- aia:end -->, user text kept
#   agents/unraid-*.md   = rendered agent definitions (scout/worker models from cfg)
# Prints one JSON line: {"ok":bool,"rendered":[...],"skipped":[...],"changed":bool}
set -u
LOG_NAME=render
. "$(dirname "$(readlink -f "$0")")/common.sh"
load_cfg
TPL=$EMHTTP/templates
prep_ram

rendered=(); skipped=(); changed=0
# Hooks run in Claude's environment: hand them an absolute php path instead of trusting PATH.
PHP_BIN=$(command -v php 2>/dev/null); [ -x "${PHP_BIN:-}" ] || PHP_BIN=/usr/bin/php
VARS=(PHP="$PHP_BIN" MAIN_MODEL="${MAIN_MODEL:-}" WORKER_MODEL="${WORKER_MODEL:-sonnet}" SCOUT_MODEL="${SCOUT_MODEL:-haiku}"
      MEMORY_DIR="$MEM_DIR" EMHTTP="$EMHTTP" DEVICE_NAME="$DEVICE" WORK_DIR="$WORK_DIR")

# put <dest> <tmpfile>: replace dest atomically only when different
put() {
  if [ -f "$1" ] && cmp -s "$1" "$2"; then rm -f "$2"; return 0; fi
  mv -f "$2" "$1" && changed=1
}
skip() { log "skip $1: $2"; skipped+=("$1"); }

T=$(mktemp -d "$RUN_DIR/render.XXXXXX") || { json_obj ok:b=false error="cannot create temp dir"; exit 0; }
trap 'rm -rf "$T"' EXIT

# ---- settings.json ----
if [ -r "$TPL/settings.base.json" ]; then
  if php "$LIB_PHP" render "$TPL/settings.base.json" "${VARS[@]}" > "$T/base.json" 2>/dev/null \
     && php "$LIB_PHP" valid "$T/base.json"; then
    user=; [ -s "$CONFIG_DIR/settings.user.json" ] && user=$CONFIG_DIR/settings.user.json
    if php "$LIB_PHP" merge "$T/base.json" $user > "$T/settings.json" 2>"$T/err"; then
      # model comes from the plugin setting: empty = let Claude Code pick its default
      if [ "${MAIN_MODEL:-}" ]; then
        php -r 'include $argv[1]; $d=json_decode(file_get_contents($argv[2]),true); $d["model"]=$argv[3]; echo aialib_json_enc($d);' \
          "$LIB_PHP" "$T/settings.json" "$MAIN_MODEL" > "$T/s2.json" && mv "$T/s2.json" "$T/settings.json"
      fi
      put "$CONFIG_DIR/settings.json" "$T/settings.json"; rendered+=(settings.json)
    else
      # a broken user override must not take the assistant down: fall back to the base alone
      log "settings.user.json is invalid ($(head -c 200 "$T/err")); using base settings only"
      put "$CONFIG_DIR/settings.json" "$T/base.json"; rendered+=("settings.json(base-only)")
    fi
  else skip settings.json "base template invalid"; fi
else skip settings.json "template missing"; fi

# ---- CLAUDE.md ----
if [ -r "$TPL/CLAUDE.block.md" ]; then
  php "$LIB_PHP" render "$TPL/CLAUDE.block.md" "${VARS[@]}" > "$T/block.md" 2>/dev/null \
    && php "$LIB_PHP" claude-md "$CONFIG_DIR/CLAUDE.md" "$T/block.md" > "$T/CLAUDE.md" \
    && { put "$CONFIG_DIR/CLAUDE.md" "$T/CLAUDE.md"; rendered+=(CLAUDE.md); } || skip CLAUDE.md "render failed"
else skip CLAUDE.md "template missing"; fi

# ---- agents ----
mkdir -p "$CONFIG_DIR/agents"
for a in unraid-scout unraid-worker; do
  if [ -r "$TPL/agents/$a.md.tpl" ]; then
    php "$LIB_PHP" render "$TPL/agents/$a.md.tpl" "${VARS[@]}" > "$T/$a.md" 2>/dev/null \
      && { put "$CONFIG_DIR/agents/$a.md" "$T/$a.md"; rendered+=("agents/$a.md"); } || skip "agents/$a.md" "render failed"
  else skip "agents/$a.md" "template missing"; fi
done

if [ "$RUN_USER" != root ]; then chown -R "$RUN_USER:$(id -g "$RUN_USER")" "$CONFIG_DIR" 2>/dev/null; fi
log "rendered: ${rendered[*]:-none}; skipped: ${skipped[*]:-none}; changed=$changed"
json_obj ok:b=true rendered:j="$(php "$LIB_PHP" list "${rendered[@]}")" skipped:j="$(php "$LIB_PHP" list "${skipped[@]}")" \
  changed:b=$([ $changed = 1 ] && echo true || echo false)
exit 0

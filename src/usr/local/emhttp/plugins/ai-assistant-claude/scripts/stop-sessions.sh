#!/bin/bash
# Array "stopping" hook helper: stop only the processes that would block unmounting /mnt, and leave the
# assistant itself (supervisor + device) running: it has no cwd or open files on /mnt by design.
# Selection (all must hold): owned by RUN_USER, in the host mount namespace (like pid 1), descended from
# the supervisor (ppid chain) or in its session, not the supervisor itself, and holding /mnt via cwd,
# an open file or the executable. Same TERM -> wait -> KILL pattern as /boot/config/cc-hooks/stopping.
# DRYRUN=1 only reports. Prints {"ok":true,"found":[pids sent TERM],"killed":[pids that needed KILL]}.
set -u
LOG_NAME=stop-sessions
. "$(dirname "$(readlink -f "$0")")/common.sh"
load_cfg
cd / || exit 1   # our own cwd must not be on /mnt either

sup=$(cat "$RUN_DIR/supervisor.pid" 2>/dev/null || true)
if [ -z "$sup" ] || ! kill -0 "$sup" 2>/dev/null; then json_obj ok:b=true found:j='[]' killed:j='[]' note="supervisor not running"; exit 0; fi
uid=$(id -u "$RUN_USER"); hostns=$(readlink /proc/1/ns/mnt)
# /proc/PID/stat: the command name may contain spaces/parens, so split after the LAST ") "
stat_field() {  # $1 pid, $2 = ppid | sid
  local s _st pp _pg sd; s=$(cat "/proc/$1/stat" 2>/dev/null) || return 1
  read -r _st pp _pg sd _ <<< "${s##*) }"   # state ppid pgrp session ...
  case "$2" in ppid) echo "$pp" ;; sid) echo "$sd" ;; esac
}
supsid=$(stat_field "$sup" sid)

descends() {  # $1 pid -> 0 if the supervisor is in its ancestry
  local p=$1 i=0
  while [ "$p" -gt 1 ] && [ $i -lt 40 ]; do
    [ "$p" = "$sup" ] && return 0
    p=$(stat_field "$p" ppid) || return 1
    [ -n "$p" ] || return 1; i=$((i+1))
  done; return 1
}
holds_mnt() {  # cwd, exe or any fd under /mnt
  local p=$1 l
  for l in "$(readlink "/proc/$p/cwd" 2>/dev/null)" "$(readlink "/proc/$p/exe" 2>/dev/null)"; do [[ $l == /mnt/* || $l == /mnt ]] && return 0; done
  for l in /proc/$p/fd/*; do l=$(readlink "$l" 2>/dev/null) || continue; [[ $l == /mnt/* ]] && return 0; done
  return 1
}
select_pids() {
  local d p
  for d in /proc/[0-9]*; do
    p=${d#/proc/}
    [ "$p" = "$$" ] || [ "$p" = "$sup" ] && continue
    [ "$(stat -c %u "$d" 2>/dev/null)" = "$uid" ] || continue
    [ "$(readlink "$d/ns/mnt" 2>/dev/null)" = "$hostns" ] || continue
    { descends "$p" || [ "$(stat_field "$p" sid)" = "$supsid" ]; } || continue
    holds_mnt "$p" && echo "$p"
  done
}

found=$(select_pids | tr '\n' ' ')
if [ -z "${found// /}" ]; then json_obj ok:b=true found:j='[]' killed:j='[]'; exit 0; fi
log "processes holding /mnt: $found"
if [ -n "${DRYRUN:-}" ]; then json_obj ok:b=true found:j="$(php "$LIB_PHP" list $found)" killed:j='[]' dryrun:b=true; exit 0; fi
# shellcheck disable=SC2086
kill -TERM $found 2>/dev/null
sleep 5
left=$(select_pids | tr '\n' ' ')
# shellcheck disable=SC2086
[ -n "${left// /}" ] && { log "KILL $left"; kill -KILL $left 2>/dev/null; }
# shellcheck disable=SC2086
json_obj ok:b=true found:j="$(php "$LIB_PHP" list $found)" killed:j="$(php "$LIB_PHP" list ${left:-})"

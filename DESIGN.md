# DESIGN — shared contract for all implementers

This file is the binding contract: paths, config keys, script CLIs and
JSON shapes. If you need to change something here, say so in your report instead of silently diverging.

## Constraints (all code)
- Unraid-version agnostic: NO node/npm. Bash + PHP-CLI only. PHP must run on 7.4 – 8.4
  (no `match`, no named args, no `str_contains`/`str_starts_with`, no enums, no readonly, no `?->`).
- Do not assume `jq` or `inotifywait` exist (older Unraid). Use PHP-CLI for JSON in scripts
  (`php -r` or a helper in `scripts/lib.php`).
- `/`, `/usr/local`, `/var` are RAM. Persistent = `/boot` (flash, FAT32: no exec bit, no symlinks,
  no chmod) or `/mnt/...` (only when the array runs).
- Never leave a long-running process with cwd or open files on `/mnt` (blocks array stop).
- Every script: `set -u`, absolute paths, works with cron's minimal PATH, logs to `$LOG_DIR`.
- English in code, comments and log lines. UI strings go through `_()` (see i18n below).

## Identifiers and paths
```
PLUGIN=ai-assistant-claude
EMHTTP=/usr/local/emhttp/plugins/ai-assistant-claude          # code (from txz)
FLASH=/boot/config/plugins/ai-assistant-claude                 # persistent
  ai-assistant-claude.cfg                                      # settings (INI, KEY="value")
  bin/claude, bin/claude.prev                                  # binary cache
  state/                                                       # mirror of persistent config files
    config/   (.credentials.json .claude.json settings.json CLAUDE.md agents/)
    memory/   (MEMORY.md + *.md)
RAM=/var/lib/ai-assistant-claude
  bin/claude                                                   # copied from FLASH at boot
  config/       = CLAUDE_CONFIG_DIR
  memory/       (live memory; synced to FLASH/state/memory)
  work/         default WORK_DIR (cwd of the device and chat sessions)
  run/          pid files, locks, fifos, login/discovery/chat runtime files
  logs/         supervisor.log, discovery.jsonl, activity.jsonl, login.log, notify.log
HISTORY_DIR (cfg, optional, on /mnt): if set and array Started, CLAUDE_CONFIG_DIR/projects is a
  directory synced to it (rsync-like copy via sync-state.sh); never a symlink (array stop!).
```
`/var/lib/ai-assistant-claude/run/state.json` = live status written by scripts, read by UI.

## Config keys (`ai-assistant-claude.cfg`, defaults in `$EMHTTP/default.cfg`)
```
ENABLED="no"            DEVICE_NAME=""  (empty = `$(hostname)-assistant`)
RUN_USER="root"         WORK_DIR="/var/lib/ai-assistant-claude/work"
SPAWN_MODE="same-dir"   CAPACITY="32"
MAIN_MODEL=""           WORKER_MODEL="sonnet"     SCOUT_MODEL="haiku"
AUTO_UPDATE="yes"       HISTORY_DIR=""
EXPLAIN_NOTIFICATIONS="yes"  GAP_CHECKS="yes"  EXTRA_BLOCKED_PATTERNS=""
DISCOVERY_DONE="no"     DISCOVERY_VERSION=""   (Unraid version at last discovery)
TZ=""   (empty = system)
```
Read in bash via `scripts/common.sh` (`load_cfg` merges default.cfg then FLASH cfg).
Read in PHP via `include/common.php` (`aia_cfg()`), write via `aia_cfg_save(array)` which writes
atomically to FLASH (tmp file + rename) and only the known keys.

## Script CLIs (all in `$EMHTTP/scripts/`, all print ONE line of JSON on stdout for the UI
unless noted; human logs go to `$LOG_DIR`)
- `rc.ai-assistant boot|start|stop|restart|status|update|sync`
  - `boot`: mkdir RAM tree, copy binary FLASH->RAM, restore state FLASH->RAM, render managed files
    (`render-config.sh`), install cron, start if ENABLED=yes. Idempotent.
  - `status` -> `{"installed":bool,"version":"","running":bool,"connected":bool,"enabled":bool,
     "logged_in":bool,"email":"","org":"","device":"","discovery":"none|running|done|failed",
     "unraid_version":""}`
- `install-claude.sh` -> installs/updates native binary into FLASH/bin + RAM/bin;
  `{"ok":bool,"version":"","error":""}`. Keeps previous as claude.prev.
- `supervisor.sh` (daemon, started by rc via setsid; pidfile run/supervisor.pid). Runs
  `claude remote-control --name "$DEVICE" --spawn "$SPAWN_MODE" --capacity N` under RUN_USER
  in WORK_DIR, pty+fifo answers the y/n prompt, 90s connect watchdog, restart after 10s,
  AUTO_UPDATE before each restart with rollback to claude.prev if the next start fails to connect
  twice. Writes connected=true/false into run/state.json.
- `login.sh start|code <code>|cancel|status|logout`
  - start -> `{"ok":true,"url":"https://claude.com/cai/oauth/authorize?..."}`
  - code  -> `{"ok":true,"email":"..."}` or `{"ok":false,"error":"..."}`
- `discover.sh preflight|start [--as-root]|status|cancel`
  - preflight -> `{"ok":bool,"missing":["docker.sock","/boot/config",...]}`
  - start -> `{"ok":true}` (runs in background, events -> logs/discovery.jsonl)
  - status -> `{"state":"running|done|failed|none","steps":[{"t":epoch,"kind":"scout|tool|memory|text","text":"..."}],"summary":""}`
- `sync-state.sh [--force]` RAM -> FLASH for changed files only (cmp), plus history to HISTORY_DIR.
- `stop-sessions.sh` (array `stopping` event): TERM->KILL only processes of RUN_USER descended from
  the supervisor that hold `/mnt` (fuser -m / cwd / open files). Supervisor itself stays up.
- `render-config.sh` writes managed files into CLAUDE_CONFIG_DIR from templates + cfg:
  settings.json (merge: base + user-advanced overrides, `model` = MAIN_MODEL, hooks, permissions),
  CLAUDE.md (managed block between `<!-- aia:begin -->` and `<!-- aia:end -->`, user text kept),
  agents/unraid-scout.md, agents/unraid-worker.md.

## Hooks (PHP-CLI, registered in settings.json)
- `hooks/pre_tool.php` PreToolUse (Bash|Write|Edit|MultiEdit): block destructive patterns (built-in
  list + EXTRA_BLOCKED_PATTERNS, newline/`|`-separated regexes) and secret-looking content written
  to the memory dir. Block via exit code 2 + reason on stderr (Claude Code hook contract — verify
  against `claude --help`/docs of the installed version).
- `hooks/post_tool.php` PostToolUse: append `{"t","session","tool","summary","cwd"}` to
  logs/activity.jsonl and `logger -t ai-assistant`.

## UI (PHP pages + `include/api.php`)
- All AJAX: `POST /plugins/ai-assistant-claude/include/api.php` with `action` + `csrf_token`.
  Action whitelist only; args via escapeshellarg; responses JSON.
- Pages (Menu="Utilities", parent `AIAssistant.page` Type="xmenu"): Status (login, enable,
  discovery progress), Settings, Memory, Activity, Advanced; Chat is added in M3.
- i18n: `include/i18n.php` provides `aia_t($s)`; uses Unraid's `_()` if defined and translated,
  else own lookup in `locales/<lang>.json` based on the WebGUI locale. English is the source.

## Milestones
M1 core (this): everything above except Chat (M3) and notifications (M2).

## Testing rule for implementers
All path roots are overridable for sandbox tests: `AIA_EMHTTP`, `AIA_FLASH`, `AIA_RAM` (env vars,
honoured by `scripts/common.sh` and `include/common.php`). Implementers test ONLY in a sandbox under
`/tmp/aia-test-*` with those overrides. Never write to the real `/boot`, `/var/lib/ai-assistant-claude`
or `/usr/local/emhttp`, never run `claude auth login` or `claude remote-control`, never touch the
existing setup in `/mnt/user/appdata/claude-code` or its processes.

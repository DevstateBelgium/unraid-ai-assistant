# AI Assistant for Unraid (for Claude Code)

An Unraid plugin that runs [Claude Code](https://www.anthropic.com/claude-code) on your server as an
administration assistant. Your server appears as a **Remote Control** device in the Claude app, so you can
ask questions, diagnose problems and (with your approval) make changes from your phone or browser, and the
Unraid WebGUI gets its own chat tab and an "ask the assistant" button.

> **Unofficial.** This is a community project. It is not affiliated with, endorsed by or sponsored by
> Anthropic or Lime Technology. "Claude" and "Claude Code" are trademarks of Anthropic, "Unraid" is a
> trademark of Lime Technology, Inc.

**Requirements:** a Claude subscription that includes Claude Code with Remote Control (you log in with your
claude.ai account; API keys are not used), an x86_64 Unraid server with internet access, and `curl`, `php`,
`flock` and `script` (all present on stock Unraid). Works on any recent Unraid version: the plugin is
version agnostic and uses only bash and the PHP CLI that ship with Unraid (no Node.js, no `jq`).

## Features

- **Remote Control device.** The server shows up in the Claude app under a name you choose. Sessions started
  there run on the server with the same memory, hooks and safety rules as the WebGUI chat.
- **First-run discovery and persistent memory.** On first enable the assistant explores the server read-only
  (hardware, array and pools, shares, containers, VMs, plugins, network, ...) with live progress, and writes
  what it learns to a small set of Markdown memory files. Later answers come from memory instead of
  re-scanning. You can read and edit the memory in the WebGUI. Memory never stores secret values.
- **Cheaper-model delegation.** The main model plans and verifies; a *worker* agent (default Sonnet) executes
  approved steps and a *scout* agent (default Haiku) does the bulk reading. All three models are configurable.
- **WebGUI chat tab and header button.** A chat tab inside the plugin, plus a button in the WebGUI header
  (or a floating button, or both; keyboard shortcut Alt+A) that opens a side panel next to whatever page you
  are on. The panel can send the visible page text and, optionally, a screenshot of the page along with your
  question ("why is this disk red?").
- **Permission modes.** Per conversation: Manual (read-only commands run, everything else asks you), Accept
  edits, Auto (a classifier approves routine actions) or Plan. Bypass modes are not offered. Sessions from the
  Claude app have their own default mode.
- **Destructive-action blocking.** A pre-tool hook refuses commands such as `mkfs`, `wipefs`, `dd` to a
  device, `rm -rf` on `/mnt` or `/boot`, array and slot changes, `zpool/zfs destroy` and `docker prune`, in
  every permission mode. You can add your own patterns.
- **Secret masking.** A helper (`ua-sanitize`) runs read-only commands and masks secret-looking values
  (passwords, tokens, keys, credentials in URLs, ...) in container environments, compose files and configs
  before the assistant sees them. Memory writes that look like secrets are blocked.
- **Activity log.** Every command and file change the assistant makes is recorded (and sent to the syslog),
  viewable in the WebGUI.
- **Unraid notification explanations and gap checks.** New warning and alert notifications get a short
  explanation of what is going on and what to do. Periodic gap checks look for restarting or unhealthy
  containers and new syslog errors, and only involve Claude when something is found. Both can be switched off.
- **Works with the array stopped.** Everything runs from RAM with state mirrored to the flash drive, so the
  assistant can help you while the array is stopped, and it releases `/mnt` when the array stops so it never
  blocks unmounting.
- **Small, flash-friendly storage.** Only small state files are written to flash, and only when they change.
- **Export, import and wipe.** Back up or move your settings and memory (optionally with the login), or delete
  everything with one confirmed action.
- **English and Dutch** user interface.

## Installation

### Community Applications

Once the plugin is listed, search for "AI Assistant" in the **Apps** tab and click Install.

### Manual

In the Unraid WebGUI go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/DevstateBelgium/unraid-ai-assistant/main/plugin/ai-assistant-claude.plg
```

Updates then appear in **Plugins** like any other plugin.

## First steps

1. Open **Utilities > AI Assistant**.
2. **Install Claude.** Click *Install Claude*. The Claude Code binary is downloaded once (it is not part of
   the plugin) and cached on the flash drive.
3. **Log in.** Click *Start login*, open the link, sign in with your claude.ai account, copy the code the page
   shows and paste it into the plugin. No password is typed into Unraid.
4. **Enable.** Click *Enable*. Within a minute the server shows up as a device in the Claude app.
5. **Discovery.** The first run explores the server read-only and builds the memory; progress is shown live.
   You can use the assistant meanwhile. *Run discovery again* after major changes.

Then open the **Chat** tab, use the header button (Alt+A) on any page, or pick the device in the Claude app.

## Settings reference

Settings live in **Utilities > AI Assistant > Settings** and are stored in
`/boot/config/plugins/ai-assistant-claude/ai-assistant-claude.cfg`. Saving restarts the assistant if it runs.

| Key | Default | Meaning |
| --- | --- | --- |
| `ENABLED` | `no` | Whether the assistant is started at boot and kept running. |
| `DEVICE_NAME` | empty | Name shown in the Claude app. Empty means `<hostname>-assistant`. |
| `RUN_USER` | `root` | Unix user the assistant runs as. A regular user limits the damage but may not be able to read some system information. |
| `WORK_DIR` | `/var/lib/ai-assistant-claude/work` | Working directory of the device and chat sessions. Keep it in RAM; a directory under `/mnt` can block stopping the array. |
| `SPAWN_MODE` | `same-dir` | How Remote Control sessions are placed: `same-dir`, `worktree` or `session`. |
| `CAPACITY` | `32` | Maximum number of parallel Remote Control sessions. |
| `MAIN_MODEL` | empty | Model for the main (planning) agent. Empty lets Claude Code decide. |
| `WORKER_MODEL` | `sonnet` | Model for the worker agent that executes approved steps. |
| `SCOUT_MODEL` | `haiku` | Model for the read-only scout agent that gathers information. |
| `AUTO_UPDATE` | `yes` | Update Claude Code before restarts and daily when idle; rolls back if the new version fails to connect. |
| `HISTORY_DIR` | empty | Optional directory (for example on a share) where session transcripts are copied while the array is started. Empty keeps them in RAM only. |
| `EXPLAIN_NOTIFICATIONS` | `yes` | Explain new Unraid warning and alert notifications. |
| `GAP_CHECKS` | `yes` | Periodically check for unhealthy containers and new syslog errors. |
| `SHOW_PAGE_BUTTON` | `yes` | Show the assistant button (Alt+A) on every WebGUI page while the assistant is enabled and logged in. |
| `BUTTON_STYLE` | `header` | Where the button appears: `header`, `floating` or `both`. |
| `CHAT_PERMISSION_MODE` | `default` | Starting permission mode of new WebGUI chats (`default` = Manual, `acceptEdits`, `auto`, `plan`). |
| `DEVICE_PERMISSION_MODE` | `default` | Permission mode of sessions started from the Claude app. Changing it restarts the assistant. |
| `EXTRA_BLOCKED_PATTERNS` | empty | Extra regular expressions (one per line) for commands that are always blocked, on top of the built-in list. |
| `DISCOVERY_DONE` | `no` | Set automatically after the first discovery. |
| `DISCOVERY_VERSION` | empty | Unraid version at the last discovery; set automatically. |
| `TZ` | empty | Time zone for the assistant. Empty uses the system time zone. |

The **Advanced** tab additionally allows a JSON overlay for the managed Claude Code `settings.json` and your own
text appended to the assistant's `CLAUDE.md` instructions.

## Security and privacy

Read this before enabling the plugin.

- **It runs as root by default.** With `RUN_USER=root` the assistant can do anything on your server that you
  approve, and in Auto mode anything its classifier approves. Choose a regular user if you can live with
  reduced visibility, and keep permission modes conservative. Remote Control means anyone who controls your
  Claude account can talk to your server: protect that account.
- **What is sent to Anthropic.** Everything the assistant works with goes to Anthropic's servers under your
  Claude account and Anthropic's terms and privacy policy: your prompts, the output of every tool the
  assistant runs (command output, file contents, logs), the memory files it reads, and, when you use the
  WebGUI panel, the **text of the page you are on and, if you tick the option, a screenshot of it**. The
  plugin has no server of its own and sends nothing anywhere else, apart from downloading Claude Code and
  checking for updates.
- **Masking has limits.** Container environments, compose files and configs read through `ua-sanitize` have
  secret-looking values masked, and secret-looking memory writes are blocked. Masking is pattern based. A
  plain-text secret that is visible on the page you share, sitting in a log or file the assistant reads in
  another way, or that you explicitly ask it to `cat`, can still be sent to Anthropic. Do not paste secrets
  into the chat.
- **Hooks are not a sandbox.** The destructive-command hook blocks known dangerous patterns, in every
  permission mode, but a determined or creative command can be missed. It is a safety net against mistakes, not
  a security boundary. Review what you approve.
- **Flash writes.** Settings, memory, the Claude login and a cached copy of the Claude Code binary are stored
  on the flash drive. State is mirrored from RAM every 10 minutes and only when a file changed; logs,
  transcripts and the working directory stay in RAM. The login credentials on flash allow access to your Claude
  account: treat the flash drive (and any export that includes credentials) accordingly.
- **No warranty.** See the license. Back up your data and do not rely on the assistant for irreversible
  operations.

## Storage layout

| Location | Contents |
| --- | --- |
| `/usr/local/emhttp/plugins/ai-assistant-claude/` | Plugin code (from the package, in RAM, reinstalled at every boot). |
| `/boot/config/plugins/ai-assistant-claude/` | Persistent data on flash: `ai-assistant-claude.cfg` (settings), cached package, `bin/claude` and `bin/claude.prev` (Claude Code binary and rollback copy), `state/config/` (login, `settings.json`, `CLAUDE.md`, agents) and `state/memory/` (the memory files). |
| `/var/lib/ai-assistant-claude/` | Runtime data in RAM: binary copy, live config and memory, `work/`, `run/` (pids, locks, status) and `logs/` (supervisor, discovery, activity, login, notifications). |
| `HISTORY_DIR` (optional) | Session transcripts, only while the array is started. |

## Uninstall and wipe

- **Remove** the plugin from **Plugins** to stop the assistant and remove its code and RAM data. Your settings,
  memory and login are **kept** in `/boot/config/plugins/ai-assistant-claude/` so a reinstall picks up where you
  left off.
- **Wipe** everything first with *Advanced > Danger zone > Delete all data* (type `DELETE` to confirm). This
  logs out and deletes settings, memory, credentials and history, in flash, RAM and the history directory.
  Alternatively delete the flash folder by hand after removal.
- Remove the device from the Claude app (Remote Control device list) if you no longer need it.

## Building from source

Requires bash, PHP CLI (for the syntax check), `tar` and `xz`. On Unraid/Slackware the real `makepkg` is used;
elsewhere the build produces an equivalent package with `tar` and `xz`.

```bash
./build.sh 2026.10.03          # version = YYYY.MM.DD or YYYY.MM.DD[a-z]
./build.sh 2026.10.03 --local  # also writes dist/ai-assistant-claude-local.plg for offline testing
```

Output: `dist/ai-assistant-claude-<version>-noarch-1.txz` (+ `.sha256`), `dist/release-notes.md`, and the
regenerated `plugin/ai-assistant-claude.plg`. Builds are reproducible: the same sources and version give the
same checksum. The plugin definition is generated from `plugin/ai-assistant-claude.plg.in` and `CHANGELOG.md`;
edit those, not the `.plg`. The project layout and internal contracts are described in [DESIGN.md](DESIGN.md).

## Releasing

1. Add a `## YYYY.MM.DD` (or `YYYY.MM.DD[a-z]` for a second release the same day) section at the top of
   [CHANGELOG.md](CHANGELOG.md).
2. Commit to `main`, then tag and push the tag:
   ```bash
   git tag 2026.10.03 && git push origin 2026.10.03
   ```
3. The **Release** workflow lints the code, builds the package, creates the GitHub release with the package and
   checksum attached (notes come from the changelog), and only then commits the regenerated
   `plugin/ai-assistant-claude.plg` to `main`. Unraid servers pick up the update from that file. Re-running the
   workflow for the same tag is safe.

## Listing in Community Applications

This section is for the project owner. The template is `ca/ai-assistant-claude.xml`. Remaining manual steps:

1. Create the support thread in the Unraid forum (Plugin Support area) and note its URL.
2. Put that URL in `SUPPORT_URL` (change the default near the top of `build.sh`; the
   generated `.plg` then carries it), in the `<Support>` element of `ca/ai-assistant-claude.xml`, and in the
   "Support" section below.
3. Submit the template repository to Community Applications via the CA submission process (see the CA support
   thread in the Unraid forum). CA reads the template from this repository, so later changes need no new
   submission.

## License

MIT, see [LICENSE](LICENSE). The bundled `html2canvas-pro` library keeps its own license
(`js/vendor/html2canvas-pro.LICENSE`).

## Support

Support thread: _link to be added once the Unraid forum thread exists._ Bugs and ideas:
[GitHub issues](https://github.com/DevstateBelgium/unraid-ai-assistant/issues).

Each release has a `## YYYY.MM.DD` (or `YYYY.MM.DD[a-z]`) heading. The text under the heading becomes the
GitHub release notes and the plugin's change log in the Unraid WebGUI. Newest entry first.

## 2026.10.03a
- Gap check now watches the RAM filesystems (`/var/log`, `/`, `/run`, `/tmp`): a warning at 80% full (setting `LOGFS_WARN_PCT`) and an alert at 95%.
- Log flood detection: `/var/log` growing by more than 10 MB between checks (setting `LOGFS_GROWTH_MB`), or one file by half of that, is flagged. The most repeated line, and for nginx the top request path, referrer and client (query strings stripped, secrets masked), are handed to the analysis, which explains the likely source (such as a browser tab stuck in an error loop) and suggests safe steps. Nothing is ever deleted or truncated without asking.
- New settings fields under the notification options, English and Dutch.

## 2026.10.03
- First public release.
- Runs Claude Code as a Remote Control device, so you can talk to your server from the Claude app.
- WebGUI: chat tab, "ask the assistant" button in the page header (Alt+A) that sends the page text or a screenshot along with your question, settings, memory editor, activity log.
- First-run discovery of the server with persistent memory, cheaper-model delegation (worker and scout agents).
- Permission modes, hard-blocked destructive commands, secret masking, Unraid notification explanations and gap checks.
- Works while the array is stopped (RAM plus flash storage). Export, import and wipe of all data. English and Dutch.
- Unofficial; not affiliated with Anthropic or Lime Technology.

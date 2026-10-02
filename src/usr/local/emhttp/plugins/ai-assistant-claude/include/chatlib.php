<?php
/* AI Assistant for Unraid (for Claude Code) - chat helpers shared by include/chat.php (HTTP),
 * scripts/chat-worker.php (detached runner) and mcp/approve.php (permission server).
 * PHP 7.4 - 8.4. Needs include/common.php; hooks/hooklib.php is loaded lazily for aia_mask().
 *
 * Runtime files (all under AIA_RAM/run/chat/):
 *   <run>.meta.json      run state {run_id, session_id, pid, pgid, state, started, ended, source, error}
 *   <run>.in.json        the stream-json user line for claude (removed by the worker once sent)
 *   <run>.jsonl          events: raw claude stream-json lines + our own {"type":"aia","kind":...} lines
 *   <run>.mcp.json       generated --mcp-config
 *   <run>.stop           stop flag (created by chat_stop, polled by the worker)
 *   perm/<run>.<id>.req.json   permission request (written by mcp/approve.php, owned by RUN_USER)
 *   perm/<run>.<id>.dec.json   decision (written by chat_approve)
 * Session index (our own): AIA_RAM/config/chat-index.json  {<uuid>: {source, mode, archived, title, t}}, mirrored to flash by
 * scripts/sync-state.sh, so titles, modes and archived flags survive a reboot. Written only when something changes.
 * Deleted conversations are moved (not removed) to AIA_RAM/config/projects-trash/<uuid>/ and purged after 7 days.
 */

require_once __DIR__ . '/common.php';

define('AIA_CHAT_MAX_RUN_SECONDS', 1800);
define('AIA_CHAT_MAX_IMAGE_BYTES', 3 * 1024 * 1024);
define('AIA_CHAT_MAX_CONTEXT_BYTES', 40000);
define('AIA_CHAT_MAX_TEXT_BYTES', 60000);
define('AIA_CHAT_MAX_ACTIVE_RUNS', 4);
define('AIA_CHAT_POLL_BYTES', 400000);

function aia_chat_hooklib()
{
    if (!function_exists('aia_mask')) {
        require_once AIA_EMHTTP . '/hooks/hooklib.php';
    }
}

function aia_chat_mask($s)
{
    aia_chat_hooklib();
    return function_exists('aia_mask') ? (string)aia_mask((string)$s) : (string)$s;
}

/* ------------------------------------------------------------------ paths */

function aia_chat_dir()
{
    return AIA_RAM . '/run/chat';
}

function aia_chat_perm_dir()
{
    return aia_chat_dir() . '/perm';
}

function aia_chat_index_file()
{
    return aia_chat_config_dir() . '/chat-index.json';
}

function aia_chat_config_dir()
{
    return AIA_RAM . '/config';
}

function aia_chat_run_id_ok($id)
{
    return is_string($id) && (bool)preg_match('/^[a-f0-9]{16}$/', $id);
}

function aia_chat_sess_id_ok($id)
{
    return is_string($id) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id);
}

function aia_chat_req_id_ok($id)
{
    return is_string($id) && (bool)preg_match('/^[a-f0-9]{8,32}$/', $id);
}

function aia_chat_uuid()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/** Claude Code's project directory name for a cwd: every non-alphanumeric character becomes "-". */
function aia_chat_encode_cwd($cwd)
{
    return preg_replace('/[^A-Za-z0-9]/', '-', $cwd);
}

/** Directory holding the session transcripts for the assistant's WORK_DIR. */
function aia_chat_projects_dir($workDir = null)
{
    if ($workDir === null) {
        $cfg = aia_cfg();
        $workDir = $cfg['WORK_DIR'];
    }
    $base = aia_chat_config_dir() . '/projects';
    $cands = array($workDir);
    $rp = @realpath($workDir);
    if ($rp !== false && $rp !== $workDir) {
        $cands[] = $rp;
    }
    foreach ($cands as $c) {
        $d = $base . '/' . aia_chat_encode_cwd($c);
        if (is_dir($d)) {
            return $d;
        }
    }
    // Claude shortens (and hashes) names over 200 characters: fall back to the 200-character prefix
    $enc = aia_chat_encode_cwd($cands[count($cands) - 1]);
    if (strlen($enc) > 200) {
        $g = @glob($base . '/' . substr($enc, 0, 200) . '*', GLOB_ONLYDIR);
        if ($g) {
            return $g[0];
        }
    }
    return $base . '/' . aia_chat_encode_cwd($cands[count($cands) - 1]);
}

function aia_chat_ensure_dirs($runUser)
{
    $d = aia_chat_dir();
    $p = aia_chat_perm_dir();
    foreach (array(dirname($d), $d, $p) as $x) {
        if (!is_dir($x)) {
            @mkdir($x, 0755, true);
        }
    }
    @chmod($d, 0755);
    // the permission server runs as RUN_USER and must be able to drop request files here
    if ($runUser !== '' && $runUser !== 'root' && function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($runUser);
        if (is_array($pw)) {
            @chown($p, $pw['uid']);
            @chgrp($p, $pw['gid']);
            @chmod($p, 0700);
            return;
        }
    }
    @chmod($p, 0755);
}

/* ------------------------------------------------------------------ small file helpers */

/** Atomic write that never follows a pre-planted symlink at the temp name (exclusive create + rename). */
function aia_chat_write($path, $data, $mode = 0644)
{
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $fh = @fopen($tmp, 'xb');
    if (!$fh) {
        return false;
    }
    $ok = fwrite($fh, $data) === strlen($data);
    fclose($fh);
    @chmod($tmp, $mode);
    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Read a regular, non-symlink file (size capped). Returns null otherwise. */
function aia_chat_read_plain($path, $max = 1048576)
{
    $st = @lstat($path);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['size'] > $max) {
        return null;
    }
    $d = @file_get_contents($path);
    return $d === false ? null : $d;
}

function aia_chat_read_json($path, $max = 1048576)
{
    $d = aia_chat_read_plain($path, $max);
    if ($d === null) {
        return null;
    }
    $j = json_decode($d, true);
    return is_array($j) ? $j : null;
}

/** Re-entrant exclusive lock on run/chat/.lock (a nested call in the same process just bumps a counter). */
function aia_chat_lock()
{
    global $AIA_CHAT_LOCK;
    if (!is_array($AIA_CHAT_LOCK)) {
        $AIA_CHAT_LOCK = array('fh' => null, 'n' => 0);
    }
    if ($AIA_CHAT_LOCK['n'] > 0) {
        $AIA_CHAT_LOCK['n']++;
        return true;
    }
    $dir = aia_chat_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fh = @fopen($dir . '/.lock', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    $AIA_CHAT_LOCK = array('fh' => $fh, 'n' => 1);
    return true;
}

function aia_chat_unlock($token = null)
{
    global $AIA_CHAT_LOCK;
    if (!is_array($AIA_CHAT_LOCK) || $AIA_CHAT_LOCK['n'] < 1) {
        return;
    }
    if (--$AIA_CHAT_LOCK['n'] === 0 && $AIA_CHAT_LOCK['fh']) {
        flock($AIA_CHAT_LOCK['fh'], LOCK_UN);
        fclose($AIA_CHAT_LOCK['fh']);
        $AIA_CHAT_LOCK['fh'] = null;
    }
}

/* ------------------------------------------------------------------ session index (source tags) */

/** The permission modes the WebGUI offers. bypassPermissions and dontAsk are deliberately not among them. */
function aia_chat_modes()
{
    return array('default', 'acceptEdits', 'auto', 'plan');
}

function aia_chat_mode_ok($m)
{
    return is_string($m) && in_array($m, aia_chat_modes(), true);
}

/** Default mode for new conversations (CHAT_PERMISSION_MODE, whitelisted). */
function aia_chat_default_mode($cfg = null)
{
    if ($cfg === null) {
        $cfg = aia_cfg();
    }
    return aia_chat_mode_ok($cfg['CHAT_PERMISSION_MODE'] ?? '') ? $cfg['CHAT_PERMISSION_MODE'] : 'default';
}

function aia_chat_index_read()
{
    $j = aia_chat_read_json(aia_chat_index_file());
    return is_array($j) ? $j : array();
}

function aia_chat_title_clean($t)
{
    $t = preg_replace('/[\x00-\x1f\x7f]+/', ' ', aia_chat_utf8($t));
    return aia_chat_clip(trim(preg_replace('/\s+/', ' ', $t)), 120);
}

/**
 * Merge $changes into the index entry of $sid (created when missing). A null value removes the key.
 * The file is rewritten only when the content really changed (it is mirrored to flash).
 * Returns the resulting entry.
 */
function aia_chat_index_update($sid, array $changes)
{
    $fh = aia_chat_lock();
    $idx = aia_chat_index_read();
    $old = $idx;
    $e = isset($idx[$sid]) && is_array($idx[$sid]) ? $idx[$sid] : array('t' => time());
    foreach ($changes as $k => $v) {
        if ($v === null || $v === false || $v === '') {
            unset($e[$k]);
        } else {
            $e[$k] = $v;
        }
    }
    $idx[$sid] = $e;
    if (count($idx) > 3000) {
        // keep the file small: drop the oldest entries without a custom title or archive flag first
        uasort($idx, function ($a, $b) {
            $ka = (isset($a['title']) || isset($a['archived'])) ? 1 : 0;
            $kb = (isset($b['title']) || isset($b['archived'])) ? 1 : 0;
            return $ka !== $kb ? $kb - $ka : ((int)($b['t'] ?? 0)) - ((int)($a['t'] ?? 0));
        });
        $idx = array_slice($idx, 0, 2500, true);
        if (!isset($idx[$sid])) {
            $idx[$sid] = $e;
        }
    }
    if ($idx !== $old) {
        if (!is_dir(dirname(aia_chat_index_file()))) {
            @mkdir(dirname(aia_chat_index_file()), 0755, true);
        }
        aia_chat_write(aia_chat_index_file(), json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    aia_chat_unlock($fh);
    return $e;
}

/** Forget a conversation (after it was deleted). */
function aia_chat_index_remove($sid)
{
    $fh = aia_chat_lock();
    $idx = aia_chat_index_read();
    if (isset($idx[$sid])) {
        unset($idx[$sid]);
        aia_chat_write(aia_chat_index_file(), json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    aia_chat_unlock($fh);
}
/** Create the entry for a new conversation (source + mode). Does nothing when it exists. */
function aia_chat_index_set($sid, $source, $mode = null)
{
    $idx = aia_chat_index_read();
    if (isset($idx[$sid])) {
        return;
    }
    $c = array('source' => $source);
    if ($mode !== null && aia_chat_mode_ok($mode)) {
        $c['mode'] = $mode;
    }
    aia_chat_index_update($sid, $c);
}

/** Mode a conversation runs in: its own, else the configured default for new ones. */
function aia_chat_session_mode($sid, $idx = null, $cfg = null)
{
    if ($idx === null) {
        $idx = aia_chat_index_read();
    }
    if (isset($idx[$sid]['mode']) && aia_chat_mode_ok($idx[$sid]['mode'])) {
        return $idx[$sid]['mode'];
    }
    return aia_chat_default_mode($cfg);
}

/* ------------------------------------------------------------------ archive / delete / rename */

function aia_chat_trash_dir()
{
    return aia_chat_config_dir() . '/projects-trash';
}

/** Stop the running run of a session (if any). Returns the run's final state or '' when nothing ran. */
function aia_chat_stop_session($sid)
{
    $m = aia_chat_active_for_session($sid);
    if (!$m) {
        return '';
    }
    $r = aia_chat_stop_run($m['run_id']);
    return (string)$r;
}

/** Ask the worker of $run to stop and wait for it. Returns the final state. */
function aia_chat_stop_run($run)
{
    $meta = aia_chat_meta($run);
    if (!$meta) {
        return 'unknown';
    }
    if (($meta['state'] ?? '') !== 'running') {
        return (string)$meta['state'];
    }
    aia_chat_write(aia_chat_dir() . '/' . $run . '.stop', (string)time());
    // the worker polls the flag every 250 ms and tears down claude's process group
    for ($i = 0; $i < 30; $i++) {
        usleep(200000);
        $m = aia_chat_meta($run);
        if (!$m || ($m['state'] ?? '') !== 'running') {
            return $m ? (string)$m['state'] : 'done';
        }
    }
    $pgid = (int)($meta['pgid'] ?? 0);
    if ($pgid > 1 && function_exists('posix_kill')) {
        @posix_kill(-$pgid, 15);
        usleep(500000);
        @posix_kill(-$pgid, 9);
    }
    $m = aia_chat_meta($run);
    if ($m && ($m['state'] ?? '') === 'running') {
        aia_chat_finalize_dead($m);
    }
    return 'stopped';
}

/** rm -rf for a directory we own, never following symlinks. */
function aia_chat_rmtree($p)
{
    if (is_link($p) || is_file($p)) {
        return @unlink($p);
    }
    if (!is_dir($p)) {
        return true;
    }
    foreach ((array)@scandir($p) as $x) {
        if ($x !== '.' && $x !== '..') {
            aia_chat_rmtree($p . '/' . $x);
        }
    }
    return @rmdir($p);
}

/**
 * Move a conversation to the trash: <sid>.jsonl and the <sid>/ directory (sub-agent transcripts, tool results).
 * Also removes the copy in HISTORY_DIR (otherwise sync-state would copy it straight back). Returns true when something moved.
 */
function aia_chat_trash_session($sid)
{
    if (!aia_chat_sess_id_ok($sid)) {
        return false;
    }
    $pdir = aia_chat_projects_dir();
    $tdir = aia_chat_trash_dir() . '/' . strtolower($sid);
    if (!is_dir($tdir) && !@mkdir($tdir, 0700, true)) {
        return false;
    }
    $moved = false;
    $src = $pdir . '/' . $sid . '.jsonl';
    if (is_file($src) && !is_link($src)) {
        $moved = @rename($src, $tdir . '/' . $sid . '.jsonl') || $moved;
    }
    $sub = $pdir . '/' . $sid;
    if (is_dir($sub) && !is_link($sub)) {
        $moved = @rename($sub, $tdir . '/data') || $moved;
    }
    @file_put_contents($tdir . '/meta.json', json_encode(array('deleted' => time(), 'project' => basename($pdir), 'sid' => $sid)));
    @touch($tdir);
    // HISTORY_DIR copy
    $cfg = aia_cfg();
    $h = trim((string)$cfg['HISTORY_DIR']);
    if ($h !== '' && strncmp($h, '/mnt/', 5) === 0) {
        $hp = rtrim($h, '/') . '/projects/' . basename($pdir);
        @unlink($hp . '/' . $sid . '.jsonl');
        if (is_dir($hp . '/' . $sid) && !is_link($hp . '/' . $sid)) {
            aia_chat_rmtree($hp . '/' . $sid);
        }
    }
    if (!$moved) {
        @rmdir($tdir);
    }
    return $moved;
}

/** Drop trash older than 7 days (also done by sync-state.sh every 10 minutes). */
function aia_chat_trash_purge($maxAge = 604800)
{
    $base = aia_chat_trash_dir();
    foreach ((array)@glob($base . '/*', GLOB_ONLYDIR) as $d) {
        if (!is_link($d) && time() - (int)@filemtime($d) > $maxAge) {
            aia_chat_rmtree($d);
        }
    }
}
/* ------------------------------------------------------------------ runs */

function aia_chat_meta_path($run)
{
    return aia_chat_dir() . '/' . $run . '.meta.json';
}

function aia_chat_meta($run)
{
    if (!aia_chat_run_id_ok($run)) {
        return null;
    }
    return aia_chat_read_json(aia_chat_meta_path($run));
}

function aia_chat_meta_save($run, array $meta)
{
    return aia_chat_write(aia_chat_meta_path($run), json_encode($meta));
}

function aia_chat_pid_alive($pid)
{
    $pid = (int)$pid;
    if ($pid < 2) {
        return false;
    }
    if (is_readable('/proc/' . $pid . '/cmdline')) {
        $c = (string)@file_get_contents('/proc/' . $pid . '/cmdline');
        return $c !== '' && strpos($c, 'chat-worker') !== false;
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
}

/** All runs that are still going (worker alive). Finalises runs whose worker died. */
function aia_chat_active_runs()
{
    $out = array();
    foreach ((array)@glob(aia_chat_dir() . '/*.meta.json') as $f) {
        $m = aia_chat_read_json($f);
        if (!$m || empty($m['run_id']) || ($m['state'] ?? '') !== 'running') {
            continue;
        }
        if (aia_chat_pid_alive($m['pid'] ?? 0)) {
            $out[] = $m;
        } elseif (time() - (int)($m['started'] ?? 0) > 20) {
            aia_chat_finalize_dead($m);
        }
    }
    return $out;
}

/** The worker vanished without writing its end marker: write one so pollers stop. */
function aia_chat_finalize_dead(array $m)
{
    $run = $m['run_id'];
    $fh = aia_chat_lock();
    $cur = aia_chat_meta($run);
    if ($cur && ($cur['state'] ?? '') === 'running') {
        @file_put_contents(aia_chat_dir() . '/' . $run . '.jsonl',
            json_encode(array('type' => 'aia', 'kind' => 'end', 'ok' => false, 'reason' => 'worker_died', 't' => time())) . "\n",
            FILE_APPEND);
        $cur['state'] = 'failed';
        $cur['ended'] = time();
        $cur['error'] = 'worker_died';
        aia_chat_meta_save($run, $cur);
        aia_chat_cleanup_perms($run);
    }
    aia_chat_unlock($fh);
}

function aia_chat_active_for_session($sid)
{
    foreach (aia_chat_active_runs() as $m) {
        if (($m['session_id'] ?? '') === $sid) {
            return $m;
        }
    }
    return null;
}

function aia_chat_cleanup_perms($run)
{
    foreach ((array)@glob(aia_chat_perm_dir() . '/' . $run . '.*') as $f) {
        @unlink($f);
    }
}

/** Drop run files older than $maxAge seconds (finished runs only). */
function aia_chat_gc($maxAge = 86400)
{
    $now = time();
    foreach ((array)@glob(aia_chat_dir() . '/*.meta.json') as $f) {
        $m = aia_chat_read_json($f);
        if (!$m || ($m['state'] ?? '') === 'running') {
            continue;
        }
        $t = (int)($m['ended'] ?? $m['started'] ?? 0);
        if ($t && $now - $t > $maxAge) {
            $run = $m['run_id'] ?? '';
            if (aia_chat_run_id_ok($run)) {
                foreach ((array)@glob(aia_chat_dir() . '/' . $run . '.*') as $x) {
                    @unlink($x);
                }
                aia_chat_cleanup_perms($run);
            }
        }
    }
}

/* ------------------------------------------------------------------ auth / readiness */

/** Cheap "is somebody logged in" check: run/auth.json if present, else the credentials file. */
function aia_chat_logged_in()
{
    $j = aia_chat_read_json(AIA_RAM . '/run/auth.json', 65536);
    if ($j !== null && array_key_exists('loggedIn', $j)) {
        return $j['loggedIn'] === true;
    }
    return is_file(aia_chat_config_dir() . '/.credentials.json');
}

function aia_chat_claude_installed()
{
    return is_file(AIA_RAM . '/bin/claude') || is_file(AIA_FLASH . '/bin/claude');
}

/* ------------------------------------------------------------------ message building */

/**
 * Validate a data URL image. Returns array(media_type, base64) or a string error.
 */
function aia_chat_image_block($dataUrl)
{
    if (!is_string($dataUrl) || strlen($dataUrl) > AIA_CHAT_MAX_IMAGE_BYTES * 4 / 3 + 200) {
        return 'Image is too large (max 3 MB).';
    }
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$~', $dataUrl, $m)) {
        return 'Unsupported image (JPEG, PNG or WebP only).';
    }
    $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bin === false || $bin === '') {
        return 'Invalid image data.';
    }
    if (strlen($bin) > AIA_CHAT_MAX_IMAGE_BYTES) {
        return 'Image is too large (max 3 MB).';
    }
    // trust the magic bytes, not the claimed type
    if (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) {
        $type = 'image/jpeg';
    } elseif (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0) {
        $type = 'image/png';
    } elseif (strncmp($bin, 'RIFF', 4) === 0 && substr($bin, 8, 4) === 'WEBP') {
        $type = 'image/webp';
    } else {
        return 'Unsupported image (JPEG, PNG or WebP only).';
    }
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($bin);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000) {
            return 'Invalid image data.';
        }
    }
    return array($type, base64_encode($bin));
}

function aia_chat_clip($s, $n)
{
    $s = (string)$s;
    if (strlen($s) <= $n) {
        return $s;
    }
    $s = substr($s, 0, $n);
    // do not cut in the middle of a UTF-8 sequence
    while ($s !== '' && (ord($s[strlen($s) - 1]) & 0xC0) === 0x80) {
        $s = substr($s, 0, -1);
    }
    return $s;
}

function aia_chat_utf8($s)
{
    $s = (string)$s;
    if (function_exists('mb_convert_encoding') && !preg_match('//u', $s)) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    return str_replace("\0", '', $s);
}

/**
 * Build the stream-json user message. $context = array(page,title,tab,text,selection) or null.
 * Returns array(line, ctxMeta, hasImage) or a string error.
 */
function aia_chat_build_message($text, $context, $image)
{
    $blocks = array();
    $ctxMeta = null;
    if (is_array($context) && (isset($context['text']) || isset($context['page']))) {
        $page = aia_chat_clip(aia_chat_utf8(isset($context['page']) ? $context['page'] : ''), 300);
        $title = aia_chat_clip(aia_chat_utf8(isset($context['title']) ? $context['title'] : ''), 300);
        $tab = aia_chat_clip(aia_chat_utf8(isset($context['tab']) ? $context['tab'] : ''), 120);
        $ctext = aia_chat_clip(aia_chat_utf8(isset($context['text']) ? $context['text'] : ''), AIA_CHAT_MAX_CONTEXT_BYTES);
        $isSel = !empty($context['selection']);
        $ctext = aia_chat_mask($ctext);
        $head = '[Screen context - page: ' . aia_chat_mask(str_replace(array("\r", "\n", ']'), ' ', $page))
            . ', title: ' . aia_chat_mask(str_replace(array("\r", "\n", ']'), ' ', $title)) . ']';
        if ($tab !== '') {
            $head .= "\n[Active tab: " . str_replace(array("\r", "\n", ']'), ' ', $tab) . ']';
        }
        $head .= "\n" . ($isSel
            ? "The user selected this text on the screen and is asking about it:\n"
            : "Visible text of the page (secrets are masked):\n");
        $blocks[] = array('type' => 'text', 'text' => $head . $ctext);
        $ctxMeta = array('page' => $page, 'title' => $title, 'tab' => $tab, 'selection' => $isSel);
    }
    $blocks[] = array('type' => 'text', 'text' => aia_chat_clip(aia_chat_utf8($text), AIA_CHAT_MAX_TEXT_BYTES));
    $hasImage = false;
    if ($image !== null && $image !== '') {
        $img = aia_chat_image_block($image);
        if (is_string($img)) {
            return $img;
        }
        $blocks[] = array('type' => 'image', 'source' => array('type' => 'base64', 'media_type' => $img[0], 'data' => $img[1]));
        $hasImage = true;
    }
    $line = json_encode(array('type' => 'user', 'message' => array('role' => 'user', 'content' => $blocks)),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        return 'Could not encode the message.';
    }
    return array($line, $ctxMeta, $hasImage);
}

/* ------------------------------------------------------------------ normalisation (stream events + transcripts) */

function aia_chat_tool_summary($name, $input)
{
    if (!is_array($input)) {
        return '';
    }
    $pick = function ($keys) use ($input) {
        foreach ($keys as $k) {
            if (isset($input[$k]) && is_scalar($input[$k]) && (string)$input[$k] !== '') {
                return (string)$input[$k];
            }
        }
        return '';
    };
    switch ($name) {
        case 'Bash':
            $s = $pick(array('command'));
            break;
        case 'Read':
        case 'Write':
        case 'Edit':
        case 'MultiEdit':
        case 'NotebookEdit':
            $s = $pick(array('file_path', 'notebook_path', 'path'));
            break;
        case 'Glob':
            $s = $pick(array('pattern'));
            break;
        case 'Grep':
            $s = $pick(array('pattern')) . ($pick(array('path')) !== '' ? '  in ' . $pick(array('path')) : '');
            break;
        case 'Agent':
        case 'Task':
            $s = $pick(array('description', 'subagent_type', 'prompt'));
            break;
        case 'WebFetch':
            $s = $pick(array('url'));
            break;
        case 'WebSearch':
            $s = $pick(array('query'));
            break;
        case 'TodoWrite':
            $s = 'todo list';
            break;
        default:
            $s = $pick(array('description', 'command', 'file_path', 'path', 'query', 'url', 'pattern', 'name'));
            if ($s === '') {
                $j = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $s = $j === false ? '' : $j;
            }
    }
    $s = preg_replace('/\s+/', ' ', trim(aia_chat_utf8($s)));
    return aia_chat_clip(aia_chat_mask($s), 300);
}

function aia_chat_result_text($content, $max = 3000)
{
    if (is_array($content)) {
        $parts = array();
        foreach ($content as $b) {
            if (is_array($b) && isset($b['type']) && $b['type'] === 'text' && isset($b['text'])) {
                $parts[] = (string)$b['text'];
            } elseif (is_array($b) && isset($b['type']) && $b['type'] === 'image') {
                $parts[] = '[image]';
            } elseif (is_string($b)) {
                $parts[] = $b;
            }
        }
        $content = implode("\n", $parts);
    }
    $s = aia_chat_clip(aia_chat_utf8((string)$content), $max);
    return aia_chat_mask($s);
}

/** True for transcript user text that is harness plumbing and not something the user typed. */
function aia_chat_is_plumbing($t)
{
    return (bool)preg_match('/^\s*<(command-name|command-message|local-command|system-reminder|task-notification|bash-input|bash-stdout)/', $t);
}

/** Split a user text block into [ctx|null, text]. */
function aia_chat_parse_ctx($t)
{
    if (strncmp($t, '[Screen context', 15) === 0) {
        $page = '';
        $title = '';
        if (preg_match('/^\[Screen context - page: (.*?), title: (.*?)\]/', $t, $m)) {
            $page = $m[1];
            $title = $m[2];
        }
        return array(array('page' => $page, 'title' => $title), null);
    }
    return array(null, $t);
}

/**
 * Convert one claude stream-json event (or transcript record) to UI items.
 * Items: user{text,ctx,image} text{text,parent} tool{id,name,summary,parent} result{id,text,error} note{level,text}
 */
function aia_chat_normalize($ev, $transcript = false)
{
    $items = array();
    if (!is_array($ev) || !isset($ev['type'])) {
        return $items;
    }
    $type = $ev['type'];
    $parent = isset($ev['parent_tool_use_id']) && is_string($ev['parent_tool_use_id']) ? $ev['parent_tool_use_id'] : '';
    if ($type === 'aia') {
        $kind = isset($ev['kind']) ? $ev['kind'] : '';
        if ($kind === 'user') {
            $items[] = array('k' => 'user', 'text' => (string)($ev['text'] ?? ''), 'ctx' => isset($ev['ctx']) ? $ev['ctx'] : null,
                'image' => !empty($ev['image']));
        } elseif ($kind === 'end') {
            if (empty($ev['ok'])) {
                $why = isset($ev['error']) ? (string)$ev['error'] : ((isset($ev['reason']) && $ev['reason'] === 'worker_died') ? 'The chat worker stopped unexpectedly.' : 'The run ended unexpectedly.');
                $items[] = array('k' => 'note', 'level' => 'error', 'text' => $why);
            }
        } elseif ($kind === 'note') {
            $items[] = array('k' => 'note', 'level' => (string)($ev['level'] ?? 'info'), 'text' => (string)($ev['text'] ?? ''));
        }
        return $items;
    }
    if ($type === 'result') {
        if (!empty($ev['is_error'])) {
            $t = isset($ev['result']) && is_string($ev['result']) && $ev['result'] !== '' ? $ev['result'] : (string)($ev['subtype'] ?? 'error');
            $items[] = array('k' => 'note', 'level' => 'error', 'text' => aia_chat_clip(aia_chat_mask($t), 1500));
        }
        return $items;
    }
    if ($type !== 'assistant' && $type !== 'user') {
        return $items;
    }
    if ($transcript && (!empty($ev['isSidechain']) || !empty($ev['isMeta']))) {
        return $items;
    }
    $msg = isset($ev['message']) && is_array($ev['message']) ? $ev['message'] : null;
    if (!$msg || !isset($msg['content'])) {
        return $items;
    }
    $content = $msg['content'];
    if (is_string($content)) {
        $content = array(array('type' => 'text', 'text' => $content));
    }
    if (!is_array($content)) {
        return $items;
    }
    $userCtx = null;
    $userText = null;
    $userImage = false;
    foreach ($content as $b) {
        if (!is_array($b) || !isset($b['type'])) {
            continue;
        }
        switch ($b['type']) {
            case 'text':
                $t = (string)($b['text'] ?? '');
                if ($t === '') {
                    break;
                }
                if ($type === 'assistant') {
                    $items[] = array('k' => 'text', 'text' => aia_chat_clip(aia_chat_utf8($t), 60000), 'parent' => $parent);
                } else {
                    if ($transcript && aia_chat_is_plumbing($t)) {
                        break;
                    }
                    list($ctx, $plain) = aia_chat_parse_ctx($t);
                    if ($ctx !== null) {
                        $userCtx = $ctx;
                    } else {
                        $userText = ($userText === null ? '' : $userText . "\n") . $plain;
                    }
                }
                break;
            case 'image':
                $userImage = true;
                break;
            case 'tool_use':
                $items[] = array('k' => 'tool', 'id' => (string)($b['id'] ?? ''), 'name' => (string)($b['name'] ?? '?'),
                    'summary' => aia_chat_tool_summary((string)($b['name'] ?? ''), isset($b['input']) ? $b['input'] : null),
                    'parent' => $parent);
                break;
            case 'tool_result':
                $items[] = array('k' => 'result', 'id' => (string)($b['tool_use_id'] ?? ''),
                    'text' => aia_chat_result_text(isset($b['content']) ? $b['content'] : ''), 'error' => !empty($b['is_error']),
                    'parent' => $parent);
                break;
        }
    }
    if ($type === 'user' && ($userText !== null || $userCtx !== null || $userImage) && !$transcript) {
        // live stream does not echo user text unless --replay-user-messages; we write our own 'user' event instead
        return $items;
    }
    if ($type === 'user' && ($userText !== null || $userImage)) {
        array_unshift($items, array('k' => 'user', 'text' => (string)$userText, 'ctx' => $userCtx, 'image' => $userImage));
    }
    return $items;
}

/** Read the tail of a transcript and return normalised items (last $maxItems). */
function aia_chat_history_items($file, $maxItems = 600)
{
    $items = array();
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return $items;
    }
    $size = filesize($file);
    // transcripts can reach tens of MB: only parse the last 6 MB, starting at a line boundary
    $maxBytes = 6 * 1024 * 1024;
    if ($size > $maxBytes) {
        fseek($fh, $size - $maxBytes);
        fgets($fh);
    }
    while (($line = fgets($fh)) !== false) {
        if ($line === '' || $line[0] !== '{') {
            continue;
        }
        $j = json_decode($line, true);
        if (!is_array($j)) {
            continue;
        }
        foreach (aia_chat_normalize($j, true) as $it) {
            $items[] = $it;
        }
    }
    fclose($fh);
    if (count($items) > $maxItems) {
        $items = array_slice($items, -$maxItems);
    }
    return $items;
}

/* ------------------------------------------------------------------ session listing */

/** Heads of the system prompts we send ourselves (discovery, notification explanations, gap checks). */
function aia_chat_system_prompt_heads()
{
    static $heads = null;
    if ($heads !== null) {
        return $heads;
    }
    $heads = array();
    foreach (array('discovery-prompt.md', 'notify-prompt.md', 'gapcheck-prompt.md') as $f) {
        $p = AIA_EMHTTP . '/templates/' . $f;
        if (is_readable($p)) {
            $t = trim((string)@file_get_contents($p, false, null, 0, 400));
            $first = trim((string)strtok($t, "\n"));
            if (strlen($first) >= 20) {
                $heads[] = substr($first, 0, 40);
            }
        }
    }
    return $heads;
}

/**
 * Read the start of a transcript: first user text (title), whether the session has any user text.
 */
function aia_chat_peek($file)
{
    $title = '';
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return null;
    }
    $read = 0;
    while (($line = fgets($fh)) !== false && $read < 262144) {
        $read += strlen($line);
        if ($line[0] !== '{' || strpos($line, '"user"') === false) {
            continue;
        }
        $j = json_decode($line, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'user' || !empty($j['isSidechain']) || !empty($j['isMeta'])) {
            continue;
        }
        $c = $j['message']['content'] ?? null;
        if (is_string($c)) {
            $c = array(array('type' => 'text', 'text' => $c));
        }
        if (!is_array($c)) {
            continue;
        }
        foreach ($c as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text' && isset($b['text'])) {
                $t = (string)$b['text'];
                if (aia_chat_is_plumbing($t) || strncmp($t, '[Screen context', 15) === 0) {
                    continue;
                }
                $title = trim(preg_replace('/\s+/', ' ', $t));
                break 2;
            }
        }
    }
    fclose($fh);
    return $title;
}

/** Is a process other than our own chat runs holding this session? (best effort) */
function aia_chat_external_active($sid, $mtime)
{
    // 1) a claude process that mentions the id on its command line (--resume <id> / --session-id <id>); one /proc scan per request
    static $cmdlines = null;
    if ($cmdlines === null) {
        $cmdlines = '';
        foreach ((array)@glob('/proc/[0-9]*/cmdline') as $f) {
            $c = @file_get_contents($f, false, null, 0, 4096);
            if ($c !== false && strpos($c, 'claude') !== false && strpos($c, 'mcp__aia__approve') === false) {
                $cmdlines .= str_replace("\0", ' ', $c) . "\n";
            }
        }
    }
    if ($cmdlines !== '' && strpos($cmdlines, $sid) !== false) {
        return true;
    }
    // 2) the transcript was written within the last 90 seconds while none of our runs is attached
    return time() - $mtime < 90;
}

/**
 * Conversations of the assistant's WORK_DIR, newest first.
 * Returns array(rows, archivedCount). Archived ones are left out unless $withArchived.
 * Row: id, title, custom (title was renamed), mtime, size, source, mode, archived, busy, run_id, external, deletable.
 */
function aia_chat_list_sessions($limit = 200, $withArchived = false)
{
    $dir = aia_chat_projects_dir();
    $idx = aia_chat_index_read();
    $cfg = aia_cfg();
    $active = array();
    foreach (aia_chat_active_runs() as $m) {
        $active[$m['session_id']] = $m['run_id'];
    }
    $heads = aia_chat_system_prompt_heads();
    $cacheFile = AIA_RAM . '/run/chat-titles.json';
    $cache = aia_chat_read_json($cacheFile);
    $cache = is_array($cache) ? $cache : array();
    $dirty = false;
    $files = (array)@glob($dir . '/*.jsonl');
    $rows = array();
    foreach ($files as $f) {
        $sid = basename($f, '.jsonl');
        $st = @stat($f);
        if (!$st || $st['size'] < 10 || !aia_chat_sess_id_ok($sid)) {
            continue;
        }
        $rows[] = array(strtolower($sid), $f, (int)$st['mtime'], (int)$st['size']);
    }
    usort($rows, function ($a, $b) {
        return $b[2] - $a[2];
    });
    $archivedCount = 0;
    foreach ($rows as $r) {
        if (!empty($idx[$r[0]]['archived'])) {
            $archivedCount++;
        }
    }
    $out = array();
    foreach ($rows as $r) {
        list($sid, $f, $mtime, $size) = $r;
        $archived = !empty($idx[$sid]['archived']);
        if ($archived && !$withArchived) {
            continue;
        }
        if (count($out) >= $limit) {
            break;
        }
        if (isset($cache[$sid]) && (int)$cache[$sid][0] === $mtime && is_string($cache[$sid][1])) {
            $title = $cache[$sid][1];
        } else {
            $title = aia_chat_peek($f);
            if ($title !== null) {
                $cache[$sid] = array($mtime, $title);
                $dirty = true;
            }
        }
        if ($title === null || $title === '' && !isset($idx[$sid]) && !isset($active[$sid])) {
            continue; // no user text at all (empty or tool-only session)
        }
        $source = isset($idx[$sid]['source']) ? $idx[$sid]['source'] : null;
        if ($source === null) {
            $source = 'app';
            foreach ($heads as $h) {
                if ($h !== '' && strncmp($title, $h, strlen($h)) === 0) {
                    $source = 'system';
                    break;
                }
            }
        }
        $busy = isset($active[$sid]);
        $external = $busy ? false : ($source === 'app' || $source === 'system') && aia_chat_external_active($sid, $mtime);
        $custom = isset($idx[$sid]['title']) && $idx[$sid]['title'] !== '';
        $out[] = array(
            'id' => $sid,
            'title' => $custom ? $idx[$sid]['title'] : aia_chat_clip(aia_chat_mask($title), 120),
            'custom' => $custom,
            'mtime' => $mtime,
            'size' => $size,
            'source' => $source,
            'mode' => aia_chat_session_mode($sid, $idx, $cfg),
            'archived' => $archived,
            'busy' => $busy,
            'run_id' => $busy ? $active[$sid] : '',
            'external' => $external,
            'deletable' => !$external,
        );
    }
    if ($dirty) {
        // drop entries of sessions that no longer exist, then store (best effort)
        $live = array();
        foreach ($rows as $r) {
            $live[$r[0]] = true;
        }
        foreach (array_keys($cache) as $k) {
            if (!isset($live[$k])) {
                unset($cache[$k]);
            }
        }
        if (is_dir(dirname($cacheFile))) {
            aia_chat_write($cacheFile, json_encode($cache));
        }
    }
    return array($out, $archivedCount);
}
/* ------------------------------------------------------------------ event clamping (worker) */

/** Keep individual stream lines reasonable: cut huge tool results. */
function aia_chat_clamp_line($line)
{
    if (strlen($line) <= 200000) {
        return $line;
    }
    $j = json_decode($line, true);
    if (!is_array($j)) {
        return aia_chat_clip($line, 200000);
    }
    $walk = function (&$v) use (&$walk) {
        if (is_string($v)) {
            if (strlen($v) > 20000) {
                $v = aia_chat_clip($v, 20000) . "\n[truncated]";
            }
        } elseif (is_array($v)) {
            foreach ($v as $k => &$x) {
                if (!($k === 'data' && is_string($x))) {
                    $walk($x);
                }
            }
        }
    };
    $walk($j);
    $out = json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $out === false ? aia_chat_clip($line, 200000) : $out;
}

/** Display form of a permission input: strings masked and clipped. */
function aia_chat_display_input($v, $depth = 0)
{
    if (is_string($v)) {
        return aia_chat_clip(aia_chat_mask(aia_chat_utf8($v)), 4000);
    }
    if (is_array($v)) {
        if ($depth > 4) {
            return '...';
        }
        $o = array();
        $n = 0;
        foreach ($v as $k => $x) {
            if (++$n > 40) {
                break;
            }
            $o[$k] = aia_chat_display_input($x, $depth + 1);
        }
        return $o;
    }
    return $v;
}

/** Pending permission requests of a run (no decision yet). */
function aia_chat_pending_perms($run)
{
    $out = array();
    foreach ((array)@glob(aia_chat_perm_dir() . '/' . $run . '.*.req.json') as $f) {
        $r = aia_chat_read_json($f, 262144);
        if (!$r || empty($r['id']) || !aia_chat_req_id_ok($r['id'])) {
            continue;
        }
        if (is_file(aia_chat_perm_dir() . '/' . $run . '.' . $r['id'] . '.dec.json')) {
            continue;
        }
        $name = (string)($r['tool_name'] ?? '?');
        $input = isset($r['input']) && is_array($r['input']) ? $r['input'] : array();
        $out[] = array(
            'id' => $r['id'],
            'tool' => $name,
            'tool_use_id' => (string)($r['tool_use_id'] ?? ''),
            'summary' => aia_chat_tool_summary($name, $input),
            'input' => aia_chat_display_input($input),
            't' => (int)($r['t'] ?? 0),
        );
    }
    usort($out, function ($a, $b) {
        return $a['t'] - $b['t'];
    });
    return $out;
}

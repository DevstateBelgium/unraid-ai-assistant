<?php
/* AI Assistant for Unraid (for Claude Code) - AJAX endpoint.
 * POST /plugins/ai-assistant-claude/include/api.php  action=<whitelisted>  csrf_token=<token>
 * Responses are JSON (except a successful `export`, which is a tar.gz download).
 */

require_once __DIR__ . '/common.php';

const AIA_MAX_FILE = 262144; // 256 KB for memory files and advanced editors
const AIA_MAX_IMPORT = 209715200; // 200 MB upload cap

$AIA_ACTIONS = array(
    'status', 'install', 'login_start', 'login_code', 'login_cancel', 'logout', 'enable', 'disable',
    'start', 'stop', 'restart', 'update', 'preflight', 'discovery_start', 'discovery_status',
    'discovery_cancel', 'save_settings', 'get_settings', 'memory_list', 'memory_get', 'memory_save',
    'memory_delete', 'activity', 'advanced_get', 'advanced_save', 'wipe_all', 'export', 'import',
);

function aia_post($key, $default = '')
{
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string)$_POST[$key] : $default;
}

function aia_fail($msg, $code = 200, $extra = array())
{
    aia_json(array_merge(array('ok' => false, 'error' => $msg), $extra), $code);
}

/* ---------- security gate ---------- */

function aia_gate()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        aia_fail('POST only', 405);
    }
    // Unraid's local_prepend.php validates the token and then UNSETS it from $_POST. If it is still
    // present (prepend inactive), or the prepend is not active at all, verify it ourselves.
    $prepend = function_exists('csrf_terminate');
    $given = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : null);
    if ($given === null && $prepend) {
        return; // already validated (and removed) by the WebGUI prepend
    }
    $token = aia_csrf_token();
    if ($token === '' || $given === null || !hash_equals($token, $given)) {
        aia_fail('invalid csrf token', 403);
    }
    unset($_POST['csrf_token']);
}

/* ---------- validation helpers ---------- */

function aia_abs_path_ok($p, $allowEmpty)
{
    if ($p === '') {
        return $allowEmpty;
    }
    if (strlen($p) > 255 || $p[0] !== '/' || strpos($p, '..') !== false || strpos($p, '//') !== false) {
        return false;
    }
    if (!preg_match('~^/[A-Za-z0-9._/ +@=,-]+$~', $p) || $p === '/') {
        return false;
    }
    $bad = array('/boot', '/proc', '/sys', '/dev', '/etc', '/bin', '/sbin', '/usr', '/lib', '/lib64', '/root', '/run');
    foreach ($bad as $b) {
        if ($p === $b || strncmp($p, $b . '/', strlen($b) + 1) === 0) {
            return false;
        }
    }
    return true;
}

function aia_yesno($v)
{
    return $v === 'yes' || $v === 'no';
}

function aia_model_ok($v)
{
    return $v === '' || (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\[\]-]{0,99}$/', $v);
}

/** Validate the settings form. Returns array(clean values, errors[field => message]). */
function aia_validate_settings(array $in)
{
    $clean = array();
    $err = array();
    $g = function ($k) use ($in) {
        return isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : null;
    };

    $v = $g('DEVICE_NAME');
    if ($v !== null) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,63}$/', $v) || $v === '') {
            $clean['DEVICE_NAME'] = $v;
        } else {
            $err['DEVICE_NAME'] = 'Invalid device name (letters, digits, space, . _ - ; max 64).';
        }
    }
    $v = $g('RUN_USER');
    if ($v !== null) {
        if (in_array($v, aia_users(), true)) {
            $clean['RUN_USER'] = $v;
        } else {
            $err['RUN_USER'] = 'Unknown user.';
        }
    }
    $v = $g('WORK_DIR');
    if ($v !== null) {
        if (aia_abs_path_ok($v, false)) {
            $clean['WORK_DIR'] = rtrim($v, '/');
        } else {
            $err['WORK_DIR'] = 'Must be a safe absolute path (not /, /boot, /etc, ...).';
        }
    }
    $v = $g('SPAWN_MODE');
    if ($v !== null) {
        if (in_array($v, array('same-dir', 'worktree', 'session'), true)) {
            $clean['SPAWN_MODE'] = $v;
        } else {
            $err['SPAWN_MODE'] = 'Invalid spawn mode.';
        }
    }
    $v = $g('CAPACITY');
    if ($v !== null) {
        if (preg_match('/^[0-9]{1,3}$/', $v) && (int)$v >= 1 && (int)$v <= 256) {
            $clean['CAPACITY'] = (string)(int)$v;
        } else {
            $err['CAPACITY'] = 'Capacity must be a number from 1 to 256.';
        }
    }
    foreach (array('MAIN_MODEL', 'WORKER_MODEL', 'SCOUT_MODEL') as $k) {
        $v = $g($k);
        if ($v === null) {
            continue;
        }
        if ($v === 'default' && $k === 'MAIN_MODEL') {
            $v = '';
        }
        if ($k !== 'MAIN_MODEL' && $v === '') {
            $v = 'inherit';
        }
        if (aia_model_ok($v)) {
            $clean[$k] = $v;
        } else {
            $err[$k] = 'Invalid model name.';
        }
    }
    foreach (array('AUTO_UPDATE', 'EXPLAIN_NOTIFICATIONS', 'GAP_CHECKS', 'SHOW_PAGE_BUTTON') as $k) {
        $v = $g($k);
        if ($v === null) {
            continue;
        }
        if (aia_yesno($v)) {
            $clean[$k] = $v;
        } else {
            $err[$k] = 'Must be yes or no.';
        }
    }
    $v = $g('BUTTON_STYLE');
    if ($v !== null) {
        if (in_array($v, array('header', 'floating', 'both'), true)) {
            $clean['BUTTON_STYLE'] = $v;
        } else {
            $err['BUTTON_STYLE'] = 'Must be header, floating or both.';
        }
    }
    foreach (array('CHAT_PERMISSION_MODE', 'DEVICE_PERMISSION_MODE') as $k) {
        $v = $g($k);
        if ($v === null) {
            continue;
        }
        if (in_array($v, array('default', 'acceptEdits', 'auto', 'plan'), true)) {
            $clean[$k] = $v;
        } else {
            $err[$k] = 'Must be default, acceptEdits, auto or plan.';
        }
    }
    $v = $g('HISTORY_DIR');
    if ($v !== null) {
        if (aia_abs_path_ok($v, true)) {
            $clean['HISTORY_DIR'] = rtrim($v, '/');
        } else {
            $err['HISTORY_DIR'] = 'Must be empty or a safe absolute path.';
        }
    }
    $v = $g('TZ');
    if ($v !== null) {
        if ($v === '' || preg_match('~^[A-Za-z0-9][A-Za-z0-9_+/:-]{0,63}$~', $v)) {
            $clean['TZ'] = $v;
        } else {
            $err['TZ'] = 'Invalid time zone.';
        }
    }
    if (isset($in['EXTRA_BLOCKED_PATTERNS']) && is_scalar($in['EXTRA_BLOCKED_PATTERNS'])) {
        $raw = str_replace(array("\r\n", "\r"), "\n", (string)$in['EXTRA_BLOCKED_PATTERNS']);
        $pats = array();
        $bad = '';
        foreach (explode("\n", $raw) as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (strlen($p) > 200 || preg_match('/[\x00-\x1f]/', $p)) {
                $bad = $p;
                break;
            }
            $ok = @preg_match('~' . str_replace('~', '\\~', $p) . '~', '');
            if ($ok === false) {
                $bad = $p;
                break;
            }
            $pats[] = $p;
        }
        if ($bad !== '' || count($pats) > 50 || strlen(implode('|', $pats)) > 4000) {
            $err['EXTRA_BLOCKED_PATTERNS'] = 'One regular expression per line (max 200 characters, 50 lines, valid PCRE).';
        } else {
            $clean['EXTRA_BLOCKED_PATTERNS'] = implode('|', $pats);
        }
    }
    return array($clean, $err);
}

/* ---------- memory ---------- */

function aia_mem_dir()
{
    return AIA_RAM . '/memory';
}

function aia_mem_name_ok($n)
{
    return strlen($n) <= 100 && $n[0] !== '.' && strpos($n, '..') === false
        && (bool)preg_match('/^[A-Za-z0-9._-]+\.md$/', $n);
}

function aia_mem_path($n)
{
    if ($n === '' || !aia_mem_name_ok($n)) {
        aia_fail('Invalid file name.');
    }
    return aia_mem_dir() . '/' . $n;
}

function aia_sync_best_effort()
{
    if (is_file(AIA_EMHTTP . '/scripts/sync-state.sh')) {
        aia_run('sync-state.sh', array(), 60);
    }
}

/* ---------- activity ---------- */

function aia_tail_lines($file, $max)
{
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return array();
    }
    $size = filesize($file);
    $chunk = 65536;
    $pos = $size;
    $buf = '';
    $limit = 8 * 1024 * 1024;
    while ($pos > 0 && substr_count($buf, "\n") <= $max && strlen($buf) < $limit) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fh, $pos);
        $buf = fread($fh, $read) . $buf;
    }
    fclose($fh);
    $lines = explode("\n", $buf);
    if ($pos > 0) {
        array_shift($lines); // possibly partial first line
    }
    $lines = array_values(array_filter($lines, function ($l) {
        return trim($l) !== '';
    }));
    return array_slice($lines, -$max);
}

/* ---------- actions ---------- */

function aia_status()
{
    $st = aia_run_json('rc.ai-assistant', array('status'), 20);
    $cfg = aia_cfg();
    $st['discovery_done'] = ($cfg['DISCOVERY_DONE'] === 'yes');
    $st['run_user'] = $cfg['RUN_USER'];
    if (!isset($st['enabled'])) {
        $st['enabled'] = ($cfg['ENABLED'] === 'yes');
    }
    return $st;
}

function aia_rc($cmd, $timeout)
{
    return aia_run_json('rc.ai-assistant', array($cmd), $timeout);
}

function aia_action_enable()
{
    $st = aia_status();
    if (empty($st['logged_in'])) {
        aia_fail('Log in first.');
    }
    if (!aia_cfg_save(array('ENABLED' => 'yes'))) {
        aia_fail('Could not write the settings file.');
    }
    $r = aia_rc('start', 90);
    $cfg = aia_cfg();
    $r['ok'] = isset($r['ok']) ? $r['ok'] : true;
    $r['needs_discovery'] = ($cfg['DISCOVERY_DONE'] !== 'yes');
    $r['run_user'] = $cfg['RUN_USER'];
    return $r;
}

function aia_action_save_settings()
{
    $in = $_POST;
    list($clean, $err) = aia_validate_settings($in);
    if ($err) {
        aia_fail('Validation failed.', 200, array('errors' => $err));
    }
    $before = aia_cfg();
    if (!aia_cfg_save($clean)) {
        aia_fail('Could not write the settings file.');
    }
    $out = array('ok' => true, 'saved' => array_keys($clean), 'restarted' => false);
    // settings the running device does not read (page button, notification helpers) need no restart
    $restart = false;
    foreach ($clean as $k => $v) {
        if (!in_array($k, array('SHOW_PAGE_BUTTON', 'BUTTON_STYLE', 'CHAT_PERMISSION_MODE', 'EXPLAIN_NOTIFICATIONS', 'GAP_CHECKS'), true) && (!isset($before[$k]) || $before[$k] !== $v)) {
            $restart = true;
        }
    }
    $st = $restart ? aia_run_json('rc.ai-assistant', array('status'), 20) : array();
    if (!empty($st['running'])) {
        $r = aia_rc('restart', 120);
        $out['restarted'] = true;
        $out['restart_ok'] = !isset($r['ok']) || $r['ok'];
    }
    if (isset($clean['RUN_USER']) && $clean['RUN_USER'] !== $before['RUN_USER']) {
        $out['run_user_changed'] = true;
    }
    return $out;
}

function aia_action_memory_list()
{
    $dir = aia_mem_dir();
    $files = array();
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . '/' . $f;
            if ($f !== '' && aia_mem_name_ok($f) && is_file($p) && !is_link($p)) {
                $files[] = array('name' => $f, 'size' => filesize($p), 'mtime' => filemtime($p));
            }
        }
    }
    usort($files, function ($a, $b) {
        if ($a['name'] === 'MEMORY.md') {
            return -1;
        }
        if ($b['name'] === 'MEMORY.md') {
            return 1;
        }
        return strcmp($a['name'], $b['name']);
    });
    return array('ok' => true, 'files' => $files);
}

function aia_action_memory_get()
{
    $p = aia_mem_path(aia_post('name'));
    if (!is_file($p) || is_link($p)) {
        aia_fail('File not found.');
    }
    if (filesize($p) > AIA_MAX_FILE) {
        aia_fail('File is larger than 256 KB; edit it on the server.');
    }
    return array('ok' => true, 'name' => aia_post('name'), 'content' => (string)file_get_contents($p));
}

function aia_action_memory_save()
{
    $p = aia_mem_path(aia_post('name'));
    $content = aia_post('content');
    if (strlen($content) > AIA_MAX_FILE) {
        aia_fail('Content exceeds 256 KB.');
    }
    if (preg_match('//u', $content) !== 1 || strpos($content, "\0") !== false) {
        aia_fail('Content must be valid UTF-8 text.');
    }
    if (is_link($p)) {
        aia_fail('Refusing to write through a symlink.');
    }
    $dir = aia_mem_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        aia_fail('Memory directory is not available (is the assistant booted?).');
    }
    $exists = is_file($p);
    $uid = $exists ? @fileowner($p) : @fileowner($dir);
    $gid = $exists ? @filegroup($p) : @filegroup($dir);
    if (!aia_write_atomic($p, $content, 0644)) {
        aia_fail('Could not write the file.');
    }
    if ($uid !== false && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($p, $uid);
        @chgrp($p, $gid);
    }
    aia_sync_best_effort();
    return array('ok' => true);
}

function aia_action_memory_delete()
{
    $p = aia_mem_path(aia_post('name'));
    if (!is_file($p) && !is_link($p)) {
        aia_fail('File not found.');
    }
    if (!@unlink($p)) {
        aia_fail('Could not delete the file.');
    }
    aia_sync_best_effort();
    return array('ok' => true);
}

function aia_action_activity()
{
    $file = AIA_RAM . '/logs/activity.jsonl';
    $q = strtolower(substr(aia_post('q'), 0, 200));
    $tool = substr(aia_post('tool'), 0, 100);
    $page = max(1, (int)aia_post('page', '1'));
    $limit = (int)aia_post('limit', '50');
    $limit = ($limit < 10 || $limit > 200) ? 50 : $limit;
    $rows = array();
    $tools = array();
    foreach (array_reverse(aia_tail_lines($file, 2000)) as $line) {
        $j = json_decode($line, true);
        if (!is_array($j)) {
            continue;
        }
        $row = array(
            't' => isset($j['t']) ? $j['t'] : '',
            'session' => isset($j['session']) && is_scalar($j['session']) ? (string)$j['session'] : '',
            'tool' => isset($j['tool']) && is_scalar($j['tool']) ? (string)$j['tool'] : '',
            'summary' => isset($j['summary']) && is_scalar($j['summary']) ? substr((string)$j['summary'], 0, 2000) : '',
            'cwd' => isset($j['cwd']) && is_scalar($j['cwd']) ? (string)$j['cwd'] : '',
        );
        $tools[$row['tool']] = true;
        if ($tool !== '' && $row['tool'] !== $tool) {
            continue;
        }
        if ($q !== '' && strpos(strtolower($row['summary'] . ' ' . $row['tool'] . ' ' . $row['cwd']), $q) === false) {
            continue;
        }
        $rows[] = $row;
    }
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    ksort($tools);
    return array('ok' => true, 'total' => $total, 'page' => $page, 'pages' => $pages,
        'tools' => array_values(array_filter(array_keys($tools), 'strlen')),
        'entries' => array_slice($rows, ($page - 1) * $limit, $limit));
}

function aia_user_dir()
{
    return AIA_FLASH . '/state/config';
}

const AIA_BEGIN = '<!-- aia:begin -->';
const AIA_END = '<!-- aia:end -->';

/** Split CLAUDE.md into array(managed block incl. markers or '', user text outside the markers). */
function aia_claude_split($text)
{
    $b = strpos($text, AIA_BEGIN);
    $e = strpos($text, AIA_END);
    if ($b !== false && $e !== false && $e > $b) {
        $e += strlen(AIA_END);
        $user = array_filter(array(trim(substr($text, 0, $b)), trim(substr($text, $e))), 'strlen');
        return array(substr($text, $b, $e - $b), implode("\n\n", $user));
    }
    return array('', trim($text));
}

function aia_action_advanced_get()
{
    // The render script reads RAM config/settings.user.json and keeps the user's CLAUDE.md text in
    // RAM config/CLAUDE.md outside the aia markers; sync-state mirrors both to FLASH/state/config.
    $ram = AIA_RAM . '/config';
    $d = aia_user_dir();
    $s = @file_get_contents($ram . '/settings.user.json');
    if ($s === false) {
        $s = @file_get_contents($d . '/settings.user.json');
    }
    $m = @file_get_contents($ram . '/CLAUDE.md');
    if ($m === false) {
        $m = @file_get_contents($d . '/CLAUDE.md');
    }
    if ($m !== false) {
        $parts = aia_claude_split($m);
        $user = $parts[1];
    } else {
        $u = @file_get_contents($d . '/CLAUDE.user.md');
        $user = $u === false ? '' : $u;
    }
    return array('ok' => true, 'settings' => $s === false ? "{\n}\n" : $s, 'claude_md' => $user);
}

function aia_action_advanced_save()
{
    $s = aia_post('settings');
    $m = aia_post('claude_md');
    if (strlen($s) > AIA_MAX_FILE || strlen($m) > AIA_MAX_FILE) {
        aia_fail('Content exceeds 256 KB.');
    }
    if (trim($s) === '') {
        $s = "{\n}\n";
    }
    $j = json_decode($s, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        aia_fail('settings.json: invalid JSON.', 200, array('detail' => json_last_error_msg()));
    }
    if (!is_array($j) || ($j !== array() && array_keys($j) === range(0, count($j) - 1))) {
        aia_fail('settings.json: the top level must be a JSON object.');
    }
    if (preg_match('//u', $m) !== 1 || strpos($m, "\0") !== false) {
        aia_fail('CLAUDE.md: content must be valid UTF-8 text.');
    }
    if (strpos($m, AIA_BEGIN) !== false || strpos($m, AIA_END) !== false) {
        aia_fail('CLAUDE.md: the managed block markers must not appear in your text.');
    }
    $ram = AIA_RAM . '/config';
    $d = aia_user_dir();
    // Current CLAUDE.md (RAM first): keep the managed block, replace only the user text.
    $cur = @file_get_contents($ram . '/CLAUDE.md');
    if ($cur === false) {
        $cur = @file_get_contents($d . '/CLAUDE.md');
    }
    $parts = aia_claude_split($cur === false ? '' : $cur);
    $m = trim($m);
    $full = ($parts[0] !== '' ? $parts[0] . "\n" . ($m === '' ? '' : "\n" . $m . "\n") : ($m === '' ? '' : $m . "\n"));
    $ok = aia_write_atomic($d . '/settings.user.json', $s)
        && aia_write_atomic($d . '/CLAUDE.user.md', $m === '' ? '' : $m . "\n")
        && aia_write_atomic($d . '/CLAUDE.md', $full);
    if (!$ok) {
        aia_fail('Could not write to flash.');
    }
    if (is_dir($ram)) {
        aia_write_atomic($ram . '/settings.user.json', $s);
        aia_write_atomic($ram . '/CLAUDE.md', $full);
    }
    $r = aia_run_json('render-config.sh', array(), 60);
    $res = array('ok' => !empty($r['ok']), 'rendered' => !empty($r['ok']));
    if (!$res['ok']) {
        $res['error'] = 'Saved, but the configuration could not be rendered.';
    }
    return $res;
}


function aia_action_wipe_all()
{
    if (aia_post('confirm') !== 'DELETE') {
        aia_fail('Type DELETE to confirm.');
    }
    $r = aia_run_json('rc.ai-assistant', array('wipe'), 180);
    if (!isset($r['ok'])) {
        $r['ok'] = true;
    }
    return $r;
}

function aia_action_export()
{
    $incl = aia_post('include_credentials') === '1' || aia_post('include_credentials') === 'yes';
    $dir = sys_get_temp_dir() . '/aia-export-' . bin2hex(random_bytes(6));
    if (!@mkdir($dir, 0700)) {
        aia_fail('Cannot create a temporary directory.');
    }
    $file = $dir . '/ai-assistant-export-' . date('Ymd-His') . '.tar.gz';
    $args = array('export', $file);
    if ($incl) {
        $args[] = '--include-credentials';
    }
    $r = aia_run_json('rc.ai-assistant', $args, 180);
    if ((isset($r['ok']) && !$r['ok']) || !is_file($file) || filesize($file) === 0) {
        @unlink($file);
        @rmdir($dir);
        aia_fail(isset($r['error']) && $r['error'] !== '' ? (string)$r['error'] : 'Export failed.');
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    @unlink($file);
    @rmdir($dir);
    exit;
}

function aia_action_import()
{
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        aia_fail('No file uploaded.');
    }
    $up = $_FILES['file'];
    if ($up['size'] <= 0 || $up['size'] > AIA_MAX_IMPORT) {
        aia_fail('Invalid file size.');
    }
    $tmp = (string)$up['tmp_name'];
    $head = (string)@file_get_contents($tmp, false, null, 0, 2);
    if ($head !== "\x1f\x8b") {
        aia_fail('Not a gzip archive.');
    }
    $dir = sys_get_temp_dir() . '/aia-import-' . bin2hex(random_bytes(6));
    if (!@mkdir($dir, 0700)) {
        aia_fail('Cannot create a temporary directory.');
    }
    $dest = $dir . '/import.tar.gz';
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$moved) {
        @rmdir($dir);
        aia_fail('Could not store the upload.');
    }
    $r = aia_run_json('rc.ai-assistant', array('import', $dest), 180);
    @unlink($dest);
    @rmdir($dir);
    if (!isset($r['ok'])) {
        $r['ok'] = true;
    }
    return $r;
}

/* ---------- dispatcher ---------- */

function aia_dispatch($action)
{
    switch ($action) {
        case 'status':
            return aia_status();
        case 'install':
            return aia_run_json('install-claude.sh', array(), 900);
        case 'login_start':
            return aia_run_json('login.sh', array('start'), 90);
        case 'login_code':
            $code = trim(aia_post('code'));
            if (!preg_match('/^[A-Za-z0-9_#.-]{10,400}$/', $code)) {
                aia_fail('That does not look like a valid login code.');
            }
            return aia_run_json('login.sh', array('code', $code), 180);
        case 'login_cancel':
            return aia_run_json('login.sh', array('cancel'), 30);
        case 'logout':
            return aia_run_json('login.sh', array('logout'), 60);
        case 'enable':
            return aia_action_enable();
        case 'disable':
            aia_cfg_save(array('ENABLED' => 'no'));
            $r = aia_rc('stop', 90);
            if (!isset($r['ok'])) {
                $r['ok'] = true;
            }
            return $r;
        case 'start':
            return aia_rc('start', 90);
        case 'stop':
            return aia_rc('stop', 90);
        case 'restart':
            return aia_rc('restart', 120);
        case 'update':
            return aia_rc('update', 900);
        case 'preflight':
            $r = aia_run_json('discover.sh', array('preflight'), 60);
            $cfg = aia_cfg();
            $r['run_user'] = $cfg['RUN_USER'];
            if (isset($r['missing']) && !is_array($r['missing'])) {
                $r['missing'] = array();
            }
            return $r;
        case 'discovery_start':
            $asRoot = aia_post('as_root') === '1' || aia_post('as_root') === 'yes' || aia_post('as_root') === 'true';
            return aia_run_json('discover.sh', $asRoot ? array('start', '--as-root') : array('start'), 60);
        case 'discovery_status':
            return aia_run_json('discover.sh', array('status'), 20);
        case 'discovery_cancel':
            return aia_run_json('discover.sh', array('cancel'), 30);
        case 'save_settings':
            return aia_action_save_settings();
        case 'get_settings':
            return array('ok' => true, 'settings' => aia_cfg(), 'users' => aia_users());
        case 'memory_list':
            return aia_action_memory_list();
        case 'memory_get':
            return aia_action_memory_get();
        case 'memory_save':
            return aia_action_memory_save();
        case 'memory_delete':
            return aia_action_memory_delete();
        case 'activity':
            return aia_action_activity();
        case 'advanced_get':
            return aia_action_advanced_get();
        case 'advanced_save':
            return aia_action_advanced_save();
        case 'wipe_all':
            return aia_action_wipe_all();
        case 'export':
            return aia_action_export();
        case 'import':
            return aia_action_import();
    }
    aia_fail('Unknown action.', 400);
}

function aia_api_main()
{
    global $AIA_ACTIONS;
    @set_time_limit(0);
    aia_gate();
    $action = aia_post('action');
    if (!in_array($action, $AIA_ACTIONS, true)) {
        aia_fail('Unknown action.', 400);
    }
    aia_json(aia_dispatch($action));
}

if (!defined('AIA_API_NO_RUN')) {
    aia_api_main();
}

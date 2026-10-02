<?php
/* AI Assistant for Unraid (for Claude Code) - shared helpers (PHP 7.4 - 8.4). */

if (defined('AIA_COMMON_LOADED')) {
    return;
}
define('AIA_COMMON_LOADED', 1);

define('AIA_PLUGIN', 'ai-assistant-claude');

function aia_env($name, $default)
{
    $v = getenv($name);
    return ($v !== false && $v !== '') ? rtrim($v, '/') : $default;
}

define('AIA_EMHTTP', aia_env('AIA_EMHTTP', '/usr/local/emhttp/plugins/ai-assistant-claude'));
define('AIA_FLASH', aia_env('AIA_FLASH', '/boot/config/plugins/ai-assistant-claude'));
define('AIA_RAM', aia_env('AIA_RAM', '/var/lib/ai-assistant-claude'));
define('AIA_CFG_FILE', AIA_FLASH . '/ai-assistant-claude.cfg');
define('AIA_DEFAULT_CFG', AIA_EMHTTP . '/default.cfg');
// Test-only overrides (not part of DESIGN.md): var.ini and passwd locations.
define('AIA_VAR_INI', aia_env('AIA_VAR_INI', '/var/local/emhttp/var.ini'));
define('AIA_PASSWD', aia_env('AIA_PASSWD', '/etc/passwd'));

/** All known cfg keys with their built-in fallback (used when default.cfg is missing). */
function aia_cfg_defaults()
{
    return array(
        'ENABLED' => 'no',
        'DEVICE_NAME' => '',
        'RUN_USER' => 'root',
        'WORK_DIR' => AIA_RAM . '/work',
        'SPAWN_MODE' => 'same-dir',
        'CAPACITY' => '32',
        'MAIN_MODEL' => '',
        'WORKER_MODEL' => 'sonnet',
        'SCOUT_MODEL' => 'haiku',
        'AUTO_UPDATE' => 'yes',
        'HISTORY_DIR' => '',
        'EXPLAIN_NOTIFICATIONS' => 'yes',
        'GAP_CHECKS' => 'yes',
        'LOGFS_WARN_PCT' => '80',
        'LOGFS_GROWTH_MB' => '10',
        'SHOW_PAGE_BUTTON' => 'yes',
        'BUTTON_STYLE' => 'header',
        'CHAT_PERMISSION_MODE' => 'default',
        'DEVICE_PERMISSION_MODE' => 'default',
        'EXTRA_BLOCKED_PATTERNS' => '',
        'DISCOVERY_DONE' => 'no',
        'DISCOVERY_VERSION' => '',
        'TZ' => '',
    );
}

/**
 * Parse a KEY="value" file. Values are written with only backslash and double quote escaped, which is exactly what
 * scripts/common.sh load_cfg un-escapes (it reads line by line, it does not source the file).
 */
function aia_cfg_parse_file($file, $known)
{
    $out = array();
    if (!is_readable($file)) {
        return $out;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (!preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        if (!array_key_exists($m[1], $known)) {
            continue;
        }
        $v = $m[2];
        if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
            $v = preg_replace('/\\\\([\\\\"])/', '$1', substr($v, 1, -1));
        } elseif (strlen($v) >= 2 && $v[0] === "'" && substr($v, -1) === "'") {
            $v = substr($v, 1, -1);
        }
        $out[$m[1]] = $v;
    }
    return $out;
}

/** default.cfg merged with the flash cfg. */
function aia_cfg()
{
    $known = aia_cfg_defaults();
    $cfg = $known;
    $cfg = array_merge($cfg, aia_cfg_parse_file(AIA_DEFAULT_CFG, $known));
    $cfg = array_merge($cfg, aia_cfg_parse_file(AIA_CFG_FILE, $known));
    return $cfg;
}

/** Atomic write of a file (tmp + rename in the same dir). */
function aia_write_atomic($path, $data, $mode = 0644)
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $mode); // no-op on FAT32
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Merge $changes (known keys only) into the flash cfg and write it atomically. */
function aia_cfg_save(array $changes)
{
    $known = aia_cfg_defaults();
    $current = aia_cfg_parse_file(AIA_CFG_FILE, $known);
    foreach ($changes as $k => $v) {
        if (!array_key_exists($k, $known)) {
            continue;
        }
        $current[$k] = str_replace(array("\r\n", "\r", "\n"), '|', (string)$v);
    }
    $body = "# AI Assistant for Unraid - managed by the WebGUI\n";
    foreach ($known as $k => $def) {
        if (!array_key_exists($k, $current)) {
            continue;
        }
        $esc = preg_replace('/([\\\\"])/', '\\\\$1', (string)$current[$k]);
        $body .= $k . '="' . $esc . '"' . "\n";
    }
    return aia_write_atomic(AIA_CFG_FILE, $body);
}

/** Send a JSON response and stop. */
function aia_json($data, $code = 200)
{
    if (!headers_sent()) {
        if (function_exists('http_response_code')) {
            http_response_code($code);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** HTML-escape. */
function aia_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Selectable users: uid 0 or >= 1000, with a real login-less-or-not account (nobody excluded). */
function aia_users()
{
    $users = array();
    $lines = @file(AIA_PASSWD, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return array('root');
    }
    foreach ($lines as $line) {
        $f = explode(':', $line);
        if (count($f) < 7) {
            continue;
        }
        $uid = (int)$f[2];
        if (($uid === 0 || $uid >= 1000) && $f[0] !== 'nobody' && preg_match('/^[a-z_][a-z0-9_.-]*\$?$/i', $f[0])) {
            $users[] = $f[0];
        }
    }
    return $users ? $users : array('root');
}

/** Read the WebGUI csrf token from var.ini. */
function aia_csrf_token()
{
    global $var;
    if (isset($var) && is_array($var) && isset($var['csrf_token'])) {
        return (string)$var['csrf_token'];
    }
    $ini = @parse_ini_file(AIA_VAR_INI);
    return is_array($ini) && isset($ini['csrf_token']) ? (string)$ini['csrf_token'] : '';
}

/**
 * Run a script from $EMHTTP/scripts with a timeout. Returns array(exit, stdout, stderr, timed_out).
 * Arguments are escaped with escapeshellarg.
 */
function aia_run($script, array $args = array(), $timeout = 30)
{
    $path = AIA_EMHTTP . '/scripts/' . $script;
    $cmd = '/bin/bash ' . escapeshellarg($path);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string)$a);
    }
    $env = array(
        'PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin',
        'HOME' => '/root',
    );
    // AIA_* are the sandbox/test overrides; production never sets them
    foreach (array('AIA_EMHTTP', 'AIA_FLASH', 'AIA_RAM', 'AIA_VAR_INI', 'AIA_PASSWD', 'AIA_STUB_LOG', 'AIA_VARINI',
                   'AIA_UNRAID_VERSION_FILE', 'AIA_CLAUDE_BIN', 'AIA_DOWNLOAD_BASE', 'AIA_IMPORT_MAX_BYTES') as $e) {
        $v = getenv($e);
        if ($v !== false) {
            $env[$e] = $v;
        }
    }
    $spec = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $proc = @proc_open($cmd, $spec, $pipes, '/', $env);
    if (!is_resource($proc)) {
        return array(127, '', 'cannot start ' . $script, false);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $start = time();
    $timed = false;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (time() - $start > $timeout) {
            $timed = true;
            @proc_terminate($proc, 15);
            usleep(300000);
            $st2 = proc_get_status($proc);
            if ($st2['running']) {
                @proc_terminate($proc, 9);
            }
            break;
        }
        usleep(50000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if (isset($st) && !$st['running'] && $st['exitcode'] >= 0) {
        $code = $st['exitcode'];
    }
    return array($timed ? 124 : $code, $out, substr($err, 0, 2000), $timed);
}

/** Run a script and decode its one-line JSON. Always returns an array. */
function aia_run_json($script, array $args = array(), $timeout = 30)
{
    list($code, $out, $err, $timed) = aia_run($script, $args, $timeout);
    if ($timed) {
        return array('ok' => false, 'error' => 'timeout');
    }
    $lines = preg_split('/\r?\n/', trim($out));
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $l = trim($lines[$i]);
        if ($l !== '' && $l[0] === '{') {
            $j = json_decode($l, true);
            if (is_array($j)) {
                return $j;
            }
        }
    }
    return array('ok' => false, 'error' => 'invalid script output (exit ' . $code . ')');
}

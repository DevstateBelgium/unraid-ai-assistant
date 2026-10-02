<?php
// JSON/template helpers for the bash scripts (no jq on Unraid). PHP 7.4 - 8.4 compatible.
// Usage: php lib.php <command> args...   (also includable: only the aialib_* functions are defined)
//   obj k=v k:b=true k:i=5          print one JSON object
//   list a b c                      print a JSON array of strings
//   get <file|-> a.b.c              print a scalar (true/false for bool), exit 1 if missing
//   state-set <file> k=v ...        merge keys into a JSON file (flock + atomic rename)
//   merge <base.json> <user.json>   deep merge to stdout (assoc: recurse, lists: union, scalar: user wins)
//   render <tpl> k=v ...            replace {{K}} placeholders, print
//   claude-md <file> <blockfile>    insert/replace the managed block, keep the user's text
//   ensure-claude-json <file> <dir> onboarding done + trust for <dir>
//   valid <file|->                  exit 0 if the input is valid JSON

function aialib_typed($arg) {
    // "key=value", "key:b=true", "key:i=5"
    $p = strpos($arg, '=');
    if ($p === false) { return array($arg, true); }
    $k = substr($arg, 0, $p);
    $v = substr($arg, $p + 1);
    $type = 's';
    $c = strpos($k, ':');
    if ($c !== false) { $type = substr($k, $c + 1); $k = substr($k, 0, $c); }
    if ($type === 'b') { $v = in_array(strtolower($v), array('1', 'true', 'yes', 'on'), true); }
    elseif ($type === 'i') { $v = (int)$v; }
    elseif ($type === 'j') { $d = json_decode($v, true); $v = ($d === null && $v !== 'null') ? $v : $d; }
    return array($k, $v);
}

function aialib_read_json($file) {
    $raw = ($file === '-') ? stream_get_contents(STDIN) : @file_get_contents($file);
    if ($raw === false || trim($raw) === '') { return null; }
    return json_decode($raw, true);
}

function aialib_is_list($a) {
    if (!is_array($a)) { return false; }
    $i = 0;
    foreach ($a as $k => $_) { if ($k !== $i++) { return false; } }
    return true;
}

function aialib_merge($base, $user) {
    if (is_array($base) && is_array($user)) {
        if (aialib_is_list($base) && aialib_is_list($user)) {
            $out = $base;
            foreach ($user as $v) { if (!in_array($v, $out, true)) { $out[] = $v; } }
            return $out;
        }
        foreach ($user as $k => $v) {
            $base[$k] = array_key_exists($k, $base) ? aialib_merge($base[$k], $v) : $v;
        }
        return $base;
    }
    return $user;
}

// Atomic write: tmp in the same dir + rename. Skips the write if the content is identical.
function aialib_write($file, $data, $mode = 0644) {
    if (@file_get_contents($file) === $data) { return false; }
    $tmp = $file . '.tmp' . getmypid();
    if (file_put_contents($tmp, $data) === false) { return false; }
    @chmod($tmp, $mode);
    return rename($tmp, $file);
}

function aialib_json_enc($d) {
    $o = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_PRETTY_PRINT')) { $o |= JSON_PRETTY_PRINT; }
    return json_encode($d, $o) . "\n";
}

function aialib_render($tpl, $vars) {
    $s = @file_get_contents($tpl);
    if ($s === false) { return null; }
    $map = array();
    foreach ($vars as $k => $v) { $map['{{' . $k . '}}'] = $v; }
    return strtr($s, $map);
}

const AIALIB_BEGIN = '<!-- aia:begin -->';
const AIALIB_END = '<!-- aia:end -->';

function aialib_claude_md($existing, $block) {
    $block = trim(str_replace(array(AIALIB_BEGIN, AIALIB_END), '', $block));
    $managed = AIALIB_BEGIN . "\n" . $block . "\n" . AIALIB_END;
    $b = strpos($existing, AIALIB_BEGIN);
    $e = strpos($existing, AIALIB_END);
    if ($b !== false && $e !== false && $e > $b) {
        return substr($existing, 0, $b) . $managed . substr($existing, $e + strlen(AIALIB_END));
    }
    $existing = trim($existing);
    return $managed . "\n" . ($existing === '' ? '' : "\n" . $existing . "\n");
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $cmd = isset($argv[1]) ? $argv[1] : '';
    $a = array_slice($argv, 2);
    switch ($cmd) {
        case 'obj':
            $o = array();
            foreach ($a as $x) { list($k, $v) = aialib_typed($x); $o[$k] = $v; }
            echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            break;
        case 'list':
            echo json_encode(array_values($a), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            break;
        case 'get':
            $d = aialib_read_json($a[0]);
            foreach (explode('.', $a[1]) as $k) {
                if (!is_array($d) || !array_key_exists($k, $d)) { exit(1); }
                $d = $d[$k];
            }
            if (is_bool($d)) { echo $d ? 'true' : 'false', "\n"; }
            elseif (is_scalar($d)) { echo $d, "\n"; }
            elseif ($d === null) { echo "\n"; }
            else { echo json_encode($d), "\n"; }
            break;
        case 'state-set':
            $file = array_shift($a);
            $fh = fopen($file . '.lock', 'c');
            if (!$fh) { exit(1); }
            flock($fh, LOCK_EX);
            $d = aialib_read_json($file);
            if (!is_array($d)) { $d = array(); }
            foreach ($a as $x) { list($k, $v) = aialib_typed($x); $d[$k] = $v; }
            $d['updated'] = time();
            aialib_write($file, json_encode($d, JSON_UNESCAPED_SLASHES) . "\n");
            flock($fh, LOCK_UN);
            break;
        case 'merge':
            $base = aialib_read_json($a[0]);
            $user = isset($a[1]) ? aialib_read_json($a[1]) : null;
            if (!is_array($base)) { fwrite(STDERR, "base is not valid JSON\n"); exit(1); }
            if ($user !== null && !is_array($user)) { fwrite(STDERR, "user overrides are not valid JSON\n"); exit(2); }
            echo aialib_json_enc($user === null ? $base : aialib_merge($base, $user));
            break;
        case 'render':
            $tpl = array_shift($a);
            $vars = array();
            foreach ($a as $x) { list($k, $v) = aialib_typed($x); $vars[$k] = $v; }
            $r = aialib_render($tpl, $vars);
            if ($r === null) { exit(1); }
            echo $r;
            break;
        case 'claude-md':
            $cur = @file_get_contents($a[0]);
            $blk = @file_get_contents($a[1]);
            if ($blk === false) { exit(1); }
            echo aialib_claude_md($cur === false ? '' : $cur, $blk);
            break;
        case 'ensure-claude-json':
            $d = aialib_read_json($a[0]);
            if (!is_array($d)) { $d = array(); }
            $d['hasCompletedOnboarding'] = true;
            if (!isset($d['projects']) || !is_array($d['projects'])) { $d['projects'] = array(); }
            if (!isset($d['projects'][$a[1]]) || !is_array($d['projects'][$a[1]])) { $d['projects'][$a[1]] = array(); }
            $d['projects'][$a[1]]['hasTrustDialogAccepted'] = true;
            aialib_write($a[0], json_encode($d, JSON_UNESCAPED_SLASHES) . "\n", 0600);
            break;
        case 'valid':
            $raw = ($a[0] === '-') ? stream_get_contents(STDIN) : @file_get_contents($a[0]);
            json_decode($raw === false ? '' : $raw);
            exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);
        default:
            fwrite(STDERR, "unknown command\n");
            exit(64);
    }
}

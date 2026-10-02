<?php
/*
 * hooklib.php - shared helpers for the AI Assistant hooks and ua-sanitize.
 * PHP 7.4 - 8.4 compatible. No dependency on lib.php / include/common.php (hooks must stay
 * standalone so that a broken UI can never brick the assistant).
 */

function aia_ram()   { $v = getenv('AIA_RAM');   return rtrim($v !== false && $v !== '' ? $v : '/var/lib/ai-assistant-claude', '/'); }
function aia_flash() { $v = getenv('AIA_FLASH'); return rtrim($v !== false && $v !== '' ? $v : '/boot/config/plugins/ai-assistant-claude', '/'); }
function aia_emhttp(){ $v = getenv('AIA_EMHTTP');return rtrim($v !== false && $v !== '' ? $v : '/usr/local/emhttp/plugins/ai-assistant-claude', '/'); }

/** Directories that hold the assistant's memory (live + flash mirror). */
function aia_memory_dirs() {
    return array(aia_ram() . '/memory', aia_flash() . '/state/memory');
}

/** Parse a KEY="value" INI-ish cfg file without executing anything. */
function aia_parse_cfg_file($file) {
    $out = array();
    if (!is_readable($file)) return $out;
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return $out;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || $ln[0] === ';') continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $ln, $m)) continue;
        $v = trim($m[2]);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $out[$m[1]] = $v;
    }
    return $out;
}

/** default.cfg merged with the flash cfg. */
function aia_cfg_all() {
    $a = aia_parse_cfg_file(aia_emhttp() . '/default.cfg');
    $b = aia_parse_cfg_file(aia_flash() . '/ai-assistant-claude.cfg');
    return array_merge($a, $b);
}

function aia_cfg_get($key, $default = '') {
    $c = aia_cfg_all();
    return isset($c[$key]) ? $c[$key] : $default;
}

function aia_log_line($msg) {
    $dir = aia_ram() . '/logs';
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . '/hooks.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
    }
}

function aia_syslog($msg) {
    if (getenv('AIA_NO_SYSLOG')) return;
    $bin = '/usr/bin/logger';
    if (!is_executable($bin)) $bin = '/bin/logger';
    if (!is_executable($bin)) return;
    @exec(escapeshellarg($bin) . ' -t ai-assistant -- ' . escapeshellarg($msg) . ' >/dev/null 2>&1');
}

/* ------------------------------------------------------------------ masking */

/** Case-insensitive alternation of key-name fragments considered sensitive. */
function aia_sens_re() {
    return 'pass|pwd|token|secret|key|auth|cred|private|salt|jwt|dsn|cookie|signature';
}

/** Mask secret VALUES in free text, keeping key names. Idempotent. */
function aia_mask($s) {
    if (!is_string($s) || $s === '') return $s;
    $S = aia_sens_re();
    // PEM private key blocks
    $s = preg_replace('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s', '***PRIVATE KEY***', $s);
    // URLs with user:pass@
    $s = preg_replace('~([A-Za-z][A-Za-z0-9+.-]*://)([^/\s:@"\'\\\\]+):([^@/\s"\'\\\\]+)@~', '$1$2:***@', $s);
    // JSON  "Key": "value"
    $s = preg_replace('/("[^"\\\\]*(?:' . $S . ')[^"\\\\]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)+"/i', '$1"***"', $s);
    // JSON string holding KEY=VALUE (docker inspect Env arrays)
    $s = preg_replace('/"([A-Za-z0-9_.-]*(?:' . $S . ')[A-Za-z0-9_.-]*)=((?:[^"\\\\]|\\\\.)*)"/i', '"$1=***"', $s);
    // YAML / env style at line start:  KEY=rest-of-line   or   key: value
    $s = preg_replace('/^(\s*(?:-\s+)?(?:export\s+)?[A-Za-z0-9_.-]*(?:' . $S . ')[A-Za-z0-9_.-]*\s*=\s*)(?!\*\*\*\s*$).+$/im', '$1***', $s);
    $s = preg_replace('/^(\s*(?:-\s+)?["\']?[A-Za-z0-9_.-]*(?:' . $S . ')[A-Za-z0-9_.-]*["\']?\s*:[ \t]+)(?![|>]\s*$)(?!\*\*\*\s*$)(?![{\[]\s*$)\S.*$/im', '$1***', $s);
    // YAML block scalars under a sensitive key:  key: |  followed by indented lines
    $lines = explode("\n", $s);
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (preg_match('/^(\s*)(?:-\s+)?["\']?[A-Za-z0-9_.-]*(?:' . $S . ')[A-Za-z0-9_.-]*["\']?\s*:\s*[|>][+-]?\s*$/i', $lines[$i], $m)) {
            $ind = strlen($m[1]);
            for ($j = $i + 1; $j < $n; $j++) {
                if (trim($lines[$j]) === '') continue;
                if (!preg_match('/^(\s*)/', $lines[$j], $mm) || strlen($mm[1]) <= $ind) break;
                $lines[$j] = $mm[1] . '***';
            }
        }
    }
    $s = implode("\n", $lines);
    // inline KEY=VALUE anywhere (shell commands, query strings)
    $s = preg_replace('/(?<![A-Za-z0-9_.-])([A-Za-z0-9_.-]*(?:' . $S . ')[A-Za-z0-9_.-]*)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\',;)\]]+)/i', '$1$2***', $s);
    // --password foo / --token=foo / curl -u user:pass
    $s = preg_replace('/(--(?:password|passwd|pass|token|secret|api-key|apikey|auth|access-key|secret-key)(?:=|\s+))("[^"]*"|\'[^\']*\'|\S+)/i', '$1***', $s);
    $s = preg_replace('/(\scurl\b[^\n]*?\s-u\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1***', $s);
    // HTTP auth headers
    $s = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 ***', $s);
    $s = preg_replace('/\b((?:Proxy-)?Authorization|X-Api-Key|Cookie|Set-Cookie)(\s*:\s*)[^\n"\']+/i', '$1$2***', $s);
    // well-known token shapes
    $s = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '***JWT***', $s);
    $s = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{16,}|xox[abprs]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{30,})/', '***', $s);
    return $s;
}

/* -------------------------------------------------------- secret detection */

function aia_is_placeholder($v) {
    $v = trim($v, " \t\"'`,;()[]{}");
    if ($v === '') return true;
    if (preg_match('/^[*xX._#-]+$/', $v)) return true;             // ***, xxx, ...
    if (preg_match('/^<[^>]*>$/', $v)) return true;                // <token>
    if (preg_match('/^\$\{?[A-Za-z_][A-Za-z0-9_]*\}?$/', $v)) return true; // $VAR
    if (preg_match('/^\{\{.*\}\}$/', $v)) return true;             // {{VAR}}
    if (preg_match('/^(redacted|masked|hidden|removed|secret|password|passwd|token|key|apikey|api_key|changeme|change-me|your[_-].*|example|placeholder|todo|tbd|unknown|none|null|nil|n\/a|na|empty|unset|set|not[_-]?set|true|false|yes|no|on|off|required|optional|enabled|disabled|present|absent|available|configured|default|auto|required|missing|stored|see|name|value|file|env|var)$/i', $v)) return true;
    return false;
}

/** Return a short reason string if $text looks like it contains a secret, else null. */
function aia_find_secret($text) {
    if (!is_string($text) || $text === '') return null;
    if (preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $text)) return 'private key block';
    if (preg_match('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', $text)) return 'JWT';
    if (preg_match('/\b(?:sk-[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{16,}|xox[abprs]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{30,})/', $text)) return 'API key / token format';
    if (preg_match('~[A-Za-z][A-Za-z0-9+.-]*://[^/\s:@"\'\\\\]+:([^@/\s"\'\\\\]+)@~', $text, $m) && !aia_is_placeholder($m[1])) return 'URL with embedded password';
    if (preg_match('/(?<![A-Za-z0-9_.-])Bearer\s+([A-Za-z0-9._~+\/=-]{12,})/', $text, $m) && !aia_is_placeholder($m[1])) return 'bearer token';
    // KEY=VALUE / KEY: VALUE with a sensitive name
    $S = aia_sens_re();
    if (preg_match_all('/(?<![A-Za-z0-9_.-])([A-Za-z0-9_.-]*(?:' . $S . ')[A-Za-z0-9_.-]*)["\']?\s*([:=])[ \t]*("[^"\n]*"|\'[^\'\n]*\'|`[^`\n]*`|[^\s,;]+)/i', $text, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $val = trim($m[3], "\"'`");
            if (aia_is_placeholder($val)) continue;
            if (strlen($val) < 6) continue;
            if (preg_match('/\s/', $val)) { // quoted prose with spaces: only flag if first word looks secret-like
                $parts = preg_split('/\s+/', $val);
                $val = $parts[0];
                if (strlen($val) < 8) continue;
            }
            if ($m[2] === ':') {
                // "Key: value" is common prose; only flag token-looking values
                $hasDigit = preg_match('/\d/', $val);
                $hasAlpha = preg_match('/[A-Za-z]/', $val);
                $hasSym   = preg_match('/[^A-Za-z0-9._\/-]/', $val);
                if (!(($hasDigit && $hasAlpha && strlen($val) >= 10) || $hasSym && strlen($val) >= 8 && $hasAlpha || strlen($val) >= 24)) continue;
            }
            if (preg_match('#^(/(run|etc|mnt|boot|var|usr|home|root|tmp|opt|srv|config|data)/|\./|\.\./|~/|https?://[^@]*$)#', $val)) continue;
            // a bare variable NAME on the right ("KEY=SOME_VAR_NAME") is a reference, not a value
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', $val) && strlen($val) < 40) continue;
            return 'value for sensitive key "' . $m[1] . '"';
        }
    }
    // --password foo
    if (preg_match('/--(?:password|passwd|token|secret|api-key|apikey)(?:=|\s+)(?!\*\*\*)([^\s"\']{6,})/i', $text, $m) && !aia_is_placeholder($m[1])) return 'secret passed as option';
    // long hex strings (not image digests)
    if (preg_match_all('/(?<![A-Za-z0-9:_.-])([0-9a-fA-F]{40,})(?![A-Za-z0-9])/', $text, $mh, PREG_OFFSET_CAPTURE)) {
        foreach ($mh[1] as $h) {
            $before = substr($text, max(0, $h[1] - 8), 8);
            if (stripos($before, 'sha256:') !== false || stripos($before, 'sha1:') !== false) continue;
            return 'long hex token';
        }
    }
    // long base64/base64url-looking tokens with mixed case + digits
    if (preg_match_all('~(?<![A-Za-z0-9+/_=.-])([A-Za-z0-9+/_-]{40,}={0,2})(?![A-Za-z0-9+/_=-])~', $text, $mb)) {
        foreach ($mb[1] as $t) {
            if (preg_match('/[a-z]/', $t) && preg_match('/[A-Z]/', $t) && preg_match('/\d/', $t) && substr_count($t, '/') < 3) return 'long base64-like token';
        }
    }
    return null;
}

/* --------------------------------------------------- destructive commands */

/** Split a command line into simple-command token lists; recurse into $(..), `..`, sh -c "..". */
function aia_split_segments($cmd, $depth = 0) {
    $segs = array();
    if ($depth > 6 || !is_string($cmd) || $cmd === '') return $segs;
    $len = strlen($cmd);
    $cur = '';
    $q = '';
    $subs = array();
    for ($i = 0; $i < $len; $i++) {
        $c = $cmd[$i];
        if ($q === "'") {
            if ($c === "'") $q = ''; 
            $cur .= $c;
            continue;
        }
        if ($c === '\\' && $i + 1 < $len) { $cur .= $c . $cmd[$i + 1]; $i++; continue; }
        if ($q === '"') {
            if ($c === '"') $q = '';
            $cur .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $q = $c; $cur .= $c; continue; }
        if ($c === ';' || $c === "\n" || $c === '&' || $c === '|' || $c === '(' || $c === ')' || $c === '{' || $c === '}') {
            if (trim($cur) !== '') $segs[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') $segs[] = $cur;
    // command substitutions anywhere (also inside double quotes)
    $inner = array();
    if (preg_match_all('/\$\(([^()]*)\)/', $cmd, $m)) foreach ($m[1] as $x) $inner[] = $x;
    if (preg_match_all('/`([^`]*)`/', $cmd, $m)) foreach ($m[1] as $x) $inner[] = $x;
    $out = array();
    foreach ($segs as $sg) {
        $toks = aia_tokenize($sg);
        if (!count($toks)) continue;
        $out[] = $toks;
        // nested shells / eval
        $b = basename($toks[0]);
        $k = 0;
        while ($k < count($toks) && !in_array(basename($toks[$k]), array('sh', 'bash', 'dash', 'zsh', 'ash', 'eval', 'ksh'), true)) $k++;
        if ($k < count($toks)) {
            $bn = basename($toks[$k]);
            if ($bn === 'eval') {
                foreach (aia_split_segments(implode(' ', array_slice($toks, $k + 1)), $depth + 1) as $s2) $out[] = $s2;
            } else {
                for ($j = $k + 1; $j < count($toks); $j++) {
                    if (preg_match('/^-[a-zA-Z]*c$/', $toks[$j]) && isset($toks[$j + 1])) {
                        foreach (aia_split_segments($toks[$j + 1], $depth + 1) as $s2) $out[] = $s2;
                        break;
                    }
                }
            }
        }
    }
    foreach ($inner as $x) foreach (aia_split_segments($x, $depth + 1) as $s2) $out[] = $s2;
    return $out;
}

/** Minimal shell word splitting (quotes removed, no expansion). */
function aia_tokenize($s) {
    $toks = array();
    $cur = '';
    $has = false;
    $q = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($q === "'") { if ($c === "'") $q = ''; else $cur .= $c; continue; }
        if ($q === '"') {
            if ($c === '\\' && $i + 1 < $len && strpos('"\\$`', $s[$i + 1]) !== false) { $cur .= $s[$i + 1]; $i++; continue; }
            if ($c === '"') $q = ''; else $cur .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') { $q = $c; $has = true; continue; }
        if ($c === '\\' && $i + 1 < $len) { $cur .= $s[$i + 1]; $i++; $has = true; continue; }
        if ($c === ' ' || $c === "\t") {
            if ($has || $cur !== '') { $toks[] = $cur; $cur = ''; $has = false; }
            continue;
        }
        $cur .= $c;
    }
    if ($has || $cur !== '') $toks[] = $cur;
    return $toks;
}

/** Remove wrapper prefixes (sudo, env, VAR=x, nohup, timeout N ...) and keywords. */
function aia_strip_wrappers($t) {
    $guard = 0;
    while (count($t) && $guard++ < 20) {
        $w = $t[0];
        $b = basename($w);
        if (in_array($b, array('then', 'do', 'else', '!', 'elif', 'if', 'while', 'until', 'time', 'exec', 'nohup', 'command', 'builtin', 'setsid', 'doas'), true) && $b !== '') {
            array_shift($t);
            while (count($t) && $b === 'command' && preg_match('/^-/', $t[0])) array_shift($t);
            continue;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $w)) { array_shift($t); continue; }
        if (in_array($b, array('sudo', 'env', 'nice', 'ionice', 'stdbuf', 'xargs', 'chroot', 'unbuffer'), true)) {
            array_shift($t);
            while (count($t) && preg_match('/^-/', $t[0])) {
                $o = array_shift($t);
                if (in_array($o, array('-u', '-g', '-n', '-c', '-C', '-p', '-i', '-o', '-e', '-I', '-L', '-P', '-s', '-d', '-E', '-S'), true) && $b !== 'xargs' && count($t) && !preg_match('/^-/', $t[0]) && $b !== 'env') array_shift($t);
                if ($b === 'xargs' && in_array($o, array('-I', '-n', '-P', '-d', '-E', '-L', '-s'), true) && count($t)) array_shift($t);
            }
            continue;
        }
        if ($b === 'timeout') { array_shift($t); while (count($t) && preg_match('/^-/', $t[0])) array_shift($t); if (count($t)) array_shift($t); continue; }
        if ($b === 'flock') { array_shift($t); while (count($t) && preg_match('/^-/', $t[0])) array_shift($t); if (count($t)) array_shift($t); continue; }
        break;
    }
    return $t;
}

function aia_norm_path($p, $cwd) {
    if ($p === '') return $cwd;
    if ($p[0] === '~') $p = '/root' . substr($p, 1);
    if ($p[0] !== '/') $p = rtrim($cwd === '' ? '/' : $cwd, '/') . '/' . $p;
    $parts = array();
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return '/' . implode('/', $parts);
}

/** Is path (already normalized, may contain a glob) something an rm -r must never touch? */
function aia_protected_path($p) {
    $base = preg_replace('~/\*+$~', '', $p);          // /mnt/* -> /mnt
    if ($base === '' || $p === '/') return true;     // "/" or "/*"
    if (preg_match('~^/(mnt|boot)(/|$)~', $base)) return true;
    if (preg_match('~^/(etc|usr|var|bin|sbin|lib|lib64|root|home|dev|proc|sys|opt|srv)$~', $base)) return true;
    if (preg_match('~^/\*~', $p)) return true;
    if (strpos($p, '*') !== false && preg_match('~^/(mnt|boot)~', $p)) return true;
    return false;
}

function aia_is_blockdev($p) {
    return (bool)preg_match('~^/dev/(sd[a-z]|hd[a-z]|vd[a-z]|xvd[a-z]|nvme\d|md\d|mmcblk|loop\d|dm-\d|mapper/|disk/|zd\d|sr\d|nbd\d)~', $p);
}

/**
 * Evaluate one simple command. Returns a reason string if it must be blocked, else null.
 */
function aia_check_simple($toks, $cwd) {
    $t = aia_strip_wrappers($toks);
    if (!count($t)) return null;
    $cmd = basename($t[0]);
    $args = array_slice($t, 1);
    $argstr = implode(' ', $args);
    $flags = array();
    foreach ($args as $a) if (strlen($a) > 1 && $a[0] === '-') $flags[] = $a;

    if (preg_match('/^(mkfs(\.[A-Za-z0-9]+)?|mke2fs|mkfs\.[a-z0-9]+|mkswap|mkntfs|mkdosfs|mkreiserfs|mkudffs)$/', $cmd)) {
        return 'filesystem creation (' . $cmd . ') is blocked';
    }
    if ($cmd === 'wipefs') return 'wipefs is blocked';
    if ($cmd === 'blkdiscard') return 'blkdiscard is blocked';
    if ($cmd === 'shred') return 'shred is blocked';
    if ($cmd === 'dd') {
        foreach ($args as $a) {
            if (preg_match('~^of=(/dev/.*)$~', $a, $m) && !preg_match('~^/dev/(null|zero|stdout|stderr|tty|fd/|full)~', $m[1])) {
                return 'dd writing to a device is blocked';
            }
        }
        return null;
    }
    if ($cmd === 'fdisk' || $cmd === 'cfdisk' || $cmd === 'gdisk' || $cmd === 'cgdisk') {
        foreach ($flags as $f) if ($f === '-l' || $f === '--list' || $f === '-s' || $f === '--getsz' || $f === '-V' || $f === '--version') return null;
        return $cmd . ' (partition editor) is blocked';
    }
    if ($cmd === 'sfdisk') {
        foreach ($flags as $f) if (in_array($f, array('-l', '--list', '-s', '--show-size', '-d', '--dump', '-F', '--list-free', '-J', '--json', '-V', '--version', '--verify', '-h', '--help'), true)) return null;
        return 'sfdisk writing is blocked';
    }
    if ($cmd === 'parted') {
        if (preg_match('/\b(mklabel|mktable|mkpart|mkpartfs|rm|resizepart|resize|name|set|toggle|move|rescue|cp|disk_set|disk_toggle)\b/', $argstr)) return 'parted writing is blocked';
        return null;
    }
    if ($cmd === 'sgdisk') {
        foreach ($args as $a) {
            if (preg_match('/^-[A-Za-z]*[ozndtcgGUuRsCAmjelBZ]/', $a) && !preg_match('/^--/', $a)) return 'sgdisk writing is blocked';
            if (preg_match('/^--(clear|zap-all|zap|new|delete|typecode|change-name|mbrtogpt|randomize-guids|disk-guid|partition-guid|replicate|sort|recompute-chs|attributes|gpttombr|move-main-table|move-second-header|load-backup|resize-table|align-end|set-alignment|hybrid)/', $a)) return 'sgdisk writing is blocked';
        }
        return null;
    }
    if ($cmd === 'mdcmd') {
        $sub = count($args) ? $args[0] : '';
        if ($sub === '' || $sub === 'status' || $sub === '-h' || $sub === '--help') return null;
        return 'mdcmd ' . $sub . ' changes the array and is blocked (only "mdcmd status" is allowed)';
    }
    if ($cmd === 'emcmd') {
        if (preg_match('/cmd(Start|Stop|NewConfig|Format|Clear)|changeDevice|cmdCheck=Cancel/i', $argstr)) return 'emcmd array/slot operation is blocked';
        return null;
    }
    if ($cmd === 'zpool') {
        if (isset($args[0]) && in_array($args[0], array('destroy', 'labelclear'), true)) return 'zpool ' . $args[0] . ' is blocked';
        return null;
    }
    if ($cmd === 'zfs') {
        if (isset($args[0]) && $args[0] === 'destroy') return 'zfs destroy is blocked';
        return null;
    }
    if ($cmd === 'btrfs') {
        if (preg_match('/^device\s+(delete|remove)\b/', $argstr)) return 'btrfs device delete is blocked';
        if (preg_match('/^check\b.*--repair/', $argstr)) return 'btrfs check --repair is blocked';
        return null;
    }
    if ($cmd === 'docker' || $cmd === 'podman') {
        $pos = array();
        foreach ($args as $a) if (!(strlen($a) && $a[0] === '-')) $pos[] = $a;
        if (isset($pos[0], $pos[1]) && $pos[0] === 'system' && $pos[1] === 'prune') {
            foreach ($flags as $f) if (preg_match('/^(--all|-[a-zA-Z]*a[a-zA-Z]*)$/', $f)) return 'docker system prune -a is blocked';
        }
        if (isset($pos[0], $pos[1]) && $pos[0] === 'volume' && in_array($pos[1], array('rm', 'remove', 'prune'), true)) return 'docker volume ' . $pos[1] . ' is blocked';
        return null;
    }
    if ($cmd === 'tee') {
        foreach ($args as $a) if (aia_is_blockdev($a)) return 'tee onto a block device is blocked';
        return null;
    }
    if ($cmd === 'find') {
        $danger = false;
        foreach ($args as $a) if (in_array($a, array('-delete', '-exec', '-execdir', '-ok', '-okdir'), true)) $danger = true;
        if ($danger) {
            foreach ($args as $a) {
                if (strlen($a) && $a[0] === '-') break;
                if ($a === '!' || $a === '(') break;
                if (aia_protected_path(aia_norm_path($a, $cwd))) {
                    if (in_array('-delete', $args, true) || preg_match('/\b(rm|shred|mkfs|dd|wipefs)\b/', $argstr)) return 'find deleting under a protected path is blocked';
                }
            }
        }
        return null;
    }
    if ($cmd === 'rm') {
        $rec = false; $npr = false; $ops = array();
        $endopts = false;
        foreach ($args as $a) {
            if (!$endopts && $a === '--') { $endopts = true; continue; }
            if (!$endopts && strlen($a) > 1 && $a[0] === '-') {
                if ($a === '--recursive' || preg_match('/^-[a-zA-Z]*[rR][a-zA-Z]*$/', $a)) $rec = true;
                if ($a === '--no-preserve-root') $npr = true;
                continue;
            }
            $ops[] = $a;
        }
        if ($npr) return 'rm --no-preserve-root is blocked';
        if ($rec) {
            foreach ($ops as $o) {
                $p = aia_norm_path($o, $cwd);
                if (aia_protected_path($p)) return 'recursive rm on ' . $p . ' is blocked';
                // glob/relative operand resolving to a protected parent, e.g. "rm -rf *" inside /mnt/...
                if (strpos($o, '*') !== false && aia_protected_path(dirname($p))) return 'recursive rm with wildcard under a protected path is blocked';
            }
            if (!count($ops) && aia_protected_path($cwd)) return 'recursive rm in a protected directory';
        } else {
            foreach ($ops as $o) { if (aia_norm_path($o, $cwd) === '/') return 'rm on / is blocked'; }
        }
        return null;
    }
    return null;
}

/** Built-in raw-text patterns (cheap second layer; operate on the whole command string). */
function aia_builtin_raw_patterns() {
    return array(
        '~>>?\s*/dev/(sd[a-z]|hd[a-z]|vd[a-z]|nvme\d|md\d|mmcblk|dm-\d|mapper/|disk/|zd\d)~' => 'redirect onto a block device is blocked',
        '~>>?\s*/proc/mdcmd~' => 'writing to /proc/mdcmd is blocked',
        '~\bdd\b[^\n;&|]*\bof=/dev/(?!null|zero|stdout|stderr|tty|full)~' => 'dd writing to a device is blocked',
        '~/update\.htm[^\n]*\bcmd(Start|Stop|NewConfig|Format)\b~i' => 'emhttp array command via update.htm is blocked',
    );
}

/** Split EXTRA_BLOCKED_PATTERNS on newlines / literal \n / unescaped | outside ()[]. */
function aia_split_patterns($raw) {
    $raw = str_replace(array("\r", '\\n'), array('', "\n"), (string)$raw);
    $out = array();
    $cur = '';
    $depth = 0; $inb = false;
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($c === '\\' && $i + 1 < $len) { $cur .= $c . $raw[$i + 1]; $i++; continue; }
        if ($inb) { if ($c === ']') $inb = false; $cur .= $c; continue; }
        if ($c === '[') { $inb = true; $cur .= $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')' && $depth > 0) $depth--;
        if (($c === "\n" || ($c === '|' && $depth === 0))) {
            if (trim($cur) !== '') $out[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}

/**
 * Main entry: returns a block reason (string) or null to allow.
 * $extra = EXTRA_BLOCKED_PATTERNS raw cfg string.
 */
function aia_check_command($cmd, $cwd, $extra) {
    if (!is_string($cmd) || trim($cmd) === '') return null;
    // 1. token layer
    $segs = aia_split_segments($cmd);
    foreach ($segs as $toks) {
        $r = aia_check_simple($toks, $cwd);
        if ($r !== null) return $r;
    }
    // 2. raw layer
    foreach (aia_builtin_raw_patterns() as $re => $why) {
        if (@preg_match($re, $cmd)) return $why;
    }
    // 3. user patterns
    foreach (aia_split_patterns($extra) as $p) {
        $re = '~' . str_replace('~', '\\~', $p) . '~i';
        $res = @preg_match($re, $cmd);
        if ($res === 1) return 'matches EXTRA_BLOCKED_PATTERNS: ' . $p;
        if ($res === false) aia_log_line('invalid EXTRA_BLOCKED_PATTERNS regex ignored: ' . $p);
    }
    return null;
}

/** Normalised absolute path (no realpath: file may not exist yet). */
function aia_is_memory_path($path, $cwd = '') {
    if (!is_string($path) || $path === '') return false;
    $p = aia_norm_path($path, $cwd);
    foreach (aia_memory_dirs() as $d) {
        $d = aia_norm_path($d, '/');
        if ($p === $d || strpos($p, $d . '/') === 0) return true;
    }
    return false;
}

function aia_trunc($s, $n) {
    if (!is_string($s)) $s = json_encode($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return strlen($s) > $n ? substr($s, 0, $n - 3) . '...' : $s;
}

function aia_append_activity($rec) {
    $dir = aia_ram() . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) return false;
    $line = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) return false;
    $f = $dir . '/activity.jsonl';
    $new = !file_exists($f);
    $ok = @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
    if ($new) @chmod($f, 0666); // hooks may run as RUN_USER while the dir was made by root
    return $ok;
}

/* ------------------------------------------------- discovery (read-only) mode */

/**
 * First unquoted output redirection whose target is not /dev/null or a file descriptor
 * (2>&1, >&2 are fine). Also flags process substitution. Returns the target text or null.
 */
function aia_find_file_redirect($cmd) {
    $len = strlen($cmd);
    $q = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $cmd[$i];
        if ($q === "'") { if ($c === "'") $q = ''; continue; }
        if ($c === '\\' && $i + 1 < $len) { $i++; continue; }
        if ($q === '"') { if ($c === '"') $q = ''; continue; }
        if ($c === "'" || $c === '"') { $q = $c; continue; }
        if ($c === '<' && $i + 1 < $len && $cmd[$i + 1] === '(') return 'process substitution';
        if ($c !== '>') continue;
        $j = $i + 1;
        if ($j < $len && $cmd[$j] === '(') return 'process substitution';
        if ($j < $len && ($cmd[$j] === '>' || $cmd[$j] === '|')) $j++;
        if ($j < $len && $cmd[$j] === '&') {                      // >&2, 2>&1, >&-
            if ($j + 1 < $len && (ctype_digit($cmd[$j + 1]) || $cmd[$j + 1] === '-')) { $i = $j + 1; continue; }
            $j++;                                                   // &>file / >&file
        }
        while ($j < $len && ($cmd[$j] === ' ' || $cmd[$j] === "\t")) $j++;
        $k = $j;
        while ($k < $len && strpos(" \t\n;&|()<>", $cmd[$k]) === false) $k++;
        $target = trim(substr($cmd, $j, $k - $j), '"\'');
        if ($target !== '/dev/null') return $target === '' ? '(unknown)' : $target;
        $i = max($i, $k - 1);
    }
    return null;
}

/**
 * Discovery mode (env AIA_DISCOVERY=1, set by discover.sh): refuse ANY command that could write or
 * change state, on top of aia_check_command(). Defense in depth next to the permission rules in
 * settings.base.json. Returns a reason or null.
 */
/** Drop redirection leftovers (2>&1 is split at "&" into "2>"/"1", "2>/dev/null", ">&2" ...). File targets were already vetted by aia_find_file_redirect(). */
function aia_drop_redirect_tokens($a) {
    $o = array();
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i];
        if (preg_match('~^(\d*|&)>>?(&?\d*|&-|/dev/null)$~', $x)) {
            // a bare "2>" / "&>" may be followed by its target token only when it is /dev/null or a fd number
            if (preg_match('~^(\d*|&)>>?$~', $x) && $i + 1 < $n && preg_match('~^(/dev/null|\d+)$~', $a[$i + 1])) $i++;
            continue;
        }
        $o[] = $x;
    }
    return $o;
}

/** mount: listing only (no args, -l, -t TYPE, -h/-V, --show-labels ...). Anything with a device/dir or -o/--bind/-a is a mutation. */
function aia_mount_listing_only($a) {
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i];
        if ($x === '-t' || $x === '--types') { if ($i + 1 >= $n) return 'mount -t needs a type'; $i++; continue; }
        if (preg_match('~^(-t.+|--types=.+|-l|--show-labels|-h|--help|-V|--version|-v|--verbose|-n|--no-mtab)$~', $x)) continue;
        return 'mount with arguments can change state (only listing: no args, -l, -t TYPE)';
    }
    return null;
}

/** swapon: only the listing forms (--show[=cols], -s, --summary, --noheadings/--raw/--bytes/-o cols). */
function aia_swapon_listing_only($a) {
    $list = false;
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i];
        if ($x === '--show' || strpos($x, '--show=') === 0 || $x === '-s' || $x === '--summary') { $list = true; continue; }
        if (in_array($x, array('--noheadings', '--raw', '--bytes', '-h', '--help', '-V', '--version'), true) || strpos($x, '--output=') === 0 || strpos($x, '--output-all') === 0) continue;
        if ($x === '-o' || $x === '--output') { $i++; continue; }
        return 'swapon can change state (only "swapon --show" / "swapon -s" are allowed)';
    }
    return $list || $n === 0 ? null : 'swapon can change state';
}

/** crontab: only -l (optionally -u USER). -r, -e, -i, "-" and FILE all modify the crontab. */
function aia_crontab_listing_only($a) {
    $l = false;
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $x = $a[$i];
        if ($x === '-l') { $l = true; continue; }
        if ($x === '-u' && $i + 1 < $n && preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $a[$i + 1])) { $i++; continue; }
        return 'crontab may only list (-l)';
    }
    return $l ? null : 'crontab may only list (-l)';
}

function aia_check_discovery_command($cmd) {
    if (!is_string($cmd) || trim($cmd) === '') return null;
    $t = aia_find_file_redirect($cmd);
    if ($t !== null) return 'output redirection to "' . $t . '" (discovery is read-only; only > /dev/null is allowed)';
    $writers = array('tee', 'cp', 'mv', 'rm', 'rmdir', 'unlink', 'chmod', 'chown', 'chgrp', 'mkdir', 'touch', 'ln', 'install', 'dd', 'truncate', 'shred', 'mkfifo', 'mknod',
        'patch', 'rsync', 'scp', 'sftp', 'ssh', 'tar', 'unzip', 'gzip', 'gunzip', 'bzip2', 'xz', 'zip', 'curl', 'wget', 'nc', 'ncat', 'socat', 'telnet', 'ftp',
        'kill', 'pkill', 'killall', 'reboot', 'shutdown', 'poweroff', 'halt', 'init', 'telinit', 'service', 'systemctl', 'modprobe', 'insmod', 'rmmod', 'umount',
        'swapon', 'swapoff', 'useradd', 'userdel', 'usermod', 'passwd', 'chpasswd', 'groupadd', 'at', 'batch', 'python', 'python3', 'perl', 'ruby', 'node', 'lua', 'tclsh',
        'installpkg', 'upgradepkg', 'removepkg', 'nohup', 'setsid', 'screen', 'tmux', 'git', 'wipefs', 'parted', 'fdisk', 'sfdisk', 'sgdisk', 'emcmd', 'notify');
    $dockerRead = array('ps', 'inspect', 'logs', 'stats', 'images', 'top', 'port', 'version', 'info', 'diff', 'history', 'events');
    $dockerSub = array('image' => array('ls', 'inspect', 'history'), 'container' => array('ls', 'inspect', 'top', 'port', 'diff', 'stats', 'logs'),
        'network' => array('ls', 'inspect'), 'volume' => array('ls', 'inspect'), 'system' => array('df', 'info', 'events'), 'context' => array('ls', 'show', 'inspect'));
    $composeRead = array('ls', 'ps', 'config', 'images', 'top', 'logs', 'version', 'port', 'events');
    $virshRead = '/^(version|list|dominfo|domstate|domblklist|domiflist|domifaddr|domstats|domid|domuuid|domname|dumpxml|domcapabilities|capabilities|nodeinfo|nodedev-list|nodedev-dumpxml|net-list|net-info|net-dumpxml|net-dhcp-leases|pool-list|pool-info|pool-dumpxml|vol-list|vol-info|vol-dumpxml|vcpuinfo|vcpupin|emulatorpin|dommemstat|domblkinfo|domblkstat|domdisplay|domcontrol|hostname|uri|sysinfo|help)$/';
    foreach (aia_split_segments($cmd) as $toks) {
        $t = aia_strip_wrappers($toks);
        if (!count($t)) continue;
        $c = basename($t[0]);
        $a = aia_drop_redirect_tokens(array_slice($t, 1));
        if ($c === 'php') {
            if (!(isset($a[0]) && preg_match('~/scripts/ua-sanitize$~', $a[0]))) return 'php is not allowed in discovery (only ua-sanitize)';
            continue;
        }
        if ($c === 'mount') { $r = aia_mount_listing_only($a); if ($r !== null) return $r; continue; }
        if ($c === 'swapon') { $r = aia_swapon_listing_only($a); if ($r !== null) return $r; continue; }
        if (in_array($c, $writers, true)) return $c . ' can change state (discovery is read-only)';
        if (in_array($c, array('eval', 'exec', 'source', '.'), true)) return $c . ' is not allowed in discovery';
        if (in_array($c, array('sh', 'bash', 'dash', 'ash', 'zsh', 'ksh'), true)) {
            if (!preg_grep('/^-[a-zA-Z]*c$/', $a)) return 'running shell scripts is not allowed in discovery';
            continue;   // "sh -c '...'" bodies are already part of the segment list
        }
        if (preg_match('~\.(sh|py|pl|rb)$~', $c)) return 'running scripts is not allowed in discovery';
        if (preg_match('~^rc\.[A-Za-z0-9_.-]+$~', $c)) return 'rc scripts are not allowed in discovery';
        switch ($c) {
        case 'sed': case 'perl':
            foreach ($a as $x) {
                if ((strlen($x) > 1 && $x[0] === '-' && $x[1] !== '-' && strpos($x, 'i') !== false && preg_match('/^-[a-zA-Z]+$/', $x)) || $x === '--in-place' || strpos($x, '--in-place=') === 0) return 'sed -i edits files';
                if (preg_match('~(^|[;{\s])w\s*/~', $x) || preg_match('~/[gpI]*w\s*\S~', $x)) return 'sed w-command writes files';
            }
            break;
        case 'sort':
            foreach ($a as $x) if ($x === '-o' || strpos($x, '--output') === 0 || preg_match('/^-[a-zA-Z]*o$/', $x)) return 'sort -o writes files';
            break;
        case 'find':
            foreach ($a as $x) if (in_array($x, array('-delete', '-exec', '-execdir', '-ok', '-okdir', '-fprint', '-fprint0', '-fprintf', '-fls'), true)) return 'find ' . $x . ' can change state';
            break;
        case 'awk': case 'gawk': case 'mawk':
            foreach ($a as $x) if (preg_match('/system\s*\(|\|\s*getline|getline\s*<|\|\s*"|>\s*"|>>|close\s*\(|inplace/', $x) || $x === '-f' || $x === '--file') return 'awk program can run commands or write files';
            break;
        case 'date':
            foreach ($a as $x) if (strpos($x, '--set') === 0 || preg_match('/^-[a-zA-Z]*s[a-zA-Z]*$/', $x)) return 'date -s sets the clock';
            break;
        case 'dmesg':
            foreach ($a as $x) if (preg_match('/^-[a-zA-Z]*[cCnDE]/', $x) && $x[1] !== '-' || preg_match('/^--(clear|read-clear|console|enable|disable)/', $x)) return 'dmesg option changes kernel log state';
            break;
        case 'journalctl':
            foreach ($a as $x) if (preg_match('/^--(rotate|vacuum|flush|relinquish|smart-relinquish|sync|setup-keys|update-catalog)/', $x)) return 'journalctl maintenance option';
            break;
        case 'crontab':
            $r = aia_crontab_listing_only($a); if ($r !== null) return $r;
            break;
        case 'sysctl':
            foreach ($a as $x) if ($x === '-w' || strpos($x, '=') !== false || $x === '-p' || $x === '--load' || $x === '--system') return 'sysctl may only read';
            break;
        case 'docker':
            foreach ($a as $x) if ($x === '-H' || $x === '--host' || $x === '--context' || $x === '-c') return 'docker global option not allowed in discovery';
            $pos = array(); foreach ($a as $x) if ($x !== '' && $x[0] !== '-') $pos[] = $x;
            $v = isset($pos[0]) ? $pos[0] : '';
            if ($v === '') break;
            if ($v === 'compose') {
                $ok = false;
                foreach (array_slice($pos, 1) as $p) if (in_array($p, $composeRead, true)) $ok = true;
                foreach (array_slice($pos, 1) as $p) if (in_array($p, array('up', 'down', 'rm', 'start', 'stop', 'restart', 'pull', 'push', 'build', 'create', 'kill', 'pause', 'unpause', 'run', 'exec', 'cp', 'scale', 'watch', 'attach', 'commit', 'export', 'wait'), true)) $ok = false;
                if (!$ok) return 'docker compose action can change state';
            } elseif (isset($dockerSub[$v])) {
                if (!in_array(isset($pos[1]) ? $pos[1] : '', $dockerSub[$v], true)) return 'docker ' . $v . ' ' . (isset($pos[1]) ? $pos[1] : '') . ' can change state';
            } elseif (!in_array($v, $dockerRead, true)) {
                return 'docker ' . $v . ' can change state';
            }
            break;
        case 'virsh':
            $verb = '';
            for ($i = 0; $i < count($a); $i++) {
                if ($a[$i] === '-c' || $a[$i] === '--connect') { $i++; continue; }
                if ($a[$i] !== '' && $a[$i][0] === '-' && !preg_match('/^(-v|-V|--version)$/', $a[$i])) continue;
                $verb = $a[$i]; break;
            }
            if ($verb === '' || !preg_match($virshRead, $verb) && !preg_match('/^(-v|-V|--version)$/', $verb)) return 'virsh ' . $verb . ' can change state (read verbs only)';
            break;
        case 'smartctl':
            foreach ($a as $x) {
                if (preg_match('/^--(test|smart|offlineauto|saveauto|set|abort|captive|drive-flags)/', $x)) return 'smartctl option changes drive state';
                if (preg_match('/^-[a-zA-Z]+$/', $x) && preg_match('/[tsoSXCrTbF]/', $x)) return 'smartctl option changes drive state';
            }
            break;
        case 'ip':
            $objs = array(); foreach ($a as $x) if ($x !== '' && $x[0] !== '-') $objs[] = $x;
            if (isset($objs[1]) && preg_match('/^(add|del|delete|set|flush|replace|change|append|prepend|exec|save|restore|monitor|annotate|test)$/', $objs[1])) return 'ip ' . $objs[0] . ' ' . $objs[1] . ' changes network state';
            if (isset($objs[0]) && $objs[0] === 'netns' && isset($objs[1]) && $objs[1] !== 'list') return 'ip netns can change state';
            break;
        case 'iptables': case 'ip6tables': case 'iptables-legacy': case 'ip6tables-legacy':
            foreach ($a as $x) if (preg_match('/^-[ADIFXPNRZE]/', $x) || preg_match('/^--(append|delete|insert|flush|delete-chain|policy|new-chain|replace|zero|rename-chain)$/', $x)) return $c . ' modifies rules';
            break;
        case 'nft':
            foreach ($a as $x) if (preg_match('/^(add|delete|flush|insert|replace|create|destroy|rename|reset|-f|-i)$/', $x)) return 'nft modifies rules';
            break;
        case 'wg':
            $v = isset($a[0]) ? $a[0] : '';
            if ($v !== '' && !preg_match('/^(show|showconf)$/', $v)) return 'wg ' . $v . ' can change state';
            break;
        case 'tailscale':
            $v = isset($a[0]) ? $a[0] : '';
            if (!preg_match('/^(status|ip|version|netcheck|whois|--version)$/', $v)) return 'tailscale ' . $v . ' can change state';
            break;
        case 'ethtool':
            foreach ($a as $x) {
                if (preg_match('/^-[sKAGCEfwtprLXNUW]$/', $x)) return 'ethtool option changes NIC state';
                if (preg_match('/^--(set|change|flash|reset|identify|test|config|pause|coalesce|features|offload|per-queue|get-)/', $x) && !preg_match('/^--get-/', $x)) return 'ethtool option changes NIC state';
            }
            break;
        case 'zpool':
            $v = isset($a[0]) ? $a[0] : '';
            if (!preg_match('/^(status|list|get|history|iostat|events|version|--version)$/', $v)) return 'zpool ' . $v . ' can change state';
            if ($v === 'events') foreach ($a as $x) if ($x === '-c') return 'zpool events -c clears the event log';
            break;
        case 'zfs':
            $v = isset($a[0]) ? $a[0] : '';
            if (!preg_match('/^(list|get|mount|diff|version|--version|userspace|groupspace)$/', $v) || ($v === 'mount' && count($a) > 1)) return 'zfs ' . $v . ' can change state';
            break;
        case 'btrfs':
            $s = implode(' ', array_filter($a, function ($x) { return $x === '' || $x[0] !== '-'; }));
            if (!preg_match('/^(fi(lesystem)? (show|usage|df)|dev(ice)? (stats|usage)|subvol(ume)? (list|show)|scrub status|balance status|version)/', $s) && !in_array('--version', $a, true)) return 'btrfs ' . $s . ' can change state';
            foreach ($a as $x) if ($x === '-z' || $x === '--reset') return 'btrfs device stats -z resets counters';
            break;
        case 'nvidia-smi':
            foreach ($a as $x) if (preg_match('/^(-pm|-e|-p|-c|-r|-pl|-ac|-rac|-acp|-lgc|-rgc|-lmc|-rmc|-mig|-gom|--persistence-mode|--ecc-config|--power-limit|--gpu-reset|--compute-mode|--applications-clocks|--lock-gpu-clocks|--reset-gpu-clocks)(=|$)/', $x)) return 'nvidia-smi option changes GPU state';
            break;
        case 'mdcmd':
            if (!(isset($a[0]) && $a[0] === 'status')) return 'mdcmd may only run "status"';
            break;
        case 'hdparm':
            foreach ($a as $x) if ($x !== '' && $x[0] === '-' && !preg_match('/^-[iI]$/', $x)) return 'hdparm may only identify (-i/-I)';
            break;
        case 'sensors':
            foreach ($a as $x) if ($x === '-s' || $x === '--set') return 'sensors -s changes limits';
            break;
        case 'dmidecode':
            foreach ($a as $x) if (strpos($x, '--dump-file') === 0) return 'dmidecode --dump-file writes files';
            break;
        case 'nvme':
            $v = isset($a[0]) ? $a[0] : '';
            if (!preg_match('/^(list|smart-log|id-ctrl|id-ns|list-ns|error-log|version)$/', $v)) return 'nvme ' . $v . ' can change state';
            break;
        case 'tree':
            foreach ($a as $x) if ($x === '-o') return 'tree -o writes files';
            break;
        case 'file':
            foreach ($a as $x) if ($x === '-C') return 'file -C writes a file';
            break;
        case 'xxd':
            foreach ($a as $x) if ($x === '-r') return 'xxd -r writes files';
            break;
        }
    }
    return null;
}

function aia_discovery_mode() { return getenv('AIA_DISCOVERY') === '1'; }

#!/usr/bin/php
<?php
/* AI Assistant for Unraid (for Claude Code) - chat worker.
 *
 *   php chat-worker.php <run_id>
 *
 * Started detached (setsid) by include/chat.php. Runs ONE user message through
 *   claude -p --input-format stream-json --output-format stream-json --verbose [--resume ID | --session-id ID]
 *          --permission-mode <mode> --permission-prompt-tool mcp__aia__approve --mcp-config <run>.mcp.json
 * as RUN_USER in WORK_DIR with exactly the device's environment (scripts/common.sh claude_cmdline), and
 * appends every stream-json line to run/chat/<run_id>.jsonl, plus our own {"type":"aia",...} lines.
 * Ends when claude emits its `result` event, when <run_id>.stop appears, or after 30 minutes.
 */

@ini_set('display_errors', '0');
@error_reporting(0);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/include/chatlib.php';

$RUN = isset($argv[1]) ? $argv[1] : '';
if (!aia_chat_run_id_ok($RUN)) {
    fwrite(STDERR, "bad run id\n");
    exit(2);
}
$DIR = aia_chat_dir();
$EVENTS = $DIR . '/' . $RUN . '.jsonl';
$STOPF = $DIR . '/' . $RUN . '.stop';
$META = aia_chat_meta($RUN);
if (!$META) {
    exit(3);
}

$stopSignal = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $h = function () use (&$stopSignal) {
        $stopSignal = true;
    };
    pcntl_signal(SIGTERM, $h);
    pcntl_signal(SIGINT, $h);
    pcntl_signal(SIGHUP, SIG_IGN);
}

// own process group so that stopping can take claude's children (MCP server, tool processes) with it
$ownGroup = false;
if (function_exists('posix_getpgid') && function_exists('posix_getpid')) {
    if (@posix_getpgid(0) === posix_getpid()) {
        $ownGroup = true;
    } elseif (function_exists('posix_setsid') && @posix_setsid() > 0) {
        $ownGroup = true;
    }
}

function aia_w_log($msg)
{
    $d = AIA_RAM . '/logs';
    if (is_dir($d)) {
        @file_put_contents($d . '/chat.log', date('Y-m-d H:i:s') . ' [chat] ' . $msg . "\n", FILE_APPEND);
    }
}

function aia_w_emit($fh, array $ev)
{
    fwrite($fh, json_encode($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush($fh);
}

$ev = @fopen($EVENTS, 'ab');
if (!$ev) {
    exit(4);
}
@chmod($EVENTS, 0644);

function aia_w_finish(array $META, $state, $fh, $ok, $error, $reason = '')
{
    global $RUN;
    $m = aia_chat_meta($RUN);
    if (is_array($m)) {
        $META = array_merge($META, $m);
    }
    $META['state'] = $state;
    $META['ended'] = time();
    if ($error !== '') {
        $META['error'] = $error;
    }
    $e = array('type' => 'aia', 'kind' => 'end', 'ok' => $ok, 't' => time());
    if ($error !== '') {
        $e['error'] = $error;
    }
    if ($reason !== '') {
        $e['reason'] = $reason;
    }
    aia_w_emit($fh, $e);
    aia_chat_meta_save($RUN, $META);
    aia_chat_cleanup_perms($RUN);
    @unlink($GLOBALS['DIR'] . '/' . $RUN . '.in.json');
}

$META['pid'] = getmypid();
$META['pgid'] = $ownGroup ? getmypid() : 0;
aia_chat_meta_save($RUN, $META);

$in = aia_chat_read_json($DIR . '/' . $RUN . '.in.json', 20 * 1048576);
if (!$in || empty($in['line'])) {
    aia_w_finish($META, 'failed', $ev, false, 'The message file is missing.');
    exit(5);
}
$cfg = aia_cfg();
$workDir = $cfg['WORK_DIR'];
if (!is_dir($workDir)) {
    @mkdir($workDir, 0755, true);
    if ($cfg['RUN_USER'] !== 'root') {
        @exec('chown ' . escapeshellarg($cfg['RUN_USER']) . ' ' . escapeshellarg($workDir) . ' 2>/dev/null');
    }
}
if (!is_dir($workDir)) {
    $workDir = '/';
}

// ---- generated MCP config: the permission server, with everything it needs in its own env
$phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' && strpos(basename(PHP_BINARY), 'php-fpm') === false ? PHP_BINARY : '/usr/bin/php';
$mcpEnv = array('AIA_RUN_ID' => $RUN, 'AIA_PERM_DIR' => aia_chat_perm_dir(), 'AIA_EMHTTP' => AIA_EMHTTP, 'AIA_RAM' => AIA_RAM, 'AIA_FLASH' => AIA_FLASH);
$mcp = array('mcpServers' => array('aia' => array(
    'type' => 'stdio',
    'command' => $phpBin,
    'args' => array(AIA_EMHTTP . '/mcp/approve.php'),
    'env' => $mcpEnv,
)));
aia_chat_write($DIR . '/' . $RUN . '.mcp.json', json_encode($mcp, JSON_UNESCAPED_SLASHES), 0644);

// ---- claude arguments
$args = array('-p', '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose');
$ver = trim((string)@file_get_contents(AIA_RAM . '/bin/claude.version'));
if ($ver !== '' && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $ver) && version_compare($ver, '2.1.211', '>=')) {
    $args[] = '--forward-subagent-text';   // sub-agent text/thinking with parent_tool_use_id (v2.1.211+)
}
if (!empty($META['new'])) {
    $args[] = '--session-id';
} else {
    $args[] = '--resume';
}
$args[] = $META['session_id'];
// permission mode of this conversation (whitelisted again here; bypassPermissions and dontAsk are never passed)
$mode = isset($META['mode']) && aia_chat_mode_ok($META['mode']) ? $META['mode'] : 'default';
$args[] = '--permission-mode';
$args[] = $mode;
$args[] = '--permission-prompt-tool';
$args[] = 'mcp__aia__approve';
$args[] = '--mcp-config';
$args[] = $DIR . '/' . $RUN . '.mcp.json';

$script = '. "$0"; load_cfg; exec bash -c "$(claude_cmdline "$@")"';
$cmd = array_merge(array('/bin/bash', '-c', $script, AIA_EMHTTP . '/scripts/common.sh'), $args);
$env = array('PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root');
foreach (array('AIA_EMHTTP', 'AIA_FLASH', 'AIA_RAM', 'AIA_UNRAID_VERSION_FILE', 'AIA_VARINI', 'AIA_STUB_LOG', 'AIA_CHAT_TEST') as $e) {
    $v = getenv($e);
    if ($v !== false) {
        $env[$e] = $v;
    }
}

$spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$proc = @proc_open($cmd, $spec, $pipes, $workDir, $env);
if (!is_resource($proc)) {
    aia_w_finish($META, 'failed', $ev, false, 'Could not start Claude Code.');
    exit(6);
}
aia_w_log("run $RUN session {$META['session_id']} " . (!empty($META['new']) ? 'new' : 'resume') . " user={$cfg['RUN_USER']} cwd=$workDir");

// our own record of what the user sent (the stream does not echo it)
aia_w_emit($ev, array('type' => 'aia', 'kind' => 'user', 'text' => (string)($in['text'] ?? ''), 'ctx' => isset($in['ctx']) ? $in['ctx'] : null,
    'image' => !empty($in['image']), 't' => time()));

stream_set_blocking($pipes[0], true);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
@fwrite($pipes[0], $in['line'] . "\n");
@fflush($pipes[0]);
unset($in);
@unlink($DIR . '/' . $RUN . '.in.json');   // may contain a screenshot: do not keep it

$buf = '';
$errTail = '';
$sawResult = false;
$stdinOpen = true;
$resultAt = 0;
$started = time();
$reason = '';
$killed = false;
$exit = -1;
$pstat = function () use (&$proc, &$exit) {
    $st = proc_get_status($proc);
    if (!$st["running"] && $st["exitcode"] !== -1) {
        $exit = (int)$st["exitcode"];
    }
    return $st;
};

$terminate = function () use (&$proc, $ownGroup, &$pstat) {
    $st = $pstat();
    if (!$st || !$st['running']) {
        return;
    }
    if ($ownGroup && function_exists('posix_kill')) {
        @posix_kill(-getmypid(), 15);   // we ignore it ourselves (handler above)
    } else {
        @proc_terminate($proc, 15);
    }
};

while (true) {
    $r = array($pipes[1], $pipes[2]);
    $w = null;
    $x = null;
    $n = @stream_select($r, $w, $x, 0, 250000);
    if ($n) {
        foreach ($r as $s) {
            $chunk = fread($s, 262144);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($s === $pipes[1]) {
                $buf .= $chunk;
                while (($p = strpos($buf, "\n")) !== false) {
                    $line = rtrim(substr($buf, 0, $p), "\r");
                    $buf = substr($buf, $p + 1);
                    if ($line === '') {
                        continue;
                    }
                    if ($line[0] === '{') {
                        $line = aia_chat_clamp_line($line);
                        if (strpos($line, '"type":"result"') !== false || strpos($line, '"type": "result"') !== false) {
                            $j = json_decode($line, true);
                            if (is_array($j) && ($j['type'] ?? '') === 'result') {
                                $sawResult = true;
                                $resultAt = time();
                            }
                        }
                        fwrite($ev, $line . "\n");
                    } else {
                        aia_w_log('stdout: ' . substr($line, 0, 300));
                    }
                }
                fflush($ev);
            } else {
                $errTail = substr($errTail . $chunk, -4000);
            }
        }
    }
    // one message per process: once the result is out, close stdin so claude exits
    if ($sawResult && $stdinOpen) {
        @fclose($pipes[0]);
        $stdinOpen = false;
    }
    $st = $pstat();
    if (!$st['running']) {
        break;
    }
    if ($stopSignal || is_file($STOPF)) {
        $reason = 'stopped';
        $terminate();
        break;
    }
    if (time() - $started > AIA_CHAT_MAX_RUN_SECONDS) {
        $reason = 'timeout';
        $terminate();
        break;
    }
    if ($sawResult && time() - $resultAt > 30) {
        $reason = 'lingering';
        $terminate();
        break;
    }
}

// let the child exit; escalate if it ignores TERM
$deadline = time() + 6;
while (true) {
    $st = $pstat();
    if (!$st['running']) {
        break;
    }
    if (time() > $deadline) {
        $killed = true;
        break;
    }
    usleep(100000);
}
// drain what is left
$rest = (string)@stream_get_contents($pipes[1]);
$errTail = substr($errTail . (string)@stream_get_contents($pipes[2]), -4000);
foreach (explode("\n", $buf . $rest) as $line) {
    $line = rtrim($line, "\r");
    if ($line !== '' && $line[0] === '{') {
        $line = aia_chat_clamp_line($line);
        $j = json_decode($line, true);
        if (is_array($j) && ($j['type'] ?? '') === 'result') {
            $sawResult = true;
        }
        fwrite($ev, $line . "\n");
    }
}
fflush($ev);
if ($stdinOpen) {
    @fclose($pipes[0]);
}
@fclose($pipes[1]);
@fclose($pipes[2]);

$errMsg = '';
if ($reason === 'stopped') {
    aia_w_emit($ev, array('type' => 'aia', 'kind' => 'note', 'level' => 'info', 'text' => 'Stopped.'));
    aia_w_finish($META, 'stopped', $ev, true, '', 'stopped');
} elseif ($reason === 'timeout') {
    aia_w_finish($META, 'failed', $ev, false, 'The run was stopped after 30 minutes.', 'timeout');
} else {
    $ok = $sawResult || $exit === 0;
    if (!$ok) {
        $t = trim(preg_replace('/\x1b\[[0-9;?]*[a-zA-Z]/', '', $errTail));
        $errMsg = $t !== '' ? aia_chat_mask(substr($t, -800)) : 'Claude Code exited with code ' . $exit . '.';
    }
    aia_w_finish($META, $ok ? 'done' : 'failed', $ev, $ok, $errMsg);
}
aia_w_log("run $RUN ended reason=" . ($reason ?: 'exit') . " exit=$exit result=" . ($sawResult ? 'yes' : 'no'));
fclose($ev);
if ($killed && $ownGroup && function_exists('posix_kill')) {
    @posix_kill(-getmypid(), 9);   // last resort: everything left in our group, ourselves included
}
@proc_close($proc);
exit(0);

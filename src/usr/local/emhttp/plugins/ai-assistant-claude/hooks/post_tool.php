#!/usr/bin/php
<?php
/*
 * post_tool.php - Claude Code PostToolUse hook (matcher: Bash|Write|Edit|MultiEdit|NotebookEdit|Agent|Task).
 * stdin = JSON {session_id, cwd, tool_name, tool_input, tool_response, ...}; exit 0 always (observational).
 * Appends one JSON line to $AIA_RAM/logs/activity.jsonl and logs to syslog (tag ai-assistant).
 * Secret values are masked (aia_mask) before anything is written.
 * Self-test: php post_tool.php --selftest
 */
require_once __DIR__ . '/hooklib.php';

function aia_post_record($in) {
    $tool = isset($in['tool_name']) ? (string)$in['tool_name'] : '';
    $ti = isset($in['tool_input']) && is_array($in['tool_input']) ? $in['tool_input'] : array();
    if ($tool === 'Bash') {
        $sum = isset($ti['command']) ? $ti['command'] : '';
    } elseif (isset($ti['file_path'])) {
        $sum = $ti['file_path'];
    } elseif ($tool === 'Agent' || $tool === 'Task') {
        $sum = (isset($ti['subagent_type']) ? $ti['subagent_type'] : 'agent') . ': ' . (isset($ti['description']) ? $ti['description'] : '');
    } elseif (isset($ti['notebook_path'])) {
        $sum = $ti['notebook_path'];
    } else {
        $sum = json_encode($ti);
    }
    $sid = isset($in['session_id']) ? (string)$in['session_id'] : '';
    return array(
        't' => time(),
        'session_id' => $sid,
        'session' => $sid,
        'tool' => $tool,
        'summary' => aia_trunc(aia_mask((string)$sum), 300),
        'cwd' => isset($in['cwd']) ? (string)$in['cwd'] : '',
        'blocked' => false,
    );
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--selftest') {
    putenv('AIA_NO_SYSLOG=1');
    $fail = 0;
    $r = aia_post_record(array('session_id' => 'a', 'cwd' => '/tmp', 'tool_name' => 'Bash', 'tool_input' => array('command' => 'MYSQL_PASSWORD=hunter2 docker run -e API_TOKEN=abc123xyz foo ' . str_repeat('x', 400))));
    if (strpos($r['summary'], 'hunter2') !== false || strpos($r['summary'], 'abc123xyz') !== false) { echo "FAIL: secret leaked in summary\n"; $fail++; }
    if (strlen($r['summary']) > 300) { echo "FAIL: summary too long\n"; $fail++; }
    if ($r['blocked'] !== false || $r['tool'] !== 'Bash' || $r['cwd'] !== '/tmp') { echo "FAIL: record shape\n"; $fail++; }
    $r = aia_post_record(array('session_id' => 'a', 'tool_name' => 'Write', 'tool_input' => array('file_path' => '/x/y.md', 'content' => 'z')));
    if ($r['summary'] !== '/x/y.md') { echo "FAIL: write summary\n"; $fail++; }
    $r = aia_post_record(array('session_id' => 'a', 'tool_name' => 'Agent', 'tool_input' => array('subagent_type' => 'unraid-scout', 'description' => 'check disks')));
    if ($r['summary'] !== 'unraid-scout: check disks') { echo "FAIL: agent summary\n"; $fail++; }
    // end-to-end into a sandbox
    $tmp = sys_get_temp_dir() . '/aia-post-selftest-' . getmypid();
    putenv('AIA_RAM=' . $tmp);
    aia_append_activity(aia_post_record(array('session_id' => 's', 'tool_name' => 'Bash', 'tool_input' => array('command' => 'ls'))));
    $l = @file($tmp . '/logs/activity.jsonl');
    $j = $l ? json_decode($l[0], true) : null;
    if (!is_array($j) || $j['summary'] !== 'ls') { echo "FAIL: activity.jsonl not written\n"; $fail++; }
    @unlink($tmp . '/logs/activity.jsonl'); @rmdir($tmp . '/logs'); @rmdir($tmp);
    echo $fail === 0 ? "post_tool selftest OK\n" : "post_tool selftest FAILED\n";
    exit($fail === 0 ? 0 : 1);
}

try {
    @set_error_handler(function () { return true; });
    $in = json_decode((string)@stream_get_contents(STDIN), true);
    if (is_array($in)) {
        $rec = aia_post_record($in);
        aia_append_activity($rec);
        aia_syslog($rec['tool'] . ' [' . substr($rec['session_id'], 0, 8) . '] ' . $rec['summary']);
    }
} catch (Throwable $e) {
    @aia_log_line('post_tool error: ' . $e->getMessage());
}
exit(0);

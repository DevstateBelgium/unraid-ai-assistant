#!/usr/bin/php
<?php
/*
 * mcp/approve.php - minimal MCP stdio server exposing ONE tool, `approve`, for
 *     claude -p ... --permission-prompt-tool mcp__aia__approve --mcp-config <json pointing here>
 *
 * Contract (checked against the Claude Code 2.1.287 binary and the Agent SDK permission docs):
 *   Claude Code calls tools/call "approve" with arguments
 *       { "tool_name": "Bash", "input": { ...the tool's input... }, "tool_use_id": "toolu_..." }
 *   and expects a single text content block holding a JSON document:
 *       allow:  {"behavior":"allow","updatedInput":{...}}      (updatedInput = the original input to run it unchanged)
 *       deny:   {"behavior":"deny","message":"...","interrupt":false}
 *   Anything else is treated as a schema-invalid result and the call is denied.
 *
 * Transport: newline-delimited JSON-RPC 2.0 on stdin/stdout (MCP stdio). stdout carries ONLY protocol
 * messages; diagnostics go to stderr.
 *
 * The request is written to $AIA_PERM_DIR/<run>.<id>.req.json; the WebGUI (include/chat.php chat_approve)
 * answers with <run>.<id>.dec.json {"decision":"allow|deny","message":""}. Polled every 300 ms.
 * No answer within 10 minutes (or stdin closing, or a cancel notification) = deny.
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@error_reporting(0);
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('AIA_APPROVE_TIMEOUT', (int)(getenv('AIA_APPROVE_TIMEOUT') ?: 600));
define('AIA_APPROVE_POLL_US', 300000);

$AIA_RUN = (string)getenv('AIA_RUN_ID');
$AIA_PERM = rtrim((string)getenv('AIA_PERM_DIR'), '/');
$AIA_STDIN_BUF = '';
$AIA_STDIN_EOF = false;
$AIA_CANCELLED = array();

function ap_log($m)
{
    fwrite(STDERR, '[aia-approve] ' . $m . "\n");
}

function ap_send($obj)
{
    $j = json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j === false) {
        $j = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"encode failed"}}';
    }
    fwrite(STDOUT, $j . "\n");
    fflush(STDOUT);
}

function ap_result($id, $result)
{
    ap_send(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result));
}

function ap_error($id, $code, $msg)
{
    ap_send(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $msg)));
}

function ap_tool_result($payload, $isError = false)
{
    return array(
        'content' => array(array('type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))),
        'isError' => $isError,
    );
}

function ap_tool_def()
{
    return array(
        'name' => 'approve',
        'description' => 'Ask the Unraid WebGUI user to allow or deny a tool call. Used only as Claude Code\'s permission prompt tool.',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'tool_name' => array('type' => 'string', 'description' => 'Name of the tool that needs permission'),
                'input' => array('type' => 'object', 'description' => 'Input of that tool call', 'additionalProperties' => true),
                'tool_use_id' => array('type' => 'string', 'description' => 'Id of the tool use'),
            ),
            'required' => array('tool_name', 'input'),
        ),
    );
}

/** Read whatever is available on stdin into the buffer without blocking beyond $us microseconds. */
function ap_pump($us)
{
    global $AIA_STDIN_BUF, $AIA_STDIN_EOF;
    if ($AIA_STDIN_EOF) {
        usleep($us);
        return;
    }
    $r = array(STDIN);
    $w = null;
    $x = null;
    $n = @stream_select($r, $w, $x, 0, $us);
    if ($n) {
        $c = fread(STDIN, 65536);
        if ($c === false || $c === '') {
            if (feof(STDIN)) {
                $AIA_STDIN_EOF = true;
            }
        } else {
            $AIA_STDIN_BUF .= $c;
        }
    }
}

/** Pop one complete line from the buffer, or null. */
function ap_pop_line()
{
    global $AIA_STDIN_BUF;
    $p = strpos($AIA_STDIN_BUF, "\n");
    if ($p === false) {
        return null;
    }
    $line = substr($AIA_STDIN_BUF, 0, $p);
    $AIA_STDIN_BUF = substr($AIA_STDIN_BUF, $p + 1);
    return rtrim($line, "\r");
}

/** While waiting for the user: notice cancellations and answer pings so the client never thinks we hung. */
function ap_service_while_waiting($reqId)
{
    global $AIA_CANCELLED;
    while (($line = ap_pop_line()) !== null) {
        if ($line === '') {
            continue;
        }
        $m = json_decode($line, true);
        if (!is_array($m) || !isset($m['method'])) {
            continue;
        }
        if ($m['method'] === 'notifications/cancelled' && isset($m['params']['requestId']) && $m['params']['requestId'] === $reqId) {
            return 'cancelled';
        }
        if ($m['method'] === 'ping' && array_key_exists('id', $m)) {
            ap_result($m['id'], new stdClass());
        }
    }
    return '';
}

function ap_write_atomic($path, $data)
{
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $fh = @fopen($tmp, 'xb');
    if (!$fh) {
        return false;
    }
    $ok = fwrite($fh, $data) === strlen($data);
    fclose($fh);
    @chmod($tmp, 0644);
    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function ap_deny($msg)
{
    return array('behavior' => 'deny', 'message' => $msg, 'interrupt' => false);
}

/** Handle one tools/call approve. Returns the decision document. */
function ap_approve($reqId, $args)
{
    global $AIA_RUN, $AIA_PERM, $AIA_STDIN_EOF;
    if (!is_array($args) || !isset($args['tool_name']) || !is_string($args['tool_name'])) {
        return ap_deny('Malformed permission request.');
    }
    $input = isset($args['input']) && is_array($args['input']) ? $args['input'] : array();
    if ($AIA_RUN === '' || !preg_match('/^[a-f0-9]{16}$/', $AIA_RUN) || $AIA_PERM === '' || !is_dir($AIA_PERM)) {
        return ap_deny('No WebGUI is attached to this run, so the action was not approved.');
    }
    $id = bin2hex(random_bytes(6));
    $base = $AIA_PERM . '/' . $AIA_RUN . '.' . $id;
    $req = array(
        'id' => $id,
        'run_id' => $AIA_RUN,
        'tool_name' => $args['tool_name'],
        'input' => $input === array() ? new stdClass() : $input,
        'tool_use_id' => isset($args['tool_use_id']) && is_string($args['tool_use_id']) ? $args['tool_use_id'] : '',
        't' => time(),
    );
    if (!ap_write_atomic($base . '.req.json', json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))) {
        return ap_deny('Could not store the permission request.');
    }
    $deadline = time() + AIA_APPROVE_TIMEOUT;
    $decFile = $base . '.dec.json';
    $out = null;
    while ($out === null) {
        clearstatcache(true, $decFile);
        $st = @lstat($decFile);
        if ($st && ($st['mode'] & 0170000) === 0100000 && $st['size'] < 65536) {
            $d = json_decode((string)@file_get_contents($decFile), true);
            if (is_array($d) && isset($d['decision'])) {
                if ($d['decision'] === 'allow') {
                    // updatedInput is the original input: run exactly what the user approved
                    $out = array('behavior' => 'allow', 'updatedInput' => $input === array() ? new stdClass() : $input);
                } else {
                    $m = isset($d['message']) && is_string($d['message']) && $d['message'] !== '' ? $d['message'] : 'The user denied this action.';
                    $out = ap_deny($m);
                }
                break;
            }
        }
        if (time() > $deadline) {
            $out = ap_deny('No answer within ' . (int)(AIA_APPROVE_TIMEOUT / 60) . ' minutes, so the action was denied.');
            break;
        }
        ap_pump(AIA_APPROVE_POLL_US);
        if (ap_service_while_waiting($reqId) === 'cancelled') {
            $out = ap_deny('The request was cancelled.');
            break;
        }
        if ($AIA_STDIN_EOF) {
            $out = ap_deny('Claude Code went away before the user answered.');
            break;
        }
    }
    @unlink($base . '.req.json');
    @unlink($decFile);
    return $out;
}

function ap_handle($m)
{
    if (!is_array($m) || !isset($m['method']) || !is_string($m['method'])) {
        if (is_array($m) && array_key_exists('id', $m)) {
            ap_error($m['id'], -32600, 'Invalid Request');
        }
        return;
    }
    $hasId = array_key_exists('id', $m);
    $id = $hasId ? $m['id'] : null;
    $params = isset($m['params']) && is_array($m['params']) ? $m['params'] : array();
    switch ($m['method']) {
        case 'initialize':
            $pv = isset($params['protocolVersion']) && is_string($params['protocolVersion']) ? $params['protocolVersion'] : '2025-06-18';
            ap_result($id, array(
                'protocolVersion' => $pv,
                'capabilities' => array('tools' => array('listChanged' => false)),
                'serverInfo' => array('name' => 'aia', 'version' => '1.0.0'),
            ));
            return;
        case 'ping':
            if ($hasId) {
                ap_result($id, new stdClass());
            }
            return;
        case 'tools/list':
            ap_result($id, array('tools' => array(ap_tool_def())));
            return;
        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            if ($name !== 'approve') {
                ap_error($id, -32602, 'Unknown tool: ' . (is_string($name) ? $name : ''));
                return;
            }
            $decision = ap_approve($id, isset($params['arguments']) ? $params['arguments'] : null);
            ap_result($id, ap_tool_result($decision));
            return;
        case 'resources/list':
            ap_result($id, array('resources' => array()));
            return;
        case 'prompts/list':
            ap_result($id, array('prompts' => array()));
            return;
        default:
            if ($hasId) {
                ap_error($id, -32601, 'Method not found');
            }
            // notifications (initialized, cancelled, ...) need no answer
    }
}

// ---- main loop
if (isset($argv[1]) && $argv[1] === '--version') {
    echo "aia-approve 1.0.0\n";
    exit(0);
}
stream_set_blocking(STDIN, false);
while (true) {
    while (($line = ap_pop_line()) !== null) {
        if ($line === '') {
            continue;
        }
        $m = json_decode($line, true);
        if ($m === null) {
            ap_error(null, -32700, 'Parse error');
            continue;
        }
        try {
            ap_handle($m);
        } catch (\Throwable $e) {
            ap_log('error: ' . $e->getMessage());
            if (is_array($m) && array_key_exists('id', $m)) {
                ap_error($m['id'], -32603, 'Internal error');
            }
        }
    }
    if ($AIA_STDIN_EOF) {
        break;
    }
    ap_pump(500000);
}
exit(0);

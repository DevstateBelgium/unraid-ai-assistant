<?php
/* AI Assistant for Unraid (for Claude Code) - chat endpoint.
 * POST /plugins/ai-assistant-claude/include/chat.php  action=<whitelisted>  csrf_token=<token>
 * Actions: chat_send, chat_poll, chat_stop, chat_sessions, chat_history, chat_approve,
 *          chat_archive, chat_delete, chat_rename, chat_set_mode.
 * The run itself lives in scripts/chat-worker.php (detached); this file only starts it and reads its files.
 */

define('AIA_API_NO_RUN', 1);
require_once __DIR__ . '/api.php';   // aia_gate(), aia_post(), aia_fail() and common.php
require_once __DIR__ . '/chatlib.php';

$AIA_CHAT_ACTIONS = array('chat_send', 'chat_poll', 'chat_stop', 'chat_sessions', 'chat_history', 'chat_approve',
    'chat_archive', 'chat_delete', 'chat_rename', 'chat_set_mode');

function aia_chat_php_bin()
{
    foreach (array('/usr/bin/php', '/usr/local/bin/php', '/bin/php') as $p) {
        if (is_executable($p)) {
            return $p;
        }
    }
    return 'php';
}

function aia_chat_env_pass()
{
    $env = array();
    foreach (array('AIA_EMHTTP', 'AIA_FLASH', 'AIA_RAM', 'AIA_VAR_INI', 'AIA_PASSWD', 'AIA_CLAUDE_BIN', 'AIA_CHAT_TEST') as $e) {
        $v = getenv($e);
        if ($v !== false) {
            $env[] = $e . '=' . escapeshellarg($v);
        }
    }
    return implode(' ', $env);
}

/** Session id from the request: strict UUID or a failure. Always lower case (that is how claude names the files). */
function aia_chat_sid_param($key = 'session_id')
{
    $sid = trim(aia_post($key));
    if (!aia_chat_sess_id_ok($sid)) {
        aia_fail('Invalid session id.');
    }
    return strtolower($sid);
}

function aia_chat_json_post($key)
{
    if (!isset($_POST[$key])) {
        return null;
    }
    if (is_array($_POST[$key])) {
        return $_POST[$key];
    }
    if (is_string($_POST[$key]) && $_POST[$key] !== '') {
        $j = json_decode($_POST[$key], true);
        return is_array($j) ? $j : null;
    }
    return null;
}

function aia_chat_action_send()
{
    $cfg = aia_cfg();
    if ($cfg['ENABLED'] !== 'yes') {
        aia_fail('The assistant is not enabled.', 200, array('code' => 'not_ready'));
    }
    if (!aia_chat_claude_installed()) {
        aia_fail('Claude Code is not installed yet.', 200, array('code' => 'not_ready'));
    }
    if (!aia_chat_logged_in()) {
        aia_fail('Not logged in. Log in on the Status tab first.', 200, array('code' => 'not_ready'));
    }
    $text = trim(aia_post('text'));
    if ($text === '') {
        aia_fail('Type a message first.');
    }
    $sid = trim(aia_post('session_id'));
    $new = false;
    if ($sid === '' || $sid === 'null') {
        $sid = aia_chat_uuid();
        $new = true;
    } elseif (!aia_chat_sess_id_ok($sid)) {
        aia_fail('Invalid session id.');
    } else {
        $sid = strtolower($sid);
    }
    $modeIn = aia_post('mode');
    if ($modeIn !== '' && !aia_chat_mode_ok($modeIn)) {
        aia_fail('Invalid permission mode.');
    }
    $source = aia_post('source', 'webgui');
    if (!in_array($source, array('webgui', 'page'), true)) {
        $source = 'webgui';
    }
    $context = aia_chat_json_post('context');
    $image = aia_post('image');
    $built = aia_chat_build_message($text, $context, $image === '' ? null : $image);
    if (is_string($built)) {
        aia_fail($built);
    }
    list($line, $ctxMeta, $hasImage) = $built;

    $workDir = $cfg['WORK_DIR'];
    $runUser = $cfg['RUN_USER'];
    if (!$new) {
        $transcript = aia_chat_projects_dir($workDir) . '/' . $sid . '.jsonl';
        if (!is_file($transcript)) {
            // a session we created a moment ago but claude has not written yet is fine; anything else is unknown
            $idx = aia_chat_index_read();
            if (!isset($idx[$sid])) {
                aia_fail('Unknown session.', 200, array('code' => 'unknown_session'));
            }
            $new = true;
        }
    }

    // the mode of this conversation: the one just chosen, else the stored one, else the default for new conversations
    $idxNow = aia_chat_index_read();
    $mode = $modeIn !== '' ? $modeIn : aia_chat_session_mode($sid, $idxNow, $cfg);

    aia_chat_ensure_dirs($runUser);
    aia_chat_gc();
    $lock = aia_chat_lock();
    $active = aia_chat_active_runs();
    foreach ($active as $m) {
        if (($m['session_id'] ?? '') === $sid) {
            aia_chat_unlock($lock);
            aia_fail('This conversation is still answering. Wait for it or press Stop.', 200, array('code' => 'busy', 'run_id' => $m['run_id']));
        }
    }
    if (count($active) >= AIA_CHAT_MAX_ACTIVE_RUNS) {
        aia_chat_unlock($lock);
        aia_fail('Too many conversations are running at once. Try again in a moment.', 200, array('code' => 'too_many'));
    }
    $run = bin2hex(random_bytes(8));
    $dir = aia_chat_dir();
    $meta = array('run_id' => $run, 'session_id' => $sid, 'new' => $new, 'source' => $source, 'mode' => $mode, 'state' => 'running',
        'pid' => 0, 'pgid' => 0, 'started' => time(), 'user' => $runUser);
    $ok = aia_chat_write($dir . '/' . $run . '.in.json', json_encode(array(
        'line' => $line, 'text' => $text, 'ctx' => $ctxMeta, 'image' => $hasImage,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0600);
    $ok = $ok && aia_chat_meta_save($run, $meta);
    if (!$ok) {
        aia_chat_unlock($lock);
        aia_fail('Could not write the run files.');
    }
    if ($new && !isset($idxNow[$sid])) {
        aia_chat_index_set($sid, $source, $mode);
    } else {
        // changes (and un-archives, a message makes a conversation active again) only touch the file when needed
        $ch = array();
        if (($idxNow[$sid]['mode'] ?? '') !== $mode) {
            $ch['mode'] = $mode;
        }
        if (!empty($idxNow[$sid]['archived'])) {
            $ch['archived'] = null;
        }
        if ($ch) {
            aia_chat_index_update($sid, $ch);
        }
    }
    // detached: the HTTP request returns at once, the worker survives php-fpm and writes <run>.jsonl
    $php = aia_chat_php_bin();
    $worker = AIA_EMHTTP . '/scripts/chat-worker.php';
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (is_executable('/bin/setsid') ? '/bin/setsid ' : '');
    // no "&&" list before the "&": the redirections must belong to the backgrounded command itself, otherwise the
    // subshell keeps exec()'s pipe open and this request would block until the worker ends
    $cmd = 'cd /; ' . aia_chat_env_pass() . ' ' . $setsid . 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' '
        . escapeshellarg($run) . ' >/dev/null 2>&1 </dev/null &';
    exec('/bin/sh -c ' . escapeshellarg($cmd));
    aia_chat_unlock($lock);

    // wait briefly for the worker to announce its pid so a quick stop works
    for ($i = 0; $i < 20; $i++) {
        $m = aia_chat_meta($run);
        if ($m && (int)$m['pid'] > 0) {
            break;
        }
        usleep(100000);
    }
    return array('ok' => true, 'run_id' => $run, 'session_id' => $sid, 'new' => $new, 'mode' => $mode);
}

function aia_chat_action_poll()
{
    $run = aia_post('run_id');
    $after = max(0, (int)aia_post('after', '0'));
    $meta = aia_chat_meta($run);
    if (!$meta) {
        aia_fail('Unknown run.', 200, array('code' => 'unknown_run'));
    }
    if (($meta['state'] ?? '') === 'running' && !aia_chat_pid_alive($meta['pid'] ?? 0) && time() - (int)($meta['started'] ?? 0) > 20) {
        aia_chat_finalize_dead($meta);
        $meta = aia_chat_meta($run);
    }
    $file = aia_chat_dir() . '/' . $run . '.jsonl';
    $items = array();
    $next = $after;
    clearstatcache(true, $file);
    $size = is_file($file) ? (int)filesize($file) : 0;
    if ($size > $after) {
        $fh = @fopen($file, 'rb');
        if ($fh) {
            fseek($fh, $after);
            $buf = (string)fread($fh, AIA_CHAT_POLL_BYTES);
            fclose($fh);
            $nl = strrpos($buf, "\n");
            if ($nl !== false) {
                $buf = substr($buf, 0, $nl + 1);
                $next = $after + strlen($buf);
                foreach (explode("\n", $buf) as $ln) {
                    if ($ln === '' || $ln[0] !== '{') {
                        continue;
                    }
                    $j = json_decode($ln, true);
                    if (is_array($j)) {
                        foreach (aia_chat_normalize($j, false) as $it) {
                            $items[] = $it;
                        }
                    }
                }
            }
        }
    }
    $state = $meta['state'] ?? 'running';
    $running = $state === 'running';
    // the final state is only reported once everything up to the end marker has been delivered
    $drained = $next >= $size;
    return array(
        'ok' => true,
        'items' => $items,
        'next' => $next,
        'state' => ($running || !$drained) ? 'running' : $state,
        'session_id' => $meta['session_id'] ?? '',
        'perms' => $running ? aia_chat_pending_perms($run) : array(),
    );
}

function aia_chat_action_stop()
{
    $run = aia_post('run_id');
    $meta = aia_chat_meta($run);
    if (!$meta) {
        aia_fail('Unknown run.', 200, array('code' => 'unknown_run'));
    }
    if (($meta['state'] ?? '') !== 'running') {
        return array('ok' => true, 'state' => $meta['state']);
    }
    return array('ok' => true, 'state' => aia_chat_stop_run($run));
}

function aia_chat_action_sessions()
{
    aia_chat_trash_purge();
    $with = aia_post('archived') === '1';
    list($rows, $nArch) = aia_chat_list_sessions(200, $with);
    return array('ok' => true, 'sessions' => $rows, 'archived_count' => $nArch, 'default_mode' => aia_chat_default_mode());
}

function aia_chat_action_history()
{
    $sid = aia_chat_sid_param();
    $file = aia_chat_projects_dir() . '/' . $sid . '.jsonl';
    $act = aia_chat_active_for_session($sid);
    $idx = aia_chat_index_read();
    $items = is_file($file) ? aia_chat_history_items($file) : array();
    if (!is_file($file) && !$act && !isset($idx[$sid])) {
        aia_fail('Unknown session.', 200, array('code' => 'unknown_session'));
    }
    $mtime = is_file($file) ? (int)filemtime($file) : 0;
    $source = isset($idx[$sid]['source']) ? $idx[$sid]['source'] : 'app';
    $out = array('ok' => true, 'items' => $items, 'source' => $source, 'run_id' => '', 'next' => 0, 'state' => 'idle',
        'external' => false, 'mode' => aia_chat_session_mode($sid, $idx), 'archived' => !empty($idx[$sid]['archived']),
        'title' => isset($idx[$sid]['title']) ? $idx[$sid]['title'] : '');
    if ($act) {
        $f = aia_chat_dir() . '/' . $act['run_id'] . '.jsonl';
        clearstatcache(true, $f);
        $out['run_id'] = $act['run_id'];
        $out['next'] = is_file($f) ? (int)filesize($f) : 0;
        $out['state'] = 'running';
    } elseif ($source === 'app' || $source === 'system') {
        $out['external'] = aia_chat_external_active($sid, $mtime);
    }
    return $out;
}

/** The conversation must exist (transcript, active run or index entry). Returns its transcript path. */
function aia_chat_known_session($sid)
{
    $file = aia_chat_projects_dir() . '/' . $sid . '.jsonl';
    $idx = aia_chat_index_read();
    if (!is_file($file) && !isset($idx[$sid]) && !aia_chat_active_for_session($sid)) {
        aia_fail('Unknown session.', 200, array('code' => 'unknown_session'));
    }
    return $file;
}

function aia_chat_action_archive()
{
    $sid = aia_chat_sid_param();
    $on = aia_post('archived', '1') !== '0';
    aia_chat_known_session($sid);
    $stopped = '';
    if ($on) {
        // closing a conversation ends its run first
        $stopped = aia_chat_stop_session($sid);
    }
    aia_chat_index_update($sid, array('archived' => $on ? 1 : null));
    return array('ok' => true, 'archived' => $on, 'stopped' => $stopped !== '');
}

function aia_chat_action_delete()
{
    $sid = aia_chat_sid_param();
    $file = aia_chat_known_session($sid);
    $idx = aia_chat_index_read();
    $source = isset($idx[$sid]['source']) ? $idx[$sid]['source'] : 'app';
    // a conversation the remote-control device (the Claude app) is attached to must not vanish underneath it
    if (!aia_chat_active_for_session($sid) && ($source === 'app' || $source === 'system')
        && is_file($file) && aia_chat_external_active($sid, (int)filemtime($file))) {
        aia_fail('This conversation is attached to the Claude app right now. End it there first, then delete it.', 200, array('code' => 'external'));
    }
    aia_chat_stop_session($sid);
    $moved = aia_chat_trash_session($sid);
    if (!$moved && is_file($file)) {
        aia_fail('Could not move the conversation to the trash.');
    }
    aia_chat_index_remove($sid);
    return array('ok' => true, 'trashed' => $moved);
}

function aia_chat_action_rename()
{
    $sid = aia_chat_sid_param();
    aia_chat_known_session($sid);
    $t = aia_chat_title_clean(aia_post('title'));
    aia_chat_index_update($sid, array('title' => $t === '' ? null : $t));
    return array('ok' => true, 'title' => $t);
}

function aia_chat_action_set_mode()
{
    $sid = aia_chat_sid_param();
    $mode = aia_post('mode');
    if (!aia_chat_mode_ok($mode)) {
        aia_fail('Invalid permission mode.');
    }
    aia_chat_known_session($sid);
    aia_chat_index_update($sid, array('mode' => $mode));
    // a running message keeps the mode it started with: the new one applies from the next message
    return array('ok' => true, 'mode' => $mode, 'applies_next' => aia_chat_active_for_session($sid) !== null);
}
function aia_chat_action_approve()
{
    $run = aia_post('run_id');
    $req = aia_post('request_id');
    $decision = aia_post('decision');
    if (!aia_chat_run_id_ok($run) || !aia_chat_req_id_ok($req)) {
        aia_fail('Invalid request.');
    }
    if ($decision !== 'allow' && $decision !== 'deny') {
        aia_fail('Invalid decision.');
    }
    $meta = aia_chat_meta($run);
    if (!$meta || ($meta['state'] ?? '') !== 'running') {
        aia_fail('This run has already finished.', 200, array('code' => 'finished'));
    }
    $reqFile = aia_chat_perm_dir() . '/' . $run . '.' . $req . '.req.json';
    if (aia_chat_read_json($reqFile, 262144) === null) {
        aia_fail('That request is no longer pending.', 200, array('code' => 'gone'));
    }
    $decFile = aia_chat_perm_dir() . '/' . $run . '.' . $req . '.dec.json';
    if (is_file($decFile)) {
        return array('ok' => true, 'already' => true);
    }
    $payload = array('decision' => $decision, 't' => time());
    if ($decision === 'deny') {
        $m = trim(aia_post('message'));
        $payload['message'] = $m !== '' ? aia_chat_clip($m, 500) : 'The user denied this action in the WebGUI.';
    }
    // world-readable: the permission server runs as RUN_USER and reads this file
    if (!aia_chat_write($decFile, json_encode($payload), 0644)) {
        aia_fail('Could not store the decision.');
    }
    return array('ok' => true);
}

function aia_chat_dispatch($action)
{
    switch ($action) {
        case 'chat_send':
            return aia_chat_action_send();
        case 'chat_poll':
            return aia_chat_action_poll();
        case 'chat_stop':
            return aia_chat_action_stop();
        case 'chat_sessions':
            return aia_chat_action_sessions();
        case 'chat_history':
            return aia_chat_action_history();
        case 'chat_approve':
            return aia_chat_action_approve();
        case 'chat_archive':
            return aia_chat_action_archive();
        case 'chat_delete':
            return aia_chat_action_delete();
        case 'chat_rename':
            return aia_chat_action_rename();
        case 'chat_set_mode':
            return aia_chat_action_set_mode();
    }
    aia_fail('Unknown action.', 400);
}

function aia_chat_main()
{
    global $AIA_CHAT_ACTIONS;
    @set_time_limit(0);
    // release the PHP session lock (if any) so polls never queue behind a slow request
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    aia_gate();
    $action = aia_post('action');
    if (!in_array($action, $AIA_CHAT_ACTIONS, true)) {
        aia_fail('Unknown action.', 400);
    }
    aia_json(aia_chat_dispatch($action));
}

if (!defined('AIA_CHAT_NO_RUN')) {
    aia_chat_main();
}

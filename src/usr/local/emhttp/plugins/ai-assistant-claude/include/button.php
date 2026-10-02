<?php
/* AI Assistant for Unraid (for Claude Code) - page button (header icon / floating): gate + head output.
 * Loaded from AIAButton.page (Menu="Buttons") on EVERY WebGUI page, so everything here must be cheap,
 * must never throw and must never print anything unless the button is wanted.
 */

if (!defined('AIA_BUTTON_LOADED')) {
    define('AIA_BUTTON_LOADED', 1);

    /** ENABLED=yes, SHOW_PAGE_BUTTON=yes, Claude installed and logged in (run/auth.json, no process started). */
    function aia_button_enabled()
    {
        try {
            require_once __DIR__ . '/common.php';
            $cfg = aia_cfg();
            if ($cfg['ENABLED'] !== 'yes' || $cfg['SHOW_PAGE_BUTTON'] !== 'yes') {
                return false;
            }
            if (!is_file(AIA_RAM . '/bin/claude')) {
                return false;
            }
            $f = AIA_RAM . '/run/auth.json';
            if (is_file($f)) {
                $j = json_decode((string)@file_get_contents($f, false, null, 0, 65536), true);
                if (is_array($j) && array_key_exists('loggedIn', $j)) {
                    return $j['loggedIn'] === true;
                }
            }
            return is_file(AIA_RAM . '/config/.credentials.json');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Head output: i18n dictionary + the shared chat script (builds the panel and, per BUTTON_STYLE, the floating button). */
    function aia_button_head()
    {
        try {
            require_once __DIR__ . '/i18n.php';
            $base = '/plugins/' . AIA_PLUGIN;
            $t = @filemtime(AIA_EMHTTP . '/js/aia-chat.js');
            $cfgB = aia_cfg();
            $style = $cfgB['BUTTON_STYLE'];
            $dm = in_array($cfgB['CHAT_PERMISSION_MODE'], array('default', 'acceptEdits', 'auto', 'plan'), true) ? $cfgB['CHAT_PERMISSION_MODE'] : 'default';
            if (!in_array($style, array('header', 'floating', 'both'), true)) {
                $style = 'header';
            }
            if ($style === 'floating') {
                // only the floating button: hide the icon Unraid adds to its own button row
                echo "<style>.nav-item.AIAButton{display:none!important}</style>\n";
            }
            echo '<script>window.AIAChat=window.AIAChat||{};window.AIAChat.i18n=' . aia_js_dict()
                . ';window.AIAChat.button=true;window.AIAChat.defaultMode=' . json_encode($dm) . ';window.AIAChat.style=' . json_encode($style)
                . ';window.AIAChat.base=' . json_encode($base) . ";</script>\n";
            echo '<script src="' . $base . '/js/aia-chat.js' . ($t ? '?v=' . $t : '') . '" defer></script>' . "\n";
        } catch (\Throwable $e) {
            // never break the page
        }
    }
}

if (!function_exists('aia_chat_tab_default_mode')) {
    function aia_chat_tab_default_mode()
    {
        require_once __DIR__ . '/common.php';
        $m = aia_cfg()['CHAT_PERMISSION_MODE'];
        return in_array($m, array('default', 'acceptEdits', 'auto', 'plan'), true) ? $m : 'default';
    }
}

if (!function_exists('aia_chat_tab_head')) {
    /** Chat tab: stylesheet + i18n dictionary + the shared chat script (the tab mounts itself on #aia-chat-root). */
    function aia_chat_tab_head()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        require_once __DIR__ . '/i18n.php';
        $base = '/plugins/' . AIA_PLUGIN;
        $v = function ($rel) {
            $t = @filemtime(AIA_EMHTTP . '/' . $rel);
            return $t ? '?v=' . $t : '';
        };
        echo '<link id="aiac-css" type="text/css" rel="stylesheet" href="' . $base . '/css/aia-chat.css' . $v('css/aia-chat.css') . "\">\n";
        $dm = aia_chat_tab_default_mode();
        echo '<script>window.AIAChat=window.AIAChat||{};window.AIAChat.i18n=' . aia_js_dict()
            . ';window.AIAChat.defaultMode=' . json_encode($dm) . ';window.AIAChat.base=' . json_encode($base) . ";</script>\n";
        echo '<script src="' . $base . '/js/aia-chat.js' . $v('js/aia-chat.js') . '"></script>' . "\n";
    }
}

<?php
/* i18n for the plugin. English is the source language.
 *
 * Unraid's own _() only knows strings from /usr/local/emhttp/languages/<locale>/*.txt, which a plugin
 * cannot extend without shipping files there. So: our own locales/<lang>.json (English text -> translation)
 * is consulted first; if it has no entry we fall back to _() when it actually translates, else English.
 */

require_once __DIR__ . '/common.php';

/** Current language code ("nl", "en", ...) derived from the WebGUI locale (e.g. "nl_NL"). */
function aia_lang()
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $loc = '';
    if (isset($GLOBALS['locale']) && is_string($GLOBALS['locale'])) {
        $loc = $GLOBALS['locale'];
    } elseif (isset($GLOBALS['display']['locale'])) {
        $loc = (string)$GLOBALS['display']['locale'];
    } elseif (isset($_SESSION['locale'])) {
        $loc = (string)$_SESSION['locale'];
    }
    $env = getenv('AIA_LOCALE');
    if ($env !== false && $env !== '') {
        $loc = $env;
    }
    $code = strtolower(substr(preg_replace('/[^A-Za-z_]/', '', $loc), 0, 2));
    $lang = $code === '' ? 'en' : $code;
    return $lang;
}

/** Dictionary for the current language (empty for English). */
function aia_dict()
{
    static $dict = null;
    if ($dict !== null) {
        return $dict;
    }
    $dict = array();
    $lang = aia_lang();
    if ($lang !== 'en' && preg_match('/^[a-z]{2}$/', $lang)) {
        $file = AIA_EMHTTP . '/locales/' . $lang . '.json';
        if (is_readable($file)) {
            $j = json_decode((string)file_get_contents($file), true);
            if (is_array($j)) {
                $dict = $j;
            }
        }
    }
    return $dict;
}

/** Translate $s; extra arguments are applied with sprintf (%s). Returns plain (unescaped) text. */
function aia_t($s)
{
    $dict = aia_dict();
    $r = $s;
    if (isset($dict[$s]) && is_string($dict[$s]) && $dict[$s] !== '') {
        $r = $dict[$s];
    } elseif (function_exists('_') && aia_lang() !== 'en') {
        $u = _($s);
        // _() returns the input (with ' -> &apos; and **bold** markup) when untranslated.
        if (is_string($u) && $u !== str_replace("'", '&apos;', trim($s)) && $u !== $s) {
            $r = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
        }
    }
    if (func_num_args() > 1) {
        $args = func_get_args();
        array_shift($args);
        $r = vsprintf($r, $args);
    }
    return $r;
}

/** Translate and HTML-escape. */
function aia_e($s)
{
    $args = func_get_args();
    return aia_h(call_user_func_array('aia_t', $args));
}

/** JSON dictionary for JS (only strings that have a translation). */
function aia_js_dict()
{
    $d = aia_dict();
    return json_encode($d ? $d : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

/** Emit CSS, the i18n dictionary and aia.js once per page load (all tabs share one DOM). */
function aia_page_head()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $base = '/plugins/' . AIA_PLUGIN;
    $v = function ($rel) {
        $t = @filemtime(AIA_EMHTTP . '/' . $rel);
        return $t ? '?v=' . $t : '';
    };
    echo '<link type="text/css" rel="stylesheet" href="' . $base . '/css/aia.css' . $v('css/aia.css') . "\">\n";
    echo '<script>window.AIA_I18N = ' . aia_js_dict() . ";</script>\n";
    echo '<script src="' . $base . '/js/aia.js' . $v('js/aia.js') . "\"></script>\n";
}

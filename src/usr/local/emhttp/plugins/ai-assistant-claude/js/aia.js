/* AI Assistant for Unraid (for Claude Code) - WebGUI behaviour. jQuery + Unraid's swal only. */
(function ($) {
  'use strict';
  if (window.AIA_LOADED) { return; }
  window.AIA_LOADED = true;

  var API = '/plugins/ai-assistant-claude/include/api.php';

  /* ---------- helpers ---------- */
  function T(s) {
    var d = window.AIA_I18N || {};
    var r = (d[s] !== undefined && d[s] !== '') ? d[s] : s;
    var i = 1, a = arguments;
    return r.replace(/%s/g, function () { return i < a.length ? a[i++] : ''; });
  }
  function token() { return (typeof csrf_token !== 'undefined') ? csrf_token : ''; }

  /** POST an action; always resolves with an object (never rejects). */
  function api(action, data, opts) {
    var d = $.extend({}, data || {}, { action: action, csrf_token: token() });
    var req = $.extend({ url: API, type: 'POST', data: d, dataType: 'json', timeout: 0 }, opts || {});
    var dfd = $.Deferred();
    $.ajax(req).done(function (r) {
      dfd.resolve(r && typeof r === 'object' ? r : { ok: false, error: T('Unexpected response.') });
    }).fail(function (xhr, st) {
      var r = null;
      try { r = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
      dfd.resolve(r && typeof r === 'object' ? r : { ok: false, error: T('Request failed (%s).', st || xhr.status) });
    });
    return dfd.promise();
  }

  function msg($el, ok, text) {
    if (!text) { $el.hide().empty(); return; }
    $el.removeClass('aia-ok aia-err').addClass(ok ? 'aia-ok' : 'aia-err').text(text).show();
  }
  function spin(id, on) { $('#' + id).toggle(!!on); }
  function errText(r, fallback) {
    if (r && r.errors) {
      var parts = [];
      $.each(r.errors, function (k, v) { parts.push(k + ': ' + T(v)); });
      return parts.join(' ');
    }
    return r && r.error ? T(String(r.error)) : (fallback || T('Failed.'));
  }
  function confirmDlg(title, text, yes, cb) {
    if (typeof swal === 'function') {
      swal({ title: title, text: text, type: 'warning', showCancelButton: true,
        confirmButtonText: yes, cancelButtonText: T('Cancel') }, function (ok) { if (ok) { setTimeout(cb, 50); } });
    } else if (window.confirm(title + '\n\n' + text)) {
      cb();
    }
  }
  function yn(v) { return v ? T('Yes') : T('No'); }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmtTime(t) {
    var d = (typeof t === 'number' || /^[0-9.]+$/.test(String(t))) ? new Date(Number(t) * 1000) : new Date(t);
    if (isNaN(d.getTime())) { return String(t); }
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  function fmtSize(n) { return n < 1024 ? n + ' B' : (n / 1024).toFixed(1) + ' KB'; }

  /* ---------- Status tab ---------- */
  function initStatus() {
    var $root = $('#aia-status');
    if (!$root.length) { return; }
    var $msg = $('#aia-msg');
    var st = {};
    var discTimer = null;
    var statusTimer = null;

    function say(ok, text) { msg($msg, ok, text); }

    function renderStatus(s) {
      st = s || {};
      var installed = !!st.installed;
      $('#aia-install-state').text(installed ? T('installed (version %s)', st.version || '?') : T('not installed'));
      $('#aia-btn-install').toggle(!installed);
      var logged = !!st.logged_in;
      if (!installed) {
        $('#aia-account-state').text(T('Install Claude first.'));
      } else if (logged) {
        $('#aia-account-state').text(T('Logged in as %s', (st.email || '?') + (st.org ? ' (' + st.org + ')' : '')));
      } else {
        $('#aia-account-state').text(T('Not logged in.'));
      }
      $('#aia-btn-login').toggle(installed && !logged && $('#aia-login-box').is(':hidden'));
      $('#aia-btn-logout').toggle(logged);
      $('#aia-as-enabled').text(yn(st.enabled));
      $('#aia-as-running').text(yn(st.running));
      $('#aia-as-connected').text(yn(st.connected));
      $('#aia-as-device').text(st.device || '-');
      $('#aia-as-unraid').text(st.unraid_version || '-');
      $('#aia-btn-enable').toggle(logged && !st.enabled);
      $('#aia-btn-disable').toggle(!!st.enabled);
      $('#aia-btn-start').toggle(installed && !st.running);
      $('#aia-btn-stop, #aia-btn-restart').toggle(!!st.running);
      $('#aia-btn-update').toggle(installed);
      var ds = st.discovery || 'none';
      $('#aia-disc-state').text(T(ds === 'running' ? 'running' : ds === 'done' ? 'done' : ds === 'failed' ? 'failed' : 'not run yet'));
      $('#aia-btn-disc-again').toggle(installed && logged && ds !== 'running');
      $('#aia-btn-disc-cancel').toggle(ds === 'running');
      spin('aia-spin-disc', ds === 'running');
      if (ds === 'running' && !discTimer) { pollDiscovery(); }
    }

    function refresh() {
      return api('status').done(function (r) { if (r.installed !== undefined || r.ok !== false) { renderStatus(r); } else { say(false, errText(r)); } });
    }

    var STEP_ICON = { scout: 'fa-search', tool: 'fa-terminal', memory: 'fa-database', text: 'fa-comment-o' };
    function renderDiscovery(r) {
      var $ul = $('#aia-disc-steps').empty();
      var omitted = parseInt(r.steps_omitted, 10) || 0;
      $.each(r.steps || [], function (i, s) {
        if (i === 1 && omitted > 0) {
          $('<li>').addClass('aia-disc-omitted').append($('<i>').addClass('fa fa-ellipsis-h')).append(document.createTextNode(' ' + T('%s earlier steps not shown', omitted))).appendTo($ul);
        }
        var icon = STEP_ICON[s.kind] || 'fa-circle-o';
        $('<li>').append($('<i>').addClass('fa ' + icon)).append(document.createTextNode(' ' + (s.text || ''))).appendTo($ul);
      });
      var $sum = $('#aia-disc-summary');
      if (r.state !== 'running' && r.summary) { $sum.text(r.summary).show(); } else { $sum.hide(); }
    }
    function pollDiscovery() {
      if (discTimer) { clearTimeout(discTimer); discTimer = null; }
      api('discovery_status').done(function (r) {
        renderDiscovery(r);
        var running = (r.state === 'running');
        spin('aia-spin-disc', running);
        $('#aia-btn-disc-cancel').toggle(running);
        if (running) {
          $('#aia-disc-state').text(T('running'));
          discTimer = setTimeout(pollDiscovery, 1000);
        } else {
          refresh();
        }
      });
    }

    function startDiscovery(asRoot) {
      spin('aia-spin-disc', true);
      $('#aia-disc-preflight').hide();
      api('discovery_start', { as_root: asRoot ? '1' : '0' }).done(function (r) {
        if (r.ok === false) { spin('aia-spin-disc', false); say(false, errText(r)); return; }
        say(true, '');
        $('#aia-disc-state').text(T('running'));
        $('#aia-btn-disc-cancel').show();
        pollDiscovery();
      });
    }

    function runDiscoveryFlow() {
      spin('aia-spin-disc', true);
      api('preflight').done(function (r) {
        spin('aia-spin-disc', false);
        if (r.ok === false && !r.missing) { say(false, errText(r)); return; }
        var missing = r.missing || [];
        var runUser = r.run_user || 'root';
        if (!missing.length || runUser === 'root') { startDiscovery(false); return; }
        var $b = $('#aia-disc-preflight').empty().show();
        $('<p>').text(T('User %s cannot access:', runUser)).appendTo($b);
        var $ul = $('<ul>').appendTo($b);
        $.each(missing, function (i, m) { $('<li>').text(String(m)).appendTo($ul); });
        confirmDlg(T('Run the discovery once as root?'),
          T('Only the read-only discovery runs as root; the assistant then runs as %s.', runUser),
          T('Run as root'), function () { startDiscovery(true); });
        // "no" path: offer a limited discovery
        $('<p>').append($('<button type="button">').text(T('Run limited discovery as %s', runUser)).on('click', function () { startDiscovery(false); })).appendTo($b);
      });
    }

    function simple(btnId, spinId, action, okText, after) {
      $('#' + btnId).on('click', function () {
        spin(spinId, true);
        api(action).done(function (r) {
          spin(spinId, false);
          if (r.ok === false) { say(false, errText(r)); } else { say(true, okText || ''); }
          if (after) { after(r); }
          refresh();
        });
      });
    }

    simple('aia-btn-install', 'aia-spin-install', 'install', T('Claude installed.'));
    simple('aia-btn-start', 'aia-spin-assistant', 'start', T('Started.'));
    simple('aia-btn-stop', 'aia-spin-assistant', 'stop', T('Stopped.'));
    simple('aia-btn-restart', 'aia-spin-assistant', 'restart', T('Restarted.'));
    simple('aia-btn-update', 'aia-spin-assistant', 'update', T('Update finished.'));
    simple('aia-btn-disable', 'aia-spin-assistant', 'disable', T('Disabled.'));
    $('#aia-btn-logout').on('click', function () {
      confirmDlg(T('Log out?'), T('The assistant will stop working until you log in again.'), T('Log out'), function () {
        spin('aia-spin-login', true);
        api('logout').done(function (r) { spin('aia-spin-login', false); say(r.ok !== false, r.ok === false ? errText(r) : T('Logged out.')); refresh(); });
      });
    });

    $('#aia-btn-enable').on('click', function () {
      spin('aia-spin-assistant', true);
      api('enable').done(function (r) {
        spin('aia-spin-assistant', false);
        if (r.ok === false) { say(false, errText(r)); refresh(); return; }
        say(true, T('Enabled.'));
        refresh();
        if (r.needs_discovery) { runDiscoveryFlow(); }
      });
    });
    $('#aia-btn-disc-again').on('click', runDiscoveryFlow);
    $('#aia-btn-disc-cancel').on('click', function () {
      api('discovery_cancel').done(function () { pollDiscovery(); });
    });

    /* login */
    $('#aia-btn-login').on('click', function () {
      spin('aia-spin-login', true);
      $(this).prop('disabled', true);
      var $b = $(this);
      api('login_start').done(function (r) {
        spin('aia-spin-login', false);
        $b.prop('disabled', false);
        if (r.ok === false || !r.url) { say(false, errText(r)); return; }
        if (!/^https:\/\//.test(r.url)) { say(false, T('Unexpected login URL.')); return; }
        say(true, '');
        $('#aia-login-url').attr('href', r.url);
        $('#aia-login-code').val('');
        $('#aia-login-box').show();
        $b.hide();
      });
    });
    $('#aia-btn-copy').on('click', function () {
      var url = $('#aia-login-url').attr('href') || '';
      function done() { say(true, T('Link copied.')); }
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(done, function () { window.prompt(T('Copy this link:'), url); });
      } else {
        var $t = $('<textarea>').val(url).appendTo('body').select();
        try { document.execCommand('copy'); done(); } catch (e) { window.prompt(T('Copy this link:'), url); }
        $t.remove();
      }
    });
    $('#aia-btn-code').on('click', function () {
      var code = $.trim($('#aia-login-code').val());
      if (!/^[A-Za-z0-9_#.-]{10,400}$/.test(code)) { say(false, T('That does not look like a valid login code.')); return; }
      spin('aia-spin-login', true);
      api('login_code', { code: code }).done(function (r) {
        spin('aia-spin-login', false);
        if (r.ok === false) { say(false, errText(r)); return; }
        $('#aia-login-code').val('');
        $('#aia-login-box').hide();
        say(true, T('Logged in as %s', r.email || ''));
        refresh();
      });
    });
    $('#aia-btn-login-cancel').on('click', function () {
      api('login_cancel').done(function () {
        $('#aia-login-box').hide();
        $('#aia-login-code').val('');
        say(true, '');
        refresh();
      });
    });

    refresh().done(function () {
      if ((st.discovery || 'none') !== 'none') { pollDiscovery(); }
    });
    statusTimer = setInterval(function () {
      if ($root.is(':visible') && !discTimer) { refresh(); }
    }, 10000);
  }

  /* ---------- Settings tab ---------- */
  function initSettings() {
    var $f = $('#aia-settings');
    if (!$f.length) { return; }
    var $msg = $('#aia-set-msg');

    function syncModel($sel) {
      var key = $sel.data('for');
      var $custom = $f.find('.aia-model-custom[data-for="' + key + '"]');
      var $hidden = $f.find('input[name="' + key + '"]');
      var v = $sel.val();
      if (v === 'custom') { $custom.show(); $hidden.val($.trim($custom.val())); }
      else { $custom.hide(); $hidden.val(v); }
    }
    $f.find('.aia-model-sel').on('change', function () { syncModel($(this)); }).each(function () { syncModel($(this)); });
    $f.find('.aia-model-custom').on('input', function () {
      syncModel($f.find('.aia-model-sel[data-for="' + $(this).data('for') + '"]'));
    });

    function warns() {
      $('#aia-root-warn').toggle($('#aia-run-user').val() === 'root');
      $('#aia-workdir-warn').toggle(/^\/mnt(\/|$)/.test($.trim($('#aia-work-dir').val())));
    }
    $('#aia-run-user').on('change', warns);
    $('#aia-work-dir').on('input', warns);
    warns();

    $('#aia-btn-save-settings').on('click', function () {
      var data = {};
      $.each($f.serializeArray(), function (i, kv) { data[kv.name] = kv.value; });
      spin('aia-spin-settings', true);
      api('save_settings', data).done(function (r) {
        spin('aia-spin-settings', false);
        if (r.ok === false) { msg($msg, false, errText(r)); return; }
        msg($msg, true, r.restarted ? T('Saved. The assistant was restarted.') : T('Saved.'));
      });
    });
  }

  /* ---------- Memory tab ---------- */
  function initMemory() {
    var $root = $('#aia-memory');
    if (!$root.length) { return; }
    var $msg = $('#aia-mem-msg'), cur = null, isNew = false;

    function setEditor(name, text, enabled) {
      cur = name;
      $('#aia-mem-name').text(name || T('Select a file'));
      $('#aia-mem-text').val(text || '').prop('disabled', !enabled);
      $('#aia-btn-mem-save').prop('disabled', !enabled);
      $('#aia-btn-mem-delete').prop('disabled', !enabled || isNew);
    }
    function load() {
      api('memory_list').done(function (r) {
        var $tb = $('#aia-mem-list tbody').empty();
        if (r.ok === false) { msg($msg, false, errText(r)); return; }
        if (!(r.files || []).length) {
          $('<tr><td colspan="3">').find('td').text(T('No memory files yet.')).end().appendTo($tb);
        }
        $.each(r.files || [], function (i, f) {
          var $tr = $('<tr class="aia-click">').toggleClass('aia-sel', f.name === cur);
          $('<td>').text(f.name).appendTo($tr);
          $('<td>').text(fmtSize(f.size)).appendTo($tr);
          $('<td>').text(fmtTime(f.mtime)).appendTo($tr);
          $tr.on('click', function () { open(f.name); });
          $tr.appendTo($tb);
        });
      });
    }
    function open(name) {
      api('memory_get', { name: name }).done(function (r) {
        if (r.ok === false) { msg($msg, false, errText(r)); return; }
        msg($msg, true, ''); isNew = false;
        setEditor(name, r.content, true);
        load();
      });
    }
    $('#aia-btn-mem-refresh').on('click', load);
    $('#aia-btn-mem-new').on('click', function () {
      var n = window.prompt(T('File name (letters, digits, . _ - and ending in .md):'), 'notes.md');
      if (!n) { return; }
      if (!/^[A-Za-z0-9._-]+\.md$/.test(n) || n.charAt(0) === '.' || n.indexOf('..') >= 0) { msg($msg, false, T('Invalid file name.')); return; }
      isNew = true; setEditor(n, '', true);
    });
    $('#aia-btn-mem-save').on('click', function () {
      if (!cur) { return; }
      spin('aia-spin-mem', true);
      api('memory_save', { name: cur, content: $('#aia-mem-text').val() }).done(function (r) {
        spin('aia-spin-mem', false);
        if (r.ok === false) { msg($msg, false, errText(r)); return; }
        isNew = false; $('#aia-btn-mem-delete').prop('disabled', false);
        msg($msg, true, T('Saved.')); load();
      });
    });
    $('#aia-btn-mem-delete').on('click', function () {
      var n = cur;
      if (!n) { return; }
      confirmDlg(T('Delete %s?', n), T('This cannot be undone.'), T('Delete'), function () {
        api('memory_delete', { name: n }).done(function (r) {
          if (r.ok === false) { msg($msg, false, errText(r)); return; }
          msg($msg, true, T('Deleted.')); setEditor(null, '', false); load();
        });
      });
    });
    setEditor(null, '', false);
    load();
  }

  /* ---------- Activity tab ---------- */
  function initActivity() {
    var $root = $('#aia-activity');
    if (!$root.length) { return; }
    var $msg = $('#aia-act-msg'), page = 1, pages = 1, timer = null, toolsLoaded = false;

    function load() {
      api('activity', { page: page, q: $('#aia-act-q').val(), tool: $('#aia-act-tool').val() }).done(function (r) {
        if (r.ok === false) { msg($msg, false, errText(r)); return; }
        msg($msg, true, '');
        page = r.page; pages = r.pages;
        var $sel = $('#aia-act-tool');
        if (!toolsLoaded || ($sel.find('option').length - 1) !== (r.tools || []).length) {
          var keep = $sel.val();
          $sel.find('option:gt(0)').remove();
          $.each(r.tools || [], function (i, t) { $('<option>').val(t).text(t).appendTo($sel); });
          $sel.val(keep); toolsLoaded = true;
        }
        var $tb = $('#aia-act-table tbody').empty();
        if (!(r.entries || []).length) { $('<tr><td colspan="4">').find('td').text(T('No activity.')).end().appendTo($tb); }
        $.each(r.entries || [], function (i, e) {
          var $tr = $('<tr>');
          $('<td class="aia-nowrap">').text(fmtTime(e.t)).appendTo($tr);
          $('<td>').text(e.tool).appendTo($tr);
          $('<td class="aia-wrap">').text(e.summary).appendTo($tr);
          $('<td class="aia-wrap">').text(e.cwd).appendTo($tr);
          $tr.appendTo($tb);
        });
        $('#aia-act-page').text(T('Page %s of %s', page, pages));
        $('#aia-act-total').text(T('%s entries', r.total));
        $('#aia-act-prev').prop('disabled', page <= 1);
        $('#aia-act-next').prop('disabled', page >= pages);
      });
    }
    $('#aia-act-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#aia-act-next').on('click', function () { if (page < pages) { page++; load(); } });
    $('#aia-btn-act-refresh').on('click', function () { load(); });
    $('#aia-act-tool').on('change', function () { page = 1; load(); });
    var qt = null;
    $('#aia-act-q').on('input', function () { clearTimeout(qt); qt = setTimeout(function () { page = 1; load(); }, 350); });
    $('#aia-act-auto').on('change', function () {
      clearInterval(timer); timer = null;
      if (this.checked) { timer = setInterval(function () { if ($root.is(':visible')) { load(); } }, 5000); }
    });
    load();
  }

  /* ---------- Advanced tab ---------- */
  function initAdvanced() {
    var $root = $('#aia-advanced');
    if (!$root.length) { return; }
    var $msg = $('#aia-adv-msg');

    api('advanced_get').done(function (r) {
      if (r.ok === false) { msg($msg, false, errText(r)); return; }
      $('#aia-adv-settings').val(r.settings);
      $('#aia-adv-claude').val(r.claude_md);
    });
    $('#aia-btn-adv-save').on('click', function () {
      try { JSON.parse($('#aia-adv-settings').val() || '{}'); }
      catch (e) { msg($msg, false, T('Invalid JSON: %s', e.message)); return; }
      spin('aia-spin-adv', true);
      api('advanced_save', { settings: $('#aia-adv-settings').val(), claude_md: $('#aia-adv-claude').val() }).done(function (r) {
        spin('aia-spin-adv', false);
        msg($msg, r.ok !== false, r.ok === false ? errText(r) : T('Saved and applied.'));
      });
    });

    $('#aia-exp-cred').on('change', function () { $('#aia-exp-warn').toggle(this.checked); });
    $('#aia-export-form').on('submit', function () {
      $(this).find('input[name="csrf_token"]').val(token());
      return true;
    });
    $('#aia-btn-import').on('click', function () {
      var f = $('#aia-imp-file')[0].files[0];
      if (!f) { msg($msg, false, T('Choose a file first.')); return; }
      confirmDlg(T('Import this archive?'), T('Existing settings and memory with the same names are overwritten.'), T('Import'), function () {
        var fd = new FormData();
        fd.append('action', 'import'); fd.append('csrf_token', token()); fd.append('file', f);
        spin('aia-spin-imp', true);
        $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }).done(function (r) {
          spin('aia-spin-imp', false);
          msg($msg, r.ok !== false, r.ok === false ? errText(r) : T('Imported. Reload the page to see the new settings.'));
        }).fail(function (xhr) {
          spin('aia-spin-imp', false);
          var r = null; try { r = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
          msg($msg, false, errText(r, T('Import failed.')));
        });
      });
    });

    $('#aia-wipe-confirm').on('input', function () { $('#aia-btn-wipe').prop('disabled', $(this).val() !== 'DELETE'); });
    $('#aia-btn-wipe').on('click', function () {
      confirmDlg(T('Delete all data?'), T('You will be logged out and all assistant data is removed. This cannot be undone.'), T('Delete everything'), function () {
        spin('aia-spin-wipe', true);
        api('wipe_all', { confirm: $('#aia-wipe-confirm').val() }).done(function (r) {
          spin('aia-spin-wipe', false);
          $('#aia-wipe-confirm').val(''); $('#aia-btn-wipe').prop('disabled', true);
          msg($msg, r.ok !== false, r.ok === false ? errText(r) : T('All data deleted.'));
        });
      });
    });
  }

  $(function () {
    initStatus(); initSettings(); initMemory(); initActivity(); initAdvanced();
  });
})(jQuery);

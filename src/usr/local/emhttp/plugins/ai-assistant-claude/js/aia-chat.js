/* AI Assistant for Unraid (for Claude Code) - shared chat UI.
 * Used by the Chat tab (AIAChat.page) and by the floating page button / side panel (AIAButton.page).
 * One global namespace: window.AIAChat. No jQuery needed; every entry point is wrapped so that an error
 * here can never break a WebGUI page.
 *
 * Inputs set before this script loads (all optional):
 *   AIAChat.i18n    dictionary English -> translation (falls back to window.AIA_I18N)
 *   AIAChat.button  true -> build the side panel on this page (and the floating button, unless style is 'header')
 *   AIAChat.style   header | floating | both: where the opener lives. 'header' is the icon in Unraid's own button
 *                   row (AIAButton.page), which calls the global AIAButton() -> AIAChat.togglePanel()
 *   AIAChat.base    plugin base URL (default /plugins/ai-assistant-claude)
 */
(function () {
  'use strict';
  var NS = window.AIAChat = window.AIAChat || {};
  if (NS.loaded) { return; }
  NS.loaded = true;

  var BASE = NS.base || '/plugins/ai-assistant-claude';
  var CHAT_URL = BASE + '/include/chat.php';
  var TAB_URL = '/Utilities/AIAssistant';
  var CONTEXT_CAP = 30000;
  var SENSITIVE = /pass|token|secret|key|auth|credential/i;

  /* ------------------------------------------------------------------ helpers */
  function T(s) {
    var d = NS.i18n || window.AIA_I18N || {};
    var r = (d[s] !== undefined && d[s] !== '') ? d[s] : s;
    var i = 1, a = arguments;
    return String(r).replace(/%s/g, function () { return i < a.length ? a[i++] : ''; });
  }
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }
  function token() { try { return (typeof csrf_token !== 'undefined') ? csrf_token : ''; } catch (e) { return ''; } }
  function ssGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { if (v === null || v === undefined) { window.sessionStorage.removeItem(k); } else { window.sessionStorage.setItem(k, v); } } catch (e) { /* private mode */ } }
  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* ignore */ } }

  /** POST an action to chat.php. Always resolves with an object, never rejects. */
  function api(action, data) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf_token', token());
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (v === undefined || v === null) { return; }
      body.set(k, (typeof v === 'object') ? JSON.stringify(v) : String(v));
    });
    return fetch(CHAT_URL, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      return r.text().then(function (t) {
        try { var j = JSON.parse(t); return (j && typeof j === 'object') ? j : { ok: false, error: 'Unexpected response.' }; }
        catch (e) { return { ok: false, error: 'Request failed (HTTP %s).', args: [r.status], http: r.status }; }
      });
    }).catch(function () { return { ok: false, error: 'Request failed.', net: true }; });
  }
  function errText(r) {
    if (!r || !r.error) { return T('Failed.'); }
    return T.apply(null, [String(r.error)].concat(r.args || []));
  }

  /* ------------------------------------------------------------------ markdown (small, safe: escape first) */
  function mdInline(s) {
    var codes = [];
    s = s.replace(/`([^`\n]+)`/g, function (_, c) { codes.push('<code>' + c + '</code>'); return '\u0001' + (codes.length - 1) + '\u0001'; });
    s = s.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, function (m, t, u) {
      var raw = u.replace(/&amp;/g, '&');
      if (!/^(https?:\/\/|mailto:|\/|#)/i.test(raw)) { return m; }
      return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + t + '</a>';
    });
    s = s.replace(/(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;:!?])/g, function (m, p, u) {
      return p + '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>';
    });
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
    s = s.replace(/(^|[^*\w])\*([^*\s][^*\n]*)\*(?!\*)/g, '$1<em>$2</em>').replace(/(^|[^_\w])_([^_\s][^_\n]*)_(?![_\w])/g, '$1<em>$2</em>');
    s = s.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');
    return s.replace(/\u0001(\d+)\u0001/g, function (_, i) { return codes[+i]; });
  }
  function mdRender(src) {
    var blocks = [];
    src = String(src).replace(/\r\n?/g, '\n');
    src = src.replace(/```[^\n`]*\n([\s\S]*?)(?:```|$)/g, function (_, code) {
      blocks.push('<pre><code>' + esc(code.replace(/\n$/, '')) + '</code></pre>');
      return '\n\u0002' + (blocks.length - 1) + '\u0002\n';
    });
    var lines = esc(src).split('\n');
    var out = [], i = 0, m, para = [];
    function flushPara() { if (para.length) { out.push('<p>' + mdInline(para.join('<br>')) + '</p>'); para = []; } }
    function isTableSep(l) { return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(l || ''); }
    function cells(l) { return l.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|'); }
    while (i < lines.length) {
      var l = lines[i];
      if ((m = /^\u0002(\d+)\u0002$/.exec(l))) { flushPara(); out.push(blocks[+m[1]]); i++; continue; }
      if (/^\s*$/.test(l)) { flushPara(); i++; continue; }
      if ((m = /^(#{1,4})\s+(.*)$/.exec(l))) { flushPara(); out.push('<h' + m[1].length + '>' + mdInline(m[2]) + '</h' + m[1].length + '>'); i++; continue; }
      if (/^\s*([-*_])\1{2,}\s*$/.test(l)) { flushPara(); out.push('<hr>'); i++; continue; }
      if (l.indexOf('|') >= 0 && isTableSep(lines[i + 1])) {
        flushPara();
        var head = cells(l), rows = [];
        i += 2;
        while (i < lines.length && lines[i].indexOf('|') >= 0 && !/^\s*$/.test(lines[i])) { rows.push(cells(lines[i])); i++; }
        var t = '<table><thead><tr>' + head.map(function (c) { return '<th>' + mdInline(c.trim()) + '</th>'; }).join('') + '</tr></thead><tbody>';
        rows.forEach(function (r) { t += '<tr>' + r.map(function (c) { return '<td>' + mdInline(c.trim()) + '</td>'; }).join('') + '</tr>'; });
        out.push(t + '</tbody></table>');
        continue;
      }
      if (/^\s*&gt;\s?/.test(l)) {
        flushPara();
        var q = [];
        while (i < lines.length && /^\s*&gt;\s?/.test(lines[i])) { q.push(lines[i].replace(/^\s*&gt;\s?/, '')); i++; }
        out.push('<blockquote>' + mdInline(q.join('<br>')) + '</blockquote>');
        continue;
      }
      if (/^\s*([-*+])\s+/.test(l) || /^\s*\d+[.)]\s+/.test(l)) {
        flushPara();
        var ordered = /^\s*\d+[.)]\s+/.test(l), li = [];
        while (i < lines.length && (/^\s*([-*+])\s+/.test(lines[i]) || /^\s*\d+[.)]\s+/.test(lines[i]) || (/^\s{2,}\S/.test(lines[i]) && li.length))) {
          if (/^\s{2,}\S/.test(lines[i]) && !/^\s*([-*+]|\d+[.)])\s+/.test(lines[i])) { li[li.length - 1] += '<br>' + lines[i].trim(); }
          else { li.push(lines[i].replace(/^\s*([-*+]|\d+[.)])\s+/, '')); }
          i++;
        }
        out.push((ordered ? '<ol>' : '<ul>') + li.map(function (x) { return '<li>' + mdInline(x) + '</li>'; }).join('') + (ordered ? '</ol>' : '</ul>'));
        continue;
      }
      para.push(l);
      i++;
    }
    flushPara();
    return out.join('');
  }
  NS.markdown = mdRender;

  /* ------------------------------------------------------------------ screen context */
  function labelOf(e, doc) {
    var t = '', id = e.id, lab;
    try {
      if (id) { lab = doc.querySelector('label[for="' + String(id).replace(/"/g, '\\"') + '"]'); if (lab) { t = lab.textContent; } }
      if (!t && e.closest) { lab = e.closest('label'); if (lab) { t = lab.textContent; } }
      if (!t) { t = e.getAttribute('aria-label') || ''; }
      if (!t && e.closest) {
        var dd = e.closest('dd'), dt = dd && dd.previousElementSibling;
        while (dt && dt.tagName !== 'DT') { dt = dt.previousElementSibling; }
        if (dt) { t = dt.textContent; }
        if (!t) {
          var td = e.closest('td'), p = td && td.previousElementSibling;
          if (p) { t = p.textContent; }
        }
      }
    } catch (x) { /* ignore */ }
    t = (t || '').replace(/\s+/g, ' ').trim();
    return t.length > 80 ? t.slice(0, 80) : t;
  }
  function isSensitive(e, doc) {
    var type = (e.getAttribute('type') || '').toLowerCase();
    if (type === 'password') { return true; }
    var hay = [e.name, e.id, e.getAttribute('autocomplete'), e.getAttribute('placeholder'), e.getAttribute('aria-label'), labelOf(e, doc)].join(' ');
    return SENSITIVE.test(hay);
  }
  function visible(e) {
    if (!(e.offsetWidth || e.offsetHeight || e.getClientRects().length)) { return false; }
    var cs = window.getComputedStyle(e);
    return cs.visibility !== 'hidden' && cs.display !== 'none';
  }
  var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, SVG: 1, CANVAS: 1, IFRAME: 1, TEMPLATE: 1, OPTION: 1, OPTGROUP: 1, HEAD: 1, LINK: 1, META: 1, IMG: 1, VIDEO: 1, AUDIO: 1 };
  var BLOCK_TAGS = { DIV: 1, P: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, UL: 1, OL: 1, LI: 1, TR: 1, TABLE: 1, DL: 1, DT: 1, DD: 1, SECTION: 1, ARTICLE: 1, FORM: 1, FIELDSET: 1, PRE: 1, BLOCKQUOTE: 1, HR: 1, BR: 1, HEADER: 1, FOOTER: 1, NAV: 1, ASIDE: 1, MAIN: 1, LEGEND: 1 };
  var WARN_CLS = /(^|\s)(red-text|orange-text|warn|warning|error|alert|failed|critical|fail)(\s|$)/i;

  function ownUi(e) {
    return e.classList && (e.classList.contains('aiac-fab') || e.classList.contains('aiac-panel') || e.id === 'aia-chat-root');
  }

  function extractText(root) {
    var lines = [], line = '', total = 0, doc = document, truncated = false;
    function flush() {
      var s = line.replace(/[ \t ]+/g, ' ').trim();
      line = '';
      if (s) { lines.push(s); total += s.length + 1; }
    }
    function full() { if (total > CONTEXT_CAP) { truncated = true; return true; } return false; }
    function field(e) {
      var tag = e.tagName, type = (e.getAttribute('type') || 'text').toLowerCase(), v = '';
      if (tag === 'INPUT' && /^(hidden|button|submit|reset|image|file)$/.test(type)) { return; }
      if (!visible(e)) { return; }
      var label = (labelOf(e, doc) || e.name || e.id || type).replace(/\s*:\s*$/, '');
      if (tag === 'INPUT' && (type === 'checkbox' || type === 'radio')) {
        v = e.checked ? '[x]' : '[ ]';
      } else if (tag === 'SELECT') {
        var o = e.options && e.options[e.selectedIndex]; v = o ? o.text : '';
      } else {
        v = e.value || '';
        if (tag === 'TEXTAREA' && v.length > 4000) { v = v.slice(-4000); }
      }
      if (isSensitive(e, doc) && v !== '' && v !== '[ ]') { v = '***'; }
      // the label text was already emitted as its own line (dt / th / label): do not repeat it
      var pend = line.replace(/\s+/g, ' ').trim().replace(/\s*:\s*$/, '');
      if (pend === label) { line = ''; }
      else {
        flush();
        var lastL = lines.length ? lines[lines.length - 1].replace(/\s*:\s*$/, '') : '';
        if (lastL === label) { total -= lines.pop().length + 1; }
      }
      line = label + ': ' + String(v).replace(/\s*\n\s*/g, ' / ');
      if (tag === 'TEXTAREA' && String(e.value || '').indexOf('\n') >= 0 && !isSensitive(e, doc)) {
        line = label + ':\n' + String(v).slice(0, 4000);
      }
      flush();
    }
    function cellText(td) {
      var save = { l: lines, ln: line, t: total };
      lines = []; line = '';
      walk(td);
      flush();
      var s = lines.join(' ');
      if (s && td.className && typeof td.className === 'string' && WARN_CLS.test(td.className)) { s = '[!] ' + s; }
      lines = save.l; line = save.ln; total = save.t;
      return s;
    }
    function table(t) {
      flush();
      var rows = t.rows || [];
      for (var r = 0; r < rows.length && !full(); r++) {
        if (!visible(rows[r])) { continue; }
        var cs = [], any = false;
        for (var c = 0; c < rows[r].cells.length; c++) {
          var cell = rows[r].cells[c];
          if (!visible(cell)) { continue; }
          var s = cellText(cell);
          if (s) { any = true; }
          cs.push(s);
        }
        if (any) { lines.push(cs.join('\t')); total += cs.join('\t').length + 1; }
      }
    }
    function walk(node) {
      for (var n = node.firstChild; n; n = n.nextSibling) {
        if (full()) { return; }
        if (n.nodeType === 3) {
          var tx = n.nodeValue.replace(/\s+/g, ' ');
          if (tx.trim()) { line += tx; }
        } else if (n.nodeType === 1) {
          var tag = n.tagName;
          if (SKIP_TAGS[tag] || ownUi(n) || n.getAttribute('aria-hidden') === 'true' && !n.textContent.trim()) { continue; }
          if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') { field(n); continue; }
          if (!visible(n)) { continue; }
          if (tag === 'TABLE') { table(n); continue; }
          if (tag === 'BUTTON') { var bt = n.textContent.replace(/\s+/g, ' ').trim(); if (bt) { line += ' [' + bt + '] '; } continue; }
          var block = !!BLOCK_TAGS[tag];
          if (block) { flush(); }
          if (n.className && typeof n.className === 'string' && WARN_CLS.test(n.className) && n.textContent.trim().length < 400) { line += '[!] '; }
          walk(n);
          if (block) { flush(); }
        }
      }
    }
    walk(root);
    flush();
    var s = lines.join('\n').replace(/\n{3,}/g, '\n\n');
    if (s.length > CONTEXT_CAP) { s = s.slice(0, CONTEXT_CAP); truncated = true; }
    // the cap is on bytes, not characters
    var bytes = function (x) { try { return new Blob([x]).size; } catch (e) { return x.length; } };
    while (bytes(s) > CONTEXT_CAP + 2000) { s = s.slice(0, Math.floor(s.length * 0.9)); truncated = true; }
    return truncated ? s + '\n[... truncated]' : s;
  }

  function activeTabName() {
    try {
      var b = document.querySelector('nav.tabs [role=tab][aria-selected=true]');
      if (b) { return b.textContent.replace(/\s+/g, ' ').trim(); }
      var r = document.querySelector('.tabs input.tab:checked, .tabs input[type=radio]:checked');
      if (r) {
        var lab = document.querySelector('label[for="' + r.id + '"]');
        if (lab) { return lab.textContent.replace(/\s+/g, ' ').trim(); }
      }
    } catch (e) { /* ignore */ }
    return '';
  }

  /** What the page looks like to the user. A remembered selection wins over the whole page. */
  NS.collectContext = function (selectionText) {
    var ctx = { page: location.pathname, title: document.title || '', tab: activeTabName(), text: '', selection: false };
    try {
      if (selectionText) {
        ctx.text = selectionText.slice(0, CONTEXT_CAP);
        ctx.selection = true;
      } else {
        var root = document.getElementById('displaybox') || document.querySelector('div.content') || document.body;
        ctx.text = extractText(root);
      }
    } catch (e) {
      ctx.text = '';
    }
    return ctx;
  };

  /* ------------------------------------------------------------------ screenshot (html2canvas-pro, lazy) */
  // html2canvas-pro (not html2canvas 1.4.1): Unraid 7's component CSS computes to oklch()/color-mix(), which 1.4.1 rejects.
  function loadH2C() {
    function pick() {
      var g = window.html2canvas;
      return typeof g === 'function' ? g : (g && typeof g.default === 'function' ? g.default : null);
    }
    if (pick()) { return Promise.resolve(pick()); }
    if (NS._h2c) { return NS._h2c; }
    NS._h2c = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      var hadDefine = Object.prototype.hasOwnProperty.call(window, 'define'), oldDefine = window.define;
      function restore() { if (hadDefine) { window.define = oldDefine; } else { try { delete window.define; } catch (e) { window.define = undefined; } } }
      try { window.define = undefined; } catch (e) { /* ignore */ }
      s.src = BASE + '/js/vendor/html2canvas-pro.min.js?v=2.5.0';
      s.onload = function () { restore(); var f = pick(); f ? resolve(f) : reject(new Error('html2canvas-pro missing')); };
      s.onerror = function () { restore(); NS._h2c = null; reject(new Error('html2canvas-pro failed to load')); };
      document.head.appendChild(s);
    });
    return NS._h2c;
  }
  function blankSecrets(doc) {
    var els = doc.querySelectorAll('input, textarea, select');
    for (var i = 0; i < els.length; i++) {
      var e = els[i];
      try {
        if (isSensitive(e, doc)) {
          if (e.tagName === 'TEXTAREA') { e.value = ''; e.textContent = ''; }
          else if (e.tagName === 'SELECT') { e.selectedIndex = -1; }
          else { e.setAttribute('value', ''); e.value = ''; }
        }
      } catch (x) { /* ignore */ }
    }
  }
  /** Fallback only: rewrite colour values the renderer cannot parse (oklch/lab/color()/color-mix) to rgb() in the clone. */
  var COLOR_PROPS = ['color', 'background-color', 'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
    'outline-color', 'text-decoration-color', 'caret-color', 'column-rule-color', 'fill', 'stroke'];
  var BAD_COLOR = /(?:oklch|oklab|lab|lch|color-mix|color)\(/i;
  function stripUnsupportedColors(doc) {
    var cv = document.createElement('canvas'); cv.width = cv.height = 1;
    var cx = cv.getContext('2d');
    function toRgb(v) {
      try {
        cx.clearRect(0, 0, 1, 1); cx.fillStyle = '#000'; cx.fillStyle = v; cx.fillRect(0, 0, 1, 1);
        var d = cx.getImageData(0, 0, 1, 1).data;
        return 'rgba(' + d[0] + ',' + d[1] + ',' + d[2] + ',' + (Math.round(d[3] / 255 * 100) / 100) + ')';
      } catch (e) { return 'transparent'; }
    }
    var win = doc.defaultView || window;
    var all = doc.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      var e = all[i], cs;
      try { cs = win.getComputedStyle(e); } catch (x) { continue; }
      for (var j = 0; j < COLOR_PROPS.length; j++) {
        var v = cs.getPropertyValue(COLOR_PROPS[j]);
        if (v && BAD_COLOR.test(v)) { try { e.style.setProperty(COLOR_PROPS[j], toRgb(v), 'important'); } catch (x) { /* ignore */ } }
      }
      var bi = cs.getPropertyValue('background-image'), sh = cs.getPropertyValue('box-shadow'), ts = cs.getPropertyValue('text-shadow');
      if (bi && BAD_COLOR.test(bi)) { e.style.setProperty('background-image', 'none', 'important'); }
      if (sh && BAD_COLOR.test(sh)) { e.style.setProperty('box-shadow', 'none', 'important'); }
      if (ts && BAD_COLOR.test(ts)) { e.style.setProperty('text-shadow', 'none', 'important'); }
    }
  }
  function errMsg(e) { return String((e && e.message) || e || 'unknown error').replace(/\s+/g, ' ').slice(0, 160); }
  /** JPEG data URL of the visible viewport (max 1600 px wide, quality 0.7). Rejects with the real reason. */
  NS.screenshot = function () {
    return loadH2C().then(function (h2c) {
      var w = window.innerWidth, h = window.innerHeight;
      var bg = '#ffffff';
      try { bg = window.getComputedStyle(document.body).backgroundColor || bg; if (/rgba\(.*,\s*0\)|transparent/.test(bg)) { bg = '#ffffff'; } } catch (e) { /* ignore */ }
      function run(strip) {
        var job = h2c(document.body, {
          x: window.pageXOffset, y: window.pageYOffset, width: w, height: h, windowWidth: document.documentElement.clientWidth, windowHeight: h,
          scale: Math.min(1, 1600 / w), useCORS: true, allowTaint: false, logging: false, backgroundColor: bg, imageTimeout: 4000,
          ignoreElements: function (e) { return ownUi(e); },
          onclone: function (doc) { blankSecrets(doc); if (strip) { stripUnsupportedColors(doc); } }
        });
        var timer = null;
        var timeout = new Promise(function (_, rej) { timer = setTimeout(function () { rej(new Error('screenshot timeout')); }, 20000); });
        return Promise.race([job, timeout]).then(function (c) { clearTimeout(timer); return c; }, function (e) { clearTimeout(timer); throw e; });
      }
      return run(false).catch(function (e1) {
        try { console.warn('AIA screenshot failed, retrying with colour fallback:', errMsg(e1)); } catch (x) { /* ignore */ }
        return run(true).catch(function (e2) { throw new Error(errMsg(e2)); });
      });
    }).then(function (canvas) {
      var q = [0.7, 0.55, 0.4], url = '';
      for (var i = 0; i < q.length; i++) {
        url = canvas.toDataURL('image/jpeg', q[i]);
        if (url.length < 2.6 * 1024 * 1024) { break; }
      }
      return url.length < 2.9 * 1024 * 1024 ? url : null;
    });
  };

  /* ------------------------------------------------------------------ the chat component */
  var SRC_LABEL = { app: 'App', webgui: 'WebGUI', page: 'Page', system: 'System' };
  // The four permission modes on offer (bypassPermissions and dontAsk are deliberately absent). Strings go through T().
  var MODES = [
    { id: 'default', label: 'Manual', hint: 'Read-only commands run; everything else asks you with an Allow/Deny card.' },
    { id: 'acceptEdits', label: 'Accept edits', hint: 'File edits are approved automatically; other commands still ask you.' },
    { id: 'auto', label: 'Auto', hint: 'A classifier approves routine actions and blocks risky ones; if it keeps blocking, you are asked.' },
    { id: 'plan', label: 'Plan', hint: 'Read-only: Claude researches and proposes a plan, nothing is changed.' }
  ];
  var MODE_NOTE = 'The blocked-command list (destructive patterns) stays active in every mode. A mode change applies from the next message.';
  function modeInfo(id) { for (var i = 0; i < MODES.length; i++) { if (MODES[i].id === id) { return MODES[i]; } } return MODES[0]; }
  function relTime(sec) {
    var d = Math.max(0, Math.floor(Date.now() / 1000) - sec);
    if (d < 60) { return T('just now'); }
    if (d < 3600) { return T('%s min ago', Math.floor(d / 60)); }
    if (d < 86400) { return T('%s h ago', Math.floor(d / 3600)); }
    if (d < 7 * 86400) { return T('%s d ago', Math.floor(d / 86400)); }
    try { return new Date(sec * 1000).toLocaleDateString(); } catch (e) { return ''; }
  }

  function Chat(root, opts) {
    opts = opts || {};
    var mode = opts.mode === 'panel' ? 'panel' : 'tab';
    var source = mode === 'panel' ? 'page' : 'webgui';
    var storeKey = mode === 'panel' ? 'aia.chat.session' : 'aia.chat.tabsession';
    var st = { sid: null, runId: '', next: 0, running: false, timer: null, fails: 0, skipUser: false, tools: {}, sessions: [],
      permIds: '', pinned: true, destroyed: false, selection: '', shot: lsGet('aia.chat.shot') !== '0', lastPollItems: 0,
      defaultMode: MODES.some(function (m) { return m.id === NS.defaultMode; }) ? NS.defaultMode : 'default', mode: null,
      view: 'chat', showArchived: lsGet('aia.chat.archived') === '1', archivedCount: 0, confirmDel: '', editing: '', cur: null };
    st.mode = st.defaultMode;
    var ui = {};

    /* ---- DOM */
    root.innerHTML = '';
    var box = el('div', 'aiac ' + (mode === 'tab' ? 'aiac-tab' : 'aiac-panelbox'));
    root.appendChild(box);
    var main = el('div', 'aiac-main');
    // conversations list: the same component in the Chat tab (left column) and in the side panel (a view of its own)
    var side = el('div', 'aiac-side');
    if (mode === 'panel') {
      var ph = el('div', 'aiac-head');
      ui.btnBack = el('button', 'aiac-iconbtn'); ui.btnBack.type = 'button'; ui.btnBack.title = T('Back to the conversation');
      ui.btnBack.appendChild(el('i', 'fa fa-arrow-left'));
      ph.appendChild(ui.btnBack);
      ph.appendChild(el('div', 'aiac-head-t', T('Conversations')));
      ui.btnClose2 = el('button', 'aiac-iconbtn'); ui.btnClose2.type = 'button'; ui.btnClose2.title = T('Close');
      ui.btnClose2.appendChild(el('i', 'fa fa-times'));
      ph.appendChild(ui.btnClose2);
      side.appendChild(ph);
    }
    var sh = el('div', 'aiac-side-head');
    ui.btnNewSide = el('button', '', T('New chat'));
    ui.btnNewSide.type = 'button';
    sh.appendChild(ui.btnNewSide);
    var alab = el('label', 'aiac-archtoggle');
    ui.arch = el('input'); ui.arch.type = 'checkbox'; ui.arch.checked = st.showArchived;
    ui.archTxt = document.createTextNode(T('Show archived'));
    alab.appendChild(ui.arch); alab.appendChild(ui.archTxt);
    sh.appendChild(alab);
    ui.listmsg = el('div', 'aiac-warn'); ui.listmsg.style.display = 'none';
    ui.list = el('div', 'aiac-list');
    side.appendChild(sh); side.appendChild(ui.listmsg); side.appendChild(ui.list);
    box.appendChild(side);
    box.appendChild(main);
    var head = el('div', 'aiac-head');
    ui.title = el('div', 'aiac-head-t', T('New conversation'));
    ui.hmode = el('span', 'aiac-modebadge');
    head.appendChild(ui.title);
    head.appendChild(ui.hmode);
    function hbtn(txt, tip, fn, icon) {
      var b = el('button', 'aiac-iconbtn');
      b.type = 'button'; b.title = tip;
      if (icon) { var i = el('i', 'fa ' + icon); b.appendChild(i); } else { b.textContent = txt; }
      b.addEventListener('click', fn);
      head.appendChild(b);
      return b;
    }
    if (mode === 'panel') {
      ui.btnList = hbtn('', T('Conversations'), function () { api2.showView('list'); }, 'fa-list');
      ui.btnNew = hbtn('', T('New conversation'), function () { api2.newChat(); }, 'fa-plus');
      ui.btnTab = hbtn('', T('Open in Chat tab'), function () { api2.openInTab(); }, 'fa-external-link');
      ui.btnClose = hbtn('', T('Close'), function () { if (opts.onClose) { opts.onClose(); } }, 'fa-times');
    }
    main.appendChild(head);
    ui.warn = el('div', 'aiac-warn'); ui.warn.style.display = 'none';
    main.appendChild(ui.warn);
    ui.msgs = el('div', 'aiac-msgs');
    main.appendChild(ui.msgs);
    ui.perms = el('div', 'aiac-perms');
    ui.busy = el('div', 'aiac-busy'); ui.busy.style.display = 'none';
    ui.busy.innerHTML = '<i class="fa fa-circle-o-notch fa-spin"></i> ' + esc(T('Working')) + '<span class="aiac-dots"></span>';
    var inp = el('div', 'aiac-input');
    ui.chips = el('div', 'aiac-chips'); ui.chips.style.display = 'none';
    ui.text = el('textarea'); ui.text.rows = 2; ui.text.placeholder = mode === 'panel' ? T('Ask about this page...') : T('Ask the assistant...');
    ui.text.setAttribute('autocomplete', 'off');
    var row = el('div', 'aiac-input-row');
    if (mode === 'panel') {
      var lab = el('label'); ui.shot = el('input'); ui.shot.type = 'checkbox'; ui.shot.checked = st.shot;
      lab.appendChild(ui.shot); lab.appendChild(document.createTextNode(T('Include screenshot')));
      row.appendChild(lab);
      ui.shot.addEventListener('change', function () { st.shot = ui.shot.checked; lsSet('aia.chat.shot', st.shot ? '1' : '0'); });
    }
    ui.mode = el('select', 'aiac-mode');
    ui.mode.setAttribute('aria-label', T('Permission mode'));
    MODES.forEach(function (m) {
      var o = el('option', '', T(m.label)); o.value = m.id; o.title = T(m.hint);
      ui.mode.appendChild(o);
    });
    row.appendChild(ui.mode);
    row.appendChild(el('span', 'aiac-grow'));
    ui.btnStop = el('button', '', T('Stop')); ui.btnStop.type = 'button'; ui.btnStop.style.display = 'none';
    ui.btnSend = el('button', '', T('Send')); ui.btnSend.type = 'button';
    row.appendChild(ui.btnStop); row.appendChild(ui.btnSend);
    ui.modehint = el('div', 'aiac-modehint');
    inp.appendChild(ui.chips); inp.appendChild(ui.text); inp.appendChild(row); inp.appendChild(ui.modehint);
    main.appendChild(inp);

    /* ---- rendering */
    function atBottom() { var m = ui.msgs; return m.scrollHeight - m.scrollTop - m.clientHeight < 60; }
    function scrollDown(force) { if (force || st.pinned) { ui.msgs.scrollTop = ui.msgs.scrollHeight; } }
    ui.msgs.addEventListener('scroll', function () { st.pinned = atBottom(); });

    function clearView() {
      ui.msgs.innerHTML = ''; ui.perms.innerHTML = ''; st.tools = {}; st.permIds = '';
      ui.msgs.appendChild(ui.perms); ui.msgs.appendChild(ui.busy);
    }
    function showEmpty() {
      if (!ui.msgs.querySelector('.aiac-m, .aiac-tool, .aiac-note')) {
        if (!ui.empty) { ui.empty = el('div', 'aiac-empty', mode === 'panel' ? T('Ask me anything about this page or your server.') : T('Start a new conversation or pick one from the list.')); }
        if (!ui.empty.parentNode) { ui.msgs.insertBefore(ui.empty, ui.perms); }
      } else if (ui.empty && ui.empty.parentNode) { ui.empty.parentNode.removeChild(ui.empty); }
    }
    function place(node) { ui.msgs.insertBefore(node, ui.perms); }

    function toolEl(it) {
      var d = el('details', 'aiac-tool aiac-run');
      d.setAttribute('data-id', it.id);
      var sm = el('summary');
      sm.appendChild(el('span', 'aiac-tool-n', it.name));
      sm.appendChild(el('span', 'aiac-tool-s', it.summary || ''));
      d.appendChild(sm);
      var body = el('div', 'aiac-tool-body');
      body.appendChild(el('div', 'aiac-sub'));
      var pre = el('pre'); pre.style.display = 'none';
      body.appendChild(pre);
      d.appendChild(body);
      d._pre = pre; d._sub = body.firstChild;
      return d;
    }

    function addItem(it) {
      if (!it || !it.k) { return; }
      if (ui.empty && ui.empty.parentNode) { ui.empty.parentNode.removeChild(ui.empty); }
      if (it.k === 'user') {
        if (st.skipUser) { st.skipUser = false; return; }
        var u = el('div', 'aiac-m aiac-m-user');
        u.appendChild(document.createTextNode(it.text || ''));
        if (it.ctx || it.image) {
          var chips = el('div');
          if (it.ctx) { chips.appendChild(el('span', 'aiac-chip', T('page context: %s', it.ctx.title || it.ctx.page || ''))); chips.appendChild(document.createTextNode(' ')); }
          if (it.image) { chips.appendChild(el('span', 'aiac-chip', T('screenshot attached'))); }
          u.appendChild(chips);
        }
        place(u);
      } else if (it.k === 'text') {
        var a = el('div', 'aiac-m aiac-m-asst aiac-md');
        a.innerHTML = mdRender(it.text || '');
        var p = it.parent && st.tools[it.parent];
        if (p) { a.className = 'aiac-m aiac-m-asst aiac-md'; p._sub.appendChild(a); } else { place(a); }
      } else if (it.k === 'tool') {
        var t = toolEl(it);
        st.tools[it.id] = t;
        var pt = it.parent && st.tools[it.parent];
        if (pt) { pt._sub.appendChild(t); } else { place(t); }
      } else if (it.k === 'result') {
        var tool = st.tools[it.id];
        if (tool) {
          tool.className = 'aiac-tool' + (it.error ? ' aiac-bad' : '');
          if (it.text) { tool._pre.textContent = it.text; tool._pre.style.display = ''; }
        }
      } else if (it.k === 'note') {
        place(el('div', 'aiac-note' + (it.level === 'error' ? ' aiac-err' : ''), it.text ? T(it.text) : ''));
      }
    }
    function addItems(items) {
      var was = atBottom() || st.pinned;
      for (var i = 0; i < items.length; i++) { try { addItem(items[i]); } catch (e) { /* skip a bad item */ } }
      if (was) { scrollDown(true); }
    }

    function renderPerms(list) {
      if (opts.onPerm) { opts.onPerm(list.length); }
      var ids = list.map(function (p) { return p.id; }).join(',');
      if (ids === st.permIds) { return; }
      st.permIds = ids;
      ui.perms.innerHTML = '';
      list.forEach(function (p) {
        var c = el('div', 'aiac-perm');
        c.appendChild(el('div', 'aiac-perm-t', T('Permission needed: %s', p.tool)));
        var pre = el('pre');
        var full = '';
        try { full = (p.input && Object.keys(p.input).length) ? JSON.stringify(p.input, null, 2) : ''; } catch (e) { full = ''; }
        pre.textContent = p.summary && (p.tool === 'Bash' || !full) ? p.summary : (full || p.summary || '');
        c.appendChild(pre);
        var ok = el('button', 'aiac-btn-allow', T('Allow')), no = el('button', 'aiac-btn-deny', T('Deny'));
        ok.type = no.type = 'button';
        function decide(d) {
          ok.disabled = no.disabled = true;
          api('chat_approve', { run_id: st.runId, request_id: p.id, decision: d }).then(function (r) {
            if (r && r.ok === false && r.code !== 'gone' && r.code !== 'finished') { ok.disabled = no.disabled = false; flash(errText(r), true); }
            else { c.parentNode && c.parentNode.removeChild(c); }
          });
        }
        ok.addEventListener('click', function () { decide('allow'); });
        no.addEventListener('click', function () { decide('deny'); });
        c.appendChild(ok); c.appendChild(no);
        ui.perms.appendChild(c);
      });
      if (list.length) { scrollDown(true); if (opts.onAttention) { opts.onAttention('perm'); } }
    }

    function flash(text, isErr) {
      ui.warn.className = 'aiac-warn' + (isErr ? ' aiac-err' : '');
      ui.warn.textContent = text || '';
      ui.warn.style.display = text ? '' : 'none';
    }
    function setRunning(on) {
      st.running = on;
      if (opts.onRunning) { opts.onRunning(on); }
      ui.busy.style.display = on ? '' : 'none';
      ui.btnStop.style.display = on ? '' : 'none';
      ui.btnSend.disabled = on;
      if (on) { scrollDown(); }
    }
    function setTitle(t) { ui.title.textContent = t || T('New conversation'); }
    function paintMode() {
      var m = modeInfo(st.mode);
      ui.mode.value = m.id;
      ui.mode.title = T(m.hint) + ' ' + T(MODE_NOTE);
      ui.hmode.textContent = T(m.label);
      ui.hmode.className = 'aiac-modebadge aiac-mode-' + m.id;
      ui.hmode.title = ui.mode.title;
      ui.modehint.textContent = T(m.hint);
      ui.modehint.title = T(MODE_NOTE);
    }
    function setMode(id) { st.mode = modeInfo(id).id; paintMode(); }
    function persist() { ssSet(storeKey, st.sid); }

    /* ---- polling */
    function stopTimer() { if (st.timer) { clearTimeout(st.timer); st.timer = null; } }
    function schedule(ms) { stopTimer(); if (!st.destroyed) { st.timer = setTimeout(poll, ms); } }
    function finish() {
      stopTimer();
      st.runId = ''; st.permIds = ''; ui.perms.innerHTML = '';
      setRunning(false);
      showEmpty();
      if (mode === 'tab' || st.view === 'list') { api2.refreshSessions(); }
      if (opts.onAttention) { opts.onAttention('done'); }
    }
    function poll() {
      st.timer = null;
      if (!st.runId || st.destroyed) { return; }
      var runId = st.runId;
      api('chat_poll', { run_id: runId, after: st.next }).then(function (r) {
        if (runId !== st.runId) { return; }
        if (!r.ok) {
          if (r.code === 'unknown_run') { finish(); return; }
          if (++st.fails > 10) { addItem({ k: 'note', level: 'error', text: errText(r) }); finish(); return; }
          schedule(Math.min(8000, 800 * st.fails));
          return;
        }
        st.fails = 0;
        st.next = r.next;
        if (r.items && r.items.length) { addItems(r.items); }
        renderPerms(r.perms || []);
        if (r.state === 'running') {
          schedule(document.hidden ? 3000 : (r.items && r.items.length ? 350 : 800));
        } else {
          finish();
        }
      });
    }
    function attach(runId, next) {
      st.runId = runId; st.next = next || 0; st.fails = 0;
      setRunning(true);
      schedule(300);
    }

    /* ---- actions */
    var api2 = {};
    api2.refreshSessions = function () {
      return api('chat_sessions', st.showArchived ? { archived: 1 } : {}).then(function (r) {
        if (!r.ok || st.destroyed) { return; }
        st.sessions = r.sessions || [];
        st.archivedCount = r.archived_count || 0;
        if (r.default_mode && MODES.some(function (m) { return m.id === r.default_mode; })) {
          st.defaultMode = r.default_mode;
          if (!st.sid && !st.modeTouched) { setMode(st.defaultMode); }
        }
        st.cur = null;
        st.sessions.forEach(function (x) { if (x.id === st.sid) { st.cur = x; } });
        renderSessions();
      });
    };
    function listFlash(text, isErr) {
      ui.listmsg.className = 'aiac-warn' + (isErr ? ' aiac-err' : '');
      ui.listmsg.textContent = text || '';
      ui.listmsg.style.display = text ? '' : 'none';
      if (st.listTimer) { clearTimeout(st.listTimer); }
      if (text) { st.listTimer = setTimeout(function () { ui.listmsg.style.display = 'none'; }, 7000); }
    }
    function iconBtn(icon, tip, fn, cls) {
      var b = el('button', 'aiac-act' + (cls ? ' ' + cls : ''));
      b.type = 'button'; b.title = tip; b.setAttribute('aria-label', tip);
      b.appendChild(el('i', 'fa ' + icon));
      b.addEventListener('click', function (e) { e.stopPropagation(); fn(e); });
      return b;
    }
    function sessRow(s) {
      var d = el('div', 'aiac-sess' + (s.id === st.sid ? ' aiac-cur' : '') + (s.archived ? ' aiac-archived' : ''));
      d.setAttribute('data-sid', s.id);
      var top = el('div', 'aiac-sess-top');
      if (s.busy || s.external) {
        var dot = el('span', 'aiac-dot' + (s.busy ? ' aiac-dot-busy' : ''));
        dot.title = s.busy ? T('Working') : T('Active in the Claude app');
        top.appendChild(dot);
      }
      if (st.editing === s.id) {
        var inp = el('input', 'aiac-rename'); inp.type = 'text'; inp.maxLength = 120; inp.value = s.title || '';
        var done = false;
        var commit = function (save) {
          if (done) { return; }
          done = true; st.editing = '';
          var v = inp.value.replace(/\s+/g, ' ').trim();
          if (!save || v === (s.title || '')) { renderSessions(); return; }
          api('chat_rename', { session_id: s.id, title: v }).then(function (r) {
            if (!r.ok) { listFlash(errText(r), true); } else if (s.id === st.sid && r.title) { setTitle(r.title); }
            api2.refreshSessions();
          });
        };
        inp.addEventListener('click', function (e) { e.stopPropagation(); });
        inp.addEventListener('keydown', function (e) {
          e.stopPropagation();
          if (e.key === 'Enter') { e.preventDefault(); commit(true); } else if (e.key === 'Escape') { e.preventDefault(); commit(false); }
        });
        inp.addEventListener('blur', function () { commit(true); });
        top.appendChild(inp);
        setTimeout(function () { try { inp.focus(); inp.select(); } catch (x) { /* ignore */ } }, 0);
      } else {
        var t = el('span', 'aiac-sess-t', s.title || T('(no title)'));
        t.title = s.title || '';
        top.appendChild(t);
      }
      d.appendChild(top);
      var meta = el('div', 'aiac-sess-m');
      meta.appendChild(el('span', 'aiac-badge ' + s.source, T(SRC_LABEL[s.source] || s.source)));
      var mi = modeInfo(s.mode);
      var mb = el('span', 'aiac-modebadge aiac-mode-' + mi.id, T(mi.label)); mb.title = T(mi.hint);
      meta.appendChild(mb);
      if (s.archived) { meta.appendChild(el('span', 'aiac-badge aiac-b-arch', T('archived'))); }
      var when = el('span', 'aiac-when', relTime(s.mtime) + (s.busy ? ' - ' + T('working') : (s.external ? ' - ' + T('active in the app') : '')));
      try { when.title = new Date(s.mtime * 1000).toLocaleString(); } catch (e) { /* ignore */ }
      meta.appendChild(when);
      if (st.confirmDel === s.id) {
        var cf = el('span', 'aiac-confirm');
        cf.appendChild(el('span', '', T('Delete this conversation?')));
        var yes = el('button', 'aiac-btn-deny', T('Delete')); yes.type = 'button';
        var no = el('button', '', T('Cancel')); no.type = 'button';
        yes.addEventListener('click', function (e) { e.stopPropagation(); doDelete(s); });
        no.addEventListener('click', function (e) { e.stopPropagation(); st.confirmDel = ''; renderSessions(); });
        cf.appendChild(yes); cf.appendChild(no);
        d.appendChild(meta); d.appendChild(cf);
      } else {
        var acts = el('span', 'aiac-acts');
        acts.appendChild(iconBtn('fa-pencil', T('Rename'), function () { st.editing = s.id; st.confirmDel = ''; renderSessions(); }));
        acts.appendChild(iconBtn(s.archived ? 'fa-undo' : 'fa-archive', s.archived ? T('Restore') : T('Archive'), function () { doArchive(s, !s.archived); }));
        var why = T('This conversation is attached to the Claude app right now. End it there first, then delete it.');
        acts.appendChild(iconBtn('fa-trash', s.deletable === false ? why : T('Delete'), function () {
          if (s.deletable === false) { listFlash(why, true); return; }
          st.confirmDel = s.id; st.editing = ''; renderSessions();
        }, s.deletable === false ? 'aiac-act-off' : ''));
        meta.appendChild(acts);
        d.appendChild(meta);
      }
      d.addEventListener('click', function () { if (st.editing !== s.id) { api2.open(s.id); } });
      return d;
    }
    function renderSessions() {
      ui.list.innerHTML = '';
      ui.arch.checked = st.showArchived;
      ui.archTxt.nodeValue = T('Show archived') + (st.archivedCount ? ' (' + st.archivedCount + ')' : '');
      if (!st.sessions.length) { ui.list.appendChild(el('div', 'aiac-empty', T('No conversations yet.'))); return; }
      st.sessions.forEach(function (s) { ui.list.appendChild(sessRow(s)); });
    }
    function doArchive(s, on) {
      api('chat_archive', { session_id: s.id, archived: on ? 1 : 0 }).then(function (r) {
        if (!r.ok) { listFlash(errText(r), true); return; }
        if (on && s.id === st.sid && !st.showArchived) { resetChat(); }
        api2.refreshSessions();
      });
    }
    function doDelete(s) {
      st.confirmDel = '';
      api('chat_delete', { session_id: s.id }).then(function (r) {
        if (!r.ok) { listFlash(errText(r), true); renderSessions(); return; }
        if (s.id === st.sid) { resetChat(); }
        api2.refreshSessions();
      });
    }
    ui.arch.addEventListener('change', function () {
      st.showArchived = ui.arch.checked; lsSet('aia.chat.archived', st.showArchived ? '1' : '0'); api2.refreshSessions();
    });

    function resetChat() {
      stopTimer();
      st.sid = null; st.runId = ''; st.next = 0; st.skipUser = false; st.modeTouched = false; st.cur = null;
      persist(); clearView(); setRunning(false); flash('');
      setTitle(''); setMode(st.defaultMode); showEmpty();
      renderSessions();
    }
    api2.newChat = function () {
      resetChat();
      api2.showView('chat');
      ui.text.focus();
    };
    api2.showView = function (v) {
      if (mode !== 'panel') { return; }
      st.view = v === 'list' ? 'list' : 'chat';
      box.classList.toggle('aiac-showlist', st.view === 'list');
      if (st.view === 'list') { st.confirmDel = ''; st.editing = ''; api2.refreshSessions(); } else { scrollDown(true); }
    };

    api2.open = function (sid) {
      stopTimer();
      st.sid = sid; st.runId = ''; st.skipUser = false; st.modeTouched = false;
      persist(); clearView(); setRunning(false); flash('');
      api2.showView('chat');
      ui.msgs.insertBefore(el('div', 'aiac-empty', T('Loading...')), ui.perms);
      return api('chat_history', { session_id: sid }).then(function (r) {
        if (st.sid !== sid) { return; }
        clearView();
        if (!r.ok) {
          if (r.code === 'unknown_session') { resetChat(); } else { flash(errText(r), true); }
          return;
        }
        addItems(r.items || []);
        var first = (r.items || []).filter(function (x) { return x.k === 'user'; })[0];
        setTitle(r.title || (first ? first.text.replace(/\s+/g, ' ').slice(0, 80) : ''));
        setMode(r.mode || st.defaultMode);
        if (r.archived) { flash(T('This conversation is archived. Sending a message restores it.')); }
        else if (r.external) { flash(T('This conversation looks active in the Claude app right now. Messages sent here and in the app can interleave.')); }
        if (r.run_id) { attach(r.run_id, r.next); } else { showEmpty(); }
        scrollDown(true);
        renderSessions();
      });
    };

    api2.send = function () {
      var text = ui.text.value.replace(/\s+$/, '');
      if (!text || st.running) { return; }
      flash('');
      var wantShot = mode === 'panel' && st.shot;
      var sel = st.selection;
      var wasNew = !st.sid;
      ui.btnSend.disabled = true;
      var pre = Promise.resolve({ ctx: null, image: null });
      if (mode === 'panel') {
        pre = new Promise(function (resolve) {
          var ctx = NS.collectContext(sel);
          if (!wantShot) { resolve({ ctx: ctx, image: null }); return; }
          NS.screenshot().then(function (img) { resolve({ ctx: ctx, image: img }); }).catch(function (e) {
            try { console.warn('AIA screenshot failed:', errMsg(e), e); } catch (x) { /* ignore */ }
            flash(T('Screenshot failed: %s; sent without it.', errMsg(e)));
            resolve({ ctx: ctx, image: null });
          });
        });
      }
      pre.then(function (p) {
        var data = { text: text, source: source, mode: st.mode };
        if (st.sid) { data.session_id = st.sid; }
        if (p.ctx) { data.context = p.ctx; }
        if (p.image) { data.image = p.image; }
        return api('chat_send', data).then(function (r) {
          if (!r.ok && p.image && (r.http === 413 || r.http === 400)) {
            delete data.image;
            flash(T('The screenshot was too large; sent without it.'));
            return api('chat_send', data);
          }
          return r;
        });
      }).then(function (r) {
        if (!r.ok) {
          ui.btnSend.disabled = false;
          if (r.code === 'busy' && r.run_id) { attach(r.run_id, 0); }
          flash(errText(r), true);
          return;
        }
        ui.text.value = '';
        st.selection = ''; renderChips();
        st.sid = r.session_id; persist();
        if (ui.empty && ui.empty.parentNode) { ui.empty.parentNode.removeChild(ui.empty); }
        // optimistic bubble; the run's own 'user' event is skipped
        var u = el('div', 'aiac-m aiac-m-user');
        u.appendChild(document.createTextNode(text));
        place(u);
        st.skipUser = true;
        if (wasNew) { setTitle(text.replace(/\s+/g, ' ').slice(0, 80)); }
        if (opts.onSent) { opts.onSent(); }
        st.pinned = true; scrollDown(true);
        attach(r.run_id, 0);
        api2.refreshSessions();
      });
    };

    api2.stop = function () {
      if (!st.runId) { return; }
      ui.btnStop.disabled = true;
      api('chat_stop', { run_id: st.runId }).then(function (r) {
        ui.btnStop.disabled = false;
        if (r && r.ok === false) { flash(errText(r), true); }
      });
    };

    api2.openInTab = function () {
      ssSet('aia.chat.handoff', st.sid ? st.sid : '-');
      window.location.href = TAB_URL;
    };

    api2.setSelection = function (text) { st.selection = text || ''; renderChips(); };
    function renderChips() {
      ui.chips.innerHTML = '';
      if (st.selection) {
        var c = el('span', 'aiac-chip', T('Selected text attached (%s characters)', st.selection.length));
        var x = el('a', '', ' x'); x.href = '#'; x.title = T('Remove');
        x.addEventListener('click', function (e) { e.preventDefault(); st.selection = ''; renderChips(); });
        c.appendChild(x);
        ui.chips.appendChild(c);
      }
      ui.chips.style.display = st.selection ? '' : 'none';
    }

    api2.focus = function () { try { ui.text.focus(); } catch (e) { /* ignore */ } };
    api2.isRunning = function () { return st.running; };
    api2.sessionId = function () { return st.sid; };
    api2.destroy = function () { st.destroyed = true; stopTimer(); };

    ui.btnSend.addEventListener('click', api2.send);
    ui.btnStop.addEventListener('click', api2.stop);
    ui.btnNewSide.addEventListener('click', api2.newChat);
    if (ui.btnBack) { ui.btnBack.addEventListener('click', function () { api2.showView('chat'); }); }
    if (ui.btnClose2) { ui.btnClose2.addEventListener('click', function () { if (opts.onClose) { opts.onClose(); } }); }
    ui.mode.addEventListener('change', function () {
      st.modeTouched = true;
      setMode(ui.mode.value);
      if (!st.sid) { return; }
      api('chat_set_mode', { session_id: st.sid, mode: st.mode }).then(function (r) {
        if (r && r.ok === false) { flash(errText(r), true); }
        else if (r && r.applies_next) { flash(T('The new mode applies from the next message.')); }
        else { flash(''); }
        api2.refreshSessions();
      });
    });
    paintMode();
    ui.text.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); api2.send(); }
      // keep the WebGUI's own shortcuts out of the question box, but let Alt+A / Escape reach the panel handler
      if (!e.altKey && e.key !== 'Escape') { e.stopPropagation(); }
    });
    ['keyup', 'keypress'].forEach(function (ev) { ui.text.addEventListener(ev, function (e) { e.stopPropagation(); }); });

    /* ---- start */
    clearView(); showEmpty();
    var handoff = mode === 'tab' ? ssGet('aia.chat.handoff') : null;
    if (handoff !== null && mode === 'tab') { ssSet('aia.chat.handoff', null); }
    var startSid = handoff && handoff !== '-' ? handoff : (handoff === '-' ? null : ssGet(storeKey));
    api2.refreshSessions();
    setInterval(function () {
      if (!document.hidden && !st.destroyed && !st.running && (mode === 'tab' || st.view === 'list') && !st.editing) { api2.refreshSessions(); }
    }, 20000);
    if (startSid) { api2.open(startSid); }
    return api2;
  }

  NS.mount = function (root, opts) {
    try {
      if (typeof root === 'string') { root = document.getElementById(root); }
      if (!root) { return null; }
      ensureCss();
      return Chat(root, opts);
    } catch (e) {
      try { console.warn('AIA chat failed to start', e); } catch (x) { /* ignore */ }
      if (root) { root.textContent = T('The chat could not be started.'); }
      return null;
    }
  };

  function ensureCss() {
    if (document.getElementById('aiac-css')) { return; }
    var l = document.createElement('link');
    l.id = 'aiac-css'; l.rel = 'stylesheet';
    l.href = BASE + '/css/aia-chat.css' + (NS.cssv ? '?v=' + NS.cssv : '');
    document.head.appendChild(l);
  }

  /* ------------------------------------------------------------------ floating button + panel */
  function activateTabOf(node) {
    try {
      var sec = node.closest('[role=tabpanel]');
      if (sec) {
        var b = document.querySelector('[role=tab][aria-controls="' + sec.id + '"]');
        if (b) { b.click(); return; }
      }
      var pane = node.closest('.tab-content, .content');
      if (pane && pane.id) {
        var r = document.querySelector('input.tab[id="' + pane.id.replace(/-panel$/, '') + '"]');
        if (r) { r.click(); }
      }
    } catch (e) { /* ignore */ }
  }

  function initButton() {
    if (window.top !== window.self) { return; }                 // popups / iframes: no button
    if (document.getElementById('aia-chat-root')) { return; }   // the Chat tab is here already
    if (document.querySelector('.aiac-fab') || NS.toggle) { return; }
    ensureCss();

    var style = NS.style === 'header' || NS.style === 'both' ? NS.style : 'floating';
    var hdr = function () { return document.querySelector('.nav-item.AIAButton'); };
    var fab = el('button', 'aiac-fab');
    fab.type = 'button';
    fab.title = T('Ask the assistant') + ' (Alt+A)';
    fab.setAttribute('aria-label', T('Ask the assistant'));
    fab.innerHTML = '<i class="fa fa-comments-o"></i><span class="aiac-fab-dot"></span>';
    if (style !== 'header') { document.body.appendChild(fab); }   // header style: detached stub, no floating button

    var panel = el('div', 'aiac-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', T('AI Assistant'));
    var grip = el('div', 'aiac-resize');
    grip.setAttribute('role', 'separator'); grip.setAttribute('aria-orientation', 'vertical'); grip.title = T('Drag to resize');
    panel.appendChild(grip);
    var holder = el('div');
    holder.style.cssText = 'flex:1;min-height:0;display:flex;flex-direction:column';
    panel.appendChild(holder);
    document.body.appendChild(panel);
    document.documentElement.classList.add('aiac-dockable');

    // ---- docked drawer: full height, pushes the page (html.aiac-docked + --aiac-dock-w), overlay below 900 px
    var W_MIN = 320, W_DEF = 440;
    function maxW() { return Math.max(W_MIN, Math.floor(window.innerWidth * 0.6)); }
    function clampW(w) { return Math.max(W_MIN, Math.min(maxW(), Math.round(w))); }
    function savedW() { var w = parseInt(lsGet('aia.panel.w'), 10); return clampW(w > 0 ? w : W_DEF); }
    var curW = savedW();
    function narrow() { return window.innerWidth < 900; }
    function applyW(w) {
      curW = clampW(w);
      panel.style.width = curW + 'px';
      document.documentElement.style.setProperty('--aiac-dock-w', curW + 'px');
    }
    function syncDock() {
      var on = isOpen() && !narrow();
      document.documentElement.classList.toggle('aiac-docked', on);
      document.documentElement.classList.toggle('aiac-overlay', isOpen() && narrow());
    }
    applyW(curW);
    var rz = null;
    grip.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) { return; }
      rz = { x: e.clientX, w: curW };
      document.documentElement.classList.add('aiac-resizing');
      try { grip.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
      e.preventDefault();
    });
    grip.addEventListener('pointermove', function (e) { if (rz) { applyW(rz.w + (rz.x - e.clientX)); } });
    function endResize(e) {
      if (!rz) { return; }
      rz = null;
      document.documentElement.classList.remove('aiac-resizing');
      try { grip.releasePointerCapture(e.pointerId); } catch (x) { /* ignore */ }
      lsSet('aia.panel.w', String(curW));
    }
    grip.addEventListener('pointerup', endResize);
    grip.addEventListener('pointercancel', endResize);
    grip.addEventListener('dblclick', function () { applyW(W_DEF); lsSet('aia.panel.w', String(W_DEF)); });
    window.addEventListener('resize', function () { applyW(curW); syncDock(); });

    panel.setAttribute('aria-hidden', 'true');
    var chat = null, lastSel = '', lastSelAt = 0, appliedAt = 0;
    // state dot on the header icon: a reply is streaming / a permission request waits
    var state = { busy: false, perm: false };
    function paintHeader() {
      var h = hdr();
      if (!h) { return; }
      h.classList.toggle('aia-busy', state.busy && !state.perm);
      h.classList.toggle('aia-perm', state.perm);
    }
    function mountChat() {
      return NS.mount(holder, {
        mode: 'panel',
        onClose: function () { setOpen(false); },
        onSent: function () { appliedAt = Date.now(); },
        onAttention: function () { if (!isOpen()) { fab.classList.add('aiac-attn'); } },
        onRunning: function (on) { state.busy = on; paintHeader(); },
        onPerm: function (n) { state.perm = n > 0; paintHeader(); }
      });
    }

    function pos() {
      var p = null;
      try { p = JSON.parse(lsGet('aia.fab.pos') || 'null'); } catch (e) { p = null; }
      return p && typeof p.r === 'number' && typeof p.b === 'number' ? p : { r: 22, b: 22 };
    }
    function applyPos(p) {
      var w = window.innerWidth, h = window.innerHeight;
      var r = Math.max(4, Math.min(w - 52, p.r)), b = Math.max(4, Math.min(h - 52, p.b));
      fab.style.right = r + 'px'; fab.style.bottom = b + 'px';
      return { r: r, b: b };
    }
    applyPos(pos());
    window.addEventListener('resize', function () { applyPos(pos()); });

    function isOpen() { return panel.classList.contains('aiac-open'); }
    function setOpen(on, from) {
      if (on === isOpen()) { return; }
      if (on) {
        // remember what the user had selected on the page before the focus moves into the panel
        var s = currentSelection();
        if (!chat) { chat = mountChat(); }
        appliedAt = Date.now();
        panel.classList.add('aiac-open');
        panel.setAttribute('aria-hidden', 'false');
        fab.classList.remove('aiac-attn');
        if (chat) { chat.setSelection(s || ''); chat.focus(); }
      } else {
        panel.classList.remove('aiac-open');
        panel.setAttribute('aria-hidden', 'true');
      }
      syncDock();
      var hh = hdr();
      if (hh) { hh.classList.toggle('aia-open', isOpen()); }
      ssSet('aia.panel.open', on ? '1' : '0');
    }
    NS.toggle = function (from) { try { setOpen(!isOpen(), typeof from === 'string' ? from : undefined); } catch (e) { /* ignore */ } };
    NS.togglePanel = function () { NS.toggle('header'); };

    function currentSelection() {
      try {
        var s = window.getSelection();
        var t = s ? String(s.toString()).trim() : '';
        if (t.length >= 2 && !(s.anchorNode && panel.contains(s.anchorNode))) { return t.slice(0, CONTEXT_CAP); }
      } catch (e) { /* ignore */ }
      return Date.now() - lastSelAt < 120000 ? lastSel : '';
    }
    document.addEventListener('selectionchange', function () {
      try {
        var s = window.getSelection(), t = s ? String(s.toString()).trim() : '';
        if (t.length >= 2 && !(s.anchorNode && (panel.contains(s.anchorNode) || fab.contains(s.anchorNode)))) { lastSel = t; lastSelAt = Date.now(); }
      } catch (e) { /* ignore */ }
    });

    // text selected while the panel is already open: offer it when the question box gets focus
    panel.addEventListener('focusin', function (e) {
      try {
        if (chat && e.target && e.target.tagName === 'TEXTAREA' && lastSelAt > appliedAt && lastSel) {
          chat.setSelection(lastSel);
          appliedAt = Date.now();
        }
      } catch (x) { /* ignore */ }
    });

    // drag (pointer events) vs click
    var drag = null;
    fab.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) { return; }
      var p = pos();
      drag = { x: e.clientX, y: e.clientY, r: p.r, b: p.b, moved: false };
      try { fab.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
      e.preventDefault();   // keeps the page selection alive
    });
    fab.addEventListener('pointermove', function (e) {
      if (!drag) { return; }
      var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 6) { return; }
      drag.moved = true;
      fab.classList.add('aiac-dragging');
      drag.cur = applyPos({ r: drag.r - dx, b: drag.b - dy });
    });
    function endDrag(e) {
      if (!drag) { return; }
      var d = drag; drag = null;
      fab.classList.remove('aiac-dragging');
      try { fab.releasePointerCapture(e.pointerId); } catch (x) { /* ignore */ }
      if (d.moved) { if (d.cur) { lsSet('aia.fab.pos', JSON.stringify(d.cur)); } }
      else { NS.toggle('fab'); }
    }
    fab.addEventListener('pointerup', endDrag);
    fab.addEventListener('pointercancel', function () { drag = null; fab.classList.remove('aiac-dragging'); });
    fab.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); NS.toggle('fab'); } });

    document.addEventListener('keydown', function (e) {
      try {
        if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'a' || e.key === 'A' || (e.code === 'KeyA' && !/^[a-z]$/i.test(e.key || '')))) {
          e.preventDefault();
          NS.toggle();
        } else if (e.key === 'Escape' && isOpen() && panel.contains(document.activeElement)) {
          setOpen(false);
        }
      } catch (x) { /* ignore */ }
    });

    if (NS.pendingToggle) { NS.pendingToggle = false; NS.togglePanel(); }   // header icon clicked before this ran

    // a conversation in progress or an open panel survives page changes
    if (ssGet('aia.panel.open') === '1' || ssGet('aia.chat.session')) {
      if (ssGet('aia.panel.open') === '1') { setOpen(true); }
      else if (!chat) { chat = mountChat(); }
    }
  }

  /* ------------------------------------------------------------------ auto start */
  function ready(fn) {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); }
  }
  ready(function () {
    try {
      var tabRoot = document.getElementById('aia-chat-root');
      if (tabRoot && !tabRoot.getAttribute('data-aiac')) {
        tabRoot.setAttribute('data-aiac', '1');
        // "Open in Chat tab" from the panel: bring this tab to the front, the component picks up the session id
        if (ssGet('aia.chat.handoff') !== null) { activateTabOf(tabRoot); }
        NS.tab = NS.mount(tabRoot, { mode: 'tab' });
      }
      if (NS.button) { initButton(); }
    } catch (e) {
      try { console.warn('AIA chat init failed', e); } catch (x) { /* ignore */ }
    }
  });
})();

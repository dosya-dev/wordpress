/* dosya Backup admin: shared helpers for the tab scripts. Exposes window.dosyaBackupApi. ES5, no build step. */
(function () {
  'use strict';
  var cfg = window.dosyaBackup;
  var app = document.getElementById('dosya-backup-app');
  if (!cfg || !app) { return; }

  // wp_localize_script prints every scalar as a string
  cfg.now = parseInt(cfg.now, 10) || Math.floor(Date.now() / 1000);
  cfg.perPage = parseInt(cfg.perPage, 10) || 20;
  cfg.safetyCopy = cfg.safetyCopy === '1' || cfg.safetyCopy === true;
  cfg.i18n = cfg.i18n || {};

  var TERMINAL = ['done', 'failed', 'skipped', 'cancelled'];

  /* Phase path (kind.phase[.step]) to i18n key. Mirrors RunningCard::PHASES; PhasesSyncTest keeps them equal. */
  var PHASES = {
    'backup.queued': 'phase_preparing',
    'backup.db_dump': 'phase_backup_database',
    'backup.file_scan': 'phase_backup_scanning',
    'backup.archive': 'phase_backup_files',
    'backup.finalize': 'phase_finishing',
    'backup.remote': 'phase_registering',
    'backup.skipped': 'phase_done',
    'backup.done': 'phase_done',
    'backup.finalize.seal': 'phase_finishing',
    'backup.finalize.hash': 'phase_finishing',
    'backup.finalize.write': 'phase_finishing',
    'backup.finalize.remote': 'phase_finishing',
    'backup.remote.register': 'phase_remote_register',
    'backup.remote.volumes': 'phase_remote_volumes',
    'backup.remote.manifests': 'phase_remote_manifests',
    'backup.remote.complete': 'phase_remote_complete',
    'restore.queued': 'phase_preparing',
    'restore.snapshot': 'phase_restore_snapshot',
    'restore.files_fetch': 'phase_restore_fetch',
    'restore.db_import': 'phase_restore_database',
    'restore.db_transform': 'phase_restore_transform',
    'restore.files_move': 'phase_restore_files',
    'restore.db_swap': 'phase_restore_swap',
    'restore.finalize': 'phase_finishing',
    'restore.health': 'phase_restore_health',
    'restore.cleanup': 'phase_restore_cleanup',
    'restore.done': 'phase_done'
  };

  function $(name) { return app.querySelector('[data-dosya="' + name + '"]'); }
  function $$(name) { return Array.prototype.slice.call(app.querySelectorAll('[data-dosya="' + name + '"]')); }

  /* sprintf subset: %s, %d, %1$s, %2$d and %%. */
  function format(str, args) {
    var next = 0;
    return String(str).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, pos, type) {
      if (type === '%') { return '%'; }
      var v = pos ? args[parseInt(pos, 10) - 1] : args[next++];
      if (v === undefined || v === null) { v = ''; }
      return type === 'd' ? String(parseInt(v, 10) || 0) : String(v);
    });
  }

  function i18n(key) {
    var str = Object.prototype.hasOwnProperty.call(cfg.i18n, key) ? cfg.i18n[key] : key;
    return format(str, Array.prototype.slice.call(arguments, 1));
  }

  /* A dropped connection rejects with error.network set, so a caller can retry; server answers never carry it. */
  function networkError() {
    var e = new Error(i18n('network_error'));
    e.network = true;
    return e;
  }

  function api(action, fields, file) {
    var body = new FormData();
    body.append('action', 'dosya_' + action);
    body.append('nonce', cfg.nonce);
    Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
    if (file) { body.append('chunk', file, 'chunk'); }
    return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) {
        return r.text().then(function (text) {
          var j;
          // a server error (a PHP fatal, a proxy timeout) is not a lost session: the page keeps working and says the request failed
          if (r.status >= 500) {
            try { j = JSON.parse(text); } catch (e) { j = null; }
            throw new Error((j && j.data && j.data.message) || i18n('request_failed'));
          }
          try { j = JSON.parse(text); } catch (e) { throw new Error('SESSION_EXPIRED'); }
          // -1 / 0 are what admin-ajax.php answers for a stale nonce or a logged-out user; 401 is our own stale-nonce answer
          if (!j || j === -1 || j === 0 || r.status === 401) { throw new Error('SESSION_EXPIRED'); }
          if (!j.success) { throw new Error((j.data && j.data.message) || i18n('request_failed')); }
          return j.data;
        }, function () { throw networkError(); });
      }, function () { throw networkError(); });
  }

  /* The message to show for a failed request. */
  function errorText(e) {
    return e && e.message === 'SESSION_EXPIRED' ? i18n('session_expired') : String((e && e.message) || i18n('request_failed'));
  }

  function setText(el, text, isError) {
    if (!el) { return; }
    if (el.hidden) { el.hidden = false; }
    el.textContent = text;
    el.classList.toggle('dosya-error', !!isError);
  }

  /* Mirrors ViewModel\Format::bytes. */
  function size(bytes) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var b = parseInt(bytes, 10) || 0;
    if (b < 1024) { return b + ' B'; }
    var v = b;
    var i = 0;
    for (;;) {
      while (v >= 1024 && i < 4) { v /= 1024; i++; }
      if (i < 4 && Math.round(v) >= 1024) { v /= 1024; i++; continue; }
      break;
    }
    var s = (v >= 100 || Math.round(v * 10) / 10 >= 100 || Math.abs(v - Math.round(v)) < 0.05) ? String(Math.round(v)) : v.toFixed(1);
    return s + ' ' + units[i];
  }

  function lockTabs(busy) {
    app.setAttribute('data-busy', busy ? '1' : '0');
    Array.prototype.forEach.call(app.querySelectorAll('.nav-tab'), function (a) {
      if (a.classList.contains('nav-tab-active')) { return; }
      // a disabled tab is also out of the keyboard order
      if (busy) {
        a.setAttribute('aria-disabled', 'true');
        a.setAttribute('title', i18n('tabs_busy'));
        a.setAttribute('tabindex', '-1');
      } else {
        a.removeAttribute('aria-disabled');
        a.removeAttribute('title');
        a.removeAttribute('tabindex');
      }
    });
  }

  function announce(text) {
    var el = $('announce');
    if (!el) { return; }
    // clear first so the same sentence twice is announced twice
    el.textContent = '';
    setTimeout(function () { el.textContent = text; }, 50);
  }

  function pct(done, total) { return Math.max(0, Math.min(100, Math.round(done / total * 100))); }
  function int(v) { return parseInt(v, 10) || 0; }

  /* Mirrors RunningCard::build, plus the joined line AdminPage renders on first paint. */
  function phaseText(status) {
    var kind = status && status.kind === 'restore' ? 'restore' : 'backup';
    var p = (status && status.progress) || {};
    var phase = String(p.phase || 'queued');
    var step = String(p.step || '');
    var path = kind + '.' + phase;
    if (step !== '' && PHASES[path + '.' + step]) { path += '.' + step; }
    var key = PHASES[path] || 'phase_working';
    if (phase === 'queued') { key = 'phase_preparing'; }
    var text = i18n(key);
    var detail = '';
    var percent = null;
    var done;
    var total;

    if (key === 'phase_remote_volumes') {
      var vol = int(p.volume);
      var vols = int(p.volumes);
      text = i18n(key, vol, vols);
      if (vols > 0) { percent = Math.max(0, Math.min(100, Math.round((Math.max(vol, 1) - 1) / vols * 100))); }
    } else if (kind === 'backup' && phase === 'db_dump') {
      done = int(p.tables_done);
      total = int(p.tables);
      detail = i18n('detail_tables', done, total);
      percent = total > 0 ? pct(done, total) : null;
    } else if (kind === 'backup' && phase === 'file_scan') {
      var n = int(p.files);
      detail = i18n(n === 1 ? 'detail_file' : 'detail_files', n, size(p.bytes));
    } else if (kind === 'backup' && phase === 'archive') {
      done = int(p.bytes_done);
      total = int(p.bytes_total);
      detail = i18n('detail_bytes', size(done), size(total));
      percent = total > 0 ? pct(done, total) : null;
    } else if (kind === 'restore' && (phase === 'files_fetch' || phase === 'db_import')) {
      done = int(p.done);
      total = int(p.total);
      detail = i18n('detail_items', done, total);
      percent = total > 0 ? pct(done, total) : null;
    }

    var heading;
    if (kind === 'restore') { heading = i18n('heading_restore'); } else if (phase === 'remote') { heading = i18n('heading_upload'); } else { heading = i18n('heading_backup'); }
    var maintenance = kind === 'restore' && phase !== 'queued' && phase !== 'done';
    var line = [text];
    if (percent !== null) { line.push(percent + '%'); }
    if (detail !== '') { line.push(detail); }
    if (maintenance) { line.push(i18n('maintenance')); }
    return { heading: heading, key: key, text: text, percent: percent, detail: detail, maintenance: maintenance, line: line.join(' ' + String.fromCharCode(183) + ' ') };
  }

  function isTerminal(state) { return TERMINAL.indexOf(state) !== -1; }

  /* ---------- shared rendering: running card and result banner (both tabs) ---------- */

  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'text') { el.textContent = v; } else if (k === 'className') { el.className = v; } else { el.setAttribute(k, v === true ? k : v); }
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) { return; }
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  function topCard() { return $('running-card') || $('status-card'); }

  /* Puts the status or running card at the top (before the result banner), with the progress line the template prints after the status card. */
  function placeTop(node) {
    var old = topCard();
    var line = app.querySelector('p.dosya-progress-line[data-dosya="progress"]');
    if (line) { line.parentNode.removeChild(line); }
    if (old) {
      old.parentNode.replaceChild(node, old);
    } else {
      var anchor = $('result');
      if (!anchor) { return; }
      anchor.parentNode.insertBefore(node, anchor);
    }
    if (node.getAttribute('data-dosya') === 'status-card') {
      node.parentNode.insertBefore(h('p', { className: 'dosya-progress-line', 'data-dosya': 'progress', 'aria-live': 'polite' }), node.nextSibling);
    }
  }

  /* Same markup as partials/running-card.php. */
  function renderRunningCard(status) {
    var t = phaseText(status);
    var card = h('section', { className: 'dosya-panel dosya-running', 'data-dosya': 'running-card', 'data-tone': 'neutral', 'data-js': '1' }, [
      h('p', { className: 'dosya-running__heading', text: t.heading }),
      h('div', { className: 'dosya-progress', role: 'progressbar', 'aria-label': t.heading, 'aria-valuemin': '0', 'aria-valuemax': '100' }, [
        h('span', { className: 'dosya-progress__thumb', style: 'width:0%' })
      ]),
      h('p', { className: 'dosya-running__detail', 'data-dosya': 'progress', 'aria-live': 'polite', text: t.line }),
      h('details', {}, [h('summary', { text: i18n('show_log') }), h('pre', { 'data-dosya': 'log' })])
    ]);
    placeTop(card);
    updateRunning(status);
    return card;
  }

  function updateRunning(status) {
    var card = $('running-card');
    if (!card) { return; }
    var t = phaseText(status);
    // the server's first paint names the restored backup's date; keep that heading for a restore
    if (card.getAttribute('data-js') === '1' || status.kind !== 'restore') {
      card.querySelector('.dosya-running__heading').textContent = t.heading;
      card.querySelector('.dosya-progress').setAttribute('aria-label', t.heading);
    }
    var bar = card.querySelector('.dosya-progress');
    if (t.percent === null) { bar.removeAttribute('aria-valuenow'); } else { bar.setAttribute('aria-valuenow', String(t.percent)); }
    card.querySelector('.dosya-progress__thumb').style.width = (t.percent === null ? 0 : t.percent) + '%';
    card.querySelector('.dosya-running__detail').textContent = t.line;
    appendLog(card, t);
  }

  var LOG_MAX = 200;

  /* Show log: one line per distinct "phase · detail", newest last, at most LOG_MAX lines. */
  function appendLog(card, t) {
    var pre = card.querySelector('[data-dosya="log"]');
    if (!pre) { return; }
    var entry = t.detail !== '' ? t.text + ' ' + String.fromCharCode(183) + ' ' + t.detail : t.text;
    var lines = pre.textContent === '' ? [] : pre.textContent.split('\n');
    if (lines.length && lines[lines.length - 1] === entry) { return; }
    lines.push(entry);
    if (lines.length > LOG_MAX) { lines = lines.slice(lines.length - LOG_MAX); }
    pre.textContent = lines.join('\n');
  }

  var RESULT_KEY = 'dosyaBackupResult';
  var RESULT_TTL = 60000;

  function hideResult() {
    var el = $('result');
    if (el) { el.hidden = true; el.textContent = ''; el.removeAttribute('data-tone'); }
  }

  /*
   * Shows a result banner (ResultBanner::build shape: tone, text, action{label, kind, id}). handlers maps an action kind to
   * function(id, button); an action without a handler is not offered. Every banner can be dismissed.
   */
  function renderResult(vm, handlers) {
    var el = $('result');
    if (!el || !vm) { return; }
    el.textContent = '';
    el.setAttribute('data-tone', vm.tone);
    el.appendChild(h('p', { text: vm.text }));
    var row = h('p', { className: 'dosya-result__actions' });
    var handler = vm.action && handlers ? handlers[vm.action.kind] : null;
    if (typeof handler === 'function') {
      var go = h('button', { type: 'button', className: 'button', 'data-dosya': 'result-action', 'data-kind': vm.action.kind,
        'data-id': vm.action.id || null, text: vm.action.label });
      go.addEventListener('click', function () { handler(vm.action.id || null, go); });
      row.appendChild(go);
      row.appendChild(document.createTextNode(' '));
    }
    var dismiss = h('button', { type: 'button', className: 'button-link', 'data-dosya': 'result-dismiss', text: i18n('dismiss') });
    dismiss.addEventListener('click', function () {
      hideResult();
      takeKeptResult();
    });
    row.appendChild(dismiss);
    el.appendChild(row);
    el.hidden = false;
  }

  /* Used only when a terminal status arrives without result_vm (an older server answer). */
  function fallbackResult(s) {
    if (s.state === 'done') {
      var w = (s.result && s.result.warnings) || [];
      return { tone: w.length ? 'caution' : 'success', text: w.length ? i18n('done_warnings', w.length, w[0]) : i18n('done'), action: null };
    }
    var cause = s.error || i18n('unknown_error');
    return { tone: s.state === 'failed' ? 'error' : 'caution', text: i18n(s.state === 'skipped' ? 'skipped_prefix' : 'failed_prefix', cause), action: null };
  }

  /* Keeps a banner across the reload that follows a finished restore; it is dropped after a minute. */
  function keepResult(vm) {
    try { window.sessionStorage.setItem(RESULT_KEY, JSON.stringify({ vm: vm, at: Date.now() })); } catch (e) { /* storage blocked: the banner is lost on reload */ }
  }

  function takeKeptResult() {
    try {
      var raw = window.sessionStorage.getItem(RESULT_KEY);
      if (!raw) { return null; }
      window.sessionStorage.removeItem(RESULT_KEY);
      var kept = JSON.parse(raw);
      var age = kept && typeof kept.at === 'number' ? Date.now() - kept.at : -1;
      if (!kept || !kept.vm || age < 0 || age > RESULT_TTL) { return null; }
      return kept.vm;
    } catch (e) {
      return null; // storage blocked or malformed: no banner to bring back
    }
  }

  /* ---------- driving a job ---------- */

  var driving = false;
  var RETRY_DELAYS = [1000, 3000, 10000];

  function isDriving() { return driving; }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  /* Runs a page callback; a callback that throws never leaves the page locked. Returns false when it threw. */
  function safely(fn, s) {
    if (!fn) { return true; }
    try {
      fn(s);
      return true;
    } catch (err) {
      if (window.console && window.console.error) { window.console.error(err); }
      return false;
    }
  }

  /*
   * Steps a job until it ends, like the legacy drive(): 3 s between polls while another request holds the job, 300 ms otherwise.
   * onStatus(status) runs every tick, onDone(status) once on a terminal state. kind ('backup'|'restore') fills status.kind
   * when the server leaves it out. A dropped connection is retried after 1, 3 and 10 s before the page gives up.
   * A finished restore reloads the page after 2.5 s: the restored database has new users and sessions.
   */
  function driveJob(jobId, onStatus, onDone, kind) {
    var isRestore = kind === 'restore';
    var failures = 0;
    driving = true;
    function stop() {
      driving = false;
      lockTabs(false);
    }
    function tick() {
      return api('step', { job_id: jobId }).then(function (s) {
        failures = 0;
        if (!s.kind && kind) { s.kind = kind; }
        isRestore = s.kind === 'restore';
        safely(onStatus, s);
        if (!isTerminal(s.state)) {
          return wait(s.state === 'busy' ? 3000 : 300).then(tick);
        }
        driving = false;
        if (!safely(onDone, s)) { lockTabs(false); }
        if (isRestore && s.state === 'done') {
          setTimeout(function () { window.location.reload(); }, 2500);
        }
        return s;
      }, function (e) {
        if (e.network && failures < RETRY_DELAYS.length) {
          return wait(RETRY_DELAYS[failures++]).then(tick);
        }
        stop();
        if (e.message !== 'SESSION_EXPIRED') {
          setText($('progress'), e.message, true);
          return null;
        }
        if (isRestore) {
          // the restore re-issued the login cookie, which retired this page's nonce; the job finishes in the background
          setText($('progress'), i18n('login_refreshed'));
          setTimeout(function () { window.location.reload(); }, 2000);
        } else {
          setText($('progress'), i18n('session_expired'), true);
        }
        return null;
      });
    }
    return tick().then(null, function (err) {
      stop();
      if (window.console && window.console.error) { window.console.error(err); }
      return null;
    });
  }

  window.dosyaBackupApi = {
    cfg: cfg,
    app: app,
    api: api,
    $: $,
    $$: $$,
    setText: setText,
    size: size,
    i18n: i18n,
    lockTabs: lockTabs,
    announce: announce,
    PHASES: PHASES,
    TERMINAL: TERMINAL,
    isTerminal: isTerminal,
    phaseText: phaseText,
    driveJob: driveJob,
    isDriving: isDriving,
    errorText: errorText,
    h: h,
    placeTop: placeTop,
    renderRunningCard: renderRunningCard,
    updateRunning: updateRunning,
    renderResult: renderResult,
    hideResult: hideResult,
    fallbackResult: fallbackResult,
    keepResult: keepResult,
    takeKeptResult: takeKeptResult,
    openRestore: null
  };
})();

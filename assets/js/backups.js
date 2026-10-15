/* dosya Backup admin: the Backups tab (status card, running card, list, popover, result banner). ES5, no build step. */
(function () {
  'use strict';
  var A = window.dosyaBackupApi;
  if (!A || A.cfg.tab !== 'backups') { return; }
  var cfg = A.cfg;
  var app = A.app;
  var $ = A.$;
  var i18n = A.i18n;
  var h = A.h;

  var page = Math.max(1, parseInt((/[?&]paged=(\d+)/.exec(window.location.search) || [])[1], 10) || 1);
  var rows = {}; // id -> {vm, raw}
  var lastStatus = null;
  var lastStatusJson = null; // the status card on screen, so a refresh keeps an open popover and its typed note

  var placeTop = A.placeTop;

  /* ---------- status card (same markup as partials/status-card.php) ---------- */

  function popover() {
    var scope = function (value, key, checked) {
      return h('label', {}, [h('input', { type: 'radio', name: 'dosya_scope', value: value, checked: checked ? 'checked' : null }), ' ' + i18n(key)]);
    };
    return h('details', { className: 'dosya-popover', 'data-dosya': 'backup-popover', hidden: true }, [
      h('summary', { className: 'screen-reader-text', text: i18n('popover_label') }),
      h('fieldset', {}, [
        h('legend', { text: i18n('scope_legend') }),
        scope('full', 'scope_full', true),
        scope('db', 'scope_db', false),
        scope('files', 'scope_files', false)
      ]),
      h('p', {}, [
        h('label', { 'for': 'dosya-backup-note', text: i18n('note_label') }),
        h('input', { type: 'text', id: 'dosya-backup-note', className: 'regular-text', maxlength: '80', 'data-dosya': 'backup-note', placeholder: i18n('note_placeholder') })
      ]),
      h('p', {}, [h('button', { type: 'button', className: 'button button-primary', 'data-dosya': 'backup-start', text: i18n('start_backup') })])
    ]);
  }

  function statusCard(vm) {
    var actions = h('div', { className: 'dosya-status__actions' }, [
      h('button', { type: 'button', className: 'button button-primary', 'data-dosya': 'backup-now', 'data-action': vm.button.action,
        'data-scope': vm.retry_scope || 'full', text: vm.button.label })
    ]);
    if (vm.fix && vm.fix.target === 'restore_safety') {
      // a failed restore: the fix restores the safety copy it made, like the result banner's button
      actions.appendChild(h('button', { type: 'button', className: 'button', 'data-dosya': 'status-undo', 'data-id': vm.fix.snapshot_id, text: vm.fix.label }));
    } else if (vm.fix && vm.fix_url) {
      actions.appendChild(h('a', { className: 'button-link', 'data-dosya': 'status-fix', 'data-target': vm.fix.target, 'data-job': vm.log_job_id || null,
        href: vm.fix_url, text: vm.fix.label }));
    }
    return h('section', { className: 'dosya-panel dosya-status', 'data-dosya': 'status-card', 'data-tone': vm.tone }, [
      h('span', { className: 'dosya-dot', 'aria-hidden': 'true' }),
      h('div', { className: 'dosya-status__text' }, [
        h('p', { className: 'dosya-status__headline', text: vm.headline }),
        h('p', { className: 'dosya-status__sub', text: vm.sub })
      ]),
      actions,
      popover()
    ]);
  }

  /* ---------- running card and result banner (shared helpers in api.js) ---------- */

  function runningCard(status) {
    lastStatus = status;
    lastStatusJson = null;
    return A.renderRunningCard(status);
  }

  function updateRunning(status) {
    lastStatus = status;
    A.updateRunning(status);
  }

  /* Undo and Restore safety copy act once: the button stays disabled unless the request is refused. */
  function undo(id, button) {
    if (A.isDriving() || (button && button.disabled)) { return; }
    if (button) { button.disabled = true; }
    begin(A.api('undo_restore', { snapshot_id: id }), 'restore').then(function (ok) {
      if (!ok && button) { button.disabled = false; }
    });
  }

  var lastScope = 'full'; // the scope of the backup this page last started, for the result banner's Retry now

  var resultHandlers = {
    undo: undo,
    restore_safety: undo,
    retry: function () { startBackup(lastScope, ''); },
    log: function () { showLog(null); }
  };

  function showResult(vm) { A.renderResult(vm, resultHandlers); }

  /* ---------- driving a job ---------- */

  function drive(jobId, kind) {
    if (A.isDriving()) { return; }
    A.lockTabs(true);
    A.driveJob(jobId, updateRunning, onDone, kind);
  }

  function onDone(s) {
    var vm = s.result_vm || A.fallbackResult(s);
    showResult(vm);
    A.announce(vm.text);
    A.lockTabs(false);
    if (s.kind === 'restore' && s.state === 'done') {
      // driveJob reloads the page in 2.5 s; keep the banner (and its Undo button) across the reload
      A.keepResult(vm);
      return;
    }
    refresh();
  }

  function begin(promise, kind) {
    A.hideResult();
    return promise.then(function (r) {
      runningCard({ kind: kind, state: 'queued', progress: { phase: 'queued' } });
      A.announce(kind === 'restore' ? i18n('heading_restore') : i18n('backup_started'));
      drive(r.job_id, kind);
      return true;
    }).catch(function (e) {
      A.setText($('progress'), e.message === 'SESSION_EXPIRED' ? i18n('session_expired') : e.message, true);
      return false;
    });
  }

  var starting = false; // a start_backup request is pending: the start buttons stay disabled until it answers

  function startButtons(disabled) {
    ['backup-now', 'backup-start'].forEach(function (name) {
      var b = $(name);
      if (b) { b.disabled = disabled; }
    });
    var retry = app.querySelectorAll('[data-dosya="result-action"][data-kind="retry"]');
    Array.prototype.forEach.call(retry, function (b) { b.disabled = disabled; });
  }

  function startBackup(scope, note) {
    if (starting || A.isDriving()) { return Promise.resolve(false); }
    starting = true;
    lastScope = scope || 'full';
    startButtons(true);
    closePopover(false);
    return begin(A.api('start_backup', { scope: lastScope, note: note }), 'backup').then(function (ok) {
      starting = false;
      // on success the running card replaced the status card; on a refusal the buttons work again
      if (!ok) { startButtons(false); }
      return ok;
    });
  }

  /* ---------- list (same markup as partials/backup-row.php) ---------- */

  function badges(list) {
    var out = [];
    (list || []).forEach(function (b) {
      out.push(' ');
      out.push(h('span', { className: 'dosya-badge dosya-badge--' + b.kind, title: b.title !== '' ? b.title : null, text: b.text }));
    });
    return out;
  }

  function downloadUrl(id, file) {
    return cfg.downloadBase.replace('__ID__', encodeURIComponent(id)).replace('__FILE__', encodeURIComponent(file));
  }

  function row(vm, raw) {
    var failed = raw.failed_reason !== null && raw.failed_reason !== undefined;
    var incremental = raw.kind === 'incremental';
    var created = h('td', {}, [vm.created_text, h('br'), h('span', { className: 'dosya-rel', text: vm.created_rel })]);
    if (vm.note !== '') {
      created.appendChild(h('br'));
      created.appendChild(h('em', { className: 'dosya-note', text: vm.note }));
    }
    var cells = [created, h('td', { text: vm.type_text })];
    if (failed) {
      cells.push(h('td', { colspan: '2' }, badges(vm.badges)));
      cells.push(h('td'));
    } else {
      var contents = h('td', {}, [vm.contents_text]);
      if (vm.contents_hint !== '') {
        contents.appendChild(h('br'));
        contents.appendChild(h('span', { className: 'dosya-hint', text: vm.contents_hint }));
      }
      cells.push(contents);
      cells.push(h('td', { text: vm.size_text }));
      cells.push(h('td', {}, badges(vm.badges)));
    }

    var items = [];
    if (!failed && vm.actions.download) {
      var files = [{ label: i18n('download_manifest'), file: 'manifest.json.gz' }];
      for (var v = 1; v <= (parseInt(raw.volumes, 10) || 0); v++) {
        var name = 'v' + ('000' + v).slice(-4) + '.dosya';
        files.push({ label: i18n('download_volume', name), file: name });
      }
      files.forEach(function (f) {
        items.push(h('li', {}, [h('a', { href: downloadUrl(vm.id, f.file), 'data-dosya': 'download', 'data-incremental': incremental ? '1' : null, text: f.label })]));
      });
    }
    if (vm.actions.log) {
      items.push(h('li', {}, [h('button', { type: 'button', className: 'button-link', 'data-dosya': 'log', 'data-id': vm.id, text: i18n('view_log') })]));
    }
    if (!failed) {
      items.push(h('li', {}, [h('button', { type: 'button', className: 'button-link', 'data-dosya': 'delete', 'data-id': vm.id,
        disabled: vm.actions['delete'] ? null : 'disabled', title: vm.actions['delete'] ? null : String(vm.delete_refusal || ''), text: i18n('delete') })]));
    }
    var actions = h('td', { className: 'dosya-actions' });
    if (!failed && vm.actions.restore) {
      actions.appendChild(h('button', { type: 'button', className: 'button', 'data-dosya': 'restore', 'data-id': vm.id, text: i18n('restore') }));
      actions.appendChild(document.createTextNode(' '));
    }
    actions.appendChild(h('details', { className: 'dosya-kebab' }, [h('summary', { 'aria-label': i18n('more_actions'), text: '\u22EE' }), h('ul', {}, items)]));
    cells.push(actions);
    return h('tr', { 'data-id': vm.id, 'data-failed': failed ? '1' : null }, cells);
  }

  function pager(p) {
    var box = $('pager');
    if (!box) { return; }
    box.textContent = '';
    var pages = Math.ceil((parseInt(p.total, 10) || 0) / Math.max(1, parseInt(p.per_page, 10) || cfg.perPage));
    var cur = parseInt(p.page, 10) || 1;
    if (pages <= 1) { box.hidden = true; return; }
    var links = h('span', { className: 'pagination-links' });
    if (cur > 1) {
      links.appendChild(h('a', { className: 'prev-page button', href: '?page=dosya-backup&tab=backups&paged=' + (cur - 1), 'data-dosya': 'page', 'data-page': String(cur - 1) }, [
        h('span', { 'aria-hidden': 'true', text: '\u2039' }), h('span', { className: 'screen-reader-text', text: i18n('newer_backups') })]));
      links.appendChild(document.createTextNode(' '));
    }
    links.appendChild(h('span', { className: 'paging-input', text: i18n('page_label', cur, pages) }));
    if (cur < pages) {
      links.appendChild(document.createTextNode(' '));
      links.appendChild(h('a', { className: 'next-page button', href: '?page=dosya-backup&tab=backups&paged=' + (cur + 1), 'data-dosya': 'page', 'data-page': String(cur + 1) }, [
        h('span', { className: 'screen-reader-text', text: i18n('older_backups') }), h('span', { 'aria-hidden': 'true', text: '\u203A' })]));
    }
    box.appendChild(h('div', { className: 'tablenav-pages' }, [links]));
    box.hidden = false;
  }

  function renderList(p) {
    var tbody = $('list');
    if (!tbody) { return; }
    tbody.textContent = '';
    rows = {};
    var vms = p.rows_vm || [];
    if (!vms.length) {
      tbody.appendChild(h('tr', { className: 'dosya-list__empty' }, [h('td', { colspan: '6', text: i18n('no_backups') })]));
    }
    vms.forEach(function (vm, i) {
      var raw = (p.backups || [])[i] || {};
      rows[vm.id] = { vm: vm, raw: raw };
      tbody.appendChild(row(vm, raw));
    });
    var foot = $('list-foot');
    if (foot && typeof p.foot_text === 'string') { foot.textContent = p.foot_text; }
    pager(p);
  }

  function renderStatus(p) {
    if (p.active && !A.isTerminal(p.active.state)) {
      if (!$('running-card')) { runningCard(p.active); } else { updateRunning(p.active); }
      drive(p.active.id, p.active.kind);
      return;
    }
    if (A.isDriving()) { return; }
    if (p.status_vm) {
      var json = JSON.stringify(p.status_vm);
      // an unchanged card stays as it is, so an open popover keeps its choices and typed note
      if (json !== lastStatusJson || !$('status-card')) { placeTop(statusCard(p.status_vm)); }
      lastStatusJson = json;
    }
    A.lockTabs(false);
  }

  function refresh() {
    return A.api('list', { page: page }).then(function (p) {
      page = parseInt(p.page, 10) || page;
      renderList(p);
      renderStatus(p);
    }).catch(function (e) {
      A.setText($('progress'), e.message === 'SESSION_EXPIRED' ? i18n('session_expired') : e.message, true);
    });
  }

  /* ---------- popover ---------- */

  function closePopover(focusButton) {
    var pop = $('backup-popover');
    if (!pop || pop.hidden) { return; }
    pop.open = false;
    pop.hidden = true;
    if (focusButton && $('backup-now')) { $('backup-now').focus(); }
  }

  function popoverValues() {
    var pop = $('backup-popover');
    var checked = pop ? pop.querySelector('input[name="dosya_scope"]:checked') : null;
    var note = $('backup-note');
    return { scope: checked ? checked.value : 'full', note: note ? String(note.value).trim().slice(0, 80) : '' };
  }

  /* ---------- log ---------- */

  /* The log of one job (the status card's View log names the job that failed). */
  function showJobLog(jobId) {
    var panel = $('log-panel');
    var pre = $('log-text');
    if (!panel || !pre) { return; }
    var open = function (text) {
      pre.textContent = text;
      panel.hidden = false;
      panel.open = true;
      var summary = panel.querySelector('summary');
      if (summary) { summary.focus(); }
    };
    A.api('status', { job_id: jobId }).then(function (s) { open(s.error || i18n('no_log')); }, function (e) { open(A.errorText(e)); });
  }

  function showLog(id) {
    var panel = $('log-panel');
    var pre = $('log-text');
    if (!panel || !pre) { return; }
    var open = function (text) {
      pre.textContent = text;
      panel.hidden = false;
      panel.open = true;
      var summary = panel.querySelector('summary');
      if (summary) { summary.focus(); }
    };
    var entry = id ? rows[id] : null;
    if (entry && entry.raw.failed_reason !== null && entry.raw.failed_reason !== undefined) {
      // a failed run's row id is its job id
      A.api('status', { job_id: id }).then(function (s) { open(s.error || entry.raw.failed_reason); }, function () { open(entry.raw.failed_reason); });
      return;
    }
    if (entry && entry.raw.upload_failed_reason) { open(entry.raw.upload_failed_reason); return; }
    if (!id && lastStatus && lastStatus.error) { open(lastStatus.error); return; }
    open(i18n('no_log'));
  }

  function newestFailedId() {
    var ids = Object.keys(rows);
    for (var i = 0; i < ids.length; i++) {
      var r = rows[ids[i]].raw;
      if (r.failed_reason !== null && r.failed_reason !== undefined) { return ids[i]; }
    }
    return null;
  }

  /* ---------- restore: restore.js (always loaded on this tab) owns the dialog ---------- */

  function openRestore(id, opener) {
    if (typeof A.openRestore === 'function') { A.openRestore(id, opener); }
  }

  /* ---------- events ---------- */

  // clicks anywhere outside an open kebab close it
  document.addEventListener('click', function (e) {
    Array.prototype.forEach.call(app.querySelectorAll('details.dosya-kebab[open]'), function (d) {
      if (!d.contains(e.target)) { d.open = false; }
    });
  });

  app.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-dosya]') : null;
    if (!t || !app.contains(t)) { return; }
    var name = t.getAttribute('data-dosya');
    var id = t.getAttribute('data-id');

    if (name === 'backup-now') {
      if (t.disabled) { return; }
      if (t.getAttribute('data-action') === 'retry') { startBackup(t.getAttribute('data-scope') || 'full', ''); return; }
      var pop = $('backup-popover');
      if (!pop) { startBackup('full', ''); return; }
      if (pop.hidden || !pop.open) {
        pop.hidden = false;
        pop.open = true;
        var first = pop.querySelector('input[name="dosya_scope"]:checked');
        if (first) { first.focus(); }
      } else {
        var vals = popoverValues();
        startBackup(vals.scope, vals.note);
      }
    } else if (name === 'backup-start') {
      if (t.disabled) { return; }
      var v = popoverValues();
      startBackup(v.scope, v.note);
    } else if (name === 'restore') {
      openRestore(id, t);
    } else if (name === 'download') {
      if (t.getAttribute('data-incremental') === '1' && !window.confirm(i18n('incremental_download'))) { e.preventDefault(); }
    } else if (name === 'log' && t.tagName === 'BUTTON') {
      t.closest('details').open = false;
      showLog(id);
    } else if (name === 'status-fix' && t.getAttribute('data-target') === 'log') {
      e.preventDefault();
      if (t.getAttribute('data-job')) { showJobLog(t.getAttribute('data-job')); } else { showLog(newestFailedId()); }
    } else if (name === 'status-undo') {
      undo(id, t);
    } else if (name === 'delete') {
      if (t.disabled || !window.confirm(i18n('delete_confirm'))) { return; }
      A.api('delete', { backup_id: id }).then(refresh).catch(function (err) {
        A.setText($('progress'), err.message === 'SESSION_EXPIRED' ? i18n('session_expired') : err.message, true);
      });
    } else if (name === 'page') {
      e.preventDefault();
      page = Math.max(1, parseInt(t.getAttribute('data-page'), 10) || 1);
      try { window.history.replaceState(null, '', t.getAttribute('href')); } catch (err) { /* history unavailable: the list still pages */ }
      refresh();
    }
  });

  app.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.key !== 'Esc') { return; }
    var kebab = e.target.closest ? e.target.closest('details.dosya-kebab[open]') : null;
    if (kebab) {
      kebab.open = false;
      kebab.querySelector('summary').focus();
      return;
    }
    var pop = e.target.closest ? e.target.closest('[data-dosya="backup-popover"]') : null;
    // the popover also closes while focus is still on the Back up now button that opened it
    if (pop || (e.target.getAttribute && e.target.getAttribute('data-dosya') === 'backup-now')) { closePopover(true); }
  });

  // restore.js drove a job on this tab and it ended without a reload: bring the list and status card up to date
  app.addEventListener('dosya:jobend', function () { refresh(); });

  A.showLog = showLog;

  // focus moving out of an open kebab closes it; a null relatedTarget (a click elsewhere) is left to the click handler
  app.addEventListener('focusout', function (e) {
    var kebab = e.target.closest ? e.target.closest('details.dosya-kebab[open]') : null;
    if (kebab && e.relatedTarget && !kebab.contains(e.relatedTarget)) { kebab.open = false; }
  });

  /* ---------- start ---------- */

  var kept = A.takeKeptResult();
  if (kept) { showResult(kept); }

  refresh();
})();

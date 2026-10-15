/* dosya Backup admin: the restore dialog (same-site and migration variants), restores and Undo, and the Restore & migrate
 * tab's resumable upload. Loaded on the Backups and Restore & migrate tabs. ES5, no build step. */
(function () {
  'use strict';
  var A = window.dosyaBackupApi;
  if (!A || (A.cfg.tab !== 'restore' && A.cfg.tab !== 'backups')) { return; }
  var cfg = A.cfg;
  var app = A.app;
  var $ = A.$;
  var h = A.h;
  var i18n = A.i18n;
  var onRestoreTab = cfg.tab === 'restore';
  var dialog = $('restore-dialog');

  /* ---------- running a restore ---------- */

  /* Result banner actions this script can carry out. */
  /* Undo and Restore safety copy act once: the button stays disabled unless the request is refused. */
  function undo(id, button) {
    if (A.isDriving() || (button && button.disabled)) { return; }
    if (button) { button.disabled = true; }
    begin(A.api('undo_restore', { snapshot_id: id })).then(function (ok) {
      if (!ok && button) { button.disabled = false; }
    });
  }

  function handlers() {
    var out = { undo: undo, restore_safety: undo };
    if (typeof A.showLog === 'function') { out.log = function () { A.showLog(null); }; }
    return out;
  }

  function showError(e) {
    var text = A.errorText(e);
    A.renderResult({ tone: 'error', text: text, action: null }, handlers());
    A.announce(text);
  }

  /* A job ended without the reload: the Backups tab refreshes its list and status card; this tab drops the running card. */
  function ended() {
    if (onRestoreTab) {
      var card = $('running-card');
      if (card) { card.parentNode.removeChild(card); }
      return;
    }
    var ev;
    try {
      ev = new window.CustomEvent('dosya:jobend');
    } catch (err) {
      ev = document.createEvent('CustomEvent');
      ev.initCustomEvent('dosya:jobend', false, false, null);
    }
    app.dispatchEvent(ev);
  }

  function onDone(s) {
    var vm = s.result_vm || A.fallbackResult(s);
    A.renderResult(vm, handlers());
    A.announce(vm.text);
    A.lockTabs(false);
    if (s.kind === 'restore' && s.state === 'done') {
      // driveJob reloads the page in 2.5 s; keep the banner (and its Undo button) across the reload
      A.keepResult(vm);
      return;
    }
    ended();
  }

  function drive(jobId, kind) {
    if (A.isDriving()) { return; }
    A.lockTabs(true);
    A.driveJob(jobId, A.updateRunning, onDone, kind || 'restore');
  }

  /* promise: a start_restore or undo_restore request answering {job_id}. */
  function begin(promise) {
    A.hideResult();
    A.lockTabs(true);
    return promise.then(function (r) {
      A.renderRunningCard({ kind: 'restore', state: 'queued', progress: { phase: 'queued' } });
      A.announce(i18n('restore_started'));
      drive(r.job_id, 'restore');
      return true;
    }).catch(function (e) {
      // a job another script is driving keeps the tabs locked
      if (!A.isDriving()) { A.lockTabs(false); }
      showError(e);
      return false;
    });
  }

  /* ---------- the dialog ---------- */

  var pending = null; // {id, opener, vm}
  var loading = false;

  function norm(v) { return String(v || '').trim().toLowerCase(); }

  function toggle(el, on) { if (el) { el.hidden = !on; } }

  /* The safety copy holds the database only: a files-only restore runs without one, so it asks for the domain like a migration. */
  function filesOnly() { return chosenScope() === 'files'; }

  function confirmNeeded() { return !!(pending && (pending.vm.type_to_confirm || filesOnly())); }

  function typedMatches() {
    var box = $('restore-confirm');
    return !!box && norm(box.value) !== '' && norm(box.value) === norm(box.getAttribute('data-expected'));
  }

  function updateGo() {
    var go = $('restore-go');
    if (!go) { return; }
    go.disabled = confirmNeeded() && !typedMatches();
    var hint = $('restore-confirm-hint');
    var box = $('restore-confirm');
    if (!hint || !box) { return; }
    // a hint only once the typed text is as long as the domain and still differs
    var expected = box.getAttribute('data-expected') || '';
    var show = confirmNeeded() && norm(box.value).length >= norm(expected).length && !typedMatches();
    hint.textContent = show ? i18n('type_domain_mismatch', expected) : '';
    hint.hidden = !show;
  }

  /* Which parts of the site a scope restores: the includes list follows the chosen radio. */
  function parts(scope) {
    if (scope === 'db') { return ['db']; }
    if (scope === 'files') { return ['files']; }
    return ['db', 'files'];
  }

  function chosenScope() {
    var el = dialog.querySelector('input[name="dosya_restore_scope"]:checked');
    return el ? el.value : 'full';
  }

  function updateIncludes() {
    var want = parts(chosenScope());
    Array.prototype.forEach.call(dialog.querySelectorAll('[data-dosya="restore-includes"] li'), function (li) {
      li.hidden = want.indexOf(li.getAttribute('data-part')) === -1;
    });
  }

  /* Follows the scope radio: safety box, type-to-confirm row and the Go button. */
  function updateScopeState() {
    if (!pending) { return; }
    var vm = pending.vm;
    var hasSafety = vm.safety !== null && vm.safety !== undefined && vm.safety !== '';
    toggle($('restore-safety'), hasSafety && !filesOnly());
    toggle($('restore-confirm-row'), confirmNeeded());
    updateIncludes();
    updateGo();
  }

  function fill(vm) {
    dialog.setAttribute('data-variant', vm.variant);
    var title = $('restore-title');
    if (title) { title.textContent = vm.title; }
    var facts = $('restore-facts');
    if (facts) {
      facts.textContent = vm.facts || '';
      facts.hidden = !vm.facts;
    }

    var cons = $('restore-consequence');
    if (cons) {
      var p = vm.consequence_parts || { before: vm.consequence, domain: '', after: '' };
      cons.textContent = '';
      cons.appendChild(document.createTextNode(p.before || ''));
      cons.appendChild(h('strong', { 'data-dosya': 'restore-domain', text: p.domain || '' }));
      cons.appendChild(document.createTextNode(p.after || ''));
    }

    // the server lists the database line before the files line, each only when the backup holds that part
    var backupParts = parts(vm.scope);
    var list = $('restore-includes');
    if (list) {
      list.textContent = '';
      (vm.includes || []).forEach(function (text, i) {
        list.appendChild(h('li', { 'data-part': backupParts[i] || null, text: text }));
      });
    }

    var safety = $('restore-safety');
    if (safety) { safety.textContent = vm.safety || ''; }

    // scope radios: the backup's own scope is the default; parts the backup does not hold cannot be chosen
    Array.prototype.forEach.call(dialog.querySelectorAll('input[name="dosya_restore_scope"]'), function (r) {
      var possible = vm.scope === 'full' || r.value === vm.scope;
      r.disabled = !possible;
      r.checked = r.value === vm.scope;
    });

    toggle($('restore-url-row'), !!vm.show_address);
    var urlLabel = $('restore-url-label');
    if (urlLabel && vm.labels) { urlLabel.textContent = vm.labels.address; }
    var url = $('restore-url');
    if (url) { url.value = vm.address_default || cfg.homeUrl || ''; }

    var change = $('restore-url-change');
    if (change) {
      change.textContent = vm.url_change || '';
      toggle(change, !!vm.url_change);
    }

    var confirmLabel = $('restore-confirm-label');
    if (confirmLabel && vm.labels) { confirmLabel.textContent = vm.labels.confirm; }
    var box = $('restore-confirm');
    if (box) {
      box.value = '';
      box.setAttribute('data-expected', vm.confirm_domain || '');
    }

    var cancel = $('restore-cancel');
    var go = $('restore-go');
    if (cancel && vm.buttons) { cancel.textContent = vm.buttons.cancel; }
    if (go && vm.buttons) { go.textContent = vm.buttons.go; }
    updateScopeState();
  }

  function onClose() {
    var result = dialog.returnValue;
    dialog.returnValue = '';
    var p = pending;
    var needDomain = confirmNeeded();
    var domainOk = typedMatches();
    var scope = p ? chosenScope() : 'full';
    pending = null;
    if (p && p.opener && typeof p.opener.focus === 'function' && document.body.contains(p.opener)) { p.opener.focus(); }
    if (result !== 'go' || !p) { return; }
    if (needDomain && !domainOk) { return; } // the button is disabled until the domain matches
    var fields = { backup_id: p.id, scope: scope, confirm: '1' };
    if (p.vm.show_address && $('restore-url')) { fields.new_url = $('restore-url').value; }
    if (needDomain && $('restore-confirm')) { fields.confirm_domain = String($('restore-confirm').value).trim(); }
    begin(A.api('start_restore', fields));
  }

  /* Opens the dialog for a stored backup. opener gets focus back when the dialog closes. */
  function openRestore(backupId, opener) {
    if (!dialog || typeof dialog.showModal !== 'function' || loading) { return; }
    // no restore while a backup or restore runs: the server would refuse it after the dialog was filled in
    if (A.isDriving() || app.getAttribute('data-busy') === '1') { return; }
    opener = opener || document.activeElement;
    loading = true;
    A.api('restore_dialog', { backup_id: backupId }).then(function (vm) {
      loading = false;
      pending = { id: backupId, opener: opener, vm: vm };
      fill(vm);
      dialog.returnValue = '';
      if (!dialog.open) { dialog.showModal(); }
      var title = $('restore-title');
      if (title) { title.focus(); }
    }, function (e) {
      loading = false;
      showError(e);
    });
  }

  if (dialog) {
    dialog.addEventListener('close', onClose);
    dialog.addEventListener('input', function (e) {
      if (e.target && e.target.getAttribute('data-dosya') === 'restore-confirm') { updateGo(); }
    });
    dialog.addEventListener('change', function (e) {
      if (e.target && e.target.name === 'dosya_restore_scope') { updateScopeState(); }
    });
    // Enter in a text field would submit with the first button; for a destructive dialog it does nothing instead
    dialog.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT' && e.target.type !== 'radio') { e.preventDefault(); }
    });
  }
  A.openRestore = openRestore;

  /* ---------- upload (Restore & migrate tab) ---------- */

  var UPLOAD_KEY = 'dosyaBackupUpload:';
  var uploading = false;

  function randomId() {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }

  /* The same files chosen again reuse their upload id, so the server answers each file's next offset and the upload resumes. */
  function uploadKey(files) {
    return UPLOAD_KEY + files.map(function (f) { return f.name + ':' + f.size + ':' + (f.lastModified || 0); }).sort().join('|');
  }

  var UPLOAD_TTL = 7 * 86400000; // the server drops an unfinished upload long before this

  /* Kept as {id, at}. An older plain id string carries no date: it resumes when chosen again and is pruned otherwise. */
  function readUpload(key) {
    var raw = window.localStorage.getItem(key);
    if (!raw) { return null; }
    if (/^[0-9a-f]{32}$/.test(raw)) { return { id: raw, at: 0 }; }
    try {
      var v = JSON.parse(raw);
      return v && typeof v.id === 'string' && /^[0-9a-f]{32}$/.test(v.id) && typeof v.at === 'number' ? v : null;
    } catch (e) {
      return null;
    }
  }

  /* Drops upload ids kept for more than a week (and malformed ones), so abandoned uploads do not pile up in storage. */
  function pruneUploads(keep) {
    try {
      var stale = [];
      for (var i = 0; i < window.localStorage.length; i++) {
        var k = window.localStorage.key(i);
        if (!k || k.indexOf(UPLOAD_KEY) !== 0 || k === keep) { continue; }
        var v = readUpload(k);
        if (!v || Date.now() - v.at > UPLOAD_TTL) { stale.push(k); }
      }
      stale.forEach(function (k) { window.localStorage.removeItem(k); });
    } catch (e) { /* storage blocked: nothing kept */ }
  }

  function uploadIdFor(key) {
    try {
      var kept = readUpload(key);
      var id = kept ? kept.id : randomId();
      window.localStorage.setItem(key, JSON.stringify({ id: id, at: Date.now() }));
      return id;
    } catch (e) {
      return randomId(); // storage blocked: this attempt still works, a later one starts over
    }
  }

  function forgetUpload(key) {
    try { window.localStorage.removeItem(key); } catch (e) { /* storage blocked: nothing kept */ }
  }

  function uploadPanel() {
    var panel = $('upload-progress');
    if (!panel) { return null; }
    if (!panel.querySelector('.dosya-upload__name')) {
      panel.textContent = '';
      panel.appendChild(h('p', { className: 'dosya-upload__name' }));
      panel.appendChild(h('div', { className: 'dosya-progress', role: 'progressbar', 'aria-label': i18n('upload_label'), 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0' }, [
        h('span', { className: 'dosya-progress__thumb', style: 'width:0%' })
      ]));
      panel.appendChild(h('p', { className: 'dosya-upload__detail' }));
    }
    panel.hidden = false;
    return panel;
  }

  function showUpload(name, done, total, chunk, chunks) {
    var panel = uploadPanel();
    if (!panel) { return; }
    var percent = total > 0 ? Math.max(0, Math.min(100, Math.floor(done / total * 100))) : 100;
    panel.querySelector('.dosya-upload__name').textContent = name ? i18n('upload_file', name) : '';
    panel.querySelector('.dosya-progress').setAttribute('aria-valuenow', String(percent));
    panel.querySelector('.dosya-progress__thumb').style.width = percent + '%';
    A.setText(panel.querySelector('.dosya-upload__detail'), i18n('uploading', A.size(done), A.size(total), chunk, chunks));
  }

  function uploadMessage(text, isError) {
    var panel = uploadPanel();
    if (!panel) { return; }
    A.setText(panel.querySelector('.dosya-upload__detail'), text, isError);
    A.announce(text);
  }

  function uploadAll(files) {
    if (uploading) { uploadMessage(i18n('upload_busy'), true); return; }
    if (!files.length) { uploadMessage(i18n('upload_none'), true); return; }
    uploading = true;
    A.hideResult();
    var chunkSize = Math.max(1, parseInt(cfg.chunkSize, 10) || 262144);
    var total = 0;
    var chunks = 0;
    files.forEach(function (f) {
      total += f.size;
      chunks += Math.ceil(f.size / chunkSize);
    });
    var key = uploadKey(files);
    pruneUploads(key);
    var uploadId = uploadIdFor(key);
    var doneBefore = 0;
    var chunksBefore = 0;

    function uploadFile(f) {
      var offset = 0;
      var stalls = 0;
      function next() {
        if (offset >= f.size) {
          doneBefore += f.size;
          chunksBefore += Math.ceil(f.size / chunkSize);
          return null;
        }
        var chunk = Math.min(chunks, chunksBefore + Math.floor(offset / chunkSize) + 1);
        showUpload(f.name, doneBefore + offset, total, chunk, chunks);
        var piece = f.slice(offset, Math.min(f.size, offset + chunkSize));
        return A.api('upload_chunk', { upload_id: uploadId, name: f.name, offset: offset, total: f.size }, piece).then(function (r) {
          var at = parseInt(r && r.next, 10) || 0;
          // the server answers where its copy ends; a resumed file jumps ahead, a stuck one stops after a few tries
          stalls = at <= offset ? stalls + 1 : 0;
          if (stalls > 3) { throw new Error(i18n('request_failed')); }
          offset = at;
          return next();
        });
      }
      return next();
    }

    var finishing = false;
    files.reduce(function (p, f) { return p.then(function () { return uploadFile(f); }); }, Promise.resolve())
      .then(function () {
        finishing = true;
        showUpload('', total, total, chunks, chunks);
        return A.api('upload_finish', { upload_id: uploadId });
      })
      .then(function (r) {
        uploading = false;
        forgetUpload(key);
        uploadMessage(i18n('backup_received'));
        openRestore(r.backup_id, $('upload'));
      })
      .catch(function (e) {
        uploading = false;
        var refused = !e.network && e.message !== 'SESSION_EXPIRED';
        if (finishing && refused) {
          // the server refused the backup (an incremental without its earlier backups, a damaged volume) and dropped the upload
          forgetUpload(key);
          uploadMessage(A.errorText(e), true);
          return;
        }
        uploadMessage(i18n('upload_failed', A.errorText(e)), true);
      });
  }

  function startUpload() {
    var drop = $('drop');
    var input = $('upload');
    if (!drop || !input) { return; }
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      uploadAll(files);
      input.value = '';
    });
    drop.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (e.dataTransfer) { e.dataTransfer.dropEffect = 'copy'; }
      drop.classList.add('is-over');
    });
    drop.addEventListener('dragleave', function (e) {
      if (!e.relatedTarget || !drop.contains(e.relatedTarget)) { drop.classList.remove('is-over'); }
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      drop.classList.remove('is-over');
      uploadAll(Array.prototype.slice.call((e.dataTransfer && e.dataTransfer.files) || []));
    });
  }

  /* ---------- start ---------- */

  if (onRestoreTab) {
    startUpload();
    var kept = A.takeKeptResult();
    if (kept) { A.renderResult(kept, handlers()); }
    // a job that is already running keeps this tab locked and shows its progress here too
    if (app.getAttribute('data-busy') === '1') {
      A.lockTabs(true);
      A.api('list', { page: 1 }).then(function (p) {
        if (p.active && !A.isTerminal(p.active.state)) {
          A.renderRunningCard(p.active);
          drive(p.active.id, p.active.kind);
        } else {
          A.lockTabs(false);
        }
      }).catch(function (e) {
        A.lockTabs(false);
        showError(e);
      });
    }
  }
})();

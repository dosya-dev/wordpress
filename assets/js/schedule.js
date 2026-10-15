/* dosya Backup admin: Schedule tab (save settings, next-run text). ES5, no build step. Needs api.js. */
(function () {
  'use strict';
  var A = window.dosyaBackupApi;
  if (!A || A.cfg.tab !== 'schedule') { return; }
  var $ = A.$;
  var schedule = $('set-schedule');
  var save = $('set-save');
  if (!schedule || !save) { return; }

  function toggleWhen() {
    var when = $('set-when');
    if (when) { when.hidden = schedule.value === 'off'; }
  }

  function showNext(text) {
    var next = $('set-next');
    if (next) { next.textContent = text || ''; }
  }

  /* Make the controls show what the server stored (it may have normalised a value). */
  function fill(st) {
    if (!st) { return; }
    schedule.value = st.schedule;
    $('set-time').value = st.time;
    var keep = $('set-keep');
    var has = Array.prototype.some.call(keep.options, function (o) { return o.value === String(st.keep); });
    if (!has) {
      var opt = document.createElement('option');
      opt.value = String(st.keep);
      opt.textContent = String(st.keep);
      keep.appendChild(opt);
    }
    keep.value = String(st.keep);
    $('set-safety').checked = !!st.safety_copy;
    $('set-email-fail').checked = !!st.email_fail;
    $('set-email-ok').checked = !!st.email_ok;
    $('set-excludes').value = (st.excludes || []).join('\n');
    toggleWhen();
  }

  schedule.addEventListener('change', toggleWhen);

  // the "Saved" line describes the last save only: any later edit clears it
  function clearStatus() {
    var status = $('set-status');
    if (status && status.textContent) { status.textContent = ''; status.classList.remove('dosya-error'); }
  }
  ['set-schedule', 'set-time', 'set-keep', 'set-safety', 'set-email-fail', 'set-email-ok', 'set-excludes'].forEach(function (name) {
    var el = $(name);
    if (!el) { return; }
    el.addEventListener('input', clearStatus);
    el.addEventListener('change', clearStatus);
  });

  save.addEventListener('click', function () {
    // every control, every time: an unchecked box is sent as '0' because the server keeps any field that is left out
    var fields = {
      schedule: schedule.value,
      time: $('set-time').value,
      keep: $('set-keep').value,
      safety_copy: $('set-safety').checked ? '1' : '0',
      email_fail: $('set-email-fail').checked ? '1' : '0',
      email_ok: $('set-email-ok').checked ? '1' : '0',
      excludes: $('set-excludes').value
    };
    save.disabled = true;
    A.api('save_settings', fields)
      .then(function (d) {
        fill(d.settings);
        showNext(d.next_run_text);
        A.setText($('set-status'), A.i18n('saved') + ' · ' + A.i18n('saved_detail'), false);
      })
      .catch(function (e) { A.setText($('set-status'), e.message, true); })
      .then(function () { save.disabled = false; });
  });
})();

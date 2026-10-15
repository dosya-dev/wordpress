/* dosya Backup admin: dosya tab (Disconnect; the storage meter is rendered by the server). ES5, no build step. Needs api.js. */
(function () {
  'use strict';
  var A = window.dosyaBackupApi;
  if (!A || A.cfg.tab !== 'dosya') { return; }
  var $ = A.$;

  var button = $('disconnect');
  if (button) {
    button.addEventListener('click', function () {
      if (!window.confirm(button.getAttribute('data-confirm') || A.i18n('disconnect_confirm'))) { return; }
      button.disabled = true;
      A.api('disconnect')
        .then(function () { window.location.reload(); })
        .catch(function (e) {
          button.disabled = false;
          A.setText($('disconnect-status'), e.message, true);
        });
    });
  }
})();

/* Админка: общие мелочи.
   - <form data-confirm="Удалить?"> / <button data-confirm="…"> — подтверждение действия;
   - Adm.post(url, data) — POST с CSRF-токеном сессии (meta csrf-token), ответ JSON;
   - сообщения .adm-flash исчезают через 5 сек. */
(function () {
  'use strict';
  var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  document.addEventListener('submit', function (e) {
    var f = e.target, msg = f.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
    if (msg && !confirm(msg)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('a[data-confirm]'); if (b && !confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });
  setTimeout(function () { document.querySelectorAll('.adm-flash.ok').forEach(function (el) { el.style.display = 'none'; }); }, 5000);
  // на телефоне вкладки .subtabs прокручиваются по горизонтали — показываем текущую
  document.querySelectorAll('.subtabs').forEach(function (bar) {
    var on = bar.querySelector('.on');
    if (on && bar.scrollWidth > bar.clientWidth) bar.scrollLeft = Math.max(0, on.offsetLeft - bar.offsetLeft - 16);
  });
  window.Adm = {
    token: token,
    post: function (url, data) {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
      fd.append('_token', token);
      return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Admin-Token': token, 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); });
    }
  };
})();

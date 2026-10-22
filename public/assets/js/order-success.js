/* «Заказ оформлен» (/order/success/): кнопки «Отправить заказ в WhatsApp / Viber».
   WhatsApp получает текст заказа в ссылке wa.me; Viber текст в ссылке на чат не принимает — по нажатию «Viber» и
   «Скопировать текст заказа» текст копируется в буфер обмена, подсказка под кнопками говорит, что его осталось вставить. */
(function () {
  'use strict';
  var box = document.querySelector('[data-msg]');
  if (!box) return;
  var src = box.querySelector('[data-msg-text]');
  var hint = box.querySelector('[data-msg-hint]');
  var text = src ? src.value : '';

  function fallback() {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }
  function copy() {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallback(); });
    }
    return Promise.resolve(fallback());
  }

  box.addEventListener('click', function (e) {
    var el = e.target.closest('[data-msg-copy]');
    if (!el || text === '') return;
    var msg = el.getAttribute('data-msg-copy');
    copy().then(function (ok) {
      if (ok && hint && msg) { hint.textContent = msg; hint.classList.add('ok'); }
    });
  });
})();

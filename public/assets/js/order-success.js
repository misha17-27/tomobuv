/* «Заказ оформлен» (/order/success/): кнопки «Отправить заказ в WhatsApp / Viber».
   WhatsApp получает текст заказа в ссылке wa.me; Viber текст в ссылке на чат не принимает — по нажатию «Viber» и
   «Скопировать текст заказа» текст копируется в буфер (UI.copyText), подсказка под кнопками говорит, что его осталось вставить. */
(function () {
  'use strict';
  var UI = window.UI;
  var box = document.querySelector('[data-msg]');
  if (!box || !UI || !UI.copyText) return;
  var src = box.querySelector('[data-msg-text]');
  var hint = box.querySelector('[data-msg-hint]');
  var text = src ? src.value : '';

  box.addEventListener('click', function (e) {
    var el = e.target.closest('[data-msg-copy]');
    if (!el || text === '') return;
    var msg = el.getAttribute('data-msg-copy');
    UI.copyText(text).then(function (ok) {
      if (ok && hint && msg) { hint.textContent = msg; hint.classList.add('ok'); }
    });
  });
})();

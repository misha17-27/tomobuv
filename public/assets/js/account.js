/* Tomobuv — вход/регистрация, кабинет, сравнение, отзывы о магазине.
   Подключается на страницах раздела через View::render(..., ['scripts' => ['js/account.js']]). Использует window.UI (app.js).
   Тексты — через UI.t(…) (переводы: lang/uk/account.php, ключи «js:…»), внутренние адреса — через UI.url(). */
(function () {
  'use strict';
  var UI = window.UI;
  if (!UI) return;
  var $ = UI.$, $$ = UI.$$, t = UI.t;

  /* ---------- ошибки у полей ---------- */
  function fieldOf(inp) { return inp.closest('.field') || inp.parentNode; }
  function kids(f, cls) { return Array.prototype.filter.call(f.children, function (c) { return c.classList.contains(cls); }); }
  function setErr(inp, msg) {
    var f = fieldOf(inp), id = 'e-' + (inp.name || inp.id), el = kids(f, 'errtxt')[0];
    inp.classList.add('err'); inp.setAttribute('aria-invalid', 'true');
    if (!el) { el = document.createElement('div'); el.className = 'errtxt'; f.appendChild(el); }
    el.id = id; el.textContent = msg; inp.setAttribute('aria-describedby', id);
    kids(f, 'hint').forEach(function (h) { h.classList.add('hidden'); });
  }
  function clearErr(inp) {
    var f = fieldOf(inp);
    inp.classList.remove('err'); inp.removeAttribute('aria-invalid'); inp.removeAttribute('aria-describedby');
    kids(f, 'errtxt').forEach(function (el) { el.remove(); });
    kids(f, 'hint').forEach(function (h) { h.classList.remove('hidden'); });
  }
  function validEmail(v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v); }

  /* Проверка формы в браузере (сервер всё равно проверяет повторно) */
  function checkForm(f) {
    var first = null;
    $$('input,textarea', f).forEach(function (i) {
      if (!i.name || i.type === 'hidden' || i.classList.contains('hidden') || i.type === 'radio' || i.type === 'checkbox') return;
      var v = i.value.trim(), msg = '';
      if (i.required && !v) msg = i.type === 'password' ? t('Введите пароль') : t('Заполните поле');
      else if (v && i.type === 'email' && !validEmail(v)) msg = t('Проверьте e-mail: например, name@gmail.com');
      else if (v && i.type === 'tel' && !UI.validPhone(v)) msg = t('Проверьте номер телефона');
      else if (i.name === 'login' && v && v.indexOf('@') < 0 && !UI.validPhone(v)) msg = t('Укажите e-mail или телефон полностью');
      else if (i.minLength > 0 && v && i.value.length < i.minLength) msg = t('Не меньше {n} символов', { n: i.minLength });
      else if (i.name === 'password2') { var p = f.querySelector('input[name=password]'); if (p && p.value !== i.value) msg = t('Пароли не совпадают'); }
      if (msg) { setErr(i, msg); if (!first) first = i; } else if (i.classList.contains('err')) clearErr(i);
    });
    if (first) first.focus();
    return !first;
  }

  function bindForms() {
    $$('form[data-auth-form]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (!checkForm(f)) { e.preventDefault(); return; }
        var b = $('button[type=submit]', f); if (b) { b.disabled = true; setTimeout(function () { b.disabled = false; }, 8000); }
      });
    });
    document.addEventListener('input', function (e) {
      var i = e.target; if (i.classList && i.classList.contains('err') && i.closest('form')) clearErr(i);
    });
    // показать / скрыть пароль
    document.addEventListener('click', function (e) {
      var b = e.target.closest('.pw-tg'); if (!b) return;
      var i = b.parentNode.querySelector('input'); if (!i) return;
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? t('Скрыть пароль') : t('Показать пароль'));
      b.classList.toggle('on', show);
    });
  }

  /* ---------- отзывы о магазине: отправка без перезагрузки ---------- */
  function bindReview() {
    var f = $('form[data-review-form]'); if (!f) return;
    var box = f.closest('.srv-form'), msg = $('.srv-msg', box), tok = $('[data-csrf]', f);
    if (tok && !tok.value) tok.value = UI.csrf();          // страница из кэша — токен из cookie
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!checkForm(f)) return;
      var data = {};
      $$('input,textarea', f).forEach(function (i) {
        if (!i.name || i.name === '_csrf' || (i.type === 'radio' && !i.checked)) return;
        data[i.name] = i.value;
      });
      var b = $('button[type=submit]', f); b.disabled = true;
      UI.post(UI.url('/reviews/'), data).then(function (r) {
        b.disabled = false;
        if (r && r.ok) {
          f.remove();
          $$('.srv-lead,.note.warn', box).forEach(function (x) { x.remove(); });
          msg.innerHTML = '<div class="note ok" role="status">' + UI.esc(r.message) + '</div>';
          UI.toast(t('Спасибо! Отзыв отправлен'));
          return;
        }
        var errs = (r && r.errors) || {}, focused = false;
        Object.keys(errs).forEach(function (k) {
          var i = f.querySelector('[name="' + k + '"]');
          if (i && i.type !== 'radio' && i.type !== 'hidden') { setErr(i, errs[k]); if (!focused) { i.focus(); focused = true; } }
        });
        msg.innerHTML = errs.form || !Object.keys(errs).length ? '<div class="note warn" role="alert">' + UI.esc((r && r.error) || t('Не удалось отправить отзыв')) + '</div>' : '';
      });
    });
  }

  /* ---------- сравнение ---------- */
  function bindCompare() {
    var box = $('#cmp-box'), table = $('#cmp-table'); if (!box || !table) return;
    var fromUrl = box.getAttribute('data-from-url') === '1';
    var diff = $('#cmp-diff');

    function ids() { return $$('thead [data-col]', table).map(function (th) { return th.getAttribute('data-col'); }); }
    function recalc() {
      var any = false;
      $$('tbody tr', table).forEach(function (tr) {
        var vals = {}, n = 0;
        $$('td[data-v]', tr).forEach(function (td) { var v = td.getAttribute('data-v'); if (!vals.hasOwnProperty(v)) { vals[v] = 1; n++; } });
        tr.classList.toggle('diff', n > 1); tr.classList.toggle('same', n <= 1);
        if (n > 1) any = true;
      });
      if (diff) { diff.disabled = !any; if (!any) { diff.checked = false; table.classList.remove('only-diff'); } }
      var cnt = ids().length, c = $('#cmp-count');
      if (c) c.textContent = t('Товаров: {n}', { n: cnt });
      if (!cnt) showEmpty();
      else if (fromUrl && history.replaceState) history.replaceState(null, '', UI.url('/compare/' + ids().join(',') + '/'));
    }
    function showEmpty() {
      box.classList.add('hidden');
      var em = $('#cmp-empty'); if (em) em.classList.remove('hidden');
      if (history.replaceState && location.pathname !== UI.url('/compare/')) history.replaceState(null, '', UI.url('/compare/'));
    }

    if (diff) diff.addEventListener('change', function () { table.classList.toggle('only-diff', diff.checked); });

    table.addEventListener('click', function (e) {
      var b = e.target.closest('[data-cmp-remove]'); if (!b) return;
      e.preventDefault();
      var id = b.getAttribute('data-cmp-remove');
      if (UI.listCookie('cmp').indexOf(id) >= 0) UI.toggleList('cmp', id); else UI.toast(t('Удалено из сравнения'));
      $$('[data-col="' + id + '"]', table).forEach(function (c) { c.remove(); });
      recalc();
    });

    var clr = $('#cmp-clear');
    if (clr) clr.addEventListener('click', function () {
      // из адреса /compare/1,2,3/ — чистим только то, что показано; иначе — весь список
      var shown = ids(), left = fromUrl ? UI.listCookie('cmp').filter(function (x) { return shown.indexOf(x) < 0; }) : [];
      UI.setCookie('cmp', left.join(','));
      UI.renderCounters();
      UI.toast(t('Список сравнения очищен'));
      showEmpty();
    });
  }

  /* ---------- «Повторить заказ»: позиции прошлого заказа → в корзину ----------
     В корзине должно оказаться столько ящиков, сколько было в заказе: товар, которого в корзине меньше, —
     /cart/update/ до нужного количества (по одному запросу, последовательно); уже лежащий в достаточном
     количестве не трогаем — повторное нажатие не удваивает корзину и не упирается в остаток. */
  function bindRepeat() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-repeat]'); if (!b) return;
      e.preventDefault();
      var items; try { items = JSON.parse(b.getAttribute('data-repeat')) || []; } catch (x) { items = []; }
      if (!items.length) return;
      var skipped = +b.getAttribute('data-skipped') || 0, ok = 0, had = 0, bad = 0, err = '';
      var btns = $$('[data-repeat]'); btns.forEach(function (x) { x.disabled = true; x.setAttribute('aria-busy', 'true'); });

      function finish() {
        btns.forEach(function (x) { x.disabled = false; x.removeAttribute('aria-busy'); });
        UI.renderCounters();
        document.dispatchEvent(new CustomEvent('cart:change', { detail: { ok: true } }));
        if (ok || (had && !bad)) {
          var m = ok ? t('Добавлено в корзину: {n} поз.', { n: ok }) : t('Все товары заказа уже в корзине.');
          if (ok && had) m += ' ' + t('Уже были в корзине: {n}.', { n: had });
          if (skipped) m += ' ' + t('Нет в наличии: {n}.', { n: skipped });
          if (bad) m += ' ' + t('Не добавлено: {n}.', { n: bad }) + (err ? ' ' + err : '');
          UI.toast(m);
          UI.open('ui-cart');
        } else UI.toast(err || t('Не удалось добавить товары в корзину'), true);
      }

      UI.getJSON(UI.url('/cart/json/')).catch(function () { return {}; }).then(function (cart) {
        var have = {};
        ((cart && cart.items) || []).forEach(function (c) { have[String(c.id)] = +c.boxes || 0; });
        var i = 0;
        (function next() {
          if (i >= items.length) { finish(); return; }
          var it = items[i++];
          if ((have[String(it.id)] || 0) >= it.boxes) { had++; next(); return; }
          UI.post(UI.url('/cart/update/'), { product_id: it.id, boxes: it.boxes }).then(function (r) {
            if (r && r.ok) ok++; else { bad++; if (r && r.error) err = r.error; }
            next();
          });
        })();
      });
    });
  }

  function init() { bindForms(); bindReview(); bindCompare(); bindRepeat(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

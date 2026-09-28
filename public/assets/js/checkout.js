/* Корзина и оформление заказа (/cart/). Работает поверх window.UI из app.js:
   изменение количества ящиков, удаление, очистка, пересчёт итогов и прогресса бесплатной доставки,
   промокод, маска телефона, проверка формы, отправка заказа (AJAX), «Купить в 1 клик» для всей корзины.
   Тексты — через UI.t() (словарь lang/uk/checkout.php, ключи «js:…»), адреса — через UI.url() (префикс /ua). */
(function () {
  'use strict';
  var UI = window.UI;
  if (!UI) return;
  var $ = UI.$, $$ = UI.$$, t = UI.t;
  var root = $('.co[data-free]');
  var form = $('form[data-checkout]');
  var FREE = root ? (+root.getAttribute('data-free') || 20) : 20;
  var LS_KEY = 'tom_checkout';
  var state = { boxes: 0, subtotal: 0 };      // итоги корзины (товары в наличии)
  var coupon = { code: '', discount: 0 };      // применённый промокод

  function plural(n, a, b, c) {
    var x = n % 10, y = n % 100;
    return t(x === 1 && y !== 11 ? a : (x >= 2 && x <= 4 && (y < 10 || y >= 20) ? b : c));
  }
  function boxesWord(n) { return plural(n, 'ящик', 'ящика', 'ящиков'); }
  function ls(get, val) {
    try {
      if (get) return JSON.parse(localStorage.getItem(LS_KEY) || 'null');
      localStorage.setItem(LS_KEY, JSON.stringify(val));
    } catch (e) { return null; }
  }

  /* ---------- итоги ---------- */
  function setMoney(el, uah) { el.setAttribute('data-uah', Math.round(uah)); el.textContent = UI.money(uah); }
  function renderTotals(r) {
    if (r) {
      state.boxes = +r.count || 0; state.subtotal = +r.total || 0;
      $$('[data-t-boxes]').forEach(function (el) { el.textContent = r.count; });
      $$('[data-t-pairs]').forEach(function (el) { el.textContent = r.pairs; });
      $$('[data-t-lines]').forEach(function (el) { el.textContent = r.lines; });
    }
    var n = $$('tr[data-line]').length, lt = $('[data-t-lines-text]');
    if (lt) lt.textContent = n + ' ' + plural(n, 'позиция', 'позиции', 'позиций');
    $$('.co-t-sum').forEach(function (el) { setMoney(el, state.subtotal); });
    var disc = Math.min(coupon.discount, state.subtotal), line = $('[data-t-disc-line]');
    if (line) {
      line.hidden = !(disc > 0);
      var d = $('[data-t-disc]', line), c = $('[data-t-disc-code]', line);
      if (d) setMoney(d, disc);
      if (c) c.textContent = coupon.code;
    }
    $$('.co-t-total').forEach(function (el) { setMoney(el, state.subtotal - disc); });
    renderShip(state.boxes);
  }
  function renderShip(boxes) {
    var left = Math.max(0, FREE - boxes), txt = $('[data-ship-text]'), bar = $('.co-bar');
    if (txt) txt.innerHTML = left ? t('До бесплатной доставки осталось {left}', { left: '<b>' + left + ' ' + UI.esc(boxesWord(left)) + '</b>' })
      : '<b>' + UI.esc(t('Доставка бесплатная')) + '</b> — ' + UI.esc(t('в заказе от {n} ящ.', { n: FREE }));
    if (bar) { bar.setAttribute('aria-valuenow', Math.min(FREE, boxes)); bar.firstElementChild.style.width = Math.min(100, boxes / FREE * 100) + '%'; }
    shipLine();
  }
  function shipLine() {
    var sel = form && $('input[name=shipping]:checked', form), pickup = sel && sel.getAttribute('data-pickup') === '1';
    $$('[data-t-ship]').forEach(function (el) { el.textContent = pickup || state.boxes >= FREE ? t('бесплатно') : t('по тарифам перевозчика'); });
  }

  /* ---------- позиции ---------- */
  function renderRow(row, item) {
    if (!row || !item) return;
    var inp = $('[data-boxes]', row), sum = $('.c-sum [data-uah]', row), pairs = $('[data-pairs]', row);
    if (inp) { inp.value = item.boxes; inp.setAttribute('data-val', item.boxes); if (item.max) inp.setAttribute('data-max', item.max); }
    if (sum) setMoney(sum, item.sum);
    var np = item.pairs != null ? item.pairs : item.boxes * (+row.getAttribute('data-bq') || 1);
    if (pairs) pairs.textContent = np + ' ' + plural(np, 'пара', 'пары', 'пар');
    var w = $('.co-warn', row);
    if (w && item.max && item.boxes <= item.max) w.parentNode.removeChild(w);
    plusState(row);
  }
  // остаток: «+» на максимуме (data-max = Cart::maxBoxes) — aria-disabled, нажатие покажет подсказку (UI.maxText)
  function plusState(row) {
    var inp = $('[data-boxes]', row), plus = $('[data-step="1"]', row); if (!inp || !plus) return;
    if ((parseInt(inp.value, 10) || 1) >= (+inp.getAttribute('data-max') || 999)) plus.setAttribute('aria-disabled', 'true'); else plus.removeAttribute('aria-disabled');
  }
  function update(row, boxes) {
    var inp = $('[data-boxes]', row), q = $('.co-qty', row);
    q.classList.add('busy');
    return UI.post(UI.url('/cart/update/'), { product_id: row.getAttribute('data-line'), boxes: boxes }).then(function (r) {
      q.classList.remove('busy');
      if (r && r.ok) { renderRow(row, r.item); renderTotals(r); UI.renderCounters(); recheckCoupon(); return; }
      UI.toast((r && r.error) || t('Не удалось изменить количество'), true);
      if (r && r.item) renderRow(row, r.item); else inp.value = inp.getAttribute('data-val');
    });
  }
  function schedule(row, now) {
    var inp = $('[data-boxes]', row), max = +inp.getAttribute('data-max') || 999, v = parseInt(inp.value, 10) || 1;
    if (v > max) { v = max; UI.toast(t('В наличии только {n} {boxes}', { n: max, boxes: boxesWord(max) }), true); }
    v = Math.max(1, v);
    inp.value = v;
    plusState(row);
    clearTimeout(row._t);
    row._t = setTimeout(function () { if (String(v) !== inp.getAttribute('data-val')) update(row, v); }, now ? 0 : 450);
  }
  function removeRow(row) {
    if (!row || row.classList.contains('gone')) return;
    row.classList.add('gone');
    UI.post(UI.url('/cart/remove/'), { product_id: row.getAttribute('data-line') }).then(function (r) {
      if (!r || !r.ok) { row.classList.remove('gone'); UI.toast((r && r.error) || t('Не удалось удалить товар'), true); return; }
      row.parentNode.removeChild(row);
      UI.renderCounters();
      if (!$$('tr[data-line]').length) { location.reload(); return; }
      UI.toast(r.message || t('Товар удалён из корзины'));
      renderTotals(r);
      recheckCoupon();
    });
  }
  function clearCart() {
    UI.dialog({
      title: t('Очистить корзину?'), text: UI.esc(t('Все товары будут удалены из корзины.')),
      html: '<form class="co-qform"><button class="btn btn-o btn-block">' + UI.esc(t('Да, очистить')) + '</button><button type="button" class="btn btn-g btn-block" data-close>' + UI.esc(t('Отмена')) + '</button></form>',
      onSubmit: function () {
        return UI.post(UI.url('/cart/clear/'), {}).then(function (r) {
          if (r && r.ok) { UI.renderCounters(); location.reload(); return true; }
          UI.toast((r && r.error) || t('Не удалось очистить корзину'), true); return false;
        });
      }
    });
  }
  // корзину поменяли в выезжающей панели или на другой вкладке — подтянуть актуальное состояние
  function syncFromServer() {
    UI.getJSON(UI.url('/cart/json/')).then(function (r) {
      var have = $$('tr[data-line]').map(function (x) { return x.getAttribute('data-line'); }).sort().join(',');
      var now = (r.items || []).map(function (i) { return String(i.id); }).sort().join(',');
      if (have !== now) { location.reload(); return; }
      (r.items || []).forEach(function (i) { renderRow($('tr[data-line="' + i.id + '"]'), i); });
      renderTotals(r);
      recheckCoupon();
    }).catch(function () { });
  }

  /* ---------- промокод ---------- */
  var cInput = $('[data-coupon]'), cOk = $('[data-coupon-ok]'), cTimer;
  function couponErr(msg) {
    var box = document.getElementById('e-coupon');
    if (box) { box.textContent = msg || ''; box.hidden = !msg; }
    if (cInput) { cInput.classList.toggle('err', !!msg); if (msg) cInput.setAttribute('aria-invalid', 'true'); else cInput.removeAttribute('aria-invalid'); }
  }
  function couponShow(r) {
    coupon = r && r.ok ? { code: r.code, discount: +r.discount || 0 } : { code: '', discount: 0 };
    if (cOk) {
      cOk.hidden = !coupon.code;
      cOk.innerHTML = coupon.code ? UI.esc(r.message || '') + ' <button type="button" class="co-coupon-x" data-coupon-remove>' + UI.esc(t('Убрать')) + '</button>' : '';
    }
    renderTotals();
  }
  function applyCoupon(quiet) {
    if (!cInput) return;
    var code = cInput.value.trim();
    if (!code) { couponShow(null); if (!quiet) couponErr(t('Введите промокод')); return; }
    var btn = $('[data-coupon-apply]');
    if (btn) btn.disabled = true;
    UI.post(UI.url('/order/'), { coupon_check: 1, coupon: code, phone: form && form.elements.phone ? form.elements.phone.value : '' }).then(function (r) {
      if (btn) btn.disabled = false;
      if (r && r.ok) { cInput.value = r.code; couponErr(''); couponShow(r); return; }
      couponShow(null);
      couponErr((r && r.error) || t('Не удалось проверить промокод'));
    });
  }
  function recheckCoupon() {
    if (!coupon.code) return;
    clearTimeout(cTimer);
    cTimer = setTimeout(function () { applyCoupon(true); }, 300);
  }
  if (cInput) {
    cInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); } });
    cInput.addEventListener('input', function () { if (cInput.classList.contains('err')) couponErr(''); if (coupon.code && cInput.value.trim().toUpperCase() !== coupon.code) couponShow(null); });
  }

  /* ---------- маска телефона: +38 (0XX) XXX-XX-XX; номера других стран (+373…) — как ввели ---------- */
  function maskPhone(v) {
    var raw = String(v || ''), plus = raw.lastIndexOf('+');
    // после подсказки «+38 (0» набрали или вставили номер целиком с «+» (+380 99 …) — считаем с этого «+»
    if (plus > 0) raw = raw.slice(plus);
    var d = raw.replace(/\D/g, '');
    if (!d) return raw.trim() === '+' ? '+' : '';
    if (d.indexOf('38') === 0) d = d.slice(2);
    else if (d.charAt(0) === '8' && d.charAt(1) === '0') d = d.slice(1);
    else if (raw.trim().charAt(0) === '+') return raw;
    if (d.indexOf('00') === 0) d = d.slice(1);         // «0» уже стоит в подсказке «+38 (0», а покупатель набрал номер с нуля
    if (d && d.charAt(0) !== '0') d = '0' + d;
    if (d.length > 10 && d.indexOf('0380') === 0) d = d.slice(3);   // после «+38 (0» набрали «380…» без «+»
    d = d.slice(0, 10);
    var out = '+38 (' + d.slice(0, 3);
    if (d.length > 3) out += ') ' + d.slice(3, 6);
    if (d.length > 6) out += '-' + d.slice(6, 8);
    if (d.length > 8) out += '-' + d.slice(8, 10);
    return out;
  }
  function phoneOk(v) {
    var d = String(v || '').replace(/\D/g, '');
    return /^\+?38/.test(String(v).trim()) || d.indexOf('0') === 0 ? /^(38)?0[1-9]\d{8}$/.test(d) : d.length >= 11 && d.length <= 13;
  }
  document.addEventListener('input', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('[data-phone]')) return;
    if (e.inputType && e.inputType.indexOf('delete') === 0) return;
    var m = maskPhone(i.value);
    if (m !== i.value) i.value = m;
  });
  document.addEventListener('focusin', function (e) { var i = e.target; if (i.matches && i.matches('[data-phone]') && !i.value) i.value = '+38 (0'; });
  document.addEventListener('focusout', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('[data-phone]')) return;
    i.value = /^\+?38 ?\(?0?$/.test(i.value.trim()) ? '' : maskPhone(i.value);
  });

  /* ---------- форма оформления ---------- */
  function field(name) { return form.elements[name]; }
  function setErr(name, msg) {
    var box = document.getElementById('e-' + name), el = field(name), input = el && el.length && !el.tagName ? null : el;
    if (box) { box.textContent = msg || ''; box.hidden = !msg; }
    if (input && input.classList) { input.classList.toggle('err', !!msg); if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid'); }
  }
  function formError(msg) { var b = $('[data-form-error]', form); if (b) { b.textContent = msg || ''; b.hidden = !msg; } }
  function checked(name) { var r = $('input[name="' + name + '"]:checked', form); return r ? r.value : ''; }
  function currentShip() { return $('input[name=shipping]:checked', form); }

  function validate() {
    var e = {}, v = function (n) { var f = field(n); return f ? String(f.value || '').trim() : ''; };
    var ship = currentShip(), pickup = ship && ship.getAttribute('data-pickup') === '1';
    if (!v('name')) e.name = t('Укажите имя'); else if (v('name').length < 2) e.name = t('Имя слишком короткое');
    if (!v('phone')) e.phone = t('Укажите телефон'); else if (!phoneOk(v('phone'))) e.phone = t('Проверьте номер телефона: +38 (0XX) XXX-XX-XX');
    if (v('email') && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v('email'))) e.email = t('Проверьте e-mail — например, name@gmail.com');
    if (!ship) e.shipping = t('Выберите способ доставки');
    if (ship && !pickup && !v('city')) e.city = t('Укажите город или населённый пункт');
    if (ship && ship.getAttribute('data-req') === '1' && !v('address')) {
      e.address = ship.getAttribute('data-kind') === 'post' ? t('Укажите адрес доставки') : t('Укажите номер отделения или склада');
    }
    if (!checked('payment')) e.payment = t('Выберите способ оплаты');
    if (!field('agree').checked) e.agree = t('Подтвердите согласие с условиями, чтобы оформить заказ');
    return e;
  }
  function showErrors(errs) {
    ['name', 'phone', 'email', 'city', 'address', 'shipping', 'payment', 'agree'].forEach(function (k) { setErr(k, errs[k] || ''); });
    if (errs.coupon) { couponErr(errs.coupon); var det = cInput && cInput.closest('details'); if (det) det.open = true; }
    var first = Object.keys(errs).filter(function (k) { return k !== '_form'; })[0];
    if (first) {
      var el = first === 'coupon' ? cInput : field(first), target = el && el.length && !el.tagName ? el[0] : el;
      if (target && target.focus) { target.focus({ preventScroll: true }); (target.closest('.field,.co-fs,.co-coupon') || target).scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }
  }

  var addrByMode = {};
  function applyShipping(init) {
    var sel = currentShip();
    if (!sel) return;
    var pickup = sel.getAttribute('data-pickup') === '1', req = sel.getAttribute('data-req') === '1', mode = pickup ? 'p' : 's';
    var geo = $('[data-geo]', form), lab = $('[data-field-label]', form), star = $('[data-field-req]', form), addr = field('address');
    if (geo) geo.hidden = pickup;
    if (lab) lab.textContent = sel.getAttribute('data-field') || t('Адрес');
    if (star) star.hidden = !req;
    if (addr) {
      addr.placeholder = sel.getAttribute('data-ph') || '';
      if (!init && form._mode && form._mode !== mode) { addrByMode[form._mode] = addr.value; addr.value = addrByMode[mode] || ''; }
    }
    form._mode = mode;
    if (!init) { setErr('shipping', ''); if (pickup) setErr('city', ''); if (!req) setErr('address', ''); }
    shipLine();
  }
  function saveLocal() {
    var d = {};
    ['name', 'phone', 'email', 'region', 'city', 'address'].forEach(function (k) { if (field(k)) d[k] = field(k).value; });
    d.shipping = checked('shipping'); d.payment = checked('payment');
    ls(false, d);
  }
  function restoreLocal() {
    // только гостю и только на «чистой» форме (после ошибки сервера поля уже заполнены)
    if (form.getAttribute('data-guest') !== '1' || $('.errtxt:not([hidden])', form) || !$('[data-form-error][hidden]', form)) return;
    var d = ls(true);
    if (!d) return;
    ['name', 'phone', 'email', 'region', 'city', 'address'].forEach(function (k) { var f = field(k); if (f && !f.value && d[k]) f.value = d[k]; });
    ['shipping', 'payment'].forEach(function (k) {
      if (!d[k]) return;
      var r = $('input[name="' + k + '"][value="' + String(d[k]).replace(/["\\]/g, '') + '"]', form);
      if (r) r.checked = true;
    });
  }
  function busyButtons(on) {
    $$('[data-submit]').forEach(function (b) {
      if (!b._t) b._t = b.textContent;
      b.disabled = on; b.textContent = on ? t('Оформляем заказ…') : b._t;
    });
  }

  if (form) {
    restoreLocal();
    applyShipping(true);
    form.addEventListener('change', function (e) {
      var el = e.target;
      if (el.name === 'shipping') applyShipping(false);
      else if (el.name === 'payment') setErr('payment', '');
      else if (el.name === 'agree' && el.checked) setErr('agree', '');
    });
    form.addEventListener('input', function (e) { var n = e.target.name; if (n && e.target.classList.contains('err')) setErr(n, ''); });
    var sending = false;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (sending) return;
      formError('');
      var errs = validate();
      showErrors(errs);
      if (Object.keys(errs).length) { formError(t('Проверьте выделенные поля формы')); return; }
      var data = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.name === '_csrf' || el.disabled) return;
        if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
        data[el.name] = el.value;
      });
      sending = true; busyButtons(true);
      UI.post(UI.url('/order/'), data).then(function (r) {
        if (r && r.ok && r.redirect) { saveLocal(); location.href = r.redirect; return; }
        sending = false; busyButtons(false);
        if (r && r.errors) showErrors(r.errors);
        formError((r && r.error) || t('Не удалось оформить заказ. Попробуйте ещё раз.'));
        if (!(r && r.errors && Object.keys(r.errors).length)) { var b = $('[data-form-error]', form); if (b) b.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      });
    });
  }

  /* ---------- «Купить в 1 клик» для всей корзины ---------- */
  function quickCart() {
    var n = form ? field('name').value : '', p = form ? field('phone').value : '';
    UI.dialog({
      title: t('Купить в 1 клик'),
      text: UI.esc(t('Оставьте телефон — менеджер перезвонит, уточнит доставку и оплату и оформит заказ.')),
      html: '<form class="co-qform" novalidate>'
        + '<label class="visually-hidden" for="q-name">' + UI.esc(t('Ваше имя')) + '</label><input class="input" id="q-name" name="name" placeholder="' + UI.esc(t('Ваше имя')) + '" autocomplete="name" maxlength="100" value="' + UI.esc(n) + '">'
        + '<label class="visually-hidden" for="q-phone">' + UI.esc(t('Телефон')) + '</label><input class="input" id="q-phone" name="phone" type="tel" inputmode="tel" data-phone placeholder="+38 (0__) ___-__-__" autocomplete="tel" maxlength="40" value="' + UI.esc(p) + '">'
        + '<input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">'
        + '<div class="errtxt" role="alert" hidden></div><button class="btn btn-o btn-block">' + UI.esc(t('Отправить заказ')) + '</button></form>',
      onSubmit: function (f) {
        var err = $('.errtxt', f), name = f.elements.name.value.trim(), phone = f.elements.phone.value.trim(), msg = '';
        if (name && name.length < 2) msg = t('Имя слишком короткое'); else if (!phone) msg = t('Укажите телефон'); else if (!phoneOk(phone)) msg = t('Проверьте номер телефона: +38 (0XX) XXX-XX-XX');
        err.textContent = msg; err.hidden = !msg;
        if (msg) return false;
        var btn = $('button', f); btn.disabled = true;
        return UI.post(UI.url('/quickorder/'), { name: name, phone: phone, website: f.elements.website.value }).then(function (r) {
          btn.disabled = false;
          if (r && r.ok) { UI.toast(r.message || t('Заказ оформлен')); if (r.redirect) location.href = r.redirect; return true; }
          err.textContent = (r && r.error) || t('Не удалось отправить заказ'); err.hidden = false;
          return false;
        });
      }
    });
  }

  /* ---------- события ---------- */
  document.addEventListener('click', function (e) {
    var st = e.target.closest('[data-step]');
    if (st) {
      var row = st.closest('tr[data-line]');
      if (!row) return;
      var inp = $('[data-boxes]', row), mx = +inp.getAttribute('data-max') || 999, want = (parseInt(inp.value, 10) || 1) + (+st.getAttribute('data-step'));
      if (want > mx && +st.getAttribute('data-step') > 0) { UI.toast(UI.maxText(mx), true); return; }   // больше остатка — не отправляем
      inp.value = Math.max(1, want);
      schedule(row);
      return;
    }
    var rm = e.target.closest('[data-remove]');
    if (rm) { removeRow(rm.closest('tr[data-line]')); return; }
    if (e.target.closest('[data-cart-clear]')) { clearCart(); return; }
    if (e.target.closest('[data-quick-cart]')) { quickCart(); return; }
    if (e.target.closest('[data-coupon-apply]')) { applyCoupon(); return; }
    if (e.target.closest('[data-coupon-remove]')) { if (cInput) { cInput.value = ''; cInput.focus(); } couponErr(''); couponShow(null); }
  });
  document.addEventListener('change', function (e) {
    var inp = e.target.closest && e.target.closest('[data-boxes]');
    if (inp) schedule(inp.closest('tr[data-line]'), true);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-boxes]')) { e.preventDefault(); e.target.blur(); }
  });
  document.addEventListener('cart:change', syncFromServer);

  // начальное состояние из разметки
  $$('[data-boxes]').forEach(function (i) { i.setAttribute('data-val', i.value); });
  var b0 = $('[data-t-boxes]'), s0 = $('.co-t-sum');
  state.boxes = b0 ? +b0.textContent || 0 : 0;
  state.subtotal = s0 ? +s0.getAttribute('data-uah') || 0 : 0;
  shipLine();
  // промокод, введённый до ошибки отправки формы без JS, — сразу проверить
  if (cInput && cInput.value.trim() && !cInput.classList.contains('err')) applyCoupon(true);
})();

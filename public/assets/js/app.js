/* Tomobuv — клиентский скрипт витрины (без библиотек).
   Страницы витрины кэшируются целиком и одинаковы для всех, поэтому всё личное
   (корзина, избранное, сравнение, валюта, «Кабинет») дорисовывается здесь из cookie.
   Публичный API для скриптов страниц: window.UI (post, toast, dialog, open, close, money, refreshPrices). */
(function () {
  'use strict';
  var CFG = window.TOM || { rates: { UAH: 1 }, freeBoxes: 20 };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  /* Перевод фразы (ключи словаря «js:Русская фраза» передаёт layout в TOM.i18n) и адрес с префиксом языка (/ua) */
  function t(s, vars) {
    var r = (CFG.i18n && CFG.i18n['js:' + s]) || s;
    if (vars) Object.keys(vars).forEach(function (k) { r = r.split('{' + k + '}').join(vars[k]); });
    return r;
  }
  function url(p) { return p && p.charAt(0) === '/' && p.indexOf('/ua/') !== 0 ? (CFG.prefix || '') + p : p; }

  /* ---------- cookie ---------- */
  function getCookie(n) {
    var m = document.cookie.match('(?:^|; )' + n.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setCookie(n, v, days) {
    var d = new Date(); d.setTime(d.getTime() + (days || 365) * 864e5);
    document.cookie = n + '=' + encodeURIComponent(v) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }
  function listCookie(n) { var v = getCookie(n); return v ? v.split(',').filter(Boolean) : []; }

  /* ---------- CSRF (double submit cookie) ---------- */
  function csrf() {
    var t = getCookie('csrf');
    if (!/^[a-f0-9]{32}$/.test(t)) {
      var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
      t = Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      setCookie('csrf', t, 30);
    }
    return t;
  }

  function post(url, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    body.append('_csrf', csrf());
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf(), 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: t('Ошибка сервера ({code})', { code: r.status }) }; }); })
      .catch(function () { return { ok: false, error: t('Нет связи с сервером. Проверьте интернет.') }; });
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  }

  /* ---------- валюта ---------- */
  var SIGN = { UAH: ' грн.', USD: '$', EUR: '€' };
  function currency() { var c = getCookie('cur'); return CFG.rates[c] ? c : 'UAH'; }
  function money(uah) {
    var c = currency(), v = uah / (CFG.rates[c] || 1);
    if (c === 'UAH') return String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + SIGN.UAH;
    return SIGN[c] + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }
  function refreshPrices(root) {
    var c = currency();
    $$('[data-uah]', root).forEach(function (el) { el.textContent = money(+el.getAttribute('data-uah')); });
    $$('#ui-cur [data-cur]').forEach(function (b) { var on = b.getAttribute('data-cur') === c; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
  }

  /* ---------- уведомления ---------- */
  function toast(msg, isError) {
    var t = $('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite'); document.body.appendChild(t); }
    t.textContent = msg; t.style.background = isError ? '#c0392b' : '';
    t.classList.add('show'); clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 2600);
  }

  /* ---------- шторки и модалки ---------- */
  var lastFocus = null;
  function open(id) {
    var el = document.getElementById(id); if (!el) return;
    lastFocus = document.activeElement;
    el.classList.add('show'); el.setAttribute('aria-hidden', 'false'); el.removeAttribute('inert');
    var ov = $('#ui-ov'); if (ov) ov.classList.add('show');
    var f = el.querySelector('input:not([type=hidden]):not(.hidden),button:not([data-close]),a'); if (f) setTimeout(function () { f.focus(); }, 60);
    if (id === 'ui-cart') loadCart();
  }
  function close() {
    // inert — закрытая шторка/модалка не ловит фокус с клавиатуры (aria-hidden без inert — ошибка доступности)
    $$('.drawer.show,.modal.show,.filters-wrap.show').forEach(function (x) { x.classList.remove('show'); x.setAttribute('aria-hidden', 'true'); x.setAttribute('inert', ''); });
    var ov = $('#ui-ov'); if (ov) ov.classList.remove('show');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* UI.dialog({title, text, html, onSubmit(form) → false чтобы не закрывать}) */
  function dialog(o) {
    var d = $('#ui-dialog'); if (!d) return;
    $('.dlg-body', d).innerHTML = '<h3>' + esc(o.title) + '</h3>' + (o.text ? '<p>' + o.text + '</p>' : '') + (o.html || '');
    var f = $('form', d);
    if (f && o.onSubmit) f.onsubmit = function (e) { e.preventDefault(); var r = o.onSubmit(f); if (r && r.then) r.then(function (ok) { if (ok !== false) close(); }); else if (r !== false) close(); };
    open('ui-dialog');
  }

  /* ---------- счётчики из cookie ---------- */
  function cartState() { var v = getCookie('cart').split(':'); return { boxes: +v[0] || 0, sum: +v[1] || 0 }; }
  function renderCounters() {
    var c = cartState(), n = { cart: c.boxes, fav: listCookie('fav').length, cmp: listCookie('cmp').length };
    $$('[data-count]').forEach(function (el) { var v = n[el.getAttribute('data-count')] || 0; el.textContent = v ? v : ''; if (v) el.removeAttribute('data-z'); else el.setAttribute('data-z', ''); });
    $$('[data-cart-total]').forEach(function (el) { el.textContent = money(c.sum); });
    renderShip(c.boxes);
    var fav = listCookie('fav'), cmp = listCookie('cmp');
    $$('[data-id]').forEach(function (host) {
      var id = host.getAttribute('data-id');
      $$('[data-act=fav]', host).forEach(function (b) { b.classList.toggle('on', fav.indexOf(id) >= 0); });
      $$('[data-act=cmp]', host).forEach(function (b) { b.classList.toggle('on', cmp.indexOf(id) >= 0); });
    });
    if (getCookie('auth')) $$('[data-auth-label]').forEach(function (el) { el.textContent = t('Кабинет'); });
  }
  function renderShip(boxes) {
    var free = CFG.freeBoxes || 20, left = Math.max(0, free - boxes);
    $$('[data-ship]').forEach(function (el) {
      el.innerHTML = '<div style="font-size:14px;margin-bottom:6px">' + (left ? t('До бесплатной доставки: <b>{n} ящ.</b>', { n: left }) : t('<b>Доставка бесплатная</b> — в заказе {n}+ ящиков', { n: free })) + '</div>'
        + '<div style="height:6px;background:rgba(127,127,127,.2);border-radius:3px;overflow:hidden"><div style="height:100%;width:' + Math.min(100, boxes / free * 100) + '%;background:var(--green);transition:width .3s"></div></div>';
      el.style.marginBottom = '14px';
    });
  }

  /* ---------- корзина ---------- */
  function afterCart(r) {
    if (!r || !r.ok) { toast((r && r.error) || t('Не удалось изменить корзину'), true); return r; }
    renderCounters();
    if (r.message) toast(r.message);
    if ($('#ui-cart.show')) loadCart();
    document.dispatchEvent(new CustomEvent('cart:change', { detail: r }));
    return r;
  }
  function addToCart(id, boxes) { return post('/cart/add/', { product_id: id, boxes: boxes || 1 }).then(afterCart); }
  function setCartQty(id, boxes) { return post('/cart/update/', { product_id: id, boxes: boxes }).then(afterCart); }
  function loadCart() {
    var bd = $('#ui-cart .bd'); if (!bd) return;
    getJSON('/cart/json/').then(function (r) {
      if (!r.items || !r.items.length) { bd.innerHTML = '<div class="empty">' + t('Корзина пуста') + '<br><a class="link" href="' + url('/category/dyetskaya-obuv/') + '">' + t('Перейти в каталог') + '</a></div>'; return; }
      bd.innerHTML = r.items.map(function (i) {
        return '<div class="ci" data-cid="' + i.id + '"><a href="' + esc(url(i.url)) + '"><img src="' + esc(i.img) + '" alt="" width="64" height="64"></a><div class="t"><b>' + esc(i.name) + '</b><small>' + t('{b} ящ. × {p} пар', { b: i.boxes, p: i.box_qty }) + ' · <span data-uah="' + i.sum + '">' + money(i.sum) + '</span></small></div>'
          + '<div class="qty"><button data-cq="-1" aria-label="' + t('Меньше') + '">−</button><input value="' + i.boxes + '" readonly aria-label="' + t('Ящиков') + '"><button data-cq="1" aria-label="' + t('Больше') + '">+</button></div></div>';
      }).join('');
    }).catch(function () { bd.innerHTML = '<div class="empty">' + t('Не удалось загрузить корзину') + '</div>'; });
  }

  /* ---------- избранное / сравнение (cookie) ---------- */
  function toggleList(name, id) {
    var list = listCookie(name), i = list.indexOf(String(id)), max = name === 'cmp' ? 20 : 200;
    if (i >= 0) list.splice(i, 1); else { list.unshift(String(id)); list = list.slice(0, max); }
    setCookie(name, list.join(','));
    renderCounters();
    toast(t(name === 'fav' ? (i >= 0 ? 'Удалено из избранного' : 'Добавлено в избранное') : (i >= 0 ? 'Удалено из сравнения' : 'Добавлено к сравнению')));
    return i < 0;
  }

  /* ---------- подсказки поиска ---------- */
  function bindSearch() {
    var f = $('#ui-search'); if (!f) return;
    var inp = $('input', f), sg = $('.suggest', f), timer, lastQ = '';
    inp.addEventListener('input', function () {
      clearTimeout(timer);
      var q = inp.value.trim();
      if (q.length < 2) { sg.classList.remove('show'); return; }
      timer = setTimeout(function () {
        lastQ = q;
        getJSON(url('/search/suggest/?q=' + encodeURIComponent(q))).then(function (r) {
          if (q !== lastQ) return;
          var items = r.items || [];
          sg.innerHTML = items.map(function (p) {
            return '<a href="' + esc(url(p.url)) + '"><img src="' + esc(p.img) + '" alt="" width="44" height="44"><span><b>' + esc(p.name) + '</b><br><small>' + esc([p.brand, p.size ? t('р.') + ' ' + p.size : ''].filter(Boolean).join(' · ')) + ' · ' + money(p.price) + '/' + t('пара') + '</small></span></a>';
          }).join('') + (items.length ? '' : '<div style="padding:12px;color:var(--muted)">' + t('Ничего не найдено') + '</div>')
            + '<a class="all" href="' + url('/search/?query=' + encodeURIComponent(q)) + '">' + t('Посмотреть все результаты') + (r.total ? ' (' + r.total + ')' : '') + '</a>';
          sg.classList.add('show');
        }).catch(function () { });
      }, 220);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('#ui-search')) sg.classList.remove('show'); });
  }

  /* ---------- формы заявок (обратный звонок, подписка, контакты) ---------- */
  function validPhone(v) { return v.replace(/\D/g, '').length >= 10; }
  function bindRequests() {
    document.addEventListener('submit', function (e) {
      var f = e.target.closest('form[data-request]'); if (!f) return;
      e.preventDefault();
      var data = {}, ok = true;
      $$('input,textarea,select', f).forEach(function (i) {
        if (!i.name) return;
        i.classList.remove('err');
        if (i.required && !i.value.trim()) { i.classList.add('err'); ok = false; }
        if (i.type === 'tel' && i.value && !validPhone(i.value)) { i.classList.add('err'); ok = false; }
        if (i.type === 'email' && i.value && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(i.value)) { i.classList.add('err'); ok = false; }
        data[i.name] = i.value;
      });
      if (!ok) { var bad = $('.err', f); if (bad) bad.focus(); return; }
      var btn = $('button', f); if (btn) btn.disabled = true;
      post(url('/request/' + f.getAttribute('data-request') + '/'), data).then(function (r) {
        if (btn) btn.disabled = false;
        if (r.ok) { f.reset(); close(); toast(r.message || t('Спасибо! Заявка отправлена')); }
        else toast(r.error || t('Не удалось отправить'), true);
      });
    });
  }

  /* ---------- главная: слайдер и вкладки ---------- */
  function bindSlider() {
    var sl = $('#slider'); if (!sl) return;
    var slides = $$('.slide', sl), dots = $$('.dots button', sl), cur = 0, t;
    if (slides.length < 2) return;
    function go(i) {
      cur = i;
      slides.forEach(function (s, k) { s.classList.toggle('on', k === i); s.setAttribute('aria-hidden', k === i ? 'false' : 'true'); $$('a', s).forEach(function (a) { a.tabIndex = k === i ? 0 : -1; }); });
      dots.forEach(function (d, k) { d.classList.toggle('on', k === i); });
    }
    function auto() { clearInterval(t); t = setInterval(function () { go((cur + 1) % slides.length); }, 6000); }
    dots.forEach(function (d) { d.addEventListener('click', function () { go(+d.getAttribute('data-i')); auto(); }); });
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) auto();
  }
  function bindTabs() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) return;
      var box = b.parentElement;
      $$('[data-tab]', box).forEach(function (x) {
        var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-selected', on);
        var p = document.getElementById(x.getAttribute('data-tab')); if (p) p.classList.toggle('hidden', !on);
      });
    });
  }

  /* ---------- мега-меню ---------- */
  function bindMega() {
    var mega = $('#ui-mega'), btn = $('#ui-catbtn'); if (!mega || !btn) return;
    function show(i) {
      $$('.l a', mega).forEach(function (a) { a.classList.toggle('on', a.getAttribute('data-panel') === i); });
      $$('.r', mega).forEach(function (p) { p.classList.toggle('hidden', p.getAttribute('data-panel') !== i); });
    }
    $$('.l a', mega).forEach(function (a) {
      a.addEventListener('mouseenter', function () { show(a.getAttribute('data-panel')); });
      a.addEventListener('focus', function () { show(a.getAttribute('data-panel')); });
    });
    btn.addEventListener('click', function () { var on = mega.classList.toggle('show'); btn.setAttribute('aria-expanded', on); });
    document.addEventListener('click', function (e) { if (!e.target.closest('#ui-mega,#ui-catbtn')) { mega.classList.remove('show'); btn.setAttribute('aria-expanded', 'false'); } });
  }

  /* ---------- общие обработчики ---------- */
  function bind() {
    document.addEventListener('click', function (e) {
      var cur = e.target.closest('[data-cur]');
      if (cur) { setCookie('cur', cur.getAttribute('data-cur')); refreshPrices(); renderCounters(); return; }

      var o = e.target.closest('[data-open]');
      if (o) { e.preventDefault(); open(o.getAttribute('data-open')); return; }
      if (e.target.closest('[data-close]') || e.target.id === 'ui-ov') { close(); return; }

      var cq = e.target.closest('[data-cq]');
      if (cq) {
        var row = cq.closest('[data-cid]'), inp = $('input', row), n = (+inp.value || 0) + (+cq.getAttribute('data-cq'));
        setCartQty(row.getAttribute('data-cid'), Math.max(0, n)); return;
      }

      var b = e.target.closest('[data-act],[data-q]'); if (!b) return;
      var host = b.closest('[data-id]'); if (!host) return;
      var id = host.getAttribute('data-id'), qin = $('.qty input', host);
      if (b.hasAttribute('data-q')) {
        e.preventDefault();
        if (qin) qin.value = Math.max(1, Math.min(999, (parseInt(qin.value, 10) || 1) + (+b.getAttribute('data-q'))));
        host.dispatchEvent(new CustomEvent('qtychange', { bubbles: true }));
        return;
      }
      var act = b.getAttribute('data-act');
      if (act === 'cart') { e.preventDefault(); b.disabled = true; addToCart(id, qin ? Math.max(1, parseInt(qin.value, 10) || 1) : 1).then(function () { b.disabled = false; }); }
      else if (act === 'fav' || act === 'cmp') { e.preventDefault(); toggleList(act, id); }
    });
    document.addEventListener('change', function (e) {
      var i = e.target.closest('.qty input'); if (i && !i.readOnly) i.value = Math.max(1, Math.min(999, parseInt(i.value, 10) || 1));
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

    var tt = $('#ui-totop');
    if (tt) {
      addEventListener('scroll', function () { tt.classList.toggle('show', scrollY > 600); }, { passive: true });
      tt.addEventListener('click', function () { scrollTo({ top: 0, behavior: 'smooth' }); });
    }
    // просмотренные товары (страница товара ставит data-viewed)
    var v = $('[data-viewed]');
    if (v) {
      var id = v.getAttribute('data-viewed'), list = listCookie('viewed').filter(function (x) { return x !== id; });
      list.unshift(id); setCookie('viewed', list.slice(0, 30).join(','));
    }
  }

  window.UI = { $: $, $$: $$, t: t, url: url, post: post, getJSON: getJSON, toast: toast, dialog: dialog, open: open, close: close, money: money,
    refreshPrices: refreshPrices, renderCounters: renderCounters, addToCart: addToCart, setCartQty: setCartQty, loadCart: loadCart,
    toggleList: toggleList, listCookie: listCookie, getCookie: getCookie, setCookie: setCookie, csrf: csrf, esc: esc, validPhone: validPhone };

  function init() {
    csrf(); bind(); bindSearch(); bindRequests(); bindSlider(); bindTabs(); bindMega();
    renderCounters();
    if (currency() !== 'UAH') refreshPrices(); else refreshPrices($('#ui-cur') ? $('#ui-cur').parentNode : null);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

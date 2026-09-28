/* Страница товара: галерея (миниатюры, свайп, лайтбокс), пересчёт суммы по ящикам,
   «Купить в 1 клик», вкладки, отзывы (AJAX), липкая панель на мобильном, «Вы недавно смотрели».
   Общие функции — window.UI из app.js (страница кэшируется, всё личное — здесь из cookie). */
(function () {
  'use strict';
  var UI = window.UI;
  if (!UI) return;
  var $ = UI.$, $$ = UI.$$;

  function plural(n, one, few, many) {
    var a = n % 10, b = n % 100;
    if (a === 1 && b !== 11) return one;
    if (a >= 2 && a <= 4 && (b < 10 || b >= 20)) return few;
    return many;
  }
  // «3 ящика», «24 пары» — ключи словаря целыми фразами (у украинского свои окончания)
  function boxesTxt(n) { return UI.t(plural(n, '{n} ящик', '{n} ящика', '{n} ящиков'), { n: n }); }
  function pairsTxt(n) { return UI.t(plural(n, '{n} пара', '{n} пары', '{n} пар'), { n: n }); }
  function clamp(v, max) { v = parseInt(v, 10); return Math.max(1, Math.min(max || 999, isNaN(v) ? 1 : v)); }

  /* ---------- покупка: ящики → пары → сумма ---------- */
  function initBuy() {
    var buy = $('#pp-buy'); if (!buy) return;
    var id = buy.getAttribute('data-id'), box = +buy.getAttribute('data-box') || 1, pair = +buy.getAttribute('data-pair') || 0;
    var qty = $('#pp-qty', buy), bar = $('#pp-bar');
    // остаток: не больше data-max ящиков (Cart::maxBoxes); UI.syncQty ставит границы, «+» и подсказку «Доступно не больше N ящиков»
    var max = UI.qtyMax(buy), limit = function () { UI.syncQty(buy); };

    function boxes() { return clamp(qty.value, max); }
    function recalc() {
      var n = boxes(), pairs = n * box, sum = pairs * pair;
      $$('[data-pp-boxes]').forEach(function (el) { el.textContent = boxesTxt(n); });
      $$('[data-pp-pairs]').forEach(function (el) { el.textContent = pairsTxt(pairs); });
      $$('[data-pp-sum]').forEach(function (el) { el.setAttribute('data-uah', sum); el.textContent = UI.money(sum); });
      if (bar) {
        // 1 ящик — «За ящик · 8 пар»; больше — «Итого · 3 ящ. · 24 пары» и сумма за все ящики
        var s = $('.pp-bar-sum', bar), l = $('[data-pp-bar-l]', bar);
        if (s) { s.setAttribute('data-uah', sum); s.textContent = UI.money(sum); }
        if (l) l.textContent = n > 1 ? UI.t('Итого') + ' · ' + UI.t('{n} ящ.', { n: n }) + ' · ' + pairsTxt(pairs) : UI.t('За ящик') + ' · ' + pairsTxt(box);
      }
    }
    buy.addEventListener('qtychange', recalc);
    qty.addEventListener('input', function () { qty.value = qty.value.replace(/\D/g, '').slice(0, 3); if (qty.value !== '') { limit(); recalc(); } });
    qty.addEventListener('change', function () { limit(); recalc(); });
    qty.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); qty.value = boxes() + (e.key === 'ArrowUp' ? 1 : -1); limit(); recalc(); }
      if (e.key === 'Enter') { e.preventDefault(); limit(); recalc(); }
    });
    recalc();

    // липкая панель (мобильный): «В корзину» с выбранным количеством
    if (bar) {
      var bb = $('[data-pp-cart]', bar);
      if (bb) bb.addEventListener('click', function () {
        bb.disabled = true;
        UI.addToCart(id, boxes()).then(function () { bb.disabled = false; });
      });
      // панель появляется, когда кнопка «В корзину» ушла вверх за экран (пока блок покупки ниже —
      // панель не нужна и не закрывает миниатюры галереи). Проверка по scroll, а не IntersectionObserver:
      // тот не срабатывает при прыжке сразу через кнопку (якорь, «Наверх»).
      var add = $('#pp-add'), shown = null, tick = false;
      var sync = function () {
        tick = false;
        var on = !add || add.getBoundingClientRect().bottom < 0;
        if (on === shown) return;
        shown = on;
        bar.classList.toggle('show', on);
        if (on) bar.removeAttribute('inert'); else bar.setAttribute('inert', '');   // спрятанная панель не ловит фокус
        document.body.classList.toggle('pp-bar-on', on);
      };
      var later = function () { if (!tick) { tick = true; requestAnimationFrame(sync); } };
      addEventListener('scroll', later, { passive: true });
      addEventListener('resize', later);
      sync();
    }

    // «Купить в 1 клик» → заказ через /quickorder/ (эндпоинт раздела оформления): имя + телефон
    var qb = $('[data-pp-quick]', buy);
    if (qb) qb.addEventListener('click', function () {
      var n = boxes(), pairs = n * box, name = buy.getAttribute('data-name') || '';
      var saved = ''; try { saved = localStorage.getItem('qo_name') || ''; } catch (e) { }
      UI.dialog({
        title: UI.t('Купить в 1 клик'),
        text: UI.esc(UI.t('Оставьте имя и телефон — менеджер перезвонит, уточнит детали и оформит заказ.')),
        html: '<div class="pp-qo-sum"><b>' + UI.esc(name.trim()) + '</b><span>' + boxesTxt(n) + ' = ' + pairsTxt(pairs)
          + ' = <b>' + UI.money(pairs * pair) + '</b></span></div>'
          + '<form class="pp-qo" novalidate>'
          + '<label class="visually-hidden" for="qo-name">' + UI.t('Ваше имя') + '</label><input class="input" id="qo-name" name="name" required placeholder="' + UI.esc(UI.t('Ваше имя')) + '" autocomplete="name" maxlength="100" value="' + UI.esc(saved) + '">'
          + '<label class="visually-hidden" for="qo-phone">' + UI.t('Телефон') + '</label><input class="input" id="qo-phone" name="phone" type="tel" required placeholder="+38 (0__) ___-__-__" autocomplete="tel" maxlength="32">'
          + '<div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>'
          + '<button class="btn btn-o btn-block" type="submit">' + UI.t('Отправить заказ') + '</button></form>',
        onSubmit: function (f) {
          var ph = f.elements.phone, nm = f.elements.name, btn = $('button[type=submit]', f);
          ph.classList.remove('err'); nm.classList.remove('err');
          if (nm.value.trim().length < 2) { nm.classList.add('err'); nm.focus(); UI.toast(UI.t('Укажите ваше имя'), true); return false; }
          if (!UI.validPhone(ph.value)) { ph.classList.add('err'); ph.focus(); UI.toast(UI.t('Укажите телефон полностью'), true); return false; }
          btn.disabled = true;
          return UI.post(UI.url('/quickorder/'), { product_id: id, boxes: n, name: nm.value.trim(), phone: ph.value.trim(), website: f.elements.website.value })
            .then(function (r) {
              btn.disabled = false;
              if (!r || !r.ok) { UI.toast((r && r.error) || UI.t('Не удалось отправить заказ'), true); return false; }
              try { localStorage.setItem('qo_name', nm.value.trim()); } catch (e) { }
              UI.dialog({ title: UI.t('Спасибо, заказ принят!'),
                text: UI.esc(r.message || UI.t('Номер заказа: {n}. Менеджер свяжется с вами в ближайшее время.', { n: r.number || r.order_id || '—' })),
                html: '<button type="button" class="btn btn-b btn-block" data-close>' + UI.t('Хорошо') + '</button>' });
              return false;   // диалог уже заменён сообщением
            });
        }
      });
    });
  }

  /* ---------- вкладки: клавиатура и переход к отзывам ---------- */
  function showTab(id) {
    var b = document.querySelector('.tabline [data-tab="' + id + '"]');
    if (b && !b.classList.contains('on')) b.click();
    return b;
  }
  function initTabs() {
    var tl = $('.pp .tabline'); if (!tl) return;
    tl.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var tabs = $$('[role=tab]', tl), i = tabs.indexOf(document.activeElement); if (i < 0) return;
      var t = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      t.focus(); t.click();
    });
    function toReviews(e) {
      if (e) e.preventDefault();
      showTab('t-rev');
      var info = $('#pp-info');
      if (info) info.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    $$('[data-pp-reviews]').forEach(function (a) { a.addEventListener('click', toReviews); });
    $$('[data-pp-specs]').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); showTab('t-spec'); var info = $('#pp-info'); if (info) info.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    });
    if (location.hash === '#reviews') setTimeout(toReviews, 50);
  }

  /* ---------- отзыв: отправка без перезагрузки ---------- */
  function initReviewForm() {
    var f = $('#rv-form'); if (!f) return;
    if (f.elements._csrf) f.elements._csrf.value = UI.csrf();
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var ok = true, rate = $('input[name=rate]:checked', f), name = f.elements.name, text = f.elements.text;
      [name, text].forEach(function (i) { i.classList.remove('err'); });
      $('.rv-rate', f).classList.remove('err');
      if (!rate) { $('.rv-rate', f).classList.add('err'); ok = false; }
      if (name.value.trim().length < 2) { name.classList.add('err'); ok = false; }
      if (text.value.trim().length < 5) { text.classList.add('err'); ok = false; }
      if (!ok) { UI.toast(UI.t('Заполните оценку, имя и текст отзыва'), true); var bad = $('.err input, input.err, textarea.err', f); if (bad) bad.focus(); return; }
      var btn = $('button[type=submit]', f); btn.disabled = true;
      UI.post(f.getAttribute('action'), { rate: rate.value, name: name.value.trim(), text: text.value.trim(), website: f.elements.website.value })
        .then(function (r) {
          btn.disabled = false;
          if (!r || !r.ok) { UI.toast((r && r.error) || UI.t('Не удалось отправить отзыв'), true); return; }
          var n = document.createElement('div');
          n.className = 'note ok rv-note'; n.setAttribute('role', 'status');
          n.textContent = r.message || UI.t('Спасибо! Отзыв появится после проверки модератором.');
          f.parentNode.replaceChild(n, f);
          UI.toast(UI.t('Отзыв отправлен'));
        });
    });
  }

  /* ---------- галерея ---------- */
  function initGallery() {
    var gal = $('#pp-gal'); if (!gal) return;
    var imgs = [], sets = []; try { imgs = JSON.parse(gal.getAttribute('data-images') || '[]'); sets = JSON.parse(gal.getAttribute('data-srcset') || '[]'); } catch (e) { }
    var main = $('#gal-img', gal), lb = $('#gal-lb'), lbImg = lb ? $('#gal-lb-img', lb) : null, cur = 0, lastFocus = null;
    if (!main || !imgs.length) return;

    function go(i) {
      var n = imgs.length; cur = (i + n) % n;
      main.srcset = sets[cur] || ''; main.src = imgs[cur];   // srcset (400/750/970) — браузер берёт размер по экрану; лайтбокс — 970
      if (lbImg) { lbImg.src = imgs[cur]; lbImg.classList.remove('zoom'); }
      $$('.gal-th', gal).forEach(function (t, k) { t.classList.toggle('on', k === cur); if (k === cur) t.setAttribute('aria-current', 'true'); else t.removeAttribute('aria-current'); });
      $$('[data-gal-cur]').forEach(function (el) { el.textContent = cur + 1; });
      var th = $$('.gal-th', gal)[cur]; if (th && th.scrollIntoView && th.parentNode.scrollWidth > th.parentNode.clientWidth) th.parentNode.scrollLeft = th.offsetLeft - 8;
      if (n > 1) { var pre = new Image(), k = (cur + 1) % n; pre.sizes = main.sizes; pre.srcset = sets[k] || ''; pre.src = imgs[k]; }   // следующее — тот же размер, что покажет галерея
    }
    function openLb() {
      if (!lb) return;
      lastFocus = document.activeElement;
      lbImg.src = imgs[cur]; lbImg.classList.remove('zoom');
      lb.hidden = false; document.documentElement.classList.add('gal-lock');
      requestAnimationFrame(function () { lb.classList.add('show'); });
      var x = $('[data-gal-close]', lb); if (x) x.focus();
    }
    function closeLb() {
      if (!lb || lb.hidden) return;
      lb.classList.remove('show'); lb.hidden = true; document.documentElement.classList.remove('gal-lock');
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (e) {
      var g = e.target.closest('[data-gal-go]');
      if (g && (gal.contains(g) || (lb && lb.contains(g)))) { e.preventDefault(); go(cur + (+g.getAttribute('data-gal-go'))); return; }
      var t = e.target.closest('[data-gal-i]');
      if (t && gal.contains(t)) { go(+t.getAttribute('data-gal-i')); return; }
      if (e.target.closest('[data-gal-open]')) { if (!swiped) openLb(); return; }
      if (lb && !lb.hidden) {
        if (e.target.closest('[data-gal-close]') || e.target.hasAttribute('data-gal-stage')) { closeLb(); return; }
        if (e.target === lbImg) zoom(e);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (!lb || lb.hidden) return;
      if (e.key === 'Escape') { e.stopPropagation(); closeLb(); }
      else if (e.key === 'ArrowRight') go(cur + 1);
      else if (e.key === 'ArrowLeft') go(cur - 1);
      else if (e.key === 'Tab') {                       // фокус не уходит из лайтбокса
        var f = $$('button', lb).filter(function (b) { return b.offsetParent !== null; });
        if (!f.length) return;
        var i = f.indexOf(document.activeElement);
        if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
      }
    }, true);

    // увеличение в лайтбоксе: клик — приблизить в точке клика, движение мыши — осмотр
    function zoom(e) {
      if (window.matchMedia('(hover: none)').matches) return;
      var on = lbImg.classList.toggle('zoom');
      if (on) origin(e);
    }
    function origin(e) {
      var r = lbImg.getBoundingClientRect();
      lbImg.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
    }
    if (lbImg) lbImg.addEventListener('mousemove', function (e) { if (lbImg.classList.contains('zoom')) origin(e); });

    // свайп (основное фото и лайтбокс)
    var sx = 0, sy = 0, swiped = false;
    function onStart(e) { var t = e.touches[0]; sx = t.clientX; sy = t.clientY; swiped = false; }
    function onEnd(e) {
      var t = e.changedTouches[0], dx = t.clientX - sx, dy = t.clientY - sy;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.3 && imgs.length > 1) {
        swiped = true; go(cur + (dx < 0 ? 1 : -1));
        setTimeout(function () { swiped = false; }, 350);
      }
    }
    [$('.gal-main', gal), lb ? $('[data-gal-stage]', lb) : null].forEach(function (el) {
      if (!el) return;
      el.addEventListener('touchstart', onStart, { passive: true });
      el.addEventListener('touchend', onEnd, { passive: true });
    });
  }

  /* ---------- ленты товаров: стрелки ---------- */
  function initRails() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rail-go]'); if (!b) return;
      var sec = b.closest('section'), rail = sec && $('[data-rail]', sec); if (!rail) return;
      rail.scrollBy({ left: (+b.getAttribute('data-rail-go')) * Math.max(200, rail.clientWidth * 0.8), behavior: 'smooth' });
    });
  }

  /* ---------- «Вы недавно смотрели» (cookie viewed, кроме текущего) ---------- */
  function initViewed() {
    var sec = $('#pp-viewed'), list = $('#pp-viewed-list'), host = $('[data-viewed]');
    if (!sec || !list) return;
    var curId = host ? host.getAttribute('data-viewed') : '';
    var ids = UI.listCookie('viewed').filter(function (x) { return /^\d+$/.test(x) && x !== curId; }).slice(0, 12);
    if (!ids.length) return;
    fetch(UI.url('/products/cards/?ids=' + ids.join(',')), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) {
        list.innerHTML = html;
        if (!$('.pc', list)) return;
        sec.classList.remove('hidden');
        UI.renderCounters();
        UI.refreshPrices(list);
      })
      .catch(function () { });
  }

  function init() {
    initBuy(); initTabs(); initReviewForm(); initGallery(); initRails(); initViewed();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

/* Каталог: фильтры, сортировка, «Показать ещё», избранное/просмотренные, поиск по брендам.
   Страницы кэшируются для всех — всё личное дорисовывает app.js (window.UI). */
(function () {
  'use strict';
  var UI = window.UI;
  if (!UI) return;
  var $ = UI.$, $$ = UI.$$;
  var mq = window.matchMedia('(max-width:980px)');

  /* ---------- фильтры ---------- */
  function cleanSubmit(form) {
    // пустые поля не попадают в адрес — ссылки короче и совпадают с серверными (формат Webasyst)
    var qs = [];
    $$('input', form).forEach(function (i) {
      if (!i.name || i.disabled || i.type === 'search' || ((i.type === 'checkbox' || i.type === 'radio') && !i.checked)) return;
      var v = i.value.trim(); if (v === '') return;
      qs.push(encodeURIComponent(i.name) + '=' + encodeURIComponent(v));
    });
    var action = form.getAttribute('action') || location.pathname;
    location.href = action + (qs.length ? '?' + qs.join('&') : '');
  }
  function bindFilters() {
    var form = $('form[data-filters]'); if (!form) return;
    form.classList.add('auto');
    form.addEventListener('submit', function (e) { e.preventDefault(); cleanSubmit(form); });
    form.addEventListener('change', function (e) {
      // на широком экране флажки применяются сразу, в мобильной панели — кнопкой «Показать товары»
      if (e.target.type === 'checkbox' && !mq.matches) cleanSubmit(form);
    });
    form.addEventListener('input', function (e) {
      var s = e.target.closest('[data-fsearch]'); if (!s) return;
      var g = s.closest('[data-fgroup]'), q = s.value.trim().toLowerCase();
      g.classList.toggle('fall', q !== '');
      $$('.fv', g).forEach(function (l) {
        var n = ($('.fn', l) || l).textContent.toLowerCase();
        l.classList.toggle('fhide', q !== '' && n.indexOf(q) < 0);
      });
    });
    form.addEventListener('click', function (e) {
      var b = e.target.closest('[data-fmore]'); if (!b) return;
      var g = b.closest('[data-fgroup]'), on = g.classList.toggle('fall');
      if (!b._t) b._t = b.textContent;
      b.textContent = on ? UI.t('Свернуть') : b._t;
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
    });
    // мобильная панель: скрыта для экранного диктора и клавиатуры (inert), пока закрыта
    var wrap = $('#cat-filters');
    function sync() {
      if (!wrap) return;
      if (mq.matches) { if (!wrap.classList.contains('show')) { wrap.setAttribute('aria-hidden', 'true'); wrap.setAttribute('inert', ''); } }
      else {
        if (wrap.classList.contains('show')) UI.close();      // закрыть до снятия атрибутов: UI.close() ставит их заново
        wrap.removeAttribute('aria-hidden'); wrap.removeAttribute('inert');
      }
    }
    sync();
    if (mq.addEventListener) mq.addEventListener('change', sync); else if (mq.addListener) mq.addListener(sync);
  }

  /* ---------- сортировка ---------- */
  function bindSort() {
    document.addEventListener('change', function (e) {
      var s = e.target.closest('select[data-nav]'); if (!s || !s.value) return;
      location.href = UI.url(s.value);
    });
  }

  /* ---------- «Показать ещё» ---------- */
  function bindMore() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-more]'); if (!b || b.getAttribute('aria-busy') === 'true') return;
      e.preventDefault();
      var root = b.closest('[data-catalog]') || document, list = $('[data-list]', root);
      var next = b.getAttribute('data-more'); if (!list || !next) return;
      b.setAttribute('aria-busy', 'true');
      // «_=more» — отдельный адрес для кэша браузера (тот же адрес отдаёт HTML страницы)
      UI.getJSON(UI.url(next) + (next.indexOf('?') < 0 ? '?' : '&') + '_=more').then(function (r) {
        b.removeAttribute('aria-busy');
        if (!r || !r.ok) throw new Error('bad');
        var tmp = document.createElement(list.tagName === 'TBODY' ? 'tbody' : 'div');
        tmp.innerHTML = r.html;
        var added = Array.prototype.slice.call(tmp.children);
        added.forEach(function (el) { list.appendChild(el); });
        var pager = $('[data-pager]', root); if (pager) pager.innerHTML = r.pager || '';
        if (r.next) {
          b.setAttribute('data-more', r.next);
          var t = $('[data-more-t]', b); if (t) t.textContent = UI.t('Показать ещё {n}', { n: r.left });
        } else {
          b.parentNode.remove();
        }
        try { history.replaceState(history.state, '', UI.url(next)); } catch (x) { }
        UI.renderCounters();
        added.forEach(function (el) { UI.refreshPrices(el); });
        var first = added[0] && (added[0].querySelector('a') || added[0]);
        if (first && first.focus) first.focus({ preventScroll: true });
      }).catch(function () {
        b.removeAttribute('aria-busy');
        UI.toast(UI.t('Не удалось загрузить товары. Попробуйте ещё раз.'), true);
      });
    });
  }

  /* ---------- избранное и просмотренные ---------- */
  function bindBalance() {
    var box = $('[data-balance]'); var kind = box && box.getAttribute('data-balance');
    document.addEventListener('click', function (e) {
      var c = e.target.closest('[data-clear]');
      if (c) {
        var name = c.getAttribute('data-clear');
        UI.dialog({
          title: UI.t(name === 'fav' ? 'Очистить избранное?' : 'Очистить просмотренные?'),
          text: UI.esc(UI.t('Список будет очищен на этом устройстве.')),
          html: '<form><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-o">' + UI.esc(UI.t('Очистить')) + '</button>'
            + '<button type="button" class="btn btn-g" data-close>' + UI.esc(UI.t('Отмена')) + '</button></div></form>',
          onSubmit: function () { UI.setCookie(name, '', -1); UI.renderCounters(); location.reload(); }
        });
        return;
      }
      // в избранном снятое «сердечко» убирает карточку из списка
      if (kind !== 'fav') return;
      var f = e.target.closest('[data-act=fav]'); if (!f) return;
      var host = f.closest('[data-id]'); if (!host) return;
      setTimeout(function () {
        if (UI.listCookie('fav').indexOf(host.getAttribute('data-id')) >= 0) return;
        host.style.transition = 'opacity .25s'; host.style.opacity = '0';
        setTimeout(function () { host.remove(); if (!$('[data-list] [data-id]')) location.reload(); }, 260);
      }, 0);
    });
  }

  /* ---------- бренды: быстрый поиск по названию ---------- */
  function bindBrands() {
    var inp = $('[data-brand-find]'); if (!inp) return;
    var empty = $('[data-brand-empty]');
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase(), any = false;
      $$('[data-brand-block]').forEach(function (sec) {
        var vis = 0;
        $$('[data-name]', sec).forEach(function (el) {
          var ok = q === '' || el.getAttribute('data-name').indexOf(q) >= 0;
          el.classList.toggle('hidden', !ok); if (ok) vis++;
        });
        // популярные при поиске не показываем — бренды и так есть в алфавитном списке
        sec.classList.toggle('hidden', vis === 0 || (q !== '' && sec.classList.contains('br-top')));
        if (vis && !(q !== '' && sec.classList.contains('br-top'))) any = true;
      });
      if (empty) empty.classList.toggle('hidden', any);
    });
  }

  /* ---------- на телефоне ряды букв и размерных рядов прокручиваются: показать выбранный ---------- */
  function showActiveChip() {
    $$('.br-letters, .cat-subs').forEach(function (row) {
      var on = $('a.on', row);
      if (on && row.scrollWidth > row.clientWidth) row.scrollLeft = Math.max(0, on.offsetLeft - row.offsetLeft - (row.clientWidth - on.offsetWidth) / 2);
    });
  }

  function init() { bindFilters(); bindSort(); bindMore(); bindBalance(); bindBrands(); showActiveChip(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

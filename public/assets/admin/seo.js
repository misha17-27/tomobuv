/* Админка → SEO.
   1) Превью Google (app/Views/admin/partials/serp.php): заголовок, адрес и описание обновляются на лету
      из полей формы, счётчики длины и точки .seo-dot — по тем же правилам, что SEO-обзор
      (title 30–70, description 70–170; пусто + есть шаблон — «по шаблону», пусто и шаблона нет — «нет»).
      Подключается партиалом сам; повторное подключение безопасно. Для блоков, добавленных позже: SeoSerp.init(el).
   2) Экран /admin/seo/: Esc в поиске товаров очищает поиск. */
(function () {
  'use strict';
  if (window.SeoSerp) return;

  var LIM = { title: [30, 70], desc: [70, 170] };
  var NAMES = { ok: 'Задан', warn: 'Длина', auto: 'По шаблону', none: 'Нет' };

  function norm(s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); }
  // значение для длины и состояния — как на сервере (SeoAudit::state: mb_strlen(trim(…))), пробелы внутри не схлопываются,
  // иначе у значений с двойными пробелами превью и SEO-обзор расходились бы на границе 70/170
  function raw(s) { return String(s == null ? '' : s).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, ''); }
  function len(s) { return Array.from(s).length; }                 // как mb_strlen (эмодзи — 1 символ)

  /* обрезка как в выдаче: по слову, с « …» (как $clip в serp.php) */
  function clip(s, n) {
    var a = Array.from(s);
    if (a.length <= n) return s;
    var cut = a.slice(0, n - 1), sp = cut.lastIndexOf(' ');
    return (sp > n / 2 ? cut.slice(0, sp) : cut).join('').replace(/\s+$/, '') + ' …';
  }
  function state(own, auto, k) {
    var n = len(own);
    if (!n) return auto ? 'auto' : 'none';
    return n < LIM[k][0] || n > LIM[k][1] ? 'warn' : 'ok';
  }
  function note(st, n, k) {
    if (st === 'ok') return 'задан';
    if (st === 'warn') return n < LIM[k][0] ? 'короче ' + LIM[k][0] : 'длиннее ' + LIM[k][1];
    if (st === 'auto') return 'по шаблону' + (n < LIM[k][0] ? ', короче ' + LIM[k][0] : n > LIM[k][1] ? ', длиннее ' + LIM[k][1] : '');
    return 'нет: пусто и шаблона нет';
  }
  function crumbs(host, path) {
    var parts = path.split('?')[0].split('/').filter(Boolean).map(function (p) {
      try { return decodeURIComponent(p); } catch (e) { return p; }            // как rawurldecode в serp.php
    });
    return host + (parts.length ? ' › ' + parts.join(' › ') : '');
  }

  function bind(box) {
    if (box.hasAttribute('data-serp-ready')) return;
    box.setAttribute('data-serp-ready', '1');
    var cfg = {};
    try { cfg = JSON.parse(box.getAttribute('data-serp-box') || '{}') || {}; } catch (e) { cfg = {}; }
    var scope = box.closest('form') || document;
    var lens = box.nextElementSibling && box.nextElementSibling.hasAttribute('data-serp-lens') ? box.nextElementSibling : null;
    var field = function (name) {
      if (!name) return null;
      var el = scope.querySelector('[name="' + String(name).replace(/["\\]/g, '') + '"]');
      return el && 'value' in el ? el : null;
    };
    var f = { title: field(cfg.title), desc: field(cfg.desc), url: field(cfg.url) };
    if (!f.title && !f.desc && !f.url) return;                        // статичное превью
    var auto = { title: raw(box.getAttribute('data-title-auto')), desc: raw(box.getAttribute('data-desc-auto')) };
    var out = { title: box.querySelector('[data-serp-title]'), desc: box.querySelector('[data-serp-desc]'), url: box.querySelector('[data-serp-url]') };

    function update() {
      ['title', 'desc'].forEach(function (k) {
        if (!f[k]) return;
        var own = raw(f[k].value), shown = own || auto[k], st = state(own, auto[k], k), n = len(shown);
        if (out[k]) {
          if (shown) out[k].textContent = clip(norm(shown), LIM[k][1]);
          else if (k === 'title') out[k].textContent = '(нет заголовка)';
          else out[k].innerHTML = '<span class="muted">Описания нет — Google возьмёт фрагмент текста со страницы</span>';
        }
        var info = lens && lens.querySelector('[data-serp-info="' + k + '"]');
        if (info) {
          var dot = info.querySelector('[data-serp-dot]'), l = info.querySelector('[data-serp-len]'), t = info.querySelector('[data-serp-note]');
          if (dot) { dot.className = 'seo-dot ' + st; dot.title = NAMES[st]; }
          if (l) l.textContent = n;
          if (t) t.textContent = note(st, n, k);
        }
      });
      if (f.url && out.url) {
        var v = f.url.value.trim().replace(/^\/+|\/+$/g, '');
        // адрес бренда — как Catalog::brandUrl (urlencode): «Mona Lisa» → /brand/Mona+Lisa/
        if (v && cfg.prefix === '/brand/') v = encodeURIComponent(v).replace(/%20/g, '+');
        if (v) out.url.textContent = crumbs(cfg.host || location.host, (cfg.prefix || '/') + v + '/');
      }
    }
    ['title', 'desc', 'url'].forEach(function (k) {
      if (f[k]) { f[k].addEventListener('input', update); f[k].addEventListener('change', update); }
    });
    update();
  }

  window.SeoSerp = {
    init: function (root) { (root || document).querySelectorAll('[data-serp-box]').forEach(bind); }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { window.SeoSerp.init(); });
  else window.SeoSerp.init();

  // ---- экран SEO-обзора: Esc в поиске товаров — сбросить поиск
  document.addEventListener('keydown', function (e) {
    var q = e.target;
    if (e.key !== 'Escape' || !q.matches || !q.matches('.seo-search input[name=q]') || !q.defaultValue) return;
    q.value = '';
    if (q.form) q.form.submit();
  });
})();

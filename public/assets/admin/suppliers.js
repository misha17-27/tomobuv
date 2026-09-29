/* Админка → «Поставщики → Jong•Golf».
   - «Проверить подключение» (AJAX, только справочники поставщика);
   - «Пробный прогон» / «Запустить сейчас»: создать запуск и перейти на его страницу;
   - страница запуска: шаги POST …/runs/{id}/step/ с прогрессом, паузой, остановкой и повтором при сбое связи;
   - таблица категорий: подсветка изменённых строк. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var nf = function (n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
  var post = function (url, data) { return window.Adm ? window.Adm.post(url, data) : Promise.reject(new Error('admin.js не загружен')); };

  // ------------------------------------------------------------------ проверка связи
  var test = $('#jg-test'), res = $('#jg-res');
  if (test) test.addEventListener('click', function () {
    test.disabled = true; res.className = 'hint jg-res'; res.textContent = 'Запрос справочников у поставщика…';
    post(test.getAttribute('data-url'), {}).then(function (r) {
      res.className = 'jg-res ' + (r.ok ? 'jg-ok' : 'jg-red');
      res.textContent = r.ok ? r.message : 'Нет связи: ' + (r.error || 'ошибка');
    }).catch(function () { res.className = 'jg-res jg-red'; res.textContent = 'Сервер не ответил — попробуйте ещё раз.'; })
      .then(function () { test.disabled = false; });
  });

  // ------------------------------------------------------------------ запуск (пробный / боевой)
  var startForm = function (form) {
    if (!form) return;
    var src = form.querySelector('select[name=source]'), file = form.querySelector('input[type=file]');
    if (src && file) {
      var sync = function () { file.hidden = src.value !== 'upload'; file.required = src.value === 'upload'; };
      src.addEventListener('change', sync); sync();
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var ask = form.getAttribute('data-ask');
      if (ask && !confirm(ask)) return;
      var btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      res.className = 'hint jg-res'; res.textContent = 'Начинаю…';
      post(form.action, new FormData(form)).then(function (r) {
        if (r && r.ok) { location.href = r.redirect; return; }
        res.className = 'jg-res jg-red'; res.textContent = (r && r.error) || 'Не удалось начать';
        btn.disabled = false;
      }).catch(function () { res.className = 'jg-res jg-red'; res.textContent = 'Сервер не ответил.'; btn.disabled = false; });
    });
  };
  startForm($('#jg-dry'));
  startForm($('#jg-run'));

  // ------------------------------------------------------------------ шаги запуска
  var box = $('#jg-run-box');
  if (box && box.getAttribute('data-running') === '1') {
    var go = $('#jg-go'), pause = $('#jg-pause'), stop = $('#jg-stop'), note = $('#jg-note'), err = $('#jg-error');
    var running = false, fails = 0, paused = false;
    var set = function (id, v) { var el = $('#' + id); if (el) el.textContent = v; };
    var show = function (p) {
      var s = p.stats || {};
      set('jg-label', p.label || '');
      set('jg-c-products', nf(s.products)); set('jg-c-created', nf(s.created)); set('jg-c-updated', nf(s.updated));
      set('jg-c-same', nf(s.same)); set('jg-c-hidden', nf((+s.hidden_color || 0) + (+s.hidden_missing || 0)));
      set('jg-c-skipped', nf(s.skipped)); set('jg-c-errors', nf(s.errors));
      var fill = $('#jg-fill'); if (fill) fill.style.width = (+p.percent || 0) + '%';
      set('jg-pct', (+p.percent || 0) + '%');
    };
    var tick = function () {
      if (paused) { running = false; return; }
      running = true;
      post(box.getAttribute('data-step'), {}).then(function (p) {
        fails = 0;
        show(p);
        if (p.done) { note.textContent = 'Готово — обновляю страницу…'; setTimeout(function () { location.href = location.pathname; }, 600); return; }
        setTimeout(tick, p.wait ? 5000 : (p.busy ? 3000 : 150));   // wait — идёт перестройка индекса каталога, порция отложена
      }).catch(function () {
        if (++fails <= 5) { note.textContent = 'Нет ответа сервера — повтор через 5 с (' + fails + ' из 5)…'; setTimeout(tick, 5000); return; }
        running = false; err.hidden = false; err.textContent = 'Связь с сервером прервалась. Нажмите «Продолжить» — запуск продолжится с того же места.';
        go.hidden = false; pause.hidden = true;
      });
    };
    go.addEventListener('click', function () { paused = false; go.hidden = true; pause.hidden = false; err.hidden = true; if (!running) tick(); });
    pause.addEventListener('click', function () { paused = true; pause.hidden = true; go.hidden = false; note.textContent = 'Пауза — шаг, который уже идёт, закончится.'; });
    stop.addEventListener('click', function () {
      if (!confirm('Остановить запуск? Уже записанные товары останутся; текущая страница поставщику не подтверждается.')) return;
      paused = true;
      post(box.getAttribute('data-stop'), {}).then(function () { location.href = location.pathname; });
    });
    if (box.getAttribute('data-auto') === '1') tick();
  }

  // ------------------------------------------------------------------ таблица категорий
  // список категорий сайта — один <template> на страницу; select получает его при первом фокусе (выбранное значение остаётся)
  var cats = $('#jg-cats');
  var fill = function (sel) {
    if (!cats || sel.getAttribute('data-filled')) return;
    var cur = sel.value;
    Array.prototype.slice.call(sel.options).forEach(function (o) { if (o.value !== '' && o.value !== '0') o.remove(); });   // «— не выбрана —» остаётся первой
    sel.appendChild(cats.content.cloneNode(true));
    sel.value = cur;
    sel.setAttribute('data-filled', '1');
  };
  ['focusin', 'mousedown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, function (e) { var s = e.target.closest && e.target.closest('select[data-cats]'); if (s) fill(s); }, true);
  });
  var map = $('#jg-map');
  if (map) map.addEventListener('change', function (e) {
    var sel = e.target.closest('select[data-was]');
    if (!sel) return;
    sel.closest('tr').classList.toggle('jg-changed', sel.value !== sel.getAttribute('data-was'));
    var n = map.querySelectorAll('tr.jg-changed').length;
    $('#jg-map-note').textContent = n ? 'Изменено строк: ' + n + ' — не забудьте сохранить.' : 'Изменённые строки подсвечиваются.';
  });
})();

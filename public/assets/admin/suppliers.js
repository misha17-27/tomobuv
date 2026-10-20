/* Админка → «Поставщики» (Jong•Golf, Хит обуви — одна разметка с префиксами jg- / sup-).
   - «Проверить подключение» / «Проверить связь» (AJAX);
   - «Пробный прогон»: создать запуск и перейти на его страницу (отчёт);
   - «Запустить сейчас» (шапка и «Обзор», [data-sup-run]) → «Обзор» с ?go=ID: страница сама делает шаги
     POST …/runs/{id}/step/ (каждый — до 12 с); закрыли страницу — боевой запуск продолжит cron;
   - «Остановить» ([data-sup-stop]): текущая порция дописывается, запуск — «Остановлен», товары не скрываются;
   - «Обзор» и шапка: ход идущего запуска — опрос GET …/progress/ раз в 4 с без перезагрузки;
   - страница запуска: шаги с прогрессом, паузой, остановкой и повтором при сбое связи;
   - таблица категорий: подсветка изменённых строк. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var nf = function (n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
  var post = function (url, data) { return window.Adm ? window.Adm.post(url, data) : Promise.reject(new Error('admin.js не загружен')); };
  var getJson = function (url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  };
  var KINDS = { run: 'Запуск', dry: 'Пробный прогон' };

  // ------------------------------------------------------------------ проверка связи
  var test = $('#jg-test'), res = $('#jg-res');
  if (test) test.addEventListener('click', function () {
    test.disabled = true; res.className = 'hint jg-res'; res.textContent = 'Запрос к поставщику…';
    post(test.getAttribute('data-url'), {}).then(function (r) {
      res.className = 'jg-res ' + (r.ok ? 'jg-ok' : 'jg-red');
      res.textContent = r.ok ? r.message : 'Нет связи: ' + (r.error || 'ошибка');
    }).catch(function () { res.className = 'jg-res jg-red'; res.textContent = 'Сервер не ответил — попробуйте ещё раз.'; })
      .then(function () { test.disabled = false; });
  });

  // ------------------------------------------------------------------ пробный прогон (форма)
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
      if (res) { res.className = 'hint jg-res'; res.textContent = 'Начинаю…'; }
      post(form.action, new FormData(form)).then(function (r) {
        if (r && r.ok) { location.href = r.redirect; return; }
        if (res) { res.className = 'jg-res jg-red'; res.textContent = (r && r.error) || 'Не удалось начать'; }
        btn.disabled = false;
      }).catch(function () { if (res) { res.className = 'jg-res jg-red'; res.textContent = 'Сервер не ответил.'; } btn.disabled = false; });
    });
  };
  startForm($('#jg-dry'));
  startForm($('#jg-run'));

  // ------------------------------------------------------------------ «Запустить сейчас» / «Остановить» (шапка и «Обзор»)
  var head = $('.sup-head'), live = $('#sup-live');
  var base = live ? live.getAttribute('data-base') : (head ? head.getAttribute('data-sup-base') : '');
  var progressUrl = base ? base + 'progress/' : '';
  var cur = { id: +((live && live.getAttribute('data-id')) || (head && head.getAttribute('data-sup-id')) || 0) };
  var say = function (text, bad) {
    var n = $('#sup-live-note') || res;
    if (n) { n.className = 'jg-res ' + (bad ? 'jg-red' : 'hint'); n.textContent = text; } else if (bad) alert(text);
  };
  $$('[data-sup-run]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.disabled) return;
      if (!confirm(b.getAttribute('data-ask') || 'Запустить загрузку сейчас?')) return;
      $$('[data-sup-run]').forEach(function (x) { x.disabled = true; });
      var pass = $('#sup-run-pass');
      say('Начинаю…');
      post(b.getAttribute('data-sup-run'), { mode: 'run', pass: pass ? pass.value : 'auto' }).then(function (r) {
        if (r && r.ok) { location.href = r.redirect || (base + '?go=' + r.id); return; }
        say((r && r.error) || 'Не удалось начать', true);
        $$('[data-sup-run]').forEach(function (x) { x.disabled = false; });
      }).catch(function () { say('Сервер не ответил — попробуйте ещё раз.', true); $$('[data-sup-run]').forEach(function (x) { x.disabled = false; }); });
    });
  });
  $$('[data-sup-stop]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!cur.id) return;
      if (!confirm('Остановить запуск №' + cur.id + '? Порция, которая уже пишется, допишется целиком; запуск получит статус «Остановлен», товары, пропавшие у поставщика, при этом не скрываются.')) return;
      stopped = true;
      $$('[data-sup-stop]').forEach(function (x) { x.disabled = true; });
      post(base + 'runs/' + cur.id + '/stop/', {}).then(function () { location.href = base; })
        .catch(function () { say('Сервер не ответил — нажмите «Остановить» ещё раз.', true); $$('[data-sup-stop]').forEach(function (x) { x.disabled = false; }); });
    });
  });

  // ход запуска: «Обзор» (метка, полоса, счётчики) и кнопки шапки
  var stopped = false;
  var set = function (sel, v) { var el = $(sel); if (el) el.textContent = v; };
  var show = function (d) {                                     // d — ответ progress/ ({running, paused, id, progress}) или шага (progress)
    var p = d.progress || d, st = d.running ? 'running' : (d.paused ? 'paused' : (p && p.status === 'running' ? 'running' : 'idle'));
    if (d.id) cur.id = +d.id;
    var busy = st !== 'idle';
    $$('[data-sup-run]').forEach(function (x) { x.disabled = busy || x.hasAttribute('data-blocked'); });
    $$('[data-sup-stop]').forEach(function (x) { x.hidden = !busy; x.disabled = false; });
    if (!live) return;
    var s = (p && p.stats) || {};
    var pill = $('#sup-live-st');
    if (pill) {
      pill.className = 'st ' + (st === 'running' ? 'st-processing' : (st === 'paused' ? 'st-new' : 'st-completed'));
      pill.textContent = st === 'running' ? 'Идёт запуск' : (st === 'paused' ? 'Ждёт продолжения' : 'Запуск не идёт');
    }
    if (p && cur.id) set('#sup-live-label', (KINDS[p.kind || d.kind] || 'Запуск') + ' №' + cur.id + ' — ' + (p.label || ''));
    var bar = $('#sup-live-bar'), cnt = $('#sup-live-counters'), open = $('#sup-live-open');
    if (bar) bar.hidden = !cur.id;
    if (cnt) cnt.hidden = !cur.id;
    if (open && cur.id) { open.hidden = false; open.href = base + 'runs/' + cur.id + '/'; }
    var pct = (p && +p.percent) || 0, fill = $('#sup-live-fill');
    if (fill) fill.style.width = pct + '%';
    set('#sup-live-pct', pct + '%');
    if (bar) bar.setAttribute('aria-valuenow', String(pct));
    var c = { products: s.products, created: s.created, updated: s.updated, hidden: (+s.hidden_color || 0) + (+s.hidden_missing || 0), skipped: s.skipped, errors: s.errors, requests: s.requests };
    Object.keys(c).forEach(function (k) { var el = live.querySelector('[data-c="' + k + '"]'); if (el) el.textContent = nf(c[k]); });
    var go = $('#sup-live-go'); if (go) go.hidden = st !== 'paused' || driving;
  };
  // кнопки, недоступные по условиям адаптера (нет наценки и т. п.), — помечаем, чтобы show() их не включал
  $$('[data-sup-run]').forEach(function (x) { if (x.disabled && !cur.id) x.setAttribute('data-blocked', '1'); });

  var driving = false, polling = false, fails = 0;
  var finished = function () {
    if (!live) { show({ running: false, paused: false }); return; }   // другие вкладки (настройки, категории) — только кнопки шапки
    say('Запуск закончен — обновляю страницу…');
    setTimeout(function () { location.href = base; }, 900);
  };
  var drive = function () {                                      // эта страница делает шаги запуска
    if (stopped || !cur.id) return;
    driving = true;
    post(base + 'runs/' + cur.id + '/step/', {}).then(function (p) {
      fails = 0;
      show({ progress: p, id: cur.id, running: !p.done });
      if (p.done) { driving = false; finished(); return; }
      if (p.busy) { driving = false; say('Шаги этого запуска делает другой процесс (cron или другая вкладка) — слежу за ходом.'); poll(); return; }
      say(p.wait ? 'Пауза: ' + (p.label || '') + ' — следующий шаг через 5 с.' : 'Страница делает шаги запуска — её можно закрыть, боевой запуск продолжит cron.');
      setTimeout(drive, p.wait ? 5000 : 200);
    }).catch(function () {
      if (++fails <= 5) { say('Нет ответа сервера — повтор через 5 с (' + fails + ' из 5)…', true); setTimeout(drive, 5000); return; }
      driving = false; say('Связь с сервером прервалась. Нажмите «Продолжить здесь» — запуск продолжится с того же места.', true);
      var go = $('#sup-live-go'); if (go) go.hidden = false;
    });
  };
  var poll = function () {                                       // только смотреть: шаги делает cron, CLI или другая вкладка
    if (polling || !progressUrl) return;
    polling = true;
    var tick = function () {
      if (stopped || driving) { polling = false; return; }
      getJson(progressUrl).then(function (d) {
        var was = cur.id;
        show(d);
        if (!d.running && !d.paused) { polling = false; if (was) finished(); return; }
        setTimeout(tick, d.paused ? 15000 : 4000);
      }).catch(function () { setTimeout(tick, 10000); });
    };
    tick();
  };
  var goBtn = $('#sup-live-go');
  if (goBtn) goBtn.addEventListener('click', function () { goBtn.hidden = true; fails = 0; if (!driving) drive(); });
  var state = live ? live.getAttribute('data-state') : (head ? ({ '1': 'running', p: 'paused' }[head.getAttribute('data-sup-running')] || 'idle') : 'idle');
  if (live && +live.getAttribute('data-go') && cur.id && state !== 'idle') drive();       // только что нажали «Запустить сейчас»
  else if (state === 'running' || state === 'paused') poll();

  // ------------------------------------------------------------------ страница запуска: шаги
  var box = $('#jg-run-box');
  if (box && box.getAttribute('data-running') === '1') {
    var go = $('#jg-go'), pause = $('#jg-pause'), stop = $('#jg-stop'), note = $('#jg-note'), err = $('#jg-error');
    var running = false, rfails = 0, paused = false;
    var rset = function (id, v) { var el = $('#' + id); if (el) el.textContent = v; };
    var rshow = function (p) {
      var s = p.stats || {};
      rset('jg-label', p.label || '');
      rset('jg-c-products', nf(s.products)); rset('jg-c-created', nf(s.created)); rset('jg-c-updated', nf(s.updated));
      rset('jg-c-same', nf(s.same)); rset('jg-c-hidden', nf((+s.hidden_color || 0) + (+s.hidden_missing || 0)));
      rset('jg-c-skipped', nf(s.skipped)); rset('jg-c-errors', nf(s.errors)); rset('jg-c-existing', nf(s.existing));
      var fill = $('#jg-fill'); if (fill) fill.style.width = (+p.percent || 0) + '%';
      rset('jg-pct', (+p.percent || 0) + '%');
    };
    var tick = function () {
      if (paused) { running = false; return; }
      running = true;
      post(box.getAttribute('data-step'), {}).then(function (p) {
        rfails = 0;
        rshow(p);
        if (p.done) { note.textContent = 'Готово — обновляю страницу…'; setTimeout(function () { location.href = location.pathname; }, 600); return; }
        setTimeout(tick, p.wait ? 5000 : (p.busy ? 3000 : 150));   // wait — идёт перестройка индекса каталога / пауза поставщика
      }).catch(function () {
        if (++rfails <= 5) { note.textContent = 'Нет ответа сервера — повтор через 5 с (' + rfails + ' из 5)…'; setTimeout(tick, 5000); return; }
        running = false; err.hidden = false; err.textContent = 'Связь с сервером прервалась. Нажмите «Продолжить» — запуск продолжится с того же места.';
        go.hidden = false; pause.hidden = true;
      });
    };
    go.addEventListener('click', function () { paused = false; go.hidden = true; pause.hidden = false; err.hidden = true; if (!running) tick(); });
    pause.addEventListener('click', function () { paused = true; pause.hidden = true; go.hidden = false; note.textContent = 'Пауза — шаг, который уже идёт, закончится.'; });
    stop.addEventListener('click', function () {
      if (!confirm('Остановить запуск? Уже записанные товары останутся; товары, пропавшие у поставщика, не скрываются.')) return;
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
    var curVal = sel.value;
    Array.prototype.slice.call(sel.options).forEach(function (o) { if (o.value !== '' && o.value !== '0') o.remove(); });   // «— не выбрана —» остаётся первой
    sel.appendChild(cats.content.cloneNode(true));
    sel.value = curVal;
    sel.setAttribute('data-filled', '1');
  };
  ['focusin', 'mousedown', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, function (e) { var s = e.target.closest && e.target.closest('select[data-cats]'); if (s) fill(s); }, true);
  });
  // строка изменена, если хоть одно поле с data-was (список, флажок «по рядам») отличается от сохранённого
  var val = function (el) { return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value; };
  // формы таблиц: #jg-map (сочетания / разделы) и #jg-sec (разделы сайта бренда Jong•Golf); подпись — #{id формы}-note
  $$('#jg-map, #jg-sec').forEach(function (map) {
    map.addEventListener('change', function (e) {
      var el = e.target.closest('[data-was]');
      if (!el) return;
      var tr = el.closest('tr');
      tr.classList.toggle('jg-changed', Array.prototype.some.call(tr.querySelectorAll('[data-was]'), function (x) { return val(x) !== x.getAttribute('data-was'); }));
      var n = map.querySelectorAll('tr.jg-changed').length, note = $('#' + map.id + '-note');
      if (note) note.textContent = n ? 'Изменено строк: ' + n + ' — не забудьте сохранить.' : 'Изменённые строки подсвечиваются.';
    });
  });
})();

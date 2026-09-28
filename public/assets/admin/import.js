/* Админка → «Импорт / экспорт».
   - загрузка файла по частям (обходит лимиты upload_max_filesize / post_max_size, продолжает после обрыва);
   - пошаговый запуск задания (POST /admin/import/{id}/run/) с прогресс-баром, паузой и повтором при сбое связи;
   - страница задания: подсветка повторов в сопоставлении, подсказки, соответствие категорий;
   - экспорт: количество товаров по фильтрам. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var nf = function (n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
  var sizeText = function (b) { return b >= 1048576 ? (b / 1048576).toFixed(1).replace('.0', '') + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ'; };
  var post = function (url, data) {
    if (window.Adm) return window.Adm.post(url, data);
    return Promise.reject(new Error('admin.js не загружен'));
  };

  // ------------------------------------------------------------------ загрузка файла
  var up = $('#im-upload');
  if (up) {
    var input = $('#im-file'), drop = $('#im-drop'), nameEl = $('#im-file-name'), btn = $('#im-upbtn');
    var bar = $('#im-upbar'), meter = $('#im-upmeter'), upText = $('#im-uptext');
    var chunk = +up.getAttribute('data-chunk') || 1048576, max = +up.getAttribute('data-max') || 0;
    var showName = function () {
      var f = input.files && input.files[0];
      nameEl.textContent = f ? f.name + ' · ' + sizeText(f.size) : 'Выберите файл или перетащите его сюда';
      drop.classList.toggle('has-file', !!f);
    };
    input.addEventListener('change', showName);
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showName(); }
    });
    var fail = function (msg) {
      btn.disabled = false;
      bar.hidden = false;
      meter.style.width = '0';
      upText.textContent = msg;
      upText.classList.add('im-bad');
    };
    var randomId = function () {
      var a = new Uint8Array(12);
      (window.crypto || window.msCrypto).getRandomValues(a);
      return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    };
    up.addEventListener('submit', function (e) {
      var f = input.files && input.files[0];
      upText.classList.remove('im-bad');
      if (!f) {
        if (!$('#im-url').value.trim()) { e.preventDefault(); fail('Выберите файл или вставьте ссылку.'); return; }
        btn.disabled = true; bar.hidden = false; upText.textContent = 'Скачиваю файл по ссылке…';
        return;                                                            // обычная отправка формы — файл скачает сервер
      }
      if (!window.FormData || !f.slice) return;                           // старый браузер — обычная отправка
      e.preventDefault();
      if (max && f.size > max) { fail('Файл больше ' + sizeText(max) + '.'); return; }
      if (!/\.(csv|txt|tsv|xlsx|xml|yml)$/i.test(f.name)) { fail(/\.xls$/i.test(f.name) ? 'Старый формат XLS не поддерживается — сохраните файл как XLSX или CSV.' : 'Подходят файлы CSV, TXT, XLSX, XML и YML.'); return; }
      btn.disabled = true; bar.hidden = false;
      var uid = randomId(), offset = 0, tries = 0, profile = $('#im-profile').value;
      var next = function () {
        var fd = new FormData();
        fd.append('upload_id', uid);
        fd.append('name', f.name);
        fd.append('size', f.size);
        fd.append('offset', offset);
        fd.append('profile_id', profile);
        fd.append('chunk', f.slice(offset, Math.min(f.size, offset + chunk)), 'chunk');
        post(up.action, fd).then(function (r) {
          if (!r || !r.ok) { fail((r && r.error) || 'Сервер не принял файл.'); return; }
          tries = 0;
          if (r.done) { meter.style.width = '100%'; upText.textContent = 'Файл загружен, открываю…'; location.href = r.redirect; return; }
          offset = +r.received || 0;
          var pct = Math.floor(offset / f.size * 100);
          meter.style.width = pct + '%';
          upText.textContent = 'Загрузка: ' + pct + '% (' + sizeText(offset) + ' из ' + sizeText(f.size) + ')';
          next();
        }).catch(function () {
          if (++tries <= 5) { upText.textContent = 'Связь прервалась — повтор через ' + (tries * 2) + ' с…'; setTimeout(next, tries * 2000); }
          else fail('Не удалось загрузить файл — проверьте интернет и попробуйте ещё раз.');
        });
      };
      upText.textContent = 'Загрузка…';
      next();
    });
  }

  // ------------------------------------------------------------------ ход задания
  var run = $('#im-run');
  if (run) {
    var goBtn = $('#im-go'), pauseBtn = $('#im-pause'), skipBtn = $('#im-skipimg'), errBox = $('#im-error');
    var running = false, fails = 0, started = Date.now(), timer = null;
    var set = function (id, v) { var el = $('#' + id); if (el) el.textContent = v; };
    var buttons = function () {
      goBtn.hidden = running;
      pauseBtn.hidden = !running;
    };
    var tick = function () {
      var s = Math.round((Date.now() - started) / 1000);
      set('im-elapsed', running ? 'идёт ' + (s >= 60 ? Math.floor(s / 60) + ' мин ' : '') + (s % 60) + ' с' : '');
    };
    var render = function (r) {
      var pct = Math.max(0, Math.min(100, +r.percent || 0));
      $('#im-fill').style.width = pct + '%';
      set('im-pct', pct + '%');
      run.querySelector('[role=progressbar]').setAttribute('aria-valuenow', pct);
      if (r.label) set('im-label', r.label);
      set('im-total', nf(r.total));
      set('im-done', nf(r.processed));
      ['created', 'updated', 'unchanged', 'skipped', 'errors'].forEach(function (k) { set('im-c-' + k, nf(r[k])); });
      if (r.images) set('im-c-images', nf(r.images.done) + ' / ' + nf(r.images.total));
      skipBtn.hidden = r.status !== 'images';
      if (r.note) set('im-note', r.note + (r.step_ms ? ' · шаг ' + (r.step_ms / 1000).toFixed(1) + ' с' : ''));
      else if (r.step_ms) set('im-note', 'Последний шаг: ' + (r.step_ms / 1000).toFixed(1) + ' с. Не закрывайте страницу; если связь оборвётся — нажмите «Продолжить».');
    };
    var stop = function (msg) {
      running = false; buttons();
      if (msg) { errBox.hidden = false; errBox.textContent = msg; }
    };
    var step = function () {
      if (!running) return;
      post(run.getAttribute('data-run'), {}).then(function (r) {
        fails = 0;
        errBox.hidden = true;
        render(r);
        if (r.status === 'new' || (r.status === 'error' && !r.processed)) { running = false; location.href = r.redirect || run.getAttribute('data-page'); return; }
        if (r.done || r.status === 'done' || r.status === 'error') {
          running = false; buttons();
          set('im-note', r.status === 'done' ? 'Импорт завершён — открываю итог…' : 'Импорт остановлен с ошибкой — открываю журнал…');
          setTimeout(function () { location.href = r.redirect || run.getAttribute('data-log'); }, 700);
          return;
        }
        if (r.ok === false && r.error) {                                   // ошибка шага — пробуем ещё, затем пауза
          if (++fails > 3) { stop(r.error + ' Нажмите «Продолжить», чтобы повторить.'); return; }
          setTimeout(step, fails * 3000);
          return;
        }
        setTimeout(step, r.busy ? 3000 : 30);
      }).catch(function () {
        if (++fails > 6) { stop('Нет связи с сервером. Импорт сохранил прогресс — нажмите «Продолжить», когда связь восстановится.'); return; }
        set('im-note', 'Связь прервалась — повтор через ' + (fails * 2) + ' с…');
        setTimeout(step, fails * 2000);
      });
    };
    var start = function () {
      if (running) return;
      running = true; fails = 0; started = Date.now(); buttons();
      if (!timer) timer = setInterval(tick, 1000);
      step();
    };
    goBtn.addEventListener('click', start);
    pauseBtn.addEventListener('click', function () { stop(); set('im-note', 'Пауза. Нажмите «Продолжить», чтобы продолжить с того же места.'); goBtn.textContent = 'Продолжить'; });
    skipBtn.addEventListener('click', function () {
      skipBtn.disabled = true;
      post(run.getAttribute('data-skip'), {}).then(function (r) { render(r); skipBtn.disabled = false; if (!running) start(); })
        .catch(function () { skipBtn.disabled = false; });
    });
    window.addEventListener('beforeunload', function (e) {
      if (running && run.getAttribute('data-status') !== 'parsing') { e.preventDefault(); e.returnValue = ''; }
    });
    if (run.getAttribute('data-auto') === '1') start();
  }

  // ------------------------------------------------------------------ настройки задания
  var form = $('#im-settings');
  if (form) {
    var maps = $$('select.im-map', form);
    var keySel = $('#im-key');
    var check = function () {
      var used = {};
      maps.forEach(function (s) { if (s.value && s.value !== 'images') (used[s.value] = used[s.value] || []).push(s); });
      maps.forEach(function (s) {
        var dup = s.value && used[s.value] && used[s.value].length > 1;
        s.classList.toggle('im-dup', !!dup);
        s.title = dup ? 'Это поле выбрано для нескольких колонок — будет взято значение первой непустой' : '';
        s.closest('tr').classList.toggle('im-on', !!s.value);
      });
      if (keySel) {
        var keyField = keySel.value, ok = maps.some(function (s) { return s.value === keyField; });
        keySel.classList.toggle('im-dup', !ok);
        keySel.title = ok ? '' : 'Колонка для этого ключа не выбрана в сопоставлении';
      }
    };
    maps.forEach(function (s) { s.addEventListener('change', check); });
    if (keySel) keySel.addEventListener('change', check);
    check();

    var sup = $('#im-supplier'), hide = $('#im-hide');
    var hideState = function () {
      if (!sup || !hide) return;
      var empty = !sup.value.trim();
      hide.disabled = empty;
      if (empty) hide.checked = false;
      hide.closest('label').title = empty ? 'Сначала укажите поставщика' : '';
    };
    if (sup) sup.addEventListener('input', hideState);
    hideState();

    var catmap = $('#im-catmap');
    $$('.im-addcat', form).forEach(function (b) {
      b.addEventListener('click', function () {
        var line = b.getAttribute('data-cat') + ' = ';
        catmap.value = (catmap.value.replace(/\s+$/, '') + (catmap.value.trim() ? '\n' : '') + line);
        catmap.focus();
        catmap.setSelectionRange(catmap.value.length, catmap.value.length);
        b.disabled = true;
      });
    });

    var profSel = $('#im-prof-id'), profName = $('#im-prof-name');
    if (profSel && profName) {
      var profState = function () {
        profName.placeholder = +profSel.value ? 'Оставить название профиля' : 'Название, напр. Forsage';
      };
      profSel.addEventListener('change', profState);
      profState();
    }
  }

  // ------------------------------------------------------------------ экспорт: количество товаров
  var ex = $('#im-export');
  if (ex) {
    var cnt = $('#im-count'), seq = 0;
    var recount = function () {
      var params = new URLSearchParams(new FormData(ex)), my = ++seq;
      cnt.textContent = '…';
      fetch(ex.getAttribute('data-count') + '?' + params.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          if (my !== seq || !r || !r.ok) return;
          var n = +r.count, a = n % 10, b = n % 100;
          cnt.textContent = nf(n);
          $('#im-count-word').textContent = a === 1 && b !== 11 ? 'товар' : (a >= 2 && a <= 4 && (b < 10 || b >= 20) ? 'товара' : 'товаров');
        })
        .catch(function () { if (my === seq) cnt.textContent = '?'; });
    };
    $$('select', ex).forEach(function (s) { s.addEventListener('change', recount); });
  }
})();

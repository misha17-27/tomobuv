/* Админка: «Изображения» (медиатека /admin/media/) и ПИКЕР картинок для любого экрана.

   Пикер — окно выбора картинки из медиатеки с загрузкой прямо в нём (как picker в админке ARG FLEX):
     <input type="text" id="cat-image" name="image" value="…">
     <button type="button" class="btn" data-media-pick="#cat-image">Выбрать</button>
   + подключить скрипт: 'scripts' => ['admin/media.js'] (стили admin/media.css скрипт подключит сам).
   Что происходит при выборе:
     - input — в value пишется ссылка /uploads/2026/09/foto.jpg, шлются события input и change;
     - textarea — в место курсора вставляется <img src="…" alt="" width height> (data-media-insert="url" — только ссылка);
     - img — меняется src; контейнер (div) — ссылка пишется в первое пустое текстовое поле внутри;
     - data-media-preview="#img-id" на кнопке — обновить картинку-превью;
     - на поле всплывает событие 'media:pick' (event.detail = {url, name, width, height, size_h}).
   Из кода: MediaPicker.open({ onSelect: function (file) { … } }).
   Загрузка файла в пикере (кнопкой или перетаскиванием в окно) сразу выбирает его, если файл один. */
(function () {
  'use strict';

  var script = document.currentScript;
  if (script && script.src && !document.querySelector('link[href*="admin/media.css"]')) {
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = script.src.replace(/media\.js(\?[^#]*)?$/, 'media.css$1');
    document.head.appendChild(css);
  }

  var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var EXT = /\.(jpe?g|png|webp|gif)$/i;
  var DEFAULT_LIMIT = 10 * 1024 * 1024;
  var AJAX = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };

  // ------------------------------------------------------------------ утилиты
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function plural(n, one, few, many) {
    var a = n % 10, b = n % 100;
    return a === 1 && b !== 11 ? one : (a >= 2 && a <= 4 && (b < 10 || b >= 20) ? few : many);
  }
  function sizeText(b) {
    if (b >= 1048576) return (Math.round(b / 104857.6) / 10).toString().replace('.', ',') + ' МБ';
    if (b >= 1024) return Math.round(b / 1024) + ' КБ';
    return b + ' Б';
  }
  function fire(node, type) {
    var ev;
    try { ev = new Event(type, { bubbles: true }); } catch (e) { ev = document.createEvent('Event'); ev.initEvent(type, true, true); }
    node.dispatchEvent(ev);
  }
  function json(r) {
    return r.json().catch(function () { throw new Error('Сервер ответил с ошибкой (' + r.status + ')'); });
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: AJAX }).then(json);
  }
  function post(url, fd) {
    fd.append('_token', token);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-Admin-Token': token } }).then(json);
  }
  /** Загрузка одного файла с прогрессом (XHR — у fetch нет прогресса отправки) → Promise<{ok, url, …}|{ok:false, error}> */
  function uploadFile(file, onProgress) {
    return new Promise(function (resolve) {
      var fd = new FormData();
      fd.append('file', file);
      fd.append('_token', token);
      var x = new XMLHttpRequest();
      x.open('POST', '/admin/media/upload/');
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.setRequestHeader('Accept', 'application/json');
      x.setRequestHeader('X-Admin-Token', token);
      if (onProgress) x.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      x.onload = function () {
        var r;
        try { r = JSON.parse(x.responseText); } catch (e) {
          r = { ok: false, error: x.status === 413 ? 'файл слишком большой для сервера' : 'сервер ответил с ошибкой (' + x.status + ')' };
        }
        resolve(r);
      };
      x.onerror = function () { resolve({ ok: false, error: 'нет связи с сервером' }); };
      x.send(fd);
    });
  }
  /** Проверка до отправки (сервер всё равно проверит содержимое) */
  function precheck(file, limit) {
    if (!EXT.test(file.name)) return 'можно загружать только JPG, PNG, WEBP или GIF';
    if (!file.size) return 'пустой файл';
    if (file.size > limit) return 'файл больше ' + sizeText(limit) + ' — уменьшите его перед загрузкой';
    return '';
  }
  /** Выполнить задачи не более чем по n одновременно */
  function pool(items, n, worker) {
    var i = 0;
    function next() { if (i >= items.length) return Promise.resolve(); var it = items[i++]; return worker(it).then(next, next); }
    var runners = [];
    for (var k = 0; k < Math.min(n, items.length); k++) runners.push(next());
    return Promise.all(runners);
  }
  function hasFiles(e) {
    var t = e.dataTransfer && e.dataTransfer.types;
    return !!t && Array.prototype.indexOf.call(t, 'Files') !== -1;
  }
  function copy(text, btn) {
    function ok() {
      if (!btn) return;
      var old = btn.getAttribute('data-md-label') || btn.textContent;
      btn.setAttribute('data-md-label', old);
      btn.textContent = 'Скопировано ✓';
      btn.classList.add('md-copied');
      clearTimeout(btn._mdT);
      btn._mdT = setTimeout(function () { btn.textContent = old; btn.classList.remove('md-copied'); }, 1600);
    }
    function fallback() {
      var ta = el('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      var done = false;
      try { done = document.execCommand('copy'); } catch (e) { done = false; }
      document.body.removeChild(ta);
      if (done) ok(); else window.prompt('Скопируйте ссылку:', text);
    }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok, fallback);
    else fallback();
  }

  // копирование и выделение — на любом экране
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-md-copy]');
    if (b) { e.preventDefault(); copy(b.getAttribute('data-md-copy'), b); }
  });
  document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches('[data-md-select]')) e.target.select();
  });

  // ------------------------------------------------------------------ экран медиатеки
  var lib = $('[data-md-lib]');
  var drop = $('[data-md-drop]');
  var grid = $('[data-md-grid]');

  /** Плитка как в app/Views/admin/media/_tile.php (для только что загруженных файлов) */
  function tile(r, isAdmin) {
    var fileUrl = '/admin/media/file/?f=' + encodeURIComponent(r.path);
    var fig = el('figure', 'md-fig is-new');
    fig.setAttribute('data-md-item', '');
    fig.setAttribute('data-path', r.path);
    fig.setAttribute('data-name', r.name);
    if (isAdmin) {
      var lab = el('label', 'md-tick');
      lab.title = 'Выбрать';
      var cb = el('input');
      cb.type = 'checkbox'; cb.name = 'f[]'; cb.value = r.path;
      cb.setAttribute('form', 'md-bulk');
      cb.setAttribute('data-md-tick', '');
      lab.appendChild(cb);
      lab.appendChild(el('span', 'md-sr', 'Выбрать ' + r.name));
      fig.appendChild(lab);
    }
    var a = el('a', 'md-thumb');
    a.href = fileUrl; a.title = r.path;
    var img = el('img');
    img.src = r.url; img.alt = '';
    if (r.width) { img.width = r.width; img.height = r.height; }
    a.appendChild(img);
    fig.appendChild(a);
    var cap = el('figcaption');
    var nm = el('a', 'md-name', r.name);
    nm.href = fileUrl; nm.title = r.name;
    cap.appendChild(nm);
    var d = new Date();
    var date = ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + '.' + d.getFullYear();
    cap.appendChild(el('span', 'md-meta', (r.width ? r.width + '×' + r.height + ' · ' : '') + r.size_h + ' · ' + date));
    var use = el('span', 'md-use');
    use.appendChild(el('i', 'seo-dot auto'));
    use.appendChild(document.createTextNode('не используется'));
    cap.appendChild(use);
    fig.appendChild(cap);
    var acts = el('div', 'md-acts');
    var cp = el('button', 'btn btn-sm', 'Ссылка');
    cp.type = 'button';
    cp.setAttribute('data-md-copy', r.url);
    cp.title = 'Скопировать ссылку ' + r.url;
    acts.appendChild(cp);
    if (isAdmin) {
      var del = el('a', 'btn btn-sm btn-d', 'Удалить');
      del.href = fileUrl + '#del';
      del.setAttribute('data-md-del', '');
      acts.appendChild(del);
    }
    fig.appendChild(acts);
    return fig;
  }

  function setTotal(delta) {
    var t = lib && $('[data-md-total]', lib);
    if (!t) return;
    var n = parseInt(t.textContent.replace(/\D+/g, ''), 10) || 0;
    t.textContent = String(Math.max(0, n + delta)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  if (drop) (function () {
    var form = drop.closest('form');
    var input = $('#md-files');
    var list = $('[data-md-queue]');
    var limit = parseInt(drop.getAttribute('data-max'), 10) || DEFAULT_LIMIT;
    var isAdmin = !!$('[data-md-bulk]');
    // новые файлы показываем в сетке, только если они попали бы в текущий список (первая страница, без поиска и т.п.)
    var live = !!lib && lib.hasAttribute('data-md-live');
    var running = 0, uploaded = 0;

    function row(file) {
      var li = el('li');
      var img = el('img');
      img.alt = '';
      if (/^image\/(jpeg|png|webp|gif)$/i.test(file.type)) {
        try {
          img.src = URL.createObjectURL(file);
          img.onload = img.onerror = function () { URL.revokeObjectURL(img.src); };
        } catch (e) { /* без превью */ }
      }
      var mid = el('div');
      mid.appendChild(el('b', '', file.name));
      var st = el('small', '', sizeText(file.size) + ' · ждёт очереди…');
      mid.appendChild(st);
      var meter = el('span', 'meter');
      var bar = el('i');
      bar.style.width = '0%';
      meter.appendChild(bar);
      mid.appendChild(meter);
      li.appendChild(img);
      li.appendChild(mid);
      list.insertBefore(li, list.firstChild);
      return {
        progress: function (p) { bar.style.width = Math.round(p * 100) + '%'; st.textContent = 'Загрузка… ' + Math.round(p * 100) + '%'; },
        done: function (r) {
          li.className = 'ok';
          meter.remove();
          st.textContent = r.url + ' · ' + (r.width ? r.width + '×' + r.height + ' · ' : '') + r.size_h + (r.resized ? ' · уменьшено' : '');
          var b = el('button', 'btn btn-sm', 'Копировать ссылку');
          b.type = 'button';
          b.setAttribute('data-md-copy', r.url);
          li.appendChild(b);
        },
        fail: function (msg) {
          li.className = 'bad';
          if (meter.parentNode) meter.remove();
          if (img.parentNode) li.replaceChild(el('span', 'md-qx', '×'), img);
          st.textContent = 'Не загружен: ' + msg;
        }
      };
    }

    function start(fileList) {
      var files = Array.prototype.slice.call(fileList || []);
      if (!files.length) return;
      list.hidden = false;
      var jobs = [];
      files.forEach(function (f) {
        var r = row(f), err = precheck(f, limit);
        if (err) r.fail(err); else jobs.push({ file: f, row: r });
      });
      running++;
      pool(jobs, 2, function (job) {
        return uploadFile(job.file, job.row.progress).then(function (r) {
          if (!r.ok) { job.row.fail(String(r.error || 'ошибка загрузки').replace(/^«[^»]*»:\s*/, '')); return; }
          job.row.done(r);
          uploaded++;
          if (grid && live) { grid.insertBefore(tile(r, isAdmin), grid.firstChild); setTotal(1); }
        });
      }).then(function () {
        running--;
        // пустой список: сетки ещё нет — показываем загруженное перезагрузкой
        if (!running && !grid && live && uploaded) location.reload();
      });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); start(input.files); input.value = ''; });
    input.addEventListener('change', function () { start(input.files); input.value = ''; });

    // перетаскивание — на всю страницу, подсвечивается зона загрузки
    var depth = 0;
    document.addEventListener('dragenter', function (e) { if (hasFiles(e)) { depth++; drop.classList.add('over'); } });
    document.addEventListener('dragleave', function (e) { if (hasFiles(e) && --depth <= 0) { depth = 0; drop.classList.remove('over'); } });
    document.addEventListener('dragover', function (e) { if (hasFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
    document.addEventListener('drop', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault();
      depth = 0;
      drop.classList.remove('over');
      start(e.dataTransfer.files);
    });
  })();

  if (lib) (function () {
    var bulk = $('[data-md-bulk]');
    var info = bulk && $('[data-md-selected]', bulk);
    var btn = bulk && $('[data-md-bulk-btn]', bulk);
    var all = bulk && $('[data-md-all]', bulk);
    var infoText = info ? info.textContent : '';

    function ticks() { return $$('[data-md-tick]', lib); }
    function sync() {
      if (!bulk) return;
      var on = ticks().filter(function (c) { return c.checked; });
      ticks().forEach(function (c) { var f = c.closest('figure'); if (f) f.classList.toggle('sel', c.checked); });
      info.textContent = on.length ? 'Выбрано: ' + on.length : infoText;
      btn.disabled = !on.length;
      all.checked = on.length > 0 && on.length === ticks().length;
    }
    function removeTiles(paths) {
      paths.forEach(function (p) {
        var f = $$('[data-md-item]', lib).filter(function (x) { return x.getAttribute('data-path') === p; })[0];
        if (!f) return;
        f.classList.add('gone');
        setTimeout(function () { f.remove(); sync(); }, 300);
      });
      setTotal(-paths.length);
    }
    function usageText(list) {
      return (list || []).slice(0, 8).map(function (u) { return '— ' + u.label + ': ' + u.title; }).join('\n')
        + ((list || []).length > 8 ? '\n… и ещё ' + (list.length - 8) : '');
    }

    if (bulk) {
      bulk.removeAttribute('data-confirm');                    // подтверждение спрашиваем сами (с учётом использования)
      var force = $('[data-md-force]', bulk);
      if (force) force.hidden = true;
      lib.addEventListener('change', function (e) {
        if (e.target === all) ticks().forEach(function (c) { c.checked = all.checked; });
        if (e.target === all || e.target.hasAttribute('data-md-tick')) sync();
      });
      bulk.addEventListener('submit', function (e) {
        e.preventDefault();
        var paths = ticks().filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
        if (!paths.length) return;
        if (!confirm('Удалить ' + paths.length + ' ' + plural(paths.length, 'файл', 'файла', 'файлов') + '? Восстановить удалённое будет нельзя.\n'
          + 'Файлы, которые используются на сайте, будут пропущены — о них спросим отдельно.')) return;
        btn.disabled = true;
        send(paths, false);
      });
      sync();
    }

    function send(paths, force) {
      var fd = new FormData();
      paths.forEach(function (p) { fd.append('f[]', p); });
      if (force) fd.append('force', '1');
      return post('/admin/media/delete/', fd).then(function (r) {
        if (r.deleted && r.deleted.length) removeTiles(r.deleted);
        if (r.blocked && r.blocked.length) {
          var names = r.blocked.slice(0, 10).map(function (b) { return '— ' + b.name + ' (' + b.count + ' ' + plural(b.count, 'место', 'места', 'мест') + ')'; }).join('\n');
          var q = r.usage && r.blocked.length === 1
            ? 'Файл «' + r.blocked[0].name + '» используется на сайте:\n' + usageText(r.usage)
            : 'Эти файлы используются на сайте:\n' + names + (r.blocked.length > 10 ? '\n… и ещё ' + (r.blocked.length - 10) : '');
          if (confirm(q + '\n\nПосле удаления картинки пропадут на этих страницах. Удалить всё равно?')) {
            return send(r.blocked.map(function (b) { return b.path; }), true);
          }
        } else if (!r.ok && r.error) {
          alert(r.error);
        }
      }).catch(function (err) { alert('Не удалось удалить: ' + err.message); })
        .then(function () { if (bulk) sync(); });
    }

    // удалить один файл: сначала показываем, где он используется
    lib.addEventListener('click', function (e) {
      var d = e.target.closest('[data-md-del]');
      if (!d) return;
      e.preventDefault();
      var fig = d.closest('[data-md-item]');
      var path = fig.getAttribute('data-path'), name = fig.getAttribute('data-name');
      d.setAttribute('aria-busy', 'true');
      getJSON('/admin/media/usage.json?f=' + encodeURIComponent(path)).then(function (r) {
        if (!r.ok) throw new Error(r.error || 'файл не найден');
        var msg = r.count
          ? 'Файл «' + name + '» используется на сайте (' + r.count + '):\n' + usageText(r.usage) + '\n\nПосле удаления картинка пропадёт на этих страницах. Удалить всё равно?'
          : 'Удалить файл «' + name + '»? Он нигде не используется. Восстановить его будет нельзя.';
        if (confirm(msg)) return send([path], r.count > 0);
      }).catch(function (err) { alert('Не удалось удалить: ' + err.message); })
        .then(function () { d.removeAttribute('aria-busy'); });
    });
  })();

  // ------------------------------------------------------------------ пикер
  var P = null;

  function buildPicker() {
    var root = el('div', 'md-picker');
    root.hidden = true;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-labelledby', 'md-pk-title');
    // разметка статичная — данные файлов вставляются только через textContent
    root.innerHTML =
      '<div class="md-picker-sc" data-md-close></div>' +
      '<div class="md-picker-pn">' +
        '<header><h2 id="md-pk-title">Выбор изображения</h2>' +
          '<button type="button" class="x" data-md-close aria-label="Закрыть">&times;</button></header>' +
        '<div class="md-picker-bar">' +
          '<input type="search" placeholder="Поиск по имени файла" aria-label="Поиск по имени файла" data-md-q>' +
          '<select aria-label="Папка" data-md-folder><option value="">Все папки</option></select>' +
        '</div>' +
        '<div class="md-picker-body" data-md-body>' +
          '<div class="md-picker-grid" data-md-pgrid></div>' +
          '<p class="md-picker-empty" data-md-empty hidden></p>' +
          '<div class="md-picker-more" data-md-more hidden><button type="button" class="btn btn-sm">Показать ещё</button></div>' +
          '<div class="md-picker-mask">Отпустите файлы — они загрузятся в медиатеку</div>' +
        '</div>' +
        '<footer><span class="muted" data-md-count></span>' +
          '<div class="md-picker-up">' +
            '<span role="status" data-md-status></span>' +
            '<input type="file" hidden multiple accept="image/jpeg,image/png,image/webp,image/gif" data-md-file>' +
            '<button type="button" class="btn btn-p btn-sm" data-md-upload>Загрузить с компьютера</button>' +
          '</div>' +
        '</footer>' +
      '</div>';
    document.body.appendChild(root);
    P = {
      root: root, body: $('[data-md-body]', root), grid: $('[data-md-pgrid]', root), q: $('[data-md-q]', root),
      folder: $('[data-md-folder]', root), more: $('[data-md-more]', root), empty: $('[data-md-empty]', root),
      count: $('[data-md-count]', root), status: $('[data-md-status]', root), file: $('[data-md-file]', root),
      upBtn: $('[data-md-upload]', root), page: 1, pages: 1, seq: 0, loaded: false, folders: false,
      limit: DEFAULT_LIMIT, opts: {}, back: null, t: 0, depth: 0
    };

    root.addEventListener('click', function (e) {
      if (e.target.closest('[data-md-close]')) { closePicker(); return; }
      var b = e.target.closest('.md-pick');
      if (b && b._item) choose(b._item);
    });
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); closePicker(); }
      if (e.key === 'Enter' && e.target === P.q) { e.preventDefault(); load(true); }
      if (e.key === 'Tab') {                                   // фокус не уходит из окна
        var f = $$('button, input:not([type=file]), select', root).filter(function (x) { return !x.disabled && x.offsetParent !== null; });
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    });
    P.q.addEventListener('input', function () { clearTimeout(P.t); P.t = setTimeout(function () { load(true); }, 250); });
    P.folder.addEventListener('change', function () { load(true); });
    $('button', P.more).addEventListener('click', function () { P.page++; load(false); });
    P.upBtn.addEventListener('click', function () { P.file.click(); });
    P.file.addEventListener('change', function () { upload(P.file.files); P.file.value = ''; });

    // перетаскивание файлов в окно пикера (не доходит до обработчика страницы медиатеки)
    root.addEventListener('dragenter', function (e) { if (hasFiles(e)) { e.stopPropagation(); P.depth++; root.classList.add('over'); } });
    root.addEventListener('dragleave', function (e) { if (hasFiles(e)) { e.stopPropagation(); if (--P.depth <= 0) { P.depth = 0; root.classList.remove('over'); } } });
    root.addEventListener('dragover', function (e) { if (hasFiles(e)) { e.preventDefault(); e.stopPropagation(); e.dataTransfer.dropEffect = 'copy'; } });
    root.addEventListener('drop', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault();
      e.stopPropagation();
      P.depth = 0;
      root.classList.remove('over');
      upload(e.dataTransfer.files);
    });
  }

  function pickTile(it, isNew) {
    var b = el('button', 'md-pick' + (isNew ? ' is-new' : ''));
    b.type = 'button';
    b.title = it.name + (it.width ? ' — ' + it.width + '×' + it.height : '');
    b._item = it;
    var img = el('img');
    img.src = it.url; img.alt = ''; img.loading = 'lazy';
    b.appendChild(img);
    b.appendChild(el('span', '', it.name));
    b.appendChild(el('small', '', (it.width ? it.width + '×' + it.height + ' · ' : '') + (it.size_h || '')));
    return b;
  }

  function status(text, bad) {
    P.status.textContent = text || '';
    P.status.classList.toggle('bad', !!bad);
  }

  function load(reset) {
    if (reset) P.page = 1;
    var seq = ++P.seq;
    P.body.classList.add('md-busy');
    var url = '/admin/media/list.json?page=' + P.page + '&q=' + encodeURIComponent(P.q.value.trim()) + '&folder=' + encodeURIComponent(P.folder.value);
    getJSON(url).then(function (r) {
      if (seq !== P.seq) return;                               // пришёл ответ на устаревший запрос
      if (!r.ok) throw new Error(r.error || 'не удалось получить список');
      if (reset) { P.grid.textContent = ''; P.body.scrollTop = 0; }
      r.items.forEach(function (it) { P.grid.appendChild(pickTile(it)); });
      P.loaded = true;
      P.pages = r.pages;
      if (r.limit) P.limit = r.limit;
      if (!P.folders && r.folders) {
        r.folders.forEach(function (f) { var o = el('option', '', f.label + ' · ' + f.count); o.value = f.value; P.folder.appendChild(o); });
        P.folders = true;
      }
      P.more.hidden = r.page >= r.pages;
      P.empty.hidden = r.total > 0;
      P.empty.textContent = P.q.value.trim() || P.folder.value
        ? 'Ничего не найдено — измените поиск или загрузите новую картинку.'
        : 'В медиатеке пока нет картинок. Загрузите первую кнопкой ниже или перетащите файл в это окно.';
      P.count.textContent = r.total + ' ' + plural(r.total, 'изображение', 'изображения', 'изображений')
        + ' · JPG, PNG, WEBP, GIF до ' + (r.limit_h || sizeText(P.limit));
    }).catch(function (err) {
      if (seq === P.seq) status('Ошибка: ' + err.message, true);
    }).then(function () {
      if (seq === P.seq) P.body.classList.remove('md-busy');
    });
  }

  function upload(fileList) {
    var files = Array.prototype.slice.call(fileList || []);
    if (!files.length) return;
    var done = [], errors = [];
    P.upBtn.disabled = true;
    var chain = Promise.resolve();
    files.forEach(function (f) {
      chain = chain.then(function () {
        var err = precheck(f, P.limit);
        if (err) { errors.push(f.name + ': ' + err); return; }
        status('Загрузка «' + f.name + '»…');
        return uploadFile(f, function (p) { status('Загрузка «' + f.name + '»… ' + Math.round(p * 100) + '%'); }).then(function (r) {
          if (!r.ok) { errors.push(String(r.error || 'ошибка загрузки')); return; }
          done.push(r);
          P.grid.insertBefore(pickTile(r, true), P.grid.firstChild);
          P.empty.hidden = true;
        });
      });
    });
    chain.then(function () {
      P.upBtn.disabled = false;
      if (errors.length) status('Не загружено: ' + errors.join(' · '), true);
      else status(done.length ? 'Загружено: ' + done.length : '');
      // один файл загружен без ошибок — это и есть выбор (как в ARG FLEX)
      if (done.length === 1 && !errors.length) choose(done[0]);
    });
  }

  function openPicker(opts) {
    if (!P) buildPicker();
    P.opts = opts || {};
    P.back = document.activeElement;
    status('');
    P.root.hidden = false;
    document.documentElement.classList.add('md-lock');
    // на телефоне не ставим курсор в поиск — выехавшая клавиатура закрыла бы половину сетки
    var coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    (coarse ? $('button[data-md-close]', P.root) : P.q).focus();
    load(true);                                                // всегда свежий список (могли загрузить в другой вкладке)
  }

  function closePicker() {
    if (!P || P.root.hidden) return;
    P.root.hidden = true;
    document.documentElement.classList.remove('md-lock');
    if (P.back && P.back.focus) P.back.focus();
  }

  function insertAtCursor(ta, text) {
    var s = ta.selectionStart, e = ta.selectionEnd;
    if (typeof s === 'number') {
      ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
      ta.selectionStart = ta.selectionEnd = s + text.length;
    } else {
      ta.value += text;
    }
  }

  function attr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

  function choose(it) {
    var o = P.opts || {};
    var file = { url: it.url, path: it.path, name: it.name, width: it.width, height: it.height, size_h: it.size_h };
    closePicker();
    if (typeof o.onSelect === 'function') { o.onSelect(file); return; }
    var t = o.target;
    if (!t) return;
    if (t.tagName === 'IMG') {
      t.src = file.url;
    } else if (t.tagName === 'TEXTAREA') {
      insertAtCursor(t, o.mode === 'url' ? file.url
        : '<img src="' + attr(file.url) + '" alt=""' + (file.width ? ' width="' + file.width + '" height="' + file.height + '"' : '') + '>');
    } else if (t.tagName === 'INPUT') {
      t.value = file.url;
    } else {                                                   // контейнер: первое пустое текстовое поле (или первое)
      var fields = $$('input[type=text], input[type=url], input:not([type])', t);
      var empty = fields.filter(function (i) { return !i.value.trim(); })[0];
      t = empty || fields[0];
      if (!t) return;
      t.value = file.url;
    }
    fire(t, 'input');
    fire(t, 'change');
    var ev;
    try { ev = new CustomEvent('media:pick', { bubbles: true, detail: file }); } catch (e) { ev = null; }
    if (ev) t.dispatchEvent(ev);
    if (o.preview) {
      var img = null;
      try { img = document.querySelector(o.preview); } catch (e) { img = null; }
      if (img) { img.src = file.url; img.hidden = false; }
    }
    if (t.focus && t.tagName !== 'IMG') t.focus();
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-media-pick]');
    if (!b) return;
    e.preventDefault();
    var sel = b.getAttribute('data-media-pick'), t = null;
    if (sel) {
      var scope = b.closest('[data-row]');                     // строки-повторители: ищем поле в своей строке
      try { t = (scope && scope.querySelector(sel)) || document.querySelector(sel); } catch (err) { t = null; }
    }
    if (!t) {
      var box = b.closest('.fld, label, [data-img-field], .img-field') || b.parentNode;
      t = box && box.querySelector('input[type=text], input[type=url], input:not([type]), textarea');
    }
    openPicker({ target: t, preview: b.getAttribute('data-media-preview'), mode: b.getAttribute('data-media-insert') || '' });
  });

  window.MediaPicker = { open: openPicker, close: closePicker };
})();

/* Админка → Каталог: товары, категории, бренды, характеристики.
   - список товаров: выбор строк и массовые действия;
   - карточка товара: адрес из названия, цена за ящик, характеристики (выбор с поиском + новое значение), фото;
   - HTML-редактор с панелью (жирный, список, ссылка…) и предпросмотром — для описаний товаров, категорий, брендов. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var debounce = function (fn, ms) { var t; return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); }; };
  var money = function (n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' грн.'; };

  // ------------------------------------------------------------------ RU | UA: переключение текстовых полей
  // Поля обоих языков есть в форме всегда (отправляются вместе), видна только выбранная версия.
  if ($('.ac-langtabs')) {
    var setLang = function (l) {
      $$('.ac-langtabs a').forEach(function (a) {
        var on = a.getAttribute('data-lang') === l;
        a.classList.toggle('on', on); a.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      $$('[data-l]').forEach(function (el) { el.hidden = el.getAttribute('data-l') !== l; });
      try { sessionStorage.setItem('ac-lang', l); } catch (err) { /* без хранилища — просто не запоминаем */ }
    };
    document.addEventListener('click', function (e) {
      var a = e.target.closest('.ac-langtabs a'); if (!a) return;
      e.preventDefault(); setLang(a.getAttribute('data-lang'));
    });
    // точка на вкладке UA — перевод уже заполнен
    var markUk = function () {
      var filled = $$('[data-l="uk"] input:not([type=hidden]), [data-l="uk"] textarea').some(function (i) { return i.value.trim() !== ''; });
      $$('.ac-langtabs a[data-lang="uk"]').forEach(function (a) { a.classList.toggle('has', filled); });
    };
    document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('[data-l="uk"]')) markUk(); });
    markUk();
    // обязательное поле на скрытой вкладке — показать её, иначе браузер не сможет подсветить ошибку
    document.addEventListener('invalid', function (e) { var box = e.target.closest('[data-l]'); if (box && box.hidden) setLang(box.getAttribute('data-l')); }, true);
    var saved = null;
    try { saved = sessionStorage.getItem('ac-lang'); } catch (err) { saved = null; }
    if (saved === 'uk') setLang('uk');
  }

  // ------------------------------------------------------------------ список товаров: массовые действия
  var bulk = $('#bulk-form');
  if (bulk) {
    var bar = $('#bulk-bar'), nEl = $('#bulk-n'), all = $('#bulk-all'), action = $('#bulk-action');
    var boxes = function () { return $$('input[name="ids[]"]', bulk); };
    var refresh = function () {
      var n = boxes().filter(function (b) { return b.checked; }).length;
      if (all && all.checked) n = +bulk.getAttribute('data-total');
      nEl.textContent = n.toLocaleString('ru-RU');
      bar.hidden = n === 0;
    };
    var ca = $('#check-all');
    if (ca) ca.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = ca.checked; }); if (!ca.checked && all) all.checked = false; refresh(); });
    bulk.addEventListener('change', function (e) {
      if (e.target.name === 'ids[]') { if (!e.target.checked && all) all.checked = false; refresh(); }
      if (e.target === all && all.checked) { boxes().forEach(function (b) { b.checked = true; }); if (ca) ca.checked = true; refresh(); }
      if (e.target === all && !all.checked) refresh();
      if (e.target === action) {
        $$('.ac-bulk-extra', bulk).forEach(function (el) { el.hidden = (' ' + el.getAttribute('data-for') + ' ').indexOf(' ' + action.value + ' ') < 0; });
      }
    });
    // клик по строке (не по ссылке) — отметить товар
    bulk.addEventListener('click', function (e) {
      var tr = e.target.closest('tbody tr');
      if (!tr || e.target.closest('a,input,button,label')) return;
      var cb = $('input[name="ids[]"]', tr); if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    bulk.addEventListener('submit', function (e) {
      var a = action.value, n = nEl.textContent;
      if (!a) { e.preventDefault(); action.focus(); return; }
      if ((a === 'addcat' || a === 'delcat') && !$('#bulk-cat').value) { e.preventDefault(); alert('Выберите категорию'); return; }
      if (a === 'price' && !$('#bulk-pct').value.trim()) { e.preventDefault(); alert('Укажите процент'); return; }
      if (a === 'delete' && !confirm('Удалить товаров: ' + n + '? Это необратимо — удалятся и фото.')) { e.preventDefault(); return; }
      if (all && all.checked && a !== 'delete' && !confirm('Применить действие ко всем найденным товарам (' + n + ')?')) e.preventDefault();
    });
    refresh();
  }

  // ------------------------------------------------------------------ HTML-редактор с предпросмотром
  // Медиатека (media.js) подгружается по первому нажатию «Медиатека», если экран её не подключил сам
  var withMedia = function (fn) {
    if (window.MediaPicker) return fn();
    var self = $('script[src*="admin/catalog.js"]');
    if (!self) return alert('Медиатека недоступна на этой странице');
    var sc = document.createElement('script');
    sc.src = self.src.replace(/catalog\.js(\?[^#]*)?$/, 'media.js$1');
    sc.onload = function () { if (window.MediaPicker) fn(); };
    document.head.appendChild(sc);
  };
  $$('.ac-editor').forEach(function (ed) {
    var ta = $('textarea', ed), frame = $('iframe', ed), remote = ed.getAttribute('data-remote') || '';
    var tools = $('.ac-etools', ed), lib = document.createElement('button');
    lib.type = 'button'; lib.className = 'btn btn-sm'; lib.setAttribute('data-cmd', 'lib');
    lib.title = 'Вставить картинку из медиатеки'; lib.textContent = 'Медиатека';
    tools.insertBefore(lib, $('.sp', tools));
    var wrap = function (before, after, def) {
      var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || def || '';
      ta.setRangeText(before + sel + after, s, e, 'end');
      ta.focus();
      if (!ta.value.slice(s, e)) ta.setSelectionRange(s + before.length, s + before.length + sel.length);
    };
    var list = function (tag) {
      var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || 'Пункт списка';
      var items = sel.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; }).map(function (l) { return '  <li>' + l.trim() + '</li>'; });
      ta.setRangeText('<' + tag + '>\n' + items.join('\n') + '\n</' + tag + '>', s, e, 'end');
      ta.focus();
    };
    var preview = function () {
      var html = ta.value;
      if (remote) html = html.replace(/(src|href)=(["'])\/wa-data\//gi, '$1=$2' + remote + '/wa-data/');
      frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"><style>body{font:15px/1.6 Manrope,system-ui,sans-serif;color:#14212b;margin:14px}img{max-width:100%;height:auto}a{color:#0b7fc1}h2,h3{line-height:1.25}</style></head><body>' + html + '</body></html>';
    };
    $('.ac-etools', ed).addEventListener('click', function (e) {
      var b = e.target.closest('button[data-cmd]'); if (!b) return;
      var c = b.getAttribute('data-cmd');
      if (c === 'b') wrap('<b>', '</b>', 'текст');
      else if (c === 'i') wrap('<i>', '</i>', 'текст');
      else if (c === 'h3') wrap('<h3>', '</h3>', 'Подзаголовок');
      else if (c === 'p') wrap('<p>', '</p>', 'Текст абзаца');
      else if (c === 'ul' || c === 'ol') list(c);
      else if (c === 'a') {
        var u = prompt('Адрес ссылки (например, /category/aktsiya/ или https://…)', '/');
        if (u && !/^\s*javascript:/i.test(u)) wrap('<a href="' + esc(u.trim()) + '">', '</a>', 'текст ссылки');
      } else if (c === 'lib') {
        var s = ta.selectionStart, en = ta.selectionEnd;         // позиция курсора до открытия окна
        withMedia(function () {
          window.MediaPicker.open({ onSelect: function (f) {
            if (!f || !f.url) return;
            var alt = (f.name || '').replace(/\.[a-z0-9]+$/i, '').replace(/[_-]+/g, ' ');
            ta.setRangeText('<img src="' + esc(f.url) + '" alt="' + esc(alt) + '"' + (f.width ? ' width="' + (+f.width) + '" height="' + (+f.height) + '"' : '') + '>', s, en, 'end');
            ta.focus();
            ta.dispatchEvent(new Event('input', { bubbles: true }));
          } });
        });
      } else if (c === 'preview') {
        var on = frame.hidden;
        frame.hidden = !on; ta.hidden = on;
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        b.classList.toggle('btn-p', on);
        if (on) preview();
      }
    });
  });

  // ------------------------------------------------------------------ карточка товара
  var pform = $('#product-form');
  if (pform) {
    // цена за ящик
    var price = $('#p-price'), box = $('#p-box'), bp = $('#box-price');
    var upd = function () { bp.textContent = money((parseFloat(price.value) || 0) * Math.max(1, parseInt(box.value, 10) || 1)); };
    price.addEventListener('input', upd); box.addEventListener('input', upd);

    // бейдж: свой текст
    var badge = $('#p-badge'), bc = $('#badge-custom');
    badge.addEventListener('change', function () { bc.hidden = badge.value !== 'custom'; });

    // адрес: подсказка из названия и проверка занятости
    var name = $('#p-name'), url = $('#p-url'), hint = $('#url-hint'), pid = url.getAttribute('data-id');
    var slug = function (params) {
      params.id = pid;
      return fetch('/admin/products/slug.json?' + new URLSearchParams(params), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
    };
    var suggest = debounce(function () {
      if (url.value.trim() !== '' || !name.value.trim()) return;
      slug({ name: name.value }).then(function (d) { url.placeholder = d.url || ''; });
    }, 400);
    name.addEventListener('input', suggest);
    $('#url-auto').addEventListener('click', function () {
      if (!name.value.trim()) { name.focus(); return; }
      url.value = '';
      slug({ name: name.value }).then(function (d) { url.value = d.url || ''; check(); });
    });
    var check = debounce(function () {
      if (!url.value.trim()) { hint.textContent = 'Пусто — адрес сформируется из названия (транслитом).'; hint.className = 'hint'; return; }
      slug({ url: url.value }).then(function (d) {
        hint.textContent = d.taken ? 'Адрес занят товаром №' + d.taken_by + ' — укажите другой.' : 'Адрес свободен: /product/' + d.url + '/';
        hint.className = d.taken ? 'hint ac-err' : 'hint';
      });
    }, 400);
    url.addEventListener('input', check);
    if (!url.value) suggest();

    // категории: поиск в дереве, основная категория всегда отмечена
    var cf = $('#cat-filter'), tree = $('#cat-tree'), main = $('#p-maincat');
    cf.addEventListener('input', function () {
      var q = cf.value.trim().toLowerCase();
      $$('label', tree).forEach(function (l) { l.hidden = q !== '' && l.getAttribute('data-name').indexOf(q) < 0; });
    });
    var mainPath = $('#p-maincat-path');
    var syncMain = function () {
      $$('input', tree).forEach(function (i) { i.closest('label').classList.toggle('ac-main', i.value === main.value); });
      var cb = main.value && $('input[value="' + main.value + '"]', tree);
      if (cb) cb.checked = true;
      // полный путь выбранной категории (у многих подкатегорий одинаковые имена: «32-38», «Зимняя обувь»)
      var opt = main.options[main.selectedIndex];
      if (mainPath) mainPath.textContent = (opt && opt.getAttribute('data-path')) || '';
    };
    main.addEventListener('change', syncMain); syncMain();
    tree.addEventListener('change', function (e) {
      if (e.target.value === main.value && !e.target.checked) { e.target.checked = true; alert('Это основная категория товара — сначала выберите другую основную.'); }
    });

    initFeatures();
    initPhotos();
  }

  // ------------------------------------------------------------------ характеристики: чипсы с поиском
  function initFeatures() {
    var optsEl = $('#feat-options');
    var opts = optsEl ? JSON.parse(optsEl.textContent || '{}') : {};
    var hex = function (code) { return code == null ? '' : '#' + ('000000' + Number(code).toString(16)).slice(-6); };
    $$('.ac-feat').forEach(function (box) {
      var fid = box.getAttribute('data-fid'), multi = box.getAttribute('data-multiple') === '1', remote = box.getAttribute('data-remote') === '1';
      var color = box.getAttribute('data-color') === '1';
      var chips = $('.ac-chips', box), input = $('.ac-chip-in', box), dd = $('.ac-dd', box), active = -1, items = [];
      var selected = function () { return $$('.ac-chip', chips).map(function (c) { return ($('input', c).value + '').toLowerCase(); }); };
      var selectedText = function () { return $$('.ac-chip', chips).map(function (c) { return c.getAttribute('data-text') || c.textContent.replace('×', '').replace('(новое)', '').trim().toLowerCase(); }); };
      var add = function (id, text, code) {
        if (!multi) $$('.ac-chip', chips).forEach(function (c) { c.remove(); });
        var c = document.createElement('span');
        c.className = 'ac-chip';
        c.setAttribute('data-text', text.toLowerCase());
        c.innerHTML = (color && code != null ? '<i class="ac-sw" style="background:' + hex(code) + '"></i>' : '')
          + '<input type="hidden" name="' + (id ? 'fv' : 'fn') + '[' + fid + '][]" value="' + esc(id || text) + '">' + esc(text)
          + (id ? '' : ' <small>(новое)</small>') + '<button type="button" class="ac-chip-x" aria-label="Убрать «' + esc(text) + '»">×</button>';
        chips.insertBefore(c, input);
        input.value = ''; close();
        if (multi) input.focus();
      };
      var close = function () { dd.hidden = true; dd.innerHTML = ''; active = -1; items = []; };
      var render = function (list, q) {
        var sel = selected(), selT = selectedText(), ql = q.toLowerCase(), exact = false;
        items = list.filter(function (o) { if (o[1].toLowerCase() === ql) exact = true; return sel.indexOf(String(o[0])) < 0 && selT.indexOf(o[1].toLowerCase()) < 0; }).slice(0, 60);
        if (q && !exact) items.push([0, q, null]);
        if (!items.length) { close(); return; }
        dd.innerHTML = items.map(function (o, i) {
          return '<div class="ac-opt" role="option" data-i="' + i + '">' + (o[0] ? '' : '<b>+ Добавить:</b> ')
            + (color && o[2] != null ? '<i class="ac-sw" style="background:' + hex(o[2]) + '"></i>' : '') + esc(o[1]) + '</div>';
        }).join('');
        dd.hidden = false; active = -1;
      };
      var load = debounce(function () {
        var q = input.value.trim();
        if (!remote) {
          var ql = q.toLowerCase();
          render((opts[fid] || []).filter(function (o) { return !ql || o[1].toLowerCase().indexOf(ql) >= 0; }), q);
          return;
        }
        if (!q) { close(); return; }
        fetch('/admin/features/' + fid + '/values.json?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (input.value.trim() === q) render((d.values || []).map(function (v) { return [v.id, v.value, v.code]; }), q); });
      }, remote ? 250 : 0);
      var pick = function (i) { var o = items[i]; if (o) add(o[0], o[1], o[2]); };
      var move = function (d) {
        if (dd.hidden || !items.length) return;
        active = (active + d + items.length) % items.length;
        $$('.ac-opt', dd).forEach(function (el, i) { el.classList.toggle('on', i === active); if (i === active) el.scrollIntoView({ block: 'nearest' }); });
      };
      input.addEventListener('input', load);
      input.addEventListener('focus', function () { if (!remote) load(); });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (dd.hidden) load(); else move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') {
          e.preventDefault();
          if (active >= 0) pick(active);
          else if (input.value.trim()) {
            var q = input.value.trim().toLowerCase(), i = items.findIndex(function (o) { return o[1].toLowerCase() === q; });
            if (i >= 0) pick(i); else add(0, input.value.trim(), null);
          }
        } else if (e.key === 'Escape') close();
        else if (e.key === 'Backspace' && !input.value) { var last = $$('.ac-chip', chips).pop(); if (last) last.remove(); }
      });
      input.addEventListener('blur', function () { setTimeout(close, 180); });
      dd.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); pick(+o.getAttribute('data-i')); } });
      chips.addEventListener('click', function (e) {
        var x = e.target.closest('.ac-chip-x'); if (x) { x.closest('.ac-chip').remove(); input.focus(); return; }
        if (e.target === chips) input.focus();
      });
    });
  }

  // ------------------------------------------------------------------ фото товара
  function initPhotos() {
    var wrap = $('#photos'); if (!wrap) return;
    var pid = wrap.getAttribute('data-pid'), input = $('#photo-input'), drop = $('#photo-drop'), status = $('#photo-status');
    var base = '/admin/products/' + pid + '/images/';
    var say = function (t, err) { status.textContent = t || ''; status.className = 'ac-small' + (err ? ' ac-err' : ''); };
    var draw = function (list) {
      wrap.innerHTML = list.map(function (im, i) {
        return '<div class="ac-ph" draggable="true" data-id="' + im.id + '"><img src="' + esc(im.thumb) + '" alt="Фото ' + (i + 1) + '">'
          + '<span class="ac-ph-main">Главное</span><div class="ac-ph-tools">'
          + '<button type="button" data-act="left" aria-label="Переместить влево">‹</button>'
          + '<button type="button" data-act="main" aria-label="Сделать главным">★</button>'
          + '<button type="button" data-act="right" aria-label="Переместить вправо">›</button>'
          + '<button type="button" data-act="del" aria-label="Удалить фото">×</button></div></div>';
      }).join('');
    };
    var handle = function (d) {
      if (d.images) draw(d.images);
      if (!d.ok || d.error) say(d.error || 'Ошибка', true);
      return d;
    };
    var order = function () { return $$('.ac-ph', wrap).map(function (el) { return el.getAttribute('data-id'); }); };
    var saveOrder = function () { return Adm.post(base + 'sort/', { ids: order().join(',') }).then(handle).then(function (d) { if (d.ok) say('Порядок сохранён. Первое фото — главное.'); }); };
    var upload = function (files) {
      files = Array.prototype.slice.call(files || []);
      if (!files.length) return;
      var i = 0, okN = 0, errs = [];
      var next = function () {
        if (i >= files.length) {
          say((okN ? 'Загружено фото: ' + okN + '. ' : '') + (errs.length ? 'Ошибки: ' + errs.join('; ') : ''), errs.length > 0);
          input.value = '';
          return;
        }
        var f = files[i++];
        say('Загрузка ' + i + ' из ' + files.length + '…');
        if (f.size > 15 * 1024 * 1024) { errs.push(f.name + ': больше 15 МБ'); next(); return; }
        var fd = new FormData(); fd.append('images[]', f);
        Adm.post(base, fd).then(function (d) {
          if (d.images) draw(d.images);
          if (d.ok) okN += d.added || 1; else errs.push(d.error || f.name);
          next();
        }).catch(function () { errs.push(f.name + ': ошибка сети или файл слишком большой для сервера'); next(); });
      };
      next();
    };
    input.addEventListener('change', function () { upload(input.files); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { if (e.dataTransfer && e.dataTransfer.types.indexOf('Files') >= 0) { e.preventDefault(); drop.classList.add('on'); } }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('on'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); upload(e.dataTransfer.files); } });

    wrap.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-act]'); if (!b) return;
      var tile = b.closest('.ac-ph'), iid = tile.getAttribute('data-id'), act = b.getAttribute('data-act');
      if (act === 'del') {
        if (!confirm('Удалить это фото?')) return;
        Adm.post(base + iid + '/delete/', {}).then(handle).then(function (d) { if (d.ok) say('Фото удалено.'); });
      } else if (act === 'main') {
        Adm.post(base + iid + '/main/', {}).then(handle).then(function (d) { if (d.ok) say('Главное фото изменено.'); });
      } else if (act === 'left' && tile.previousElementSibling) { wrap.insertBefore(tile, tile.previousElementSibling); saveOrder(); }
      else if (act === 'right' && tile.nextElementSibling) { wrap.insertBefore(tile.nextElementSibling, tile); saveOrder(); }
    });
    // перетаскивание для сортировки
    var dragged = null;
    wrap.addEventListener('dragstart', function (e) { dragged = e.target.closest('.ac-ph'); if (dragged) { dragged.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragged.getAttribute('data-id')); } });
    wrap.addEventListener('dragover', function (e) {
      if (!dragged) return;
      e.preventDefault();
      var t = e.target.closest('.ac-ph'); if (!t || t === dragged) return;
      var r = t.getBoundingClientRect(), after = (e.clientX - r.left) > r.width / 2;
      wrap.insertBefore(dragged, after ? t.nextSibling : t);
    });
    wrap.addEventListener('drop', function (e) { if (dragged) e.preventDefault(); });
    wrap.addEventListener('dragend', function () { if (!dragged) return; dragged.classList.remove('drag'); dragged = null; saveOrder(); });
  }

  // ------------------------------------------------------------------ категория: тип и условие
  var ctype = $$('input[name="type"]');
  if (ctype.length) {
    var cond = $('#cond-box'), sub = $('#sub-box');
    var syncType = function () {
      var dyn = ($('input[name="type"]:checked') || {}).value === '1';
      if (cond) cond.hidden = !dyn;
      if (sub) sub.hidden = dyn;
    };
    ctype.forEach(function (r) { r.addEventListener('change', syncType); });
    syncType();
    $$('[data-cond]').forEach(function (b) {
      b.addEventListener('click', function () {
        var ta = $('#p-cond'), add = b.getAttribute('data-cond');
        ta.value = ta.value.trim() ? ta.value.trim() + '&' + add : add;
        ta.focus();
      });
    });
    var bsel = $('#cond-brand');
    if (bsel) bsel.addEventListener('change', function () {
      if (!bsel.value) return;
      var ta = $('#p-cond'), add = 'brand.value_id=' + bsel.value;
      ta.value = ta.value.trim() ? ta.value.trim() + '&' + add : add;
      bsel.value = '';
    });
  }

  // ------------------------------------------------------------------ дерево категорий / список брендов: быстрый поиск по строкам
  var treeQ = $('#cat-q');
  if (treeQ) {
    var treeEmpty = $('#cat-q-empty');
    treeQ.addEventListener('input', function () {
      var q = treeQ.value.trim().toLowerCase(), shown = 0;
      $$('#cat-tree-table tbody tr').forEach(function (tr) {
        var hit = !q || (tr.getAttribute('data-name') || '').indexOf(q) >= 0;
        tr.hidden = !hit; if (hit) shown++;
      });
      if (treeEmpty) treeEmpty.hidden = shown > 0;
    });
  }

  // ------------------------------------------------------------------ предпросмотр загружаемой картинки (категория, бренд)
  $$('input[type=file][data-preview]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var img = $(inp.getAttribute('data-preview')); if (!img || !inp.files[0]) return;
      img.src = URL.createObjectURL(inp.files[0]); img.hidden = false;
    });
  });

  // ------------------------------------------------------------------ картинка категории/бренда: путь (медиатека), «убрать»
  $$('[data-clear]').forEach(function (b) {
    var field = $(b.getAttribute('data-clear')), img = $(b.getAttribute('data-clear-preview') || '');
    if (!field) return;
    var file = field.form && $('input[type=file][name="image_file"]', field.form);
    b.addEventListener('click', function () {
      field.value = '';
      if (file) file.value = '';
      if (img) { img.removeAttribute('src'); img.hidden = true; }
      field.focus();
    });
    // путь введён вручную — показать превью (ссылки /uploads/… — локальные)
    field.addEventListener('change', function () {
      var v = field.value.trim();
      if (!img) return;
      if (/^\/(uploads|wa-data)\/[^"<>]+\.(jpe?g|png|gif|webp|svg)$/i.test(v)) { img.src = v; img.hidden = false; }
      else if (!v) { img.removeAttribute('src'); img.hidden = true; }
    });
  });

  // ------------------------------------------------------------------ значения характеристики: выбор, объединение
  var vform = $('#values-form');
  if (vform) {
    var vca = $('#values-all');
    if (vca) vca.addEventListener('change', function () { $$('input[name="ids[]"]', vform).forEach(function (b) { b.checked = vca.checked; }); });
    vform.addEventListener('submit', function (e) {
      var btn = e.submitter, act = btn && btn.value;
      var n = $$('input[name="ids[]"]:checked', vform).length;
      if (act === 'merge') {
        if (n < 2) { e.preventDefault(); alert('Отметьте минимум два значения — они объединятся в выбранное «главным» (●).'); return; }
        var target = $('input[name="target"]:checked', vform);
        if (!target) { e.preventDefault(); alert('Отметьте кружком (●) значение, которое останется.'); return; }
        if (!confirm('Объединить ' + n + ' значений? Товары получат оставшееся значение, остальные будут удалены.')) e.preventDefault();
      }
      if (act === 'delete' && n && !confirm('Удалить отмеченные значения (' + n + ')? Удаляются только неиспользуемые.')) e.preventDefault();
      if (act === 'delete' && !n) { e.preventDefault(); alert('Отметьте значения.'); }
    });
    // переименование: кнопка «сохранить» появляется при изменении
    vform.addEventListener('input', function (e) {
      if (e.target.classList.contains('ac-vname')) e.target.closest('tr').classList.add('ac-changed');
    });
  }
})();

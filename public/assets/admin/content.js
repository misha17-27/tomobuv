/* Админка: контент и настройки + простой HTML-редактор.
 *
 * Редактор подключается к любому <textarea data-editor> (страницы, блог, товары, категории…):
 *   'scripts' => ['admin/content.js'] в render() раздела (стили admin/content.css и медиатеку admin/media.js
 *   скрипт подгрузит сам, если их нет на странице).
 *   Панель: H2, H3, абзац, жирный, курсив, списки, ссылка, картинка (загрузка на /admin/upload/),
 *   таблица, «Предпросмотр» ↔ «HTML-код». В предпросмотре текст можно править прямо «как на сайте»
 *   (стили витрины, скрипты из содержимого не выполняются — iframe в песочнице).
 *   Необязательно: data-media-base="https://…" — откуда брать картинки /wa-data/… в предпросмотре;
 *   class="… small" у textarea → невысокий редактор.
 *   API: window.AdmEditor.init(textarea), window.AdmEditor.upload(file) → Promise<{ok,url}>.
 *
 * Переключатель «RU | UA» (form.ed-form[data-lang], кнопки [data-lang-to], поля .l-ru/.l-uk, [data-uk] — счётчик
 * заполненных, [data-copy-from][data-copy-to] — «Скопировать русский текст»). Кнопки «Медиатека» — если подключён media.js.
 *
 * Прочее: загрузка картинок сразу при выборе файла ([data-upload-now]), предпросмотр баннера,
 * сортировка баннеров перетаскиванием/стрелками, списки строк (телефоны, способы доставки),
 * предпросмотр SEO-шаблонов, счётчики символов, Ctrl+S — сохранить, предупреждение о несохранённых правках.
 */
(function () {
  'use strict';

  // Подключили только скрипт (другой раздел админки с textarea[data-editor]) — стили редактора подгружаем сами
  var SELF = document.currentScript && document.currentScript.src;
  if (SELF && !document.querySelector('link[href*="admin/content.css"]')) {
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = SELF.replace(/content\.js(\?[^#]*)?$/, 'content.css$1');
    document.head.appendChild(css);
  }

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* приватный режим */ } }
  };

  // ------------------------------------------------------------------ сообщение внизу экрана
  var toastTimer;
  function toast(msg, isErr) {
    var t = $('.ed-toast');
    if (!t) { t = document.createElement('div'); t.className = 'ed-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.toggle('err', !!isErr);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, isErr ? 6000 : 2500);
  }

  function post(url, fd) {
    if (window.Adm && Adm.post) return Adm.post(url, fd);
    return Promise.reject(new Error('Adm.post недоступен'));
  }

  // ------------------------------------------------------------------ загрузка картинок
  var MAX_SIDE = 2400, SHRINK_FROM = 1.5 * 1024 * 1024;

  /** Большое фото уменьшаем в браузере (экономит трафик и обходит лимит хостинга на размер файла) */
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < SHRINK_FROM || !window.createImageBitmap) return resolve(file);
      createImageBitmap(file).then(function (bmp) {
        var k = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
        var c = document.createElement('canvas');
        c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        var type = file.type === 'image/png' ? 'image/png' : file.type;
        c.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) return resolve(file);
          resolve(new File([blob], file.name, { type: type }));
        }, type, 0.86);
      }).catch(function () { resolve(file); });
    });
  }

  function upload(file) {
    if (!file) return Promise.reject(new Error('Файл не выбран'));
    if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type)) return Promise.resolve({ ok: false, error: 'Можно загружать только картинки JPG, PNG, GIF или WEBP' });
    return shrink(file).then(function (f) {
      var fd = new FormData();
      fd.append('file', f, f.name);
      return post('/admin/upload/', fd);
    }).catch(function () { return { ok: false, error: 'Не удалось загрузить файл (слишком большой или нет связи)' }; });
  }

  function pickFile(cb) {
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/jpeg,image/png,image/gif,image/webp';
    inp.addEventListener('change', function () { if (inp.files && inp.files[0]) cb(inp.files[0]); });
    inp.click();
  }

  // ------------------------------------------------------------------ HTML-редактор
  var BTNS = [
    ['h2', 'H2', 'Заголовок раздела (H2)'], ['h3', 'H3', 'Подзаголовок (H3)'], ['p', '¶', 'Обычный абзац'], ['|'],
    ['b', 'Ж', 'Жирный (Ctrl+B)', 'ed-b'], ['i', 'К', 'Курсив (Ctrl+I)', 'ed-i'], ['|'],
    ['ul', '• Список', 'Маркированный список'], ['ol', '1. Список', 'Нумерованный список'], ['|'],
    ['link', 'Ссылка', 'Вставить ссылку'], ['img', 'Картинка', 'Загрузить и вставить картинку'],
    ['lib', 'Медиатека', 'Вставить картинку из медиатеки', 'ed-lib'], ['table', 'Таблица', 'Вставить таблицу'],
    ['gap'], ['mode', 'Предпросмотр', 'Переключить: предпросмотр / HTML-код', 'ed-mode']
  ];
  var FRONT_CSS = '/assets/css/app.css';

  function Editor(ta) {
    this.ta = ta;
    this.base = ta.getAttribute('data-media-base') || (window.AdmEditorConfig && AdmEditorConfig.mediaBase) || '';
    var box = this.box = document.createElement('div');
    box.className = 'ed' + (ta.classList.contains('small') || (ta.rows && ta.rows < 8) ? ' small-ed' : '');
    var bar = this.bar = document.createElement('div');
    bar.className = 'ed-bar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Оформление текста');
    BTNS.forEach(function (b) {
      if (b[0] === '|') { var s = document.createElement('span'); s.className = 'sep'; bar.appendChild(s); return; }
      if (b[0] === 'gap') { var g = document.createElement('span'); g.className = 'gap'; bar.appendChild(g); return; }
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('data-cmd', b[0]);
      btn.title = b[2];
      btn.setAttribute('aria-label', b[2]);
      btn.textContent = b[1];
      if (b[3]) btn.className = b[3];
      if (b[0] === 'mode') btn.setAttribute('aria-pressed', 'false');
      if (b[0] === 'lib') btn.hidden = !window.MediaPicker;   // покажем, когда подключится media.js
      bar.appendChild(btn);
    });
    box.setAttribute('data-mode', 'code');
    ta.parentNode.insertBefore(box, ta);
    box.appendChild(bar);
    box.appendChild(ta);
    ta.classList.add('ed-src');
    var foot = this.foot = document.createElement('div');
    foot.className = 'ed-foot';
    foot.innerHTML = '<span class="ed-hint"></span><span class="ed-len"></span>';
    box.appendChild(foot);
    this.frame = null;
    this.snapshot = null;
    this.mode = 'code';

    var self = this;
    bar.addEventListener('mousedown', function (e) { if (e.target.closest('button')) e.preventDefault(); }); // не терять выделение
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-cmd]');
      if (b) self.exec(b.getAttribute('data-cmd'));
    });
    ta.addEventListener('keydown', function (e) {
      if (!(e.ctrlKey || e.metaKey)) return;
      var k = e.key.toLowerCase();
      if (k === 'b' || k === 'и') { e.preventDefault(); self.exec('b'); }
      if (k === 'i' || k === 'ш') { e.preventDefault(); self.exec('i'); }
    });
    ta.addEventListener('input', function () { self.updateLen(); });
    var form = ta.form;
    if (form) form.addEventListener('submit', function () { self.sync(); });
    this.updateLen();
    if (store.get('adm-editor-mode') === 'visual') this.setMode('visual');
  }

  Editor.prototype.updateLen = function () {
    var n = this.ta.value.length;
    $('.ed-len', this.foot).textContent = n ? n.toLocaleString('ru-RU') + ' симв. HTML' : 'пусто';
    $('.ed-hint', this.foot).textContent = this.mode === 'visual' ? 'Предпросмотр: правьте текст прямо здесь' : 'HTML-код';
  };

  /** HTML для предпросмотра: картинки /wa-data/… — с основного сайта (локальная разработка) */
  Editor.prototype.toPreview = function (html) {
    if (!this.base) return html;
    return html.replace(/(src|href)=(["'])\/wa-data\//gi, '$1=$2' + this.base.replace(/\/$/, '') + '/wa-data/');
  };
  Editor.prototype.fromPreview = function (html) {
    if (!this.base) return html;
    var b = this.base.replace(/\/$/, '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return html.replace(new RegExp('(src|href)=(["\'])' + b + '/wa-data/', 'gi'), '$1=$2/wa-data/');
  };

  Editor.prototype.setMode = function (mode) {
    var self = this;
    if (mode === this.mode) return;
    if (mode === 'visual') {
      if (!this.frame) {
        this.frame = document.createElement('iframe');
        this.frame.className = 'ed-frame';
        this.frame.title = 'Предпросмотр содержимого';
        // песочница: скрипты из содержимого не выполняются, но редактор может менять документ
        this.frame.setAttribute('sandbox', 'allow-same-origin');
        this.box.insertBefore(this.frame, this.foot);
      }
      var doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank">'
        + '<link rel="stylesheet" href="' + FRONT_CSS + '">'
        + '<style>html,body{background:#fff}body{padding:14px 18px;max-width:none;min-height:280px;outline:none}body:empty:before{content:"Начните писать…";color:#9aa8b3}'
        + 'img{max-width:100%;height:auto}table{border-collapse:collapse}td,th{border:1px solid #e3e8ec;padding:6px 8px}</style>'
        + '</head><body class="prose" contenteditable="true" spellcheck="true">' + this.toPreview(this.ta.value) + '</body></html>';
      this.frame.onload = function () {
        var d = self.frame.contentDocument;
        if (!d || !d.body) return;
        self.snapshot = d.body.innerHTML;
        try { d.execCommand('defaultParagraphSeparator', false, 'p'); d.execCommand('styleWithCSS', false, false); } catch (e) { /* старые браузеры */ }
        self.fitFrame();
        d.addEventListener('input', function () { self.fitFrame(); markDirty(); });
        d.addEventListener('paste', function (e) { self.onPaste(e); });
        d.addEventListener('keydown', function (e) {
          if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveForm(self.ta.form); }
        });
        $$('img', d).forEach(function (im) { im.addEventListener('load', function () { self.fitFrame(); }); });
      };
      this.frame.srcdoc = doc;
    } else {
      this.sync();
    }
    this.mode = mode;
    this.box.setAttribute('data-mode', mode);
    var mb = $('[data-cmd=mode]', this.bar);
    mb.setAttribute('aria-pressed', mode === 'visual' ? 'true' : 'false');
    mb.textContent = mode === 'visual' ? 'HTML-код' : 'Предпросмотр';
    store.set('adm-editor-mode', mode);
    this.updateLen();
  };

  Editor.prototype.fitFrame = function () {
    var d = this.frame && this.frame.contentDocument;
    if (!d || !d.documentElement || !this.box.offsetParent) return;   // скрытый редактор (другой язык) — подгоним при показе
    this.frame.style.height = Math.min(1400, Math.max(this.ta.offsetHeight || 320, d.documentElement.scrollHeight + 4)) + 'px';
  };

  /** Заменить всё содержимое (кнопка «Скопировать русский текст») */
  Editor.prototype.setValue = function (html) {
    this.ta.value = html;
    if (this.mode === 'visual' && this.frame && this.frame.contentDocument && this.frame.contentDocument.body) {
      this.frame.contentDocument.body.innerHTML = this.toPreview(html);
      this.snapshot = this.frame.contentDocument.body.innerHTML;
      this.fitFrame();
    }
    this.updateLen();
    this.ta.dispatchEvent(new Event('input', { bubbles: true }));
  };

  Editor.prototype.getValue = function () {
    this.sync();
    return this.ta.value;
  };

  /** Перенести правки из предпросмотра в textarea (только если что-то изменили) */
  Editor.prototype.sync = function () {
    if (this.mode !== 'visual' || !this.frame) return;
    var d = this.frame.contentDocument;
    if (!d || !d.body) return;
    var html = d.body.innerHTML;
    if (html === this.snapshot) return;
    this.snapshot = html;
    this.ta.value = this.fromPreview(html.replace(/ contenteditable="(true|false)"/g, ''));
    this.updateLen();
  };

  /** Вставка из Word/браузера: убираем стили, классы, span/font и прочий мусор */
  Editor.prototype.onPaste = function (e) {
    var cd = e.clipboardData;
    if (!cd) return;
    var html = cd.getData('text/html');
    var text = cd.getData('text/plain');
    e.preventDefault();
    var out;
    if (html) {
      var tmp = document.createElement('div');
      tmp.innerHTML = html.replace(/<!--[\s\S]*?-->/g, '');
      $$('script,style,meta,link,title,xml,o\\:p', tmp).forEach(function (n) { n.remove(); });
      $$('*', tmp).forEach(function (n) {
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          var keep = (n.tagName === 'A' && a.name === 'href') || (n.tagName === 'IMG' && /^(src|alt|width|height)$/.test(a.name))
            || (/^T[DH]$/.test(n.tagName) && /^(colspan|rowspan)$/.test(a.name));
          if (!keep || /^\s*javascript:/i.test(a.value)) n.removeAttribute(a.name);
        });
      });
      $$('span,font', tmp).forEach(function (n) { n.replaceWith.apply(n, Array.prototype.slice.call(n.childNodes)); });
      out = tmp.innerHTML;
    } else {
      out = esc(text).replace(/\r?\n\r?\n/g, '</p><p>').replace(/\r?\n/g, '<br>');
      if (/<\/p><p>/.test(out)) out = '<p>' + out + '</p>';
    }
    this.frame.contentDocument.execCommand('insertHTML', false, out);
  };

  // --- команды
  Editor.prototype.exec = function (cmd) {
    var self = this;
    if (cmd === 'mode') return this.setMode(this.mode === 'visual' ? 'code' : 'visual');
    if (cmd === 'img') {
      return pickFile(function (file) {
        self.box.classList.add('is-loading');
        toast('Загрузка картинки…');
        upload(file).then(function (r) {
          self.box.classList.remove('is-loading');
          if (!r || !r.ok) return toast((r && r.error) || 'Ошибка загрузки', true);
          var alt = (file.name || '').replace(/\.[a-z0-9]+$/i, '').replace(/[_-]+/g, ' ');
          self.insert('<img src="' + esc(r.url) + '" alt="' + esc(alt) + '"' + (r.width ? ' width="' + r.width + '" height="' + r.height + '"' : '') + '>');
          toast('Картинка загружена');
        });
      });
    }
    if (cmd === 'lib') {
      if (!window.MediaPicker) return toast('Медиатека недоступна на этой странице', true);
      return window.MediaPicker.open({
        onSelect: function (f) {
          if (!f || !f.url) return;
          var alt = (f.name || '').replace(/\.[a-z0-9]+$/i, '').replace(/[_-]+/g, ' ');
          self.insert('<img src="' + esc(f.url) + '" alt="' + esc(alt) + '"' + (f.width ? ' width="' + (+f.width) + '" height="' + (+f.height) + '"' : '') + '>');
        }
      });
    }
    if (cmd === 'link') {
      var sel = this.selectedText();
      var url = window.prompt('Адрес ссылки (например /category/aktsiya/ или https://…)', /^(https?:\/\/|\/)/.test(sel) ? sel : '/');
      if (!url || url === '/') return;
      if (/^\s*javascript:/i.test(url)) return toast('Недопустимый адрес', true);
      var ext = /^https?:\/\//i.test(url) && url.indexOf(location.host) < 0;
      if (this.mode === 'visual' && sel) {
        var d = this.frame.contentDocument;
        d.execCommand('createLink', false, url);
        return this.afterVisual();
      }
      return this.insert('<a href="' + esc(url) + '"' + (ext ? ' target="_blank" rel="noopener"' : '') + '>' + (sel ? esc(sel) : esc(url)) + '</a>', sel !== '');
    }
    if (cmd === 'table') {
      var size = window.prompt('Размер таблицы: столбцов × строк', '3x4');
      if (!size) return;
      var m = size.match(/(\d+)\s*[x×х*]\s*(\d+)/i);
      var cols = Math.min(10, Math.max(1, m ? +m[1] : 3)), rows = Math.min(50, Math.max(1, m ? +m[2] : 4));
      var h = '<table>\n<thead><tr>' + new Array(cols + 1).join('<th>Заголовок</th>') + '</tr></thead>\n<tbody>\n';
      for (var r = 1; r < rows; r++) h += '<tr>' + new Array(cols + 1).join('<td>&nbsp;</td>') + '</tr>\n';
      return this.insert(h + '</tbody>\n</table>\n');
    }
    if (this.mode === 'visual') return this.execVisual(cmd);
    this.execCode(cmd);
  };

  Editor.prototype.execVisual = function (cmd) {
    var d = this.frame && this.frame.contentDocument;
    if (!d) return;
    this.frame.contentWindow.focus();
    var map = { b: ['bold'], i: ['italic'], ul: ['insertUnorderedList'], ol: ['insertOrderedList'], h2: ['formatBlock', '<h2>'], h3: ['formatBlock', '<h3>'], p: ['formatBlock', '<p>'] };
    var c = map[cmd];
    if (c) d.execCommand(c[0], false, c[1] || null);
    this.afterVisual();
  };

  Editor.prototype.afterVisual = function () { this.sync(); this.fitFrame(); markDirty(); };

  Editor.prototype.selectedText = function () {
    if (this.mode === 'visual') {
      var w = this.frame && this.frame.contentWindow;
      return w ? String(w.getSelection()) : '';
    }
    return this.ta.value.substring(this.ta.selectionStart, this.ta.selectionEnd);
  };

  /** Вставить HTML в место курсора (replaceSel — заменить выделенное) */
  Editor.prototype.insert = function (html, replaceSel) {
    if (this.mode === 'visual') {
      this.frame.contentWindow.focus();
      this.frame.contentDocument.execCommand('insertHTML', false, html);
      return this.afterVisual();
    }
    var ta = this.ta, s = ta.selectionStart, e = replaceSel ? ta.selectionEnd : ta.selectionStart;
    this.replaceRange(s, e, html, s + html.length, s + html.length);
  };

  Editor.prototype.replaceRange = function (s, e, text, selS, selE) {
    var ta = this.ta;
    ta.focus();
    ta.setSelectionRange(s, e);
    // execCommand сохраняет историю отмены (Ctrl+Z); если не сработал — меняем значение напрямую
    var ok = false;
    try { ok = document.execCommand('insertText', false, text); } catch (err) { ok = false; }
    if (!ok || ta.value.substring(s, s + text.length) !== text) ta.value = ta.value.substring(0, s) + text + ta.value.substring(e);
    ta.setSelectionRange(selS, selE);
    this.updateLen();
    markDirty();
  };

  Editor.prototype.execCode = function (cmd) {
    var ta = this.ta, s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.substring(s, e);
    var wrap = { b: ['<strong>', '</strong>', 'жирный текст'], i: ['<em>', '</em>', 'курсив'], h2: ['<h2>', '</h2>', 'Заголовок'], h3: ['<h3>', '</h3>', 'Подзаголовок'], p: ['<p>', '</p>', 'Текст абзаца'] }[cmd];
    if (wrap) {
      var inner = sel || wrap[2];
      var block = cmd === 'h2' || cmd === 'h3' || cmd === 'p';
      inner = inner.replace(/^<(h[1-6]|p)>([\s\S]*)<\/\1>$/i, '$2');   // H2 поверх <p>…</p> — заменить тег
      var txt = wrap[0] + inner + wrap[1] + (block && !sel ? '\n' : '');
      return this.replaceRange(s, e, txt, s + wrap[0].length, s + wrap[0].length + inner.length);
    }
    if (cmd === 'ul' || cmd === 'ol') {
      var lines = (sel || 'Пункт списка').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
      var list = '<' + cmd + '>\n' + lines.map(function (l) { return '  <li>' + l.replace(/^[-•*]\s*/, '') + '</li>'; }).join('\n') + '\n</' + cmd + '>\n';
      return this.replaceRange(s, e, list, s + list.length, s + list.length);
    }
  };

  var editors = [];
  function initEditor(ta) {
    if (ta.__ed) return ta.__ed;
    ta.__ed = new Editor(ta);
    editors.push(ta.__ed);
    return ta.__ed;
  }
  window.AdmEditor = { init: initEditor, upload: upload, toast: toast };

  // ------------------------------------------------------------------ несохранённые изменения, Ctrl+S
  var dirty = false;
  function markDirty() { dirty = true; }
  function saveForm(form) {
    form = form || $('form.ed-form');
    if (!form) return;
    editors.forEach(function (ed) { ed.sync(); });
    if (form.requestSubmit) form.requestSubmit(); else form.submit();
  }
  document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('form.ed-form')) dirty = true; });
  document.addEventListener('change', function (e) { if (e.target.closest && e.target.closest('form.ed-form') && e.target.type !== 'file') dirty = true; });
  // после admin.js (подтверждение «Удалить?»): отменённая отправка не сбрасывает признак несохранённых правок
  document.addEventListener('submit', function (e) { if (!e.defaultPrevented) dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && $('form.ed-form')) { e.preventDefault(); saveForm(); }
  });

  // ------------------------------------------------------------------ счётчики символов, адрес из названия
  var TR = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'ґ': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo', 'є': 'ye', 'ж': 'zh', 'з': 'z', 'и': 'i', 'і': 'i', 'ї': 'yi', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'kh', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'shch', 'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya' };
  function slug(s) {
    return s.toLowerCase().trim().split('').map(function (c) { return TR[c] !== undefined ? TR[c] : c; }).join('')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
  }

  function initForms() {
    $$('[data-counter]').forEach(function (el) {
      var max = +el.getAttribute('data-counter'), c = document.createElement('small');
      c.className = 'hint';
      el.insertAdjacentElement('afterend', c);
      var upd = function () {
        var n = el.value.length;
        c.textContent = n ? n + ' / ' + max + ' симв.' + (n > max ? ' — длинновато, поисковик обрежет' : '') : 'рекомендуется до ' + max + ' симв.';
        c.classList.toggle('warn', n > max);
      };
      el.addEventListener('input', upd);
      upd();
    });

    $$('input[data-slug-from]').forEach(function (u) {
      var src = u.form && u.form.elements[u.getAttribute('data-slug-from')];
      var noslash = u.hasAttribute('data-slug-noslash');
      var orig = u.getAttribute('data-url-orig') || '';
      var red = u.form && $('.ed-redirect', u.form);
      var upd = function () {
        if (src && orig === '') { var s = slug(src.value); u.placeholder = s ? s + (noslash ? '' : '/') : u.placeholder; }
        if (red) {
          var norm = function (v) { return v.replace(/^\/+|\/+$/g, ''); };
          red.classList.toggle('hidden', orig === '' || norm(u.value) === norm(orig) || norm(u.value) === '');
        }
      };
      if (src) src.addEventListener('input', upd);
      u.addEventListener('input', upd);
      upd();
    });

    // «Подставить значение» — кнопка [data-fill=имя поля][data-value]
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-fill]');
      if (!b) return;
      var f = b.form || b.closest('form'), el = f && f.elements[b.getAttribute('data-fill')];
      if (el) { el.value = b.getAttribute('data-value'); el.focus(); markDirty(); }
    });
  }

  // ------------------------------------------------------------------ картинка: загрузка сразу при выборе файла
  function initImageFields() {
    $$('[data-img-field]').forEach(function (box) {
      var file = $('input[type=file][data-upload-now]', box), path = $('input[name=image]', box);
      if (!file || !path) return;
      var form = box.closest('form');
      var show = function (url) {
        var prev = $('.img-prev', box), im = prev && $('img', prev);
        if (prev) {
          prev.classList.toggle('empty', !url);
          if (im && url) im.src = url;
        }
        if (form) $$('[data-out=image]', form).forEach(function (im) { if (url) { im.src = url; im.hidden = false; } else { im.hidden = true; } });
      };
      file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) return;
        box.classList.add('is-loading');
        upload(f).then(function (r) {
          box.classList.remove('is-loading');
          if (!r || !r.ok) { toast((r && r.error) || 'Ошибка загрузки', true); file.value = ''; return; }
          path.value = r.url;
          file.value = '';                   // файл уже на сервере — повторно с формой не отправляем
          path.dispatchEvent(new Event('input', { bubbles: true }));   // предпросмотр баннера
          path.dispatchEvent(new Event('change', { bubbles: true }));
          show(r.url);
          markDirty();
          toast('Картинка загружена — не забудьте сохранить');
        });
      });
      path.addEventListener('change', function () {
        show(path.value.trim());
        var r = $('input[name=image_remove]', box);
        if (r && path.value.trim()) r.checked = false;      // выбрали новую картинку — «убрать» больше не нужно
      });
      // битая ссылка — показываем «Нет картинки», а не пустую рамку
      var im0 = $('.img-prev img', box);
      if (im0) im0.addEventListener('error', function () { if (im0.getAttribute('src')) $('.img-prev', box).classList.add('empty'); });
      var rm = $('input[name=image_remove]', box);
      if (rm) rm.addEventListener('change', function () { $('.img-prev', box).classList.toggle('empty', rm.checked || !path.value.trim()); });
    });
  }

  // ------------------------------------------------------------------ предпросмотр баннера (для открытого языка; UA пусто → RU)
  function initBannerPreview() {
    var form = $('[data-banner-form]');
    if (!form) return;
    var prev = $('.bn-prev', form);
    var val = function (name) { var el = form.elements[name]; return el ? el.value.trim() : ''; };
    var render = function () {
      var uk = form.getAttribute('data-lang') === 'uk';
      var pick = function (k) { return (uk && val(k + '_uk')) || val(k); };
      var img = val('image');
      $$('[data-out]', prev).forEach(function (o) {
        var key = o.getAttribute('data-out');
        if (key === 'image') { o.hidden = !img; if (img) o.src = /^\/wa-data\//.test(img) && prev.getAttribute('data-media-base') ? prev.getAttribute('data-media-base') + img : img; return; }
        var v = pick(key);
        o.textContent = v || (key === 'title' ? 'Заголовок' : key === 'button' ? (o.closest('.bn-wide') ? 'Перейти' : (uk ? 'Дивитися' : 'Смотреть')) : '');
      });
      var l = $('[data-prev-lang]', form);
      if (l) l.textContent = uk ? 'UA' : 'RU';
    };
    $$('[data-prev]', form).forEach(function (el) { el.addEventListener('input', render); el.addEventListener('change', render); });
    form.addEventListener('langchange', render);
    $$('input[name=place]', form).forEach(function (r) {
      r.addEventListener('change', function () { if (r.checked) prev.setAttribute('data-place', r.value); });
    });
  }

  // ------------------------------------------------------------------ переключатель «RU | UA» в формах страниц, статей, баннеров
  function initLang() {
    $$('form.ed-form[data-lang]').forEach(function (form) {
      var btns = $$('[data-lang-to]', form), hidden = form.elements._lang, counter = $('[data-uk-count]', form);
      var ukFields = $$('[data-uk]', form);
      var count = function () {
        if (!counter || !ukFields.length) return;
        var n = ukFields.filter(function (el) { return el.value.trim() !== ''; }).length;
        counter.textContent = n ? 'заполнено ' + n + ' из ' + ukFields.length : 'не заполнено';
        counter.classList.toggle('full', n === ukFields.length);
      };
      var set = function (lang, focus) {
        form.setAttribute('data-lang', lang);
        if (hidden) hidden.value = lang;
        btns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-lang-to') === lang ? 'true' : 'false'); });
        editors.forEach(function (ed) { if (form.contains(ed.ta)) ed.fitFrame(); });
        try { form.dispatchEvent(new Event('langchange')); } catch (e) { /* старые браузеры */ }
        if (focus) {
          var first = $('.l-' + lang + ' input[type=text], .l-' + lang + ' textarea', form);
          if (first && first.offsetParent) first.focus();
        }
      };
      btns.forEach(function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-lang-to'), true); }); });
      ukFields.forEach(function (el) { el.addEventListener('input', count); });
      // ошибка в поле другого языка — открыть его
      var bad = $('[aria-invalid=true]', form);
      if (bad && bad.closest('.l-uk')) set('uk'); else if (bad && bad.closest('.l-ru')) set('ru');
      count();
    });

    // «Скопировать русский текст» в поле UA (с подтверждением, если там уже что-то есть)
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-copy-from]');
      if (!b) return;
      var f = b.closest('form'), from = f && f.elements[b.getAttribute('data-copy-from')], to = f && f.elements[b.getAttribute('data-copy-to')];
      if (!from || !to) return;
      var src = from.__ed ? from.__ed.getValue() : from.value;
      if (!src.trim()) return toast('Русский вариант пуст — копировать нечего', true);
      var cur = to.__ed ? to.__ed.getValue() : to.value;
      if (cur.trim() && cur !== src && !confirm('Заменить украинский текст русским? Текущий украинский вариант пропадёт.')) return;
      if (to.__ed) to.__ed.setValue(src); else { to.value = src; to.dispatchEvent(new Event('input', { bubbles: true })); }
      markDirty();
      toast('Русский текст скопирован — переведите его');
    });
  }

  // ------------------------------------------------------------------ превью в поиске Google (страницы)
  function initSerp() {
    $$('[data-serp-src]').forEach(function (el) {
      var box = $('.serp[data-serp="' + el.getAttribute('data-serp-for') + '"]');
      if (!box) return;
      var out = $(el.getAttribute('data-serp-src') === 'title' ? '[data-serp-title]' : '[data-serp-desc]', box);
      var upd = function () { out.textContent = el.value.trim() || el.placeholder || ''; };
      el.addEventListener('input', upd);
    });
    var url = $('input[name=url][data-slug-from]');
    if (url && $('.serp')) {
      url.addEventListener('input', function () {
        var p = url.value.replace(/^\/+|\/+$/g, '') || url.placeholder.replace(/^\/+|\/+$/g, '');
        $$('.serp').forEach(function (s) {
          var o = $('[data-serp-url]', s);
          if (o) o.textContent = ((s.getAttribute('data-serp') === 'uk' ? 'ua/' : '') + p).split('/').join(' › ');
        });
      });
    }
  }

  // ------------------------------------------------------------------ медиатека (media.js): кнопки появляются, если скрипт подключён
  function initMediaButtons() {
    if (!window.MediaPicker) return;
    $$('[data-media-pick][hidden], .ed-bar [data-cmd=lib][hidden]').forEach(function (b) { b.hidden = false; });
  }
  /** Есть редактор или кнопки медиатеки, а media.js на экран не подключён — подгружаем его (после defer-скриптов) */
  function loadMedia() {
    if (window.MediaPicker || !SELF || document.querySelector('script[src*="admin/media.js"]')) return;
    if (!$('textarea[data-editor], [data-media-pick]')) return;
    var sc = document.createElement('script');
    sc.src = SELF.replace(/content\.js(\?[^#]*)?$/, 'media.js$1');
    sc.onload = initMediaButtons;
    document.head.appendChild(sc);
  }

  // ------------------------------------------------------------------ сортировка строк (баннеры)
  function initSortable() {
    $$('tbody[data-sortable]').forEach(function (tb) {
      var url = tb.getAttribute('data-sortable'), dragRow = null, before = '';
      var order = function () { return $$('tr[data-id]', tb).map(function (tr) { return tr.getAttribute('data-id'); }); };
      var save = function () {
        var ids = order();
        if (ids.join(',') === before) return;
        var fd = new FormData();
        ids.forEach(function (id) { fd.append('ids[]', id); });
        post(url, fd).then(function (r) {
          if (r && r.ok) { before = ids.join(','); toast('Порядок сохранён'); } else toast((r && r.error) || 'Не удалось сохранить порядок', true);
        }).catch(function () { toast('Нет связи с сервером', true); });
      };
      before = order().join(',');
      tb.addEventListener('dragstart', function (e) {
        var h = e.target.closest && e.target.closest('.drag');
        if (!h) return;
        dragRow = h.closest('tr');
        dragRow.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragRow.getAttribute('data-id'));
        try { e.dataTransfer.setDragImage(dragRow, 20, 20); } catch (err) { /* не критично */ }
      });
      tb.addEventListener('dragover', function (e) {
        if (!dragRow) return;
        e.preventDefault();
        var tr = e.target.closest && e.target.closest('tr');
        if (!tr || tr === dragRow || tr.parentNode !== tb) return;
        var r = tr.getBoundingClientRect();
        tb.insertBefore(dragRow, e.clientY > r.top + r.height / 2 ? tr.nextSibling : tr);
      });
      tb.addEventListener('drop', function (e) { if (dragRow) e.preventDefault(); });
      tb.addEventListener('dragend', function () {
        if (!dragRow) return;
        dragRow.classList.remove('dragging');
        dragRow = null;
        save();
      });
      tb.addEventListener('click', function (e) {
        var b = e.target.closest('[data-move]');
        if (!b) return;
        var tr = b.closest('tr'), dir = +b.getAttribute('data-move');
        if (dir < 0 && tr.previousElementSibling) tb.insertBefore(tr, tr.previousElementSibling);
        else if (dir > 0 && tr.nextElementSibling) tb.insertBefore(tr.nextElementSibling, tr);
        else return;
        save();
        var again = $('[data-move="' + dir + '"]', tr);
        if (again && getComputedStyle(again).visibility !== 'hidden') again.focus();
      });
    });
  }

  // ------------------------------------------------------------------ списки строк (телефоны, способы доставки)
  function renumber(list) {
    if (!list.hasAttribute('data-renumber')) return;
    $$('.list-row', list).forEach(function (row, i) {
      var s = $('input[name$="[sort]"]', row);
      if (s) s.value = i + 1;
    });
  }
  function initLists() {
    var n = 0;
    document.addEventListener('change', function (e) {
      var c = e.target;
      if (c.type === 'checkbox' && /\[status\]$/.test(c.name || '')) { var r = c.closest('.sm-row'); if (r) r.classList.toggle('is-off', !c.checked); }
    });
    document.addEventListener('click', function (e) {
      var add = e.target.closest('[data-row-add]');
      if (add) {
        var name = add.getAttribute('data-row-add'), list = $('[data-list="' + name + '"]');
        if (!list) return;
        var tpl = $('template[data-row-tpl="' + name + '"]'), row;
        if (tpl) {
          var wrap = document.createElement(list.tagName === 'TBODY' ? 'tbody' : 'div');
          wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + Date.now().toString(36) + (n++));
          row = wrap.firstElementChild;
        } else {
          var last = $$('.list-row', list).pop();
          if (!last) return;
          row = last.cloneNode(true);
          $$('input,textarea', row).forEach(function (i) { i.value = ''; });
        }
        list.appendChild(row);
        renumber(list);
        var first = $('input[type=text],input[type=tel],input:not([type])', row);
        if (first) first.focus();
        markDirty();
        return;
      }
      var del = e.target.closest('[data-row-del]');
      if (del) {
        var row2 = del.closest('.list-row'), list2 = row2 && row2.parentNode;
        if (!row2) return;
        var used = del.getAttribute('data-used');
        if (used && !confirm('По этому способу есть заказы (' + used + '). Удалить из списка? Обычно достаточно выключить.')) return;
        if (list2.getAttribute('data-list') === 'phones' && $$('.list-row', list2).length === 1) { $('input', row2).value = ''; return; }
        row2.remove();
        renumber(list2);
        markDirty();
        return;
      }
      var up = e.target.closest('[data-row-up]');
      if (up) {
        var row3 = up.closest('.list-row');
        if (row3 && row3.previousElementSibling) {
          row3.parentNode.insertBefore(row3, row3.previousElementSibling);
          renumber(row3.parentNode);
          up.focus();
          markDirty();
        }
      }
    });
  }

  // ------------------------------------------------------------------ SEO-шаблоны: переменные и предпросмотр
  function initSeo() {
    var sEl = $('#seo-sample');
    if (!sEl) return;
    var samples = {};
    try { samples = JSON.parse(sEl.textContent); } catch (e) { return; }
    var tpl = function (t, lang) {
      var sample = samples[lang] || samples.ru || {};
      return t.replace(/\{\$([a-z_]+)(?:\.([a-z_]+))?(?:\|[^}]*)?\}/gi, function (m, a, b) {
        var v = sample[a];
        if (b) v = v && typeof v === 'object' ? v[b] : '';
        return v == null || typeof v === 'object' ? '' : String(v);
      }).trim();
    };
    $$('[data-seo]').forEach(function (el) {
      var out = el.parentNode.querySelector('.seo-prev');
      var lang = el.getAttribute('data-seo') === 'uk' ? 'uk' : 'ru';
      var ru = lang === 'uk' ? el.closest('.i18n').querySelector('[data-seo=ru]') : null;
      var upd = function () {
        var v = el.value.trim();
        if (!v && ru && ru.value.trim()) { out.innerHTML = 'Пусто — русский шаблон: <b>' + esc(tpl(ru.value.trim(), 'uk')) + '</b>'; return; }
        out.innerHTML = v ? 'Пример: <b>' + esc(tpl(v, lang)) + '</b>' : 'Пусто — будет использовано название';
      };
      if (ru) ru.addEventListener('input', upd);
      el.addEventListener('input', upd);
      el.addEventListener('focus', function () { var g = el.closest('[data-seo-group]'); if (g) g.__last = el; });
      upd();
    });
    document.addEventListener('mousedown', function (e) { if (e.target.closest('.var')) e.preventDefault(); });
    document.addEventListener('click', function (e) {
      var b = e.target.closest('.var');
      if (!b) return;
      var g = b.closest('[data-seo-group]'), el = g && (g.__last || $('[data-seo]', g));
      if (!el || el.disabled) return;
      var s = el.selectionStart != null ? el.selectionStart : el.value.length, en = el.selectionEnd != null ? el.selectionEnd : s;
      var v = b.getAttribute('data-var');
      el.value = el.value.slice(0, s) + v + el.value.slice(en);
      el.focus();
      el.setSelectionRange(s + v.length, s + v.length);
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  // ------------------------------------------------------------------ валюты: пример пересчёта
  function initRates() {
    $$('[data-rate]').forEach(function (inp) {
      var c = inp.getAttribute('data-rate'), out = $('[data-rate-example="' + c + '"]');
      var upd = function () {
        var r = parseFloat(inp.value.replace(',', '.').replace(/\s/g, ''));
        out.textContent = r > 0 ? 'Пример: товар за 1 020 грн. покупатель увидит как ' + (1020 / r).toFixed(2) + ' ' + c : '';
      };
      inp.addEventListener('input', upd);
      upd();
    });
  }

  // ------------------------------------------------------------------ редиректы
  function initRedirects() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-edit-redirect]');
      if (b) {
        var f = $('form.rd-add');
        if (!f) return;
        f.elements.from_url.value = b.getAttribute('data-from');
        f.elements.to_url.value = b.getAttribute('data-to');
        f.elements.code.value = b.getAttribute('data-code');
        f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        f.elements.to_url.focus();
        f.elements.to_url.select();
      }
      var all = e.target.closest('[data-check-all]');
      if (all) $$('input[type=checkbox][name="' + all.getAttribute('data-check-all') + '"]').forEach(function (c) { c.checked = all.checked; });
    });
  }

  function init() {
    $$('textarea[data-editor]').forEach(initEditor);
    initForms();
    initImageFields();
    initBannerPreview();
    initLang();
    initSerp();
    initSortable();
    initLists();
    initSeo();
    initRates();
    initRedirects();
    initMediaButtons();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  // media.js подключается после этого файла — кнопки «Медиатека» включаем, когда он выполнится
  document.addEventListener('DOMContentLoaded', function () { initMediaButtons(); loadMedia(); });
  window.addEventListener('load', initMediaButtons);
})();

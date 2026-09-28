/* Админка → «Промокоды».
   Список: отметка строк и массовые действия (.bulk-bar).
   Форма: генератор кода, код ЗАГЛАВНЫМИ, единица скидки (%/грн), быстрый выбор срока, поиск категорий/брендов/товаров
   для ограничений (чипы с hidden-полями), сводка «что получится» справа, предупреждение о несохранённых изменениях. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var LIST_MAX = 300;

  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function plural(n, a, b, c) {
    var x = n % 10, y = n % 100;
    return x === 1 && y !== 11 ? a : (x >= 2 && x <= 4 && (y < 10 || y >= 20) ? b : c);
  }
  function money(v) {
    return String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' грн.';
  }
  function num(v) {
    var n = parseFloat(String(v || '').replace(/\s+/g, '').replace(',', '.'));
    return isFinite(n) ? n : 0;
  }
  function ymd(d) {
    var p = function (x) { return (x < 10 ? '0' : '') + x; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }
  function dmy(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
  }

  // ================================================================ список: массовые действия
  var bulk = $('[data-cp-bulk]');
  if (bulk) {
    var all = $('[data-cp-all]', bulk), apply = $('[data-cp-apply]', bulk), picked = $('[data-cp-picked]', bulk);
    var act = $('select[name=action]', bulk);
    var boxes = $$('input[name="ids[]"]', bulk);
    var syncBulk = function () {
      var n = boxes.filter(function (b) { return b.checked; }).length;
      if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
      if (picked) picked.textContent = n ? 'Отмечено: ' + n : 'Отметьте промокоды галочками';
      if (!apply || !act) return;
      apply.disabled = !n || !act.value;
      var what = n + ' ' + plural(n, 'промокод', 'промокода', 'промокодов');
      apply.setAttribute('data-confirm', act.value === 'delete'
        ? 'Удалить ' + what + '? В заказах, где они применены, скидка останется.'
        : (act.value === 'disable' ? 'Выключить ' + what + '? Покупатели не смогут их применить.' : 'Включить ' + what + '?'));
    };
    if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); syncBulk(); });
    boxes.forEach(function (b) { b.addEventListener('change', syncBulk); });
    if (act) act.addEventListener('change', syncBulk);
    syncBulk();
  }

  // ================================================================ форма промокода
  var form = $('[data-cp-form]');
  if (!form) return;
  var editable = !form.querySelector('fieldset[disabled]');
  var dirty = false, sending = false;
  var dirtyNote = $('[data-cp-dirty]');
  var markDirty = function () {
    if (!editable) return;
    dirty = true;
    if (dirtyNote) dirtyNote.hidden = false;
  };

  // ---- код: заглавные буквы без пробелов, генератор
  var code = $('[data-cp-code]', form);
  if (code) {
    code.addEventListener('input', function () {
      var pos = code.selectionStart, v = code.value, nv = v.replace(/\s+/g, '').toUpperCase();
      if (nv !== v) {
        code.value = nv;
        if (pos != null) { pos = Math.max(0, pos - (v.length - nv.length)); code.setSelectionRange(pos, pos); }
      }
    });
  }
  var gen = $('[data-cp-gen]', form);
  if (gen && code) {
    gen.addEventListener('click', function () {
      // «SALE-» в поле — приставка: получится SALE-7KQ2MX9A
      var m = /^([A-Za-z0-9_]{1,11})-$/.exec(code.value.trim());
      gen.disabled = true;
      getJSON('/admin/coupons/generate.json' + (m ? '?prefix=' + encodeURIComponent(m[1]) : ''))
        .then(function (r) {
          if (r && r.ok) { code.value = r.code; code.removeAttribute('aria-invalid'); markDirty(); summary(); code.focus(); }
          else alert((r && r.error) || 'Не удалось сгенерировать код');
        })
        .catch(function () { alert('Нет связи с сервером — попробуйте ещё раз'); })
        .then(function () { gen.disabled = false; });
    });
  }

  // ---- тип скидки: % или грн, потолок только для процента
  var type = $('[data-cp-type]', form), value = $('[data-cp-value]', form), unit = $('[data-cp-unit]', form), pct = $('[data-cp-percent]', form);
  var syncType = function () {
    if (!type) return;
    var isPct = type.value === 'percent';
    if (unit) unit.textContent = isPct ? '%' : 'грн';
    if (value) { if (isPct) value.setAttribute('max', '100'); else value.removeAttribute('max'); value.step = isPct ? '0.01' : '1'; }
    if (pct) pct.hidden = !isPct;
  };
  if (type) type.addEventListener('change', syncType);
  syncType();

  // ---- срок: быстрые кнопки
  var starts = $('[data-cp-starts]', form), expires = $('[data-cp-expires]', form);
  $$('[data-cp-days]', form).forEach(function (b) {
    b.addEventListener('click', function () {
      if (!expires) return;
      var d = b.getAttribute('data-cp-days');
      if (d === '0') {
        expires.value = '';
      } else {
        var base = new Date();
        var st = starts && /^\d{4}-\d{2}-\d{2}$/.test(starts.value) ? new Date(starts.value + 'T00:00:00') : null;
        if (st && st > base) base = st;
        if (d === 'eom') base = new Date(base.getFullYear(), base.getMonth() + 1, 0);
        else base.setDate(base.getDate() + parseInt(d, 10) - 1);   // «неделя» = сегодня + 6 дней, последний день включительно
        expires.value = ymd(base);
      }
      expires.removeAttribute('aria-invalid');
      markDirty(); summary();
    });
  });

  // ---- ограничения: поиск и чипы
  $$('[data-cp-pick]', form).forEach(function (box) {
    var kind = box.getAttribute('data-cp-pick'), name = box.getAttribute('data-name');
    var chips = $('[data-cp-chips]', box), q = $('[data-cp-q]', box), drop = $('[data-cp-drop]', box);
    var timer = 0, seq = 0, items = [], active = -1, quiet = false;

    var pickedIds = function () {
      var o = {};
      $$('.chip[data-id]', chips).forEach(function (c) { o[c.getAttribute('data-id')] = 1; });
      return o;
    };
    var close = function () { if (drop) { drop.hidden = true; drop.innerHTML = ''; } items = []; active = -1; if (q) q.setAttribute('aria-expanded', 'false'); };
    var highlight = function (i) {
      var lis = $$('li[data-i]', drop);
      lis.forEach(function (li) { li.classList.remove('on'); li.setAttribute('aria-selected', 'false'); });
      active = i;
      if (lis[i]) { lis[i].classList.add('on'); lis[i].setAttribute('aria-selected', 'true'); lis[i].scrollIntoView({ block: 'nearest' }); }
    };
    var add = function (it) {
      var have = pickedIds();
      if (have[it.id]) return;
      if (Object.keys(have).length >= LIST_MAX) { alert('Не больше ' + LIST_MAX + ' элементов в одном ограничении'); return; }
      var chip = el('span', 'chip on');
      chip.setAttribute('data-id', it.id);
      chip.appendChild(document.createTextNode(it.name));
      var h = el('input'); h.type = 'hidden'; h.name = name; h.value = it.id;
      var x = el('button', 'cp-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Убрать «' + it.name + '»');
      chip.appendChild(h); chip.appendChild(x);
      chips.appendChild(chip);
      markDirty(); summary();
    };
    var render = function (list, query) {
      if (!drop) return;
      drop.innerHTML = ''; items = list; active = -1;
      var have = pickedIds();
      if (!list.length) {
        drop.appendChild(el('li', 'cp-none', kind === 'product' && query.length < 2 ? 'Введите хотя бы 2 символа' : 'Ничего не найдено'));
      }
      list.forEach(function (it, i) {
        var li = el('li');
        li.setAttribute('role', 'option');
        li.setAttribute('data-i', i);
        li.appendChild(document.createTextNode(it.name));
        if (it.sub) li.appendChild(el('small', '', it.sub));
        if (have[it.id]) { li.setAttribute('aria-disabled', 'true'); li.title = 'Уже выбрано'; }
        drop.appendChild(li);
      });
      drop.hidden = false;
      if (q) q.setAttribute('aria-expanded', 'true');
    };
    var search = function () {
      var query = q.value.trim(), my = ++seq;
      if (kind === 'product' && query.length < 2 && !/^\d+$/.test(query)) { render([], query); return; }
      getJSON('/admin/coupons/lookup.json?kind=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(query))
        .then(function (r) { if (my === seq && document.activeElement === q) render(r && r.ok ? r.items : [], query); })
        .catch(function () { /* нет связи — просто не показываем подсказки */ });
    };
    var choose = function (i) {
      var it = items[i];
      if (!it) return;
      if (pickedIds()[it.id]) return;
      add(it);
      q.value = '';
      close();
      q.focus();
    };

    if (chips) chips.addEventListener('click', function (e) {
      var x = e.target.closest('.cp-x');
      if (!x || !editable) return;
      var chip = x.closest('.chip');
      if (chip) chip.remove();
      markDirty(); summary();
      // фокус — в поиск (клавиатура не теряется), но без выпадающего списка: он закрыл бы кнопку «Сохранить»
      if (q) { quiet = true; q.focus(); quiet = false; }
    });
    if (!q || !drop) return;
    q.setAttribute('role', 'combobox');
    q.setAttribute('aria-autocomplete', 'list');
    q.setAttribute('aria-expanded', 'false');
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 220); });
    q.addEventListener('focus', function () { if (!quiet && (kind !== 'product' || q.value.trim() !== '')) search(); });
    q.addEventListener('blur', function () { setTimeout(close, 150); });
    q.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {                       // Enter в поиске не отправляет форму
        e.preventDefault();
        if (active >= 0) choose(active);
        else if (items.length === 1 && !pickedIds()[items[0].id]) choose(0);
      } else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && items.length) {
        e.preventDefault();
        var have = pickedIds(), step = e.key === 'ArrowDown' ? 1 : -1;   // уже выбранные пропускаем
        for (var i = active + step; i >= 0 && i < items.length; i += step) {
          if (!have[items[i].id]) { highlight(i); break; }
        }
      } else if (e.key === 'Escape') {
        if (!drop.hidden) { e.preventDefault(); close(); }
      }
    });
    drop.addEventListener('mousedown', function (e) {          // mousedown — раньше, чем blur поля
      var li = e.target.closest('li[data-i]');
      if (!li) return;
      e.preventDefault();
      choose(parseInt(li.getAttribute('data-i'), 10));
    });
  });

  // ---- сводка справа: как промокод будет работать
  var sumBox = $('[data-cp-summary]', form);
  var field = function (n) { var f = form.elements.namedItem(n); return f ? f.value : ''; };
  function summary() {
    if (!sumBox) return;
    var rows = [];
    var v = num(field('value')), isPct = field('type') !== 'fixed';
    var disc = v > 0 ? '−' + (isPct ? (Math.round(v * 100) / 100) + '%' : money(v)) : '—';
    if (isPct && num(field('max_discount')) > 0) disc += ', не больше ' + money(num(field('max_discount')));
    rows.push(['Скидка', disc]);

    var scope = [];
    [['category', ['категория', 'категории', 'категорий']], ['brand', ['бренд', 'бренда', 'брендов']], ['product', ['товар', 'товара', 'товаров']]]
      .forEach(function (k) {
        var chips = $$('[data-cp-pick="' + k[0] + '"] .chip[data-id]', form);
        if (chips.length === 1) scope.push(chips[0].firstChild.textContent);
        else if (chips.length) scope.push(chips.length + ' ' + plural(chips.length, k[1][0], k[1][1], k[1][2]));
      });
    rows.push(['Товары', scope.length ? scope.join(' + ') : 'весь заказ']);

    var cond = [], ms = num(field('min_sum')), mb = parseInt(field('min_boxes'), 10) || 0;
    if (ms > 0) cond.push('от ' + money(ms));
    if (mb > 0) cond.push('от ' + mb + ' ' + plural(mb, 'ящика', 'ящиков', 'ящиков'));
    rows.push(['Заказ', cond.length ? cond.join(', ') : 'любой']);

    var s = dmy(field('starts_at')), e = dmy(field('expires_at'));
    rows.push(['Срок', s || e ? (s ? 'с ' + s + ' ' : '') + (e ? 'по ' + e : 'бессрочно') : 'бессрочно']);

    var lim = [], ul = parseInt(field('usage_limit'), 10) || 0, pc = parseInt(field('per_customer_limit'), 10) || 0;
    if (ul === 1) lim.push('одноразовый');
    else if (ul > 0) lim.push(ul + ' ' + plural(ul, 'применение', 'применения', 'применений'));
    if (pc > 0) lim.push(pc + ' ' + plural(pc, 'раз', 'раза', 'раз') + ' на клиента');
    rows.push(['Лимит', lim.length ? lim.join(', ') : 'без ограничений']);

    var st = form.elements.namedItem('status');
    if (st && !st.checked) rows.push(['Статус', 'выключен — код не примут']);

    var dl = el('dl', 'detail');
    rows.forEach(function (r) {
      var d = el('div');
      d.appendChild(el('dt', '', r[0]));
      d.appendChild(el('dd', '', r[1]));
      dl.appendChild(d);
    });
    sumBox.innerHTML = '';
    sumBox.appendChild(dl);
  }
  summary();

  // ---- несохранённые изменения
  form.addEventListener('input', function (e) { if (!e.target.matches('[data-cp-q]')) { markDirty(); summary(); } });
  form.addEventListener('change', function (e) { if (!e.target.matches('[data-cp-q]')) { markDirty(); summary(); } });
  // слушатель на document срабатывает после подтверждения из admin.js: отменённое удаление не снимает защиту
  document.addEventListener('submit', function (e) {
    if (!e.defaultPrevented && (e.target === form || e.target.id === 'cp-del')) sending = true;
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty && !sending) { e.preventDefault(); e.returnValue = ''; }
  });
})();

/* Админка, раздел «Продажи».
   - быстрая смена статуса в списке заказов (select.sl-st → POST /admin/orders/{id}/status/);
   - массовая смена статуса отмеченных / всех найденных заказов [data-sl-bulk];
   - редактор состава заказа [data-oi]: пересчёт на лету, удаление, добавление товара через поиск;
   - поиск клиента в новом заказе [data-cust-picker];
   - заявки: смена статуса / удаление кнопками [data-req]. */
(function () {
  'use strict';

  function money(v) {
    var n = Math.round((+v || 0) * 100) / 100;
    var s = (Math.round(n) === n ? n.toFixed(0) : n.toFixed(2)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return s + ' грн.';
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  var toastT;
  function toast(msg, err) {
    var t = document.querySelector('.sl-toast');
    if (!t) { t = el('div', 'sl-toast'); t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.toggle('err', !!err);
    requestAnimationFrame(function () { t.classList.add('show'); });
    clearTimeout(toastT);
    toastT = setTimeout(function () { t.classList.remove('show'); }, 3500);
  }
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); });
  }
  function debounce(fn, ms) {
    var t;
    return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); };
  }

  // ------------------------------------------------ быстрая смена статуса в списке
  document.addEventListener('change', function (e) {
    var s = e.target.closest('select.sl-st');
    if (!s) return;
    var prev = s.getAttribute('data-prev'), val = s.value;
    // удаление и возврат — только после подтверждения (одним случайным кликом не испортить заказ)
    if ((val === 'deleted' || val === 'refunded') && !confirm('Перевести заказ в статус «' + s.options[s.selectedIndex].text + '»?')) { s.value = prev; return; }
    s.classList.add('sl-busy');
    Adm.post('/admin/orders/' + s.getAttribute('data-order') + '/status/', { status: val }).then(function (r) {
      s.classList.remove('sl-busy');
      if (!r.ok) throw new Error(r.error || 'Ошибка');
      s.className = (s.className.replace(/\bst-\S+/g, '') + ' st-' + val).replace(/\s+/g, ' ').trim();
      s.setAttribute('data-prev', val);
      var tr = s.closest('tr'); if (tr) tr.classList.toggle('unread', val === 'new');
      toast(r.message || 'Статус изменён');
    }).catch(function (err) {
      s.classList.remove('sl-busy');
      s.value = prev;
      toast(err.message || 'Не удалось изменить статус', true);
    });
  });

  // ------------------------------------------------ массовая смена статуса в списке (form[data-sl-bulk] → POST /admin/orders/bulk/)
  var bulk = document.querySelector('form[data-sl-bulk]');
  if (bulk) {
    var bar = bulk.querySelector('[data-sl-bulk-bar]'), nEl = bulk.querySelector('[data-sl-bulk-n]'),
      allBox = bulk.querySelector('[data-sl-bulk-all]'), heads = [].slice.call(bulk.querySelectorAll('[data-sl-check-all]')),
      boxes = [].slice.call(bulk.querySelectorAll('input[name="ids[]"]')), total = +bulk.getAttribute('data-total') || 0;
    var picked = function () { return boxes.filter(function (b) { return b.checked; }).length; };
    var sync = function () {
      var n = picked(), all = !!(allBox && allBox.checked);
      nEl.textContent = (all ? total : n).toLocaleString('ru-RU');
      bar.hidden = !n && !all;
      heads.forEach(function (h) { h.checked = n > 0 && n === boxes.length; h.indeterminate = n > 0 && n < boxes.length; });
    };
    bulk.addEventListener('change', function (e) {
      var isHead = heads.indexOf(e.target) >= 0;   // галочка в шапке таблицы или (на телефоне, где шапки нет) над ней
      if (isHead) boxes.forEach(function (b) { b.checked = e.target.checked; });
      if (e.target === allBox && allBox.checked) boxes.forEach(function (b) { b.checked = true; });
      if (allBox && (isHead || e.target.name === 'ids[]') && picked() < boxes.length) allBox.checked = false;
      sync();
    });
    bulk.addEventListener('submit', function (e) {
      var sel = bulk.querySelector('select[name=to_status]');
      if (!sel.value) { e.preventDefault(); sel.focus(); toast('Выберите новый статус', true); return; }
      if (!confirm('Перевести заказов: ' + nEl.textContent + ' — в статус «' + sel.options[sel.selectedIndex].text + '»? Письма клиентам не отправляются.')) e.preventDefault();
    });
    sync();
  }

  // ------------------------------------------------ универсальный выпадающий поиск
  function picker(input, drop, url, render, onPick) {
    var items = [], act = -1, lastQ = '';
    function close() { drop.classList.add('hidden'); act = -1; }
    function mark() {
      [].forEach.call(drop.children, function (c, i) { c.classList.toggle('act', i === act); });
    }
    var search = debounce(function () {
      var q = input.value.trim();
      if (q.length < 2) { close(); return; }
      lastQ = q;
      getJSON(url + encodeURIComponent(q)).then(function (r) {
        if (q !== lastQ) return;
        items = (r && r.items) || [];
        drop.textContent = '';
        if (!items.length) drop.appendChild(el('div', 'sl-drop-msg', 'Ничего не найдено'));
        items.forEach(function (it, i) {
          var b = render(it);
          b.setAttribute('role', 'option');
          b.addEventListener('mousedown', function (ev) { ev.preventDefault(); onPick(items[i]); input.value = ''; close(); });
          drop.appendChild(b);
        });
        act = -1;
        drop.classList.remove('hidden');
      }).catch(function () { toast('Ошибка поиска', true); });
    }, 250);
    input.addEventListener('input', search);
    input.addEventListener('focus', function () { if (drop.children.length && input.value.trim().length > 1) drop.classList.remove('hidden'); });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
    input.addEventListener('keydown', function (e) {
      if (drop.classList.contains('hidden')) return;
      if (e.key === 'ArrowDown') { act = Math.min(items.length - 1, act + 1); mark(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { act = Math.max(0, act - 1); mark(); e.preventDefault(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (items[act >= 0 ? act : 0]) { onPick(items[act >= 0 ? act : 0]); input.value = ''; close(); } }
      else if (e.key === 'Escape') close();
    });
  }

  // ------------------------------------------------ редактор состава заказа
  document.querySelectorAll('[data-oi]').forEach(function (box) {
    var body = box.querySelector('[data-oi-body]');
    var form = box.closest('form');
    var dirty = form && form.querySelector('[data-oi-dirty]');
    var seq = 0;

    function recalc() {
      var sub = 0, boxes = 0, pairs = 0;
      body.querySelectorAll('[data-oi-row]').forEach(function (tr) {
        var bq = +tr.getAttribute('data-bq') || 1, q0 = +tr.getAttribute('data-q0') || 0;
        var b = Math.max(1, parseInt(tr.querySelector('[data-oi-boxes]').value, 10) || 1);
        var p = parseFloat(String(tr.querySelector('[data-oi-price]').value).replace(',', '.')) || 0;
        // ящики не меняли — пары как были (у старых заказов бывает некратное количество)
        var q = (q0 && b === Math.ceil(q0 / bq)) ? q0 : b * bq;
        tr.querySelector('[data-oi-pairs]').textContent = q;
        tr.querySelector('[data-oi-sum]').textContent = money(q * p);
        sub += q * p; boxes += Math.ceil(q / bq); pairs += q;
      });
      var ship = parseFloat(String(box.querySelector('[data-oi-ship]').value).replace(',', '.')) || 0;
      var disc = parseFloat(String(box.querySelector('[data-oi-disc]').value).replace(',', '.')) || 0;
      box.querySelector('[data-oi-bp]').textContent = boxes + ' / ' + pairs;
      box.querySelector('[data-oi-sub]').textContent = money(sub);
      box.querySelector('[data-oi-total]').textContent = money(Math.max(0, sub + ship - disc));
      box.querySelector('[data-oi-empty]').classList.toggle('hidden', !!body.querySelector('[data-oi-row]'));
    }
    function touched() { if (dirty) dirty.classList.remove('hidden'); recalc(); }

    box.addEventListener('input', function (e) {
      if (e.target.matches('[data-oi-boxes],[data-oi-price],[data-oi-ship],[data-oi-disc]')) touched();
    });
    box.addEventListener('click', function (e) {
      var d = e.target.closest('[data-oi-del]');
      if (!d) return;
      var rows = body.querySelectorAll('[data-oi-row]');
      if (rows.length <= 1 && !box.closest('[data-new-order]')) { toast('В заказе должна остаться хотя бы одна позиция', true); return; }
      d.closest('tr').remove();
      touched();
    });

    function addRow(p) {
      // тот же товар уже есть — просто +1 ящик
      var ex = body.querySelector('input[name$="[product_id]"][value="' + (+p.id) + '"]');
      if (ex) {
        var bi = ex.closest('tr').querySelector('[data-oi-boxes]');
        bi.value = (parseInt(bi.value, 10) || 0) + 1;
        touched(); toast('Добавлен ещё 1 ящик: ' + p.name);
        return;
      }
      var key = 'n' + (Date.now() % 100000) + (seq++);
      var tr = el('tr', 'sl-new-row');
      tr.setAttribute('data-oi-row', '');
      tr.setAttribute('data-bq', p.box_qty);
      tr.setAttribute('data-q0', '0');
      var ph = el('td', 'sl-ph'), img = el('img');
      img.src = p.img; img.alt = ''; img.width = 44; img.height = 44;
      ph.appendChild(img);
      var nm = el('td', 'sl-iname'); nm.setAttribute('data-l', 'Товар');
      [['id', '0'], ['product_id', String(p.id)]].forEach(function (h) {
        var i = el('input'); i.type = 'hidden'; i.name = 'items[' + key + '][' + h[0] + ']'; i.value = h[1]; nm.appendChild(i);
      });
      var a = el('a', null, p.name); a.href = p.link; a.target = '_blank'; a.rel = 'noopener';
      nm.appendChild(a);
      var subl = el('div', 'sl-isub muted', (p.sku ? 'Арт.: ' + p.sku : '') + (p.size && p.size !== p.sku ? ' · р. ' + p.size : '') + ' · ');
      var al = el('a', null, 'в админке'); al.href = '/admin/products/' + p.id + '/';
      subl.appendChild(al);
      if (!p.status) subl.appendChild(el('span', 'sl-warn', ' · скрыт'));
      nm.appendChild(subl);
      function numTd(label, content) { var td = el('td', 'num'); td.setAttribute('data-l', label); if (content instanceof Node) td.appendChild(content); else td.textContent = content; return td; }
      function inp(name, val, cls, attr) {
        var i = el('input', cls); i.type = 'number'; i.name = 'items[' + key + '][' + name + ']'; i.value = val; i.required = true;
        i.setAttribute(attr, ''); i.setAttribute('aria-label', (name === 'boxes' ? 'Ящиков: ' : 'Цена за пару: ') + p.name);
        return i;
      }
      var bx = inp('boxes', '1', 'sl-in', 'data-oi-boxes'); bx.min = 1; bx.max = 9999; bx.step = 1;
      var pr = inp('price', String(p.price), 'sl-in sl-in-price', 'data-oi-price'); pr.min = 0; pr.step = '0.01';
      var pairsTd = numTd('Пар', String(p.box_qty)); pairsTd.setAttribute('data-oi-pairs', '');
      var sum = el('b'); sum.setAttribute('data-oi-sum', '');
      var del = el('td', 'sl-idel'), db = el('button', 'btn btn-sm btn-d');
      db.type = 'button'; db.setAttribute('data-oi-del', ''); db.setAttribute('aria-label', 'Удалить позицию ' + p.name);
      db.innerHTML = '<svg class="i" aria-hidden="true"><use href="#i-trash"/></svg>';
      del.appendChild(db);
      [ph, nm, numTd('Пар в ящике', String(p.box_qty)), numTd('Ящиков', bx), pairsTd, numTd('Цена за пару', pr), numTd('Сумма', sum), del]
        .forEach(function (td) { tr.appendChild(td); });
      body.appendChild(tr);
      touched();
      toast('Добавлено: ' + p.name);
    }

    var pk = box.querySelector('[data-picker]');
    if (pk) {
      picker(pk.querySelector('[data-picker-input]'), pk.querySelector('[data-picker-drop]'), '/admin/orders/products.json?q=', function (it) {
        var b = el('button', 'sl-opt'); b.type = 'button';
        var img = el('img'); img.src = it.img; img.alt = ''; img.width = 40; img.height = 40; img.loading = 'lazy';
        var t = el('span', 'sl-opt-t');
        t.appendChild(el('b', null, it.name));
        t.appendChild(el('small', null, 'ID ' + it.id + (it.sku ? ' · арт. ' + it.sku : '') + (it.size ? ' · р. ' + it.size : '') + ' · ' + it.box_qty + ' пар/ящ.'
          + (it.status ? '' : ' · скрыт') + (it.in_stock ? '' : ' · нет в наличии')));
        b.appendChild(img); b.appendChild(t); b.appendChild(el('span', 'sl-opt-p', money(it.price)));
        return b;
      }, addRow);
    }

    if (form) form.addEventListener('submit', function (e) {
      if (!body.querySelector('[data-oi-row]')) { e.preventDefault(); toast('Добавьте хотя бы один товар', true); }
    });
    recalc();
  });

  // ------------------------------------------------ новый заказ: поиск клиента
  var cp = document.querySelector('[data-cust-picker]');
  if (cp) {
    var form = cp.closest('form');
    var idEl = form.querySelector('[data-cust-id]');
    var sel = form.querySelector('[data-cust-sel]');
    var hint = form.querySelector('[data-cust-hint]');
    var fld = function (n) { return form.querySelector('[data-cust-f="' + n + '"]'); };
    picker(cp.querySelector('[data-cust-input]'), cp.querySelector('[data-cust-drop]'), '/admin/customers/search.json?q=', function (c) {
      var b = el('button', 'sl-opt'); b.type = 'button';
      var t = el('span', 'sl-opt-t');
      t.appendChild(el('b', null, c.name || ('Клиент #' + c.id)));
      t.appendChild(el('small', null, [c.phone_f, c.email, c.city].filter(Boolean).join(' · ') + ' · заказов: ' + c.orders_count));
      b.appendChild(t);
      return b;
    }, function (c) {
      idEl.value = c.id;
      fld('name').value = c.name || '';
      fld('phone').value = c.phone_f || c.phone || '';
      fld('email').value = c.email || '';
      if (c.city) fld('city').value = c.city;
      if (c.lang && fld('lang')) fld('lang').value = c.lang;
      sel.querySelector('[data-cust-name]').textContent = (c.name || 'Клиент') + ' · #' + c.id + ' · заказов: ' + c.orders_count;
      sel.classList.remove('hidden');
      if (hint) hint.hidden = true;
    });
    form.querySelector('[data-cust-clear]').addEventListener('click', function () {
      idEl.value = '0';
      ['name', 'phone', 'email', 'city'].forEach(function (n) { fld(n).value = ''; });
      sel.classList.add('hidden');
      if (hint) hint.hidden = false;
      fld('name').focus();
    });
  }

  // ------------------------------------------------ заявки: обработано / вернуть / удалить
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-req]');
    if (!b) return;
    var act = b.getAttribute('data-req');
    if (act === 'delete' && !confirm('Удалить заявку? Это действие нельзя отменить.')) return;
    var tr = b.closest('[data-req-row]');
    b.disabled = true;
    Adm.post('/admin/requests/' + tr.getAttribute('data-req-row') + '/status/', { status: act }).then(function (r) {
      b.disabled = false;
      if (!r.ok) throw new Error(r.error || 'Ошибка');
      toast(r.message || 'Готово');
      if (act === 'delete' || tr.hasAttribute('data-req-filtered')) { tr.remove(); return; }
      var done = act === 'done';
      tr.classList.toggle('unread', !done);
      tr.classList.toggle('sl-req-done', done);
      var st = tr.querySelector('[data-req-st]');
      if (st) { st.textContent = done ? 'Обработана' : 'Новая'; st.className = 'st ' + (done ? 'st-completed' : 'st-new'); }
      tr.querySelector('[data-req="done"]').classList.toggle('hidden', done);
      tr.querySelector('[data-req="new"]').classList.toggle('hidden', !done);
    }).catch(function (err) { b.disabled = false; toast(err.message || 'Ошибка', true); });
  });
})();

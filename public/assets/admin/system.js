/* Раздел «Система»: безопасность, сотрудники, мой аккаунт, почта, состояние системы.
   - [data-add-ip] — добавить свой IP в список разрешённых;
   - [data-toggle-pass] — показать/скрыть пароль; [data-copy] — скопировать показанный один раз пароль;
   - select[data-role-hint] — подсказка к выбранной роли; смена e-mail в «Мой аккаунт» просит текущий пароль;
   - [data-preset] — заполнить SMTP для Gmail / Ukr.net / почты хостинга;
   - «Состояние системы»: проверка закрытых служебных адресов (probe.json) и индикатор перестройки индекса. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-add-ip]');
    if (b) {
      var ta = $('#sys-ips'), ip = b.getAttribute('data-add-ip');
      if (!ta) return;
      var list = ta.value.split(/[\s,;]+/).filter(Boolean);
      if (list.indexOf(ip) === -1) list.push(ip);
      ta.value = list.join('\n');
      ta.focus();
      return;
    }
    b = e.target.closest('[data-toggle-pass]');
    if (b) {
      var inp = b.parentNode.querySelector('input');
      if (!inp) return;
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.textContent = show ? 'Скрыть' : 'Показать';
      return;
    }
    b = e.target.closest('[data-copy]');
    if (b) {
      var src = b.parentNode.querySelector('[data-copy-src]');
      if (!src) return;
      var done = function () { b.textContent = 'Скопировано'; setTimeout(function () { b.textContent = 'Копировать'; }, 2000); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(src.textContent.trim()).then(done, function () { selectText(src); });
      } else { selectText(src); try { document.execCommand('copy'); done(); } catch (err) { /* выделено — можно скопировать вручную */ } }
      return;
    }
    b = e.target.closest('[data-preset]');
    if (b) {
      var p = JSON.parse(b.getAttribute('data-preset') || '{}');
      var host = $('#sys-smtp-host'), port = $('#sys-smtp-port'), sec = $('#sys-smtp-secure'), how = $('#sys-preset-how');
      if (host) host.value = p.host || '';
      if (port) port.value = p.port || '';
      if (sec) sec.value = p.secure || '';
      if (how) { how.textContent = p.how || ''; how.classList.remove('hidden'); }
      $$('[data-preset]').forEach(function (x) { x.classList.toggle('on', x === b); });
      var user = document.querySelector('input[name=smtp_user]');
      if (user && !user.value) user.focus();
    }
  });

  function selectText(el) {
    var r = document.createRange(); r.selectNodeContents(el);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

  // подсказка к роли
  $$('select[data-role-hint]').forEach(function (sel) {
    var out = $(sel.getAttribute('data-role-hint'));
    sel.addEventListener('change', function () {
      var o = sel.options[sel.selectedIndex];
      if (out && o) out.textContent = o.getAttribute('data-hint') || '';
    });
  });

  // «Мой аккаунт»: поле текущего пароля нужно только при смене e-mail
  var em = $('input[name=email][data-orig]'), cur = $('#sys-email-current');
  if (em && cur && cur.classList.contains('sys-if-email')) {
    var sync = function () { cur.classList.toggle('hidden', em.value.trim().toLowerCase() === em.getAttribute('data-orig').toLowerCase()); };
    em.addEventListener('input', sync);
    sync();
  }

  // «Состояние системы»: перестройка индекса занимает секунды — показываем, что идёт работа
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.hasAttribute('data-reindex') || e.defaultPrevented) return;
    var btn = f.querySelector('button');
    if (btn) { btn.disabled = true; btn.textContent = 'Перестраиваем индекс…'; }
  });

  // «Состояние системы»: иконки меток берём из уже выведенных строк (одинаковые с сервером)
  var icons = {}, fallbackIcon = { ok: '✓', warn: '!', bad: '✕' };
  function icon(state) {
    if (!icons[state]) { var m = $('.sys-status td.mark.' + state); icons[state] = m ? m.innerHTML : fallbackIcon[state]; }
    return icons[state];
  }
  function getJson(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
  }

  // группа «Каталог», если её не было в кэше, догружается отдельным запросом
  $$('tbody[data-defer]').forEach(function (tb) {
    var card = tb.closest('.card'), note = card && card.querySelector('[data-defer-note]');
    getJson(tb.getAttribute('data-defer'))
      .then(function (d) { tb.innerHTML = d.html || ''; tb.removeAttribute('data-defer'); if (note) note.remove(); retally(); })
      .catch(function () {
        var v = tb.querySelector('.sys-value'); if (v) v.textContent = 'Не удалось посчитать — обновите страницу';
        if (note) note.textContent = 'ошибка';
      });
  });

  // закрыты ли служебные адреса (запрос к сайту идёт с сервера, до 4 секунд)
  var rows = $$('tr[data-probe]');
  if (rows.length) {
    rows.forEach(function (tr) { tr.classList.add('is-probing'); });
    getJson('/admin/status/probe.json')
      .then(function (d) {
        (d.items || []).forEach(function (it) {
          var tr = rows.filter(function (x) { return x.getAttribute('data-probe') === it.path; })[0];
          if (!tr) return;
          tr.classList.remove('is-probing');
          var mark = tr.querySelector('td.mark');
          mark.className = 'mark ' + it.state;
          mark.innerHTML = icon(it.state);
          var val = tr.querySelector('.sys-value');
          if (val) val.firstChild.nodeValue = it.value + ' ';
        });
        retally();
      })
      .catch(function () {
        rows.forEach(function (tr) {
          tr.classList.remove('is-probing');
          var val = tr.querySelector('.sys-value'); if (val) val.firstChild.nodeValue = 'Не удалось проверить — обновите страницу ';
        });
      });
  }

  function retally() {
    var t = { ok: 0, warn: 0, bad: 0 };
    $$('.sys-status td.mark').forEach(function (m) { ['ok', 'warn', 'bad'].forEach(function (s) { if (m.classList.contains(s)) t[s]++; }); });
    Object.keys(t).forEach(function (s) {
      var box = $('#sys-tally [data-tally="' + s + '"]');
      if (!box) return;
      box.querySelector('span').textContent = t[s];
      if (s === 'warn') box.classList.toggle('hot', t.warn > 0);
      if (s === 'bad') box.classList.toggle('warn', t.bad > 0);
    });
    var sum = $('#sys-summary');
    if (!sum) return;
    sum.className = 'flash' + (t.bad ? ' bad' : (t.warn ? ' warn' : ''));
    sum.textContent = t.bad ? 'Есть что исправить до запуска сайта — см. строки с красным крестиком.'
      : (t.warn ? 'Ничего не сломано. Пункты с жёлтым знаком стоит посмотреть до запуска.' : 'Все проверки пройдены — сайт готов к работе.');
  }
})();

/* Админка → «Отчёты»:
   - всплывашка над столбиком графика (дата, выручка, заказы) — мышь и касание, вместо системного <title>;
   - «Лучшие товары»: переключение «по ящикам / по выручке» без перезагрузки (ссылки работают и без JS);
   - «Обновить сейчас» — обычная форма POST с _token (JS не нужен). */
(function () {
  'use strict';

  // ---------- всплывашка графика ----------
  document.querySelectorAll('.rp-chart').forEach(function (box) {
    var svg = box.querySelector('svg');
    if (!svg) return;
    svg.querySelectorAll('.col > title').forEach(function (t) { t.remove(); });
    var tip = document.createElement('div');
    tip.className = 'rp-tip';
    tip.hidden = true;
    box.appendChild(tip);
    var current = null;

    function show(col) {
      if (current === col) return;
      if (current) current.classList.remove('on');
      current = col;
      col.classList.add('on');
      tip.textContent = '';
      var b = document.createElement('b'); b.textContent = col.getAttribute('data-d');
      var s = document.createElement('span'); s.textContent = col.getAttribute('data-s') + ' · ' + col.getAttribute('data-n');
      tip.appendChild(b); tip.appendChild(s);
      tip.hidden = false;
      var hit = col.querySelector('.hit').getBoundingClientRect();
      var bar = col.querySelector('.b');
      var top = bar ? bar.getBoundingClientRect().top : hit.bottom;
      var rb = box.getBoundingClientRect();
      var x = hit.left + hit.width / 2 - rb.left + box.scrollLeft;
      var half = tip.offsetWidth / 2 + 4;
      x = Math.max(half + box.scrollLeft, Math.min(x, box.scrollLeft + box.clientWidth - half));
      tip.style.left = x + 'px';
      tip.style.top = Math.max(tip.offsetHeight + 2, top - rb.top - 8) + 'px';
    }
    function hide() {
      if (current) current.classList.remove('on');
      current = null;
      tip.hidden = true;
    }
    svg.addEventListener('mouseover', function (e) {
      var col = e.target.closest && e.target.closest('.col');
      if (col) show(col);
    });
    svg.addEventListener('mouseleave', hide);
    svg.addEventListener('click', function (e) {          // касание на телефоне
      var col = e.target.closest && e.target.closest('.col');
      if (col) show(col); else hide();
    });
    box.addEventListener('scroll', hide, { passive: true });
  });

  // ---------- переключатель «Лучшие товары» ----------
  var card = document.getElementById('top');
  if (card) {
    card.addEventListener('click', function (e) {
      var chip = e.target.closest('a[data-top]');
      if (!chip || e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      var by = chip.getAttribute('data-top');
      card.querySelectorAll('a[data-top]').forEach(function (a) { a.classList.toggle('on', a === chip); });
      card.querySelectorAll('[data-top-list]').forEach(function (l) { l.hidden = l.getAttribute('data-top-list') !== by; });
      var keep = document.querySelector('.rp-refresh input[name=top]');   // «Обновить сейчас» вернёт на тот же список
      if (keep) keep.value = by;
      try {
        var u = new URL(location.href);
        u.searchParams.set('top', by);
        history.replaceState(null, '', u.pathname + u.search + '#top');
      } catch (err) { /* старый браузер — просто без адреса */ }
    });
  }
})();

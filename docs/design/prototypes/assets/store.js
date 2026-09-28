/* Общая логика для всех трёх дизайнов: корзина, избранное, сравнение, валюта, поиск.
   В прототипе состояние хранится в localStorage; на Webasyst это заменится на штатные API shop. */
window.Store = (function () {
  const KEY = 'tomobuv-proto';
  const RATES = { UAH: 1, USD: 41.5, EUR: 48.5 }; // демо-курсы
  const SIGN = { UAH: 'грн', USD: '$', EUR: '€' };

  let s = { cart: {}, fav: [], cmp: [], cur: 'UAH' };
  try { s = Object.assign(s, JSON.parse(localStorage.getItem(KEY)) || {}); } catch (e) { }
  const subs = [];
  const FREE_BOXES = 20; // от 20 ящиков доставка бесплатная
  // Оптовая подсказка в корзине: сколько ящиков осталось до бесплатной доставки
  function renderShip() {
    const n = Object.values(s.cart).reduce((a, b) => a + b, 0), left = Math.max(0, FREE_BOXES - n);
    document.querySelectorAll('[data-ship]').forEach(el => {
      el.innerHTML = `<div style="font-size:14px;margin-bottom:6px">${left ? `До бесплатной доставки: <b>${left} ящ.</b>` : '<b>Доставка бесплатная</b> — в заказе 20+ ящиков'}</div>
        <div style="height:6px;background:rgba(127,127,127,.2);border-radius:3px;overflow:hidden"><div style="height:100%;width:${Math.min(100, n / FREE_BOXES * 100)}%;background:var(--ship,#2f7d4f);transition:width .3s"></div></div>`;
      el.style.marginBottom = '14px';
    });
  }
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { } subs.forEach(f => f(s)); renderShip(); };
  document.addEventListener('DOMContentLoaded', renderShip);
  const byId = id => TOM.products.find(p => p.id === id);

  function money(uah) {
    const v = uah / RATES[s.cur];
    const n = s.cur === 'UAH' ? Math.round(v) : Math.round(v * 100) / 100;
    const str = n.toLocaleString('ru-RU', { minimumFractionDigits: s.cur === 'UAH' ? 0 : 2, maximumFractionDigits: 2 }).replace(/,/g, ',');
    return s.cur === 'UAH' ? `${str} ${SIGN.UAH}` : `${SIGN[s.cur]}${str}`;
  }

  const api = {
    get state() { return s; },
    RATES,
    on(f) { subs.push(f); f(s); renderShip(); },
    money,
    byId,
    addToCart(id, boxes = 1) { s.cart[id] = (s.cart[id] || 0) + boxes; save(); api.toast(`«${byId(id).name}» — ${boxes} ящ. в корзине`); },
    setQty(id, q) { if (q <= 0) delete s.cart[id]; else s.cart[id] = q; save(); },
    toggle(list, id) {
      const a = s[list]; const i = a.indexOf(id);
      if (i >= 0) a.splice(i, 1); else a.push(id);
      save();
      api.toast((list === 'fav' ? 'Избранное' : 'Сравнение') + (i >= 0 ? ': удалено' : ': добавлено'));
      return i < 0;
    },
    has(list, id) { return s[list].includes(id); },
    setCur(c) { s.cur = c; save(); },
    cartCount() { return Object.values(s.cart).reduce((a, b) => a + b, 0); },
    cartTotal() { return Object.entries(s.cart).reduce((a, [id, q]) => a + byId(id).boxPrice * q, 0); },
    cartItems() { return Object.entries(s.cart).map(([id, q]) => ({ p: byId(id), q })); },
    search(q) {
      q = q.trim().toLowerCase(); if (!q) return [];
      return TOM.products.filter(p => (p.name + ' ' + p.brand + ' ' + p.size).toLowerCase().includes(q)).slice(0, 6);
    },
    toast(msg) {
      let t = document.getElementById('toast');
      if (!t) { t = document.createElement('div'); t.id = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
      t.textContent = msg; t.classList.add('show');
      clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2200);
    }
  };
  return api;
})();

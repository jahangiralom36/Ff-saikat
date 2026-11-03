/* Storefront behaviour. All dynamic text is inserted with textContent (XSS-safe). */
(function () {
  'use strict';
  const A = window.APP;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const fmt = n => A.cur + (Math.round(n * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 });

  function toast(t) { const el = $('#toast'); el.textContent = t; el.classList.add('on'); clearTimeout(toast.t); toast.t = setTimeout(() => el.classList.remove('on'), 2600); }
  function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  async function post(data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    fd.append('csrf', A.csrf);
    try {
      const r = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      return await r.json();
    } catch (e) { return { ok: false, msg: 'Network error. Please try again.' }; }
  }
  function say(box, ok, msg) { box.replaceChildren(); if (msg) { const m = el('div', 'msg ' + (ok ? 'ok' : 'err'), msg); box.appendChild(m); } }

  /* ---------- modals ---------- */
  const App = window.App = {
    open(id, tab) {
      if (id === 'accountModal' && !A.logged) id = 'authModal';
      const m = document.getElementById(id); if (!m) return;
      m.classList.add('open'); document.body.style.overflow = 'hidden';
      if (tab) switchTab(m, tab);
    },
    close(m) { m.classList.remove('open'); if (!$('.modal.open')) document.body.style.overflow = ''; }
  };
  document.addEventListener('click', e => {
    const m = e.target.closest('.modal');
    if (e.target.matches('[data-close]') || e.target.closest('[data-close]')) { if (m) App.close(m); }
    else if (e.target.classList && e.target.classList.contains('modal')) App.close(e.target);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.modal.open').forEach(App.close); });

  function switchTab(m, tab) {
    $$('.tab', m).forEach(t => t.classList.toggle('on', t.dataset.tab === tab));
    $$('[data-tabpane]', m).forEach(p => p.classList.toggle('hidden', p.dataset.tabpane !== tab));
  }
  $$('.tabs').forEach(tabs => tabs.addEventListener('click', e => {
    const t = e.target.closest('.tab'); if (t) switchTab(t.closest('.modal'), t.dataset.tab);
  }));

  /* ---------- filter / search ---------- */
  let curCat = 'all';
  function filter() {
    const q = $('#q').value.trim().toLowerCase(); let n = 0;
    $$('#grid .pcard').forEach(c => {
      const show = (curCat === 'all' || c.dataset.cat === curCat) && (!q || c.dataset.name.includes(q));
      c.classList.toggle('hidden', !show); if (show) n++;
    });
    $('#empty').classList.toggle('hidden', n > 0);
  }
  $('#q').addEventListener('input', filter);
  $$('.cat').forEach(b => b.addEventListener('click', () => {
    $$('.cat').forEach(x => x.classList.remove('on')); b.classList.add('on'); curCat = b.dataset.cat; filter();
  }));

  /* ---------- slider ---------- */
  const sl = $('#slider');
  if (sl) {
    const slides = $$('.slide', sl), dots = $$('.dots b', sl); let i = 0;
    const go = n => { slides[i].classList.remove('on'); dots[i] && dots[i].classList.remove('on'); i = (n + slides.length) % slides.length; slides[i].classList.add('on'); dots[i] && dots[i].classList.add('on'); };
    dots.forEach((d, n) => d.addEventListener('click', () => go(n)));
    if (slides.length > 1) setInterval(() => go(i + 1), 4500);
  }

  /* ---------- card click: maintenance popup OR buy modal ---------- */
  $('#grid').addEventListener('click', e => { const c = e.target.closest('.pcard'); if (c) openProduct(+c.dataset.id); });
  $('#grid').addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('pcard')) { e.preventDefault(); openProduct(+e.target.dataset.id); } });

  function openProduct(id) {
    const p = A.products[id]; if (!p) return;
    p.maint ? openMaint(p) : openBuy(p);
  }

  function openMaint(p) {
    const M = A.maint;
    $('#mpChip').textContent = M.chip;
    $('#mpName').textContent = p.name;
    $('#mpRt').textContent = '📋 ' + M.rules;
    $('#mpMsg').textContent = (p.msg && p.msg.trim()) ? p.msg : M.defMsg;
    $('#mpClose').textContent = M.close;
    const ic = $('#mpIc'); ic.replaceChildren();
    if (p.img) { const im = el('img'); im.src = p.img; im.alt = ''; ic.appendChild(im); } else ic.textContent = '⚠️';
    const s = $('#mpSupport');
    if (M.support) { s.href = M.support; s.textContent = '💬 ' + M.supportLabel; s.classList.remove('hidden'); } else s.classList.add('hidden');
    App.open('maintModal');
  }

  /* ---------- buy flow ---------- */
  let cur = null, planSel = null, qty = 1, coupon = null;
  function openBuy(p) {
    cur = p; planSel = null; qty = 1; coupon = null;
    $('#bName').textContent = p.name;
    $('#bLead').textContent = p.plans.length ? 'Choose a plan and get your key instantly.' : 'No plans available yet.';
    const d = $('#bDesc'); d.textContent = p.desc || ''; d.classList.toggle('hidden', !p.desc);
    const dl = $('#bDl'); if (p.dl) { dl.href = p.dl; dl.classList.remove('hidden'); } else dl.classList.add('hidden');
    $('#qVal').textContent = 1; $('#bCoupon').value = ''; $('#bCouponMsg').replaceChildren(); $('#bMsg').replaceChildren();
    const box = $('#bPlans'); box.replaceChildren();
    p.plans.forEach(pl => {
      const row = el('div', 'plan' + (pl.stock < 1 ? ' out' : ''));
      const info = el('b', null, pl.title); info.appendChild(el('small', null, pl.stock > 0 ? pl.stock + ' in stock' : 'Out of stock'));
      const price = el('span', 'pr', fmt(pl.price));
      row.append(el('span', 'r'), info, price);
      row.addEventListener('click', () => { if (pl.stock < 1) return; planSel = pl; coupon = null; $('#bCouponMsg').replaceChildren(); $$('.plan', box).forEach(x => x.classList.remove('on')); row.classList.add('on'); recalc(); });
      box.appendChild(row);
    });
    const first = p.plans.findIndex(x => x.stock > 0);
    if (first > -1) box.children[first].click(); else recalc();
    if (A.siteMaint) say($('#bMsg'), false, A.siteMaintMsg);
    App.open('buyModal');
  }
  function total() { if (!planSel) return 0; return Math.max(0, planSel.price * qty - (coupon ? coupon.discount : 0)); }
  function recalc() { $('#bTotal').textContent = planSel ? fmt(total()) : '—'; $('#bBuy').disabled = !planSel || A.siteMaint; }
  $('#qMinus').onclick = () => { qty = Math.max(1, qty - 1); $('#qVal').textContent = qty; coupon = null; $('#bCouponMsg').replaceChildren(); recalc(); };
  $('#qPlus').onclick = () => { qty = Math.min(10, qty + 1); $('#qVal').textContent = qty; coupon = null; $('#bCouponMsg').replaceChildren(); recalc(); };

  $('#bCouponBtn').onclick = async () => {
    const code = $('#bCoupon').value.trim(); if (!code || !planSel) return;
    if (!A.logged) return App.open('authModal');
    const r = await post({ action: 'coupon', code, total: planSel.price * qty });
    coupon = r.ok ? r : null; say($('#bCouponMsg'), r.ok, r.msg); recalc();
  };
  $('#bBuy').onclick = async function () {
    if (!A.logged) { App.close($('#buyModal')); return App.open('authModal'); }
    if (!planSel) return;
    this.disabled = true;
    const r = await post({ action: 'buy', plan_id: planSel.id, qty, coupon: coupon ? coupon.code : '' });
    this.disabled = false;
    if (!r.ok) { say($('#bMsg'), false, r.msg); return; }
    App.close($('#buyModal'));
    $('#kInfo').textContent = r.product + ' · ' + r.plan + ' — New balance ' + r.balance;
    const list = $('#kList'); list.replaceChildren();
    r.keys.forEach(k => {
      const wrap = el('div', 'key'); const c = el('code', null, k); const b = el('button', null, 'COPY'); b.type = 'button';
      b.onclick = () => copy(k); wrap.append(c, b); list.appendChild(wrap);
    });
    App.open('keyModal');
  };

  function copy(t) {
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).catch(() => {
      const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }).finally(() => toast('Copied ✔'));
  }
  document.addEventListener('click', e => { const b = e.target.closest('[data-copy]'); if (b) copy(b.dataset.copy); });

  /* ---------- auth ---------- */
  function bindAuth(form, action) {
    form.addEventListener('submit', async e => {
      e.preventDefault(); const btn = form.querySelector('button:not([type=button])'); btn.disabled = true;
      const data = Object.fromEntries(new FormData(form)); data.action = action;
      const r = await post(data); btn.disabled = false;
      say($('.amsg', form), r.ok, r.msg);
      if (r.ok) setTimeout(() => location.reload(), 500);
    });
  }
  if (/[?&]login=1/.test(location.search) && !A.logged) App.open('authModal');
  bindAuth($('#loginForm'), 'login'); bindAuth($('#regForm'), 'register');
  const lo = $('#logoutBtn'); if (lo) lo.onclick = async () => { await post({ action: 'logout' }); location.reload(); };

  /* ---------- deposit ---------- */
  const depForm = $('#depForm');
  if (depForm) {
    const box = $('#pmBox');
    const showPm = id => {
      const m = A.methods.find(x => x.id === id); if (!m) return;
      depForm.method_id.value = id; box.replaceChildren();
      box.append(el('div', null, 'Send money to this ' + (m.type || 'account') + ':'));
      const no = el('div', 'no'); no.append(el('span', null, m.no)); const b = el('button', null, 'COPY'); b.type = 'button'; b.onclick = () => copy(m.no); no.appendChild(b);
      box.appendChild(no); if (m.info) box.appendChild(el('div', null, m.info));
    };
    $$('#pmList .pm-i').forEach(i => i.addEventListener('click', () => { $$('#pmList .pm-i').forEach(x => x.classList.remove('on')); i.classList.add('on'); showPm(+i.dataset.pm); }));
    if (A.methods[0]) showPm(A.methods[0].id);
    depForm.addEventListener('submit', async e => {
      e.preventDefault(); const btn = depForm.querySelector('button:not([type=button])'); btn.disabled = true;
      const data = Object.fromEntries(new FormData(depForm)); data.action = 'deposit';
      const r = await post(data); btn.disabled = false; say($('.amsg', depForm), r.ok, r.msg);
      if (r.ok) setTimeout(() => location.reload(), 1400);
    });
  }
})();

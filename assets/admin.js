(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const open = id => { document.getElementById(id).classList.add('open'); };
  document.addEventListener('click', e => {
    if (e.target.closest('[data-close]')) e.target.closest('.modal').classList.remove('open');
    else if (e.target.classList.contains('modal')) e.target.classList.remove('open');
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.modal.open').forEach(m => m.classList.remove('open')); });

  const side = $('#side'), scrim = $('#scrim');
  const tog = () => { side.classList.toggle('open'); scrim.classList.toggle('open'); };
  $('#burger').onclick = tog; scrim.onclick = tog;

  $('#logout').onclick = async e => {
    e.preventDefault();
    const fd = new FormData(); fd.append('action', 'logout'); fd.append('csrf', window.CSRF);
    await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    location.href = 'index.php';
  };

  /* ---- product form ---- */
  window.ProdForm = function (p) {
    const set = (id, v) => { $('#pf_' + id).value = v == null ? '' : v; };
    p = p || {};
    $('#pfTitle').textContent = p.id ? 'Edit product' : 'Add product';
    set('id', p.id || ''); set('name', p.name); set('subtitle', p.subtitle); set('tag', p.tag);
    set('cat', p.category_id || ''); set('disc', p.discount_percent || 0); set('sort', p.sort_order || 0);
    set('dl', p.download_link); set('desc', p.description); set('mmsg', p.maintenance_msg);
    $('#pf_maint').checked = +p.is_maintenance === 1;
    $('#pf_active').checked = p.id ? +p.is_active === 1 : true;
    $('#pf_pin').checked = +p.is_pinned === 1;
    open('prodModal');
  };
  window.MaintForm = function (m) {
    $('#mtTitle').textContent = '🔧 ' + m.name;
    $('#mt_id').value = m.id; 
    $('#mt_state').checked = !m.on;       // currently online → default to turning ON; currently in maintenance → default to turning OFF
    $('#mt_msg').value = m.msg || '';
    open('maintModal');
  };
  window.UserForm = function (u) {
    $('#umTitle').textContent = u.u; $('#umBal').textContent = 'Current balance: ' + u.b.toFixed(2);
    $$('.um_id').forEach(i => i.value = u.id);
    $('#um_status').value = u.status === 'active' ? 'suspended' : 'active';
    $('#um_sbtn').textContent = u.status === 'active' ? '⛔ Suspend' : '✅ Activate';
    $('#um_role').value = u.role === 'admin' ? 'user' : 'admin';
    $('#um_rbtn').textContent = u.role === 'admin' ? 'Make customer' : 'Make admin';
    $('#umSelf').style.display = u.self ? 'none' : '';
    open('userModal');
  };
  window.PmForm = function (m) {
    m = m || {}; $('#pmTitle').textContent = m.id ? 'Edit method' : 'Add method';
    $('#pm_id').value = m.id || ''; $('#pm_name').value = m.name || ''; $('#pm_no').value = m.account_no || '';
    $('#pm_type').value = m.account_type || ''; $('#pm_info').value = m.instructions || ''; $('#pm_sort').value = m.sort_order || 0;
    $('#pm_active').checked = m.id ? +m.is_active === 1 : true; open('pmModal');
  };
  window.CpForm = function (c) {
    c = c || {}; $('#cp_id').value = c.id || ''; $('#cp_code').value = c.code || ''; $('#cp_type').value = c.type || 'percent';
    $('#cp_val').value = c.value || ''; $('#cp_max').value = c.max_uses || 0;
    $('#cp_exp').value = c.expires_at ? c.expires_at.replace(' ', 'T').slice(0, 16) : '';
    $('#cp_active').checked = c.id ? +c.is_active === 1 : true; open('cpModal');
  };

  /* ---- settings: presets + live maintenance preview ---- */
  $$('.pre').forEach(b => b.onclick = () => {
    $('#t_p').value = b.dataset.p; $('#t_a').value = b.dataset.a;
    document.documentElement.style.setProperty('--primary', b.dataset.p); document.documentElement.style.setProperty('--accent', b.dataset.a);
  });
  const t_p = $('#t_p'), t_a = $('#t_a');
  if (t_p) { t_p.oninput = () => document.documentElement.style.setProperty('--primary', t_p.value); t_a.oninput = () => document.documentElement.style.setProperty('--accent', t_a.value); }
  const bind = (inp, out) => { const i = $(inp), o = $(out); if (!i || !o) return; const f = () => o.textContent = out === '#pv_rt' ? '📋 ' + i.value : i.value; i.addEventListener('input', f); f(); };
  bind('#m_rb', '#pv_rb'); bind('#m_bt', '#pv_bt'); bind('#m_ch', '#pv_ch'); bind('#m_rt', '#pv_rt'); bind('#m_msg', '#pv_msg'); bind('#m_cl', '#pv_cl');
})();

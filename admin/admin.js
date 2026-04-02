// ====== Állítsd be, ha más mappában van a projekt ======
function t(key) { return (window.LANG && window.LANG[key]) || key; }
const BASE = document.body?.dataset?.appBase || '/terkep';
const API_LIST        = `${BASE}/api/admin_reports.php`;
const API_ACTION      = `${BASE}/api/admin_action.php`; // delete-hez (legacy)
const API_SET_STATUS  = `${BASE}/api/report_set_status.php`;
const API_STATUS_LOG  = `${BASE}/api/report_status_log.php`;
const API_ATTACH_LIST = `${BASE}/api/report_attachments.php`;
const API_ATTACH_DEL  = `${BASE}/api/report_attachment_delete.php`;
const API_STATS       = `${BASE}/api/admin_stats.php`;
const API_USERS       = `${BASE}/api/admin_users.php`;
const API_LAYERS      = `${BASE}/api/admin_layers.php`;
const API_AUTHORITIES = `${BASE}/api/admin_authorities.php`;
const API_MODULES     = `${BASE}/api/admin_modules.php`;
const API_IOT_SYNC    = `${BASE}/api/admin_iot_sync.php`;
const API_BUDGET      = `${BASE}/api/admin_budget_projects.php`;
const LOGOUT_URL      = `${BASE}/admin/logout.php`;
// ========================================================

let map = null;
let markers = [];
let markerById = new Map();
let layerMarkers = [];

if (document.getElementById('map')) {
  const b = document.body;
  const mlat = parseFloat(b.dataset.mapLat);
  const mlng = parseFloat(b.dataset.mapLng);
  const mzoom = parseInt(b.dataset.mapZoom, 10);
  const lat = isFinite(mlat) ? mlat : 46.565;
  const lng = isFinite(mlng) ? mlng : 20.667;
  const zoom = isFinite(mzoom) ? mzoom : 13;
  map = L.map('map').setView([lat, lng], zoom);
  map.attributionControl.setPrefix(false);
  L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
    maxZoom: 20,
    attribution: '&copy; OpenStreetMap közreműködők, Humanitarian style'
  }).addTo(map);
}

function clearMarkers(){
  if (!map) return;
  markers.forEach(m => map.removeLayer(m));
  markers = [];
  markerById.clear();
}

function clearLayerMarkers(){
  if (!map) return;
  layerMarkers.forEach(m => map.removeLayer(m));
  layerMarkers = [];
}

function esc(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;');
}

function descriptionSummary(text, maxLen){
  const s = String(text ?? '').trim();
  if (!s) return '';
  const len = maxLen ?? 120;
  return s.length <= len ? s : s.slice(0, len) + '…';
}

async function fetchJson(url, opts){
  const res = await fetch(url, {
    credentials: 'same-origin', // session cookie
    ...opts
  });

  const text = await res.text();
  let j = null;
  try { j = JSON.parse(text); } catch(_) {}

  if (!res.ok){
    const msg = (j && (j.error || j.message)) ? (j.error || j.message) : text;
    throw new Error(`HTTP ${res.status}: ${msg}`);
  }
  return j != null ? j : {};
}

// ---- Ikonok: stabil emoji-s jelölők (nincs külső kép / URL) ----
const CAT_EMOJI = {
  road: '🚧',
  sidewalk: '🚶',
  lighting: '💡',
  trash: '🗑️',
  green: '🌳',
  traffic: '🚦',
  idea: '💭',
  default: '❓'
};

const CAT_BORDER = {
  road: '#e74c3c',
  sidewalk: '#f39c12',
  lighting: '#3498db',
  trash: '#16a085',
  green: '#2ecc71',
  traffic: '#e67e22',
  idea: '#9b59b6',
  default: '#333'
};

function badgeIcon(category){
  const emoji = CAT_EMOJI[category] || CAT_EMOJI.default;
  const border = CAT_BORDER[category] || CAT_BORDER.default;

  return L.divIcon({
    className: '',
    iconSize: [36, 36],
    iconAnchor: [18, 18],
    popupAnchor: [0, -18],
    html: `<div class="badge-marker" style="border-color:${border}">
             <span class="badge-emoji">${emoji}</span>
           </div>`
  });
}

function getIcon(category){
  return badgeIcon(category);
}

function layerIcon(category){
  const map = {
    election: { emoji: '🗳️', border: '#ff7a00' },
    public: { emoji: '🏥', border: '#00c48c' },
    tourism: { emoji: '🏛️', border: '#8e44ff' },
    trees: { emoji: '🌳', border: '#22c55e' },
    default: { emoji: '📍', border: '#60a5fa' }
  };
  const info = map[category] || map.default;
  return L.divIcon({
    className: '',
    iconSize: [32, 32],
    iconAnchor: [16, 16],
    popupAnchor: [0, -14],
    html: `<div class="badge-marker" style="border-color:${info.border}">
             <span class="badge-emoji">${info.emoji}</span>
           </div>`
  });
}

function catLabel(cat){
  return t('cat.' + cat + '_desc') || t('cat.' + cat) || cat;
}

// --- Státuszok (legacy + jarokelo irány) ---
function statusLabelKey(st){ return 'admin.status_' + st; }

const STATUS_OPTIONS = [
  'approved', // publikus map kompatibilitás miatt fent
  'new',
  'needs_info',
  'forwarded',
  'waiting_reply',
  'in_progress',
  'solved',
  'closed',
  'rejected',
  'pending'
];

function statusLabel(st){
  return t(statusLabelKey(st)) || st;
}

function reporterLine(r){
  const name = r.reporter_display_name || r.reporter_name || '';
  if (!name) return '';
  const level = r.reporter_level ? ` • ${esc(r.reporter_level)}` : '';
  if (r.reporter_profile_public && r.reporter_user_id) {
    return `<div class="text-secondary"><b>${esc(t('admin.reporter_label'))}:</b> <a href="${BASE}/user/profile.php?id=${encodeURIComponent(r.reporter_user_id)}" target="_blank">${esc(name)}</a>${level}</div>`;
  }
  return `<div class="text-secondary"><b>${esc(t('admin.reporter_label'))}:</b> ${esc(name)}${level}</div>`;
}

function catLabelShort(cat){ return t('cat.' + cat) || cat; }

async function loadStats(){
  try{
    const j = await fetchJson(API_STATS);
    if (!j || typeof j !== 'object') { throw new Error(t('admin.invalid_response')); }
    const data = j.data || {};
    const reports1 = data.reports_1d ?? 0;
    const reports7 = data.reports_7d ?? 0;
    const users7 = data.users_7d ?? 0;
    const status = data.status || {};
    const category = data.category || {};

    const el1 = document.getElementById('kpiReports1');
    const elReports = document.getElementById('kpiReports7');
    const elUsers = document.getElementById('kpiUsers7');
    const elStatus = document.getElementById('kpiStatus');
    const elCat = document.getElementById('kpiCategory');
    if (el1) el1.textContent = String(reports1);
    if (elReports) elReports.textContent = String(reports7);
    if (elUsers) elUsers.textContent = String(users7);

    if (elStatus){
      const parts = [
        `${t('admin.count_new')}: ${status.new || 0}`,
        `${t('admin.count_published')}: ${status.approved || 0}`,
        `${t('admin.count_in_progress')}: ${status.in_progress || 0}`,
        `${t('admin.count_solved')}: ${status.solved || 0}`,
        `${t('admin.count_rejected')}: ${status.rejected || 0}`
      ];
      elStatus.textContent = parts.join(' • ');
    }

    if (elCat){
      const parts = Object.entries(category).map(([k,v]) => (catLabelShort(k))+': '+v);
      elCat.textContent = parts.length ? parts.join(' • ') : '—';
    }

    const statusOrder = ['new','approved','in_progress','solved','rejected','needs_info','forwarded','waiting_reply','closed','pending'];
    const statusColors = { new:'#0d6efd', approved:'#198754', in_progress:'#ffc107', solved:'#20c997', rejected:'#dc3545', needs_info:'#6f42c1', forwarded:'#fd7e14', waiting_reply:'#0dcaf0', closed:'#6c757d', pending:'#adb5bd' };
    const chartStatus = document.getElementById('chartStatus');
    if (chartStatus){
      const maxS = Math.max(1, ...Object.values(status));
      const items = statusOrder.filter(s => (status[s]||0) > 0).map(s => ({ k:s, v:status[s]||0, label:statusLabel(s), color:statusColors[s]||'#6c757d' }));
      chartStatus.innerHTML = items.length ? items.map(x => `
        <div class="admin-chart-bar">
          <span class="label">${esc(x.label)}</span>
          <div class="bar-wrap"><div class="bar" style="width:${100*x.v/maxS}%;background:${x.color}"></div></div>
          <span class="val">${x.v}</span>
        </div>
      `).join('') : '<div class="text-secondary small">' + t('admin.no_data') + '</div>';
    }

    const chartCategory = document.getElementById('chartCategory');
    if (chartCategory){
      const maxC = Math.max(1, ...Object.values(category));
      const items = Object.entries(category).sort((a,b)=>b[1]-a[1]).map(([k,v]) => ({ k, v, label: catLabelShort(k) || k }));
      const catColors = ['#e74c3c','#3498db','#f1c40f','#34495e','#27ae60','#9b59b6','#ff7a00','#0ea5e9'];
      chartCategory.innerHTML = items.length ? items.map((x,i) => `
        <div class="admin-chart-bar">
          <span class="label">${esc(x.label)}</span>
          <div class="bar-wrap"><div class="bar" style="width:${100*x.v/maxC}%;background:${catColors[i%catColors.length]}"></div></div>
          <span class="val">${x.v}</span>
        </div>
      `).join('') : '<div class="text-secondary small">' + t('admin.no_data') + '</div>';
    }

    const countsEl = document.getElementById('counts');
    if (countsEl){
      countsEl.innerHTML = `
        <span class="badge text-bg-secondary">${t('admin.count_new')}: <b>${status.new || 0}</b></span>
        <span class="badge text-bg-secondary">${t('admin.count_published')}: <b>${status.approved || 0}</b></span>
        <span class="badge text-bg-secondary">${t('admin.count_in_progress')}: <b>${status.in_progress || 0}</b></span>
        <span class="badge text-bg-secondary">${t('admin.count_solved')}: <b>${status.solved || 0}</b></span>
        <span class="badge text-bg-secondary">${t('admin.count_rejected')}: <b>${status.rejected || 0}</b></span>
      `;
    }
  }catch(e){
    console.error(e);
  }
}

async function loadLogInto(el, reportId){
  const j = await fetchJson(`${API_STATUS_LOG}?id=${encodeURIComponent(reportId)}`);
  const rows = (j.data || []).slice(0, 3);

  if (!rows.length){
    el.innerHTML = '';
    return;
  }

  el.innerHTML = rows.map(x => {
    const oldS = x.old_status ? statusLabel(x.old_status) : '';
    const newS = statusLabel(x.new_status);
    const note = x.note ? ` • ${esc(x.note)}` : '';
    return `${esc(x.changed_at)}: ${esc(oldS)} → <b>${esc(newS)}</b>${note}`;
  }).join('<br>');
}

async function loadAttachmentsInto(el, reportId){
  el.textContent = t('admin.load') + '...';
  try{
    const j = await fetchJson(`${API_ATTACH_LIST}?id=${encodeURIComponent(reportId)}`);
    const rows = j.data || [];
    if (!rows.length){
      el.innerHTML = '<span class="meta">' + t('admin.no_attachment') + '</span>';
      return;
    }

    el.innerHTML = rows.map(a => {
      const name = esc(a.filename || '');
      const url = esc(a.url || '');
      const created = esc(a.created_at || '');
      return `
        <div class="meta" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <a href="${url}" target="_blank" rel="noopener">${name || t('admin.attachment_fallback')}</a>
          <span>${created}</span>
          <button class="del" data-att-del="${a.id}">${t('admin.delete')}</button>
        </div>
      `;
    }).join('');

    el.querySelectorAll('button[data-att-del]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.getAttribute('data-att-del'));
        if (!id) return;
        if(!confirm(t('admin.confirm_delete_attachment'))) return;
        btn.disabled = true;
        try{
          await fetchJson(API_ATTACH_DEL, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ id })
          });
          await loadAttachmentsInto(el, reportId);
        }catch(e){
          console.error(e);
          alert(t('admin.error_delete') + ': ' + e.message);
        }finally{
          btn.disabled = false;
        }
      });
    });
  }catch(e){
    console.error(e);
    el.innerHTML = '<span class="meta">' + t('admin.error_load_attachments') + '</span>';
  }
}

function renderRow(r){
  const wrap = document.createElement('div');
  wrap.className = 'card card-outline card-primary admin-item';

  const optionsHtml = STATUS_OPTIONS.map(s =>
    `<option value="${s}" ${r.status===s?'selected':''}>${esc(statusLabel(s))}</option>`
  ).join('');

  wrap.innerHTML = `
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <b>#${r.id}</b>
          <span class="text-secondary ms-2">(<span data-role="status-text">${esc(statusLabel(r.status))}</span>)</span>
        </div>
        ${r.case_no ? `<span class="badge text-bg-secondary">${t('admin.case_no')}: <span data-role="case">${esc(r.case_no)}</span></span>` : ''}
      </div>
      <div class="fw-semibold mt-2">${esc(catLabel(r.category))}</div>
      ${r.title ? `<div class="mt-1">${esc(r.title)}</div>` : ''}
      <div class="text-secondary mt-1" title="${esc(r.description)}">${esc(descriptionSummary(r.description))}</div>
      ${r.address_approx ? `<div class="text-secondary mt-1">${esc(r.address_approx)}</div>` : ''}
      ${r.authority_name ? `<div class="text-secondary small">${esc(t('admin.authority_label'))}: ${esc(r.authority_name)}</div>` : ''}
      ${reporterLine(r)}
      <div class="text-secondary mt-1">${Number(r.lat).toFixed(6)}, ${Number(r.lng).toFixed(6)} • ${esc(r.created_at || '')}</div>

      <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
        <select data-role="status" class="form-select form-select-sm" style="min-width:220px">${optionsHtml}</select>
        <input data-role="note" class="form-control form-control-sm" placeholder="${t('admin.note_placeholder')}" style="min-width:240px">
        <button data-action="save" class="btn btn-primary btn-sm">${t('admin.save')}</button>
        <button data-action="delete" class="btn btn-outline-danger btn-sm">${t('admin.delete')}</button>
        <button data-action="export-fms" class="btn btn-outline-secondary btn-sm" title="${t('admin.export_fms_title')}">${t('admin.export_fms')}</button>
      </div>

      <div class="mt-2">
        <button class="btn btn-outline-secondary btn-sm" data-action="log-toggle">${t('admin.status_log')}</button>
        <div data-role="log" class="text-secondary mt-2" style="display:none"></div>
      </div>
      <div class="mt-2">
        <button class="btn btn-outline-secondary btn-sm" data-action="att-toggle">${t('admin.attachments')}</button>
        <div data-role="att-list" class="text-secondary mt-2" style="display:none"></div>
      </div>
    </div>
  `;

  // hover → map jump
  wrap.addEventListener('mouseenter', () => {
    if (map) map.setView([r.lat, r.lng], Math.max(map.getZoom(), 17));
    const mk = markerById.get(r.id);
    if (mk) mk.openPopup();
  });

  const logEl = wrap.querySelector('[data-role="log"]');
  const logToggle = wrap.querySelector('[data-action="log-toggle"]');
  let logLoaded = false;

  const attList = wrap.querySelector('[data-role="att-list"]');
  const attToggle = wrap.querySelector('[data-action="att-toggle"]');
  let attLoaded = false;
  attToggle?.addEventListener('click', async () => {
    const isOpen = attList.style.display !== 'none';
    attList.style.display = isOpen ? 'none' : 'block';
    if (!isOpen && !attLoaded) {
      attLoaded = true;
      await loadAttachmentsInto(attList, r.id);
    }
  });

  // gombok
  wrap.querySelectorAll('button[data-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const action = btn.getAttribute('data-action');

      // TÖRLÉS
      if (action === 'delete'){
        if(!confirm(t('admin.confirm_delete_report') + ' (#' + r.id + ')')) return;

        try{
          await fetchJson(API_ACTION, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ id: r.id, action:'delete' })
          });

          const mk = markerById.get(r.id);
          if (mk && map){
            map.removeLayer(mk);
            markerById.delete(r.id);
          }
          wrap.remove();
        }catch(e){
          console.error(e);
          alert(t('admin.error_delete') + ': ' + e.message);
        }
        return;
      }

      if (action === 'log-toggle'){
        const isOpen = logEl.style.display !== 'none';
        logEl.style.display = isOpen ? 'none' : 'block';
        if (!isOpen && !logLoaded){
          logLoaded = true;
          await loadLogInto(logEl, r.id);
        }
        return;
      }

      // Export FMS
      if (action === 'export-fms'){
        try {
          await fetchJson(`${BASE}/api/fms_bridge/export_report.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ report_id: r.id })
          });
          alert(t('admin.fms_sent'));
        } catch (e) {
          if (e.message && e.message.includes('400')) {
            alert(t('admin.fms_not_configured_alert'));
          } else {
            alert(t('admin.error_generic') + ': ' + e.message);
          }
        }
        return;
      }

      // MENTÉS (státusz)
      const st   = wrap.querySelector('[data-role="status"]').value;
      const note = wrap.querySelector('[data-role="note"]').value;

      try{
        await fetchJson(API_SET_STATUS, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ id: r.id, status: st, note })
        });

        // UI: státusz szöveg frissítés
        r.status = st;
        wrap.querySelector('[data-role="status-text"]').textContent = statusLabel(st);

        if (logLoaded){
          await loadLogInto(logEl, r.id);
        }

      }catch(e){
        console.error(e);
        alert(t('admin.error_save') + ': ' + e.message);
      }
    });
  });

  return wrap;
}

function renderReports(rows){
  const list = document.getElementById('reportList');
  list.innerHTML = '';
  if (!rows.length){
    list.innerHTML = '<div class="text-secondary">' + t('admin.no_filter_results') + '</div>';
    return;
  }
  rows.forEach(r => list.appendChild(renderRow(r)));
}

let _authorityOptionsLoaded = false;
async function ensureAuthorityFilterOptions(){
  const sel = document.getElementById('authorityFilter');
  if (!sel || _authorityOptionsLoaded) return;
  try {
    const j = await fetchJson(API_AUTHORITIES);
    const auths = j.authorities || [];
    auths.forEach(a => {
      const opt = document.createElement('option');
      opt.value = String(a.id);
      opt.textContent = esc(a.name || a.city || t('admin.authority_hash') + a.id);
      sel.appendChild(opt);
    });
    _authorityOptionsLoaded = true;
  } catch (e) {
    console.warn('Authority filter options:', e);
  }
}

async function loadReports(){
  const status = document.getElementById('statusFilter').value;
  const q = (document.getElementById('reportSearch').value || '').trim();
  const authorityId = (document.getElementById('authorityFilter') && document.getElementById('authorityFilter').value) ? Number(document.getElementById('authorityFilter').value) : 0;
  const limit = Number(document.getElementById('reportLimit').value || 300);
  const list = document.getElementById('reportList');
  list.textContent = t('admin.load') + '...';
  clearMarkers();

  try{
    await ensureAuthorityFilterOptions();
    const qs = new URLSearchParams();
    qs.set('status', status);
    if (q) qs.set('q', q);
    if (authorityId > 0) qs.set('authority_id', String(authorityId));
    if (limit) qs.set('limit', String(limit));
    const j = await fetchJson(`${API_LIST}?${qs.toString()}`);
    const rows = (j && j.data) ? j.data : [];
    renderReports(rows);

    if (map) {
      for(const r of rows){
        const mk = L.marker([r.lat, r.lng], { icon: getIcon(r.category) })
          .addTo(map)
          .bindPopup(
            `<b>#${r.id}</b> <small>(${esc(statusLabel(r.status))})</small><br>` +
            (r.case_no ? `<small><b>${t('admin.case_no')}:</b> ${esc(r.case_no)}</small><br>` : '') +
            `<b>${esc(catLabel(r.category))}</b><br>` +
            `${r.title ? `<b>${esc(r.title)}</b><br>` : ''}` +
            `${esc(r.description)}<br>` +
            `${r.address_approx ? `<small>${esc(r.address_approx)}</small>` : ''}`
          );

        markers.push(mk);
        markerById.set(r.id, mk);
      }
      if(rows.length) map.setView([rows[0].lat, rows[0].lng], 14);
    }
  }catch(e){
    console.error(e);
    list.innerHTML = '';
    alert(t('admin.error_load') + ': ' + e.message);
  }
}

async function loadUsers(){
  const q = (document.getElementById('userSearch').value || '').trim();
  const role = document.getElementById('userRoleFilter').value || '';
  const active = document.getElementById('userActiveFilter').value || '';
  const list = document.getElementById('userList');
  list.textContent = t('admin.load') + '...';

  try{
    const qs = new URLSearchParams();
    if (q) qs.set('q', q);
    if (role) qs.set('role', role);
    if (active !== '') qs.set('active', active);
    const j = await fetchJson(`${API_USERS}?${qs.toString()}`);
    const rows = j.data || [];
    if (!rows.length){
    list.innerHTML = '<div class="text-secondary">' + t('admin.no_filter_results') + '</div>';
      return;
    }

    const roleOpts = ['user','civiluser','communityuser','govuser','admin','superadmin'].map(r => `<option value="${r}">${esc(t('admin.role_'+r) || r)}</option>`).join('');
    list.innerHTML = `
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th><th>${t('admin.user_col_name')}</th><th>${t('admin.user_col_email')}</th><th>${t('admin.user_col_level')}</th><th>${t('admin.user_col_role')}</th><th>${t('admin.user_col_status')}</th><th>${t('admin.user_col_action')}</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(u => `
            <tr data-user="${u.id}">
              <td>${u.id}</td>
              <td>${esc(u.display_name || '')}</td>
              <td>${esc(u.email || '')}</td>
              <td>${esc(u.level || 0)}</td>
              <td>
                <select class="user-role">
                  ${roleOpts}
                </select>
              </td>
              <td>${Number(u.is_active) === 0 ? t('admin.user_banned') : t('admin.user_active')}</td>
              <td>
                <button class="soft user-toggle">${Number(u.is_active) === 0 ? t('admin.activate') : t('admin.deactivate')}</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;

    list.querySelectorAll('tr[data-user]').forEach(tr => {
      const id = Number(tr.getAttribute('data-user'));
      const roleSel = tr.querySelector('.user-role');
      const toggleBtn = tr.querySelector('.user-toggle');

      const current = rows.find(x => Number(x.id) === id);
      if (roleSel && current) roleSel.value = current.role || 'user';

      roleSel?.addEventListener('change', async () => {
        try{
          await fetchJson(API_USERS, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ action:'update_role', user_id:id, role: roleSel.value })
          });
        }catch(e){
          alert(t('admin.role_update_error') + ': ' + e.message);
        }
      });

      toggleBtn?.addEventListener('click', async () => {
        const next = (current && Number(current.is_active) === 0) ? 1 : 0;
        try{
          await fetchJson(API_USERS, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ action:'toggle_active', user_id:id, is_active: next })
          });
          await loadUsers();
        }catch(e){
          alert(t('admin.status_update_error') + ': ' + e.message);
        }
      });
    });
  }catch(e){
    console.error(e);
    list.innerHTML = '<div class="text-secondary">' + t('admin.load_error') + '</div>';
  }
}

async function loadLayerAuthorityOptions(){
  const sel = document.getElementById('layerAuthority');
  if (!sel) return;
  try {
    const j = await fetchJson(API_AUTHORITIES);
    const authorities = j.authorities || [];
    const first = sel.querySelector('option');
    sel.innerHTML = first ? first.outerHTML : '<option value="">' + t('admin.layer_authority_none') + '</option>';
    authorities.forEach(a => {
      const opt = document.createElement('option');
      opt.value = a.id;
      opt.textContent = esc(a.name || a.city || `#${a.id}`);
      sel.appendChild(opt);
    });
  } catch (e) {
    console.warn('Hatóság lista betöltése (layerekhez):', e);
  }
}

async function loadLayers(){
  const list = document.getElementById('layerList');
  list.textContent = t('admin.load') + '...';
  try{
    const j = await fetchJson(API_LAYERS);
    const rows = j.data || [];
    if (!rows.length){
      list.innerHTML = '<div class="text-secondary">' + t('admin.no_layer') + '</div>';
    } else {
      list.innerHTML = rows.map(l => {
        const isTrees = (l.layer_key === 'trees' || l.layer_type === 'trees');
        const authLine = (l.authority_name || l.authority_city) ? ` • ${esc(l.authority_name || l.authority_city)}` : '';
        return `
        <div class="admin-item" data-layer="${l.id}" data-layer-type="${esc(l.layer_type || '')}">
          <div><b>${esc(l.name)}</b> <span class="meta">(${esc(l.layer_key)})</span></div>
          <div class="meta">${esc(l.category)}${authLine}${isTrees ? ' • ' + t('admin.layer_trees_catalog') : ' • ' + t('admin.points_count') + ': ' + (l.point_count || 0)}</div>
          <div class="actions">
            <label class="check"><input type="checkbox" class="layer-active" ${Number(l.is_active) ? 'checked' : ''}> ${t('admin.layer_active')}</label>
            ${isTrees ? '' : '<button class="soft layer-points">' + t('admin.layer_points_btn') + '</button>'}
            <button class="del layer-delete">${t('admin.delete')}</button>
          </div>
        </div>
      `;
      }).join('');
    }

    const sel = document.getElementById('pointLayerSelect');
    sel.innerHTML = rows.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');

    list.querySelectorAll('[data-layer]').forEach(item => {
      const id = Number(item.getAttribute('data-layer'));
      item.querySelector('.layer-active')?.addEventListener('change', async (e) => {
        const checked = e.target.checked ? 1 : 0;
        await fetchJson(API_LAYERS, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'toggle_layer', id, is_active: checked })
        });
        await loadLayers();
        await loadLayerMarkers();
      });
      item.querySelector('.layer-points')?.addEventListener('click', async () => {
        await loadPoints(id);
      });
      item.querySelector('.layer-delete')?.addEventListener('click', async () => {
        if (!confirm(t('admin.confirm_delete_layer'))) return;
        await fetchJson(API_LAYERS, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'delete_layer', id })
        });
        await loadLayers();
        await loadLayerMarkers();
      });
    });
  }catch(e){
    console.error(e);
    list.innerHTML = '<div class="text-secondary">' + t('admin.load_error') + '</div>';
  }
}

async function loadPoints(layerId){
  const list = document.getElementById('pointList');
  list.textContent = t('admin.load') + '...';
  try{
    const j = await fetchJson(`${API_LAYERS}?layer_id=${encodeURIComponent(layerId)}`);
    const rows = j.data || [];
    if (!rows.length){
      list.innerHTML = '<div class="text-secondary">' + t('admin.no_point') + '</div>';
      return;
    }
    list.innerHTML = rows.map(p => `
      <div class="admin-item" data-point="${p.id}">
        <div><b>${esc(p.name || t('admin.point_fallback'))}</b></div>
        <div class="meta">${esc(p.address || '')}</div>
        <div class="meta">${Number(p.lat).toFixed(6)}, ${Number(p.lng).toFixed(6)}</div>
        <div class="actions">
          <button class="del point-delete">${t('admin.delete')}</button>
        </div>
      </div>
    `).join('');

    list.querySelectorAll('[data-point]').forEach(item => {
      const id = Number(item.getAttribute('data-point'));
      item.querySelector('.point-delete')?.addEventListener('click', async () => {
        if (!confirm(t('admin.confirm_delete_point'))) return;
        await fetchJson(API_LAYERS, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ action:'delete_point', id })
        });
        await loadPoints(layerId);
      });
    });
  }catch(e){
    console.error(e);
    list.innerHTML = '<div class="text-secondary">' + t('admin.load_error') + '</div>';
  }
}

async function loadLayerMarkers(){
  if (!map) return;
  clearLayerMarkers();
  try{
    const j = await fetchJson(`${API_LAYERS}?with_points=1`);
    const data = j.data || {};
    const layers = data.layers || [];
    const points = data.points || [];
    if (!layers.length || !points.length) return;
    const layerById = new Map(layers.map(l => [Number(l.id), l]));
    for (const p of points){
      const l = layerById.get(Number(p.layer_id));
      if (!l) continue;
      const mk = L.marker([p.lat, p.lng], { icon: layerIcon(l.category) })
        .addTo(map)
        .bindPopup(
          `<b>${esc(l.name)}</b><br>` +
          `${p.name ? `<b>${esc(p.name)}</b><br>` : ''}` +
          `${p.address ? `<small>${esc(p.address)}</small><br>` : ''}`
        );
      layerMarkers.push(mk);
    }
  }catch(e){
    console.warn('layer markers load failed', e);
  }
}

async function loadAuthorities(){
  const list = document.getElementById('authorityList');
  const contactList = document.getElementById('contactList');
  const select = document.getElementById('contactAuthoritySelect');
  const assignSelect = document.getElementById('assignAuthoritySelect');
  const assignList = document.getElementById('assignList');
  if (!list || !contactList || !select) return;
  if (!assignSelect || !assignList) return;
  list.textContent = t('admin.load') + '...';
  contactList.textContent = t('admin.load') + '...';
  assignList.textContent = t('admin.load') + '...';
  try{
    const j = await fetchJson(API_AUTHORITIES);
    const authorities = j.authorities || [];
    const contacts = j.contacts || [];
    const assignments = j.assignments || [];

    select.innerHTML = authorities.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
    assignSelect.innerHTML = authorities.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');

    if (!authorities.length){
      list.innerHTML = '<div class="text-secondary">' + t('admin.no_data') + '</div>';
    } else {
      const editLabel = t('admin.authority_edit') || 'Szerkesztés';
      list.innerHTML = authorities.map(a => {
        const hasBounds = a.min_lat != null && a.max_lat != null && a.min_lng != null && a.max_lng != null;
        const boundsStr = hasBounds ? ` [${Number(a.min_lat).toFixed(2)}, ${Number(a.max_lat).toFixed(2)}, ${Number(a.min_lng).toFixed(2)}, ${Number(a.max_lng).toFixed(2)}]` : ' <span class="text-warning">' + (t('admin.authority_no_bounds') || 'nincs terület') + '</span>';
        return `
        <div class="admin-item auth-row" data-id="${a.id}">
          <div class="meta">
            <b>${esc(a.name)}</b> • ${esc(a.city || '')}${boundsStr}
          </div>
          <div class="text-secondary">${esc(a.contact_email || '')} ${esc(a.contact_phone || '')}</div>
          <div class="auth-edit-fields d-none border rounded p-2 mt-2 bg-light">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
              <input class="form-control form-control-sm auth-edit-name" placeholder="${esc(t('admin.authority_name'))}" value="${esc(a.name || '')}" style="min-width:140px">
              <input class="form-control form-control-sm auth-edit-city" placeholder="${esc(t('admin.authority_city'))}" value="${esc(a.city || '')}" style="width:100px">
              <input class="form-control form-control-sm auth-edit-address" placeholder="${esc(t('admin.authority_address'))}" value="${esc(a.address || '')}" style="min-width:180px">
              <input class="form-control form-control-sm auth-edit-email" placeholder="${esc(t('admin.authority_email'))}" value="${esc(a.contact_email || '')}" style="width:140px">
              <input class="form-control form-control-sm auth-edit-phone" placeholder="${esc(t('admin.authority_phone'))}" value="${esc(a.contact_phone || '')}" style="width:100px">
            </div>
            <div class="small text-secondary mb-1">${esc(t('admin.authority_bounds_hint') || '')}</div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <input class="form-control form-control-sm auth-edit-minlat" type="number" step="any" placeholder="min_lat" value="${a.min_lat != null ? a.min_lat : ''}" style="width:80px">
              <input class="form-control form-control-sm auth-edit-maxlat" type="number" step="any" placeholder="max_lat" value="${a.max_lat != null ? a.max_lat : ''}" style="width:80px">
              <input class="form-control form-control-sm auth-edit-minlng" type="number" step="any" placeholder="min_lng" value="${a.min_lng != null ? a.min_lng : ''}" style="width:80px">
              <input class="form-control form-control-sm auth-edit-maxlng" type="number" step="any" placeholder="max_lng" value="${a.max_lng != null ? a.max_lng : ''}" style="width:80px">
              <button type="button" class="btn btn-sm btn-primary auth-save-full">${t('admin.authority_save')}</button>
            </div>
          </div>
          <div class="actions mt-1">
            <button type="button" class="btn btn-outline-secondary btn-sm auth-edit">${editLabel}</button>
            <button class="btn btn-outline-danger btn-sm auth-del" data-id="${a.id}">${t('admin.delete')}</button>
          </div>
        </div>
      `;
      }).join('');
      list.querySelectorAll('.auth-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm(t('admin.confirm_delete_authority'))) return;
          await fetchJson(API_AUTHORITIES, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ action:'delete_authority', id: Number(btn.dataset.id) })
          });
          await loadAuthorities();
        });
      });
      list.querySelectorAll('.auth-edit').forEach(btn => {
        btn.addEventListener('click', () => {
          const row = btn.closest('.auth-row');
          const fields = row.querySelector('.auth-edit-fields');
          if (fields) {
            fields.classList.toggle('d-none');
            btn.textContent = fields.classList.contains('d-none') ? (t('admin.authority_edit') || 'Szerkesztés') : (t('admin.cancel') || 'Mégse');
          }
        });
      });
      list.querySelectorAll('.auth-save-full').forEach(btn => {
        btn.addEventListener('click', async () => {
          const row = btn.closest('.auth-row');
          const id = row ? Number(row.dataset.id) : 0;
          if (id <= 0) return;
          const body = {
            action: 'update_authority',
            id,
            name: row.querySelector('.auth-edit-name')?.value?.trim() || '',
            city: row.querySelector('.auth-edit-city')?.value?.trim() || '',
            address: row.querySelector('.auth-edit-address')?.value?.trim() || '',
            contact_email: row.querySelector('.auth-edit-email')?.value?.trim() || '',
            contact_phone: row.querySelector('.auth-edit-phone')?.value?.trim() || '',
            min_lat: parseNum(row.querySelector('.auth-edit-minlat')?.value),
            max_lat: parseNum(row.querySelector('.auth-edit-maxlat')?.value),
            min_lng: parseNum(row.querySelector('.auth-edit-minlng')?.value),
            max_lng: parseNum(row.querySelector('.auth-edit-maxlng')?.value)
          };
          try {
            await fetchJson(API_AUTHORITIES, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(body)
            });
            row.querySelector('.auth-edit-fields')?.classList.add('d-none');
            const editBtn = row.querySelector('.auth-edit');
            if (editBtn) editBtn.textContent = t('admin.authority_edit') || 'Szerkesztés';
            await loadAuthorities();
          } catch (e) {
            alert(t('admin.authority_save_error') + ': ' + e.message);
          }
        });
      });
    }

    if (!contacts.length){
      contactList.innerHTML = '<div class="text-secondary">' + t('admin.no_data') + '</div>';
    } else {
      contactList.innerHTML = contacts.map(c => `
        <div class="admin-item">
          <div class="meta">
            <b>${esc(c.name)}</b> • <span class="text-secondary">${esc(c.service_code)}</span>
          </div>
          <div class="text-secondary">${esc(c.description || '')}</div>
          <div class="actions">
            <button class="btn btn-outline-danger btn-sm contact-del" data-id="${c.id}">Törlés</button>
          </div>
        </div>
      `).join('');
      contactList.querySelectorAll('.contact-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm(t('admin.confirm_delete_contact'))) return;
          await fetchJson(API_AUTHORITIES, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ action:'delete_contact', id: Number(btn.dataset.id) })
          });
          await loadAuthorities();
        });
      });
    }

    if (!assignments.length){
      assignList.innerHTML = '<div class="text-secondary">' + t('admin.no_data') + '</div>';
    } else {
      assignList.innerHTML = assignments.map(a => `
        <div class="admin-item">
          <div class="meta">
            <b>${esc(a.display_name || a.email)}</b> • <span class="text-secondary">${esc(a.email)}</span>
          </div>
          <div class="text-secondary">${t('admin.authority_label')}: ${esc(a.authority_name || a.authority_id)}</div>
          <div class="actions">
            <button class="btn btn-outline-danger btn-sm assign-del" data-id="${a.id}">${t('admin.delete')}</button>
          </div>
        </div>
      `).join('');
      assignList.querySelectorAll('.assign-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm(t('admin.confirm_delete_assign'))) return;
          await fetchJson(API_AUTHORITIES, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ action:'remove_user', id: Number(btn.dataset.id) })
          });
          await loadAuthorities();
        });
      });
    }
  }catch(e){
    console.error(e);
    list.innerHTML = '<div class="text-secondary">' + t('admin.load_error') + '</div>';
    contactList.innerHTML = '<div class="text-secondary">' + t('admin.load_error') + '</div>';
    assignList.innerHTML = '<div class="text-secondary">' + t('admin.load_error') + '</div>';
  }
}

async function loadModules(){
  const list = document.getElementById('moduleList');
  if (!list) return;
  list.textContent = t('admin.load') + '...';
  try {
    const j = await fetchJson(API_MODULES);
    const modules = j.modules || [];
    if (!modules.length) {
      list.innerHTML = '<div class="text-secondary">' + t('admin.no_module') + '</div>';
      return;
    }
    let euSectionOpened = false;
    list.innerHTML = modules.map(m => {
      let sectionPrefix = '';
      if (m.group === 'eu_open_data' && !euSectionOpened) {
        euSectionOpened = true;
        sectionPrefix = '<h5 class="h6 text-secondary text-uppercase border-bottom pb-2 mb-3">' + esc(t('admin.eu_open_data_group')) + '</h5>';
      }
      const setList = m.settings || [];
      let enabled = false;
      const fields = setList.map(s => {
        if (s.key === 'enabled') {
          enabled = s.value === '1' || s.set;
          return '';
        }
        if (s.type === 'select' && s.options && typeof s.options === 'object') {
          const val = s.value || '';
          const opts = Object.entries(s.options).map(([k, lbl]) => `<option value="${esc(k)}"${val === k ? ' selected' : ''}>${esc(lbl)}</option>`).join('');
          return `<div class="mb-2"><label class="form-label small">${esc(s.label)}</label><select class="form-select form-select-sm" data-module-key="${esc(m.id)}" data-setting-key="${esc(s.key)}">${opts}</select></div>`;
        }
        const type = (s.type === 'password' || s.mask) ? 'password' : (s.type === 'number' ? 'number' : 'text');
        const placeholder = s.mask && s.set ? '•••••••• (' + t('admin.unchanged') + ')' : (s.placeholder || '');
        const val = s.mask && s.set ? '' : (s.value || '');
        const minAttr = s.type === 'number' ? ' min="0"' : '';
        return `<div class="mb-2"><label class="form-label small">${esc(s.label)}</label><input class="form-control form-control-sm" data-module-key="${esc(m.id)}" data-setting-key="${esc(s.key)}" type="${type}" value="${esc(val)}" placeholder="${esc(placeholder)}"${minAttr}></div>`;
      }).join('');
      const isMistral = (m.id === 'mistral');
      const isOpenai = (m.id === 'openai');
      const isIot = (m.id === 'iot');
      const isEu = (m.id === 'eu_open_data');
      return `
        ${sectionPrefix}
        <div class="card mb-3" data-module-id="${esc(m.id)}">
          <div class="card-body">
            <h6 class="card-title">${esc(m.name)}</h6>
            <p class="text-secondary small">${esc(m.description || '')}</p>
            ${isEu ? '<p class="text-secondary small mb-2">' + esc(t('admin.eu_open_data_hint')) + '</p>' : ''}
            ${isIot ? '<p class="text-secondary small mb-2">' + (t('admin.iot_sync_hint') || 'Az adatok a szinkronizálás után jelennek meg a közig Szenzorok fülön.') + '</p>' : ''}
            <div class="form-check mb-2">
              <input class="form-check-input module-enabled" type="checkbox" data-module-id="${esc(m.id)}" id="mod-${esc(m.id)}" ${enabled ? 'checked' : ''}>
              <label class="form-check-label" for="mod-${esc(m.id)}">${t('admin.enabled')}</label>
            </div>
            ${fields}
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <button type="button" class="btn btn-sm btn-primary module-save" data-module-id="${esc(m.id)}">${t('admin.module_save')}</button>
              ${isMistral ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestMistral">' + t('admin.test_mistral') + '</button>' : ''}
              ${isOpenai ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestOpenai">' + t('admin.test_openai') + '</button>' : ''}
              ${isIot ? '<button type="button" class="btn btn-sm btn-outline-primary" id="btnIotSync">' + (t('admin.iot_sync_now') || 'Szinkronizálás most') + '</button>' : ''}
            </div>
            ${isMistral ? '<div id="mistralTestResult" class="small mt-2 text-secondary"></div>' : ''}
            ${isOpenai ? '<div id="openaiTestResult" class="small mt-2 text-secondary"></div>' : ''}
            ${isIot ? '<div id="iotSyncResult" class="small mt-2 text-secondary"></div>' : ''}
          </div>
        </div>
      `;
    }).join('');

    list.querySelectorAll('.module-save').forEach(btn => {
      btn.addEventListener('click', async () => {
        const moduleId = btn.getAttribute('data-module-id');
        const card = btn.closest('.card');
        const enabled = card.querySelector('.module-enabled')?.checked ?? false;
        const settings = {};
        card.querySelectorAll('input[data-module-key][data-setting-key]').forEach(inp => {
          if (inp.type === 'password' && inp.value === '') return;
          if (inp.value.trim() !== '') settings[inp.getAttribute('data-setting-key')] = inp.value.trim();
        });
        card.querySelectorAll('select[data-module-key][data-setting-key]').forEach(sel => {
          const v = sel.value;
          if (v !== null && v !== undefined) settings[sel.getAttribute('data-setting-key')] = v;
        });
        try {
          await fetchJson(API_MODULES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_module', module_id: moduleId, enabled: enabled ? 1 : 0, settings })
          });
          alert(t('admin.module_saved'));
          loadModules();
        } catch (e) {
          alert(t('admin.error_generic') + ': ' + (e.message || e));
        }
      });
    });

    const btnTest = document.getElementById('btnTestMistral');
    const testResult = document.getElementById('mistralTestResult');
    if (btnTest && testResult) {
      btnTest.addEventListener('click', async () => {
        testResult.textContent = t('admin.testing');
        btnTest.disabled = true;
        try {
          const j = await fetchJson(API_MODULES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test_mistral' })
          });
          if (j && j.ok) {
            testResult.textContent = (j.message || t('admin.mistral_ok'));
            testResult.className = 'small mt-2 text-success';
          } else {
            testResult.textContent = (j && j.error) ? j.error : t('admin.unknown_error');
            testResult.className = 'small mt-2 text-danger';
          }
        } catch (e) {
          testResult.textContent = t('admin.error_generic') + ': ' + (e.message || e);
          testResult.className = 'small mt-2 text-danger';
        }
        btnTest.disabled = false;
      });
    }
    const btnTestOpenai = document.getElementById('btnTestOpenai');
    const openaiTestResult = document.getElementById('openaiTestResult');
    if (btnTestOpenai && openaiTestResult) {
      btnTestOpenai.addEventListener('click', async () => {
        openaiTestResult.textContent = t('admin.testing');
        btnTestOpenai.disabled = true;
        try {
          const j = await fetchJson(API_MODULES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test_openai' })
          });
          if (j && j.ok) {
            openaiTestResult.textContent = (j.message || t('admin.openai_ok'));
            openaiTestResult.className = 'small mt-2 text-success';
          } else {
            openaiTestResult.textContent = (j && j.error) ? j.error : 'Ismeretlen hiba';
            openaiTestResult.className = 'small mt-2 text-danger';
          }
        } catch (e) {
          openaiTestResult.textContent = t('admin.error_generic') + ': ' + (e.message || e);
          openaiTestResult.className = 'small mt-2 text-danger';
        }
        btnTestOpenai.disabled = false;
      });
    }
    const btnIotSync = document.getElementById('btnIotSync');
    const iotSyncResult = document.getElementById('iotSyncResult');
    if (btnIotSync && iotSyncResult) {
      btnIotSync.addEventListener('click', async () => {
        iotSyncResult.textContent = t('admin.iot_sync_running') || 'Szinkronizálás…';
        iotSyncResult.className = 'small mt-2 text-secondary';
        btnIotSync.disabled = true;
        try {
          const j = await fetchJson(API_IOT_SYNC);
          if (j && j.ok && j.providers) {
            const parts = Object.entries(j.providers).map(([k, v]) => {
              const err = v.error ? ' (' + v.error + ')' : '';
              return k + ': ' + (v.imported || 0) + ' szenzor' + (v.updated ? ', ' + v.updated + ' metrika' : '') + err;
            });
            iotSyncResult.textContent = (t('admin.iot_sync_done') || 'Kész.') + ' ' + (parts.length ? parts.join('; ') : (t('admin.iot_sync_no_providers') || 'Nincs konfigurált provider.'));
            iotSyncResult.className = 'small mt-2 text-success';
          } else {
            iotSyncResult.textContent = (j && j.error) ? j.error : (t('admin.unknown_error') || 'Ismeretlen hiba');
            iotSyncResult.className = 'small mt-2 text-danger';
          }
        } catch (e) {
          iotSyncResult.textContent = t('admin.error_generic') + ': ' + (e.message || e);
          iotSyncResult.className = 'small mt-2 text-danger';
        }
        btnIotSync.disabled = false;
      });
    }
  } catch (e) {
    console.error(e);
    const msg = (e && e.message) ? e.message : t('admin.load_error');
    list.innerHTML = '<div class="text-secondary">' + esc(msg) + '</div>';
  }
}

function initTabs(){
  const tabs = document.querySelectorAll('.tab[data-tab]');
  const overviewEl = document.getElementById('tab-overview');
  const panelEl = document.getElementById('admin-tab-panel');
  const bodies = {
    overview: overviewEl,
    reports: document.getElementById('tab-reports'),
    users: document.getElementById('tab-users'),
    layers: document.getElementById('tab-layers'),
    authorities: document.getElementById('tab-authorities'),
    modules: document.getElementById('tab-modules')
  };
  tabs.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const key = btn.getAttribute('data-tab');
      tabs.forEach(t => t.classList.toggle('active', t === btn));
      if (key === 'overview') {
        if (overviewEl) overviewEl.hidden = false;
        if (panelEl) panelEl.hidden = true;
        loadStats();
      } else {
        if (overviewEl) overviewEl.hidden = true;
        if (panelEl) panelEl.hidden = false;
        ['reports','users','layers','authorities','modules'].forEach(k => {
          const el = bodies[k];
          if (el) el.hidden = (k !== key);
        });
        if (key === 'users') loadUsers();
        if (key === 'layers') {
          clearMarkers();
          loadLayerAuthorityOptions();
          loadLayers();
          loadLayerMarkers();
        }
        if (key === 'reports') {
          clearLayerMarkers();
          loadStats();
        }
        if (key === 'authorities') {
          clearLayerMarkers();
          loadAuthorities();
        }
        if (key === 'modules') loadModules();
      }
    });
  });
}

function BUDGET_STATUS_LABEL() { return { draft: t('admin.budget_status_draft'), published: t('admin.budget_status_published'), closed: t('admin.budget_status_closed') }; }

async function loadBudgetProjects() {
  const list = document.getElementById('budgetProjectList');
  if (!list) return;
  list.textContent = t('admin.load') + '...';
  try {
    const j = await fetchJson(API_BUDGET);
    const projects = j.projects || [];
    const authorities = j.authorities || [];
    const authOptions = authorities.map(a => `<option value="${a.id}">${esc(a.name || a.city || '')}</option>`).join('');
    list.innerHTML = `
      <div id="budgetAddForm" class="border rounded p-3 mb-3 bg-light" style="display:none">
        <h6 class="mb-2">${esc(t('admin.budget_add'))}</h6>
        <div class="row g-2">
          <div class="col-12"><label class="form-label small">${esc(typeof t === 'function' ? t('idea.title_placeholder') : t('admin.budget_col_title'))}</label><input class="form-control form-control-sm" id="budgetNewTitle" placeholder="${esc(t('admin.budget_placeholder'))}"></div>
          <div class="col-12"><label class="form-label small">${esc(t('admin.description'))}</label><textarea class="form-control form-control-sm" id="budgetNewDesc" rows="2"></textarea></div>
          <div class="col-6"><label class="form-label small">${esc(t('admin.budget_amount_ft'))}</label><input type="number" class="form-control form-control-sm" id="budgetNewBudget" value="0" min="0" step="0.01"></div>
          <div class="col-6"><label class="form-label small">${esc(t('admin.common_status'))}</label><select class="form-select form-select-sm" id="budgetNewStatus"><option value="draft">${esc(t('admin.budget_status_draft'))}</option><option value="published">${esc(t('admin.budget_status_published'))}</option><option value="closed">${esc(t('admin.budget_status_closed'))}</option></select></div>
          <div class="col-12"><label class="form-label small">${esc(t('admin.budget_authority'))}</label><select class="form-select form-select-sm" id="budgetNewAuthority"><option value="">—</option>${authOptions}</select></div>
          <div class="col-12"><button type="button" class="btn btn-sm btn-primary" id="budgetSaveNew">${esc(t('admin.save'))}</button> <button type="button" class="btn btn-sm btn-outline-secondary" id="budgetCancelNew">${esc(t('admin.cancel'))}</button></div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover">
          <thead><tr><th>#</th><th>${t('admin.budget_col_title')}</th><th>${t('admin.budget_col_budget')}</th><th>${t('admin.budget_col_votes')}</th><th>${t('admin.common_status')}</th><th>${t('admin.budget_authority')}</th><th></th></tr></thead>
          <tbody id="budgetTableBody">${projects.length ? projects.map(p => budgetRow(p, authOptions)) : '<tr><td colspan="7" class="text-secondary">' + esc(t('admin.no_project')) + '</td></tr>'}</tbody>
        </table>
      </div>
    `;
    document.getElementById('btnBudgetAdd')?.addEventListener('click', () => {
      const f = document.getElementById('budgetAddForm');
      if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
    });
    document.getElementById('budgetCancelNew')?.addEventListener('click', () => {
      const f = document.getElementById('budgetAddForm');
      if (f) f.style.display = 'none';
    });
    document.getElementById('budgetSaveNew')?.addEventListener('click', async () => {
      const title = document.getElementById('budgetNewTitle')?.value?.trim();
      if (!title) { alert(t('admin.title_required')); return; }
      try {
        await fetchJson(API_BUDGET, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'create',
            title,
            description: document.getElementById('budgetNewDesc')?.value?.trim() || '',
            budget: parseFloat(document.getElementById('budgetNewBudget')?.value || '0') || 0,
            status: document.getElementById('budgetNewStatus')?.value || 'draft',
            authority_id: document.getElementById('budgetNewAuthority')?.value ? parseInt(document.getElementById('budgetNewAuthority').value, 10) : null
          })
        });
        document.getElementById('budgetAddForm').style.display = 'none';
        document.getElementById('budgetNewTitle').value = '';
        document.getElementById('budgetNewDesc').value = '';
        loadBudgetProjects();
      } catch (e) {
        alert(t('admin.error_generic') + ': ' + (e.message || e));
      }
    });
    list.querySelectorAll('[data-budget-edit]').forEach(btn => {
      const id = parseInt(btn.getAttribute('data-budget-edit'), 10);
      const proj = projects.find(p => p.id === id);
      if (!proj) return;
      btn.addEventListener('click', () => budgetEditRow(id, proj, authorities, list));
    });
    list.querySelectorAll('[data-budget-delete]').forEach(btn => {
      const id = parseInt(btn.getAttribute('data-budget-delete'), 10);
      btn.addEventListener('click', async () => {
        if (!confirm(t('admin.confirm_delete_project'))) return;
        try {
          await fetchJson(API_BUDGET, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) });
          loadBudgetProjects();
        } catch (e) {
          alert(t('admin.error_generic') + ': ' + (e.message || e));
        }
      });
    });
  } catch (e) {
    console.error(e);
    list.innerHTML = '<div class="text-secondary">' + esc((e && e.message) || t('admin.load_error')) + '</div>';
  }
}

function budgetRow(p, authOptions) {
  const statusLabels = BUDGET_STATUS_LABEL();
  const statusLabel = statusLabels[p.status] || p.status;
  const descStr = p.description || '';
  const descShort = descStr.slice(0, 60);
  return `<tr data-project-id="${p.id}">
    <td>${p.id}</td>
    <td><strong>${esc(p.title)}</strong>${descShort ? '<br><span class="text-secondary small">' + esc(descShort) + (descStr.length > 60 ? '…' : '') + '</span>' : ''}</td>
    <td>${Number(p.budget).toLocaleString('hu-HU')} Ft</td>
    <td>${p.vote_count || 0}</td>
    <td>${esc(statusLabel)}</td>
    <td>${esc(p.authority_name || '—')}</td>
    <td><button type="button" class="btn btn-sm btn-outline-secondary" data-budget-edit="${p.id}">${esc(t('admin.edit'))}</button> <button type="button" class="btn btn-sm btn-outline-danger" data-budget-delete="${p.id}">${esc(t('admin.delete'))}</button></td>
  </tr>`;
}

function budgetEditRow(id, proj, authorities, listEl) {
  const tr = listEl.querySelector(`tr[data-project-id="${id}"]`);
  if (!tr) return;
  const authOpts = (authorities || []).map(a => `<option value="${a.id}"${a.id == proj.authority_id ? ' selected' : ''}>${esc(a.name || a.city || '')}</option>`).join('');
  tr.innerHTML = `
    <td colspan="7" class="align-top bg-light p-2">
      <div class="row g-2">
        <div class="col-12"><input class="form-control form-control-sm" id="budgetEditTitle" value="${esc(proj.title)}" placeholder="${esc(t('idea.title_placeholder'))}"></div>
        <div class="col-12"><textarea class="form-control form-control-sm" id="budgetEditDesc" rows="2">${esc(proj.description || '')}</textarea></div>
        <div class="col-4"><input type="number" class="form-control form-control-sm" id="budgetEditBudget" value="${Number(proj.budget)}" min="0" step="0.01"></div>
        <div class="col-4"><select class="form-select form-select-sm" id="budgetEditStatus"><option value="draft"${proj.status==='draft'?' selected':''}>${esc(t('admin.budget_status_draft'))}</option><option value="published"${proj.status==='published'?' selected':''}>${esc(t('admin.budget_status_published'))}</option><option value="closed"${proj.status==='closed'?' selected':''}>${esc(t('admin.budget_status_closed'))}</option></select></div>
        <div class="col-4"><select class="form-select form-select-sm" id="budgetEditAuthority"><option value="">—</option>${authOpts}</select></div>
        <div class="col-12"><button type="button" class="btn btn-sm btn-primary" id="budgetSaveEdit">${esc(t('admin.save'))}</button> <button type="button" class="btn btn-sm btn-outline-secondary" id="budgetCancelEdit">${esc(t('admin.cancel'))}</button></div>
      </div>
    </td>
  `;
  document.getElementById('budgetSaveEdit')?.addEventListener('click', async () => {
    const title = document.getElementById('budgetEditTitle')?.value?.trim();
    if (!title) { alert(t('api.facility_name_required') || 'Title required'); return; }
    try {
      await fetchJson(API_BUDGET, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update',
          id,
          title,
          description: document.getElementById('budgetEditDesc')?.value?.trim() || '',
          budget: parseFloat(document.getElementById('budgetEditBudget')?.value || '0') || 0,
          status: document.getElementById('budgetEditStatus')?.value || 'draft',
          authority_id: document.getElementById('budgetEditAuthority')?.value ? parseInt(document.getElementById('budgetEditAuthority').value, 10) : null
        })
      });
      loadBudgetProjects();
    } catch (e) {
      alert(t('admin.error_generic') + ': ' + (e.message || e));
    }
  });
  document.getElementById('budgetCancelEdit')?.addEventListener('click', () => loadBudgetProjects());
}

document.getElementById('loadReports')?.addEventListener('click', loadReports);
document.getElementById('refreshReports')?.addEventListener('click', loadReports);
let searchTimer = null;
document.getElementById('reportSearch')?.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadReports(), 300);
});
document.getElementById('reportLimit')?.addEventListener('change', () => loadReports());

document.getElementById('refreshUsers')?.addEventListener('click', loadUsers);
document.getElementById('userSearch')?.addEventListener('input', () => loadUsers());
document.getElementById('userRoleFilter')?.addEventListener('change', () => loadUsers());
document.getElementById('userActiveFilter')?.addEventListener('change', () => loadUsers());

document.getElementById('layerCategory')?.addEventListener('change', () => {
  const cat = document.getElementById('layerCategory').value;
  const keyInput = document.getElementById('layerKey');
  const hint = document.getElementById('layerTreesHint');
  if (cat === 'trees') {
    if (keyInput) { keyInput.value = 'trees'; keyInput.readOnly = true; keyInput.placeholder = 'trees (fix)'; }
    if (hint) hint.style.display = 'inline';
  } else {
    if (keyInput) { keyInput.readOnly = false; keyInput.placeholder = t('admin.layer_key_placeholder'); }
    if (hint) hint.style.display = 'none';
  }
});

document.getElementById('createLayer')?.addEventListener('click', async () => {
  const cat = document.getElementById('layerCategory').value;
  const keyInput = document.getElementById('layerKey');
  const key = (cat === 'trees') ? 'trees' : (keyInput.value.trim() || '');
  const body = {
    action:'create_layer',
    layer_key: key,
    name: document.getElementById('layerName').value.trim() || (cat === 'trees' ? 'Fák (fakataszter)' : ''),
    category: cat,
    is_active: document.getElementById('layerActive').checked ? 1 : 0,
    is_temporary: document.getElementById('layerTemporary').checked ? 1 : 0,
    visible_from: document.getElementById('layerFrom').value || null,
    visible_to: document.getElementById('layerTo').value || null,
    authority_id: document.getElementById('layerAuthority')?.value ? Number(document.getElementById('layerAuthority').value) : null
  };
  try{
    await fetchJson(API_LAYERS, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    keyInput.value = '';
    document.getElementById('layerName').value = '';
    await loadLayers();
    await loadLayerMarkers();
  }catch(e){
    alert(t('admin.layer_save_error') + ': ' + (e.message || e));
  }
});

document.getElementById('createPoint')?.addEventListener('click', async () => {
  const body = {
    action:'create_point',
    layer_id: Number(document.getElementById('pointLayerSelect').value || 0),
    name: document.getElementById('pointName').value.trim(),
    lat: document.getElementById('pointLat').value.trim(),
    lng: document.getElementById('pointLng').value.trim(),
    address: document.getElementById('pointAddress').value.trim(),
    meta_json: document.getElementById('pointMeta').value.trim() || null
  };
  try{
    await fetchJson(API_LAYERS, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('pointName').value = '';
    document.getElementById('pointLat').value = '';
    document.getElementById('pointLng').value = '';
    document.getElementById('pointAddress').value = '';
    document.getElementById('pointMeta').value = '';
    await loadPoints(body.layer_id);
    await loadLayerMarkers();
  }catch(e){
    alert(t('admin.point_save_error') + ': ' + e.message);
  }
});

function parseNum(val) {
  const v = parseFloat(String(val || '').replace(',', '.'));
  return isFinite(v) ? v : null;
}
document.getElementById('createAuthority')?.addEventListener('click', async () => {
  const body = {
    action:'create_authority',
    name: document.getElementById('authorityName').value.trim(),
    city: document.getElementById('authorityCity').value.trim(),
    address: document.getElementById('authorityAddress')?.value?.trim() || '',
    contact_email: document.getElementById('authorityEmail').value.trim(),
    contact_phone: document.getElementById('authorityPhone').value.trim(),
    is_active: 1
  };
  const minLat = parseNum(document.getElementById('authorityMinLat')?.value);
  const maxLat = parseNum(document.getElementById('authorityMaxLat')?.value);
  const minLng = parseNum(document.getElementById('authorityMinLng')?.value);
  const maxLng = parseNum(document.getElementById('authorityMaxLng')?.value);
  if (minLat != null) body.min_lat = minLat;
  if (maxLat != null) body.max_lat = maxLat;
  if (minLng != null) body.min_lng = minLng;
  if (maxLng != null) body.max_lng = maxLng;
  try{
    await fetchJson(API_AUTHORITIES, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('authorityName').value = '';
    document.getElementById('authorityCity').value = '';
    document.getElementById('authorityAddress').value = '';
    document.getElementById('authorityEmail').value = '';
    document.getElementById('authorityPhone').value = '';
    document.getElementById('authorityMinLat').value = '';
    document.getElementById('authorityMaxLat').value = '';
    document.getElementById('authorityMinLng').value = '';
    document.getElementById('authorityMaxLng').value = '';
    await loadAuthorities();
  }catch(e){
    alert(t('admin.authority_save_error') + ': ' + e.message);
  }
});

document.getElementById('createContact')?.addEventListener('click', async () => {
  const body = {
    action:'create_contact',
    authority_id: Number(document.getElementById('contactAuthoritySelect').value || 0),
    service_code: document.getElementById('contactCode').value.trim(),
    name: document.getElementById('contactName').value.trim(),
    description: document.getElementById('contactDesc').value.trim(),
    is_active: 1
  };
  try{
    await fetchJson(API_AUTHORITIES, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('contactCode').value = '';
    document.getElementById('contactName').value = '';
    document.getElementById('contactDesc').value = '';
    await loadAuthorities();
  }catch(e){
    alert(t('admin.contact_save_error') + ': ' + e.message);
  }
});

document.getElementById('assignUser')?.addEventListener('click', async () => {
  const body = {
    action:'assign_user',
    authority_id: Number(document.getElementById('assignAuthoritySelect').value || 0),
    email: document.getElementById('assignEmail').value.trim()
  };
  try{
    await fetchJson(API_AUTHORITIES, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('assignEmail').value = '';
    await loadAuthorities();
  }catch(e){
    alert(t('admin.assign_error') + ': ' + e.message);
  }
});

document.getElementById('refreshStats')?.addEventListener('click', loadStats);

initTabs();
document.querySelectorAll('.app-sidebar .sidebar-section-header').forEach(function(header){
  header.addEventListener('click', function(){
    header.classList.toggle('sidebar-section-collapsed');
    var next = header.nextElementSibling;
    while (next && !next.classList.contains('nav-header')) {
      next.classList.toggle('sidebar-section-item-hidden');
      next = next.nextElementSibling;
    }
  });
  header.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); header.click(); } });
});
loadStats();
loadReports();
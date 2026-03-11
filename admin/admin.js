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
  return j;
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
  const m = {
    road:'Úthiba / kátyú',
    sidewalk:'Járda / burkolat hiba',
    lighting:'Közvilágítás',
    trash:'Szemét / illegális',
    green:'Zöldterület / veszélyes fa',
    traffic:'Közlekedés / tábla',
    idea:'Ötlet / javaslat',
    civil_event:'Civil esemény'
  };
  return m[cat] || cat;
}

// --- Státuszok (legacy + jarokelo irány) ---
const STATUS_LABEL = {
  pending: 'Pending (régi)',
  approved: 'Publikálva',
  rejected: 'Elutasítva',

  new: 'Új',
  needs_info: 'Kiegészítésre vár',
  forwarded: 'Továbbítva',
  waiting_reply: 'Válaszra vár',
  in_progress: 'Megoldás alatt',
  solved: 'Megoldva',
  closed: 'Lezárva'
};

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
  return STATUS_LABEL[st] || st;
}

function reporterLine(r){
  const name = r.reporter_display_name || r.reporter_name || '';
  if (!name) return '';
  const level = r.reporter_level ? ` • ${esc(r.reporter_level)}` : '';
  if (r.reporter_profile_public && r.reporter_user_id) {
    return `<div class="text-secondary"><b>Beküldő:</b> <a href="${BASE}/user/profile.php?id=${encodeURIComponent(r.reporter_user_id)}" target="_blank">${esc(name)}</a>${level}</div>`;
  }
  return `<div class="text-secondary"><b>Beküldő:</b> ${esc(name)}${level}</div>`;
}

const CAT_LABEL = {
  road:'Úthiba', sidewalk:'Járda', lighting:'Közvilágítás', trash:'Szemét',
  green:'Zöld', traffic:'Közlekedés', idea:'Ötlet', civil_event:'Civil'
};

async function loadStats(){
  try{
    const j = await fetchJson(API_STATS);
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
        `Új: ${status.new || 0}`,
        `Publikált: ${status.approved || 0}`,
        `Folyamatban: ${status.in_progress || 0}`,
        `Megoldva: ${status.solved || 0}`,
        `Elutasítva: ${status.rejected || 0}`
      ];
      elStatus.textContent = parts.join(' • ');
    }

    if (elCat){
      const parts = Object.entries(category).map(([k,v]) => (CAT_LABEL[k]||k)+': '+v);
      elCat.textContent = parts.length ? parts.join(' • ') : '—';
    }

    const statusOrder = ['new','approved','in_progress','solved','rejected','needs_info','forwarded','waiting_reply','closed','pending'];
    const statusColors = { new:'#0d6efd', approved:'#198754', in_progress:'#ffc107', solved:'#20c997', rejected:'#dc3545', needs_info:'#6f42c1', forwarded:'#fd7e14', waiting_reply:'#0dcaf0', closed:'#6c757d', pending:'#adb5bd' };
    const chartStatus = document.getElementById('chartStatus');
    if (chartStatus){
      const maxS = Math.max(1, ...Object.values(status));
      const items = statusOrder.filter(s => (status[s]||0) > 0).map(s => ({ k:s, v:status[s]||0, label:STATUS_LABEL[s]||s, color:statusColors[s]||'#6c757d' }));
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
      const items = Object.entries(category).sort((a,b)=>b[1]-a[1]).map(([k,v]) => ({ k, v, label:CAT_LABEL[k]||k }));
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
        <span class="badge text-bg-secondary">Új: <b>${status.new || 0}</b></span>
        <span class="badge text-bg-secondary">Publikált: <b>${status.approved || 0}</b></span>
        <span class="badge text-bg-secondary">Folyamatban: <b>${status.in_progress || 0}</b></span>
        <span class="badge text-bg-secondary">Megoldva: <b>${status.solved || 0}</b></span>
        <span class="badge text-bg-secondary">Elutasítva: <b>${status.rejected || 0}</b></span>
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
      el.innerHTML = '<span class="meta">Nincs csatolmány.</span>';
      return;
    }

    el.innerHTML = rows.map(a => {
      const name = esc(a.filename || '');
      const url = esc(a.url || '');
      const created = esc(a.created_at || '');
      return `
        <div class="meta" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <a href="${url}" target="_blank" rel="noopener">${name || 'Csatolmány'}</a>
          <span>${created}</span>
          <button class="del" data-att-del="${a.id}">Törlés</button>
        </div>
      `;
    }).join('');

    el.querySelectorAll('button[data-att-del]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.getAttribute('data-att-del'));
        if (!id) return;
        if(!confirm('Biztos törlöd a csatolmányt?')) return;
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
          alert('Hiba törlés közben: ' + e.message);
        }finally{
          btn.disabled = false;
        }
      });
    });
  }catch(e){
    console.error(e);
    el.innerHTML = '<span class="meta">Hiba a csatolmányok betöltésekor.</span>';
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
        ${r.case_no ? `<span class="badge text-bg-secondary">Ügyszám: <span data-role="case">${esc(r.case_no)}</span></span>` : ''}
      </div>
      <div class="fw-semibold mt-2">${esc(catLabel(r.category))}</div>
      ${r.title ? `<div class="mt-1">${esc(r.title)}</div>` : ''}
      <div class="text-secondary mt-1" title="${esc(r.description)}">${esc(descriptionSummary(r.description))}</div>
      ${r.address_approx ? `<div class="text-secondary mt-1">${esc(r.address_approx)}</div>` : ''}
      ${r.authority_name ? `<div class="text-secondary small">Hatóság: ${esc(r.authority_name)}</div>` : ''}
      ${reporterLine(r)}
      <div class="text-secondary mt-1">${Number(r.lat).toFixed(6)}, ${Number(r.lng).toFixed(6)} • ${esc(r.created_at || '')}</div>

      <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
        <select data-role="status" class="form-select form-select-sm" style="min-width:220px">${optionsHtml}</select>
        <input data-role="note" class="form-control form-control-sm" placeholder="Megjegyzés (opcionális)" style="min-width:240px">
        <button data-action="save" class="btn btn-primary btn-sm">Mentés</button>
        <button data-action="delete" class="btn btn-outline-danger btn-sm">Törlés</button>
        <button data-action="export-fms" class="btn btn-outline-secondary btn-sm" title="Küldés a FixMyStreet rendszerbe">Export FMS</button>
      </div>

      <div class="mt-2">
        <button class="btn btn-outline-secondary btn-sm" data-action="log-toggle">Státusz napló</button>
        <div data-role="log" class="text-secondary mt-2" style="display:none"></div>
      </div>
      <div class="mt-2">
        <button class="btn btn-outline-secondary btn-sm" data-action="att-toggle">Csatolmányok</button>
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
        if(!confirm(`Biztos törlöd? (#${r.id})`)) return;

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
          alert('Hiba törlés közben: ' + e.message);
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
          alert('Elküldve a FixMyStreet rendszerbe.');
        } catch (e) {
          if (e.message && e.message.includes('400')) {
            alert('A FixMyStreet nincs beállítva (FMS_OPEN311_*).');
          } else {
            alert('Hiba: ' + e.message);
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
        alert('Hiba mentésnél: ' + e.message);
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
      opt.textContent = esc(a.name || a.city || 'Hatóság #' + a.id);
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
    const rows = j.data || [];
    renderReports(rows);

    if (map) {
      for(const r of rows){
        const mk = L.marker([r.lat, r.lng], { icon: getIcon(r.category) })
          .addTo(map)
          .bindPopup(
            `<b>#${r.id}</b> <small>(${esc(statusLabel(r.status))})</small><br>` +
            (r.case_no ? `<small><b>Ügyszám:</b> ${esc(r.case_no)}</small><br>` : '') +
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
    alert('Hiba betöltésnél: ' + e.message);
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

    const roleOpts = ['user','civiluser','communityuser','govuser','admin','superadmin'].map(r => `<option value="${r}">${r}</option>`).join('');
    list.innerHTML = `
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th><th>Név</th><th>E-mail</th><th>Szint</th><th>Role</th><th>Állapot</th><th>Művelet</th>
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
              <td>${Number(u.is_active) === 0 ? 'Tiltott' : 'Aktív'}</td>
              <td>
                <button class="soft user-toggle">${Number(u.is_active) === 0 ? 'Aktivál' : 'Tilt'}</button>
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
          alert('Role frissítés hiba: ' + e.message);
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
          alert('Állapot frissítés hiba: ' + e.message);
        }
      });
    });
  }catch(e){
    console.error(e);
    list.innerHTML = '<div class="text-secondary">Hiba a betöltésnél.</div>';
  }
}

async function loadLayerAuthorityOptions(){
  const sel = document.getElementById('layerAuthority');
  if (!sel) return;
  try {
    const j = await fetchJson(API_AUTHORITIES);
    const authorities = j.authorities || [];
    const first = sel.querySelector('option');
    sel.innerHTML = first ? first.outerHTML : '<option value="">— Nincs —</option>';
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
      list.innerHTML = '<div class="text-secondary">Nincs layer.</div>';
    } else {
      list.innerHTML = rows.map(l => {
        const isTrees = (l.layer_key === 'trees' || l.layer_type === 'trees');
        const authLine = (l.authority_name || l.authority_city) ? ` • ${esc(l.authority_name || l.authority_city)}` : '';
        return `
        <div class="admin-item" data-layer="${l.id}" data-layer-type="${esc(l.layer_type || '')}">
          <div><b>${esc(l.name)}</b> <span class="meta">(${esc(l.layer_key)})</span></div>
          <div class="meta">${esc(l.category)}${authLine}${isTrees ? ' • Fakataszter (billenő = fa réteg ki/be)' : ' • pontok: ' + (l.point_count || 0)}</div>
          <div class="actions">
            <label class="check"><input type="checkbox" class="layer-active" ${Number(l.is_active) ? 'checked' : ''}> Aktív</label>
            ${isTrees ? '' : '<button class="soft layer-points">Pontok</button>'}
            <button class="del layer-delete">Törlés</button>
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
        if (!confirm('Biztos törlöd a layert és pontjait?')) return;
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
    list.innerHTML = '<div class="text-secondary">Hiba a betöltésnél.</div>';
  }
}

async function loadPoints(layerId){
  const list = document.getElementById('pointList');
  list.textContent = t('admin.load') + '...';
  try{
    const j = await fetchJson(`${API_LAYERS}?layer_id=${encodeURIComponent(layerId)}`);
    const rows = j.data || [];
    if (!rows.length){
      list.innerHTML = '<div class="text-secondary">Nincs pont.</div>';
      return;
    }
    list.innerHTML = rows.map(p => `
      <div class="admin-item" data-point="${p.id}">
        <div><b>${esc(p.name || 'Pont')}</b></div>
        <div class="meta">${esc(p.address || '')}</div>
        <div class="meta">${Number(p.lat).toFixed(6)}, ${Number(p.lng).toFixed(6)}</div>
        <div class="actions">
          <button class="del point-delete">Törlés</button>
        </div>
      </div>
    `).join('');

    list.querySelectorAll('[data-point]').forEach(item => {
      const id = Number(item.getAttribute('data-point'));
      item.querySelector('.point-delete')?.addEventListener('click', async () => {
        if (!confirm('Biztos törlöd a pontot?')) return;
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
    list.innerHTML = '<div class="text-secondary">Hiba a betöltésnél.</div>';
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
      list.innerHTML = authorities.map(a => `
        <div class="admin-item">
          <div class="meta">
            <b>${esc(a.name)}</b> • ${esc(a.city || '')}
          </div>
          <div class="text-secondary">${esc(a.contact_email || '')} ${esc(a.contact_phone || '')}</div>
          <div class="actions">
            <button class="btn btn-outline-danger btn-sm auth-del" data-id="${a.id}">Törlés</button>
          </div>
        </div>
      `).join('');
      list.querySelectorAll('.auth-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm('Biztos törlöd a hatóságot?')) return;
          await fetchJson(API_AUTHORITIES, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ action:'delete_authority', id: Number(btn.dataset.id) })
          });
          await loadAuthorities();
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
          if (!confirm('Biztos törlöd a szolgáltatást?')) return;
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
          <div class="text-secondary">Hatóság: ${esc(a.authority_name || a.authority_id)}</div>
          <div class="actions">
            <button class="btn btn-outline-danger btn-sm assign-del" data-id="${a.id}">Törlés</button>
          </div>
        </div>
      `).join('');
      assignList.querySelectorAll('.assign-del').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm('Biztos törlöd a hozzárendelést?')) return;
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
    list.innerHTML = '<div class="text-secondary">Hiba a betöltésnél.</div>';
    contactList.innerHTML = '<div class="text-secondary">Hiba a betöltésnél.</div>';
    assignList.innerHTML = '<div class="text-secondary">Hiba a betöltésnél.</div>';
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
      list.innerHTML = '<div class="text-secondary">Nincs modul.</div>';
      return;
    }
    list.innerHTML = modules.map(m => {
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
        const placeholder = s.mask && s.set ? '•••••••• (változatlan)' : (s.placeholder || '');
        const val = s.mask && s.set ? '' : (s.value || '');
        const minAttr = s.type === 'number' ? ' min="0"' : '';
        return `<div class="mb-2"><label class="form-label small">${esc(s.label)}</label><input class="form-control form-control-sm" data-module-key="${esc(m.id)}" data-setting-key="${esc(s.key)}" type="${type}" value="${esc(val)}" placeholder="${esc(placeholder)}"${minAttr}></div>`;
      }).join('');
      const isMistral = (m.id === 'mistral');
      const isOpenai = (m.id === 'openai');
      return `
        <div class="card mb-3" data-module-id="${esc(m.id)}">
          <div class="card-body">
            <h6 class="card-title">${esc(m.name)}</h6>
            <p class="text-secondary small">${esc(m.description || '')}</p>
            <div class="form-check mb-2">
              <input class="form-check-input module-enabled" type="checkbox" data-module-id="${esc(m.id)}" id="mod-${esc(m.id)}" ${enabled ? 'checked' : ''}>
              <label class="form-check-label" for="mod-${esc(m.id)}">Bekapcsolva</label>
            </div>
            ${fields}
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <button type="button" class="btn btn-sm btn-primary module-save" data-module-id="${esc(m.id)}">${t('admin.module_save')}</button>
              ${isMistral ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestMistral">Teszt Mistral</button>' : ''}
              ${isOpenai ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestOpenai">Teszt OpenAI</button>' : ''}
            </div>
            ${isMistral ? '<div id="mistralTestResult" class="small mt-2 text-secondary"></div>' : ''}
            ${isOpenai ? '<div id="openaiTestResult" class="small mt-2 text-secondary"></div>' : ''}
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
          if (inp.type === 'password' && inp.placeholder && inp.placeholder.includes('változatlan') && inp.value === '') return;
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
          alert('Hiba: ' + (e.message || e));
        }
      });
    });

    const btnTest = document.getElementById('btnTestMistral');
    const testResult = document.getElementById('mistralTestResult');
    if (btnTest && testResult) {
      btnTest.addEventListener('click', async () => {
        testResult.textContent = 'Tesztelés...';
        btnTest.disabled = true;
        try {
          const j = await fetchJson(API_MODULES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test_mistral' })
          });
          if (j && j.ok) {
            testResult.textContent = (j.message || 'Mistral: OK');
            testResult.className = 'small mt-2 text-success';
          } else {
            testResult.textContent = (j && j.error) ? j.error : 'Ismeretlen hiba';
            testResult.className = 'small mt-2 text-danger';
          }
        } catch (e) {
          testResult.textContent = 'Hiba: ' + (e.message || e);
          testResult.className = 'small mt-2 text-danger';
        }
        btnTest.disabled = false;
      });
    }
    const btnTestOpenai = document.getElementById('btnTestOpenai');
    const openaiTestResult = document.getElementById('openaiTestResult');
    if (btnTestOpenai && openaiTestResult) {
      btnTestOpenai.addEventListener('click', async () => {
        openaiTestResult.textContent = 'Tesztelés...';
        btnTestOpenai.disabled = true;
        try {
          const j = await fetchJson(API_MODULES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'test_openai' })
          });
          if (j && j.ok) {
            openaiTestResult.textContent = (j.message || 'OpenAI: OK');
            openaiTestResult.className = 'small mt-2 text-success';
          } else {
            openaiTestResult.textContent = (j && j.error) ? j.error : 'Ismeretlen hiba';
            openaiTestResult.className = 'small mt-2 text-danger';
          }
        } catch (e) {
          openaiTestResult.textContent = 'Hiba: ' + (e.message || e);
          openaiTestResult.className = 'small mt-2 text-danger';
        }
        btnTestOpenai.disabled = false;
      });
    }
  } catch (e) {
    console.error(e);
    const msg = (e && e.message) ? e.message : 'Hiba a betöltésnél.';
    list.innerHTML = '<div class="text-secondary">' + esc(msg) + '</div>';
  }
}

function initTabs(){
  const tabs = document.querySelectorAll('.tab[data-tab]');
  const bodies = {
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
      Object.keys(bodies).forEach(k => {
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
    });
  });
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
    if (keyInput) { keyInput.readOnly = false; keyInput.placeholder = 'Layer kulcs (pl. election)'; }
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
    alert('Layer mentés hiba: ' + (e.message || e));
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
    alert('Pont mentés hiba: ' + e.message);
  }
});

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
  try{
    await fetchJson(API_AUTHORITIES, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('authorityName').value = '';
    document.getElementById('authorityCity').value = '';
    document.getElementById('authorityAddress').value = '';
    document.getElementById('authorityEmail').value = '';
    document.getElementById('authorityPhone').value = '';
    await loadAuthorities();
  }catch(e){
    alert('Hatóság mentés hiba: ' + e.message);
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
    alert('Szolgáltatás mentés hiba: ' + e.message);
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
    alert('Hozzárendelés hiba: ' + e.message);
  }
});

document.getElementById('refreshStats')?.addEventListener('click', loadStats);

initTabs();
loadStats();
loadReports();
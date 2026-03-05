// ====== Állítsd be, ha más mappában van a projekt ======
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
const LOGOUT_URL      = `${BASE}/admin/logout.php`;
// ========================================================

const map = L.map('map').setView([46.565, 20.667], 13);
map.attributionControl.setPrefix(false);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; OpenStreetMap közreműködők'
}).addTo(map);

let markers = [];
let markerById = new Map();
let layerMarkers = [];

function clearMarkers(){
  markers.forEach(m => map.removeLayer(m));
  markers = [];
  markerById.clear();
}

function clearLayerMarkers(){
  layerMarkers.forEach(m => map.removeLayer(m));
  layerMarkers = [];
}

function esc(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;');
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

async function loadStats(){
  try{
    const j = await fetchJson(API_STATS);
    const data = j.data || {};
    const reports7 = data.reports_7d ?? 0;
    const users7 = data.users_7d ?? 0;
    const status = data.status || {};

    const elReports = document.getElementById('kpiReports7');
    const elUsers = document.getElementById('kpiUsers7');
    const elStatus = document.getElementById('kpiStatus');
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
  el.textContent = 'Betöltés...';
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
      <div class="text-secondary mt-1">${esc(r.description)}</div>
      ${r.address_approx ? `<div class="text-secondary mt-1">${esc(r.address_approx)}</div>` : ''}
      ${reporterLine(r)}
      <div class="text-secondary mt-1">${Number(r.lat).toFixed(6)}, ${Number(r.lng).toFixed(6)} • ${esc(r.created_at || '')}</div>

      <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
        <select data-role="status" class="form-select form-select-sm" style="min-width:220px">${optionsHtml}</select>
        <input data-role="note" class="form-control form-control-sm" placeholder="Megjegyzés (opcionális)" style="min-width:240px">
        <button data-action="save" class="btn btn-primary btn-sm">Mentés</button>
        <button data-action="delete" class="btn btn-outline-danger btn-sm">Törlés</button>
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
    map.setView([r.lat, r.lng], Math.max(map.getZoom(), 17));
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
          if (mk){
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
    list.innerHTML = `<div class="text-secondary">Nincs találat ehhez a szűréshez.</div>`;
    return;
  }
  rows.forEach(r => list.appendChild(renderRow(r)));
}

async function loadReports(){
  const status = document.getElementById('statusFilter').value;
  const q = (document.getElementById('reportSearch').value || '').trim();
  const limit = Number(document.getElementById('reportLimit').value || 300);
  const list = document.getElementById('reportList');
  list.textContent = 'Betöltés...';
  clearMarkers();

  try{
    const qs = new URLSearchParams();
    qs.set('status', status);
    if (q) qs.set('q', q);
    if (limit) qs.set('limit', String(limit));
    const j = await fetchJson(`${API_LIST}?${qs.toString()}`);
    const rows = j.data || [];
    renderReports(rows);

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

    if(rows.length){
      map.setView([rows[0].lat, rows[0].lng], 14);
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
  list.textContent = 'Betöltés...';

  try{
    const qs = new URLSearchParams();
    if (q) qs.set('q', q);
    if (role) qs.set('role', role);
    if (active !== '') qs.set('active', active);
    const j = await fetchJson(`${API_USERS}?${qs.toString()}`);
    const rows = j.data || [];
    if (!rows.length){
    list.innerHTML = '<div class="text-secondary">Nincs találat.</div>';
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

async function loadLayers(){
  const list = document.getElementById('layerList');
  list.textContent = 'Betöltés...';
  try{
    const j = await fetchJson(API_LAYERS);
    const rows = j.data || [];
    if (!rows.length){
      list.innerHTML = '<div class="text-secondary">Nincs layer.</div>';
    } else {
      list.innerHTML = rows.map(l => `
        <div class="admin-item" data-layer="${l.id}">
          <div><b>${esc(l.name)}</b> <span class="meta">(${esc(l.layer_key)})</span></div>
          <div class="meta">${esc(l.category)} • pontok: ${l.point_count || 0}</div>
          <div class="actions">
            <label class="check"><input type="checkbox" class="layer-active" ${Number(l.is_active) ? 'checked' : ''}> Aktív</label>
            <button class="soft layer-points">Pontok</button>
            <button class="del layer-delete">Törlés</button>
          </div>
        </div>
      `).join('');
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
  list.textContent = 'Betöltés...';
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
  list.textContent = 'Betöltés...';
  contactList.textContent = 'Betöltés...';
  assignList.textContent = 'Betöltés...';
  try{
    const j = await fetchJson(API_AUTHORITIES);
    const authorities = j.authorities || [];
    const contacts = j.contacts || [];
    const assignments = j.assignments || [];

    select.innerHTML = authorities.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
    assignSelect.innerHTML = authorities.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');

    if (!authorities.length){
      list.innerHTML = '<div class="text-secondary">Nincs adat.</div>';
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
      contactList.innerHTML = '<div class="text-secondary">Nincs adat.</div>';
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
      assignList.innerHTML = '<div class="text-secondary">Nincs adat.</div>';
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

function initTabs(){
  const tabs = document.querySelectorAll('.tab[data-tab]');
  const bodies = {
    reports: document.getElementById('tab-reports'),
    users: document.getElementById('tab-users'),
    layers: document.getElementById('tab-layers'),
    authorities: document.getElementById('tab-authorities')
  };
  tabs.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const key = btn.getAttribute('data-tab');
      tabs.forEach(t => t.classList.toggle('active', t === btn));
      Object.keys(bodies).forEach(k => {
        bodies[k].hidden = (k !== key);
      });
      if (key === 'users') loadUsers();
      if (key === 'layers') {
        clearMarkers();
        loadLayers();
        loadLayerMarkers();
      }
      if (key === 'reports') {
        clearLayerMarkers();
      }
      if (key === 'authorities') {
        clearLayerMarkers();
        loadAuthorities();
      }
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

document.getElementById('createLayer')?.addEventListener('click', async () => {
  const body = {
    action:'create_layer',
    layer_key: document.getElementById('layerKey').value.trim(),
    name: document.getElementById('layerName').value.trim(),
    category: document.getElementById('layerCategory').value,
    is_active: document.getElementById('layerActive').checked ? 1 : 0,
    is_temporary: document.getElementById('layerTemporary').checked ? 1 : 0,
    visible_from: document.getElementById('layerFrom').value || null,
    visible_to: document.getElementById('layerTo').value || null
  };
  try{
    await fetchJson(API_LAYERS, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('layerKey').value = '';
    document.getElementById('layerName').value = '';
    await loadLayers();
    await loadLayerMarkers();
  }catch(e){
    alert('Layer mentés hiba: ' + e.message);
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
    contact_email: document.getElementById('authorityEmail').value.trim(),
    contact_phone: document.getElementById('authorityPhone').value.trim(),
    is_active: 1
  };
  try{
    await fetchJson(API_AUTHORITIES, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    document.getElementById('authorityName').value = '';
    document.getElementById('authorityCity').value = '';
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

initTabs();
loadStats();
loadReports();
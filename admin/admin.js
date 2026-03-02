// ====== Állítsd be, ha más mappában van a projekt ======
const BASE = '/terkep';
const API_LIST        = `${BASE}/api/admin_reports.php`;
const API_ACTION      = `${BASE}/api/admin_action.php`; // delete-hez (legacy)
const API_SET_STATUS  = `${BASE}/api/report_set_status.php`;
const API_STATUS_LOG  = `${BASE}/api/report_status_log.php`;
const API_ATTACH_LIST = `${BASE}/api/report_attachments.php`;
const API_ATTACH_DEL  = `${BASE}/api/report_attachment_delete.php`;
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

function clearMarkers(){
  markers.forEach(m => map.removeLayer(m));
  markers = [];
  markerById.clear();
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

async function loadCounts(){
  // gyors, de érthető pillák
  const keys = ['pending','new','approved','in_progress','solved','rejected'];
  const counts = {};

  for(const st of keys){
    try{
      const j = await fetchJson(`${API_LIST}?status=${encodeURIComponent(st)}`);
      counts[st] = (j.data || []).length;
    }catch(_){
      counts[st] = 0;
    }
  }

  const el = document.getElementById('counts');
  el.innerHTML = `
    <div class="pill">Új: <b>${counts.new||0}</b></div>
    <div class="pill">Publikált: <b>${counts.approved||0}</b></div>
    <div class="pill">Folyamatban: <b>${counts.in_progress||0}</b></div>
    <div class="pill">Megoldva: <b>${counts.solved||0}</b></div>
    <div class="pill">Elutasítva: <b>${counts.rejected||0}</b></div>
    <div class="pill">Pending (régi): <b>${counts.pending||0}</b></div>
  `;
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
  wrap.className = 'item';

  const optionsHtml = STATUS_OPTIONS.map(s =>
    `<option value="${s}" ${r.status===s?'selected':''}>${esc(statusLabel(s))}</option>`
  ).join('');

  wrap.innerHTML = `
    <b>#${r.id}</b> <span class="meta">(<span data-role="status-text">${esc(statusLabel(r.status))}</span>)</span><br>
    ${r.case_no ? `<div class="meta"><b>Ügyszám:</b> <span data-role="case">${esc(r.case_no)}</span></div>` : ''}
    <b>${esc(catLabel(r.category))}</b><br>
    ${r.title ? `<div>${esc(r.title)}</div>` : ''}
    <div>${esc(r.description)}</div>
    ${r.address_approx ? `<div class="meta">${esc(r.address_approx)}</div>` : ''}
    <div class="meta">${Number(r.lat).toFixed(6)}, ${Number(r.lng).toFixed(6)} • ${esc(r.created_at || '')}</div>

    <div class="btns" style="align-items:center; gap:8px; flex-wrap:wrap">
      <select data-role="status" style="min-width:220px">${optionsHtml}</select>
      <input data-role="note" placeholder="Megjegyzés (opcionális)" style="min-width:240px; padding:10px; border:1px solid #e6eaf2; border-radius:12px">
      <button data-action="save" class="primary">Mentés</button>
      <button data-action="delete" class="del">Törlés</button>
    </div>

    <div class="meta" data-role="log" style="margin-top:8px"></div>
    <div class="meta" style="margin-top:8px">
      <button class="soft" data-action="att-toggle">Csatolmányok</button>
      <div data-role="att-list" style="margin-top:8px; display:none"></div>
    </div>
  `;

  // hover → map jump
  wrap.addEventListener('mouseenter', () => {
    map.setView([r.lat, r.lng], Math.max(map.getZoom(), 17));
    const mk = markerById.get(r.id);
    if (mk) mk.openPopup();
  });

  // log betöltés azonnal (ha van)
  const logEl = wrap.querySelector('[data-role="log"]');
  loadLogInto(logEl, r.id).catch(()=>{});

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

        // log frissítés
        await loadLogInto(logEl, r.id);

      }catch(e){
        console.error(e);
        alert('Hiba mentésnél: ' + e.message);
      }
    });
  });

  return wrap;
}

async function loadData(){
  const status = document.getElementById('status').value;
  const list = document.getElementById('list');
  list.textContent = 'Betöltés...';

  clearMarkers();

  try{
    await loadCounts();

    const j = await fetchJson(`${API_LIST}?status=${encodeURIComponent(status)}`);
    const rows = j.data || [];
    list.innerHTML = '';

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

      list.appendChild(renderRow(r));
    }

    if(rows.length){
      map.setView([rows[0].lat, rows[0].lng], 14);
    }else{
      list.innerHTML = `<div class="meta">Nincs találat ehhez a szűréshez.</div>`;
    }

  }catch(e){
    console.error(e);
    list.innerHTML = '';
    alert('Hiba betöltésnél: ' + e.message);
  }
}

document.getElementById('load')?.addEventListener('click', loadData);
document.getElementById('refresh')?.addEventListener('click', loadData);

document.getElementById('logout')?.addEventListener('click', () => {
  window.location.href = LOGOUT_URL;
});
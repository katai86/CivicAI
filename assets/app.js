// Public map (bejelentés + jóváhagyott jelölők)
const BASE = document.body?.dataset?.appBase || '/terkep';
const IS_LOGGED_IN = document.body?.dataset?.loggedIn === '1' || !!window.TERKEP_LOGGED_IN;
const USER_ROLE = document.body?.dataset?.role || window.TERKEP_ROLE || 'guest';
const API_LIST   = `${BASE}/api/reports_list.php`;
const API_CREATE = `${BASE}/api/report_create.php`;
const API_NEARBY = `${BASE}/api/reports_nearby.php`;
const API_SUGGEST_CATEGORY = `${BASE}/api/suggest_category.php`;
const API_LAYERS = `${BASE}/api/layers_public.php`;
const API_FACILITIES = `${BASE}/api/facilities_list.php`;
const API_CIVIL_EVENTS = `${BASE}/api/civil_events_list.php`;
const API_TREES = `${BASE}/api/trees_list.php`;
const API_TREE_ADOPT = `${BASE}/api/tree_adopt.php`;
const API_TREE_WATER = `${BASE}/api/tree_watering.php`;
const API_TREE_CREATE = `${BASE}/api/tree_create.php`;
const API_CIVIL_EVENT_CREATE = `${BASE}/api/civil_event_create.php`;
const API_REPORT_LIKE = `${BASE}/api/report_like.php`;
const GEO_SEARCH = 'https://nominatim.openstreetmap.org/search';

// ====== Map init ======
const mapCenter = (() => {
  const body = document.body;
  const lat = parseFloat(body.dataset.mapLat);
  const lng = parseFloat(body.dataset.mapLng);
  const zoom = parseInt(body.dataset.mapZoom, 10);
  return { lat: isFinite(lat) ? lat : 47.1625, lng: isFinite(lng) ? lng : 19.5033, zoom: isFinite(zoom) ? zoom : 7 };
})();
const map = L.map('map').setView([mapCenter.lat, mapCenter.lng], mapCenter.zoom);
map.attributionControl.setPrefix(false);

L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
  maxZoom: 20,
  attribution: '&copy; OpenStreetMap közreműködők, Humanitarian style'
}).addTo(map);

// geolokáció: ha elérhető, ugorjon a felhasználó környékére
if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      if (isFinite(lat) && isFinite(lng)) {
        map.setView([lat, lng], 12, { animate: true });
      }
    },
    () => {},
    { enableHighAccuracy: false, timeout: 5000, maximumAge: 600000 }
  );
}

let markerLayers = []; // { marker, data }
let searchMarker = null;
let searchMarkerTimeout = null;
let layerMarkers = [];
let treeMarkers = [];
let activeTreeFilter = 'all';
let addTreeMode = false;
let addTreeMarker = null;
let addTreeMapClick = null;
let facilityMarkers = [];
let civilEventMarkers = [];
let reloadTimer = null;
let activeCategory = 'all';

// ====== Utils ======
function t(key){ return (window.LANG && window.LANG[key]) || key; }
function esc(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;');
}

async function fetchJson(url, opts){
  const res = await fetch(url, opts);
  const text = await res.text();
  let j = null;
  try { j = JSON.parse(text); } catch(_) {}

  if (!res.ok){
    const msg = (j && (j.error || j.message)) ? (j.error || j.message) : text;
    throw new Error(`HTTP ${res.status}: ${msg}`);
  }
  return j;
}

async function geocodeAddress(query, limit = 5){
  const url = `${GEO_SEARCH}?format=json&limit=${encodeURIComponent(limit)}&countrycodes=hu&q=${encodeURIComponent(query)}`;
  const res = await fetchJson(url);
  return Array.isArray(res) ? res : [];
}

function placeSearchMarker(lat, lon){
  if (searchMarker) map.removeLayer(searchMarker);
  if (searchMarkerTimeout) clearTimeout(searchMarkerTimeout);
  searchMarker = L.marker([lat, lon]).addTo(map);
  map.setView([lat, lon], 16, { animate: true });
  searchMarkerTimeout = setTimeout(() => {
    if (searchMarker) map.removeLayer(searchMarker);
    searchMarker = null;
  }, 10000);
}

// ====== Category labels + badge icons ======
const CAT_LABEL = {
  road:'Úthiba / kátyú',
  sidewalk:'Járda / burkolat hiba',
  lighting:'Közvilágítás',
  trash:'Szemét / illegális',
  green:'Zöldterület / veszélyes fa',
  traffic:'Közlekedés / tábla',
  idea:'Ötlet / javaslat',
  civil_event:'Civil esemény',
  tree_upload:'Fa feltöltés'
};

const ICON = {
  road:     { tw: '1f6a7', color:'#e74c3c' }, // 🚧
  sidewalk: { tw: '1f6b6', color:'#3498db' }, // 🚶
  lighting: { tw: '1f4a1', color:'#f1c40f' }, // 💡
  trash:    { tw: '1f5d1', color:'#34495e' }, // 🗑️
  green:    { tw: '1f333', color:'#27ae60' }, // 🌳
  traffic:  { tw: '1f6a6', color:'#9b59b6' }, // 🚦
  idea:     { tw: '2757',  color:'#ff7a00' }, // ❗
  civil_event: { tw: '1f91d', color:'#0ea5e9' }, // 🤝
  tree_upload: { tw: '1f333', color:'#27ae60' }  // 🌳
};

function catLabel(cat){ return CAT_LABEL[cat] || cat; }

function shortDescription(text) {
  if (!text || !String(text).trim()) return '';
  const s = String(text).trim();
  const maxLen = 180;
  if (s.length <= maxLen) return s;
  const dot = s.indexOf('.', 80);
  if (dot !== -1 && dot < maxLen) return s.slice(0, dot + 1).trim();
  return s.slice(0, maxLen).trim() + '…';
}

function canUseCivil(){
  return ['civiluser','admin','superadmin'].includes(USER_ROLE);
}

function canCreateIssue(){
  return ['user','govuser','admin','superadmin'].includes(USER_ROLE);
}

function buildCategoryOptions(){
  const opts = [];
  if (canCreateIssue()) {
    opts.push(
      { id:'road', label:'Úthiba / kátyú' },
      { id:'sidewalk', label:'Járda / burkolat hiba' },
      { id:'lighting', label:'Közvilágítás' },
      { id:'trash', label:'Szemét / illegális' },
      { id:'green', label:'Zöldterület / veszélyes fa' },
      { id:'traffic', label:'Közlekedés / tábla' },
      { id:'idea', label:'Ötlet / javaslat' },
      { id:'tree_upload', label:'Fa feltöltés' }
    );
  }
  if (canUseCivil()) {
    opts.push({ id:'civil_event', label:'Civil esemény' });
  }
  return opts.map(o => `<option value="${o.id}">${o.label}</option>`).join('');
}

function statusLabel(st){
  const m = {
    approved: 'Publikálva',
    pending: 'Ellenőrzés alatt',
    new: 'Új',
    needs_info: 'Kiegészítésre vár',
    forwarded: 'Továbbítva',
    waiting_reply: 'Válaszra vár',
    in_progress: 'Folyamatban',
    solved: 'Megoldva',
    closed: 'Lezárva',
    rejected: 'Elutasítva',
  };
  return m[st] || st;
}

function userLine(r){
  if (!r) return '';
  const name = r.reporter_display_name || r.reporter_name_public;
  const level = r.reporter_level ? ` • ${esc(r.reporter_level)}` : '';
  if (!name) return '';
  if (r.reporter_profile_public && r.reporter_user_id) {
    return `<small><b>${esc(t('popup.sender'))}:</b> <a href="${BASE}/user/profile.php?id=${encodeURIComponent(r.reporter_user_id)}" target="_blank">${esc(name)}</a>${level}</small><br>`;
  }
  return `<small><b>${esc(t('popup.sender'))}:</b> ${esc(name)}${level}</small><br>`;
}

function likeLine(r){
  if (!r) return '';
  const count = Number(r.like_count || 0);
  const liked = Number(r.liked_by_me || 0) === 1;
  const label = liked ? t('popup.liked') : t('modal.like');
  return `<div class="like-row" data-report-id="${r.id}">
    <button type="button" class="like-btn ${liked ? 'liked' : ''}" data-id="${r.id}" aria-label="${esc(t('modal.like'))}">❤️</button>
    <span class="like-count">${count}</span>
    <span class="like-label">${label}</span>
  </div>`;
}


function badgeIcon(cat){
  const info = ICON[cat] || { tw:'2753', color:'#999' };
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/${info.tw}.svg`;

  const html = `
    <div class="badge-marker" style="--ring:${info.color}">
      <img src="${url}" alt="" />
    </div>
  `;

  return L.divIcon({
    className: '',
    html,
    iconSize: [34, 34],
    iconAnchor: [17, 17],
    popupAnchor: [0, -14]
  });
}

function layerIcon(cat){
  const map = {
    election: { tw: '1f5f3', color: '#ff7a00' },   // 🗳️
    public: { tw: '1f3e5', color: '#00c48c' },     // 🏥
    tourism: { tw: '1f3db', color: '#8e44ff' },    // 🏛️
    trees: { tw: '1f333', color: '#22c55e' },      // 🌳
    default: { tw: '1f4cd', color: '#60a5fa' }     // 📍
  };
  const info = map[cat] || map.default;
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/${info.tw}.svg`;
  const html = `
    <div class="badge-marker" style="--ring:${info.color}">
      <img src="${url}" alt="" />
    </div>
  `;
  return L.divIcon({ className:'', html, iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-14] });
}

function facilityIcon(){
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/1f3e5.svg`;
  const html = `
    <div class="badge-marker" style="--ring:#00c48c">
      <img src="${url}" alt="" />
    </div>
  `;
  return L.divIcon({ className:'', html, iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-14] });
}

function civilEventIcon(){
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/1f4c5.svg`;
  const html = `
    <div class="badge-marker" style="--ring:#8e44ff">
      <img src="${url}" alt="" />
    </div>
  `;
  return L.divIcon({ className:'', html, iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-14] });
}

function greenActionIcon(){
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/1f33f.svg`;
  const html = `
    <div class="badge-marker" style="--ring:#16a34a">
      <img src="${url}" alt="" />
    </div>
  `;
  return L.divIcon({ className:'', html, iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-14] });
}

function treeIcon(tree){
  const colors = {
    adopted: '#3b82f6',
    needs_water: '#eab308',
    dangerous: '#dc2626',
    default: '#22c55e'
  };
  let ring = colors.default;
  if (tree) {
    if (tree.risk_level === 'high' || tree.risk_level === 'medium') ring = colors.dangerous;
    else if (tree.adopted_by_user_id) ring = colors.adopted;
    else if (tree.last_watered === null || (tree.last_watered && isOlderThanDays(tree.last_watered, 7))) ring = colors.needs_water;
  }
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/1f333.svg`;
  const html = `
    <div class="badge-marker" style="--ring:${ring}">
      <img src="${url}" alt="" />
    </div>
  `;
  return L.divIcon({ className:'', html, iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-14] });
}
function isOlderThanDays(ymdStr, days){
  if (!ymdStr) return true;
  const [y, m, d] = String(ymdStr).split('-').map(Number);
  const then = new Date(y, (m || 1) - 1, d || 1);
  const now = new Date();
  return (now - then) / (86400 * 1000) > days;
}

// ====== Legend toggle (default closed, chevron indicates expandable) ======
(function initLegend(){
  const legend = document.getElementById('legend');
  const btn = document.getElementById('legendToggle');
  const body = document.getElementById('legendBody');
  if(!legend || !btn || !body) return;

  const setExpanded = (isOpen) => {
    legend.classList.toggle('open', isOpen);
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  };

  setExpanded(false);

  btn.addEventListener('click', () => setExpanded(!legend.classList.contains('open')));
})();

function setLegendCount(n){
  const el = document.getElementById('legendCount');
  if (el) el.textContent = String(n ?? 0);
}

// ====== Load public markers ======
function clearMarkers(){
  for(const it of markerLayers){
    map.removeLayer(it.marker);
  }
  markerLayers = [];
}

function getBoundsParams(){
  const b = map.getBounds();
  return {
    minLat: b.getSouth(),
    maxLat: b.getNorth(),
    minLng: b.getWest(),
    maxLng: b.getEast()
  };
}

async function loadApprovedMarkers(){
  clearMarkers();

  const params = new URLSearchParams();
  const b = getBoundsParams();
  params.set('minLat', b.minLat);
  params.set('maxLat', b.maxLat);
  params.set('minLng', b.minLng);
  params.set('maxLng', b.maxLng);
  params.set('limit', '800');
  if (activeCategory && activeCategory !== 'all') {
    params.set('category', activeCategory);
  }
  const j = await fetchJson(`${API_LIST}?${params.toString()}`);
  const rows = j.data || [];

  for(const r of rows){
    const mk = L.marker([r.lat, r.lng], { icon: badgeIcon(r.category) })
      .addTo(map)
      .bindPopup(
        `<b>#${r.id}</b><br>` +
        `<b>${esc(catLabel(r.category))}</b><br>` +
        (r.status ? `<small><b>Státusz:</b> ${esc(statusLabel(r.status))}</small><br>` : '') +
        userLine(r) +
        (r.title ? `<b>${esc(r.title)}</b><br>` : '') +
        (r.description ? `${esc(shortDescription(r.description))}<br>` : '') +
        `<div class="popup-like-wrap">${likeLine(r)}</div>`
      );

    markerLayers.push({ marker: mk, data: r });
  }

  setLegendCount(rows.length);
}

function scheduleReload(){
  if (reloadTimer) clearTimeout(reloadTimer);
  reloadTimer = setTimeout(() => {
    loadApprovedMarkers().catch(err => console.error(err));
    loadLayerMarkers().catch(err => console.error(err));
    loadFacilities().catch(err => console.error(err));
    loadCivilEvents().catch(err => console.error(err));
    loadTrees().catch(err => console.error(err));
  }, 250);
}

loadApprovedMarkers().catch(err => console.error(err));

function initLegendFilters(){
  const allBtn = document.querySelector('.legend-filter[data-cat="all"]');
  const itemBtns = document.querySelectorAll('.legend-item-btn[data-cat]');
  const setActive = (cat) => {
    activeCategory = cat;
    if (allBtn) allBtn.classList.toggle('active', cat === 'all');
    itemBtns.forEach(b => b.classList.toggle('active', (b.getAttribute('data-cat') || '') === cat));
    loadApprovedMarkers().catch(err => console.error(err));
  };
  if (allBtn) allBtn.addEventListener('click', () => setActive('all'));
  itemBtns.forEach(btn => {
    btn.addEventListener('click', () => setActive(btn.getAttribute('data-cat') || 'all'));
  });
}

initLegendFilters();

map.on('popupopen', (e) => {
  const el = e.popup.getElement();
  if (!el) return;

  // Like gomb (bejelentésekhez)
  const likeBtn = el.querySelector('.like-btn');
  if (likeBtn) {
    likeBtn.addEventListener('click', async () => {
      if (!IS_LOGGED_IN) {
        alert('Bejelentkezés szükséges.');
        return;
      }
      const id = Number(likeBtn.getAttribute('data-id'));
      try{
        const j = await fetchJson(API_REPORT_LIKE, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ id })
        });
        if (!j || !j.ok) return;
        const countEl = el.querySelector('.like-count');
        const labelEl = el.querySelector('.like-label');
        if (countEl) countEl.textContent = String(j.count ?? 0);
        if (labelEl) labelEl.textContent = j.liked ? 'Kedveled' : 'Tetszik';
        likeBtn.classList.toggle('liked', !!j.liked);
      }catch(err){
        console.error(err);
      }
    }, { once: true });
  }

  // Fa örökbefogadás + öntözés
  const treeActions = el.querySelector('.tree-actions');
  if (treeActions && IS_LOGGED_IN) {
    const treeId = Number(treeActions.getAttribute('data-tree-id') || '0');
    const adoptBtn = treeActions.querySelector('.btn-tree-adopt');
    const waterForm = treeActions.querySelector('.tree-water-form');

    if (adoptBtn && treeId > 0) {
      adoptBtn.addEventListener('click', async () => {
        const currentlyAdopted = adoptBtn.getAttribute('data-adopted') === '1';
        const action = currentlyAdopted ? 'cancel' : 'adopt';
        try{
          const j = await fetchJson(API_TREE_ADOPT, {
            method:'POST',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ tree_id: treeId, action })
          });
          if (!j || !j.ok) return;
          adoptBtn.setAttribute('data-adopted', j.adopted ? '1' : '0');
          const adoptedLabel = j.adopted
            ? (window.LANG && window.LANG['tree.action_cancel'] ? window.LANG['tree.action_cancel'] : 'Örökbefogadás lemondása')
            : (window.LANG && window.LANG['tree.action_adopt'] ? window.LANG['tree.action_adopt'] : 'Örökbefogadom');
          adoptBtn.textContent = adoptedLabel;

          const adoptedLine = el.querySelector('.tree-adopted-line');
          if (adoptedLine) {
            if (j.adopted) {
              adoptedLine.textContent = window.LANG && window.LANG['tree.adopted_by_self']
                ? window.LANG['tree.adopted_by_self']
                : 'Örökbefogadtad ezt a fát.';
            } else {
              adoptedLine.textContent = window.LANG && window.LANG['tree.not_adopted']
                ? window.LANG['tree.not_adopted']
                : 'Nem örökbefogadott';
            }
          }
        }catch(err){
          console.error(err);
        }
      });
    }

    if (waterForm && treeId > 0) {
      waterForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(waterForm);
        fd.append('tree_id', String(treeId));
        try{
          const res = await fetch(API_TREE_WATER, {
            method:'POST',
            body: fd
          });
          const j = await res.json().catch(() => null);
          if (!res.ok || !j || !j.ok) return;
          const lastLine = el.querySelector('.tree-last-watered-line');
          if (lastLine && j.last_watered) {
            const tpl = window.LANG && window.LANG['tree.last_watered']
              ? window.LANG['tree.last_watered']
              : 'Öntözve: {date}';
            lastLine.textContent = tpl.replace('{date}', j.last_watered);
          }
          waterForm.reset();
        }catch(err){
          console.error(err);
        }
      });
    }
  }
});

function clearLayerMarkers(){
  layerMarkers.forEach(m => map.removeLayer(m));
  layerMarkers = [];
}

function clearFacilityMarkers(){
  facilityMarkers.forEach(m => map.removeLayer(m));
  facilityMarkers = [];
}

function clearCivilEventMarkers(){
  civilEventMarkers.forEach(m => map.removeLayer(m));
  civilEventMarkers = [];
}

function clearTreeMarkers(){
  treeMarkers.forEach(m => map.removeLayer(m));
  treeMarkers = [];
}

function treeHealthLabel(s){ return (window.LANG && window.LANG['tree.health_' + s]) ? window.LANG['tree.health_' + s] : (s || '–'); }
function treeRiskLabel(s){ return (window.LANG && window.LANG['tree.risk_' + s]) ? window.LANG['tree.risk_' + s] : (s || '–'); }

async function loadTrees(){
  clearTreeMarkers();
  try{
    const params = new URLSearchParams();
    const b = getBoundsParams();
    params.set('minLat', b.minLat);
    params.set('maxLat', b.maxLat);
    params.set('minLng', b.minLng);
    params.set('maxLng', b.maxLng);
    params.set('limit', '500');
    params.set('filter', activeTreeFilter);
    const j = await fetchJson(`${API_TREES}?${params.toString()}`);
    const rows = j.data || [];
    for (const t of rows){
      const lastWateredText = t.last_watered
        ? ((window.LANG && window.LANG['tree.last_watered']) ? window.LANG['tree.last_watered'].replace('{date}', t.last_watered) : 'Öntözve: ' + t.last_watered)
        : ((window.LANG && window.LANG['tree.not_watered']) ? window.LANG['tree.not_watered'] : 'Még nem öntözték');
      const adoptedText = t.adopted_by_user_id
        ? ((window.LANG && window.LANG['tree.adopted_by']) ? window.LANG['tree.adopted_by'].replace('{name}', esc(t.adopter_name || '')) : 'Örökbefogadta: ' + esc(t.adopter_name || ''))
        : ((window.LANG && window.LANG['tree.not_adopted']) ? window.LANG['tree.not_adopted'] : 'Nem örökbefogadott');
      const canInteract = IS_LOGGED_IN;
      const adoptedByMe = t.adopted_by_user_id && Number(t.adopted_by_user_id) === Number(document.body?.dataset?.userId || 0);
      const adoptLabel = adoptedByMe
        ? ((window.LANG && window.LANG['tree.action_cancel']) ? window.LANG['tree.action_cancel'] : 'Örökbefogadás lemondása')
        : ((window.LANG && window.LANG['tree.action_adopt']) ? window.LANG['tree.action_adopt'] : 'Örökbefogadom');
      const actionsHtml = canInteract ? `
        <div class="tree-actions" data-tree-id="${t.id}">
          <div class="tree-meta-lines">
            <small class="tree-adopted-line">${adoptedText}</small><br>
            <small class="tree-last-watered-line">${lastWateredText}</small>
          </div>
          <div class="tree-actions-buttons" style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
            <button type="button" class="btn-soft btn-tree-adopt" data-adopted="${adoptedByMe ? '1' : '0'}">${esc(adoptLabel)}</button>
            <form class="tree-water-form" enctype="multipart/form-data" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
              <input type="number" name="water_amount" min="0" step="0.5" placeholder="${window.LANG && window.LANG['tree.water_amount_placeholder'] ? window.LANG['tree.water_amount_placeholder'] : 'Liter'}" style="max-width:90px">
              <input type="file" name="photo" accept="image/*" style="max-width:150px">
              <button type="submit" class="btn-soft">${esc(window.LANG && window.LANG['tree.action_water'] ? window.LANG['tree.action_water'] : 'Öntözés naplózása')}</button>
            </form>
          </div>
        </div>
      ` : `
        <div class="tree-meta-lines">
          <small>${adoptedText}</small><br>
          <small>${lastWateredText}</small>
          <p class="tree-login-hint" style="margin-top:8px;font-size:12px">
            ${(window.LANG && window.LANG['tree.login_to_adopt']) ? window.LANG['tree.login_to_adopt'] : 'Örökbe fogadáshoz és öntözéshez be kell jelentkezned.'}
            <a href="${esc(BASE + '/user/login.php?redirect=' + encodeURIComponent(window.location.pathname || '/'))}">${(window.LANG && window.LANG['nav.login']) ? window.LANG['nav.login'] : 'Belépés'}</a>
          </p>
        </div>
      `;

      const mk = L.marker([t.lat, t.lng], { icon: treeIcon(t) })
        .addTo(map)
        .bindPopup(
          `<b>🌳 ${esc(t.species || (window.LANG && window.LANG['tree.unknown_species']) ? window.LANG['tree.unknown_species'] : 'Fa')}</b><br>` +
          (t.address ? `<small>${esc(t.address)}</small><br>` : '') +
          `<small><b>${(window.LANG && window.LANG['tree.health']) ? window.LANG['tree.health'] : 'Állapot'}:</b> ${treeHealthLabel(t.health_status)}</small><br>` +
          `<small><b>${(window.LANG && window.LANG['tree.risk']) ? window.LANG['tree.risk'] : 'Kockázat'}:</b> ${treeRiskLabel(t.risk_level)}</small><br>` +
          actionsHtml
        );
      treeMarkers.push(mk);
    }
  }catch(e){
    console.warn('trees load failed', e);
  }
}

async function loadLayerMarkers(){
  clearLayerMarkers();
  try{
    const params = new URLSearchParams();
    const b = getBoundsParams();
    params.set('minLat', b.minLat);
    params.set('maxLat', b.maxLat);
    params.set('minLng', b.minLng);
    params.set('maxLng', b.maxLng);
    params.set('limit', '2000');
    const j = await fetchJson(`${API_LAYERS}?${params.toString()}`);
    const data = j.data || {};
    const layers = data.layers || [];
    const points = data.points || [];

    const layerById = new Map(layers.map(l => [Number(l.id), l]));
    for (const p of points){
      const layer = layerById.get(Number(p.layer_id));
      if (!layer || (layer.layer_type === 'trees')) continue;
      const mk = L.marker([p.lat, p.lng], { icon: layerIcon(layer.category) })
        .addTo(map)
        .bindPopup(
          `<b>${esc(layer.name)}</b><br>` +
          `${p.name ? `<b>${esc(p.name)}</b><br>` : ''}` +
          `${p.address ? `<small>${esc(p.address)}</small><br>` : ''}`
        );
      layerMarkers.push(mk);
    }

    const treesLayerActive = layers.some(l => (l.layer_key === 'trees' || l.layer_type === 'trees') && Number(l.is_active) === 1);
    if (treesLayerActive) {
      await loadTrees();
    } else {
      clearTreeMarkers();
    }
  }catch(e){
    console.warn('layer load failed', e);
  }
}

async function loadFacilities(){
  clearFacilityMarkers();
  try{
    const params = new URLSearchParams();
    const b = getBoundsParams();
    params.set('minLat', b.minLat);
    params.set('maxLat', b.maxLat);
    params.set('minLng', b.minLng);
    params.set('maxLng', b.maxLng);
    params.set('limit', '2000');
    const j = await fetchJson(`${API_FACILITIES}?${params.toString()}`);
    const rows = j.data || [];
    for (const f of rows){
      const mk = L.marker([f.lat, f.lng], { icon: facilityIcon() })
        .addTo(map)
        .bindPopup(
          `<b>${esc(f.name || 'Közület')}</b><br>` +
          `${f.service_type ? `<small>${esc(f.service_type)}</small><br>` : ''}` +
          `${f.address ? `<small>${esc(f.address)}</small><br>` : ''}`
        );
      facilityMarkers.push(mk);
    }
  }catch(e){
    console.warn('facilities load failed', e);
  }
}

async function loadCivilEvents(){
  clearCivilEventMarkers();
  try{
    const params = new URLSearchParams();
    const b = getBoundsParams();
    params.set('minLat', b.minLat);
    params.set('maxLat', b.maxLat);
    params.set('minLng', b.minLng);
    params.set('maxLng', b.maxLng);
    params.set('limit', '2000');
    const j = await fetchJson(`${API_CIVIL_EVENTS}?${params.toString()}`);
    const rows = j.data || [];
    for (const ev of rows){
      const type = ev.event_type || 'civil';
      const icon = type === 'green_action' ? greenActionIcon() : civilEventIcon();
      const mk = L.marker([ev.lat, ev.lng], { icon })
        .addTo(map)
        .bindPopup(
          `<b>${esc(ev.title || (type === 'green_action' ? 'Zöld akció' : 'Civil esemény'))}</b><br>` +
          `${ev.address ? `<small>${esc(ev.address)}</small><br>` : ''}` +
          `${ev.description ? `<small>${esc(ev.description)}</small><br>` : ''}`
        );
      civilEventMarkers.push(mk);
    }
  }catch(e){
    console.warn('civil events load failed', e);
  }
}

map.on('moveend zoomend', () => {
  scheduleReload();
});

loadLayerMarkers().catch(err => console.error(err));
loadFacilities().catch(err => console.error(err));
loadCivilEvents().catch(err => console.error(err));

// ====== Tree layer filter (legend) ======
(function initTreeLayerFilter(){
  const btns = document.querySelectorAll('.legend-tree-filter[data-tree-filter]');
  btns.forEach(btn => {
    btn.addEventListener('click', () => {
      const filter = btn.getAttribute('data-tree-filter') || 'all';
      activeTreeFilter = filter;
      btns.forEach(b => b.classList.toggle('active', (b.getAttribute('data-tree-filter') || '') === filter));
      loadTrees().catch(err => console.error(err));
    });
  });
})();

// ====== Új fa felvitele (térképre kattintás + űrlap) ======
(function initAddTree(){
  const btn = document.getElementById('btnAddTree');
  if (!btn || !IS_LOGGED_IN) return;

  function exitAddTreeMode(){
    addTreeMode = false;
    btn.classList.remove('active');
    map.getContainer().style.cursor = '';
    if (addTreeMapClick) {
      map.off('click', addTreeMapClick);
      addTreeMapClick = null;
    }
    if (addTreeMarker) {
      map.removeLayer(addTreeMarker);
      addTreeMarker = null;
    }
  }

  btn.addEventListener('click', () => {
    addTreeMode = !addTreeMode;
    btn.classList.toggle('active', addTreeMode);
    map.getContainer().style.cursor = addTreeMode ? 'crosshair' : '';
    if (addTreeMode) {
      addTreeMapClick = (e) => {
        const { lat, lng } = e.latlng;
        if (addTreeMarker) map.removeLayer(addTreeMarker);
        addTreeMarker = L.marker([lat, lng], { icon: treeIcon(null) }).addTo(map);
        const addLabel = (window.LANG && window.LANG['legend.tree_add']) ? window.LANG['legend.tree_add'] : 'Új fa felvitele';
        const speciesPh = (window.LANG && window.LANG['tree.species_placeholder']) ? window.LANG['tree.species_placeholder'] : 'Faj (opcionális)';
        const notePh = (window.LANG && window.LANG['tree.note_placeholder']) ? window.LANG['tree.note_placeholder'] : 'Megjegyzés (opcionális)';
        const submitLabel = (window.LANG && window.LANG['tree.submit_add']) ? window.LANG['tree.submit_add'] : 'Fa mentése';
        const popupContent = `
          <form class="tree-create-form" data-lat="${lat}" data-lng="${lng}">
            <input type="text" name="species" placeholder="${esc(speciesPh)}" maxlength="120">
            <textarea name="note" placeholder="${esc(notePh)}" rows="2" maxlength="500"></textarea>
            <input type="file" name="photo" accept="image/*">
            <button type="submit" class="btn-soft">${esc(submitLabel)}</button>
          </form>
        `;
        addTreeMarker.bindPopup(popupContent, { maxWidth: 320 }).openPopup();
        setTimeout(() => {
          const popupEl = addTreeMarker && addTreeMarker.getPopup().getElement();
          const formEl = popupEl?.querySelector('.tree-create-form');
          if (formEl) {
            formEl.addEventListener('submit', async (ev) => {
              ev.preventDefault();
              const fd = new FormData(formEl);
              fd.append('lat', String(lat));
              fd.append('lng', String(lng));
              try {
                const res = await fetch(API_TREE_CREATE, { method: 'POST', body: fd });
                const j = await res.json().catch(() => null);
                if (!res.ok || !j || !j.ok) {
                  alert(j && j.error ? j.error : 'Hiba történt.');
                  return;
                }
                exitAddTreeMode();
                await loadTrees();
              } catch (err) {
                console.error(err);
                alert('Hiba történt.');
              }
            });
          }
        }, 50);
      };
      map.on('click', addTreeMapClick);
    } else {
      exitAddTreeMode();
    }
  });
})();

// ====== Address search ======
(function initSearch(){
  const form = document.getElementById('mapSearchForm');
  const input = document.getElementById('mapSearchInput');
  const results = document.getElementById('mapSearchResults');
  if (!form || !input) return;

  const hideResults = () => {
    if (!results) return;
    results.style.display = 'none';
    results.innerHTML = '';
  };

  const showResults = (items) => {
    if (!results) return;
    if (!items.length) {
      hideResults();
      return;
    }
    results.innerHTML = items.map((it, idx) => {
      const label = it.display_name || it.name || 'Találat';
      return `<button type="button" class="search-result" data-idx="${idx}">${esc(label)}</button>`;
    }).join('');
    results.style.display = 'block';
  };

  if (results) {
    results.addEventListener('click', (e) => {
      const btn = e.target.closest('.search-result');
      if (!btn) return;
      const idx = parseInt(btn.getAttribute('data-idx'), 10);
      const list = results._items || [];
      const hit = list[idx];
      if (!hit) return;
      const lat = parseFloat(hit.lat);
      const lon = parseFloat(hit.lon);
      if (isFinite(lat) && isFinite(lon)) placeSearchMarker(lat, lon);
      hideResults();
    });
  }

  document.addEventListener('click', (e) => {
    if (!results) return;
    if (!form.contains(e.target)) hideResults();
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = input.value.trim();
    if (!q) return;

    try{
      const hits = await geocodeAddress(q, 5);
      if (!hits.length) {
        alert(t('search.no_results'));
        return;
      }
      if (hits.length === 1) {
        const lat = parseFloat(hits[0].lat);
        const lon = parseFloat(hits[0].lon);
        if (isFinite(lat) && isFinite(lon)) placeSearchMarker(lat, lon);
        hideResults();
        return;
      }
      if (results) {
        results._items = hits;
        showResults(hits);
      }
    }catch(err){
      console.error(err);
      alert(t('search.error'));
    }
  });
})();

// ====== Report modal ======
let tempMarker = null;

function closeModal(){
  const m = document.getElementById('reportModal');
  if (m) m.remove();

  if (tempMarker){
    map.removeLayer(tempMarker);
    tempMarker = null;
  }
}

function openModal(latlng){
  closeModal();

  tempMarker = L.marker(latlng).addTo(map);

  const modal = document.createElement('div');
  modal.id = 'reportModal';
  modal.className = 'modal-overlay';
  modal.innerHTML = `
    <div class="modal">
      <button class="modal-x" type="button" aria-label="${esc(t('modal.close'))}">×</button>

      <div class="modal-scroll">
        <h3>${esc(t('modal.category'))}</h3>
        <select id="mCategory">
          ${buildCategoryOptions()}
        </select>
        <p id="mCategorySuggestion" class="muted small" style="display:none; margin-top:4px"></p>

        <label>${esc(t('modal.category'))} – rövid cím</label>
        <input id="mTitle" maxlength="120" placeholder="${esc(t('modal.title_placeholder'))}">

        <label>Leírás</label>
        <textarea id="mDesc" rows="4" maxlength="5000" placeholder="${esc(t('modal.desc_placeholder'))}"></textarea>

        <label>${esc(t('modal.image_optional'))}</label>
        <input id="mImage" type="file" accept="image/*" class="modal-file">

        <div id="mEventFields" style="display:none">
          <h3>Esemény időpont</h3>
          <label>Kezdete</label>
          <input id="mEventStart" type="date">
          <label>Vége</label>
          <input id="mEventEnd" type="date">
        </div>

        <h3>Cím (opcionális)</h3>
        <div class="addr-grid">
          <div>
            <label>${esc(t('modal.zip'))}</label>
            <input id="mZip" maxlength="16" placeholder="5900">
          </div>
          <div>
            <label>${esc(t('modal.city'))}</label>
            <input id="mCity" maxlength="80" placeholder="${esc(t('modal.city_placeholder') || 'Orosháza')}">
          </div>
          <div>
            <label>${esc(t('modal.street'))}</label>
            <input id="mStreet" maxlength="120" placeholder="${esc(t('modal.street_placeholder') || 'Utca')}">
          </div>
          <div>
            <label>${esc(t('modal.house'))}</label>
            <input id="mHouse" maxlength="20" placeholder="12">
          </div>
        </div>
        <label>Cím megjegyzés</label>
        <input id="mAddrNote" maxlength="160" placeholder="${esc(t('modal.addr_note'))}">
        <p class="muted" style="margin-top:8px;font-size:12px">${esc(t('modal.geocode_hint'))} <strong>${esc(t('modal.geocode_btn'))}</strong></p>
        <button type="button" id="mGeocodeBtn" class="btn-soft" style="margin-top:6px">${esc(t('modal.geocode_btn'))}</button>

        <div class="checks">
          <label class="check">
            <input id="mAnon" type="checkbox" checked>
            <span>${esc(t('modal.anon'))}</span>
          </label>

          <label class="check">
            <input id="mNotify" type="checkbox">
            <span>${esc(t('modal.notify'))}</span>
          </label>
        </div>

        <div id="mLoggedBox" class="box" style="display:none">
          <div class="gdpr-note">${esc(t('modal.logged_note'))}</div>
        </div>

        <div id="mContact" class="box" style="display:none">
          <label>E-mail</label>
          <input id="mEmail" maxlength="190" placeholder="${esc(t('modal.email_placeholder'))}" inputmode="email">

          <label>Név</label>
          <input id="mName" maxlength="80" placeholder="${esc(t('modal.name_placeholder'))}">

          <label class="check" style="margin-top:10px">
            <input id="mCreateAccount" type="checkbox">
            <span>${esc(t('modal.create_account'))}</span>
          </label>

          <div id="mPassWrap" style="display:none">
            <label>${esc(t('modal.password'))}</label>
            <input id="mPass" type="password" minlength="8" maxlength="80" placeholder="********">
          </div>

          <div class="gdpr">
            <label class="check">
              <input id="mConsentData" type="checkbox">
              <span>${esc(t('modal.gdpr_data'))}</span>
            </label>
            <label class="check">
              <input id="mConsentShare" type="checkbox" checked>
              <span>${esc(t('modal.gdpr_share'))}</span>
            </label>
            <label class="check">
              <input id="mConsentMarketing" type="checkbox">
              <span>${esc(t('modal.gdpr_marketing'))}</span>
            </label>
          </div>
        </div>

        <div class="modal-note">
          A bejelentés ellenőrzés után jelenik meg.
        </div>
        <div id="mNearby200" class="modal-note" style="display:none"></div>

        <div class="modal-actions">
          <button id="mSubmit" class="btn-primary" type="button">${esc(t('modal.submit'))}</button>
          <button id="mCancel" class="btn-ghost" type="button">${esc(t('modal.cancel') || 'Mégse')}</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  modal.querySelector('.modal-x').addEventListener('click', closeModal);
  modal.querySelector('#mCancel').addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  const elAnon = modal.querySelector('#mAnon');
  const elNotify = modal.querySelector('#mNotify');
  const elContact = modal.querySelector('#mContact');
  const elLoggedBox = modal.querySelector('#mLoggedBox');
  const elCreate = modal.querySelector('#mCreateAccount');
  const elPassWrap = modal.querySelector('#mPassWrap');
  const elCategory = modal.querySelector('#mCategory');
  const elEventFields = modal.querySelector('#mEventFields');

  modal.querySelector('#mGeocodeBtn')?.addEventListener('click', async () => {
    const zip = modal.querySelector('#mZip')?.value.trim() || '';
    const city = modal.querySelector('#mCity')?.value.trim() || '';
    const street = modal.querySelector('#mStreet')?.value.trim() || '';
    const house = modal.querySelector('#mHouse')?.value.trim() || '';
    const addr = [street, house, city, zip].filter(Boolean).join(', ');
    if (!addr) { alert('Add meg legalább a várost vagy az utcát!'); return; }
    try {
      const hits = await geocodeAddress(addr, 1);
      if (!hits || !hits.length) { alert('Nem található a cím. Próbáld pontosítani (pl. Orosháza, Balassa Pál utca 25).'); return; }
      const h = hits[0];
      const lat = parseFloat(h.lat);
      const lon = parseFloat(h.lon);
      if (isFinite(lat) && isFinite(lon)) {
        if (tempMarker) map.removeLayer(tempMarker);
        tempMarker = L.marker([lat, lon]).addTo(map);
        map.setView([lat, lon], 17, { animate: true });
        latlng.lat = lat;
        latlng.lng = lon;
        alert('Hely beállítva. Most küldd el a bejelentést.');
      } else { alert('Nem sikerült a koordináta.'); }
    } catch (e) {
      console.error(e);
      alert('Hiba a cím keresésnél. Próbáld újra.');
    }
  });

  const syncPass = () => {
    elPassWrap.style.display = elCreate.checked ? '' : 'none';
  };

  const syncContact = () => {
    if (IS_LOGGED_IN) {
      // Logged in: hide guest-only fields (email/name/GDPR/register-on-submit)
      elContact.style.display = 'none';
      if (elLoggedBox) elLoggedBox.style.display = elNotify.checked ? '' : 'none';
      if (elCreate) elCreate.checked = false;
      if (elPassWrap) elPassWrap.style.display = 'none';
      return;
    }

    // Guest: show contact if notify/register or not anonymous
    const show = elNotify.checked || elCreate.checked || !elAnon.checked;
    elContact.style.display = show ? '' : 'none';
    if (elLoggedBox) elLoggedBox.style.display = 'none';

    if (!show) {
      modal.querySelector('#mEmail').value = '';
      modal.querySelector('#mName').value = '';
      modal.querySelector('#mPass').value = '';
      modal.querySelector('#mConsentData').checked = false;
      modal.querySelector('#mConsentMarketing').checked = false;
      modal.querySelector('#mConsentShare').checked = true;
      elCreate.checked = false;
      elPassWrap.style.display = 'none';
    }
  };

  const syncCategory = () => {
    const isCivil = elCategory && elCategory.value === 'civil_event';
    if (elEventFields) elEventFields.style.display = isCivil ? '' : 'none';
  };

  elNotify.addEventListener('change', syncContact);
  elAnon.addEventListener('change', syncContact);
  elCreate.addEventListener('change', () => { syncContact(); syncPass(); });
  if (elCategory) elCategory.addEventListener('change', syncCategory);

  syncCategory();

  // Kategória javaslat a leírás alapján (Phase 4 – szabályalapú javaslat)
  let suggestTmo = null;
  const elDesc = modal.querySelector('#mDesc');
  const elSuggestion = modal.querySelector('#mCategorySuggestion');
  if (elDesc && elSuggestion) {
    elDesc.addEventListener('input', () => {
      clearTimeout(suggestTmo);
      elSuggestion.style.display = 'none';
      elSuggestion.innerHTML = '';
      const desc = elDesc.value.trim();
      if (desc.length < 10) return;
      suggestTmo = setTimeout(async () => {
        try {
          const res = await fetchJson(`${API_SUGGEST_CATEGORY}?description=${encodeURIComponent(desc)}`);
          if (res.ok && res.suggested_category && elCategory.querySelector(`option[value="${res.suggested_category}"]`)) {
            elSuggestion.innerHTML = `Javasolt kategória: <strong>${esc(res.label || res.suggested_category)}</strong> <button type="button" class="btn-soft btn-sm" id="mApplySuggestion">Kiválasztom</button>`;
            elSuggestion.style.display = 'block';
            modal.querySelector('#mApplySuggestion')?.addEventListener('click', () => {
              elCategory.value = res.suggested_category;
              syncCategory();
              elSuggestion.style.display = 'none';
            });
          }
        } catch (e) { console.warn('suggest_category failed', e); }
      }, 400);
    });
  }

  modal.querySelector('#mSubmit').addEventListener('click', async () => {
    const category = modal.querySelector('#mCategory').value;
    const title = modal.querySelector('#mTitle').value.trim();
    const description = modal.querySelector('#mDesc').value.trim();
    const address_zip = modal.querySelector('#mZip')?.value.trim() || '';
    const address_city = modal.querySelector('#mCity')?.value.trim() || '';
    const address_street = modal.querySelector('#mStreet')?.value.trim() || '';
    const address_house = modal.querySelector('#mHouse')?.value.trim() || '';
    const address_note = modal.querySelector('#mAddrNote')?.value.trim() || '';
    const event_start = modal.querySelector('#mEventStart')?.value || '';
    const event_end = modal.querySelector('#mEventEnd')?.value || '';

    const reporter_is_anonymous = modal.querySelector('#mAnon').checked ? 1 : 0;
    const notify_enabled = modal.querySelector('#mNotify').checked ? 1 : 0;
    let create_account = modal.querySelector('#mCreateAccount')?.checked ? 1 : 0;
    if (IS_LOGGED_IN) create_account = 0;

        let password       = modal.querySelector('#mPass')?.value || '';

    let reporter_email = modal.querySelector('#mEmail')?.value.trim() || '';
    let reporter_name  = modal.querySelector('#mName')?.value.trim() || '';

    if (IS_LOGGED_IN) {
      // server will use session user data if needed
      reporter_email = '';
      reporter_name = '';
      password = '';
    }

    let consent_data = modal.querySelector('#mConsentData')?.checked ? 1 : 0;
    let consent_share_thirdparty = modal.querySelector('#mConsentShare')?.checked ? 1 : 0;
    let consent_marketing = modal.querySelector('#mConsentMarketing')?.checked ? 1 : 0;
    if (IS_LOGGED_IN) {
      consent_data = 0;
      consent_share_thirdparty = 0;
      consent_marketing = 0;
    }
    
    if (!description && category !== 'tree_upload'){
      alert('Kérlek írj leírást!');
      return;
    }

    if (category === 'tree_upload') {
      if (!IS_LOGGED_IN) {
        alert(t('modal.tree_upload_login') || 'Fa feltöltéshez be kell jelentkezned.');
        return;
      }
    }

    // nem anonim -> név kell
    if (!IS_LOGGED_IN && !reporter_is_anonymous && !reporter_name) {
      alert('Ha nem anonim a beküldés, kérlek add meg a neved!');
      return;
    }

    const needsPersonal = (notify_enabled || create_account || !reporter_is_anonymous);

    if (!IS_LOGGED_IN && needsPersonal && !consent_data) {
      alert('Kérjük fogadd el az adatkezelési tájékoztatót (hozzájárulás az adatkezeléshez).');
      return;
    }

    // értesítés/reg: email kell (vendégnél)
    if (!IS_LOGGED_IN && (notify_enabled || create_account) && !reporter_email) {
      alert('Az értesítéshez / regisztrációhoz e-mail cím szükséges.');
      return;
    }

    if (create_account && password.length < 8) {
      alert('A regisztrációhoz legalább 8 karakteres jelszó kell.');
      return;
    }

    // Hasonló bejelentések 200 m-en belül – tájékoztató (nem blokkol)
    const elNearby200 = modal.querySelector('#mNearby200');
    if (elNearby200) {
      elNearby200.style.display = 'none';
      elNearby200.textContent = '';
    }
    if (category !== 'civil_event' && category !== 'tree_upload') {
      try {
        const near200 = await fetchJson(
          `${API_NEARBY}?lat=${encodeURIComponent(latlng.lat)}&lng=${encodeURIComponent(latlng.lng)}&category=${encodeURIComponent(category)}&radius=200`
        );
        if (near200.ok && near200.data && near200.data.length > 0 && elNearby200) {
          elNearby200.textContent = `ℹ️ ${near200.data.length} hasonló bejelentés 200 m-en belül (ugyanebben a kategóriában).`;
          elNearby200.style.display = 'block';
        }
      } catch (e) { console.warn('nearby 200m check failed', e); }
    }

    // Duplikáció ellenőrzés (50m) – civil esemény és fa feltöltésnél nem fut
    let force = false;
    if (category !== 'civil_event' && category !== 'tree_upload') {
      try{
        const near = await fetchJson(
          `${API_NEARBY}?lat=${encodeURIComponent(latlng.lat)}&lng=${encodeURIComponent(latlng.lng)}&category=${encodeURIComponent(category)}&radius=50`
        );

        if (near.ok && near.data && near.data.length){
          const d = near.data[0];
          const msg =
            `⚠️ Van már hasonló (${catLabel(category)}) jelölés kb. ${Math.round(d.distance_m)} méteren belül ` +
            `(státusz: ${statusLabel(d.status)}).\n\nBiztos beküldöd így is?`;
          if(!confirm(msg)) return;
          force = true;
        }
      }catch(err){
        console.warn('nearby check failed:', err);
      }
    }

    const btn = modal.querySelector('#mSubmit');
    btn.disabled = true;
    btn.textContent = 'Beküldés...';

    try{
      if (category === 'tree_upload') {
        const formData = new FormData();
        formData.append('lat', String(latlng.lat));
        formData.append('lng', String(latlng.lng));
        formData.append('species', title || '');
        formData.append('note', description || '');
        const fileInput = modal.querySelector('#mImage');
        if (fileInput && fileInput.files && fileInput.files[0]) {
          formData.append('photo', fileInput.files[0]);
        }
        const res = await fetch(API_TREE_CREATE, { method: 'POST', body: formData, credentials: 'same-origin' });
        const j = await res.json();
        if (!j || !j.ok) {
          throw new Error(j && j.error ? j.error : 'Fa feltöltés sikertelen.');
        }
        alert(t('tree.submit_success') || 'Fa rögzítve. Megjelenik a térképen.');
        closeModal();
        loadTrees().catch(e => console.warn('loadTrees after tree_create', e));
        btn.disabled = false;
        btn.textContent = t('modal.submit') || 'Beküldés';
        return;
      }
      if (category === 'civil_event') {
        if (!event_start || !event_end) {
          alert('Az esemény kezdetét és végét add meg.');
          btn.disabled = false;
          btn.textContent = 'Beküldés';
          return;
        }
        await fetchJson(API_CIVIL_EVENT_CREATE, {
          method: 'POST',
          headers: { 'Content-Type':'application/json' },
          body: JSON.stringify({
            title: title || 'Civil esemény',
            description,
            start_date: event_start,
            end_date: event_end,
            lat: latlng.lat,
            lng: latlng.lng,
            address: [address_street, address_house, address_city, address_zip].filter(Boolean).join(' ')
          })
        });
      } else {
        const j = await fetchJson(API_CREATE, {
          method: 'POST',
          headers: { 'Content-Type':'application/json' },
          body: JSON.stringify({
            category,
            title,
            description,
            lat: latlng.lat,
            lng: latlng.lng,
            force_duplicate: force ? 1 : 0,

            reporter_is_anonymous,
            notify_enabled,
            reporter_email: reporter_email || null,
            reporter_name: reporter_name || null,
            create_account,
            password: create_account ? password : null,

            consent_data,
            consent_share_thirdparty,
            consent_marketing,

            address_zip: address_zip || null,
            address_city: address_city || null,
            address_street: address_street || null,
            address_house: address_house || null,
            address_note: address_note || null,
          })
        });
        if (IS_LOGGED_IN && j && j.id) {
          const fileInput = modal.querySelector('#mImage');
          if (fileInput && fileInput.files && fileInput.files[0]) {
            try {
              const fd = new FormData();
              fd.append('report_id', String(j.id));
              fd.append('file', fileInput.files[0]);
              await fetchJson(BASE + '/api/report_upload.php', { method: 'POST', body: fd });
            } catch (e) { console.warn('Image upload failed', e); }
          }
        }
      }

      alert(t('modal.thanks') || 'Köszönjük! A bejelentés ellenőrzés után fog megjelenni a térképen.');
      closeModal();

    }catch(err){
      console.error(err);
      alert('Hiba a beküldésnél: ' + err.message);
      btn.disabled = false;
      btn.textContent = 'Beküldés';
    }
  });
}

// Kattintás a térképen → bejelentés
map.on('click', (e) => openModal(e.latlng));

document.getElementById('btnNewReport')?.addEventListener('click', () => {
  const c = map.getCenter();
  openModal({ lat: c.lat, lng: c.lng });
});

// ====== FIRST POPUP (Belépés / Reg / Anonim) ======
(function introGate(){
  const KEY = 'terkep_intro_done_v1';
  // Ha már egyszer döntött, ne zavarjuk újra
  if (localStorage.getItem(KEY) === '1') return;

  // Ha be van jelentkezve, akkor semmi értelme újra mutatni.
  // (Belépés után visszadobhat a /terkep/ oldalra, és nem akarjuk újra felugrasztani.)
  if (window.TERKEP_LOGGED_IN === true) {
    localStorage.setItem(KEY, '1');
    return;
  }

  document.body.classList.add('intro-open');

  const ov = document.createElement('div');
  ov.className = 'intro-overlay';
  ov.innerHTML = `
    <div class="intro-card">
      <h2>Problématérkép</h2>
      <p class="intro-muted">
        Bejelentés küldéséhez választhatsz: <b>belépés</b>, <b>regisztráció</b> vagy <b>anonim folytatás</b>.
      </p>

      <div class="intro-actions">
        <a class="btn-primary" id="introLogin" href="${BASE}/user/login.php">Belépés</a>
        <a class="btn-ghost" id="introReg" href="${BASE}/user/register.php">Regisztráció</a>
        <button class="btn-soft" type="button" id="introAnon">Anonim folytatás</button>
      </div>

      <div class="intro-foot">
        <small>
          Tipp: ha belépsz/regisztrálsz, később könnyebb visszakeresni a saját ügyeidet.
        </small>
      </div>
    </div>
  `;
  document.body.appendChild(ov);

  // Ha belépés / regisztráció gombra megy, akkor is tekintsük „eldöntöttnek”,
  // hogy visszatéréskor ne ugorjon fel újra.
  const markDone = () => { try { localStorage.setItem(KEY, '1'); } catch(e){} };
  ov.querySelector('#introLogin')?.addEventListener('click', markDone);
  ov.querySelector('#introReg')?.addEventListener('click', markDone);

  ov.querySelector('#introAnon').addEventListener('click', () => {
    localStorage.setItem(KEY, '1');
    document.body.classList.remove('intro-open');
    ov.remove();
  });
})();
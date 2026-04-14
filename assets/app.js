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
const API_TREE_ANALYZE_PHOTO = `${BASE}/api/tree_analyze_photo.php`;
const API_TREE_HEALTH_ANALYZE = `${BASE}/api/tree_health_analyze.php`;
const API_CIVIL_EVENT_CREATE = `${BASE}/api/civil_event_create.php`;
const API_REPORT_LIKE = `${BASE}/api/report_like.php`;
const API_IDEAS = `${BASE}/api/ideas_list.php`;
const API_IDEA_CREATE = `${BASE}/api/idea_create.php`;
const API_IDEA_VOTE = `${BASE}/api/idea_vote.php`;
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
let suppressMapClickOpenReport = false;
window._lastGeocodeHit = null;
let layerMarkers = [];
let treeMarkers = [];
let treeClusterGroup = null; // Leaflet.markercluster: fa markerek csoportja
let activeTreeFilter = 'all';
let addTreeMode = false;
let addTreeMarker = null;
let addTreeMapClick = null;
let facilityMarkers = [];
let civilEventMarkers = [];
let ideaMarkers = [];
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

/** P2: toast a Mobilekit (mobil) vagy CivicUi (desktop) útján */
function civicUiToast(message, type, title) {
  const msg = message == null ? '' : String(message);
  if (window.CivicUi && typeof window.CivicUi.toast === 'function') {
    window.CivicUi.toast({
      type: type || 'info',
      title: title || '',
      message: msg,
      timeoutMs: type === 'error' ? 5200 : 3600,
    });
  } else if (typeof alert === 'function') {
    alert(title ? title + '\n' + msg : msg);
  }
}

function civicToastFromApiPayload(payload, fallback) {
  if (window.CivicApi && typeof window.CivicApi.parseErrorMessage === 'function') {
    return window.CivicApi.parseErrorMessage(payload, fallback || '');
  }
  if (payload && typeof payload.error === 'string') return payload.error;
  return fallback || '';
}

function civicToastErr(err) {
  let msg = err && err.message ? String(err.message) : String(err);
  if (window.CivicApi && typeof window.CivicApi.parseErrorMessage === 'function' && err && err.payload) {
    msg = window.CivicApi.parseErrorMessage(err.payload, msg);
  }
  civicUiToast(msg, 'error');
}

function setModalInlineError(modal, text) {
  if (!modal) return;
  const el = modal.querySelector('#mInlineError');
  if (!el) return;
  if (!text) {
    el.style.display = 'none';
    el.textContent = '';
    return;
  }
  el.textContent = text;
  el.style.display = 'block';
}

async function fetchJson(url, opts){
  if (window.CivicApi && typeof window.CivicApi.fetchJson === 'function') {
    return window.CivicApi.fetchJson(url, opts || {});
  }
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

function getMapGeocodeProvider(){
  const cfg = window.CIVIC_GEOCODE;
  if (cfg && cfg.public_map_tomtom_only) return 'tomtom';
  const sel = document.getElementById('mapSearchProvider');
  if (sel && sel.value) return sel.value;
  if (cfg && cfg.default) return cfg.default;
  return 'nominatim';
}

async function geocodeAddress(query, limit = 5){
  const q = String(query ?? '').trim();
  if (!q) return [];
  const cfg = window.CIVIC_GEOCODE;
  const prov = getMapGeocodeProvider();
  if (cfg && cfg.backend && cfg.endpoint) {
    const u = `${cfg.endpoint}?q=${encodeURIComponent(q)}&limit=${encodeURIComponent(limit)}&provider=${encodeURIComponent(prov)}`;
    const j = await fetchJson(u, { credentials: 'include' });
    if (j && j.ok && Array.isArray(j.results)) return j.results;
    return [];
  }
  if (window.CIVIC_GEOCODE && window.CIVIC_GEOCODE.public_map_tomtom_only) {
    return [];
  }
  const url = `${GEO_SEARCH}?format=json&limit=${encodeURIComponent(limit)}&countrycodes=hu&q=${encodeURIComponent(q)}`;
  const res = await fetchJson(url);
  return Array.isArray(res) ? res : [];
}

function normalizeGeocodeHit(hit, lat, lon){
  if (hit && typeof hit === 'object') {
    return {
      lat: String(hit.lat != null ? hit.lat : lat),
      lon: String(hit.lon != null ? hit.lon : lon),
      display_name: hit.display_name || '',
      postal_code: hit.postal_code || '',
      city: hit.city || '',
      street: hit.street || '',
      house: hit.house || '',
    };
  }
  return { lat: String(lat), lon: String(lon), display_name: '', postal_code: '', city: '', street: '', house: '' };
}

function fillModalAddressFields(modal, p){
  if (!modal || !p) return;
  const z = modal.querySelector('#mZip');
  const c = modal.querySelector('#mCity');
  const s = modal.querySelector('#mStreet');
  const h = modal.querySelector('#mHouse');
  const n = modal.querySelector('#mAddrNote');
  if (z && p.postal_code) z.value = String(p.postal_code);
  if (c && p.city) c.value = String(p.city);
  if (s && p.street) s.value = String(p.street);
  if (h && p.house) h.value = String(p.house);
  if (n && p.display_name && !String(n.value || '').trim()) n.value = String(p.display_name);
}

async function reverseGeocodeFillModal(modal, lat, lng){
  const base = window.CIVIC_GEOCODE?.reverse_endpoint;
  if (!base || !modal) return;
  try {
    const j = await fetchJson(`${base}?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`, { credentials: 'include' });
    if (j && j.ok) fillModalAddressFields(modal, j);
  } catch (e) {
    console.warn('reverse geocode', e);
  }
}

function placeSearchMarker(lat, lon, hit){
  if (searchMarker) map.removeLayer(searchMarker);
  if (searchMarkerTimeout) clearTimeout(searchMarkerTimeout);
  window._lastGeocodeHit = normalizeGeocodeHit(hit, lat, lon);
  searchMarker = L.marker([lat, lon]).addTo(map);
  map.setView([lat, lon], 16, { animate: true });
  searchMarkerTimeout = setTimeout(() => {
    if (searchMarker) map.removeLayer(searchMarker);
    searchMarker = null;
    window._lastGeocodeHit = null;
  }, 600000);
}

// ====== Category labels + badge icons ======
function catLabel(cat){ return (window.LANG && (window.LANG['cat.'+cat+'_desc'] || window.LANG['cat.'+cat])) || cat; }

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
      { id:'road', label: t('cat.road_desc') },
      { id:'sidewalk', label: t('cat.sidewalk_desc') },
      { id:'lighting', label: t('cat.lighting_desc') },
      { id:'trash', label: t('cat.trash_desc') },
      { id:'green', label: t('cat.green_desc') },
      { id:'traffic', label: t('cat.traffic_desc') },
      { id:'idea', label: t('cat.idea_desc') },
      { id:'tree_upload', label: t('cat.tree_upload_desc') }
    );
  }
  if (canUseCivil()) {
    opts.push({ id:'civil_event', label: t('cat.civil_event_desc') });
  }
  return opts.map(o => `<option value="${o.id}">${esc(o.label)}</option>`).join('');
}

function statusLabel(st){ return t('status.' + st) || st; }

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

/** EU admin sub-city context (district, borough, arrondissement, sublocality, …) from provider-normalized JSON */
function subcityContextLine(r){
  const a = r && r.admin_subdivision;
  if (!a || typeof a !== 'object') return '';
  const name = String(a.subcity_name || '').trim();
  if (!name) return '';
  const city = String(a.city || '').trim();
  const typ = String(a.subcity_type || '').trim();
  let text = name;
  if (city && city.toLowerCase() !== name.toLowerCase()) {
    text += ', ' + city;
  }
  const typeHint = typ && typ !== 'unknown_subcity_unit'
    ? ` <span class="popup-subcity-type">(${esc(typ.replace(/_/g, ' '))})</span>`
    : '';
  return `<small class="popup-subcity"><b>${esc(t('popup.subcity_context'))}</b> ${esc(text)}${typeHint}</small><br>`;
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

function ideaIcon(){
  const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/svg/1f4a1.svg`;
  const html = `
    <div class="badge-marker" style="--ring:#eab308">
      <img src="${url}" alt="" />
    </div>
  `;
  return L.divIcon({ className:'', html, iconSize:[34,34], iconAnchor:[17,17], popupAnchor:[0,-14] });
}

function treeIcon(tree){
  // M5: green = healthy/good, yellow = needs attention/fair, red = unhealthy/poor/critical
  const colors = {
    healthy: '#22c55e',
    needs_attention: '#eab308',
    unhealthy: '#dc2626',
    adopted: '#3b82f6',
    default: '#22c55e'
  };
  let ring = colors.default;
  if (tree) {
    const hs = (tree.health_status || '').toLowerCase();
    const unhealthyStatus = ['poor', 'critical', 'unhealthy'];
    const needsAttentionStatus = ['fair', 'needs_attention'];
    if (unhealthyStatus.includes(hs) || tree.risk_level === 'high' || tree.risk_level === 'medium') {
      ring = colors.unhealthy;
    } else if (needsAttentionStatus.includes(hs) || tree.last_watered === null || (tree.last_watered && isOlderThanDays(tree.last_watered, 7))) {
      ring = colors.needs_attention;
    } else if (tree.adopted_by_user_id) {
      ring = colors.adopted;
    } else {
      ring = colors.healthy;
    }
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

// ====== PC: Jelmagyarázat menüpont – panel megnyitása/bezárása (lenyíló a gomb alatt) ======
(function initLegendMenuPanel(){
  const menuBtn = document.getElementById('legendMenuBtn');
  const panel = document.getElementById('legendPanel');
  const legend = document.getElementById('legend');
  if(!menuBtn || !panel) return;
  const open = () => {
    panel.hidden = false;
    menuBtn.setAttribute('aria-expanded', 'true');
    if (legend) { legend.classList.add('open'); legend.querySelector('#legendToggle')?.setAttribute('aria-expanded', 'true'); }
  };
  const close = () => { panel.hidden = true; menuBtn.setAttribute('aria-expanded', 'false'); };
  menuBtn.addEventListener('click', (e) => { e.stopPropagation(); panel.hidden ? open() : close(); });
  document.addEventListener('click', () => { if(!panel.hidden) close(); });
  panel.addEventListener('click', (e) => e.stopPropagation());
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
    if (r.category === 'green' || r.category === 'tree_upload') continue;
    const mk = L.marker([r.lat, r.lng], { icon: badgeIcon(r.category) })
      .addTo(map)
      .bindPopup(
        `<b>#${r.id}</b><br>` +
        `<b>${esc(catLabel(r.category))}</b><br>` +
        (r.status ? `<small><b>Státusz:</b> ${esc(statusLabel(r.status))}</small><br>` : '') +
        subcityContextLine(r) +
        userLine(r) +
        (r.title ? `<b>${esc(r.title)}</b><br>` : '') +
        (r.description ? `${esc(shortDescription(r.description))}<br>` : '') +
        `<div class="popup-like-wrap">${likeLine(r)}</div>`
      );

    markerLayers.push({ marker: mk, data: r });
  }

  setLegendCount(markerLayers.length);
}

function scheduleReload(){
  if (reloadTimer) clearTimeout(reloadTimer);
  reloadTimer = setTimeout(() => {
    loadApprovedMarkers().catch(err => console.error(err));
    loadLayerMarkers().catch(err => console.error(err));
    loadFacilities().catch(err => console.error(err));
    loadCivilEvents().catch(err => console.error(err));
    loadIdeas().catch(err => console.error(err));
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
        civicUiToast(t('auth.login_required'), 'info');
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
        if (labelEl) labelEl.textContent = j.liked ? t('popup.liked') : t('modal.like');
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
          if (!res.ok || !j || !j.ok) {
            civicUiToast(civicToastFromApiPayload(j, t('tree.water_error')), 'error');
            return;
          }
          const lastLine = el.querySelector('.tree-last-watered-line');
          if (lastLine && j.last_watered) {
            const tpl = window.LANG && window.LANG['tree.last_watered']
              ? window.LANG['tree.last_watered']
              : 'Öntözve: {date}';
            lastLine.textContent = tpl.replace('{date}', j.last_watered);
          }
          waterForm.reset();
          const msg = (window.LANG && window.LANG['tree.watered_success']) ? window.LANG['tree.watered_success'] : 'Öntözve. Köszönjük!';
          civicUiToast(msg, 'success');
        }catch(err){
          console.error(err);
          civicUiToast(t('tree.water_error'), 'error');
        }
      });
    }

    const healthForm = treeActions.querySelector('.tree-health-form');
    if (healthForm && treeId > 0) {
      healthForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const photoInput = healthForm.querySelector('.tree-health-photo');
        if (!photoInput || !photoInput.files || !photoInput.files[0]) {
          civicUiToast(t('tree.health_analyze_need_photo'), 'info');
          return;
        }
        const fd = new FormData();
        fd.append('tree_id', String(treeId));
        fd.append('photo', photoInput.files[0]);
        const resultEl = healthForm.querySelector('.tree-health-result');
        if (resultEl) resultEl.textContent = t('tree.health_analyzing');
        try {
          const res = await fetch(API_TREE_HEALTH_ANALYZE, { method: 'POST', body: fd });
          const j = await res.json().catch(() => null);
          if (!res.ok || !j || !j.ok) {
            if (resultEl) resultEl.textContent = (j && j.error) ? j.error : t('common.error_generic');
            return;
          }
          const statusLabel = (window.LANG && window.LANG['tree.health_status_' + j.status]) ? window.LANG['tree.health_status_' + j.status] : j.status;
          if (resultEl) resultEl.textContent = statusLabel + (j.suggestion ? ': ' + j.suggestion : '');
          healthForm.reset();
        } catch (err) {
          if (resultEl) resultEl.textContent = t('tree.water_error');
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
  if (treeClusterGroup) {
    map.removeLayer(treeClusterGroup);
    treeClusterGroup.clearLayers();
    treeClusterGroup = null;
  } else {
    treeMarkers.forEach(m => { try { map.removeLayer(m); } catch (_) {} });
  }
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
            <form class="tree-health-form" enctype="multipart/form-data" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin-top:4px">
              <input type="file" name="photo" accept="image/*" class="tree-health-photo" style="max-width:140px">
              <button type="submit" class="btn-soft btn-tree-health">${esc(window.LANG && window.LANG['tree.health_analyze'] ? window.LANG['tree.health_analyze'] : 'Egészség elemzés')}</button>
              <span class="tree-health-result small text-secondary"></span>
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

      const ageText = t.estimated_age ? (t.estimated_age + ' év') : (t.planting_year ? (new Date().getFullYear() - parseInt(t.planting_year, 10)) + ' év' : '–');
      const treeSerial = 'T' + String(Number(t.id)).padStart(4, '0');
      const speciesLabel = (window.LANG && window.LANG['tree.species_label']) ? window.LANG['tree.species_label'] : 'Fajta';
      const speciesText = (t.species && String(t.species).trim()) ? esc(t.species) : ((window.LANG && window.LANG['tree.unknown_species']) ? window.LANG['tree.unknown_species'] : '–');
      const mk = L.marker([t.lat, t.lng], { icon: treeIcon(t) }).bindPopup(
        `<b>🌳 ${esc(treeSerial)}</b> · <b>${speciesLabel}:</b> ${speciesText}<br>` +
        (t.address ? `<small>${esc(t.address)}</small><br>` : '') +
        `<small><b>${(window.LANG && window.LANG['tree.age']) ? window.LANG['tree.age'] : 'Életkor'}:</b> ${ageText}</small><br>` +
        `<small><b>${(window.LANG && window.LANG['tree.health']) ? window.LANG['tree.health'] : 'Állapot'}:</b> ${treeHealthLabel(t.health_status)}</small><br>` +
        `<small><b>${(window.LANG && window.LANG['tree.risk']) ? window.LANG['tree.risk'] : 'Kockázat'}:</b> ${treeRiskLabel(t.risk_level)}</small><br>` +
        actionsHtml
      );
      if (typeof L.markerClusterGroup === 'function') {
        if (!treeClusterGroup) treeClusterGroup = L.markerClusterGroup({ chunkedLoading: true });
        treeClusterGroup.addLayer(mk);
      } else {
        mk.addTo(map);
      }
      treeMarkers.push(mk);
    }
    if (treeClusterGroup && treeMarkers.length) map.addLayer(treeClusterGroup);
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

async function loadIdeas(){
  ideaMarkers.forEach(m => map.removeLayer(m));
  ideaMarkers = [];
  try{
    const params = new URLSearchParams();
    const b = getBoundsParams();
    params.set('minLat', b.minLat);
    params.set('maxLat', b.maxLat);
    params.set('minLng', b.minLng);
    params.set('maxLng', b.maxLng);
    params.set('limit', '500');
    const j = await fetchJson(`${API_IDEAS}?${params.toString()}`);
    const rows = j.data || [];
    for (const idea of rows){
      const mk = L.marker([idea.lat, idea.lng], { icon: ideaIcon() })
        .addTo(map)
        .bindPopup(
          `<b>${esc(idea.title || '')}</b><br>` +
          (idea.description ? `<small>${esc(idea.description)}</small><br>` : '') +
          (idea.author_name ? `<small>${esc(idea.author_name)}</small><br>` : '') +
          `<div class="idea-vote-row" data-idea-id="${idea.id}">
            <button type="button" class="like-btn ${idea.voted_by_me ? 'liked' : ''}" data-idea-id="${idea.id}" aria-label="${esc(t('idea.vote'))}">👍</button>
            <span class="idea-vote-count">${Number(idea.vote_count || 0)}</span> <span>${esc(t('idea.votes'))}</span>
          </div>`
        );
      mk._ideaData = idea;
      ideaMarkers.push(mk);
    }
    map.on('popupopen', (e) => {
      const popup = e.popup;
      const el = popup.getElement && popup.getElement();
      if (!el) return;
      const voteBtn = el.querySelector('.like-btn[data-idea-id]');
      if (voteBtn) {
        voteBtn.onclick = () => handleIdeaVote(Number(voteBtn.getAttribute('data-idea-id')), el);
      }
    });
  }catch(e){
    console.warn('ideas load failed', e);
  }
}

async function handleIdeaVote(ideaId, popupEl){
  if (!IS_LOGGED_IN) {
    if (popupEl) popupEl.querySelector('.idea-vote-count') && (popupEl.querySelector('.idea-vote-count').textContent += ' – ' + t('idea.login_to_vote'));
    return;
  }
  try {
    const j = await fetchJson(API_IDEA_VOTE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: ideaId }) });
    if (j.ok && popupEl) {
      const cnt = popupEl.querySelector('.idea-vote-count');
      const btn = popupEl.querySelector('.like-btn[data-idea-id]');
      if (cnt) cnt.textContent = j.count;
      if (btn) btn.classList.toggle('liked', !!j.voted);
    }
  } catch (err) {
    console.warn('idea vote failed', err);
  }
}

map.on('moveend zoomend', () => {
  scheduleReload();
});

loadLayerMarkers().catch(err => console.error(err));
loadFacilities().catch(err => console.error(err));
loadCivilEvents().catch(err => console.error(err));
loadIdeas().catch(err => console.error(err));

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

// ====== Új ötlet (térképre kattintás + űrlap) ======
(function initAddIdea(){
  const btn = document.getElementById('btnAddIdea');
  if (!btn || !IS_LOGGED_IN) return;
  let addIdeaMode = false;
  let addIdeaMapClick = null;
  btn.addEventListener('click', () => {
    addIdeaMode = !addIdeaMode;
    btn.classList.toggle('active', addIdeaMode);
    map.getContainer().style.cursor = addIdeaMode ? 'crosshair' : '';
    if (addIdeaMode) {
      addIdeaMapClick = (e) => {
        addIdeaMode = false;
        btn.classList.remove('active');
        map.getContainer().style.cursor = '';
        map.off('click', addIdeaMapClick);
        suppressMapClickOpenReport = true;
        openModal(e.latlng, { category: 'idea' });
      };
      map.on('click', addIdeaMapClick);
    } else {
      if (addIdeaMapClick) map.off('click', addIdeaMapClick);
    }
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
        exitAddTreeMode();
        suppressMapClickOpenReport = true;
        openModal(e.latlng, { category: 'tree_upload' });
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
      if (isFinite(lat) && isFinite(lon)) placeSearchMarker(lat, lon, hit);
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
        civicUiToast(t('search.no_results'), 'info');
        return;
      }
      if (hits.length === 1) {
        const lat = parseFloat(hits[0].lat);
        const lon = parseFloat(hits[0].lon);
        if (isFinite(lat) && isFinite(lon)) placeSearchMarker(lat, lon, hits[0]);
        hideResults();
        return;
      }
      if (results) {
        results._items = hits;
        showResults(hits);
      }
    }catch(err){
      console.error(err);
      civicUiToast(t('search.error'), 'error');
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

function openIdeaModal(latlng){
  closeModal();
  const lat = typeof latlng.lat === 'function' ? latlng.lat() : latlng.lat;
  const lng = typeof latlng.lng === 'function' ? latlng.lng() : latlng.lng;
  tempMarker = L.marker([lat, lng]).addTo(map);
  const modal = document.createElement('div');
  modal.id = 'reportModal';
  modal.className = 'modal-overlay';
  modal.innerHTML = `
    <div class="modal">
      <button class="modal-x" type="button" aria-label="${esc(t('modal.close'))}">×</button>
      <div class="modal-scroll">
        <h3>${esc(t('legend.idea_add') || 'Új ötlet')}</h3>
        <label>${esc(t('idea.title_placeholder') || 'Ötlet címe')}</label>
        <input id="mIdeaTitle" maxlength="200" placeholder="${esc(t('idea.title_placeholder') || '')}">
        <label>${esc(t('idea.desc_placeholder') || 'Leírás (opcionális)')}</label>
        <textarea id="mIdeaDesc" rows="3" maxlength="5000" placeholder="${esc(t('idea.desc_placeholder') || '')}"></textarea>
        <button type="button" class="btn primary" id="mIdeaSubmit">${esc(t('idea.submit') || 'Beküldés')}</button>
      </div>
    </div>`;
  document.body.appendChild(modal);
  modal.querySelector('.modal-x').onclick = () => { closeModal(); };
  modal.querySelector('#mIdeaSubmit').onclick = async () => {
    const title = modal.querySelector('#mIdeaTitle').value.trim();
    if (!title) { civicUiToast(t('api.facility_name_required'), 'info'); return; }
    const description = modal.querySelector('#mIdeaDesc').value.trim();
    try {
      const j = await fetchJson(API_IDEA_CREATE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title, description, lat, lng })
      });
      if (j.ok) {
        closeModal();
        loadIdeas().catch(() => {});
        civicUiToast(t('modal.thanks'), 'success');
      } else {
        civicUiToast(civicToastFromApiPayload(j, typeof j.error === 'string' ? j.error : t('common.error_generic')), 'error');
      }
    } catch (e) {
      civicToastErr(e);
    }
  };
}

function showLoginGate(){
  if (document.getElementById('introOverlayGate')) return;
  document.body.classList.add('intro-open');
  const ov = document.createElement('div');
  ov.id = 'introOverlayGate';
  ov.className = 'intro-overlay';
  ov.innerHTML = `
    <div class="intro-card">
      <h2>${esc(t('site.name') || 'Problématérkép')}</h2>
      <p class="intro-muted">${esc(t('modal.report_requires_login') || 'Bejelentés küldéséhez jelentkezz be vagy regisztrálj.')}</p>
      <div class="intro-actions">
        <a class="btn-primary" href="${BASE}/user/login.php?redirect=${encodeURIComponent(window.location.pathname || '/')}">${esc(t('nav.login') || 'Belépés')}</a>
        <a class="btn-ghost" href="${BASE}/user/register.php">${esc(t('nav.register') || 'Regisztráció')}</a>
      </div>
    </div>
  `;
  document.body.appendChild(ov);
}

function openModal(latlng, options){
  if (!IS_LOGGED_IN) {
    showLoginGate();
    return;
  }
  if (options && options.category === 'idea') {
    openIdeaModal(latlng);
    return;
  }
  closeModal();
  window._openModalOptions = options || {};

  const coords = {
    lat: Number(typeof latlng.lat === 'function' ? latlng.lat() : latlng.lat),
    lng: Number(typeof latlng.lng === 'function' ? latlng.lng() : latlng.lng),
  };

  tempMarker = L.marker([coords.lat, coords.lng]).addTo(map);

  const isMobileFullscreen = document.body.classList.contains('civicai-mobile');
  const modal = document.createElement('div');
  modal.id = 'reportModal';
  modal.className = 'modal-overlay' + (isMobileFullscreen ? ' modal-fullscreen-mobile' : '');
  modal.innerHTML = `
    <div class="modal">
      <button class="modal-x" type="button" aria-label="${esc(t('modal.close'))}">×</button>

      <div class="modal-scroll">
        <div id="mInlineError" class="modal-inline-error" role="alert" style="display:none"></div>
        <h3>${esc(t('modal.category'))}</h3>
        <select id="mCategory">
          ${buildCategoryOptions()}
        </select>
        <p id="mCategorySuggestion" class="muted small" style="display:none; margin-top:4px"></p>

        <div id="mTreeHint" class="modal-note box" style="display:none"></div>

        <div id="mImageBlock">
          <label>${esc(t('modal.image_optional'))}</label>
          <input id="mImage" type="file" accept="image/*" class="modal-file">
          <button type="button" id="mAnalyzePhotoBtn" class="btn-soft" style="display:none; margin-top:8px">${esc(t('tree.analyze_photo_btn') || 'Fotó elemzése (AI)')}</button>
          <div id="mTreeAnalyzeResult" class="tree-analyze-result" style="display:none" aria-live="polite"></div>
        </div>

        <label id="mTitleLabel">${esc(t('modal.category'))} – rövid cím</label>
        <input id="mTitle" maxlength="120" placeholder="${esc(t('modal.title_placeholder'))}">

        <label id="mDescLabel">Leírás</label>
        <textarea id="mDesc" rows="4" maxlength="5000" placeholder="${esc(t('modal.desc_placeholder'))}"></textarea>

        <div id="mTreeSizes" style="display:none">
          <label>${esc(t('tree.trunk_label') || 'Törzsméret (cm, opcionális)')}</label>
          <input id="mTrunkDiameter" type="number" min="0" max="500" step="0.1" placeholder="pl. 45">
          <label>${esc(t('tree.canopy_label') || 'Koronaméret (m, opcionális)')}</label>
          <input id="mCanopyDiameter" type="number" min="0" max="50" step="0.1" placeholder="pl. 8">
        </div>

        <div id="mReportFields">
        <div id="mEventFields" style="display:none">
          <h3>${esc(t('modal.event_time_heading'))}</h3>
          <label>${esc(t('modal.label_event_start'))}</label>
          <input id="mEventStart" type="date">
          <label>${esc(t('modal.label_event_end'))}</label>
          <input id="mEventEnd" type="date">
        </div>

        <h3>${esc(t('modal.address_section_optional'))}</h3>
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
        <label>${esc(t('modal.address_note_label'))}</label>
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
          <label>${esc(t('modal.label_email'))}</label>
          <input id="mEmail" maxlength="190" placeholder="${esc(t('modal.email_placeholder'))}" inputmode="email">

          <label>${esc(t('modal.label_name'))}</label>
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

  const modalOpts = options || {};
  if (modalOpts.prefillAddress) {
    fillModalAddressFields(modal, modalOpts.prefillAddress);
  } else if (modalOpts.fromMapClick && window.CIVIC_GEOCODE?.reverse_endpoint && modalOpts.category !== 'tree_upload') {
    void reverseGeocodeFillModal(modal, coords.lat, coords.lng);
  }

  if (isMobileFullscreen) {
    const modalBox = modal.querySelector('.modal');
    const mobileHeader = document.createElement('div');
    mobileHeader.className = 'modal-header-mobile';
    mobileHeader.innerHTML = `
      <span class="modal-header-mobile-title">${esc(t('fab.report') || 'Bejelentés')}</span>
      <button type="button" class="modal-header-mobile-close" aria-label="${esc(t('modal.close'))}">×</button>
    `;
    modalBox.insertBefore(mobileHeader, modalBox.firstChild);
    mobileHeader.querySelector('.modal-header-mobile-close').addEventListener('click', closeModal);
  }

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
    const geoBtn = modal.querySelector('#mGeocodeBtn');
    const zip = modal.querySelector('#mZip')?.value.trim() || '';
    const city = modal.querySelector('#mCity')?.value.trim() || '';
    const street = modal.querySelector('#mStreet')?.value.trim() || '';
    const house = modal.querySelector('#mHouse')?.value.trim() || '';
    const addr = [street, house, city, zip].filter(Boolean).join(', ');
    if (!addr) { setModalInlineError(modal, t('modal.address_required')); return; }
    setModalInlineError(modal, '');
    const geoLabel = geoBtn ? geoBtn.textContent : '';
    if (geoBtn) { geoBtn.disabled = true; geoBtn.textContent = t('modal.geocode_searching'); }
    try {
      const hits = await geocodeAddress(addr, 1);
      if (!hits || !hits.length) { civicUiToast(t('modal.geocode_no_results'), 'info'); return; }
      const h = hits[0];
      const lat = parseFloat(h.lat);
      const lon = parseFloat(h.lon);
      if (isFinite(lat) && isFinite(lon)) {
        if (tempMarker) map.removeLayer(tempMarker);
        tempMarker = L.marker([lat, lon]).addTo(map);
        map.setView([lat, lon], 17, { animate: true });
        coords.lat = lat;
        coords.lng = lon;
        fillModalAddressFields(modal, h);
        civicUiToast(t('modal.location_set'), 'success');
      } else { civicUiToast(t('modal.geocode_failed'), 'error'); }
    } catch (e) {
      console.error(e);
      civicUiToast(t('modal.geocode_error'), 'error');
    } finally {
      if (geoBtn) { geoBtn.disabled = false; geoBtn.textContent = geoLabel || t('modal.geocode_btn'); }
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
    const cat = elCategory ? elCategory.value : '';
    const isCivil = cat === 'civil_event';
    const isTree = cat === 'tree_upload';
    if (elEventFields) elEventFields.style.display = isCivil ? '' : 'none';
    const mReportFields = modal.querySelector('#mReportFields');
    const mTreeHint = modal.querySelector('#mTreeHint');
    const mTitleLabel = modal.querySelector('#mTitleLabel');
    const mDescLabel = modal.querySelector('#mDescLabel');
    const mTitleInput = modal.querySelector('#mTitle');
    const mDescInput = modal.querySelector('#mDesc');
    if (mReportFields) mReportFields.style.display = isTree ? 'none' : '';
    if (mTreeHint) {
      mTreeHint.style.display = isTree ? 'block' : 'none';
      mTreeHint.textContent = t('modal.tree_hint');
    }
    const mImageBlock = modal.querySelector('#mImageBlock');
    const mTreeSizes = modal.querySelector('#mTreeSizes');
    const mAnalyzePhotoBtn = modal.querySelector('#mAnalyzePhotoBtn');
    if (mTreeSizes) mTreeSizes.style.display = isTree ? 'block' : 'none';
    if (mAnalyzePhotoBtn) mAnalyzePhotoBtn.style.display = isTree ? 'block' : 'none';
    if (mImageBlock) {
      const scroll = modal.querySelector('.modal-scroll');
      const target = isTree ? mTitleLabel : (modal.querySelector('#mTreeSizes') || mReportFields);
      if (scroll && target) scroll.insertBefore(mImageBlock, target);
    }
    if (isTree) {
      if (mTitleLabel) mTitleLabel.textContent = t('modal.tree_species_label');
      if (mDescLabel) mDescLabel.textContent = t('modal.tree_note_label');
      if (mTitleInput) mTitleInput.placeholder = t('tree.species_placeholder') || 'pl. kőris, tölgy';
      if (mDescInput) mDescInput.placeholder = t('tree.note_placeholder') || 'pl. becsült életkor, állapot';
    } else {
      if (mTitleLabel) mTitleLabel.textContent = t('modal.default_title_label');
      if (mDescLabel) mDescLabel.textContent = t('modal.description_label');
      if (mTitleInput) mTitleInput.placeholder = t('modal.title_placeholder') || 'pl. Kátyú a kereszteződésnél';
      if (mDescInput) mDescInput.placeholder = t('modal.desc_placeholder') || 'Írd le röviden a problémát';
    }
  };

  elNotify.addEventListener('change', syncContact);
  elAnon.addEventListener('change', syncContact);
  elCreate.addEventListener('change', () => { syncContact(); syncPass(); });
  if (elCategory) elCategory.addEventListener('change', syncCategory);

  syncCategory();
  if (window._openModalOptions && window._openModalOptions.category === 'tree_upload' && elCategory) {
    elCategory.value = 'tree_upload';
    syncCategory();
  }
  window._openModalOptions = null;

  modal.querySelector('#mAnalyzePhotoBtn')?.addEventListener('click', async () => {
    const fileInput = modal.querySelector('#mImage');
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
      civicUiToast(t('tree.health_analyze_need_photo'), 'info');
      return;
    }
    const btn = modal.querySelector('#mAnalyzePhotoBtn');
    const origText = btn?.textContent;
    const resultEl = modal.querySelector('#mTreeAnalyzeResult');
    if (resultEl) { resultEl.style.display = 'none'; resultEl.textContent = ''; }
    if (btn) { btn.disabled = true; btn.textContent = t('tree.analyze_photo_analyzing'); }
    try {
      const fd = new FormData();
      fd.append('photo', fileInput.files[0]);
      const res = await fetch(API_TREE_ANALYZE_PHOTO, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await res.json().catch(() => null);
      if (j && j.ok) {
        const mTitle = modal.querySelector('#mTitle');
        if (j.species && mTitle) mTitle.value = j.species;
        const mTrunk = modal.querySelector('#mTrunkDiameter');
        if (j.trunk_diameter_cm != null && mTrunk) mTrunk.value = String(j.trunk_diameter_cm);
        const mCanopy = modal.querySelector('#mCanopyDiameter');
        if (j.canopy_diameter_m != null && mCanopy) mCanopy.value = String(j.canopy_diameter_m);
        const resultEl = modal.querySelector('#mTreeAnalyzeResult');
        if (resultEl) {
          const hasAny = (j.species && j.species.trim()) || j.trunk_diameter_cm != null || j.canopy_diameter_m != null;
          if (hasAny) {
            resultEl.style.display = 'none';
            resultEl.textContent = '';
          } else {
            resultEl.textContent = t('tree.analyze_no_result');
            resultEl.style.display = 'block';
          }
        }
      } else {
        const resultEl = modal.querySelector('#mTreeAnalyzeResult');
        if (resultEl) { resultEl.style.display = 'none'; resultEl.textContent = ''; }
        civicUiToast(civicToastFromApiPayload(j, typeof j.error === 'string' ? j.error : t('common.error_server')), 'error');
      }
    } catch (e) {
      const re = modal.querySelector('#mTreeAnalyzeResult');
      if (re) { re.style.display = 'none'; re.textContent = ''; }
      civicUiToast(t('common.error_server'), 'error');
    }
    if (btn) { btn.disabled = false; btn.textContent = origText || t('tree.analyze_photo_btn'); }
  });

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
            elSuggestion.innerHTML = `${esc(t('modal.suggested_category_intro'))} <strong>${esc(res.label || res.suggested_category)}</strong> <button type="button" class="btn-soft btn-sm" id="mApplySuggestion">${esc(t('modal.suggested_category_apply'))}</button>`;
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
    setModalInlineError(modal, '');
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
      setModalInlineError(modal, t('api.description_required'));
      return;
    }

    // nem anonim -> név kell
    if (!IS_LOGGED_IN && !reporter_is_anonymous && !reporter_name) {
      setModalInlineError(modal, t('modal.name_required'));
      return;
    }

    const needsPersonal = (notify_enabled || create_account || !reporter_is_anonymous);

    if (!IS_LOGGED_IN && needsPersonal && !consent_data) {
      setModalInlineError(modal, t('api.gdpr_consent_required'));
      return;
    }

    // értesítés/reg: email kell (vendégnél)
    if (!IS_LOGGED_IN && (notify_enabled || create_account) && !reporter_email) {
      setModalInlineError(modal, t('modal.email_required_notify'));
      return;
    }

    if (create_account && password.length < 8) {
      setModalInlineError(modal, t('api.password_min_8'));
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
          `${API_NEARBY}?lat=${encodeURIComponent(coords.lat)}&lng=${encodeURIComponent(coords.lng)}&category=${encodeURIComponent(category)}&radius=200`
        );
        if (near200.ok && near200.data && near200.data.length > 0 && elNearby200) {
          elNearby200.textContent = 'ℹ️ ' + (t('modal.nearby_count').replace('{n}', String(near200.data.length)));
          elNearby200.style.display = 'block';
        }
      } catch (e) { console.warn('nearby 200m check failed', e); }
    }

    // Duplikáció ellenőrzés (50m) – civil esemény és fa feltöltésnél nem fut
    let force = false;
    if (category !== 'civil_event' && category !== 'tree_upload') {
      try{
        const near = await fetchJson(
          `${API_NEARBY}?lat=${encodeURIComponent(coords.lat)}&lng=${encodeURIComponent(coords.lng)}&category=${encodeURIComponent(category)}&radius=50`
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
    btn.textContent = t('modal.submitting');

    try{
      if (category === 'tree_upload') {
        if (modal._treeSubmitting) return;
        if (!IS_LOGGED_IN) {
          civicUiToast(t('modal.tree_upload_login'), 'error');
          return;
        }
        modal._treeSubmitting = true;
        const formData = new FormData();
        formData.append('lat', String(coords.lat));
        formData.append('lng', String(coords.lng));
        formData.append('species', title || '');
        formData.append('note', description || '');
        const trunkVal = modal.querySelector('#mTrunkDiameter')?.value;
        const canopyVal = modal.querySelector('#mCanopyDiameter')?.value;
        if (trunkVal != null && trunkVal !== '') formData.append('trunk_diameter_cm', trunkVal);
        if (canopyVal != null && canopyVal !== '') formData.append('canopy_diameter_m', canopyVal);
        const fileInput = modal.querySelector('#mImage');
        if (fileInput && fileInput.files && fileInput.files[0]) {
          formData.append('photo', fileInput.files[0]);
        }
        const res = await fetch(API_TREE_CREATE, { method: 'POST', body: formData, credentials: 'same-origin' });
        const rawText = await res.text();
        let j = null;
        try {
          j = rawText ? JSON.parse(rawText) : null;
        } catch (_) {
          if (res.status >= 400) throw new Error((t('common.error_server') || 'Szerver hiba.') + ' (HTTP ' + res.status + ')');
        }
        if (!j || !j.ok) {
          modal._treeSubmitting = false;
          throw new Error(j && j.error ? j.error : (t('common.error_server') || 'Fa feltöltés sikertelen.'));
        }
        civicUiToast(t('tree.submit_success'), 'success');
        modal._treeSubmitting = false;
        closeModal();
        loadTrees().catch(e => console.warn('loadTrees after tree_create', e));
        btn.disabled = false;
        btn.textContent = t('modal.submit');
        return;
      }
      if (category === 'civil_event') {
        if (!event_start || !event_end) {
          setModalInlineError(modal, t('modal.event_dates_required'));
          btn.disabled = false;
          btn.textContent = t('modal.submit');
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
            lat: coords.lat,
            lng: coords.lng,
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
            lat: coords.lat,
            lng: coords.lng,
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

      civicUiToast(t('modal.thanks'), 'success');
      closeModal();

    }catch(err){
      console.error(err);
      modal._treeSubmitting = false;
      civicUiToast((t('common.error_submit') || 'Hiba') + ': ' + (err && err.message ? err.message : String(err)), 'error');
      btn.disabled = false;
      btn.textContent = t('modal.submit');
    }
  });
}

// Kattintás a térképen → bejelentés (TomTom reverse cím, ha elérhető)
map.on('click', (e) => {
  if (suppressMapClickOpenReport) {
    suppressMapClickOpenReport = false;
    return;
  }
  openModal(e.latlng, { fromMapClick: true });
});

document.getElementById('btnNewReport')?.addEventListener('click', () => {
  let latlng = map.getCenter();
  let prefill = null;
  const hit = window._lastGeocodeHit;
  if (hit && searchMarker && map.hasLayer(searchMarker)) {
    const ll = searchMarker.getLatLng();
    const plat = parseFloat(hit.lat);
    const plon = parseFloat(hit.lon);
    if (isFinite(plat) && isFinite(plon) && Math.abs(ll.lat - plat) < 1e-4 && Math.abs(ll.lng - plon) < 1e-4) {
      latlng = { lat: plat, lng: plon };
      prefill = hit;
    }
  }
  openModal(latlng, prefill ? { prefillAddress: prefill } : {});
});

// ====== FIRST POPUP (vendég: csak Belépés / Regisztráció – anonim bejelentés nincs) ======
(function introGate(){
  if (window.TERKEP_LOGGED_IN === true || document.body?.dataset?.loggedIn === '1') return;
  document.body.classList.add('intro-open');
  const ov = document.createElement('div');
  ov.id = 'introOverlayGate';
  ov.className = 'intro-overlay';
  ov.innerHTML = `
    <div class="intro-card">
      <h2>${esc(t('site.name') || 'Problématérkép')}</h2>
      <p class="intro-muted">${esc(t('modal.report_requires_login') || 'Bejelentés küldéséhez jelentkezz be vagy regisztrálj.')}</p>
      <div class="intro-actions">
        <a class="btn-primary" href="${BASE}/user/login.php?redirect=${encodeURIComponent(window.location.pathname || '/')}">${esc(t('nav.login') || 'Belépés')}</a>
        <a class="btn-ghost" href="${BASE}/user/register.php">${esc(t('nav.register') || 'Regisztráció')}</a>
      </div>
      <div class="intro-foot"><small>${esc(t('modal.report_login_tip') || 'Belépés után a saját ügyeidet is könnyen visszakeresed.')}</small></div>
    </div>
  `;
  document.body.appendChild(ov);
})();

// ====== EU Green overlay (közig / admin: Copernicus + helyi rács) ======
(function initEuGreenOverlay(){
  const role = USER_ROLE || '';
  if (!['govuser', 'admin', 'superadmin'].includes(role)) return;
  const toggle = document.getElementById('euGreenOverlayToggle');
  const sel = document.getElementById('euGreenOverlayLayer');
  if (!toggle || !sel) return;
  let euLayer = null;
  async function loadEuLayer(){
    if (euLayer) {
      map.removeLayer(euLayer);
      euLayer = null;
    }
    if (!toggle.checked) return;
    const lt = sel.value || 'planting_priority';
    const url = `${BASE}/api/eu_green_overlay.php?layer_type=${encodeURIComponent(lt)}`;
    try {
      const res = await fetch(url, { credentials: 'include' });
      const j = await res.json();
      if (!j.ok || !j.data || j.data.type !== 'FeatureCollection') return;
      euLayer = L.geoJSON(j.data, {
        pointToLayer(feature, latlng) {
          const w = (feature.properties && feature.properties.weight != null) ? parseFloat(String(feature.properties.weight)) : 0.3;
          const r = 4 + Math.min(14, (isFinite(w) ? w : 0.3) * 12);
          const c = w > 0.55 ? '#e74c3c' : (w > 0.35 ? '#f39c12' : '#27ae60');
          return L.circleMarker(latlng, { radius: r, color: c, fillColor: c, fillOpacity: 0.42, weight: 1 });
        }
      });
      euLayer.addTo(map);
    } catch (e) {
      console.warn('EU green overlay', e);
    }
  }
  toggle.addEventListener('change', loadEuLayer);
  sel.addEventListener('change', () => { if (toggle.checked) loadEuLayer(); });
})();
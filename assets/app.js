// Public map (bejelentés + jóváhagyott jelölők)
const BASE = '/terkep';
const IS_LOGGED_IN = !!window.TERKEP_LOGGED_IN;
const USER_ROLE = window.TERKEP_ROLE || 'guest';
const API_LIST   = `${BASE}/api/reports_list.php`;
const API_CREATE = `${BASE}/api/report_create.php`;
const API_NEARBY = `${BASE}/api/reports_nearby.php`;
const GEO_SEARCH = 'https://nominatim.openstreetmap.org/search';

// ====== Map init ======
const map = L.map('map').setView([46.565, 20.667], 13);
map.attributionControl.setPrefix(false);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; OpenStreetMap közreműködők'
}).addTo(map);

let markerLayers = []; // { marker, data }
let searchMarker = null;
let searchMarkerTimeout = null;

// ====== Utils ======
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
  civil_event:'Civil esemény'
};

const ICON = {
  road:     { tw: '1f6a7', color:'#e74c3c' }, // 🚧
  sidewalk: { tw: '1f6b6', color:'#3498db' }, // 🚶
  lighting: { tw: '1f4a1', color:'#f1c40f' }, // 💡
  trash:    { tw: '1f5d1', color:'#34495e' }, // 🗑️
  green:    { tw: '1f333', color:'#27ae60' }, // 🌳
  traffic:  { tw: '1f6a6', color:'#9b59b6' }, // 🚦
  idea:     { tw: '2757',  color:'#ff7a00' }, // ❗
  civil_event: { tw: '1f91d', color:'#0ea5e9' } // 🤝
};

function catLabel(cat){ return CAT_LABEL[cat] || cat; }

function canUseCivil(){
  return ['civil','admin','superadmin'].includes(USER_ROLE);
}

function buildCategoryOptions(){
  const opts = [
    { id:'road', label:'Úthiba / kátyú' },
    { id:'sidewalk', label:'Járda / burkolat hiba' },
    { id:'lighting', label:'Közvilágítás' },
    { id:'trash', label:'Szemét / illegális' },
    { id:'green', label:'Zöldterület / veszélyes fa' },
    { id:'traffic', label:'Közlekedés / tábla' },
    { id:'idea', label:'Ötlet / javaslat' }
  ];
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
    return `<small><b>Beküldő:</b> <a href="${BASE}/user/profile.php?id=${encodeURIComponent(r.reporter_user_id)}" target="_blank">${esc(name)}</a>${level}</small><br>`;
  }
  return `<small><b>Beküldő:</b> ${esc(name)}${level}</small><br>`;
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

// ====== Legend toggle ======
(function initLegend(){
  const legend = document.getElementById('legend');
  const btn = document.getElementById('legendToggle');
  const body = document.getElementById('legendBody');
  if(!legend || !btn || !body) return;

  const setExpanded = (isOpen) => {
    legend.classList.toggle('open', isOpen);
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  };

  const isMobile = window.matchMedia && window.matchMedia('(max-width: 560px)').matches;
  setExpanded(!isMobile);

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

async function loadApprovedMarkers(){
  clearMarkers();

  const j = await fetchJson(API_LIST);
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
        `${esc(r.description)}`
      );

    markerLayers.push({ marker: mk, data: r });
  }

  setLegendCount(rows.length);
}

loadApprovedMarkers().catch(err => console.error(err));

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
        alert('Nem található a cím. Kérlek pontosíts!');
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
      alert('Hiba a keresésnél. Próbáld újra később.');
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
      <button class="modal-x" type="button" aria-label="Bezárás">×</button>

      <div class="modal-scroll">
        <h3>Kategória</h3>
        <select id="mCategory">
          ${buildCategoryOptions()}
        </select>

        <label>Rövid cím (opcionális)</label>
        <input id="mTitle" maxlength="120" placeholder="pl. Kátyú a kereszteződésnél">

        <label>Leírás</label>
        <textarea id="mDesc" rows="4" maxlength="5000" placeholder="Írd le röviden a problémát / javaslatot"></textarea>

        <h3>Cím (opcionális)</h3>
        <div class="addr-grid">
          <div>
            <label>Irányítószám</label>
            <input id="mZip" maxlength="16" placeholder="5900">
          </div>
          <div>
            <label>Város</label>
            <input id="mCity" maxlength="80" placeholder="Orosháza">
          </div>
          <div>
            <label>Utca</label>
            <input id="mStreet" maxlength="120" placeholder="Szabadság utca">
          </div>
          <div>
            <label>Házszám</label>
            <input id="mHouse" maxlength="20" placeholder="12">
          </div>
        </div>
        <label>Cím megjegyzés (opcionális)</label>
        <input id="mAddrNote" maxlength="160" placeholder="pl. kapubejáró mellett">

        <div class="checks">
          <label class="check">
            <input id="mAnon" type="checkbox" checked>
            <span>Anonim publikálás (a neved nem jelenik meg)</span>
          </label>

          <label class="check">
            <input id="mNotify" type="checkbox">
            <span>Kérek e-mail értesítést a státuszváltozásokról</span>
          </label>
        </div>

        <div id="mLoggedBox" class="box" style="display:none">
          <div class="gdpr-note">Bejelentkezve vagy. Ha kéred az értesítést, a fiókod e-mail címére küldjük (nem kell újra megadnod).</div>
        </div>

        <div id="mContact" class="box" style="display:none">
          <label>E-mail (értesítéshez / regisztrációhoz)</label>
          <input id="mEmail" maxlength="190" placeholder="nev@email.hu" inputmode="email">

          <label>Név (ha nem anonim, kötelező)</label>
          <input id="mName" maxlength="80" placeholder="Pl. Kovács Anna">

          <label class="check" style="margin-top:10px">
            <input id="mCreateAccount" type="checkbox">
            <span>Szeretnék regisztrálni (később vissza tudok térni az ügyeimhez)</span>
          </label>

          <div id="mPassWrap" style="display:none">
            <label>Jelszó (min. 8 karakter)</label>
            <input id="mPass" type="password" minlength="8" maxlength="80" placeholder="********">
          </div>

          <div class="gdpr">
            <label class="check">
              <input id="mConsentData" type="checkbox">
              <span>Elfogadom az adatkezelési tájékoztatót, és hozzájárulok az adataim kezeléséhez.</span>
            </label>
            <label class="check">
              <input id="mConsentShare" type="checkbox" checked>
              <span>Hozzájárulok, hogy az ügy intézése érdekében az adataimat az illetékeseknek továbbítsák.</span>
            </label>
            <label class="check">
              <input id="mConsentMarketing" type="checkbox">
              <span>Hozzájárulok marketing célú megkeresésekhez.</span>
            </label>
            <div class="gdpr-note">
              A személyes adatok megadása önkéntes. Anonim bejelentésnél csak a jelölés adatai kerülnek tárolásra.
            </div>
          </div>
        </div>

        <div class="modal-note">
          A bejelentés ellenőrzés után jelenik meg. A címet a rendszer automatikusan csatolja.
        </div>

        <div class="modal-actions">
          <button id="mSubmit" class="btn-primary" type="button">Beküldés</button>
          <button id="mCancel" class="btn-ghost" type="button">Mégse</button>
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

  elNotify.addEventListener('change', syncContact);
  elAnon.addEventListener('change', syncContact);
  elCreate.addEventListener('change', () => { syncContact(); syncPass(); });

  modal.querySelector('#mSubmit').addEventListener('click', async () => {
    const category = modal.querySelector('#mCategory').value;
    const title = modal.querySelector('#mTitle').value.trim();
    const description = modal.querySelector('#mDesc').value.trim();
    const address_zip = modal.querySelector('#mZip')?.value.trim() || '';
    const address_city = modal.querySelector('#mCity')?.value.trim() || '';
    const address_street = modal.querySelector('#mStreet')?.value.trim() || '';
    const address_house = modal.querySelector('#mHouse')?.value.trim() || '';
    const address_note = modal.querySelector('#mAddrNote')?.value.trim() || '';

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
    
    if (!description){
      alert('Kérlek írj leírást!');
      return;
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

    // Duplikáció ellenőrzés (50m)
    let force = false;
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

    const btn = modal.querySelector('#mSubmit');
    btn.disabled = true;
    btn.textContent = 'Beküldés...';

    try{
      await fetchJson(API_CREATE, {
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

      alert('Köszönjük! A bejelentés ellenőrzés után fog megjelenni a térképen.');
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
# CivicAI – Urban Intelligence Layer – Milestone terv (szűrt, projekthez igazítva)

Ez a dokumentum a **Development Prompt** milestone-jait veti össze a **jelenlegi kódbázissal**, kiveszi a felesleges vagy már megvalósult részeket, és blokkokra szedi a ténylegesen megvalósítandó munkát.

**Fő szabály:** Ne építsünk újat ott, ahol már van; bővítsük a meglévő modulokat.

---

## Prioritások (product fókusz)

A government (önkormányzat) számára a jelenlegi állapot **még nem eladható**. Az alábbi sorrend és fókusz tükrözi, mi kell az eladhatósághoz és a user élményhez.

| Priorítás | Cél | Milestone-ok / tevékenység |
|-----------|-----|----------------------------|
| **1. Gov eladhatóság** | Önkormányzatnak mutatható, döntéshez használható platform | **Részvételi rész:** M3 Ideation (ötletek + szavazás), M4 Participatory Budgeting (közös költségvetés). **Statisztikai fejlesztések:** M1 Hotspot, M2 AI Insights, M5 Green City Analytics, M6 ESG Report Generator, M8 Advanced Gov Dashboard (Chart.js, indexek), M10 Open Data API bővítés. |
| **2. User oldal** | Polgár számára használható, professzionális élmény | **M11 Címkereső** – moduláris geocoding (Photon default), backend API, admin toggle; a bejelentési űrlap és a térkép használata egyszerűbb. |
| **Nem prior** | Erőforrás nem ide kerül | **Design módosítás** – nem prioritás. **2D/3D Digital Twin (M7)** – nem fontos; a rétegek összehangolása csak akkor, ha a fenti részek készen vannak. **PWA (M9)** – opcionális, később. |

Összefoglalva: **először** a gov-nak szóló részvétel (ötletek, költségvetési szavazás) és statisztika (hotspot, AI insights, green analytics, ESG jelentés, dashboard diagramok, open data), **párhuzamosan vagy hamar** a user oldali **címkereső**. Design és digital twin háttérbe szorul.

---

## 1. Kódbázis elemzés – mi van már

| Terület | Meglévő | Fájlok / táblák |
|--------|---------|------------------|
| **Térkép** | Leaflet + OSM, marker cluster, rétegek | `index.php`, `mobile/index.php`, `assets/app.js`, `api/reports_list.php`, `api/trees_list.php`, `api/layers_public.php`, `api/facilities_list.php`, `api/civil_events_list.php` |
| **Bejelentések** | reports, kategóriák, státusz, fotó, FMS bridge | `reports`, `report_attachments`, `report_status_log`, `report_likes`, `api/report_create.php`, `api/report_set_status.php`, `open311/v2/` |
| **AI** | Router (Mistral/OpenAI/Gemini), gov summary/ESG, report understanding, tree health | `services/AiRouter.php`, `AiPromptBuilder.php`, `api/gov_ai.php`, `api/report_create.php` (AI), `api/tree_health_analyze.php` |
| **Gov dashboard** | Statisztika, AI összefoglaló/ESG/jelentések, export, öntözendő fák | `gov/index.php`, `api/gov_ai.php`, `api/analytics.php`, `api/esg_export.php`, `api/trees_needing_water.php` |
| **Admin** | Bejelentések, felhasználók, rétegek, hatóságok, modulok | `admin/index.php`, `api/admin_*.php`, `api/admin_modules.php` |
| **Fa nyilvántartás** | trees, örökbefogadás, öntözés, egészség elemzés, fajta gondozás | `trees`, `tree_logs`, `tree_species_care`, `api/tree_*.php` |
| **Export / Open Data** | reports, trees, esg – CSV, JSON, GeoJSON | `api/export.php`, `api/esg_export.php`, `api/analytics.php` |
| **Gamification** | XP, badge, leaderboard, report like | `services/XpBadge.php`, `api/leaderboard.php`, `api/report_like.php` |
| **Barátok** | friend request, list | `api/friend_request.php`, `api/friends_list.php` |
| **Címkeresés** | Nominatim, frontend közvetlen hívás | `assets/app.js` (GEO_SEARCH, geocodeAddress), `util.php` (nominatim_*) |
| **PWA** | manifest.php (dinamikus), nincs service worker a fő app-ban | `manifest.php`, Mobilekit sablonok (reference) |

---

## 2. Milestone → blokk megfeleltetés (mi kell, mi nem)

### MILESTONE 1 – Urban Hotspot Engine

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| Heatmap réteg a Leafleten | **Nincs** | Új: heatmap réteg (pl. Leaflet.heat), opcionális, kapcsolható |
| Szűrés kategória / időszak | **Van** adat (reports) | Meglévő reports pipeline + új hotspot service |
| Top 10 hotspot, kategória gyakoriság, klaszterek | **Nincs** | Új: HotspotService (vagy analytics bővítés), SQL/aggregation |
| Admin dashboard megjelenítés | **Van** admin/gov | Új: admin/gov aloldal vagy blokk (insights panel) |

**Kivehető:** Semmi – teljesen új funkció.

**Blokkok:**
1. **Backend:** HotspotService (vagy `api/hotspots.php`) – `reports` alapján: top N hotspot (lat/lng grid vagy clustering), kategória gyakoriság, időszak szűrés; válasz JSON.
2. **Frontend:** Leaflet heatmap plugin (pl. Leaflet.heat) – opcionális réteg, kapcsoló a jelmagyarázatban; adat a new API-ból.
3. **Admin/Gov:** Hotspot insight panel – top 10 lista, kategória megoszlás (meglévő dashboard bővítése).

---

### MILESTONE 2 – Civic Analytics Engine (AI insights)

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| AI elemzés: report descriptions, comments, feedback | **Részben** – gov_ai summary/engagement/sustainability, report understanding | Bővítés: „emerging issues”, sentiment, trend szövegek; **comments** jelenleg nincs (nincs comments tábla) |
| Meglévő AI router, Mistral default | **Van** | Használni: AiRouter, AiPromptBuilder |
| Eredmények a gov dashboardon | **Van** AI panel | Új: „AI Insights” blokk (trendek, emerging issues) – strukturált insight tárolás opcionális |

**Kivehető:** Új AI provider vagy map stack – nem kell.

**Blokkok:**
1. **Adat:** Ha „comments” kell: `report_comments` tábla (opcionális) VAGY csak reports.description + report_status_log note – spec szerint „comments” nincs a projektben, így kezdetben csak reports + status history.
2. **Service:** CivicAnalyticsService vagy bővített AiPromptBuilder – promptok: emerging issues, sentiment trend, category growth, increasing complaints areas; kimenet strukturált (JSON).
3. **Storage (opcionális):** `ai_insights` vagy meglévő `ai_results` – cache/history; gov UI csak legutóbbi vagy időszakos.
4. **Gov dashboard:** „AI Insights” kártya – list vagy összefoglaló szöveg (meglévő gov index bővítése).

---

### MILESTONE 3 – Citizen Ideation Module

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| Ötlet entitás (title, description, lat, lng, votes, status) | **Nincs** | Új tábla: `ideas` (id, title, description, lat, lng, user_id, status, created_at, updated_at) + `idea_votes` (user_id, idea_id) |
| Beküldés, térképre tűzés, szavazás | **Nincs** | Új API: create, list, vote; frontend: idea marker réteg + űrlap + szavazás gomb |
| Státusz: submitted → under_review → planned → in_progress → completed | **Nincs** | Enum/status mező, admin/gov kezelés (státusz változtatás) |
| Térképen + listában | **Van** map + list pattern (reports) | Ugyanaz a minta: ideas list API + map layer |

**Kivehető:** Külön „second civic platform” – ne legyen; integráljuk user, XP (opcionális), map, admin rendszerbe.

**Blokkok:**
1. **DB:** Migráció: `ideas`, `idea_votes`; status enum a spec szerint.
2. **API:** `api/ideas_list.php` (GET), `api/idea_create.php` (POST), `api/idea_vote.php` (POST); admin: `api/admin_ideas.php` vagy bővített admin – státusz módosítás.
3. **Frontend:** Ideas réteg a térképen (markerek), „Új ötlet” űrlap (lat/lng a map click-ből), lista nézet (pl. gov vagy külön oldal); szavazás gomb (reuse report_like pattern).
4. **Gamification:** Opcionális XP ötlet beküldésért/szavazásért (XpBadge).

---

### MILESTONE 4 – Participatory Budgeting

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| Projektek (title, description, budget, votes, status) | **Nincs** | Új tábla: `budget_projects` (id, title, description, budget, status, created_at, authority_id) + `budget_votes` (user_id, project_id) |
| Admin közzététel, polgár szavazás, rangsor | **Nincs** | Admin CRUD; nyilvános list + vote API; rangsor = ORDER BY vote count |

**Kivehető:** Duplikált „vote” rendszer – szavazás logika hasonló idea_votes-hoz (user_id, entity_id), külön tábla.

**Blokkok:**
1. **DB:** Migráció: `budget_projects`, `budget_votes`; authority_id a projektekhez (városonként).
2. **API:** `api/budget_projects_list.php`, `api/budget_vote.php`; admin: `api/admin_budget_projects.php` (CRUD).
3. **Admin UI:** Beépítés a meglévő admin dashboardba (új menüpont vagy tab) – projektek kezelése.
4. **Frontend (citizen):** Külön oldal vagy gov/public lista: projektek rangsorolva, szavazás gomb (egy user = egy szavazat projektenként).
5. **Későbbi:** ESG / analytics / export kapcsolás – projekt és szavazás adat szerepelhet az exportban.

---

### MILESTONE 5 – Green City Analytics

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| Fa egészség heatmap, kockázat, vízstressz, fajta, CO2 | **Részben** – trees, health_status, species, tree_species_care, AI health | Nincs: fa heatmap, vízstressz indikátor, CO2 becslés, fajta megoszlás dashboard |
| Tree cadastre | **Van** | Nem építjük újjá; bővítjük analytics szolgáltatást és vizualizációt |

**Kivehető:** Új fa nyilvántartás – nem.

**Blokkok:**
1. **Backend:** TreeAnalyticsService vagy `api/tree_analytics.php` – aggregációk: health megoszlás, species megoszlás, „water stress” (pl. last_watered + species care alapján), opcionális CO2 becslés (egyszerű formula: fajta + méret).
2. **Gov dashboard:** „Green City Analytics” blokk – fa egészség heatmap (vagy kategóriás összesítés), kockázat megoszlás, fajta megoszlás, vízstressz számláló, CO2 (ha implementált).
3. **Térkép:** Opcionális fa heatmap réteg (pl. density / health alapú) – vagy csak dashboard, térkép marad marker alapú.

---

### MILESTONE 6 – ESG Report Generator

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| ESG jelentés: fa, engagement, issues, projects | **Van** esg_export, gov_ai ESG, analytics | Bővítés: strukturált „Municipal ESG Report”, „Urban Sustainability”, „Green City Performance”; AI narratíva már van (gov_ai) |
| Export PDF, CSV, JSON | **Van** PDF (gov), CSV/JSON (esg_export, export) | Meglévő exportok bővítése; új sablonok ha kell |
| AI narratíva | **Van** AiRouter, gov_ai | Új prompt típusok vagy meglévő ESG prompt finomítás; strukturált szekciók + AI szöveg |

**Kivehető:** Párhuzamos export rendszer – nem; `api/export.php` és `api/esg_export.php` bővítése.

**Blokkok:**
1. **Report típusok:** Meghatározott szekciók: Municipal ESG Report, Urban Sustainability Report, Green City Performance; adat forrás: trees, reports, engagement (analytics), opcionális budget_projects.
2. **Service:** EsgReportGenerator – összegyűjti a metrikákat, meghívja az AI-t szekciónként (AiPromptBuilder bővítés); kimenet strukturált (titles + narrative + numbers).
3. **Export:** PDF (meglévő lib + új sablon), CSV, JSON – `api/esg_export.php` vagy `api/export.php` bővítés (pl. report_type param).
4. **Sablonok:** Moduláris sablonok (branding később) – most egy közös layout.

---

### MILESTONE 7 – Urban Digital Twin Layer

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| Egyesített rétegek: reports, trees, events, facilities, projects | **Részben** – reports, trees, layers, facilities, civil_events már külön rétegek | Összevonás egy „city layer” koncepcióban; nem 3D, csak 2D multi-layer |
| Layer toggles, legend, teljesítmény | **Van** legend, réteg váltás (app.js) | Egységesítés, esetleg egy „Digital Twin” toggle ami mindet mutatja; performance (clustering, limit) |

**Kivehető:** Súlyos 3D twin – nem kell; „lightweight 2D multi-layer” elég.

**Blokkok:**
1. **Konfiguráció:** Egy „urban layers” konfig (vagy meglévő layers_public bővítés) – reports, trees, events, facilities, ideas, (optional) budget_projects; mind ugyanazon a map instance-on.
2. **Frontend:** Meglévő rétegek maradnak; opcionális „Összes réteg” / „Város nézet” kapcsoló; legend bővítés (ideas, projects ha van).
3. **API:** Meglévő list API-k; opcionális `api/urban_layers.php` – egy hívással minden réteg adat (ha jobb teljesítmény kell).

---

### MILESTONE 8 – Advanced Government Dashboard

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| City Health Score, Engagement Index, Issue Trends, Green Index | **Részben** – analytics, gov stat, ESG blokkok | Explicit „score” / index számítás + Chart.js diagramok |
| Chart.js, időtrend, kategória, földrajz | **Nincs** Chart.js a fő app-ban | Chart.js bevezetés gov (és esetleg admin) oldalon; időtrend, kategória, földrajzi bontás |

**Kivehető:** Külön admin UI – nem; a meglévő gov (és admin) dashboard bővítése.

**Blokkok:**
1. **Metrikák:** Service réteg: City Health Score, Citizen Engagement Index, Urban Issue Trends, Green Infrastructure Index – definíciók (pl. analytics adatokból számolt indexek); dokumentált képletek.
2. **API:** Meglévő `api/analytics.php` bővítés vagy `api/gov_metrics.php` – időszak, bontások (idő, kategória, district).
3. **Gov UI:** Chart.js: időtrend (pl. havi bejelentések), kategória megoszlás, földrajzi bontás; 4 panel kártya (Health, Engagement, Issues, Green).
4. **Konzisztencia:** Ugyanazok a metrikák használhatók exportban és későbbi nyilvános API-ban.

---

### MILESTONE 9 – PWA Mobile Install

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| manifest.json | **Van** `manifest.php` (dinamikus) | Ellenőrizni: start_url, scope, icons; mobil oldalra linkelni ha még nincs |
| Service worker | **Nincs** a fő app-ban | Új: service worker – csak biztonságos cache (statikus asset, nem auth kritikus) |
| Add to Home Screen, alap offline | **Részben** manifest | SW regisztráció; cache stratégia (pl. cache-first statikus, network-first API) |

**Kivehető:** Teljes offline admin – nem; csak alap cache, auth/dynamic flow ne legyen cache-elve kritikusan.

**Blokkok:**
1. **Manifest:** `manifest.php` használata a mobile/index.php-n (link rel="manifest"); ikonok, theme_color, display standalone – már létezik, csak ellenőrzés.
2. **Service worker:** Új `sw.js` (vagy `service-worker.js`) – cache: CSS, JS, font, opcionális map tile policy; whitelist; ne cache-eljük: POST, auth header-t igénylő request.
3. **Regisztráció:** Mobile (és opcionális desktop) oldalon regisztráció; „Add to Home Screen” UX (prompt vagy tipp).
4. **Teszt:** Install flow, alap offline (pl. üres térkép vagy cached statikus oldal).

---

### MILESTONE 10 – Open Data API Extension

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| /api/stats, /api/hotspots, /api/trees, /api/esg, /api/ideas | **Van** trees (trees_list), esg (esg_export); nincs: stats, hotspots, ideas | Új vagy egységes végpontok; nyilvános vagy token alapú |
| CSV, JSON, GeoJSON | **Van** export.php, esg_export | Új végpontok ugyanilyen formátumokkal; pagination, filter ahol kell |

**Kivehető:** Párhuzamos API felépítés – nem; meglévő `api/export.php`, `api/analytics.php` mintára.

**Blokkok:**
1. **api/stats:** Általános statisztika (pl. analytics összesítés) – GET, format=json|csv; scope: authority/city; jog: nyilvános read-only vagy API key.
2. **api/hotspots:** M1 HotspotService kimenet – GET, format=json|csv|geojson; filter: category, date_from, date_to.
3. **api/trees:** Meglévő `trees_list` már létezik; ha kell külön „open data” név: alias vagy `api/export.php?dataset=trees` dokumentálása.
4. **api/esg:** Meglévő `esg_export`; dokumentáció, opcionális nyilvános hozzáférés.
5. **api/ideas:** M3 után – GET list, format=json|csv|geojson; pagination.
6. **Közös:** Konzisztens válasz formátum, hibakezelés, opcionális rate limit nyilvános esetén.

---

### MILESTONE 11 – Modular Address Search / Geocoding

| Kérés | Állapot | Teendő |
|-------|---------|--------|
| Leaflet + OSM marad | **Van** | Nem cseréljük |
| Külön geocoder modul, toggle, Photon default, Google prepared | **Nincs** – frontend közvetlen Nominatim | Backend geocoder service; Photon provider; Google placeholder; config toggle |
| Admin: ON/OFF, provider, Photon/Google beállítások | **Nincs** | Új config kulcsok + admin UI (meglévő modulokhoz hasonlóan) |
| Egyéges válasz (success, provider, results[]) | **Nincs** | Új API pl. `api/geocode.php` – query param; válasz: label, lat, lng, street, city, stb. |

**Kivehető:** Leaflet/OSM csere – kifejezetten tilos.

**Blokkok:**
1. **Config:** ADDRESS_SEARCH_ENABLED, ADDRESS_SEARCH_PROVIDER, PHOTON_*, GOOGLE_* (enabled=false), ADDRESS_SEARCH_MIN_CHARS, DEBOUNCE_MS, COUNTRY – config.php + opcionális .env.
2. **Backend:** GeocoderService (vagy AddressSearchService) – interface; PhotonProvider; GoogleGeocoderProvider (placeholder, enabled=false); timeout, üres eredmény, fallback; `api/geocode.php` (GET query=).
3. **Admin:** Beállítások blokk: címkeresés ki/be, provider választó, Photon URL, Google API key (rejtett), min chars, debounce, ország.
4. **Frontend:** Címkeresés UI csak ha ADDRESS_SEARCH_ENABLED=true; autocomplete, debounce, min 3 karakter; dropdown; kiválasztás → map pan + marker, lat/lng (és cím szöveg) kitöltése a report formban; mobilra is.
5. **Teszt:** Modul ki/be, Photon keresés, Google kikapcsolva (nincs hiba), térkép és mezők helyes kitöltése, mobil.

---

## 3. Implementációs sorrend és függőségek

**Sorrend a prioritások alapján (gov eladhatóság + user címkereső):**

**A. Gov eladhatóság – részvétel**
1. **M3 – Ideation** – ötletek + szavazás (térképen és listában); gov/admin kezelés. Közvetlenül mutatható „polgári részvétel”.
2. **M4 – Participatory Budgeting** – projektek + szavazás; admin CRUD, nyilvános rangsor. Szintén erős eladási pont.

**B. Gov eladhatóság – statisztika és dashboard**
3. **M1 – Hotspot Engine** – top problémák, kategória gyakoriság; gov/admin panel.
4. **M2 – Civic Analytics AI** – emerging issues, trendek, AI insights a gov dashboardon.
5. **M5 – Green City Analytics** – fa egészség, fajta, vízstressz (és opcionális CO2); gov blokk.
6. **M6 – ESG Report Generator** – strukturált ESG jelentések, PDF/CSV/JSON; meglévő esg bővítése.
7. **M8 – Advanced Gov Dashboard** – Chart.js, City Health / Engagement / Issues / Green indexek.
8. **M10 – Open Data API** – stats, hotspots, ideas (M3 után); egységes végpontok.

**C. User oldal – fontos**
9. **M11 – Address Search** – moduláris geocoding (Photon), backend API, admin toggle; bejelentési űrlap és térkép használatának javítása.

**D. Nem prior (később vagy skip)**
10. **M7 – Digital Twin Layer** – 2D réteg összehangolás; nem fontos a jelenlegi célhoz.
11. **M9 – PWA** – service worker, install; opcionális.

---

## 4. Fájlok / komponensek – hivatkozás

- **Térkép, rétegek:** `index.php`, `mobile/index.php`, `assets/app.js` (map init, layers, markers).
- **API-k:** `api/reports_list.php`, `api/trees_list.php`, `api/layers_public.php`, `api/analytics.php`, `api/export.php`, `api/esg_export.php`, `api/gov_ai.php`.
- **AI:** `services/AiRouter.php`, `services/AiPromptBuilder.php`.
- **Gov/Admin:** `gov/index.php`, `admin/index.php`; admin API-k: `api/admin_*.php`.
- **Config:** `config.php`, opcionális `.env`.
- **Címkeresés (jelenlegi):** `assets/app.js` (geocodeAddress, GEO_SEARCH), `util.php` (nominatim_*).

---

## 5. Rövid teszt checklist (milestone-onként)

- **M1:** Heatmap megjelenik, szűrők működnek; admin panel listázza a top hotspotokat és kategóriákat.
- **M2:** Gov „AI Insights” látható; emerging issues / trend szövegek értelmesek; cache (ha van) nem törik el.
- **M3:** Ötlet létrehozás, megjelenik a térképen és listában; szavazás növeli a számot; státusz változtatás adminnál.
- **M4:** Projekt létrehozás adminnál; nyilvános lista rangsorolva; szavazás egy user = egy szavazat/projekt.
- **M5:** Dashboard mutatja fa egészség/fajta/vízstressz (és CO2 ha van); számok konzisztensek a trees adatokkal.
- **M6:** ESG jelentés generálás PDF/CSV/JSON; AI szekciók olvashatók; adat források helyesek.
- **M7:** Több réteg egyszerre látható; kapcsolók és legend világosak; teljesítmény elfogadható.
- **M8:** Chart.js diagramok betöltődnek; City Health / Engagement / Issues / Green indexek láthatók.
- **M9:** Manifest elérhető; „Add to Home Screen” működik; service worker cache statikus tartalmat; auth flow nem törik.
- **M10:** /api/stats, /api/hotspots (és ideas ha kész) válaszolnak; formátumok (JSON, CSV, GeoJSON) konzisztensek; pagination/filter ahol kell.
- **M11:** Modul ki/be kapcsolás; Photon keresés működik; Google kikapcsolva hibátlan; kiválasztás kitölti a mezőket és mozgatja a térképet; mobil OK.

---

*Dokumentum verzió: 1.0 – a meglévő CivicAI kódbázis és a Development Prompt alapján.*

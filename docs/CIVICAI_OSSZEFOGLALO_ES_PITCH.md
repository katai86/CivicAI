# CivicAI – Teljes összefoglaló és fejlesztési irány (pitch deck alap)

---

## 1. Mi a CivicAI?

**CivicAI** egy **urbán civic-tech platform**: önkormányzatoknak és polgároknak egy helyen térkép, bejelentések, ötletek, részvételi költségvetés, fa nyilvántartás, AI elemzés és export. A polgárok bejelenthetik a problémákat, ötleteket adhatnak, szavazhatnak; a hatóság egy dashboardon látja a bejelentéseket, ötleteket és statisztikákat, AI összefoglalót és ESG jellegű exportot kap.

**Rövid értékajánlás:**
- **Polgárok:** Egy helyen: térkép, bejelentés, ötlet beküldés, szavazás ötletekre és költségvetési projektekre, fa örökbefogadás, társadalmi funkciók (like, barátok, rangsor).
- **Önkormányzatok:** Gov felület: bejelentések és ötletek város szerint, státusz kezelés, AI összefoglaló, ESG/analytics export, fa öntözési lista, modulok (FMS, AI, Részvételi költségvetés ki/be).
- **Admin:** Felhasználók, bejelentések, rétegek, hatóságok, szolgáltatások, hatósági felhasználók, Részvételi költségvetés projektek, modulbeállítások.

---

## 2. Jelenlegi funkciók (mi van meg)

### 2.1 Polgári oldal (térkép, felhasználó)

| Funkció | Leírás |
|--------|--------|
| **Térkép** | Leaflet + OSM, rétegek (bejelentések, fák, ötletek, közületi pontok, civil események, egyéni rétegek), marker clustering, jelmagyarázat |
| **Bejelentés** | Új bejelentés (kategória, cím, leírás, fotó), térképre tűzés; státusz követés |
| **Ötletek (M3)** | Új ötlet beküldése (cím, koordináta), ötletek megjelenítése a térképen, szavazás (támogatom), szavazatszám |
| **Részvételi költségvetés (M4)** | Külön oldal: projektek listája, szavazás (egy user = egy szavazat/projekt), időszakos ki/be kapcsolás (Modulok) |
| **Fa funkciók** | Fa réteg, örökbefogadás, öntözési napló, fa egészség (AI opció) |
| **Profil, gamification** | Bejelentkezés, regisztráció, profil, XP, badge, toplista, bejelentés like, barátok |
| **Nyelvek** | HU, EN (és opcionális további nyelvek) |

### 2.2 Közigazgatási (gov) felület

| Funkció | Leírás |
|--------|--------|
| **Dashboard** | Statisztikák (bejelentések 1/7 nap, státusz és kategória megoszlás), AI összefoglaló, ESG jellegű export (JSON/CSV) |
| **Bejelentések** | Lista (város/hatóság szerint gov usernek), státusz szűrés; admin: státusz módosítás |
| **Ötletek** | Ötletek fül: lista a **hozzátartozó város(ok) szerint** (authority_id), státusz változtatás (beküldve → átnézés alatt → tervezett → folyamatban → kész) |
| **Fák** | Fa réteg, öntözendő fák lista, fa funkciók |
| **Modulok** | Gov user saját modul kapcsolók (UI szint) |

### 2.3 Admin felület

| Funkció | Leírás |
|--------|--------|
| **Statisztika** | KPI (bejelentések ma/7 nap, felhasználók 7 nap), státusz és kategória megoszlás, integráció státusz (FixMyStreet, AI) |
| **Bejelentések** | Lista, szűrés (státusz, hatóság, keresés), limit, betöltés/frissítés |
| **Felhasználók** | Lista, szerep, aktív/tiltott szűrés |
| **Rétegek** | Map layers és pontok létrehozása, kategória (pl. választás, fakataszter), hatóság opcionális |
| **Hatóságok** | CRUD, szolgáltatás (Open311 service_code), hatósági felhasználó hozzárendelés |
| **Részvételi költségvetés** | Projektek CRUD (cím, leírás, költségvetés, státusz, hatóság), szavazatszám |
| **Modulok** | FixMyStreet, Mistral AI, OpenAI, **Részvételi költségvetés** (ki/be – időszakos szavazás) |

### 2.4 Backend / integrációk

| Terület | Leírás |
|--------|--------|
| **API-k** | REST-szerű végpontok: reports, trees, ideas, budget, layers, facilities, civil_events, analytics, esg_export, admin CRUD |
| **AI** | AiRouter (Mistral / OpenAI / Gemini), gov összefoglaló, ESG narratíva, bejelentés kategorizálás, fa egészség elemzés |
| **FixMyStreet / Open311** | Opcionális bridge: bejelentések kiküldése külső rendszerbe, szinkron; modul be/ki |
| **Export** | CSV, JSON, GeoJSON (reports, trees, analytics, ESG) |

### 2.5 Adatbázis (összevont migráció)

- **Alap:** users, reports, report_attachments, report_status_log, report_likes, friends, friend_requests, badges, user_xp_log, stb.
- **Hatóság:** authorities, authority_contacts, authority_users (város, bbox, szolgáltatások)
- **Térkép:** map_layers, map_layer_points
- **Modulok:** module_settings, user_module_toggles
- **FMS:** fms_reports, fms_sync_log
- **Közület / civil:** facilities, civil_events
- **Fák:** trees, tree_logs, tree_adoptions, tree_watering_logs, tree_species_care
- **AI:** ai_results
- **Ötletek (M3):** ideas, idea_votes (authority_id a város szerinti szűréshez)
- **Részvételi költségvetés (M4):** budget_projects, budget_votes
- **Egyéb:** user_xp_log, reports/users bővítések (cím, bejelentő, XP, stb.)

---

## 3. Technológiai stack

| Réteg | Technológia |
|-------|-------------|
| **Backend** | PHP 7.4+ (session, PDO, REST-szerű API-k) |
| **Adatbázis** | MySQL / MariaDB (InnoDB, utf8mb4) |
| **Frontend** | HTML5, CSS3, JavaScript (ES6+), Leaflet (térkép), Bootstrap 5, AdminLTE (admin/gov) |
| **AI** | Mistral / OpenAI / Gemini (API), saját AiRouter + prompt builder |
| **Egyéb** | Open311 kompatibilis végpontok, opcionális FMS bridge, moduláris beállítások (DB) |

---

## 4. Célközönség és használati esetek

| Szereplő | Használati eset |
|----------|------------------|
| **Polgár** | Probléma bejelentése a térképen, ötlet beküldése, szavazás ötletekre és költségvetési projektekre, fa örökbefogadás, tevékenység és rangsor |
| **Önkormányzat (gov user)** | Bejelentések és ötletek áttekintése a saját városban, státusz kezelés, AI összefoglaló, ESG/analytics export, fa öntözési lista |
| **Admin** | Rendszer kezelése: felhasználók, hatóságok, rétegek, Részvételi költségvetés projektek, modulok (FMS, AI, Részvételi költségvetés ki/be) |
| **Külső rendszer** | Open311 / export API-k (CSV, JSON, GeoJSON) – partnerek, nyílt adat |

---

## 5. Fejlesztési irány – merre megyünk (pitch deck / roadmap)

A **docs/URBAN_INTELLIGENCE_MILESTONES.md** alapján az alábbi sorrend és fókusz jellemzi a fejlesztést. A pitch deckben ezeket „következő lépések” és „roadmap” szekciókban érdemes megjeleníteni.

### 5.1 Priorítás 1: Gov eladhatóság (önkormányzatnak mutatható platform)

| Milestone | Cél | Mit ad a terméknek |
|-----------|-----|---------------------|
| **M1 – Hotspot Engine** | Top problémák, kategória gyakoriság, heatmap | Gov/admin: „hol a legtöbb bejelentés”, kategória megoszlás; térképen opcionális heatmap réteg |
| **M2 – Civic Analytics (AI insights)** | Emerging issues, trendek, érzelmi/sentiment elemzés | Gov: „AI Insights” panel – trendek, növekvő problématerületek, AI szöveges összefoglalók |
| **M5 – Green City Analytics** | Fa egészség, fajta, vízstressz, opcionális CO2 | Gov: Green blokk – fa egészség megoszlás, fajta, öntözési igény, (CO2 becslés) |
| **M6 – ESG Report Generator** | Strukturált ESG / fenntarthatósági jelentések | PDF/CSV/JSON jelentések: Municipal ESG, Urban Sustainability, Green City Performance; AI narratíva |
| **M8 – Advanced Gov Dashboard** | Indexek, Chart.js, időtrendek | City Health Score, Engagement Index, Issue Trends, Green Index; diagramok (idő, kategória, földrajz) |
| **M10 – Open Data API** | Egységes nyilvános/partner API | /api/stats, /api/hotspots, /api/ideas, /api/trees, /api/esg – JSON, CSV, GeoJSON, pagination |

### 5.2 Priorítás 2: User oldal – professzionális élmény

| Milestone | Cél | Mit ad a terméknek |
|-----------|-----|---------------------|
| **M11 – Címkereső (geocoding)** | Moduláris címkereső, Photon alapértelmezett | Bejelentési űrlap és térkép: cím beírása → találatok → kiválasztás → térkép és koordináta kitöltése; admin: ki/be, provider (Pl. Photon) |

### 5.3 Később / opcionális

| Milestone | Cél |
|-----------|-----|
| **M7 – Digital Twin Layer** | 2D réteg összehangolás (reports, trees, events, facilities, ideas egy „város nézetben”); nem 3D |
| **M9 – PWA** | Service worker, „Add to Home Screen”, alap cache – mobil install élmény |

### 5.4 Nem prioritás (jelen tervben)

- Teljes 3D Digital Twin
- Design átdolgozás (csak szükség esetén)
- Párhuzamos „second civic platform” – minden a meglévő rendszerbe épül

---

## 6. Pitch deck – javasolt szekciók és üzenetek

1. **Probléma** – Önkormányzatoknak nehéz egy helyen látni a polgári bejelentéseket, ötleteket és részvételi költségvetést; a polgároknak szétszórt eszközök.
2. **Megoldás** – CivicAI: egy platform = térkép + bejelentés + ötlet + részvételi költségvetés + fa nyilvántartás + AI elemzés + gov dashboard + export.
3. **Jelenlegi állapot** – Már megvan: polgári térkép, bejelentés, ötlet (M3), részvételi költségvetés (M4) időszakos kapcsolóval, gov ötletek város szerint, admin modulok, AI és FMS opciók, exportok.
4. **Következő lépések (roadmap)** – Hotspot & AI Insights (M1, M2) → Green Analytics (M5) → ESG jelentések (M6) → Advanced Gov Dashboard (M8) → Open Data API (M10) → Címkereső (M11); opcionális: PWA, 2D Digital Twin.
5. **Célközönség** – Önkormányzatok (fő vásárló), polgárok (végfelhasználók), partnerek (API/export).
6. **Különböző** – Részvételi költségvetés időszakos ki/be; ötletek város szerint a gov-on; moduláris (FMS, AI, Részvételi költségvetés); AI + ESG egy helyen; nyílt adat és Open311 kompatibilitás.

---

*Dokumentum: CivicAI teljes összefoglaló és fejlesztési irány – pitch deck alap. Frissítve: 2026.*

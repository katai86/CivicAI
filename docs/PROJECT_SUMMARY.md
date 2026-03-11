# CivicAI (Köz.Tér / Problematérkép) – Projekt összefoglaló

Ez a dokumentum a teljes mappa (CivicAI) elemzéséből készült összefoglaló. Cél: áttekintés a tesztelés és igazítási igények előtt, valamint alapanyag üzleti tervhez és prezentációhoz.

---

## 1. Mi a CivicAI egy mondatban?

**CivicAI egy civic térkép és bejelentő platform:** polgárok helyi problémákat jelentenek (út, járda, zöldterület, ötlet), nyomon követik ügyeiket, eseményeket és létesítményeket látnak, XP-t és badge-eket szereznek; a hatóságok és adminok kezelik a bejelentéseket, opcionálisan Open311/FixMyStreet integrációval és AI-alapú funkciókkal (fa egészség, összefoglalók, ESG).

---

## 2. Mappaszerkezet és fő fájlok

| Mappa / fájl | Jelentése |
|--------------|-----------|
| **index.php** | Fő belépési pont: térkép (desktop); mobilon átirányít a mobile/index.php-ra. |
| **config.php** | Egyetlen alkalmazás-konfig: DB, URL, térkép, session, FMS, AI, feltöltés – értékek környezeti változókból (.env). |
| **db.php** | Adatbázis kapcsolat (MySQL/MariaDB). |
| **util.php** | Session, auth, XP/badge, geocode, nyelv, API válasz segédfüggvények. |
| **user/** | Polgári felület: login, register, profile, my, settings, friends, report (új/szerkesztés), verify, logout. |
| **admin/** | Admin dashboard (AdminLTE): statisztika, bejelentések, felhasználók, rétegek, hatóságok, beépülő modulok (AI, FMS). |
| **gov/** | Önkormányzati (hatósági) dashboard: statisztika, bejelentések lista, AI összefoglaló/jelentés, ESG, öntözendő fák, export, modul kapcsolók. |
| **mobile/** | Mobil webalkalmazás (Mobilekit UI): térkép, bejelentés, profil, leaderboard. |
| **api/** | REST-szerű PHP végpontok: bejelentések (lista, létrehozás, feltöltés, státusz, like), fák (lista, örökbefogadás, öntözés, létrehozás, egészség elemzés), események, létesítmények, barátok, leaderboard, analytics, ESG export, export (reports/trees/esg), trees_needing_water, gov_ai, admin műveletek, FMS bridge. |
| **open311/** | Open311 API v2: discovery, services, requests (GET/POST) – multi-city (jurisdiction_id). |
| **services/** | Backend szolgáltatások: AiRouter, MistralProvider, OpenAIProvider, GeminiProvider, AiPromptBuilder, AiResultParser, XpBadge. |
| **assets/** | Frontend: style.css, app.js (térkép, markerek, fa réteg, űrlapok), admin.css. |
| **lang/** | Többnyelvű szövegek: hu.php, en.php, de, es, fr, it, sl. |
| **sql/** | Migrációk: 00_run_all_migrations_safe.sql, 01_consolidated_migrations.sql, 2026-xx egyedi (pl. tree cadastre, tree_species_care). |
| **docs/** | Dokumentáció: SOURCE_OF_TRUTH, MILESTONE_*, ROADMAP, OPERATIONS, AI_SETUP, SMART_CITY_MILESTONES, FUTURE_AI_FEATURES, DESIGN, stb. |
| **uploads/** | Feltöltött képek (bejelentés melléklet, fa, avatar) – config-ban UPLOAD_DIR, UPLOAD_PUBLIC. |
| **design/** | Statikus design anyagok (wireframe, design system) – nem futási kód. |
| **dashboard/** | AdminLTE vendor/build – admin és gov UI ezt használja. |

---

## 3. Felhasználói szerepkörök és belépési pontok

| Szerepkör | Hol lép be | Fő tevékenység |
|-----------|------------|-----------------|
| **Vendég** | / (térkép), /leaderboard.php, /case.php?token= | Térkép böngészés, nyilvános ügy megtekintés token alapján, leaderboard. |
| **Polgár (user)** | /user/login.php, regisztráció /user/register.php | Bejelentés küldése, saját ügyek, profil, barátok, fa örökbefogadás/öntözés, XP/badge. |
| **Hatóság (govuser)** | /gov/index.php (bejelentkezés user relációval) | Saját hatóság statisztikája, bejelentések listája (read-only vagy scope), AI összefoglaló/jelentés, ESG dashboard, öntözendő fák lista, export, FMS/AI modul kapcsolók. |
| **Admin / superadmin** | /admin/login.php | Teljes dashboard: bejelentések státusz kezelés, felhasználók, rétegek, hatóságok, modul beállítások (AI limitek, FMS, OpenAI), analytics, ESG. |

Több önkormányzat: **authorities** + **authority_users** + **reports.authority_id**; gov user csak a saját hatóságához tartozó adatot lát.

---

## 4. Fő funkciók és alrészek (tesztelési szempontból)

### 4.1 Polgári (civic) mag

- **Térkép:** Leaflet, markerek (bejelentések kategória szerint), klaszterezés, szűrők (legend), téma (világos/sötét), nyelv.
- **Bejelentés:** Új bejelentés (kategória: úthiba, járda, zöld, ötlet, stb.), cím, leírás, fotó feltöltés, geolokáció; státusz nyomon követés; értesítés e-mail (opcionális token).
- **Nyilvános ügy megtekintés:** /case.php?token= – token alapján (nincs bejelentkezés).
- **Profil, Saját ügyek, Beállítások, Barátok:** user/my.php, user/report.php, user/settings.php, user/friends.php.
- **Leaderboard:** XP és rangsor – nyilvános oldal.

### 4.2 Fa réteg (Urban Tree Cadastre, Green Intelligence)

- **Fák a térképen:** trees réteg, színkód (egészség: zöld/sárga/piros), popup: fajta, életkor, öntözés, örökbefogadás.
- **Fa örökbefogadás:** felhasználó „örökbe fogad” egy fát (adopted_by_user_id).
- **Öntözés:** naplózás (tree_logs), last_watered frissül.
- **Új fa felvitele:** tree_create API (admin/gov/örökbefogadó jog).
- **Fa szerkesztés:** tree_edit.php – species, estimated_age, planting_year, health_status, risk_level, notes.
- **AI fa egészség elemzés:** fotó feltöltés → vision API (Mistral/OpenAI) → healthy/dry/disease_suspected; eredmény tree_logs-ba.
- **Öntözendő fák (M7):** tree_species_care tábla (fajtánkénti intervallum, liter); api/trees_needing_water.php; gov dashboard „Öntözendő fák” blokk, lista gomb.

### 4.3 Hatósági / önkormányzati (Gov) dashboard

- **Statisztika:** mai/7 napos/összes bejelentés, státusz és kategória szerinti bontás, hatóság(ok) neve.
- **M9 öt panel:** City Health Overview, Citizen Engagement, Urban Issues, Tree Registry, ESG Impact (címkék a meglévő blokkokon).
- **Civic Analytics:** api/analytics.php – issue, engagement, urban maintenance; export JSON/CSV; gov kártya linkekkel.
- **Urban ESG Dashboard:** E/S/G blokkok (környezet, társadalom, irányítás), évválasztó, JSON/CSV export (esg_export.php).
- **Öntözendő fák (M7):** szám + „Lista megtekintése” (trees_needing_water API).
- **AI:** Összefoglaló kérés, ESG összefoglaló, AI jelentés (típus: karbantartás/részvétel/fenntarthatóság + időszak); PDF export (logo), formázott szöveg.
- **Bejelentések lista:** szűrő státusz szerint (gov user: csak saját hatóság).
- **Modul kapcsolók:** FMS/Open311, AI (Mistral/OpenAI) – csak megjelenítés, beállítás adminnál.

### 4.4 Admin dashboard

- **Bejelentések:** lista, státusz módosítás (pending, approved, rejected, solved, closed, stb.), megjegyzés; státusz változáskor e-mail értesítés (ha be van állítva).
- **Felhasználók, Rétegek, Hatóságok:** CRUD jellegű kezelés.
- **Beépülő modulok:** FixMyStreet/Open311 (base URL, jurisdiction, API key), Mistral, OpenAI – enabled, API kulcs, AI limitek (napi jelentés, összefoglaló limit, kép elemzés limit); „Teszt Mistral” gomb.
- **Analytics, ESG:** linkek a megfelelő API-khoz / exportokhoz.

### 4.5 Export és Open Data (M8)

- **api/export.php:** dataset=reports|trees|esg, format=csv|geojson|json; opcionálisan year, authority_id, city, date_from, date_to. Reports/trees: CSV, GeoJSON (Point), JSON; ESG → esg_export.php. Jog: admin vagy gov (reports/esg: authority scope).

### 4.6 Integrációk

- **Open311:** open311/v2 – discovery, services, requests (GET/POST); multi-city (APP_JURISDICTION_ID).
- **FixMyStreet bridge:** Bejelentés itt keletkezik; kiválasztott ügy exportálható Open311-ként a külső FMS szolgáltatásba (fms_bridge/export_report.php). Státusz visszahúzás: fms_bridge/sync.php (cron, ADMIN_TOKEN).
- **AI:** Mistral (elsődleges), OpenAI (vision is); AiRouter, prompt builder, rate limit (napi/összefoglaló/kép). Gov: összefoglaló, ESG narratíva, típusos jelentés (maintenance/engagement/sustainability) + időszak.

### 4.7 Egyéb

- **Többnyelv:** hu, en, stb. – lang fájlok, current_lang(), t().
- **Health check:** GET /api/health.php – ok, db, config_review; 503 ha DB elérhetetlen.
- **API dokumentáció (nyilvános):** /api-docs.php – Open311 rövid leírás.

---

## 5. Technológiai stack

- **Backend:** PHP, MySQL/MariaDB.
- **Frontend:** HTML, CSS (Bootstrap 5), JavaScript; Leaflet (térkép), MarkerCluster; AdminLTE (admin/gov); Mobilekit (mobil).
- **Konfiguráció:** config.php + környezeti változók (getenv); nincs .env commitolva, .env.example a változónevekkel.
- **Feltöltés:** config-ban UPLOAD_DIR, UPLOAD_PUBLIC, max méret, engedélyezett MIME (jpg, png, webp).
- **Session:** SESSION_NAME cookie, start_secure_session() (util).

---

## 6. Smart City milestone-ok (M1–M10) – állapot

| # | Milestone | Állapot | Rövid kimenet |
|---|-----------|--------|----------------|
| M1 | Civic Analytics | Kész | api/analytics.php, JSON/CSV, gov/admin link |
| M2 | AI report generator | Kész | gov_ai.php type + timeframe, AiPromptBuilder, Gov kártya |
| M3 | ESG dashboard | Kész | esg_export.php, Gov E/S/G blokkok, év, export |
| M4 | Tree cadastre bővítés | Kész | trees.notes, tree_edit.php |
| M5 | Tree layer szín | Kész | app.js treeIcon() health alapján |
| M6 | AI tree monitoring | Kész | tree_health_analyze.php, vision (OpenAI/Mistral), fa popup |
| M7 | Tree watering + értesítés | Kész | tree_species_care, trees_needing_water.php, Gov „Öntözendő fák” blokk |
| M8 | Export & Open Data | Kész | export.php reports/trees/esg, csv/geojson/json |
| M9 | Dashboard UI panelek | Kész | Gov 5 panel (City Health, Engagement, Issues, Trees, ESG) |
| M10 | Jövőbeli AI (placeholder) | Kész | docs/FUTURE_AI_FEATURES.md |

A milestone fejlesztés **kész**; opcionális: M7 e-mail/cron értesítés, M8 nyilvános rate limit, M10 predikciós szolgáltatás.

---

## 7. Dokumentumok (docs/) – rövid útmutató

- **README.md** – Dokumentáció olvasási sorrendje.
- **SOURCE_OF_TRUTH.md** – Mi a source of truth, mely fájlok aktívak.
- **MILESTONE_1_AUDIT_AND_PRODUCT_MAP.md** – Modul térkép, szerepkörök, user journey.
- **MILESTONE_4_USER_FLOWS.md** – Vendég/user/gov/admin flow-k.
- **SMART_CITY_MILESTONES.md** – Smart City M1–M10 részletes leírás és állapot.
- **FUTURE_AI_FEATURES.md** – Jövőbeli AI (predikció, hősziget, árvíz, zöldfedettség) terv.
- **OPERATIONS.md** – Health check, FMS sync cron, térkép multi-city, biztonság.
- **AI_SETUP.md** – AI (Mistral/OpenAI) beállítás, limitek, teszt.
- **ROADMAP_MILESTONES.md** – Topbar/design, beépülő modulok, fa réteg milestone-ok.

---

## 8. Teszteléshez és igazításhoz – javasolt fókuszok

1. **Polgári flow:** Regisztráció, bejelentés (minden kategória), fotó, státusz követés, értesítés e-mail, nyilvános case token.
2. **Fa flow:** Térkép fa réteg, szín, popup, örökbefogadás, öntözés, új fa (jog), fa szerkesztés, egészség elemzés (fotó).
3. **Gov:** Bejelentkezés (govuser), statisztika, bejelentések lista (szűrő), Analytics/ESG export, öntözendő fák lista, AI összefoglaló/jelentés (típus + időszak), PDF export.
4. **Admin:** Státusz változtatás, modulok (FMS, Mistral, OpenAI, limitek), Teszt Mistral, Analytics/ESG.
5. **Export:** export.php – reports/trees/esg, csv/geojson/json (admin és gov jog).
6. **Mobil:** mobile/index.php – térkép, navigáció, bejelentés, profil.
7. **Nyelvek és téma:** váltás minden fő oldalon (desktop és mobil).

Ezt az összefoglalót használhatod a tesztelési checklist és az igazítási igények (bug report, UX javaslat) strukturálásához.

# CivicAI → Smart City AI Platform – Elemzés és milestone sor

Ez a dokumentum a továbbfejlesztési prezentációt elemzi, összeveti a meglévő rendszerrel, és egy tiszta **milestone sort** ad a bővítéshez. **Nem írjuk át az egész rendszert** – modulárisan bővítjük PHP + MySQL környezetben.

---

## 1. Rövid elemzés – mi van már, mi kell

### Meglévő (amit a spec részben vagy teljesen lefed)

| Spec kérés | Jelenlegi állapot |
|------------|--------------------|
| **Bejelentések, térkép, kategóriák, fotó, geolocation** | ✅ Van: reports, Leaflet, categories, report_attachments, lat/lng |
| **Admin, gov dashboard** | ✅ Van: admin/index.php, gov/index.php (AdminLTE) |
| **AI összefoglaló / ESG** | ✅ Van: gov_ai.php (summary + esg), AiRouter (Mistral/OpenAI), PDF export a gov oldalon |
| **Fa réteg, fa feltöltés, örökbe fogadás, öntözés** | ✅ Van: trees, tree_create, tree_adopt, tree_watering, Tree Layer, clustering |
| **Fa adatmodell (species, health, last_watered, caretaker)** | ✅ Van: trees tábla (species, health_status, last_watered, adopted_by_user_id), tree_logs |
| **Open311 / FixMyStreet kompatibilitás** | ✅ Van: open311/v2 (discovery, services, requests), fms_reports + export_report |
| **Több hatóság / város** | ✅ Van: authorities, authority_users, reports.authority_id, gov user scope |

### Amit a spec kér és még nincs (vagy csak részben)

| Spec kérés | Komment |
|-----------|--------|
| **Civic Analytics modul** | Nincs dedikált statisztika modul; a gov oldal már húz adatot (reports_total, by_status, by_category), de nincs „issues per district”, „per month”, „avg resolution time”, export (JSON/CSV/Excel). |
| **AI report generator (maintenance / engagement / sustainability)** | A gov AI most egy általános „summary” és „esg” típust használ. Nincs `generate_ai_report(type, timeframe)` a három típussal és időablakkal. |
| **ESG dashboard külön modul, export PDF/Excel/API** | ESG blokk van a gov-on, de nincs külön „Urban ESG Dashboard” név, strukturált E/S/G metrikák és `generate_esg_report(year)` + export formátumok. |
| **Tree cadastre bővítés** | trees már jó; hiányzik: planting_date (van planting_year), age (van estimated_age), notes (nincs – tree_logs.note van), fa szerkesztés + fotó feltöltés fa szinten. |
| **Tree Layer színkód (zöld/sárga/piros)** | Térképen a fa réteg van, de a marker szín nem feltétlenül health_status alapján (good→green, fair→yellow, poor/critical→red). |
| **AI tree monitoring (fotó → health)** | Nincs: képfeltöltés → AI elemzés (leaf color, dryness, disease) → health_status javaslat. |
| **Öntözés ajánlás + értesítés** | Nincs: fajtánkénti öntözési ajánlás (pl. 3 naponta, 10 l), és „értesítés gondozónak”. |
| **Export & Open Data** | Nincs központi export: CSV, GeoJSON, Excel, JSON API bejelentésekhez, fa nyilvántartáshoz, ESG stathoz. |
| **Dashboard UI panelek** | Admin/Gov-on van stat, de nincs öt külön panel: City Health, Citizen Engagement, Urban Issues, Tree Registry, ESG Impact. |
| **Jövőbeli AI (predikció, hősziget, árvíz, zöldfedettség)** | Csak helykitöltő architektúra kell (pl. doc + interface/extension point). |

### Mit érdemes kiemelni

- **FixMyStreet stílus:** A bejelentések a CivicAI-ban keletkeznek; a **FixMyStreet bridge** (FMS modul) az `api/fms_bridge/export_report.php`-n keresztül küldi a kiválasztott bejelentést Open311 formátumban a külső FMS/Open311 végpontra. Tehát „FixMyStreet style” = saját térkép + bejelentés itt, opcionális szinkron FMS felé.
- **Open311 kompatibilitás:** Már van: `open311/v2/discovery.php`, `services.php`, `requests.php` (GET list, POST create). A `APP_JURISDICTION_ID` / FMS beállításokkal multi-city használatra is alkalmas.
- **SaaS / több önkormányzat:** `authorities` + `authority_users` + `reports.authority_id` már városonkénti scope-ot ad. A további moduloknál mindig `authority_id` (és opcionálisan `city`) szerint szűrünk, így skálázható marad.

---

## 2. Adatbázis / architektúra – ajánlott bővítések

- **reports:** Már van `category`, `status`, `authority_id`, `city`, `created_at`; a felbontási időhöz kell a `report_status_log` (solved/closed időpont) – ezt ki lehet számolni.
- **District:** A spec „issues per district”-et kér. Jelenleg nincs `reports.district` vagy `reports.suburb` konzisztens használata. Lehet: (1) `reports.suburb` vagy (2) külön `districts` tábla (authority_id, name) + `reports.district_id`. Kezdéshez elég a meglévő `suburb` vagy egy opcionális `district` szöveg mező.
- **Analytics cache (opcionális):** Nagy adatmennyiségnél lehet `analytics_cache` (authority_id, period_type, period_key, metrics_json, updated_at) a gyors dashboard-hoz; kezdetben élő SQL is elég.
- **Trees:** `planting_date` (DATE) opcionális kiegészítés a `planting_year` mellett; `notes` (TEXT) a trees táblában; fa mellé kép: `tree_logs.image_path` már van, vagy külön `tree_attachments`.
- **Tree species watering:** Új tábla pl. `tree_species_care` (species_name vagy id, watering_interval_days, watering_volume_liters, notes) – ajánlás és értesítéshez.
- **ESG:** A metrikák a meglévő adatokból számolhatók (reports, trees, users, report_status_log); külön ESG tábla csak ha éves snapshot-ot akarunk tárolni (pl. `esg_snapshots`: year, authority_id, metrics_json).

---

## 3. Milestone sor – ajánlott sorrend

A cél: **minimal change**, **moduláris bővítés**, és hogy minden épüljön a már meglévőre.

---

### M1 — Civic Analytics modul (CivicAI Analytics) ✅

**Cél:** Egy statisztika modul, ami a önkormányzati adatokból generál mérőszámokat és exportálható adatot.

**Megvalósítva:** `api/analytics.php` – issue statisztika (total, open, resolved, by_category, by_district, by_month, avg_resolution_days), citizen engagement (active_users, new_users_30d, reports_per_user, upvotes_total, upvotes_per_issue, participation_index), urban_maintenance (road, green, lighting, trash, drainage). Export: JSON (default), CSV (UTF-8 BOM, Excel-kompatibilis). Jogosultság: admin vagy gov user (scope: authority_id / city). Opcionális `date_from`, `date_to`. Gov dashboard és Admin: CivicAI Analytics kártya, Export JSON / CSV linkek.

- **Issue statisztika:** összes bejelentés, kategória szerint, (ha van) kerület/district szerint, havi bontás, nyitott vs lezárt, átlagos megoldási idő (report_status_log alapján).
- **Citizen engagement:** aktív felhasználók, új felhasználók, bejelentés/fő, like/upvote per bejelentés, egyszerű „részvételi” index.
- **Urban maintenance:** kategóriák szűrése (úthiba, illegális lerakás, világítás, park, csatorna) – a meglévő `reports.category`-ból.
- **Backend:** PHP függvények vagy egy `api/analytics.php` (scope: authority_id / city), SQL lekérdezések.
- **Export:** ugyanabból az adatból JSON, CSV, Excel-kompatibilis (pl. CSV UTF-8 BOM vagy egyszerű XLSX lib).

**Kimenet:** Analytics modul (pl. `api/analytics.php` + opcionális `admin/analytics.php` vagy gov aloldal), export formátumok dokumentálva.

---

### M2 — AI report generator (típus + időablak) ✅

**Cél:** AI által generált, típusos jelentések (karbantartás, részvétel, fenntarthatóság).

**Megvalósítva:** Bővített `api/gov_ai.php`: `action=generate`, `type`: summary | esg | maintenance | engagement | sustainability, `timeframe`: last_30_days | last_90_days | last_year. A maintenance/engagement/sustainability típusoknál a statisztika az időablak alapján szűrve. `AiPromptBuilder`: reportMaintenance(), reportEngagement(), reportSustainability(). Gov dashboard: „AI jelentés (típus + időszak)” kártya, típus és időszak választó, Generálás gomb.

---

### M3 — ESG dashboard bővítés (Urban ESG Dashboard) ✅

**Cél:** ESG struktúra E/S/G alapon, export és év szerinti jelentés.

**Megvalósítva:** `api/esg_export.php` (year, format=json|csv), E/S/G metrikák évre; Gov „Urban ESG Dashboard" kártya + export; Admin ESG link.

- **Environment:** pl. fák száma, új ültetések, becsült CO2, zöldfelület indikátor, illegális hulladék csökkenés (report kategóriákból).
- **Social:** aktív polgárok, részvétel, önkéntes fa gondozás (adopt/watering), bejelentések.
- **Governance:** átlagos válaszidő, megoldási arány, átláthatóság indikátor, reagálási sebesség.
- **Függvény:** `generate_esg_report(year)` – adott év adatai, strukturált E/S/G metrikák.
- **Export:** PDF (már van gov-nál sablon), Excel tábla, JSON API (pl. `api/esg_export.php?year=2025&format=json`).

**Kimenet:** Gov/Admin „Urban ESG Dashboard” név, E/S/G blokkok, export gombok (PDF/Excel/JSON).

---

### M4 — Tree cadastre kiegészítés (Smart Tree Registry)

**Cél:** Fa nyilvántartás teljesebb és szerkeszthető.

- **Mezők:** tree_id, lat, lng, species, planting_date (vagy planting_year), age (vagy estimated_age), health_status, caretaker_user_id (vagy adopted_by_user_id), last_watering, notes. Ahol hiányzik (pl. notes), bővítés.
- **Műveletek:** térképről új fa (van), fa adat szerkesztése (admin/gov vagy tulajdonos), fotó feltöltés fához (tree_logs vagy tree_attachments).
- **„Adopt a tree”:** már van (tree_adopt); csak a megnevezés és a felület konzisztenciája.

**Kimenet:** Trees tábla + API bővítés (pl. tree_edit, tree_photo_upload), admin/gov fa szerkesztő (opcionális egyszerű UI).

---

### M5 — Tree map layer színkód (health) ✅

**Cél:** Térképen a fa réteg szín szerint jelzi az állapotot.

**Megvalósítva:** `treeIcon()`: health_status poor/critical/unhealthy vagy risk_level high/medium → piros; fair/needs_attention vagy öntözés késő → sárga; egyébként zöld (healthy). Popup: species, cím, életkor (estimated_age vagy év planting_year alapján), állapot, kockázat, öntözés/gondozó (meglévő actionsHtml). Lang: tree.age.

---

### M6 — AI tree monitoring (fotó → egészség)

**Cél:** Fotó feltöltés → AI elemzés → egyszerű egészségi javaslat.

- **Bemenet:** fa fotó (feltöltés).
- **AI elemzés:** levél szín, szárazság, látható betegség jelek (prompt + vision API, ahol van).
- **Kimenet:** egyszerű kategória: healthy | dry | disease_suspected; opcionálisan javasolt health_status frissítés (tree_logs vagy trees).
- **Provider:** Meglévő vision (Mistral/OpenAI) vagy később Gemini; rate limit (image_analysis) marad.

**Kimenet:** pl. `api/tree_health_analyze.php` + fa résznél „Egészség elemzés” fotó feltöltéssel.

---

### M7 — Tree watering ajánlás és értesítés

**Cél:** Fajtánkénti öntözési ajánlás és gondozó értesítés.

- **Adatmodell:** pl. `tree_species_care`: species (név vagy referencia), watering_interval_days, watering_volume_liters, megjegyzés.
- **Logika:** fa `last_watered` + fajta ajánlás → „öntözendő” lista; gondozó (adopted_by_user_id) számára értesítés.
- **Értesítés:** kezdetben e-mail vagy dashboard üzenet („Ez a fa öntözést igényel. Ajánlott: 10 l.”); később push opcionális.

**Kimenet:** species care tábla + API/várólista (pl. „trees needing water”), értesítési pont (pl. cron + mail vagy gov „Öntözendő fák” blokk).

---

### M8 — Export & Open Data

**Cél:** Központosított export több adathalmazra és formátumra.

- **Formátumok:** CSV, GeoJSON, Excel, JSON API.
- **Adathalmazok:** bejelentések (reports + opcionális geom), fa nyilvántartás (trees), ESG statisztika (M3).
- **Jogosultság:** admin mindent; gov csak saját authority scope; nyilvános API opcionális (read-only, rate limit).

**Kimenet:** pl. `api/export.php` vagy külön végpontok (reports_export, trees_export, esg_export) query param: format=csv|geojson|xlsx|json.

---

### M9 — Dashboard UI panelek

**Cél:** Admin/Gov dashboard egyértelmű panelekben.

- **Panelek:** City Health Overview, Citizen Engagement, Urban Issues, Tree Registry, ESG Impact.
- **Megvalósítás:** meglévő AdminLTE + Bootstrap; minden panel egy blokk (kártya), adat forrása M1/M3 és meglévő API-k.
- **Nem írjuk át az egész felületet** – bővítjük a meglévő dashboardot ezekkel a blokkokkal.

**Kimenet:** admin/index.php és/vagy gov/index.php bővítve a 5 panel résszel (adat lekérés meglévő vagy M1/M3 API-kból).

---

### M10 — Jövőbeli AI funkciók (placeholder)

**Cél:** Későbbi bővítés lehetővé tétele anélkül, hogy most megvalósítanánk.

- **Lehetséges témák:** karbantartási tervezés predikció, hősziget érzékelés, árvíz kockázat, zöldfedettség elemzés, digitális iker.
- **Teendő:** rövid architektúra doc (pl. `docs/FUTURE_AI_FEATURES.md`) – milyen adat kellene, milyen API/interface (pl. „prediction service”), hogyan illeszkedne a jelenlegi AiRouter és a statisztika modulhoz. Nincs új tábla vagy production kód, csak terv.

**Kimenet:** egy dokumentum a későbbi bővítéshez.

---

## 4. Összefoglaló tábla

| # | Milestone | Rövid cél | Függőség |
|---|-----------|-----------|-----------|
| M1 | Civic Analytics | Statisztikák + export (JSON/CSV/Excel) | - |
| M2 | AI report generator | maintenance / engagement / sustainability + timeframe | M1, meglévő AI |
| M3 | ESG dashboard | E/S/G metrikák, generate_esg_report(year), PDF/Excel/API | M1 |
| M4 | Tree cadastre bővítés | Szerkesztés, notes, fotó fához | Meglévő trees |
| M5 | Tree layer szín | Zöld/sárga/piros health alapján | Meglévő réteg |
| M6 | AI tree monitoring | Fotó → health javaslat | Meglévő AI vision |
| M7 | Tree watering ajánlás + értesítés | Fajta ajánlás, értesítés gondozónak | M4 (species care) |
| M8 | Export & Open Data | CSV, GeoJSON, Excel, JSON API | M1, M3, trees API |
| M9 | Dashboard UI panelek | 5 panel (City Health, Engagement, Issues, Trees, ESG) | M1, M3 |
| M10 | Jövőbeli AI | Doc + interface tervezet | - |

---

## 5. FixMyStreet / Open311 / multi-municipality (rövid)

- **FixMyStreet stílus:** Bejelentés a CivicAI térképen és űrlapon történik; a FMS modul (`api/fms_bridge/export_report.php`) kiválasztott reportot Open311-ként továbbítja a konfigurált FMS/Open311 szolgáltatásnak. Tehát a platform FixMyStreet-kompatibilis bridge-ként működik.
- **Open311:** `open311/v2/` már biztosít discovery-t, service listát és service requests (GET/POST). Új végpontok csak akkor kellenek, ha más Open311 részt (pl. service definition részletesen) kell támogatni.
- **Több önkormányzat (SaaS):** Minden új modul (analytics, ESG, export) legyen **authority_id** (és esetleg city) alapú szűrhető, hogy városonként külön adat és jogosultság legyen. A meglévő authorities + authority_users + reports.authority_id ezt már támogatja.

---

Ezt a milestone sort használhatod a fejlesztés lépésről-lépésre történő vezetéséhez. Ha kész vagy az első deliverable-lal (pl. M1), jelezd, és onnan folytatjuk a következővel.

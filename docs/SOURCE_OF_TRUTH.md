# Köz.Tér / CivicAI – Source of Truth

## 1. Mi a jelenlegi SOURCE OF TRUTH?

- **Alkalmazás belépési pont:** `index.php` (térkép) + `config.php` + `util.php` (közös bootstrap, session, auth helperek).
- **Adatbázis:** MySQL/MariaDB; a tényleges schema a `kataia_civicai` export + széttagolt SQL migrációk (`sql/2026-*.sql`). Táblák referencia: **sql/00_baseline_schema.md**; a 2026-03 … 2026-11 migrációk összességében adja a célállapotot.
- **Auth:**
  - **Admin:** `admin/login.php` → először users tábla (email + jelszó; role admin/superadmin), session `admin_logged_in`; ha nincs ilyen user, config `ADMIN_USER`/`ADMIN_PASS` (üzemeltetési fallback).
  - **User:** `user/login.php` → `users` tábla (email + pass_hash), session `user_id` + `user_role`; ha role admin/superadmin, egy belépéssel elérhető az admin felület is.
- **API belépés:** Minden `/api/*.php` a `util.php`-n keresztül (közös error/exception handler, `json_response`). Jogosultság: `require_admin()`, `require_user()`, vagy nyilvános.
- **Frontend:** Egy fő térkép shell: `index.php` + `assets/app.js` (Leaflet, markerek, bejelentés modál, layerek). Admin UI: `admin/index.php` + `admin/admin.js` (AdminLTE alapú). User oldalak: `user/*.php`, `leaderboard.php`, `case.php`.

## 2. Mely fájlok / modulok tűnnek aktívnak?

| Terület | Aktív fájlok / modulok |
|--------|-------------------------|
| Core | `index.php`, `config.php`, `db.php`, `util.php`, `case.php`, `leaderboard.php` |
| Reporting | `api/report_create.php`, `api/reports_list.php`, `api/reports_nearby.php`, `api/report_set_status.php`, `api/report_upload.php`, `api/report_attachments.php`, `api/report_status_log.php`, `api/report_like.php` |
| User | `user/login.php`, `user/register.php`, `user/logout.php`, `user/settings.php`, `user/my.php`, `user/profile.php`, `user/friends.php`, `user/report.php`, `user/verify.php` |
| Admin | `admin/index.php`, `admin/login.php`, `admin/logout.php`, `admin/admin.js`, `api/admin_*.php` (reports: hatóság szűrés, users, authorities, layers, stats, action) |
| Gov | `gov/index.php` (közigazgatási dashboard: statisztika a városhoz tartozó bejelentésekről, státusz/kategória megoszlás, lista státusz szűréssel, státuszváltás) |
| Community | `api/civil_event_create.php`, `api/civil_events_list.php`, `api/facility_save.php`, `api/facilities_list.php` |
| Social | `api/friend_request.php`, `api/friends_list.php`, `api/leaderboard.php`, `api/report_like.php` |
| Open311 | `open311/v2/discovery.php`, `open311/v2/services.php`, `open311/v2/service_definition.php`, `open311/v2/requests.php` (saját Open311 API – bejövő kérések → reports) |
| FMS bridge | `api/fms_bridge/report_create.php` (külső FMS felé küldés), `api/fms_bridge/sync.php` (státusz visszahúzás), `api/fms_bridge/export_report.php` (report export FMS-be, gov/admin) |
| Layers | `api/layers_public.php`, `api/admin_layers.php` |
| Üzemeltetés | `api/health.php` (GET, nincs auth; DB + config_review) |
| Assets | `assets/style.css`, `assets/app.js`, `assets/admin.css` |

## 3. Mely részek tűnnek régi, párhuzamos, vendorizált vagy tisztítandó örökségnek?

- **dashboard/ mappa:** Teljes AdminLTE (npm, src, dist). Az admin UI jelenleg **nem** a dashboard buildjét használja közvetlenül, hanem az `admin/index.php` saját HTML + `admin/admin.js` + `assets/admin.css`. A dashboard/ inkább vendor/ referencia vagy jövőbeli átállás – **duplikált vagy félbehagyott**.
- **Admin belépés:** Egységesítve: admin/login először users táblából (email + jelszó, admin/superadmin); config belépés csak fallback. **Megvalósítva.**
- **Migrációk széttagoltsága:** 2026-03, 2026-04, 2026-05, 2026-07, 2026-08, 2026-09, 2026-10, plusz `SQL_attachments.sql` – nincs egyetlen „canonical schema” fájl, és a kataia_civicai export régebbi schema-t használ (pl. authorities: email, active; users.role ENUM korlátozott). **Tisztítandó / konszolidálandó.**
- **FMS bridge vs lokális report:** A fő bejelentés flow (`api/report_create.php`) csak lokálisan ment – nem hívja az fms_bridge-ot. Az fms_bridge/report_create egy **külön** endpoint (külső FMS felé küldés). A sync visszahúzza a külső státuszt. **Nem duplikált, de a narratíva („egy kattintás és megy a hatósághoz”) csak akkor teljes, ha a lokális report utólag szinkronizálódik vagy küldésre kerül – jelenleg ez opcionális.**
- **design/ mappa:** Statikus HTML/CSS/MD (design-system, gamification, mobile, homepage). **Referencia / terv** – nem futó kód, de a UI prioritásokhoz és Podim narratívához hasznos.
- **country-wide / Europe kommentek:** A config és a geocoder jelenleg HU-specifikus (Orosháza bbox a report_create-ben). Nincs tényleges multi-country logika – csak kommentek vagy elnevezések maradtak. **Elavult irány vagy előkészítés.**

## 4. Mely részeket kell megtartani, melyikeket refaktorálni, melyeket kivenni?

- **Megtartani (core):** index.php, util.php, config.php, db.php, report_create + reports_list + reports_nearby, user login/register/settings/my, admin index + admin API-k, gov index, civil_event + facilities API-k, open311/v2 (saját API), layers_public, leaderboard, badges/XP (util + API-k), friend/like API-k, assets (style, app.js, admin.css).
- **Refaktorálni:** Auth egységesítés (admin: config vs users tábla); migrációk → egy baseline + inkrementális; authority routing (find_authority_for_report) és régi vs új schema kezelés dokumentálása.
- **Kivenni / elrejteni (célzottan):** Semmit azonnal törölni nem kell. A dashboard/ mappát lehet „vendor” vagy „reference” alá rendezni, vagy később admin UI-t átállítani rá – de jelenleg ne távolítsuk el, csak nevezzük meg örökségnek.
- **Opcionális / később:** FMS bridge (report_create → külső FMS) egyértelmű flow-ba foglalása; multi-city / multi-country; AI réteg.

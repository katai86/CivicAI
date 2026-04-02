# MILESTONE 1 – Teljes audit és termékmapa

## 1. Megértésem a jelenlegi állapotról

A Köz.Tér / CivicAI egy többrétegű civic-tech alkalmazás: térképes bejelentés, szerepkörök (user, civiluser, communityuser, govuser, admin), gamification (XP, badge, leaderboard), barátok/like, civil események, közületi létesítmények (facilities), hatósági dashboard, admin dashboard, saját Open311 API és opcionális FixMyStreet bridge. A kód plain PHP + Leaflet + AdminLTE elemek, a schema több migrációból áll, az admin belépés részben config, részben users tábla.

## 2. Audit / problémák

- **Két auth pálya:** admin/login config jelszóval + session `admin_logged_in`; user/login users táblával. require_admin() mindkettőt elfogadja (admin_logged_in VAGY user_role admin/superadmin).
- **Migrációk:** Nincs egy baseline; 2026-03–2026-10 és SQL_attachments szétszórva; a production export (kataia_civicai) régebbi oszlopneveket használ (authorities.email/active, users.role ENUM).
- **Dashboard mappa:** AdminLTE teljes forrás – az admin UI azonban saját index.php + admin.js + admin.css. A dashboard/ nem része a futó admin felületnek → vendor/reference státusz.
- **FMS vs lokális:** A fő bejelentés csak lokális. Külső FMS felé küldés és visszahúzás külön endpointokon van (fms_bridge) és opcionális.
- **Design vs futó UI:** design/ mappa tervrajzok, a tényleges UI (style.css, app.js) nem mindenhol követi a design-system.md dark/glassmorphism irányt konzisztensen.

## 3. Javasolt megoldás (röviden)

- Source of truth dokumentálva (docs/SOURCE_OF_TRUTH.md).
- Egyértelmű modul- és user-journey térkép (lásd alább).
- Migrációk és auth konszolidálása későbbi milestone-okra (M2, M3).
- Semmit nem törlünk azonnal; elavultat megnevezünk és prioritizálunk (keep/cut/later).

---

# Kimenetek

## 1. Modul térkép

| Modul | Fájlok / entry | Függőség | Megjegyzés |
|-------|----------------|----------|------------|
| Core / bootstrap | config.php, db.php, util.php | - | Session, auth, error, geocode, XP helperek |
| Public map | index.php, assets/app.js, assets/style.css | Core, API | Leaflet, markerek, layerek, kereső |
| Reporting engine | api/report_create.php, report_set_status, report_upload, report_attachments, report_status_log | Core, util (find_authority, XP) | Lokális mentés, fallback schema |
| Report listing | api/reports_list.php, reports_nearby.php, layers_public.php | Core | Nyilvános / szűrt listák |
| User auth & profile | user/login, register, logout, settings, my, profile, verify | Core, api (my_reports, avatar, friends) | Session, role |
| Social | api/friend_request, friends_list, report_like | Core, users | Barátkérés, like |
| Gamification | util (XP, badge, streak), api/leaderboard, leaderboard.php | Core, users, reports | XP log, badge, toplista |
| Civil events | api/civil_event_create, civil_events_list | Core, users | civiluser only |
| Facilities | api/facility_save, facilities_list | Core, users | communityuser only |
| Authority routing | util (find_authority_for_report), admin_authorities | authorities, authority_contacts (opcionális) | Lokális routing, régi schema fallback |
| Gov workflow | gov/index.php | Core, reports, authority_users | Státuszváltás, saját hatóságok |
| Admin operations | admin/index.php, login, logout, api/admin_* | Core, reports, users, authorities, layers | AdminLTE jellegű UI |
| Open311 (saját API) | open311/v2/* | Core, reports | Bejövő kérések → reports |
| FMS bridge | api/fms_bridge/report_create, sync | Core, FMS config | Külső FMS küldés + státusz sync |
| Layers | api/admin_layers, layers_public, map_layer_points | Core | Közösségi rétegek |

## 2. User role térkép

| Role | Belépés | Fő képességek | Korlátozások |
|------|---------|----------------|--------------|
| Vendég | - | Térkép, bejelentés (anonim/opcionális regisztráció), toplista megtekintés | Nincs profil, barát, like (ahol auth kell) |
| user | user/login | Bejelentés (nem civil_event), like, barátok, profil, saját ügyek, toplista | Nem hoz létre civil eseményt, nem facility, nem gov |
| civiluser | user/login | Civil esemény létrehozás, profil, barátok | Nem küldhet normál hibabejelentést |
| communityuser | user/login | Egy facility (buborék) szerkesztése, profil | Nem küldhet bejelentést, nem civil esemény |
| govuser | user/login | Gov dashboard, saját hatóság ügyeinek státuszváltása | Csak authority_users-hoz rendelt hatóságok |
| admin | user/login VAGY admin/login | Admin dashboard, reports/users/authorities/layers kezelés | - |
| superadmin | user/login VAGY admin/login | Mint admin + pl. gov regisztráció engedélyezése | - |

## 3. Adatfolyam térkép

- **Bejelentés (nyilvános):** Böngésző → report_create (POST) → validáció, rate limit, duplikáció, reverse geocode, authority_id (find_authority_for_report) → reports INSERT (több fallback schema) → XP/badge (ha user_id) → JSON ok.
- **Bejelentés (Open311 bejövő):** Külső kliens → open311/v2/requests.php POST → reports INSERT (authority_id, service_code) → service_request_id vissza.
- **FMS kifelé:** (Opcionális) Kliens → api/fms_bridge/report_create → külső FMS Open311 API → külső id válasz. (A lokális report_create NEM hívja ezt.)
- **FMS vissza:** Cron/admin → api/fms_bridge/sync → külső FMS requests.json → fms_reports egyeztetés → reports.status + report_status_log frissítés.
- **Státuszváltás:** Gov/Admin → report_set_status vagy gov/index.php POST → reports.status + report_status_log; értesítés (email) opcionális.
- **XP/Badge:** report_create, report_set_status (approved/solved), report_upload, register → add_user_xp / check_* → user_xp_log, users.total_xp/level, user_badges.

## 4. Public user journey

1. Landing: index.php (térkép).
2. Keresés: cím mező → Nominatim → térkép középre.
3. Bejelentés: pont a térképen / „Bejelentés” → modál: kategória, leírás, opcionális cím, anonim/regisztrált, értesítés, GDPR.
4. Küldés → sikeres üzenet vagy duplikátum/rate limit hiba.
5. Toplista, jelmagyarázat, layerek (civil, facilities, map_layer_points) böngészése.
6. (Ha belépett) Saját ügyeim, Barátok, Beállítások, Közigazgatási (ha govuser).

## 5. Admin journey

1. admin/login.php (config user/pass) VAGY user belépés admin/superadmin role-lal.
2. admin/index.php: lapok – Bejelentések (szűrés, státusz), Felhasználók (szerepkör, aktivitás), Hatóságok (CRUD, contacts, user assignment), Layerek.
3. Műveletek: report státusz, user role/tiltás, authority create/delete, layer/point CRUD.

## 6. Gov journey

1. Belépés govuser (vagy admin/superadmin).
2. gov/index.php: csak az authority_users-hoz rendelt hatóságok ügyei.
3. Státuszváltás (pending, approved, in_progress, solved, stb.) + megjegyzés; opcionális email értesítés a bejelentőnek.

## 7. Civil user journey

1. Belépés civiluser.
2. Civil esemény létrehozás (API / jövőbeli UI): title, description, start/end date, lat/lng, address.
3. Nem küldhet normál hibabejelentést (út, szemét stb.) – 403.

## 8. Community user journey

1. Belépés communityuser.
2. Egy facility (háziorvos, gyógyszertár stb.) létrehozása/szerkesztése (profil + egy „buborék”).
3. Nem küldhet hibabejelentést – 403.

## 9. Integrációs pontok listája

| Pont | Irány | Protokoll | Megjegyzés |
|------|-------|-----------|------------|
| Nominatim | Kifelé | HTTP GET | Reverse geocode, cím keresés |
| Saját Open311 (requests.php) | Bejövő | POST/GET | Kérések küldése felénk, listázás |
| FixMyStreet (fms_bridge) | Kifelé + Bejövő | Open311-style API | Küldés (report_create) + státusz sync (sync.php) |
| (Jövő) E-mail | Kifelé | SMTP / sendmail | Értesítések, verify link |

## 10. Elavult / párhuzamos / veszélyes pontok listája

| Pont | Típus | Javaslat |
|------|--------|----------|
| admin/login config jelszó | Párhuzamos auth | Hosszú távon egyesíteni users táblával (admin role) vagy világosan dokumentálni „demo admin”. |
| dashboard/ mappa | Vendor / nem futó | Megtartani referenciaként; ne építeni rá azonnal, vagy később admin UI átállítás. |
| Széttagolt SQL migrációk | Tisztítandó | M3: egy baseline schema + inkrementális migrációk. |
| authorities régi schema (email, active) | Örökség | Kód már kezeli (fallback); 2026-10 opcionális bővítés. |
| FMS nincs a fő flow-ban | Üzleti döntés | Demo narratívában tisztázni: lokális first, FMS opcionális export/sync. |
| Orosháza-specifikus bbox | HU-specifikus | Szándékos; multi-city későbbi phase. |

---

## Fő narratíva most vs Podim-kompatibilis

**Jelenlegi fő narratíva:** Hibabejelentő térkép + kategóriák, regisztráció, XP/badge/leaderboard, barátok, civil esemény és közületi pontok, hatósági és admin dashboard, saját Open311 API; opcionálisan FixMyStreet bridge.

**Podim-kompatibilis fő narratíva (ne csak „hibabejelentő app”):**

- **Civic engagement platform** – bejelentés, civil esemény, közületi láthatóság, barátok, like, toplista egy helyen.
- **AI-assisted urban feedback system** – (roadmap) kategória/authority javaslat, duplikáció valószínűség, elemzés.
- **Community + authority collaboration layer** – lakosság bejelent, hatóság ügykezel, státusz és értesítés.
- **Gamified local participation engine** – XP, szintek, badge-ek, streak, toplista.
- **Civil visibility platform** – civil események és közületi létesítmények a térképen.
- **Local governance support system** – gov dashboard, authority routing, Open311 kompatibilitás, (jövőben) multi-city.

---

## Mit módosítottam (Milestone 1)

- **Létrehoztam:** `docs/SOURCE_OF_TRUTH.md` és `docs/MILESTONE_1_AUDIT_AND_PRODUCT_MAP.md`.
- **Kódot nem változtattam** – csak audit és dokumentum.

## Mi a következő milestone

**Milestone 2 – Architektúra letisztítása:** Javasolt mappaszerkezet, modulhatárok, mi maradjon plain PHP, mi legyen service/API, dashboard vendor kezelés; terv szinten, nagy fájlmozgatás nélkül.

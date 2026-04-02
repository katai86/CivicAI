# MILESTONE 2 – Architektúra letisztítása

## 1. Megértésem a jelenlegi állapotról

A kód gyökérben laposabb struktúra: api/, user/, admin/, gov/, open311/, assets/, design/, dashboard/, sql/. Nincs szigorú rétegzés: sok API közvetlenül db()+util(), a üzleti logika részben util.php nagy függvényeiben (XP, auth, geocode, authority routing).

## 2. Audit / problémák

- Üzleti logika szétszórva (report_create nagy fájl, util.php túlnőtt).
- Nincs külön „service” réteg – minden API-ban vagy util-ban van.
- dashboard/ = teljes AdminLTE vendor, de a futó admin UI nem használja a buildet.

## 3. Javasolt megoldás (terv – fizikai áthelyezés később)

### Cél alrendszerek

| Alrendszer | Leírás | Jelenlegi hely | Javasolt (logikai) |
|------------|--------|----------------|---------------------|
| A. Public map & discovery | Térkép, kereső, layerek, markerek | index.php, app.js, layers_public | Marad; app.js modulárisabb blokkokra bontható később |
| B. Reporting engine | Create, list, nearby, status, attachments | api/report_*.php, util (find_authority, XP) | Marad api/; find_authority + report XP logika később kivezethető service-szerűbe |
| C. Community / social / gamification | Like, barátok, leaderboard, XP, badge | api/, util.php | Marad; XP/badge helperek maradhatnak util, vagy később services/ReportXpService.php stb. |
| D. Civil events & civic orgs | Civil esemény CRUD, list | api/civil_event_*.php | Marad api/ |
| E. Community facilities | Facility save/list | api/facility_*.php | Marad api/ |
| F. Authority routing & gov workflow | find_authority, authorities, gov dashboard | util, api/admin_authorities, gov/ | Marad; find_authority később AuthorityRouter vagy hasonló |
| G. Admin operations | Admin UI, admin API-k | admin/, api/admin_* | Marad |
| H. Open311 / FMS interoperability | Saját Open311, fms_bridge | open311/, api/fms_bridge/ | Marad; dokumentált (M7) |
| I. Analytics / AI-ready | (Jövő) event log, aggregátumok | - | M8 terv; új táblák/endpointok később |
| J. Demo / Podim showcase | Landing, demo flow | index.php, később opcionális demo/ vagy flag | M5; lehet csak route/query param + egy dedikált landing snippet |

### Javasolt mappaszerkezet (célszerkezet, nagy lépések nélkül)

```
CivicAI/
├── config.php
├── db.php
├── util.php              # Közös bootstrap, auth, log_error, json_response, session; XP/badge/geocode maradhat itt rövid távon
├── index.php             # Public map entry
├── case.php
├── leaderboard.php
├── api/
│   ├── report_*.php      # Reporting engine
│   ├── admin_*.php       # Admin API-k
│   ├── civil_event_*.php
│   ├── facility_*.php
│   ├── friend_*.php, report_like.php, leaderboard.php
│   ├── layers_public.php
│   └── fms_bridge/       # FMS optional
├── open311/v2/           # Saját Open311 API
├── user/                 # User-facing oldalak
├── admin/                # Admin UI (PHP + JS)
├── gov/                  # Gov dashboard
├── assets/               # CSS, JS, képek
├── design/               # Tervrajzok (nem futó kód)
├── dashboard/            # VENDOR: AdminLTE forrás (reference) – ne töröljük, de ne építsük rá azonnal
├── sql/                  # Migrációk (M3 konszolidálás)
├── docs/                 # Audit, tervdokumentumok
└── uploads/
```

- **Mi maradjon plain PHP-ban:** Minden jelenlegi entry (index, user/*, admin/*, gov/*) és api/*. A logika maradhat a fájlokban; a túl nagy util.php-ból később lehet kiszakítani „service” jellegű include-okat (pl. `require_once __DIR__.'/../services/ReportXp.php'`), de nem kötelező azonnal.
- **Mi legyen service/helper:** util.php = helper (auth, session, json_response, log_error, safe_str, app_url, geocode, find_authority, XP/badge). Ha növekszik, lehet: `helpers/Auth.php`, `helpers/ReportXp.php` – opcionális refactor.
- **Mi legyen API endpoint:** Minden jelenlegi api/*.php marad REST-szerű endpoint (POST/GET, JSON). Nincs külön router – minden fájl saját method check.
- **Mi legyen később SPA vagy külön frontend:** A fő térkép (index.php + app.js) maradhat server-rendered shell + vanilla JS/Leaflet. Admin UI maradhat PHP + admin.js. Később: ha szükséges, a térkép vagy az admin lehet SPA (pl. Vue/React), de nem követelmény a Podim demóhoz.

### Dashboard vendor

- **Javaslat:** A `dashboard/` mappát **ne töröljük**, de kezeljük **vendor / reference**-ként. Az admin felület továbbra is `admin/index.php` + `admin/admin.js` + `assets/admin.css`. Ha később teljes AdminLTE buildet akarunk beépíteni (asset pipeline), akkor a dashboard/ build kimenetét (pl. dist/) lehet admin-ra mutatni; addig nem kötelező és nem mozgatunk fájlokat.

## 4. Konkrét teendők (M2 – terv szinten)

- Nincs azonnali fájlmozgatás.
- docs/MILESTONE_2_ARCHITECTURE.md létrehozva (ez a fájl).
- Következő: M3 adatmodell és migráció konszolidáció.

## 5. Rövid magyarázat laikus nyelven

A rendszert logikai „táblákra” bontottuk (térkép, bejelentés, közösség, civil, közület, hatóság, admin, Open311, stb.). A fájlok helye most nem változik; csak azt terveztük meg, mi melyik alrendszerhez tartozik, és hogy a dashboard mappa csak referenciaként marad. A későbbi nagy tisztítás vagy service réteg erre épülhet.

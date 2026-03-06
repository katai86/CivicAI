# Milestone-ok állapota – ellenőrzés

Utolsó átnézés: a Phase 1–5 és a docs alapján összeállított állapot. A **Kész** = implementálva és/vagy dokumentálva; **Opcionális / később** = a terv szerint nem kötelező most.

---

## PHASE 1 – Demo stabilizálás

| Feladat | Állapot | Megjegyzés |
|---------|---------|------------|
| Demo adatok (1–2 report, civil event, facility) | Kész | sql/demo_seed.sql: 2 bejelentés, 1 civil esemény, 1 facility (user_id=1). Csak demo/teszt DB-n futtatandó. |
| Sikeres küldés üzenet, üres állapotok (M6) | Kész | report_create visszajelzés, üres listák kezelve. |
| Gov dashboard authority_users nélkül → üres lista, ne 500 | Kész | gov/index.php try/catch, üres authorities. |
| Regisztráció/belépés minden szerepkörrel (user, civil, community, gov ha engedélyezve) | Kész | user/register.php, GOV_REGISTRATION_ENABLED, 2026-09 role. |
| Migráció sorrend (00_README_MIGRATIONS.md) | Kész | 2026-03 … 2026-11, 00_baseline_schema.md. |
| Hiányzó tábla → try/catch (civil_events, facilities) | Kész | API-k fallback-kel. |

**Összegzés:** Phase 1 kész. Demo script (seed) opcionális, M5 lépések dokumentálva.

---

## PHASE 2 – Product clean-up

| Feladat | Állapot | Megjegyzés |
|---------|---------|------------|
| Auth: admin belépés egyesítése (users tábla + config fallback) | Kész | admin/login.php, user/login.php. |
| Baseline schema doc + migráció sorrend | Kész | sql/00_baseline_schema.md, 00_README_MIGRATIONS.md. |
| Util: XP/geocode/authority kiszakítása service-be | Kész (rész) | services/XpBadge.php; geocode/authority util-ban marad (opcionális további kiszakítás). |
| Dashboard README „vendor/reference” | Kész | dashboard/README.md. |
| SOURCE_OF_TRUTH, M2/M3 docok | Kész | docs/SOURCE_OF_TRUTH.md, MILESTONE_2, MILESTONE_3. |

**Összegzés:** Phase 2 kész.

---

## PHASE 3 – Interoperability

| Feladat | Állapot | Megjegyzés |
|---------|---------|------------|
| Open311 API dokumentálása (M7) + nyilvános API docs oldal | Kész | docs/MILESTONE_7, api-docs.php. |
| FMS: „Küldés a külső rendszerbe” (Export to FMS gomb, fms_reports) | Kész | api/fms_bridge/export_report.php (gov/admin). |
| Státusz sync (sync.php) cron dokumentálása | Kész | docs/OPERATIONS.md. |

**Összegzés:** Phase 3 kész.

---

## PHASE 4 – AI-assisted civic layer

| Feladat | Állapot | Megjegyzés |
|---------|---------|------------|
| Kategória javaslat (szabályalapú) a bejelentés űrlapon | Kész | api/suggest_category.php, app.js „Kiválasztom”. |
| Duplikátum figyelmeztetés: hasonló 200 m-en belül (nem blokkoló) | Kész | app.js: reports_nearby radius=200, tájékoztató szöveg. |
| Admin/gov: statisztika blokk (COUNT category, status) | Kész | admin_stats.php + admin UI; gov/index.php statisztika. |
| Opcionális: rövid összefoglaló (description N karakter) admin listában | Kész | Admin listában a leírás 120 karakterre vágva, tooltip-ben a teljes szöveg; Export FMS gomb a sorban. |

**Összegzés:** Phase 4 kötelező és opcionális részei is kész (admin listában rövid leírás + Export FMS gomb).

---

## PHASE 5 – Multi-city / commercial scalability

| Feladat | Állapot | Megjegyzés |
|---------|---------|------------|
| Bbox/terület konfigból (ne hardcoded Orosháza) | Kész | MAP_CENTER_LAT, MAP_CENTER_LNG, MAP_ZOOM (config, index, admin). |
| Open311 jurisdiction_id több városra | Kész | APP_JURISDICTION_ID (env), discovery visszaadja jurisdiction_id-t; api-docs és OPERATIONS dokumentálja. |
| Admin: város/hatóság szűrés (multi-tenant) | Kész | admin_reports.php authority_id, admin UI hatóság szűrő. |

**Összegzés:** Phase 5 kötelező és quick win részei kész. jurisdiction_id a saját Open311-nél opcionális.

---

## Egyéb ellenőrzött elemek

| Elem | Állapot |
|------|--------|
| api/health.php | Létezik, GET, db + config_review. |
| api-docs.php | Létezik, Open311 discovery/services/requests. |
| api/suggest_category.php | Létezik, szabályalapú. |
| services/XpBadge.php | Létezik, util.php betölti. |
| sql/00_baseline_schema.md | Létezik. |
| sql/2026-11-authority-users-only.sql | Létezik. |
| docs/OPERATIONS.md | Létezik, health, FMS sync, map config. |
| Gov dashboard statisztika + státusz szűrés | Kész. |
| authority_users 503 javítás (migráció + doc) | Kész. |

---

## Összefoglaló

- **Phase 1–5 kötelező és quick win feladatai:** implementálva vagy dokumentálva.
- **Opcionális elemek megvalósítva:**  
  - Demo seed: sql/demo_seed.sql.  
  - Admin listában leírás rövid összefoglaló (120 karakter) + Export FMS gomb.  
  - Open311 discovery jurisdiction_id (APP_JURISDICTION_ID).
- **Dokumentáció:** SOURCE_OF_TRUTH és docs/README frissítve (baseline_schema, 2026-11).

A milestone-ok szerint a szükséges fejlesztések és javítások **készen vannak**; a fent jelzett opcionális elemek a terv szerint későbbre vagy „nice-to-have”-ra maradnak.

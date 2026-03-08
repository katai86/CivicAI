# Milestone-ok állapota – ellenőrzés

Utolsó átnézés: a Phase 1–5 és a docs alapján összeállított állapot. A **Kész** = implementálva és/vagy dokumentálva; **Opcionális / később** = a terv szerint nem kötelező most.

---

## Mindegyik milestone kész van-e? (M1–M10)

| Milestone | Doc | Kódbázis / deliverable | Állapot |
|-----------|-----|------------------------|---------|
| **M1** Audit & product map | MILESTONE_1_AUDIT_AND_PRODUCT_MAP.md | API-k, user flows, SOURCE_OF_TRUTH – megegyezik | Kész |
| **M2** Architektúra | MILESTONE_2_ARCHITECTURE.md | Auth, admin, util, migráció sorrend | Kész |
| **M3** Adatmodell, migrációk | MILESTONE_3_DATA_MODEL_AND_MIGRATIONS.md | 00_baseline_schema.md, 00_README_MIGRATIONS.md, 2026-03…2026-11 | Kész |
| **M4** User flows | MILESTONE_4_USER_FLOWS.md | Regisztráció, report_create, gov, admin flow – implementálva | Kész |
| **M5** Podim demo flow | MILESTONE_5_PODIM_DEMO_FLOW.md | demo_seed.sql, sikeres küldés, státuszváltás, find_authority | Kész |
| **M6** UI/UX prioritások | MILESTONE_6_UI_UX_PRIORITIES.md | Jelmagyarázat, light theme, buborék, keresés, modal, üres állapotok | Kész |
| **M7** FixMyStreet/Open311 | MILESTONE_7_FIXMYSTREET_OPEN311_EXPLAINED.md | open311/v2 (discovery, services, requests), fms_bridge (export, sync), api-docs.php | Kész |
| **M8** AI réteg roadmap | MILESTONE_8_AI_LAYER_ROADMAP.md | suggest_category, reports_nearby 200 m, admin_stats, find_authority – quick wins megvannak | Kész |
| **M9** Feature prioritization | MILESTONE_9_FEATURE_PRIORITIZATION.md | KEEP/LATER/CUT terv; core (térkép, reg, státusz, XP, Open311, civil/facility) megvan | Kész |
| **M10** Fejlesztési terv | MILESTONE_10_DEVELOPMENT_PLAN.md | Phase 1–5 kötelező + quick win feladatok implementálva (lásd alább) | Kész |

**Összegzés:** Mind a 10 milestone dokumentálva van, és a terv szerinti implementáció (Phase 1–5) **kész**. Nincs olyan kötelező feladat, ami hiányzik.

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

# Köz.Tér / CivicAI – Dokumentáció

Ez a mappa a **teljes audit és fejlesztési terv** eredményeit tartalmazza (product architect + full-stack prompt alapján). A cél: Podim/demo/befektetői bemutatóra erős, koherens civic-tech platform, tisztán rendszerezett kódbázis és roadmap.

## Olvasási sorrend (ajánlott)

1. **SOURCE_OF_TRUTH.md** – Mi a source of truth; mely fájlok aktívak; mi örökség, mit refaktoráljunk.
2. **MILESTONE_1_AUDIT_AND_PRODUCT_MAP.md** – Modul térkép, role térkép, adatfolyam, user journey-k, integrációk, elavult pontok, fő narratíva vs Podim narratíva.
3. **MILESTONE_2_ARCHITECTURE.md** – Javasolt alrendszerek, mappaszerkezet, mi maradjon PHP/service/API, dashboard vendor.
4. **MILESTONE_3_DATA_MODEL_AND_MIGRATIONS.md** – Entitások, kapcsolatok, indexek, baseline + inkrementális migrációk.
5. **MILESTONE_4_USER_FLOWS.md** – Vendég/user/civil/community/gov/admin flow-k, trigger → validáció → tárolás → edge case.
6. **MILESTONE_5_PODIM_DEMO_FLOW.md** – 3–5 perces demo lépések, mit rejtsünk el, mit emeljünk ki.
7. **MILESTONE_6_UI_UX_PRIORITIES.md** – Landing, map shell, markerek, report modal, badge/XP, leaderboard, gov/admin, empty states; kozmetika vs termék vs Podim azonnal.
8. **MILESTONE_7_FIXMYSTREET_OPEN311_EXPLAINED.md** – FixMyStreet integráció, fms_bridge, saját Open311 API, különbségek, üzleti és technikai értelmezés, jövőkép.
9. **MILESTONE_8_AI_LAYER_ROADMAP.md** – AI funkciók (kategória javaslat, duplikátum, összefoglaló, statisztika), mi gyorsan megvalósítható, mi mutatható be hitelesen, mit ne ígérjünk túl.
10. **MILESTONE_9_FEATURE_PRIORITIZATION.md** – KEEP MOST / LATER / CUT or simplify; friend, like, civil, facilities, Open311, FMS, gov, admin, stb.
11. **MILESTONE_10_DEVELOPMENT_PLAN.md** – Phase 1–5: demo stabilizálás, product clean-up, interoperability, AI-assisted layer, multi-city; célok, feladatok, kockázatok, quick wins, befektetői/értékesítési szöveg.

## SQL migrációk

A **sql/** mappában a **00_README_MIGRATIONS.md** a futtatási sorrendet és megjegyzéseket írja le (2026-03 … 2026-10).

## Üzemeltetés

- **Health check:** `GET /api/health.php` – JSON válasz: `ok`, `db` (ok/error), `config_review` (true ha APP_BASE_URL üres vagy example.com). DB hiba esetén HTTP 503. Auth nincs; monitoring/load balancer számára.

## Megjegyzés

A dokumentumok **nem cserélték fel a működő kódot**. Csak audit, terv és prioritizáció történt; a korábban beépített robusztussági javítások (hiányzó táblák kezelése, régi schema fallback, migráció README) megmaradtak.

# MILESTONE 10 – Konkrét fejlesztési terv

## PHASE 1 – Demo stabilizálás

- **Cél:** Podim / befektetői bemutatóhoz stabil, 3–5 perces flow; nincs 500, egyértelmű jogosultságok és visszajelzések.
- **Fő feladatok:**
  - Demo adatok: 1–2 report, 1 civil event, 1 facility; opcionálisan 1 user + 1 govuser előre.
  - Sikeres küldés üzenet és üres állapotok ellenőrzése (M6).
  - Gov dashboard: authority_users nélkül is üres lista (ne 500) – már megvan.
  - Regisztráció és belépés minden szerepkörrel (user, civiluser, communityuser; gov ha engedélyezve) – már megvan.
  - Migráció sorrend dokumentálva (sql/00_README_MIGRATIONS.md); 2026-09 futtatva ahol kell.
- **Függőségek:** Nincs; meglévő kód + doc.
- **Kockázatok:** Hiányzó tábla (civil_events, facilities) → 503; már kezelve try/catch-kel.
- **Quick wins:** Demo script (M5) betartása; 1 sikeres bejelentés + 1 státuszváltás gyakorlás.
- **Befektetőnek:** „Működő civic platform: bejelentés, gamification, civil/kozületi réteg, hatósági workflow, Open311.”
- **Értékesítés:** Önkormányzat: „Egy helyen a bejelentések és a részvétel.” Civic partner / NGO: „Civil események és közületi pontok láthatósága.”

---

## PHASE 2 – Product clean-up

- **Cél:** Tiszta architektúra, egyértelmű source of truth, auth és migráció konszolidáció.
- **Fő feladatok:**
  - Auth: admin belépés egyesítése (users tábla admin/superadmin) VAGY világos „demo admin” doc.
  - Adatmodell: egy baseline schema doc (sql/00_baseline_schema.sql vagy csak doc) + migráció sorrend (megvan).
  - Util.php: ha túl nagy, XP/geocode/authority kiszakítása service/helper fájlokba (opcionális).
  - Dashboard mappa: README „vendor/reference” – ne építsünk rá; vagy később admin UI átállás.
- **Függőségek:** Phase 1 stabil.
- **Kockázatok:** Refactor során regresszió; tesztelés manuálisan vagy egyszerű smoke.
- **Quick wins:** docs/SOURCE_OF_TRUTH.md és M2/M3 docok (megvannak).
- **Befektetőnek:** „Karbantartható, tisztán felépített platform.”
- **Értékesítés:** Hosszú távú skálázás, új fejlesztők onboarding.

---

## PHASE 3 – Interoperability

- **Cél:** Open311 és FixMyStreet narratíva és opcionális flow véglegesítése.
- **Fő feladatok:**
  - Saját Open311 API dokumentálása (M7 megvan); nyilvános „API docs” link vagy statikus oldal.
  - FMS bridge: ha használják, „Küldés a külső rendszerbe” lépés megtervezése (pl. report mentés után egy „Export to FMS” gomb és fms_reports kapcsolat).
  - Státusz sync (sync.php) cron vagy ütemezett hívás dokumentálása.
- **Függőségek:** Phase 2.
- **Kockázatok:** Külső API változás; rate limit.
- **Quick wins:** M7 doc (megvan); discovery URL megosztása partnereknek.
- **Befektetőnek:** „Szabványos, integrálható civic API.”
- **Értékesítés:** Önkormányzat / partner: „Open311 kompatibilis; opcionálisan FixMyStreet kapcsolat.”

---

## PHASE 4 – AI-assisted civic layer

- **Cél:** Hiteles „AI-assisted” elemek bemutatása (M8 roadmap).
- **Fő feladatok:**
  - Kategória javaslat (szabályalapú vagy kis modell) a bejelentés űrlapon.
  - Duplikátum figyelmeztetés bővítés: „Hasonló bejelentések 200 m-en belül” (nem blokkolás).
  - Admin/gov: statisztika blokk (COUNT per category, status, trend).
  - Opcionális: rövid összefoglaló (description első N karakter vagy külső API) admin listában.
- **Függőségek:** Phase 2 stabil adat.
- **Kockázatok:** Túlígéret; tisztázni „javaslat”, nem „döntés”.
- **Quick wins:** Statisztika SQL + egy dashboard blokk.
- **Befektetőnek:** „AI-supported routing és duplikátum védelem; később elemzés.”
- **Értékesítés:** „Intelligens civic platform” – kategória javaslat, duplikátum figyelmeztetés.

---

## PHASE 5 – Multi-city / commercial scalability

- **Cél:** Több város / régió, konfig vagy tábla alapú bbox, jurisdiction.
- **Fő feladatok:**
  - Bbox és terület kezelés konfig vagy city/authority tábla alapján (ne hardcoded Orosháza).
  - Open311 jurisdiction_id vagy hasonló több városra.
  - Admin: város / hatóság szűrés, multi-tenant jelleg (opcionális).
- **Függőségek:** Phase 2–3.
- **Kockázatok:** Adat és jogosultság elkülönítés per city.
- **Quick wins:** Bbox config-ból (pl. env per city).
- **Befektetőnek:** „Skálázható több városra.”
- **Értékesítés:** Több önkormányzat, régió, ország.

---

## Összefoglaló

- **Phase 1:** Demo stabilizálás – most; docok + demo script + meglévő kód.
- **Phase 2:** Clean-up – auth, schema doc, opcionális refactor.
- **Phase 3:** Interoperability – Open311/FMS doc és opcionális flow.
- **Phase 4:** AI réteg – kategória javaslat, duplikátum figyelmeztetés, statisztika.
- **Phase 5:** Multi-city – bbox, jurisdiction, skála.

A jelenlegi működő rendszert nem semmisítettük meg; a változtatások dokumentáció és terv szinten történtek, kódban csak a korábban már megvalósított robusztussági javítások maradtak (try/catch, fallback schema, migráció README).

# CivicAI → Civic Green Intelligence Platform

**Cél:** A meglévő civic bejelentő rendszer bővítése környezetfigyelő, zöld intelligencia és AI-támogatás rétegekkel.  
**Határidő:** 2025. március 11. – funkcionálisan kész. Utána csak hibajavítás, finomítás, demo előkészítés.  
**Szabály:** Ne építsük újrra a rendszert; csak bővítsük a meglévő kódbázist.

---

## Rendszerarchitektúra (négy réteg)

| Réteg | Tartalom | Megjegyzés |
|-------|----------|------------|
| **1. Core Civic Layer** | Bejelentések, térkép, képek, admin, kategóriák, felhasználók | Meglévő – ne változtassuk az architektúrát. |
| **2. Green Intelligence Layer** | Fa nyilvántartás, örökbefogadás, öntözési napló, zöld közösségi akciók | Új. |
| **3. Government Intelligence Layer** | Zöld dashboards, fenntarthatósági mutatók, kerületi analitika, összesítők | Új. |
| **4. AI Assistance Layer** | Jelentésértés, kategorizálás, duplikátum, képfelismerés, összefoglalók | Csak tanácsadó; soha nem automatikus döntés. |

---

## MILESTONE 1 – Urban Tree Cadastre (városi fa nyilvántartás)

Fák mint önálló entitások. A bejelentések továbbra is külön táblában vannak; egy bejelentés opcionálisan hivatkozhat egy fára (`related_tree_id`).

- **trees** tábla: id, lat, lng, address, species, estimated_age, planting_year, trunk_diameter, canopy_diameter, health_status, risk_level, last_inspection, last_watered, adopted_by_user_id, gov_validated, public_visible, created_at, updated_at
- **tree_logs** tábla: id, tree_id, user_id, log_type, note, image_path, created_at  
  - log_type: inspection, watering, damage, maintenance
- **reports** bővítés: related_tree_id, ai_category, ai_priority, report_gov_validated, impact_type
- Térkép rétegszűrők: Bejelentések | Fák | Örökbefogadott fák | Öntözést igénylő fák | Veszélyes fák
- Fa popup: faj, egészségi állapot, örökbefogadás, utolsó öntözés, fotó idővonal, polgári naplók

**Migráció:** `sql/2026-13-tree-cadastre.sql`

---

## MILESTONE 2 – Citizen Tree Adoption (fa örökbefogadás)

- **tree_adoptions**: id, tree_id, user_id, adopted_at, status (active | inactive)
- **tree_watering_logs**: id, tree_id, user_id, photo, water_amount, created_at
- Felhasználói akciók: fa örökbefogadás, öntözés naplózása, fotó feltöltés, kár bejelentése
- Gamification: XP események – watering_tree, adopting_tree, tree_inspection, green_action; badge-ek: Tree Guardian, Green Hero, City Gardener, Urban Forester

**Migráció:** `sql/2026-14-tree-adoption.sql`

---

## MILESTONE 3 – Green Community Actions (zöld közösségi akciók)

- civil_events bővítése vagy **green_actions** tábla: event_type = green_action (pl. faültetés, közösségi öntözés, takarítás, biodiverzitás felmérés)
- **green_actions** (ha külön tábla): id, title, description, lat, lng, start_date, end_date, organizer, participants_count, created_at
- Térképen: zöld levél ikon

**Migráció:** `sql/2026-15-green-actions.sql` (vagy civil_events bővítés)

---

## MILESTONE 4 – Government Dashboard (önkormányzati analitika)

- **Környezet:** összes fa, új fák, vizsgálatra váró, öntözést igénylő, veszélyes fák, zöld bejelentések
- **Szociális:** aktív polgárok, fa örökbefogadók, zöld események, öntözési akciók
- **Governance:** bejelentés megoldási idő, vizsgálati hátrány, karbantartási akciók, kerületi összehasonlítás
- Generált jelentések: heti civic összefoglaló, havi zöld jelentés, kerületi fenntarthatósági index, polgári részvétel
- Export: PDF, CSV, JSON

---

## MILESTONE 5 – AI integráció (általános)

- AI mindig **tanácsadó**; soha nem kötelező a platform működéséhez.
- **Provider:** Mistral (elsődleges), Google Gemini (placeholder, kikapcsolva).
- Env: AI_ENABLED, AI_PROVIDER, AI_PROVIDER_VISION, MISTRAL_API_KEY, GEMINI_API_KEY, model nevek.
- Szolgáltatások: AiProviderInterface, MistralProvider, GeminiProvider, AiRouter, AiPromptBuilder, AiResultParser. Timeout, egyszeri retry, biztonságos hiba esetén is mentés.

---

## MILESTONE 6 – AI Report Understanding (bejelentés értelmezés)

- Bejelentés küldésekor: cím + leírás → AI.
- Válasz (JSON): suggested_category, suggested_subcategory, urgency_level, short_admin_summary, citizen_friendly_rewrite, green_related_flag, confidence_score.
- Admin felülvizsgálja a javaslatokat.

---

## MILESTONE 7 – Duplicate Detection (duplikátum felismerés)

- Logika: ~30 m sugarú kör, szöveg- és kategória hasonlóság; opcionális AI indoklás.
- Kimenet: possible_duplicate, duplicate_candidates, reasoning_summary. Admin dönt.

---

## MILESTONE 8 – Image Classification (képfelismerés)

- Feltöltött kép → AI. Címkék: tree, garbage, pothole, lighting, graffiti, other; fa esetén: possible_dry_tree, possible_damage, possible_tilt_risk. Csak tanácsadó.

---

## MILESTONE 9 – AI Government Summary (önkormányzati összefoglaló)

- Adatbázis aggregátumok + AI narratíva: környezet, szociális, governance. Kimenetek: polgármesteri tájékoztató, havi civic jelentés, fenntarthatósági narratíva, EU pályázati összefoglaló. A számok mindig DB-ből; az AI csak szöveget ír.

---

## MILESTONE 10 – AI Cost Control

- Limitek: AI_MAX_REPORTS_PER_DAY, AI_SUMMARY_LIMIT, AI_IMAGE_ANALYSIS_LIMIT.
- **ai_results** tábla: id, entity_type, entity_id, task_type, model_name, input_hash, output_json, confidence_score, created_at. task_type: report_classification, duplicate_detection, admin_summary, gov_summary, image_classification.

---

## Fejlesztési szabályok

- Ne törjük el a meglévő bejelentés küldést.
- Ne töröljünk meglévő táblákat.
- Új táblákhoz migrációt használjunk.
- AI hibája esetén mindig legyen fallback; a bejelentés mindenképp mentésre kerüljön.

---

## Végső cél

- Polgárok bejelentik a problémákat.
- A fák kezelt városi eszközként jelennek meg.
- Közösségek a zöld infrastruktúrát tartják karban.
- Az AI segíti a civic adminisztrációt.
- Az önkormányzatok használható elemzéseket kapnak.

*A rendszer a Civic Green Intelligence Platform felé fejlődik.*

# Milestone áttekintés – mi megvan, mi kimaradt, mit érdemes egységesíteni

Utolsó ellenőrzés: 2026. A dokumentum a **Smart City M1–M10** és a **Foundation (Phase 1–5)** milestone-okat veti össze a kódbázissal.

---

## 1. Két milestone rendszer (nem keverendő)

| Rendszer | Hol | Tartalom |
|----------|-----|----------|
| **Smart City M1–M10** | docs/SMART_CITY_MILESTONES.md, PROJECT_SUMMARY.md | Civic Analytics, AI report, ESG, Tree cadastre, Tree layer, AI tree, Tree watering (M7), Export (M8), Dashboard UI (M9), Jövőbeli AI (M10). |
| **Foundation M1–M10** | docs/MILESTONE_STATUS.md | Audit, Architektúra, Adatmodell, User flows, Podim demo, UI/UX, Open311 (M7), AI roadmap (M8), Feature prioritization (M9), Fejlesztési terv (M10). |

A továbbiakban a **Smart City** M1–M10-ról van szó, ha mást nem írunk.

---

## 2. Smart City M1–M10 – állapot a kódban

| # | Milestone | Állapot | Fájlok / megjegyzés |
|---|-----------|--------|---------------------|
| **M1** | Civic Analytics | ✅ Kész | api/analytics.php, gov + admin linkek (Analytics export JSON/CSV). |
| **M2** | AI report generator | ✅ Kész | api/gov_ai.php (type + timeframe), AiPromptBuilder, Gov „AI jelentés” kártya. |
| **M3** | ESG dashboard | ✅ Kész | api/esg_export.php, Gov E/S/G blokkok, évválasztó, JSON/CSV; api/esg_metrics.php (Analytics ESG Command Center). |
| **M4** | Tree cadastre bővítés | ✅ Kész | trees.notes, api/tree_edit.php, tree_logs image_path. |
| **M5** | Tree layer szín (health) | ✅ Kész | app.js treeIcon() – zöld/sárga/piros health/risk alapján. |
| **M6** | AI tree monitoring | ✅ Kész | api/tree_health_analyze.php, vision API, fa popup „Egészség elemzés”. |
| **M7** | Tree watering + értesítés | ✅ Kész | tree_species_care, api/trees_needing_water.php, Gov „Öntözendő fák” blokk. E-mail/cron: opcionális, nincs. |
| **M8** | Export & Open Data | ✅ Kész | api/export.php (reports/trees/esg, csv/geojson/json). Nyilvános rate limit: opcionális. |
| **M9** | Dashboard UI panelek | ✅ Kész | Gov: 5 panel (City Health, Engagement, Issues, Trees, ESG), City Health Index kártya (api/city_health.php), Heatmap, Statistics, Sentiment. |
| **M10** | Jövőbeli AI (placeholder + bővítések) | ✅ Kész | docs/FUTURE_AI_FEATURES.md; **megvalósítva:** Gov AI Copilot (api/gov_copilot.php, services/GovCopilot.php), Surveys fül (api/gov_surveys.php), Analytics: Predictions (api/predictions.php), Green Intelligence (api/green_metrics.php), ESG Command Center (api/esg_metrics.php). |

**Összegzés:** M1–M10 mind megvan a kódban. Opcionális hiányok: M7 e-mail/cron, M8 nyilvános rate limit.

---

## 3. Gov dashboard – teljes funkciólista (egységesítéshez)

- **Dashboard tab:** City Health Index (city_health.php), AI Copilot kártya, 5 panel kártya, statisztika (mai/7d/összes, by_status, by_category), Analytics + ESG blokkok, Öntözendő fák (M7), AI összefoglaló/ESG/jelentés (gov_ai.php), modul státusz (FMS, AI).
- **Reports tab:** Lista, státusz szűrő (report lista védelem: csak display_name, try-catch).
- **Ideas tab:** Ötletek lista, státusz váltás.
- **Surveys tab:** Felmérések lista, eredmények (gov_surveys.php).
- **Trees tab:** Fa lista, szerkesztés (gov_trees_list.php, tree_edit.php).
- **Analytics tab:** Heatmap (heatmap_data.php), Statistics (gov_statistics.php), Sentiment (sentiment_analysis.php), Predictions (predictions.php), Green Intelligence (green_metrics.php), ESG Command Center (esg_metrics.php + JSON/CSV linkek).
- **Modules tab:** Modul kapcsolók (gov_modules.php) – csak nem adminnak.

---

## 4. Amit érdemes egységesíteni / kiegészíteni

### 4.1 Dokumentáció

- **SMART_CITY_MILESTONES.md:** M10-nél jelezni, hogy a „jövőbeli AI” mellett már megvanna: Gov Copilot, Surveys fül, Analytics Predictions/Green/ESG Command Center (lásd docs/MILESTONE_REVIEW.md).
- **FUTURE_AI_FEATURES.md:** Rövid szakasz, hogy a predikciós/analytics widgetek (Predictions, Green, ESG metrikák) és a Copilot már implementáltak; a doc továbbra is terv a többi témához (hősziget, árvíz, stb.).
- **PROJECT_SUMMARY.md:** 6. tábla M10-nél említeni: Gov Copilot, Surveys, Predictions/Green/ESG analytics (opcionális egy sor).

### 4.2 Nyelvek

- **gov.tab_surveys, gov.copilot_*, gov.surveys_intro, gov.survey_results, gov.survey_responses, survey.status_***: hu + en megvan; de, sl, es, it, fr-ben hiányoznak → fallback a kulcsra. Opcionális: ugyanezen kulcsok hozzáadása a többi lang fájlhoz a konzisztencia miatt.

### 4.3 API konzisztencia

- Minden listed gov API (city_health, gov_copilot, gov_surveys, gov_statistics, sentiment_analysis, heatmap_data, predictions, green_metrics, esg_metrics) 200 + `ok: false` hibakor (nem 500) – már így van.
- Jogosultság: admin vagy gov user, authority scope ahol értelmes – ellenőrizve.

### 4.4 Admin oldal

- Admin **nem** tartalmaz Copilot / Surveys / Analytics tabot – csak Gov. Ez szándékos (admin: felhasználók, bejelentések, hatóságok, rétegek, modulok, Analytics/ESG linkek). Nincs teendő, ha nem kérünk adminra is hasonló paneleket.

---

## 5. Kimaradt (opcionális) – nincs kötelező hiány

| Elem | Állapot | Megjegyzés |
|------|--------|------------|
| M7 e-mail / cron értesítés öntözendő fákhoz | Nincs | Doc szerint opcionális; később cron + mail. |
| M8 nyilvános Open Data rate limit | Nincs | Doc szerint opcionális. |
| M10 további predikciók (hősziget, árvíz, zöldfedettség) | Csak terv | FUTURE_AI_FEATURES.md; nincs production kód. |
| Gov debug kapcsoló (?debug=1) | Nincs | Csak fejlesztéskor hasznos; nem commitoljuk, vagy csak kommentben. |
| Lang: de/sl/es/it/fr surveys + copilot | Hiányzik | Fallback kulcsra; opcionális kiegészítés. |

---

## 6. Rövid teszt checklist (milestone-onként)

- **M1:** Gov/Admin Analytics export JSON/CSV működik.
- **M2:** Gov AI jelentés (típus + időszak) generál, megjelenik a szöveg.
- **M3:** Gov ESG blokkok, évválasztó, JSON/CSV; Analytics ESG Command Center betölt.
- **M4:** Fa szerkesztés (tree_edit) működik.
- **M5:** Térképen fa réteg szín (zöld/sárga/piros) látszik.
- **M6:** Fa popup „Egészség elemzés” fotóval működik.
- **M7:** Gov „Öntözendő fák” szám + lista megjelenik.
- **M8:** export.php dataset=reports|trees|esg, format=csv|geojson|json működik.
- **M9:** Gov dashboard 5 panel + City Health + statisztika látszik.
- **M10:** Gov Copilot kérdés–válasz; Surveys fül lista + eredmények; Analytics Predictions, Green, ESG Command Center kártyák betöltődnek.
- **Időjárás:** api/weather.php (Open-Meteo), WEATHER_ENABLED config; Gov dashboard időjárás kártya (ha be van kapcsolva).
- **IoT:** Gov „IoT” fül; api/iot_devices.php (jelenleg üres lista, később mérőeszközök bekapcsolhatók a térképre).

---

**Összegzés:** Minden milestone megvan, nincs kötelező kimaradás. Egységesítés: doc frissítés (M10 bővítések), opcionálisan nyelvek (surveys/copilot) és GOV_ADD_STEPS.md megtartása referencia gyanánt.

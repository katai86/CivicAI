# CivicAI – Urban Intelligence Expansion – Milestone terv

Ez a dokumentum a **Urban Intelligence Platform** bővítés 10 milestone-ját írja le pontosan. Minden milestone a meglévő CivicAI kódbázissal integrálódik; nem építünk párhuzamos modulokat.

**Alapelvek:**
- Meglévő modulok (térkép, reports, trees, admin, gov, AI Router, export, Open311, FMS bridge) **nem** épülnek újjá.
- Minden milestone: meglévő adatbázis bővítése, meglévő API-k kihasználása, ugyanaz a mappaszerkezet és kódstílus.
- Ne törjünk meg meglévő funkcionalitást.

**Projekt gyökér:** `CivicAI/` — `api/`, `services/`, `gov/`, `admin/`, `assets/`, `sql/`, `util.php`, `db.php`, `config.php`.

---

## Áttekintés – Milestone lista

| # | Milestone | Rövid cél | Fő deliverable |
|---|-----------|-----------|----------------|
| M1 | Urban Heatmap Engine | Térbeli koncentráció heatmap (issues, activity, tree health, ESG) | api/heatmap_data.php, Leaflet heat layer, gov widget |
| M2 | Government Statistics Hub | Részletes operatív analitika (trend, response time, resolution, backlog, participation, tree stats) | api/gov_statistics.php, gov Statistics Hub szekció |
| M3 | City Health Score | Egy AI számított 0–100 index (infrastructure, environment, engagement, maintenance) | services/CityHealthScore.php, api/city_health.php, gov kártya |
| M4 | AI Sentiment Analysis | Lakossági sentiment (reports, comments, feedback) – AI Router | services/SentimentAnalyzer.php, api/sentiment_analysis.php, gov widget |
| M5 | Prediction Engine | Jövőbeli problémák előrejelzése (kátyú, szemét, világítás, fa egészség) | services/UrbanPredictionEngine.php, api/predictions.php, térkép + gov |
| M6 | Green Intelligence Module | Fa réteg bővítés: lombkorona, CO2, biodiverzitás, szárazság kockázat | services/GreenIntelligence.php, api/green_metrics.php, gov panel |
| M7 | ESG Command Center | ESG dashboard upgrade: E/S/G szekciók, automatikus jelentés, PDF/CSV/JSON | api/esg_metrics.php, jelentés generálás, export |
| M8 | Citizen Participation Suite | Ötletek, szavazás, felmérések, részvételi költségvetés lite, projekt fórum | ideas, votes, surveys táblák + API-k + frontend |
| M9 | Urban Digital Twin Lite | *(kihagyva)* Egyesített intelligencia térkép, rétegvezérlés | — |
| M10 | AI Government Copilot | Beszélgetős AI asszisztens gov usereknek (meglévő AI Router) | services/GovCopilot.php, api/gov_copilot.php, gov chat UI |

---

# MILESTONE 1 – URBAN HEATMAP ENGINE

## Cél
A városi problémák és a polgári aktivitás térbeli koncentrációjának vizualizálása dinamikus heatmap réteggel.

## Architektúra és integráció
- **Térkép:** Meglévő Leaflet (index.php, mobile/index.php, assets/app.js) – heatmap **réteg** pluginnal bővül, nem cseréljük le a markereket.
- **Adatforrások:** `reports`, `trees`, `tree_watering_logs`, `report_likes`, `user_xp_log` (ahol releváns), meglévő `api/analytics.php` / `api/admin_stats.php` aggregációk kihasználása.
- **Hatóság:** authority_id szűrés a meglévő `authorities` és `authority_users` alapján; gov user csak saját hatóság adatát lássa.

## Adatbázis
- **Új tábla nem kötelező** a heatmap M1-hez: a heatmap pontok a meglévő `reports` (lat, lng, created_at, category, status, authority_id), `trees` (lat, lng, health_status, risk), és opcionálisan cache tábla később.
- Opcionális cache: `heatmap_cache` (type, date_from, date_to, category, authority_id, data_json, created_at) – ha a lekérdezés lassú, cache-elhető.

## Backend

### Szolgáltatás
- **Fájl:** `services/HeatmapDataService.php` (opcionális) VAGY közvetlenül az API-ban az aggregáció.
- **Logika:**
  - `issue_density`: reports száma grid/cell vagy pont alapján (lat, lng, weight = count vagy 1).
  - `unresolved_issues`: reports ahol status NOT IN ('solved','closed','rejected').
  - `citizen_activity`: reports + tree_watering + report_likes (user aktivitás pontok).
  - `tree_health_risk`: trees ahol health_status/risk magas – súlyozás.
  - `esg_risk`: kombinált (pl. sok megoldatlan + kevés fa) – egyszerű szabály vagy meglévő ESG adat.
- **Szűrés:** date_from, date_to (created_at), category, authority_id; zoom szint alapján pontok összesítése vagy decimálás (kevesebb pont nagy zoomnál).

### API végpont
- **URL:** `GET /api/heatmap_data.php`
- **Paraméterek:**
  - `type` (string): `issue_density` | `unresolved_issues` | `citizen_activity` | `tree_health_risk` | `esg_risk`
  - `date_from` (date, opcionális)
  - `date_to` (date, opcionális)
  - `category` (string, opcionális) – pl. road, sidewalk, trees
  - `authority_id` (int, opcionális) – gov user esetén csak a saját
- **Válasz formátum (JSON):**
```json
{
  "ok": true,
  "data": [
    { "lat": 46.56, "lng": 20.66, "weight": 1.5 },
    ...
  ]
}
```
- **Auth:** Gov/admin vagy nyilvános (csak nyilvános adat) – session vagy token; authority scope gov usernek.

## Frontend
- **Leaflet heat layer:** Plugin pl. `Leaflet.heat` (npm/cdn) – a projektben már van Leaflet; heat réteg külön layer, kapcsolható.
- **Vezérlő:** Térképen (jelmagyarázat vagy külön panel): heatmap be/ki kapcsoló; opcionálisan type választó (dropdown: issue density, unresolved, activity, tree risk, ESG risk).
- **Szűrés:** Dátum és kategória szűrő a heatmap adathoz (opcionális, vagy csak API default 30 nap).
- **Zoom-függő intenzitás:** Plugin beállítás (radius/blur zoom alapján) vagy API-ban zoom szint küldése és kevesebb/több pont visszaadása.

## Government dashboard
- **Widget:** „Urban Issue Heatmap” – egy kártya: beágyazott kis térkép vagy link a fő térképre heatmap réteggel bekapcsolva; vagy statikus „Heatmap a térképen a Jelmagyarázatnál kapcsolható” szöveg + link.

---

# MILESTONE 2 – GOVERNMENT STATISTICS HUB

## Cél
Részletes operatív analitika az önkormányzatoknak: trendek, válaszidő, megoldási arány, backlog, részvétel, fa statisztikák.

## Architektúra és integráció
- **Meglévő:** `api/admin_stats.php` (reports_1d, reports_7d, users_7d, status, category), `gov/index.php` (statisztika blokk, státusz/kategória megoszlás). **Bővítjük**, nem cseréljük.
- **Adatbázis:** `reports`, `report_status_log`, `users`, `trees`, `tree_watering_logs`, `authorities` – mind megvan.

## Adatbázis
- Opcionális: `district` vagy `area` mező/cache ha „district” nincs (elég lehet authority_id = „district” vagy city). Ha nincs districts tábla, „district” = authority vagy cím alapú csoportosítás (pl. irányítószám).
- Új táblák csak ha szükséges: pl. `response_time_cache` (report_id, first_response_at, resolved_at) – vagy ezt report_status_log-ból számoljuk.

## Backend

### API végpont
- **URL:** `GET /api/gov_statistics.php`
- **Paraméterek:** `authority_id` (opcionális), `date_from`, `date_to` (opcionális) – gov user csak saját authority.
- **Válasz struktúra (JSON):**
```json
{
  "ok": true,
  "data": {
    "issue_trends": [
      { "date": "2026-03-01", "category": "road", "count": 5 },
      ...
    ],
    "issue_trend_per_district": [
      { "authority_id": 1, "name": "...", "count": 10 },
      ...
    ],
    "response_times": { "avg_hours": 24, "median_hours": 12, "by_category": [...] },
    "resolution_rate": { "rate": 0.75, "total": 100, "resolved": 75 },
    "backlog_growth": { "current_open": 20, "previous_period_open": 18, "trend": "up" },
    "citizen_participation_rate": { "active_users_7d": 10, "reports_7d": 15, "rate_description": "..." },
    "tree_maintenance_stats": { "total_trees": 100, "watered_7d": 30, "adopted": 40, "health_at_risk_count": 5 },
    "engagement_rate": { "reports_per_user_7d": 1.5, "new_users_7d": 3 }
  }
}
```
- **Számítások:**
  - response_time: report_status_log első „approved”/„in_progress” és „solved”/„closed” időpont különbsége.
  - resolution_rate: solved+closed / total (időszak).
  - backlog: open statusú reportok száma most vs. előző period végén.
  - citizen_participation: users + reports 7d; tree_maintenance: trees, tree_watering_logs, tree_adoptions.

## Frontend
- Nem kell új public oldal; a gov dashboardon használjuk.

## Government dashboard
- **Új szekció:** „Government Statistics Hub” (pl. új tab vagy accordion blokk).
- **Widgetek:**
  - **Issue trend chart:** Kategória vagy dátum szerinti trend (meglévő Chart.js vagy admin-chart-bar stílus – gov/index.php már használ bar chartot).
  - **Resolution time chart:** Átlag válaszidő / megoldási idők (sáv vagy vonal).
  - **Engagement trend:** Részvétel időben (users, reports).
  - **District performance table:** Táblázat: authority/district, nyitott, megoldott, arány.
- **Könyvtár:** Meglévő frontend lib (gov oldalon nincs külön Chart.js import a snippet alapján – ha kell, Chart.js vagy ugyanaz a CSS bar mint admin).

---

# MILESTONE 3 – CITY HEALTH SCORE

## Cél
Egyetlen, AI által számított 0–100-as index a város „egészségéről”, alindexekkel: infrastruktúra, környezet, részvétel, karbantartás.

## Architektúra és integráció
- **AI:** Meglévő `services/AiRouter.php`, `AiPromptBuilder.php` – új „task type” vagy dedikált hívás; adatokat összegyűjtjük, AI-nak strukturált összefoglalót kérünk, majd score-t parse-olunk.
- **Adatforrások:** reports (status, category), trees (health, count), resolution_rate, participation (M2 vagy közvetlen lekérdezés).

## Adatbázis
- Opcionális: `city_health_scores` (id, authority_id, scored_at, overall_score, infrastructure_score, environment_score, engagement_score, maintenance_score, details_json) – cache; vagy mindig real-time számítás.

## Backend

### Szolgáltatás
- **Fájl:** `services/CityHealthScore.php`
- **Metódusok:** pl. `compute(int $authorityId = null): array`
- **Logika:** Összegyűjti: megoldási arány, nyitott reportok száma, fa egészség átlag, részvételi mutatók; ezt strukturáltan (szöveg vagy JSON) megadja az AI-nak; AI válaszból kinyeri a 0–100 score-okat (infrastructure, environment, engagement, maintenance, overall). Fallback: szabályalapú képlet ha AI nem elérhető (pl. súlyozott átlag).

### API végpont
- **URL:** `GET /api/city_health.php`
- **Paraméterek:** `authority_id` (opcionális).
- **Válasz (JSON):**
```json
{
  "ok": true,
  "data": {
    "city_health_score": 78,
    "infrastructure_score": 72,
    "environment_score": 85,
    "engagement_score": 80,
    "maintenance_score": 75
  }
}
```

## Government dashboard
- **Widget:** Nagy kártya: „City Health Index: 78” + alindexek (infrastructure, environment, engagement, maintenance) rövid felirattal vagy kis sávdiagrammal.

---

# MILESTONE 4 – AI SENTIMENT ANALYSIS

## Cél
Lakossági hangulat elemzés a bejelentések leírása, megjegyzések és visszajelzések alapján; meglévő AI Routerral.

## Architektúra és integráció
- **AI:** `AiRouter`, `AiPromptBuilder` – új prompt típus: sentiment + top topics + emerging issues.
- **Adatforrások:** `reports.description`, `report_status_log.note` („comments” helyett); ha később lesz `report_comments` tábla, azt is bele lehet venni.

## Adatbázis
- Opcionális: `report_comments` (id, report_id, user_id, body, created_at) – ha a spec „comments”-et is beleveszi; kezdetben csak reports + status notes.
- Opcionális cache: `sentiment_cache` (authority_id, period, result_json, created_at).

## Backend

### Szolgáltatás
- **Fájl:** `services/SentimentAnalyzer.php`
- **Logika:** Összegyűjti a releváns szövegeket (reports.description, notes); batch vagy minta; AI-nak prompt: sentiment (positive/neutral/negative), top topics, emerging issues; választ parse-olja (számok, listák).

### API végpont
- **URL:** `GET /api/sentiment_analysis.php`
- **Paraméterek:** `authority_id`, `date_from`, `date_to` (opcionális).
- **Válasz (JSON):**
```json
{
  "ok": true,
  "data": {
    "positive_percent": 25,
    "neutral_percent": 50,
    "negative_percent": 25,
    "top_concerns": ["roads", "lighting", "waste"],
    "emerging_issues": ["..."]
  }
}
```

## Government dashboard
- **Widget:** „Citizen Sentiment Overview” – kördiagram vagy sávok (positive/neutral/negative), lista: top concerns, emerging issues.

---

# MILESTONE 5 – PREDICTION ENGINE

## Cél
Jövőbeli városi problémák előrejelzése: kátyú, hulladék, világítás, fa egészség kockázat; adat: történeti reportok, időjárás (opcionális), fa logok, szezonális és térbeli klaszterek.

## Architektúra és integráció
- **Adat:** `reports` (category, lat, lng, created_at), `trees` (health, risk, last_watered), `tree_watering_logs` – szabályalapú vagy egyszerű modell (regresszió/klaszter) először; időjárás csak ha van külső API (opcionális).
- **AI:** Opcionális: AI segítségével „risk summary”; alapvetően számítási motor.

## Adatbázis
- Opcionális: `prediction_runs` (id, authority_id, run_at, type, result_json) – cache; vagy mindig real-time.

## Backend

### Szolgáltatás
- **Fájl:** `services/UrbanPredictionEngine.php`
- **Kimenetek:** predicted_issues (kategória + terület), risk_zones (geo polygon vagy pontok), predicted_tree_failures (fa id vagy terület).
- **Bemenetek:** historical reports (category, location, time), tree health/watering, seasonal (hónap), geographic clustering (hotspotok).

### API végpont
- **URL:** `GET /api/predictions.php`
- **Paraméterek:** `authority_id`, `types` (opcionális: pothole, waste, lighting, tree_health).
- **Válasz (JSON):**
```json
{
  "ok": true,
  "data": {
    "predicted_issues": [
      { "category": "road", "lat": 46.56, "lng": 20.66, "risk_level": "high" }
    ],
    "risk_zones": [
      { "type": "pothole", "polygon_or_bounds": "...", "score": 0.8 }
    ],
    "predicted_tree_failures": [
      { "tree_id": 1, "lat": 46.56, "lng": 20.66, "risk": "medium" }
    ]
  }
}
```

## Frontend
- **Térkép:** Előrejelzési zónák megjelenítése: polygon vagy marker réteg (pl. külön szín), kapcsolható réteg.

## Government dashboard
- **Szekció:** „Urban Risk Prediction” – lista vagy mini térkép a risk zones / predicted issues / tree failures összefoglalóval.

---

# MILESTONE 6 – GREEN INTELLIGENCE MODULE

## Cél
Meglévő fa nyilvántartás bővítése: lombkorona lefedettség, CO2 elnyelés becslés, biodiverzitási indikátor, szárazság kockázat.

## Architektúra és integráció
- **Meglévő:** `trees`, `tree_logs`, `tree_species_care`, `api/trees_list.php`, `api/tree_*.php`, fa réteg a térképen. **Csak bővítés.**

## Adatbázis
- Meglévő `trees` oszlopok (species, health, stb.) – ha kell: `canopy_radius_m`, `trunk_circumference_cm` (ha van ilyen) a becslésekhez; egyébként egyszerű formula (fajta alapú default terület, CO2).

## Backend

### Szolgáltatás
- **Fájl:** `services/GreenIntelligence.php`
- **Számítások:**
  - **canopy_coverage:** trees területe / vizsgált terület (authority bbox vagy fix); faonkenti korona terület becslés (species + default radius ha nincs adat).
  - **carbon_absorption:** egyszerű formula (fa szám, átlag korona, fajta faktor) – tonna CO2/év.
  - **biodiversity_index:** fajta sokféleség (species count, Shannon index vagy egyszerű count).
  - **drought_risk:** last_watered, species vízigény (ha van tree_species_care), szárazság kockázat score.

### API végpont
- **URL:** `GET /api/green_metrics.php`
- **Paraméterek:** `authority_id` (opcionális).
- **Válasz (JSON):**
```json
{
  "ok": true,
  "data": {
    "canopy_coverage": 0.15,
    "carbon_absorption": 12.5,
    "biodiversity_index": 0.72,
    "drought_risk": 0.3
  }
}
```

## Government dashboard
- **Panel:** „Green Intelligence” – számok és rövid magyarázat (canopy %, CO2, biodiversity, drought risk).

---

# MILESTONE 7 – ESG COMMAND CENTER

## Cél
Meglévő ESG dashboard fejlesztése: Environmental / Social / Governance szekciók, automatikus ESG jelentés, PDF/CSV/JSON export.

## Architektúra és integráció
- **Meglévő:** `api/esg_export.php`, `api/gov_ai.php` (ESG összefoglaló), `api/analytics.php`. **Bővítjük** az esg_export és gov UI-t.

## Adatbázis
- Nincs kötelező új tábla; ESG metrikák a meglévő adatokból (reports, trees, users, resolution, participation).

## Backend

### API végpont
- **URL:** `GET /api/esg_metrics.php`
- **Válasz (JSON):** Szekciók szerint:
  - **Environmental:** tree_coverage (vagy green_metrics), heat_island_index (egyszerű: pl. kevés zöld = magasabb), water_stress (green_metrics drought_risk).
  - **Social:** citizen_participation, volunteer_engagement (pl. tree adoption, watering count).
  - **Governance:** response_transparency (pl. report_status_log nyilvános), resolution_rate.

### Automatikus ESG jelentés
- **Szolgáltatás vagy script:** Összegyűjti az E/S/G metrikákat, opcionálisan AI narratíva (AiRouter), generál egy „report” objektumot.
- **Export formátumok:** PDF (meglévő lib vagy egyszerű sablon), CSV, JSON – endpoint pl. `api/esg_export.php?format=pdf|csv|json` bővítése vagy külön `api/esg_report.php`.

## Government dashboard
- **ESG Command Center szekció:** Három alblokk: Environmental, Social, Governance – metrikák és rövid szöveg; gomb: „Export report” (PDF/CSV/JSON).

---

# MILESTONE 8 – CITIZEN PARTICIPATION SUITE

## Cél
Új részvételi eszközök: ötlet beküldés, közösségi szavazás, felmérések, részvételi költségvetés lite, projekt megbeszélési fonalak.

## Architektúra és integráció
- **Ötletek:** Meglévő: `ideas` (2026-22-ideas-ideation.sql), `api/ideas_list.php`, `api/idea_create.php`, `api/idea_vote.php`, `api/idea_set_status.php`; 2026-24: ideas.authority_id. Bővítjük: discussion threads, survey.
- **Részvételi költségvetés:** Meglévő: `budget_projects`, `budget_votes` (2026-23), `api/budget_projects_list.php`, `api/budget_vote.php`, admin CRUD. „Participatory budgeting lite” = nyilvános szavazás UI + opcionális projekt fórum.
- **Surveys:** Új táblák (`surveys`, `survey_responses`) és API (`api/survey.php`).

## Adatbázis
- **ideas:** (id, title, description, lat, lng, user_id, authority_id, status, created_at, updated_at) – ha még nincs, migráció.
- **idea_votes** vagy **votes:** (id, user_id, idea_id, created_at) – egy user egy ötletre egy szavazat.
- **surveys:** (id, title, description, authority_id, starts_at, ends_at, created_at).
- **survey_responses:** (id, survey_id, user_id, response_json vagy question_id + value, created_at).
- **Project discussion threads:** `project_threads` (id, project_id, parent_id, user_id, body, created_at) – project_id = idea_id vagy budget_project_id; vagy egyszerűen `idea_comments` (idea_id, user_id, body, created_at).

## Backend

### API-k
- **Ötletek:** `GET/POST /api/ideas.php` (list + create), `POST /api/vote.php` (action=idea_vote, idea_id) – vagy meglévő idea_vote.php.
- **Felmérések:** `GET /api/survey.php` (list active), `GET /api/survey.php?id=...` (egy survey kérdésekkel), `POST /api/survey.php` (action=submit_response, survey_id, responses).
- **Részvételi költségvetés lite:** Meglévő `api/budget_projects_list.php`, `api/budget_vote.php` – elég; opcionális „discussion” = idea_comments szerű.

## Frontend
- **Részvétel szekció:** Új menüpont vagy oldal: Ötletek list + „Új ötlet”, szavazás gomb; Felmérések list + kitöltés; Részvételi költségvetés (projektek + szavazás); opcionális projekt fórum (thread lista + új hozzászólás).

## Government dashboard
- Gov felületen: ötletek list (státusz kezelés), felmérések kezelés (létrehozás, eredmények), költségvetési projektek (már lehet adminban) – összesítés widgetek opcionális.

---

# MILESTONE 9 – URBAN DIGITAL TWIN LITE *(kihagyva)*

*Ez a milestone projekt döntés alapján nem kerül megvalósításra.*

## Cél (referencia)
Egyesített városi intelligencia térkép: issues, trees, facilities, citizen activity, ESG indikátorok, predictions egy rétegvezérlős felületen; ez legyen a fő „intelligence” felület.

## Architektúra és integráció
- **Meglévő rétegek:** reports (api/reports_list.php), trees (api/trees_list.php), facilities (api/facilities_list.php), civil_events (api/civil_events_list.php), layers (api/layers_public.php). **Összevonjuk** egy layer control alatt.
- **Új rétegek:** citizen_activity (M1 heatmap vagy marker), ESG indicators (pl. mini marker vagy tooltip), predictions (M5 risk zones).
- **Leaflet:** Meglévő layer switcher bővítése vagy új „Intelligence” nézet: egy oldal (pl. gov vagy külön „intelligence map”) ahol minden réteg kapcsolható.

## Adatbázis
- Nincs kötelező új tábla; csak frontend és API hívások kombinálása.

## Backend
- Új dedikált endpoint opcionális: pl. `GET /api/intelligence_layers.php` – visszaadja a réteg listát (id, name, type, api_url, default_visible); vagy a frontend közvetlenül hívja a meglévő API-kat rétegenként.

## Frontend
- **Rétegvezérlés:** Panel: Issues, Trees, Facilities, Citizen activity, ESG indicators, Predictions – checkbox vagy toggle; bekapcsoláskor betölti az adott réteg adatát a megfelelő API-ból és megjeleníti.
- **Egy fő felület:** Pl. gov „Intelligence Map” tab vagy külön route (pl. intelligence.php) – ugyanaz a Leaflet, minden réteg egy helyen.

## Government dashboard
- Ez a „main intelligence interface” – a gov dashboardon link vagy beágyazott „Intelligence Map” szekció (teljes képernyős térkép + layer control).

---

# MILESTONE 10 – AI GOVERNMENT COPILOT

## Cél
Beszélgetős AI asszisztens a gov usereknek; példa kérdések: „Mely kerületeknek van a legtöbb problémája?”, „Hova érdemes fát ültetni?”, „Mi változott az elmúlt 30 napban?” – meglévő AI Routerral.

## Architektúra és integráció
- **AI:** `AiRouter`, `AiPromptBuilder` – új task type: `gov_copilot`. Kontextus: összefoglaló adatok (statisztikák, listák) + user kérdés → AI válasz.
- **Adat:** Gov statistics (M2), city health (M3), sentiment (M4), predictions (M5), green metrics (M6), ESG (M7) – ezekből rövid szöveges vagy strukturált összefoglalót adunk az AI-nak, hogy válaszolhasson.

## Adatbázis
- Opcionális: `gov_copilot_conversations` (id, user_id, authority_id, messages_json, created_at) – ha történetet tárolunk; kezdetben stateless is lehet.

## Backend

### Szolgáltatás
- **Fájl:** `services/GovCopilot.php`
- **Logika:** Bemenet: user kérdés (string). Összegyűjti a releváns adatokat (pl. gov_statistics, city_health, green_metrics, utolsó 30 nap változás) rövid szövegben vagy JSON-ban; ezt + kérdést megadja az AiRouter-nak; választ visszaadja.

### API végpont
- **URL:** `POST /api/gov_copilot.php`
- **Body (JSON):** `{ "question": "What districts have the most issues?" }`
- **Válasz (JSON):** `{ "ok": true, "data": { "answer": "..." } }` – AI generált szöveg.

## Government dashboard
- **UI:** „AI Copilot” vagy „Ask AI” – chat felület: input mező + Küldés; válasz megjelenítése (markdown vagy plain text). Gov felhasználó sessionnel védett.

---

# FINAL TASK (minden milestone után)

1. **Refaktor:** Szükség szerint kód átszervezés (duplikátum eltávolítás, közös helperek).
2. **Dokumentáció:** docs frissítése: SOURCE_OF_TRUTH, api-docs.php (új endpointok), OPERATIONS.md ha új cron/script.
3. **Migrációk:** sql/ mappában új migráció fájlok (2026-XX-...) és 00_run_all_migrations_safe.sql / 00_README_MIGRATIONS.md frissítése.
4. **Visszafelé kompatibilitás:** Meglévő API és frontend törés nélkül; opcionális query paraméterek, új mezők additívan.
5. **Összefoglaló:** Egy rövid doc vagy README szekció: „Urban Intelligence Expansion – Added modules” lista (M1–M10, fájlok, endpointok, widgetek).

---

# Függőségi sorrend javaslat

| Sorrend | Milestone | Megjegyzés |
|---------|-----------|------------|
| 1 | M1 Heatmap | Független, gyors látható eredmény |
| 2 | M2 Gov Statistics | Sok M3–M7 widget erre épül |
| 3 | M3 City Health | M2 adatot használja |
| 4 | M4 Sentiment | AI, önálló |
| 5 | M6 Green Intelligence | Fa réteg bővítés, M7 ESG-hez kell |
| 6 | M7 ESG Command Center | M6 + meglévő ESG |
| 7 | M5 Predictions | Több adatforrás, később |
| 8 | M8 Participation | Ötlet/szavazás/survey – táblák + API + UI |
| 9 | M9 Digital Twin Lite | Rétegvezérlés, M1+M5+M6 összerakása |
| 10 | M10 Gov Copilot | M2–M7 adatokra épül, utolsó |

---

*Dokumentum verzió: 1.0. Utolsó frissítés: 2026-03.*

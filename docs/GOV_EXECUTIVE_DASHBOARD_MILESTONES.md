# Gov Executive / Analytics dashboard – mérföldkövek (állapot)

Belső fejlesztési sáv a `gov/index.php` körüli **vezetői áttekintés**, **elemzés** és **zöld/ESG** blokkokra. A kódban más modulok (pl. `api/gov_statistics.php` „M2”, `GreenIntelligence` „M6”) saját számozást használnak – ez a táblázat **csak ezt az executive/analytics utat** követi.

| # | Cél | Állapot | Fő fájlok / megjegyzés |
|---|-----|---------|-------------------------|
| **M1** | Executive hero, összefoglaló API | **Kész** | `api/executive_summary.php`, `services/ExecutiveSummaryService.php`, `gov/index.php` hero |
| **M2** | KPI komponensek (kártyák, gauge) | **Kész** | `assets/js/components/kpi.js`, `CivicKpi`, gov hero integráció |
| **M3** | Idősorok, Chart.js (30d/90d/12m) | **Kész** | `api/trends.php`, Elemzés fül, `ExecutiveSummaryService::resolveScopes` |
| **M4** | Kategória megoszlás (doughnut) | **Kész** | `api/category_stats.php`, `#govCategoryCanvas` |
| **M5** | Zöld / ESG összetett dashboard + vizuális finomítás | **Kész** | `api/green_dashboard.php`, zöld pillanatkép kártya, ESG Command Center progress sávok |
| **M6** | Prioritási motor + API | **Kész** | `services/PrioritizationEngine.php`, `api/priorities.php`, Elemzés „Várakozási prioritások” |
| **M7** | Morning brief (24h + top kategóriák) | **Kész** | `api/morning_brief.php`, Városvezérlő kártya |
| **M8** | Strukturált insights (szabályalapú, nem LLM) | **Kész** | `api/gov_insights.php`, insights kártya + frissítés |
| **M9** | PDF / nyomtatható összefoglaló export | **Kész (v1)** | Városvezérlő: `govRunPdfExport` + gomb – `executive_summary` + `morning_brief` + `gov_insights` szöveg, több oldal, ugyanaz a jsPDF mint az AI PDF |
| **M10** | KPI / executive cache, opcionális cron | **Kész (v1 – fájl)** | `GOV_API_CACHE_TTL_SECONDS` (alap 90s, 0=off), `data/gov_api_cache/`; `executive_summary`, `morning_brief`, `gov_insights`; fejléc `X-Gov-Api-Cache: HIT|MISS` |
| **M11** | Menü: prémium csoportosítás / átnevezés | **Kész (v1)** | Sidebar: Munka / Elemzés és adatok / Városi intelligencia / Beállítások – `gov.nav_section_*` |
| **M12** | Kerület / subcity **diagramok** (heatmap mellett) | **Kész (v1)** | `api/subcity_stats.php` + Elemzés vízszintes oszlopdiagram (`govZoneCanvas`); `zone_mode` subcity vs district mint prioritások |
| **M13** | Open Data API bővítés (egységes végpontok) | **Kész (v1 – katalógus)** | `api/gov_open_data_catalog.php` – csoportosított JSON index, `href` + param jegyzet; EU sorok csak ha `eu_open_data` modul aktív; Elemzés fül link + `authority_id` szinkron |
| **M14** | Opcionális AI magyarázat az insights listához | **Kész (v1)** | `api/gov_insights_explain.php` POST + `AiPromptBuilder::govInsightsExplain`; `gov_insights_explain` task a summary limitben; UI: „AI magyarázat” gomb (ha AI + mistral UI engedély); kliens küldi a bullet listát |

## Rövid összegzés

- **M1–M14 (v1):** implementálva (PDF, cache, menü, zóna-chart, API-katalógus, insights AI magyarázat).
- **Következő:** más roadmapok (`ROADMAP_MILESTONES.md`, stb.) – igény szerint.
- Általános termék-milestone-ok: lásd még `docs/ROADMAP_MILESTONES.md` (A/B/C), `docs/URBAN_INTELLIGENCE_MILESTONES.md`, EU: `docs/EU_OPEN_DATA.md`.

*Utolsó frissítés: 2026-04 (fejlesztési szakasz).*

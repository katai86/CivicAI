# CivicAI Intelligence Platform – milestone állapot

A **CivicAI Intelligence Platform** a gov felületen klíma-, környezeti, energia-, mobilitási és AI képfelismerő modulokat kezel modulárisan.

## Milestone lista

| # | Cél | Állapot | Fő fájlok |
|---|-----|---------|-----------|
| M1 | Modul meta + registry (GFW, adapter váz) | Kész | `services/IntelligenceModuleRegistry.php`, `services/GlobalForestWatchService.php` |
| M2 | Dashboard klímaindex + menü átrendezés | Kész | `api/intelligence_dashboard.php`, `gov/index.php` |
| M3 | Modulkezelő (ki/be, dashboard/map/report) | Kész | `api/intelligence_module_settings.php`, gov Modulkezelő fül |
| M4 | Adatforrás adapterek | Kész | `services/intelligence/*.php` |
| M5 | AI Vision réteg (mock/preview) | Kész | `services/AiVisionService.php`, `api/ai_vision_analyze.php` |
| M6 | Térképes réteg-kezelő | Kész | `api/intelligence_map_layers.php`, gov „Térképes rétegek” fül |
| M7 | CivicAI Klímaindex | Kész | `services/ClimateIndexService.php` |
| M8 | Jelentésgenerátor (HTML + PDF) | Kész | `api/intelligence_report.php`, gov „Intelligence jelentések” |
| M9 | Cache, hibakezelés, provider napló | Kész | `IntelligenceModuleTrait`, `ExternalDataCache`, `api/intelligence_provider_logs.php`, tesztkapcsolat API |
| M10 | UX (kártyák, jelmagyarázat, átlátszóság) | Kész (v1) | gov klíma kártyák, réteg opacity, modul státusz badge-ek |

## API végpontok

| Endpoint | Leírás |
|----------|--------|
| `GET api/intelligence_modules.php` | Modul lista + státusz |
| `GET api/intelligence_dashboard.php` | Klímaindex + ajánlások |
| `GET api/intelligence_context.php` | Összesítő hub kontextus |
| `GET api/intelligence_map_layers.php` | Réteg lista / GeoJSON réteg |
| `GET api/intelligence_report.php?format=html` | HTML jelentés |
| `POST api/intelligence_test_module.php` | Admin modul teszt (admin) |
| `GET api/intelligence_provider_logs.php` | Provider napló |
| `POST api/ai_vision_analyze.php` | Képelemzés (mock) |

## Bekapcsolás

1. **Admin → Beépülő modulok** – `climate_gbif`, `climate_hungaromet`, `climate_pvgis`, `climate_ocm`, `climate_viirs`, AI modellek.
2. **EU nyílt adatok** – Copernicus rétegekhez: `eu_open_data` + `copernicus_enabled` + hatóság **bbox**.
3. **Gov → Modulkezelő** – dashboard / térkép / jelentés kapcsolók, tesztkapcsolat.

## Smoke teszt

```bash
php tests/verify_intelligence_platform.php
```

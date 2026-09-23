# M0 – Full Repository Audit – COMPLETION

**Status:** DONE  
**Date:** 2026-09-01

---

## Implemented

- Full repository scan for TODO/MOCK/STUB/reference patterns
- Architecture documents created
- Master roadmap M0–M26 initialized
- Existing vs missing component mapping

## Changed / created files

| File | Action |
|------|--------|
| `CIVICAI_MASTER_ROADMAP.md` | created |
| `docs/CIVICAI_CITY_INTELLIGENCE_ARCHITECTURE.md` | created |
| `docs/PLANT_TREE_INTELLIGENCE_ARCHITECTURE.md` | created |
| `docs/milestones/M00_COMPLETION.md` | created |
| `tests/audit_m0_repository.php` | created |

## Key audit findings

### Production-ready (reuse, do not duplicate)

- City Intelligence: `services/cityintel/*`, `sql/2026-32-*`
- Tree cadastre: `trees`, `tree_logs`, gov/admin UI
- Gov dashboard: analytics, ESG, morning brief, Copilot
- Cloud AI vision: `AiVisionService`, `UrbanObservationService`
- IoT virtual sensors + cron
- EU Open Data services (Copernicus OAuth-dependent)

### Reference / mock (NOT production truth)

| File | Issue |
|------|-------|
| `ViirsDataService.php` | `viirs_reference_grid`, `preview_reference` |
| `HungaroMetDataService.php` | `using_reference` mock values |
| `GbifDataService.php` | reference fallback counts |
| `OpenChargeMapDataService.php` | reference fallback |
| `GeminiProvider.php` | safety stub |
| `AiVisionService.php` | deprecated hash mock path |

City Intelligence orchestrator **correctly skips** reference-only ingestion for PVGIS/GBIF/OCM.

### Missing for M1–M26

- No `city_zones`, `city_discoveries`, `city_priority_scores` tables
- No job queue / async GPU worker
- No PlantCLEF, Qwen, PlantNet, TreeHealthEngine
- No expert review queue, prompt registry
- SAM/YOLO/BLIP = cloud prompt profiles only

### No parallel systems rule

Extend `city_observations`, `CitySpatialEngine`, `PrioritizationEngine`, `CityHealthScore`, `AiVisionService` – do not replace.

## DB changes

None (audit only).

## API / UI / AI/ML

Audit only – no functional changes.

## Tests

Run: `php tests/audit_m0_repository.php`

## Known limitations

- M17/M20/M21 blocked until GPU inference service exists
- PHP shared hosting cannot run PlantCLEF/Qwen locally without separate worker VM

## Acceptance checklist

| Criterion | Result |
|-----------|--------|
| Architecture docs exist | ✅ |
| Roadmap M0–M26 listed | ✅ |
| Mock/reference inventory | ✅ |
| Existing infra mapped | ✅ |
| No duplicate system planned | ✅ |
| M1–M26 implementation complete | ❌ (by design – M0 scope only) |

**M0 = DONE**

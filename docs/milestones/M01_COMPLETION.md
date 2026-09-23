# M1 – Unified Observation & Evidence Foundation – COMPLETION

**Status:** IN_PROGRESS (foundation landed; integration + tests pending)  
**Date:** 2026-09-01

---

## Implemented

- `UnifiedObservationTypes` – MEASURED / OBSERVED / ESTIMATED / INFERRED / AI_INTERPRETATION / PROJECTED
- `UnifiedObservationLayer` – validates layer, writes observation + provenance
- `CivicEvidenceLinkStore` – evidence graph links (for M6)
- `CityObservationStore` – M1 column support when migration applied
- SQL migration `2026-33-unified-observation-foundation.sql`

## Changed files

- `sql/2026-33-unified-observation-foundation.sql`
- `services/intelligence/UnifiedObservationTypes.php`
- `services/intelligence/UnifiedObservationLayer.php`
- `services/intelligence/CivicEvidenceLinkStore.php`
- `services/cityintel/CityObservationStore.php`

## DB changes

Run on `civicai_core`:

```bash
mysql civicai_core < sql/2026-33-unified-observation-foundation.sql
```

New tables: `civic_evidence_links`, `civic_observation_provenance`  
Extended: `city_observations` (+ measurement_type, zone_key, model metadata, entity link)

## Not yet done (blocks M1 = DONE)

- [ ] Wire `CityCitizenSignalBridge`, `CityVisionBridge` → `UnifiedObservationLayer`
- [ ] Wire `urban_observations` / `ai_results` read path into provenance
- [ ] API: `city_evidence.php` chain endpoint
- [ ] Integration test with DB
- [ ] Deploy SQL on production

## Acceptance checklist

| Criterion | Result |
|-----------|--------|
| measurement_type enum in code | ✅ |
| provenance table | ✅ schema |
| evidence links table | ✅ schema |
| Store supports M1 columns | ✅ |
| All ingest paths migrated | ❌ |
| E2E test | ❌ |
| Production SQL applied | ❌ |

**M1 ≠ DONE until checklist complete.**

# Full Run Deploy Checklist (M1–M27)

## 1. SQL (phpMyAdmin → civicai_core)

1. `sql/2026-33-unified-observation-foundation.sql` (skip duplicate ALTER lines)
2. `sql/2026-34-plant-tree-city-intelligence.sql`
3. Existing City Intel: `sql/DEPLOY_city_intelligence_phpmyadmin.sql` (if not done)

## 2. Admin API keys

- **Mistral** or **OpenAI** (vision + narration)
- **Plant & Tree** module: PlantNet API key (+ optional HuggingFace)
- Enable **Plant & Tree Intelligence** module

## 3. Cron

```
GET /api/cron_city_intelligence_sync.php?token=ADMIN_TOKEN
```
Every 4h – runs full orchestrator including priorities, discoveries, change intel, situation.

## 4. Smoke tests

| Endpoint | Method |
|----------|--------|
| `/api/plant_provider_health.php` | GET |
| `/api/city_intel_dashboard.php?sync=1` | GET (gov login) |
| `/api/city_priorities.php` | GET |
| `/api/city_discoveries.php` | GET |
| `/api/city_situation.php` | GET |
| `/api/city_evidence.php?root_type=insight&root_id=1` | GET |
| `/api/plant_session.php` | POST `{action:open}` |
| `/api/plant_analyze.php` | POST photo |

CLI (if PHP available):
```
php tests/verify_plant_intelligence.php
php tests/verify_city_intelligence.php
```

## 5. UI verification

- **Gov → City Intelligence**: priorities, discoveries, change panels + sync
- **Gov → Evidence**: „Miért?” → evidence chain
- **Map → fa → health analyze**: species + health + risk (plant_tree response)
- **Admin → Plant & Tree**: test PlantNet / HuggingFace

## 6. What runs without external keys

- TreeHealthEngine, PublicRiskEngine (deterministic)
- UnifiedPriorityEngine, CityDiscoveryEngine (on existing DB data)
- CitySpatialEngine, situation map (partial without observations)

## 7. Requires API keys for full AI path

- PlantNet species ID
- Mistral/OpenAI visual condition JSON

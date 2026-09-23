# CivicAI City Intelligence – Architecture

**Verzió:** 1.0 (M0 audit)  
**Scope:** M1–M14 master roadmap

---

## 1. Cél

Adatvezérelt városi állapot: **mérés → megfigyelés → indikátor → baseline → anomália → zóna → discovery → prioritás → evidence → UI → Copilot → jelentés**.

A lakossági bejelentés **egy** jelforrás, nem az egyetlen.

---

## 2. Jelenlegi állapot (M0)

### 2.1 Megvan

```
External APIs ──┐
Citizen reports ┼──► CityIntelligenceOrchestrator
Vision/Urban  ──┤         │
Trees aggregate ─┘         ▼
                    city_observations (dedupe)
                           │
              CityIndicatorEngines
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
  city_indicator_values  city_baselines  city_anomalies
                           │
                    CityInsightEngine
                           │
                    city_insights
                           │
              Gov UI / Dashboard / Evidence (partial)
```

**Fájlok:** `services/cityintel/*`, `api/city_intel_*.php`, `sql/2026-32-city-intelligence.sql`

### 2.2 Hiányzik (M1–M14)

| Komponens | Milestone | Státusz |
|-----------|-----------|---------|
| Unified measurement_type / provenance | M1 | hiányzik |
| `city_zones`, `city_zone_metrics` | M2 | hiányzik |
| Unified Priority Engine 0–100 | M3 | csak report prio |
| Thematic Situation Map | M4 | hiányzik |
| `city_discoveries` engine | M5 | insights közelít |
| Full Evidence Explorer graph | M6 | részleges UI |
| Change snapshots 24h/7d/30d/90d | M7 | hiányzik |
| Morning Brief 2.0 actions+map | M8 | v1 van |
| Copilot controlled query | M9 | szabad LLM kontextus |
| City Health 7 dim + history | M10 | 4 dim, nincs history |
| Correlation engine | M11 | hiányzik |
| Time machine | M12 | hiányzik |
| Scenario engine | M13 | hiányzik |
| Weekly/monthly auto reports | M14 | partial intel report |

---

## 3. Cél architektúra (M1–M14)

```
┌─────────────────────────────────────────────────────────────┐
│                     DATA SOURCES                             │
│ Open-Meteo, OSM, CAMS, CLMS, Copernicus, GBIF, IoT,         │
│ citizen reports, trees, vision, Plant & Tree (M25)            │
└──────────────────────────┬──────────────────────────────────┘
                           ▼
┌─────────────────────────────────────────────────────────────┐
│              UNIFIED OBSERVATION LAYER (M1)                  │
│ measurement_type: MEASURED|OBSERVED|ESTIMATED|INFERRED|...   │
│ provenance, confidence, zone_id, model metadata              │
│ tables: city_observations (+ extensions), evidence_links     │
└──────────────────────────┬──────────────────────────────────┘
                           ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐
│ Indicators   │  │ Zones M2     │  │ Discoveries M5           │
│ Baselines    │  │ zone_metrics │  │ discovery + recommended  │
│ Anomalies    │  │ snapshots    │  │ action                   │
└──────┬───────┘  └──────┬───────┘  └──────────┬───────────────┘
       │                 │                      │
       └────────────┬────┴──────────────────────┘
                    ▼
         ┌─────────────────────┐
         │ Priority Engine M3  │ 0–100, explainable, versioned
         └──────────┬──────────┘
                    ▼
    ┌───────────────────────────────────┐
    │ Presentation Layer                 │
    │ Situation Map M4 | Evidence M6     │
    │ Change M7 | Brief M8 | Health M10  │
    │ Copilot M9 | Reports M14           │
    └───────────────────────────────────┘
```

---

## 4. Adatmodell (cél – M1+)

### Meglévő (2026-32)

- `city_data_sources` – registry
- `city_observations` – dedupe hash, metadata_json
- `city_indicator_values`, `city_baselines`, `city_anomalies`, `city_insights`

### Új (M1 – 2026-33)

- `city_observations` bővítés: `measurement_type`, `provenance_layer`, `zone_key`, `model_provider`, `model_version`, `pipeline_version`
- `civic_evidence_links` – parent/child evidence graph (M6)

### Új (M2)

- `city_zones` – authority_id, zone_key, grid_row, grid_col, bbox, config_cell_m
- `city_zone_metrics` – aggregated metrics per zone per period
- `city_zone_snapshots` – historical rollups for time machine (M12)

### Új (M5)

- `city_discoveries` – type, zone, severity, confidence, evidence_json, status, recommended_action

### Új (M3)

- `city_priority_scores` – entity_type, entity_id, score, components_json, version, computed_at

---

## 5. API konvenció (meglévő + tervezett)

| Endpoint | Milestone | Státusz |
|----------|-----------|---------|
| `city_intel_dashboard.php` | M1 | ✅ |
| `city_intel_spatial.php` | M2 | partial |
| `city_intel_insights.php` | M6 | ✅ |
| `city_zones.php` | M2 | planned |
| `city_discoveries.php` | M5 | planned |
| `city_priority.php` | M3 | planned |
| `city_change.php` | M7 | planned |
| `city_evidence.php` | M6 | planned |
| `city_scenario.php` | M13 | planned |

---

## 6. LLM szerep (Data Integrity)

| LLM SZABAD | LLM TILOS |
|------------|-----------|
| Narráció, magyarázat | measurement, score, trend számítás |
| Copilot intent → service hívás | discovery generálás önmagában |
| Report executive summary szöveg | fake adat hiányzó providernél |

---

## 7. Cron / sync

- `cron_city_intelligence_sync.php` – authority bbox, ADMIN_TOKEN
- Javasolt: 3–6 óra
- Zone recompute: sync után vagy külön cron (M2)

---

## 8. Multi-tenant

Minden lekérdezés: `authority_id` scope (gov user, admin optional all).

---

## 9. Ismert limitációk

- HungaroMet: placeholder, Open-Meteo proxy
- VIIRS: reference grid, nem ingestálva CI-be
- Copernicus NDVI: OAuth required
- Nincs job queue – sync szinkron HTTP cron

---

## 10. Kapcsolódó docok

- `docs/CITY_INTELLIGENCE.md` – deploy
- `docs/PLANT_TREE_INTELLIGENCE_ARCHITECTURE.md` – M15–M25
- `CIVICAI_MASTER_ROADMAP.md` – milestone truth

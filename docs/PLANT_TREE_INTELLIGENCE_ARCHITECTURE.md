# Plant & Tree Intelligence – Architecture

**Verzió:** 1.1 (cloud path)  
**Scope:** M15–M26 master roadmap  
**Production út:** `docs/PLANT_TREE_CLOUD_ARCHITECTURE.md` – **nincs self-host GPU követelmény**

---

## 1. Pozíció a CivicAI-ban

Plant & Tree Intelligence **nem külön termék** – adatot szolgáltat a City Intelligence platformnak.

```
IMAGE UPLOAD
    │
    ▼
┌─────────────────────────────────────┐
│ AI MODEL ROUTER / VISION PIPELINE   │  M15
│ Quality Gate → Detection → Species  │
│ → Condition → Segmentation → Health │
│ → Risk → Fusion → Evidence          │
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│ TREE REGISTRY (temporal)            │  M24
│ tree_inspections (immutable history)│
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│ CivicAI Observation (M1)          │  M25
│ type: TREE_VISUAL_HEALTH, etc.      │
└──────────────┬──────────────────────┘
               ▼
┌─────────────────────────────────────┐
│ City Intelligence                   │
│ avgTreeHealth, stressedRatio,       │
│ hazardCount, cross-signal insights  │
└─────────────────────────────────────┘
```

---

## 2. Jelenlegi állapot (M0) – őszinte audit

### Megvan (cloud / manuális)

| Komponens | Implementáció | Production? |
|-----------|---------------|-------------|
| Tree cadastre | `trees`, `tree_logs` | ✅ |
| Manual health/risk | gov UI select mezők | ✅ |
| Cloud tree photo AI | `api/tree_health_analyze.php` → Mistral/OpenAI | ✅ (LLM, nem specialist) |
| Street vision | `AiVisionService`, `UrbanObservationService` | ✅ cloud |
| Vision → CI bridge | `CityVisionBridge.php` | ✅ |
| Species field | `trees.species` text | ✅ manuális |

### Nincs meg (M15–M26) – cloud path

| Komponens | Státusz |
|-----------|---------|
| PlantTreeVisionRouter (M15) | ❌ |
| Provider interfaces (M16) | ❌ |
| PlantNet species provider (M17) | ❌ – **PlantNet API**, nem PlantCLEF |
| Cloud vision condition JSON (M20) | ❌ – **Pixtral/GPT-4o**, nem Qwen local |
| Segmentation estimates (M21) | ❌ – vision ESTIMATED; opcionális Replicate |
| PlantNet validation provider | ❌ |
| Image quality gate | ❌ |
| TreeInspectionSession multi-image | ❌ |
| TreeHealthEngine deterministic | ❌ |
| Public risk engine (separate from health) | ❌ |
| PlantVisionFusionService | ❌ |
| Expert validation UI | ❌ |
| AI review queue | ❌ |
| Prompt registry | ❌ |
| Async GPU queue | ❌ |
| Model versioning per prediction | partial (`ai_results`) |
| Active learning dataset | ❌ |
| Plant analysis dedicated APIs | ❌ |

---

## 3. Cél pipeline (M15–M23)

```
IMAGE
  → ImageQualityGate (M18)
      → fail: INSUFFICIENT_IMAGE_QUALITY
  → PlantSpeciesProvider (PlantNet API M17)
      → Top-K taxonomy candidates
  → PlantConditionProvider (Cloud Vision M20 – Pixtral/GPT-4o)
      → structured visual observations JSON
  → SegmentationProvider (M21 – vision ESTIMATED; opcionális Replicate)
      → measurements (ESTIMATED)
  → TreeHealthEngine (M22)
      → healthScore, healthLabel
  → PublicRiskEngine (M22)
      → riskLevel (≠ health)
  → PlantVisionFusionService (M23)
      → PlantNet + vision consensus / conflict
  → Evidence package (M6)
  → tree_inspections row (M24, append-only)
  → city_observations (M25)
  → City Intelligence recompute
  → TextInterpretationProvider (Mistral/OpenAI)
      → narration ONLY
```

---

## 4. Provider interfészek (M16 – tervezett)

```php
interface PlantSpeciesProvider {
    /** @return SpeciesCandidate[] top-K */
    public function identify(string $imagePath, array $context): SpeciesResult;
}

interface PlantConditionProvider {
    /** @return VisualObservation[] structured */
    public function extractObservations(string $imagePath, array $context): ConditionResult;
}

interface SegmentationProvider { ... }
interface PlantExternalValidationProvider { ... } // PlantNet
interface TextInterpretationProvider { ... }      // LLM narration only
```

Minden provider: `modelProvider`, `modelName`, `modelVersion`, `pipelineVersion`.

---

## 5. Adatmodell (tervezett – M19/M24)

### `tree_inspections` (append-only)

- `id`, `tree_id`, `authority_id`, `session_id`
- `image_paths_json`, `part_types_json`
- `species_candidates_json`, `species_consensus`
- `visual_observations_json`
- `segmentation_json`, `measurements_json`
- `health_label`, `health_score`, `risk_level`
- `confidence_json`, `failure_state`
- `model_metadata_json`, `evidence_json`
- `created_at` – **soha ne írja felül a régit**

### `tree_inspection_sessions` (M19)

- multi-image aggregation, conflicts, required_shots

### `plant_expert_validations`

- expert corrections for active learning

### `plant_analysis_jobs` (async M15+)

- status: queued|processing|completed|failed
- SHA256 cache key

---

## 6. Confidence policy (M18)

Konfigurálható küszöbök (default irány):

| Range | Label |
|-------|-------|
| ≥ 0.85 | HIGH |
| 0.60–0.85 | MEDIUM |
| 0.35–0.60 | LOW |
| < 0.35 | UNKNOWN |

Unknown triggers: low top1, small margin, high entropy, failed external validation.

---

## 7. Failure states (M15+)

`SUCCESS` | `PARTIAL_SUCCESS` | `INSUFFICIENT_IMAGE` | `MODEL_UNAVAILABLE` | `LOW_CONFIDENCE` | `CONFLICTED_IDENTIFICATION` | `PROCESSING_FAILED` | `UNKNOWN`

**Szabály:** hiányzó provider → **nem** talál ki másik provider eredményt.

---

## 8. Cloud serving (M15+ – production path)

**Nincs GPU szerver szükség.** Minden inference külső API:

| Provider | Szolgáltatás | Admin kulcs |
|----------|--------------|-------------|
| Mistral / OpenAI | Vision + narration | `mistral.api_key` / `openai.api_key` |
| PlantNet | Species validation | `plant_tree.plantnet_api_key` (új) |
| Replicate (opcionális) | SAM segmentation | `plant_tree.replicate_token` (új) |

- Rate limit: meglévő `ai_image_analysis_limit` + PlantNet quota
- Health: `/api/plant_provider_health.php` (tervezett)
- Async queue: opcionális DB-backed jobs nagy forgalomra (nem GPU miatt)

Részletek: `docs/PLANT_TREE_CLOUD_ARCHITECTURE.md`

---

## 9. Frontend (M15+ / M26)

Cél UI (HU):

- Faj: közönséges név + tudományos + confidence %
- Állapot: STRESSED + score/100
- Észlelt jelek listája
- Kockázat: INSPECTION RECOMMENDED (nem „veszélyes fa”)
- „Miért?” evidence chain
- Guidance: „Készíts közelebbi levélképet”

---

## 10. License audit (M26 gate – pending)

| Provider | License | Status |
|----------|---------|--------|
| PlantNet API | API ToS | NOT_STARTED |
| Mistral Pixtral | API ToS | in use |
| OpenAI GPT-4o vision | API ToS | in use |
| Replicate (opcionális) | API ToS | NOT_STARTED |

---

## 11. E2E tesztek (M26 gate)

1. Real tree image → PlantNet + Cloud Vision → Health → DB → CI → UI
2. Bad image → INSUFFICIENT_IMAGE, no fake analysis
3. Low confidence → PlantNet + vision conflict UI
4. Re-inspection → temporal trend
5. Tree + NDVI + drought + citizen → cross-signal insight

---

## 12. Kapcsolódó fájlok (meglévő)

- `api/tree_health_analyze.php`
- `api/tree_analyze_photo.php`
- `api/ai_vision_analyze.php`
- `services/AiVisionService.php`
- `services/UrbanObservationService.php`
- `services/cityintel/CityVisionBridge.php`

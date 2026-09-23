# Plant & Tree Intelligence – Cloud Architecture (no self-host)

**Verzió:** 1.1  
**Döntés:** Production út **felhős API-kkal** – nincs szükség GPU szerverre, PlantCLEF-re, Qwen self-hostra.

---

## 1. Elv

A master brief **szerepeit** (species, condition, health, risk, evidence) cloud provider-ekkel töltjük ki:

| Brief szerep | Self-host (elvetve) | Cloud helyettesítés |
|--------------|---------------------|---------------------|
| Species ID (M17) | PlantCLEF DINOv2 | **PlantNet API** (+ opcionális Plant.id / Kindwise) |
| Visual condition (M20) | Qwen3-VL local | **Mistral Pixtral / OpenAI GPT-4o vision** strict JSON |
| Segmentation (M21) | SAM2/YOLO local | Vision-leírás → **ESTIMATED** mérések; opcionális **Replicate** SAM (pay-per-use) |
| Narration | — | Mistral/OpenAI text (csak magyarázat) |
| Health / Risk score (M22) | — | **PHP TreeHealthEngine** (deterministic, nem LLM) |

---

## 2. Pipeline (cloud)

```
IMAGE UPLOAD
  → ImageQualityGate (PHP: blur/size/exposure heurisztika)
  → PlantNetProvider (M17) ──────────┐
  → CloudVisionConditionProvider     │  Mistral/OpenAI structured JSON
     (M20 – „Qwen helyett”)          │
  → SegmentationEstimate (M21)     │  optional Replicate API
  → TreeHealthEngine (M22)           │  PHP deterministic
  → PublicRiskEngine (M22)           │
  → PlantVisionFusionService (M23)   │  consensus / conflict
  → tree_inspections (append-only)
  → UnifiedObservationLayer → City Intelligence
  → TextInterpretationProvider     │  LLM narration ONLY
```

---

## 3. Provider-ek (admin modul kulcsok)

| Provider | API | Admin kulcs | Milestone |
|----------|-----|-------------|-----------|
| Cloud Vision | Mistral Pixtral / OpenAI | `mistral.api_key` / `openai.api_key` | M15, M20 |
| PlantNet | https://my-api.plantnet.org | `plant_tree.plantnet_api_key` (új) | M17, M23 |
| Plant.id (opcionális) | Kindwise | `plant_tree.plantid_api_key` (új) | M23 |
| Replicate SAM (opcionális) | replicate.com | `plant_tree.replicate_token` (új) | M21 |

**Magyar köznév:** taxonomy DB-ből / `trees.species` / HU i18n – **ne generálja az LLM**.

---

## 4. Mi marad változatlan (data integrity)

- LLM **nem** számol healthScore, riskScore, priority
- LLM **nem** generál discovery-t önmagában
- Species confidence PlantNet top1 + margin + vision egyetértés
- Rossz kép → `INSUFFICIENT_IMAGE_QUALITY`, nincs fake diagnosis
- Hiányzó API kulcs → `MODEL_UNAVAILABLE`, nincs találgatás

---

## 5. Milestone mapping (cloud path)

| Milestone | Cloud megvalósítás |
|-----------|-------------------|
| M15 | `PlantTreeVisionRouter` – provider választás |
| M16 | PHP provider interfészek (PlantNet, CloudVision, TextInterpretation) |
| M17 | `PlantNetSpeciesProvider` – Top-K taxonomy |
| M18 | Confidence policy + quality gate |
| M19 | `TreeInspectionSession` multi-image |
| M20 | `CloudVisionConditionProvider` – JSON schema (Qwen prompt logika, Pixtral motor) |
| M21 | Estimated measurements vagy Replicate API |
| M22–M25 | Ugyanaz, PHP engine + CI bridge |
| M26 | E2E cloud inference tesztek |

---

## 6. Korlátok (őszintén)

| Terület | Cloud limit |
|---------|-------------|
| Species pontosság | PlantNet > generikus LLM „faj” találgatás |
| Pixel-pontos segmentation | Replicate nélkül csak ESTIMATED |
| Költség | API hívás / kép – admin limit (`ai_image_analysis_limit`) |
| Offline / adatvédelem | Kép külső API-ra megy – dokumentálni kell |

---

## 7. Nincs szükség

- GPU VM
- vLLM / SGLang
- PlantCLEF weights letöltés
- Qwen model cache
- Self-host Docker inference

---

## Kapcsolódó

- `docs/AI_VISION_MILESTONE.md` – már cloud-first
- `services/AiVisionService.php` – alap vision
- `CIVICAI_MASTER_ROADMAP.md` – M17/M20 cloud path

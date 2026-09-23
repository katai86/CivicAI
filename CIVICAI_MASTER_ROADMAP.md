# CivicAI Master Roadmap – City Intelligence + Plant & Tree Intelligence

**Utolsó frissítés:** 2026-09-01  
**Igazság forrása:** ez a fájl + `docs/milestones/MXX_COMPLETION.md`  
**Szabály:** csak `NOT_STARTED` | `IN_PROGRESS` | `BLOCKED` | `DONE`

---

## Összesítő

| Állapot | Darab |
|---------|-------|
| DONE | 1 |
| IN_PROGRESS | 26 |
| BLOCKED | 0 |
| NOT_STARTED | 1 |

**Projekt kész:** NEM (M0–M26 mind DONE + Production Gate szükséges)

---

## Milestone tábla

| Milestone | Status | Backend | DB | API | UI | AI/ML | Real Data | Tests | Integration | Evidence |
|-----------|--------|---------|----|----|----|-------|-----------|-------|-------------|----------|
| **M0** Full repository audit | DONE | audit doc | — | — | — | audit | audit | audit script | map existing | audit findings |
| **M1** Unified observation & evidence foundation | IN_PROGRESS | yes | migration | — | — | types | partial | partial | yes | partial |
| **M2** Spatial intelligence / city zones | IN_PROGRESS | CityZoneEngine | yes | spatial API | map partial | — | yes | partial | partial | no |
| **M3** City priority engine 0–100 | IN_PROGRESS | UnifiedPriorityEngine | yes | city_priorities | partial | — | yes | partial | partial | no |
| **M5** CivicAI discoveries engine | IN_PROGRESS | CityDiscoveryEngine | yes | city_discoveries | partial | template | yes | partial | partial | partial |
| **M7** Change intelligence | IN_PROGRESS | CityChangeIntelligence | yes | city_change | partial | — | partial | partial | partial | no |
| **M15** Plant & Tree AI model router | IN_PROGRESS | PlantTreeVisionRouter | yes | plant_analyze | partial | cloud | partial | partial | partial | partial |
| **M16** Provider architecture | IN_PROGRESS | yes | — | health API | — | cloud | partial | partial | partial | partial |
| **M17** Species engine (cloud) | IN_PROGRESS | PlantNet+HF | — | yes | — | PlantNet + HF | partial | partial | partial | partial |
| **M18** Confidence / unknown / quality | IN_PROGRESS | yes | — | — | — | yes | — | partial | partial | partial |
| **M20** Visual condition engine (cloud) | IN_PROGRESS | CloudVisionCondition | — | yes | — | Pixtral/GPT-4o | partial | partial | partial | partial |
| **M21** Segmentation & measurements | IN_PROGRESS | SegmentationEstimate | — | yes | — | cloud ESTIMATED | — | partial | partial | partial |
| **M22** TreeHealth + public risk engine | IN_PROGRESS | yes | trees tbl | tree_health | partial | PHP engines | partial | partial | partial | partial |
| **M23** Multi-model fusion + PlantNet | IN_PROGRESS | PlantVisionFusion | — | yes | — | yes | partial | partial | partial | partial |
| **M24** Tree registry temporal intelligence | IN_PROGRESS | tree_inspections | yes | partial | partial | yes | yes | partial | partial | partial |
| **M25** Tree → City Intelligence integration | IN_PROGRESS | PlantTreeCiBridge | yes | yes | partial | — | partial | partial | yes | partial |
| **M4** City situation map | IN_PROGRESS | CitySituationEngine | yes | city_situation | partial | — | partial | partial | partial | partial |
| **M6** Evidence explorer | IN_PROGRESS | CivicEvidenceLinkStore | yes | city_evidence | gov partial | — | yes | partial | yes | partial |
| **M8** Executive morning brief 2.0 | IN_PROGRESS | morning_brief+CI | no | yes | yes | LLM optional | yes | partial | partial | partial |
| **M9** Gov Copilot 2.0 | NOT_STARTED | GovCopilot | no | gov_copilot | yes | LLM | yes | no | partial | no |
| **M10** City Health 2.0 | IN_PROGRESS | CityHealthScoreV2 | yes | city_scenario | partial | — | yes | partial | partial | partial |
| **M11** Pattern & correlation engine | IN_PROGRESS | PatternCorrelationEngine | — | city_scenario | — | — | partial | partial | partial | partial |
| **M12** City time machine | IN_PROGRESS | CityTimeMachine | yes | city_scenario | — | — | partial | partial | partial | partial |
| **M13** Scenario engine | IN_PROGRESS | ScenarioEngine | — | city_scenario | — | — | partial | partial | partial | partial |
| **M14** Automated intelligence reports | IN_PROGRESS | intel report | no | partial | partial | LLM | partial | partial | partial | partial |
| **M19** Multi-image + plant part | IN_PROGRESS | TreeInspectionSession | yes | plant_session | partial | cloud | partial | partial | partial | partial |
| **M26** Full QA / production gate | IN_PROGRESS | partial | partial | partial | partial | cloud | partial | yes | partial | partial |
| **M27** Full i18n all pages | IN_PROGRESS | — | — | — | partial | — | — | — | — | — |

---

## Meglévő infrastruktúra (M0 audit – ne duplikáld)

| Terület | Fájl / modul | Megjegyzés |
| City Intelligence core | `services/cityintel/*`, `sql/2026-32-*` | observations, indicators, baselines, anomalies, insights |
| Plant & Tree cloud | `services/plant/*`, `api/plant_analyze.php` | PlantNet, HuggingFace, Pixtral pipeline |
| Spatial (grid) | `CitySpatialEngine.php`, `CityZoneEngine.php` | grid + city_zones tábla |
| Vision (cloud) | `AiVisionService.php`, `UrbanObservationService.php` | Mistral/OpenAI vision, nem PlantCLEF/Qwen |
| Tree cadastre | `trees`, `tree_logs`, `tree_species_care` | manuális health/risk mezők |
| Priority (reports) | `PrioritizationEngine.php` | csak nyitott ügyek, nem unified 0–100 |
| City Health | `CityHealthScore.php` | 4 dimenzió, szabályalapú, nincs verzió/történet |
| Copilot | `GovCopilot.php` | LLM + kontextus, nincs controlled query pipeline |
| Morning brief | `api/morning_brief.php` | szabály + opcionális AI |
| EU / Green | `GreenIntelligence`, `CopernicusDataService` | NDVI OAuth-függő |
| IoT | `virtual_sensors`, `cron_iot_sync.php` | működő váz |
| Reference/mock | `ViirsDataService`, `HungaroMetDataService`, GBIF/OCM reference | **NEM production truth** |
| Queue/worker | — | **NINCS** async job rendszer |
| GPU inference | — | **NINCS** self-host |

---

## Blokkolók (BLOCKED)

**Self-host GPU path:** opcionális, NEM kötelező. A production út = **Cloud Plant & Tree Intelligence** (lásd alább).

| Régi blocker | Cloud helyettesítés |
|--------------|---------------------|
| PlantCLEF (local GPU) | **PlantNet API** + Mistral/OpenAI vision structured JSON |
| Qwen3-VL (local) | **Pixtral / GPT-4o vision** strict JSON visual observations |
| SAM2/YOLO (local) | Vision-leírás + **ESTIMATED** mérések; opcionális Replicate API (pay-per-use, nem self-host) |

---

## Következő lépés (session)

1. ~~M0 → DONE~~
2. **Deploy SQL:** `2026-33`, `2026-34-plant-tree-city-intelligence.sql`
3. **Admin → Beépülő modulok → Plant & Tree:** PlantNet + Mistral/OpenAI kulcsok
4. M1 DONE gate – integration test production DB-n

---

## Completion fájlok

| Milestone | Completion doc |
|-----------|----------------|
| M0 | `docs/milestones/M00_COMPLETION.md` |
| M1 | `docs/milestones/M01_COMPLETION.md` (pending) |
| … | … |

---

## Definition of Done (M26 gate)

- [ ] M0–M25 mind DONE
- [ ] M26 QA gate passed
- [ ] Cloud Plant & Tree E2E (PlantNet + Pixtral/OpenAI vision)
- [ ] City Intelligence E2E
- [ ] Cross-signal tree + NDVI + weather
- [ ] No critical mock in production path
- [ ] Authority isolation verified
- [ ] `CIVICAI_IMPLEMENTATION_REPORT.md` final

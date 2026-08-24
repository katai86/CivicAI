# AI Vision – Milestone (100% production-közeli cél)

**Cél:** Feltöltött közterületi fotó → valódi multimodális AI elemzés (Mistral Pixtral / OpenAI) → kategória, prioritás, leírás, confidence, veszély → mentés és UI.

**Nem cél ebben a milestone-ban:** saját SAM2/YOLO self-host (későbbi opcionális hardveres réteg).

---

## Állapot a milestone előtt

| Réteg | Állapot |
|-------|---------|
| Gov „AI Vision” (SAM/YOLO/BLIP UI) | Mock |
| Fa fotóelemzés | Élő `callWithImage` |
| Bejelentés fotó | Nincs vision |

---

## Milestone checklist

### M-V1 – Infrastrukturális finomítás ✅ (implementáció)

- [x] `AiRouter::callWithImage` bővíthető timeout / max_tokens
- [x] Civic vision system + user prompt (`AiPromptBuilder`)
- [x] JSON normalizálás (`AiResultParser`)

### M-V2 – Valódi gov AI Vision ✅

- [x] `AiVisionService` mock helyett felhő vision
- [x] `api/ai_vision_analyze.php` tényleges képtovábbítás
- [x] Elemzési módok (blip / yolo / sam2 / depth) = prompt-profilok, nem külön local modellek
- [x] Gov UI eredmény: kategória, prioritás, leírás, objektumok, confidence

### M-V3 – Polgári bejelentés fotó ✅

- [x] `api/report_vision_analyze.php` – fotó → javaslatok (login)
- [x] Bejelentés űrlap: „Fotó elemzése (AI)” nem csak fához
- [x] `report_upload.php` – feltöltés után best-effort vision + `ai_results`

### M-V4 – Dokumentáció / státusz ✅

- [x] Ez a milestone fájl
- [x] `INTELLIGENCE_PLATFORM.md` M5 frissítés
- [x] i18n: ne „mock” legyen a szöveg

### M-V5 – Későbbi (opcionális, nem blokkolja a demót)

- [ ] Self-host SAM2 / YOLO GPU pipeline
- [ ] Gemini vision provider
- [ ] Bounding box overlay a térképen
- [ ] Batch / async queue nagy forgalomra

---

## Acceptance (Budaörs demo)

1. Admin: Mistral **vagy** OpenAI bekapcsolva, API kulcs, `ai_image_analysis_limit` > 0.
2. Gov → Klíma → AI képfelismerés: feltöltés → **néhány másodperc** → valódi leírás + kategória (nincs `preview_mock`).
3. Térkép → új bejelentés → fotó → AI elemzés → kategória / cím / leírás kitöltés.
4. Feltöltött csatolmány után `ai_results` sor `image_classification` task_type-pal.

---

## Technikai döntés

**Egy felhő multimodális modell** (Pixtral / GPT-4o-mini) helyettesíti a mock SAM/YOLO/BLIP demót. A UI „modell” választója **elemzési fókuszt** jelent, nem külön weights fájlokat.

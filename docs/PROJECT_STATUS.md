# CivicAI – projekt állapot (2026-08-25)

**Egy mondat:** CivicAI egy **demózható civic-tech MVP**: polgári térkép + bejelentés/ötlet/költségvetés + fa kataszter + gov Intelligence/City Brain + felhő AI vision — **nem** még teljes production SaaS.

**Repo:** ~102 API végpont, ~37 service, domain: `civicai.hu` · main: `cfb7af6+`

---

## Mi van meg (kész / demózható)

| Terület | Tartalom |
|---------|----------|
| **Polgári core** | Térkép (Leaflet), bejelentés + fotó, státusz, like, nyilvános ügy token, XP/badge, barátok, leaderboard |
| **Ötletek** | Beküldés, szavazás, térkép, gov státuszkezelés |
| **Részvételi költségvetés** | Projektek, szavazás, modul ki/be |
| **Fa / Green** | Kataszter, örökbefogadás, öntözés, AI egészség, öntözendő lista |
| **Gov** | KPI áttekintés, Analytics, ESG, Copilot, felmérések, export |
| **Admin** | User/authority/réteg/modul CRUD, AI + FMS kulcsok |
| **Open311 / FMS** | v2 API + bridge export/sync |
| **AI** | AiRouter (Mistral/OpenAI/Gemini), összefoglaló, kategória, fa + közterület vision |
| **City Brain Copilot & AI Vision** | Utcai fotó → út állapot, zöld %, fa faj/egészség/méret (WOW demó) |
| **Intelligence** | GBIF, HungaroMet, PVGIS, VIIRS, OCM, GFW, klímaindex, jelentés HTML/PDF |
| **EU / HU open data** | EEA/Eurostat/CAMS stb.; KSH gyakran snapshot |
| **IoT váz** | virtual_sensors táblák, adapterek, cron, gov IoT fül |
| **Ops / security** | Health check, cache/timeout, uploads PHP tiltás, webshell szkenner |
| **i18n** | HU/EN erős; további nyelvek részben |

---

## Részben kész / demón figyelni

| Terület | Megjegyzés |
|---------|------------|
| City Brain többi fül | Menü kész; predictive/hotspot/risk vékonyabb / szabályalapú |
| Copernicus OAuth / CLMS | Kulcs nélkül 401 + timeout → **KI** demóra |
| AI Vision „SAM/YOLO/BLIP” | Prompt-profilok, **nem** self-host GPU modell |
| KSH élő | Gyakran nem elérhető → referencia OK |
| IoT mélység | Struktúra kész, nem minden provider production-grade |
| Design | Gov frissebb; admin/landing vegyes |

---

## Hiányzik / később

- Self-host SAM2/YOLO, 3D Digital Twin (nem cél)
- Egységes marketing landing
- Observability (error/metrika dashboard)
- Bejelentés wizard + draft (UX backlog)
- Batch/async vision queue
- Partner Open Data API dokumentáció
- Teljes SaaS ops (billing, SLA monitoring)

---

## Budaörs demó checklist

1. `git pull` main  
2. SQL: `2026-28-ai-results-utf8mb4.sql` + `2026-29-demo-intelligence-active.sql`  
3. Admin: Mistral **vagy** OpenAI BE + kulcs + `ai_image_analysis_limit` > 0  
4. EU: Copernicus + CLMS **KI**, ha nincs érvényes kulcs  
5. **WOW:** Gov → City Brain → **Copilot & AI Vision** → utcai fotó  

---

## Kapcsolódó docok

- `docs/PROJECT_SUMMARY.md` – részletes funkciólista  
- `docs/MVP_READINESS.md` – bemutató scorecard  
- `docs/INTELLIGENCE_PLATFORM.md` – intel modulok  
- `docs/AI_VISION_MILESTONE.md` – vision állapot  
- `docs/USER_FRIENDLY_BACKLOG.md` – UX következő lépések  

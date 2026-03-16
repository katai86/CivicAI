# Jövőbeli AI funkciók (placeholder)

Ez a dokumentum a későbbi bővítések architektúra-tervét írja le. **A 2. szakasz („Már megvalósítva”) kivételével** nincs új production kód vagy tábla a leírt további témákhoz – csak terv és illeszkedés a meglévő AiRouter és statisztika modulhoz.

---

## Már megvalósítva (M10 bővítések)

| Funkció | Leírás | Fájlok |
|---------|--------|--------|
| **Gov AI Copilot** | Kérdés–válasz a hatósági adatokról (kerületek, problémák, fák). | api/gov_copilot.php, services/GovCopilot.php; Gov dashboard kártya. |
| **Surveys fül** | Aktív/lezárt felmérések listája, eredmények megtekintése. | api/gov_surveys.php; Gov „Felmérések” tab. |
| **Predictions (Analytics)** | Városi kockázat előrejelzés (várható problémák, kockázati zónák, fa kockázat). | api/predictions.php; Gov Analytics tab. |
| **Green Intelligence (Analytics)** | Lombkorona, CO₂, biodiverzitás, szárazság kockázat. | api/green_metrics.php; Gov Analytics tab. |
| **ESG Command Center (Analytics)** | E/S/G mutatók, JSON/CSV export. | api/esg_metrics.php; Gov Analytics tab. |

A fenti részek production kódban megvannak. Az alábbi szakaszok továbbra is **terv** (placeholder).

---

## 1. Lehetséges témák

| Téma | Rövid leírás | Adatigény |
|------|--------------|-----------|
| **Karbantartási predikció** | Bejelentések / kategóriák alapján előrejelzés: hol, mikor várható több ügy (pl. téli útkárosodás). | `reports` (kategória, dátum, város, státusz), idősor; opcionálisan időjárás. |
| **Hősziget érzékelés** | Hőtérkép vagy index a zöldterek / fák hiánya alapján. | `trees`, `reports` (zöld kategória), esetleg külső hőmérséklet/domborzat. |
| **Árvíz kockázat** | Területi esővíz / árvíz kockázat becslése. | Bejelentések (víz, csatorna), domborzat, eső adat (külső API). |
| **Zöldfedettség elemzés** | Zöldfelület arány, fajta diverzitás, javaslatok. | `trees` (fajta, életkor, egészség), térképes rétegek. |
| **Digitális iker (city twin)** | Város modell – szimuláció, forgatókönyvek. | Összesített adat (reports, trees, ESG), 3D/területi modell (későbbi fázis). |

---

## 2. Javasolt API / interface

- **Prediction service**  
  Egy központi végpont (pl. `api/ai_predict.php` vagy `services/PredictionService.php`), amely:
  - Bemenet: `type` (maintenance | heat_island | flood_risk | green_cover), `authority_id` vagy `city`, opcionálisan `timeframe`, `bbox`.
  - Kimenet: strukturált JSON (pl. `risk_level`, `hotspots[]`, `recommendations[]`).
  - A meglévő **AiRouter**-t bővíteni egy `callPrediction(type, params)` metódussal, amely a megfelelő promptot és (ha kell) külső adatlekérést hívja.

- **Rate limit**  
  Új task típus pl. `prediction` az `ai_rate_limits` táblában (vagy meglévő `image_analysis` / `report_generation` mellett), hogy a predikciók ne terheljék túl a szolgáltatást.

- **Cache**  
  Predikció eredmények cache-elése (pl. 24 óra) `authority_id` + `type` + `timeframe` alapján, hogy a dashboard ne hívja folyamatosan az AI-t.

---

## 3. Illeszkedés a jelenlegi rendszerhez

- **AiRouter** (`services/AiRouter.php`):  
  Új metódus(ok) a fenti típusokhoz; a meglévő `callWithImage()` és szöveges hívások mellett egy „prediction” ág, ahol a prompt a statisztika modul kimenetéből épül (pl. analytics aggregátumok, ESG metrikák).

- **Statisztika modul** (`api/analytics.php`, `api/esg_export.php`):  
  Ezek továbbra is az igaz adatforrások; az AI csak ezekből generált összefoglalót / predikciót ad. Nem kell duplikálni a számításokat – az AI bemenet = meglévő API válasz (vagy annak részhalmaza).

- **Gov / Admin dashboard**  
  Új kártyák vagy aloldalak: pl. „Karbantartási előrejelzés”, „Zöldfedettség összefoglaló”. Adatforrás: a fenti prediction API; megjelenítés: formázott szöveg vagy egyszerű lista, ahogy az M2 AI jelentésnél.

---

## 4. Következő lépések (opcionális)

1. Adatgyűjtés finomítása: mely mezők kellenek a predikciókhoz (pl. `reports.suburb`, pontos koordináta).
2. Külső API-k kiválasztása (időjárás, domborzat), ha szükséges.
3. `PredictionService` vagy `api/ai_predict.php` váz implementálása és AiRouter bővítése.
4. Dashboard widget(ek) és cache réteg hozzáadása.

---

*Dokumentum: placeholder a Smart City M10 milestonehoz. Frissítve: 2026.*

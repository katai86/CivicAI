# Gov index – fejlesztések visszarakása lépésről lépésre

Az index most a **stabil alapverzió**. Teszteld: ha 500-at kapsz, valószínűleg a report lista `u.level` / `u.profile_public` oszlopai hiányoznak a szerver `users` táblájából (lásd 1. lépés).

Egyenként add vissza az alábbi blokkokat. **Ha valamelyik lépés után megjelenik a 500, az a blokk a hibás.**

---

## 1. lépés: Report lista védelem (ajánlott először)
Ha a stabil verzió is 500-at dob, a report lista SELECT-je hibás lehet (pl. nincs `users.level` / `users.profile_public`).
- **Változtatás:** A report lista SELECT-ből vedd ki az `u.level AS reporter_level` és `u.profile_public AS reporter_profile_public` oszlopokat, és a lista lekérdezést tedd try-catch blokkba (hiba esetén üres lista).

---

## 2. lépés: Else ág a statisztikához
Ha a gov usernek nincs hatósága, a `$stats['environment']` stb. nincs beállítva.
- **Változtatás:** Az `if ($isAdmin || $authorityIds) { ... $stats['governance'] = $gov; }` után add hozzá: `} else { $stats['environment'] = [...]; $stats['social'] = [...]; $stats['governance'] = [...]; }`

---

## 3. lépés: Surveys fül
- **HTML:** Új nav elem (Felmérések) a menüben + új tab body `id="tab-surveys"` (lista, eredmények, govSurveysList, govSurveyResults, govSurveyResultsBack).
- **JS:** `govSurveysUrl`, tab listába `'surveys'`, `if (key === 'surveys') loadGovSurveys();`, valamint a `loadGovSurveys` és `showGovSurveyResults` függvények + a Back gomb listener.

---

## 4. lépés: AI Copilot kártya
- **HTML:** A dashboardon a City Health kártya után add hozzá a `id="govCopilotCard"` kártyát (textarea, Küldés gomb, válasz/hiba div).
- **JS:** `govCopilotUrl`, és az `initGovCopilot()` IIFE (postJson gov_copilot.php-ra).

---

## 5. lépés: Analytics + EU nyílt adatok + előrejelzések + ESG
- **Analytics tab (`tab-analytics`):** Hőtérkép, statisztika, sentiment, **Predictions** (`govPredictionsContent`), **ESG Command Center** (`govEsgMetricsContent` + linkEsgCommandJson, linkEsgCommandCsv). A zöld (Copernicus) mutatók és műhold/EU rétegek **nem** ide tartoznak.
- **EU nyílt adatok tab (`tab-eu-open-data`):** Zöld intelligencia kártya `govEuTabGreenMetrics`, Copernicus / műhold blokk (`govEuGreenSatelliteContent`), EU térkép `govEuGreenMap` + `loadGovEuGreenMapOverlay`, levegő/klíma/Eurostat kártyák. Tab váltáskor: `loadGovGreenMetrics`, `loadGovEuAirQuality`, `loadGovEuClimate`, `loadGovEuCountryContext`, `initGovEuGreenMap`, `loadGovEuGreenMapOverlay`.
- **Áttekintés (dashboard):** EEA & INSPIRE blokk: `govDashboardEeaInspireContent`, `loadGovEuEeaInspire`.
- **JS:** `govPredictionsUrl`, `govPredictionsLabels`, `govGreenMetricsUrl`, `govGreenMetricsLabels`, `govEsgMetricsUrl`, `govEsgMetricsLabels`, `govEuGreenOverlayUrl`, `govMapJsLabels` (OSM/Esri rétegcímkék), stb. Analytics fülön: `loadGovPredictions(); loadGovEsgMetrics();` (és a meglévő statisztika/sentiment). A `loadGovEsgMetrics` végén: linkEsgCommandJson/Csv href frissítése esgExportUrl-ra.

---

## 6. lépés: Debug kapcsoló (opcionális)
- **PHP:** Fájl elején: `if (isset($_GET['debug']) && $_GET['debug'] === '1') { error_reporting(E_ALL); ini_set('display_errors','1'); }` – így ?debug=1 mellett látszik a PHP hiba a 500 helyett.

---

**Javasolt sorrend:** 1 → 2 → 3 → 4 → 5. Ha 3-nál jön a 500, a Surveys blokk a gond; ha 4-nél, a Copilot; ha 5-nél, valamelyik analytics/EU bővítmény.

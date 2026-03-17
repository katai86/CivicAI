# CivicAI – IoT / Virtual Sensors & City Brain – Milestone terv

## M1 – Codebase & architektúra elemzés (KÉSZ)

### Térkép rétegek
- **map_layers** + **map_layer_points** (sql); **api/layers_public.php** visszaadja az aktív layereket; **assets/app.js** `loadLayerMarkers()`, `loadTrees()` – a trees réteg külön **api/trees_list.php**.
- Új virtuális szenzorok: vagy új réteg típus (pl. `layer_type = 'iot'`), vagy külön API (pl. **api/virtual_sensors_list.php**) + térképen külön marker réteg – **nem** keverjük a map_layer_points-szel (az választás/szavazóhelyiségek stb.).

### Modulok (admin + gov)
- **module_settings** (module_key, setting_key, value): admin által beállított API kulcsok, enabled (mistral, fms, openai, participatory_budget, surveys).
- **api/admin_modules.php**: `$MODULE_DEFS` – minden modulhoz settings (enabled, api_key, stb.). GET lista (maszkolt jelszó), POST save_module.
- **user_module_toggles** (user_id, module_key, is_enabled): gov user szintű ki/be (AI, FMS, budget, surveys).
- **api/gov_modules.php**: GET/POST – gov felület Modulok fül: kapcsolók mentése; surveys/budget esetén szinkron **module_settings**-be (nyilvános menü).
- **util.php**: `get_module_setting()`, `user_module_enabled($userId, $moduleKey)`.

### Cron / scheduler
- Nincs általános job runner; van **api/cron_send_greetings.php**. Az IoT sync-nek külön cron endpoint kell (pl. **api/cron_iot_sync.php**) vagy külső cron hívja.

### Gov dashboard
- **gov/index.php**: tab-ok (dashboard, reports, ideas, surveys, budget, trees, ai, analytics, **iot**, modules). IoT tab már létezik, tartalma üres/placeholder. Feltételes megjelenés: `$govSurveysEnabled`, `$govBudgetEnabled` – ugyanígy kell **$govIotEnabled**.

### Újrafelhasználás / kiterjesztés
- **Újrafelhasználjuk**: module_settings, user_module_toggles, admin_modules.php pattern, gov_modules.php pattern, map (Leaflet), gov tab rendszer, lang t().
- **Kiterjesztjük**: admin MODULE_DEFS (iot modul, provider API kulcsok), gov defs + IoT kapcsoló, gov menü (City Brain + almenük), új táblák (virtual_sensors, metrics), új API-k (virtual_sensors_list, cron_iot_sync).
- **Kerüljük**: map_layer_points használata IoT szenzorokra (külön adatmodell), duplikált map logika.

---

## M2 – Egyesített adatmodell (virtual_sensors táblák)

- **virtual_sensors**: id, source_provider, external_station_id, name, sensor_type, category, lat, lng, address_or_area_name, municipality, country, ownership_type, display_mode, status, trust_score, confidence_score, license_note, api_source_url, is_active, last_seen_at, created_at, updated_at.
- **virtual_sensor_metrics_latest**: id, virtual_sensor_id, metric_key, metric_value, metric_unit, measured_at, quality_flag, raw_payload_reference, created_at.
- **virtual_sensor_metric_history**: id, virtual_sensor_id, metric_key, metric_value, metric_unit, measured_at, quality_flag, raw_payload_reference, created_at.
- **virtual_sensor_provider_logs**: id, provider_name, sync_started_at, sync_finished_at, status, imported_count, updated_count, error_count, log_message, created_at.
- Opcionális: virtual_sensor_raw_cache.

Fájl: **sql/2026-iot-virtual-sensors.sql** + beépítés **01_consolidated_migrations.sql**-ba (vagy külön migration).

---

## M3 – Admin: IoT modul (API kulcsok, provider konfig)

- **api/admin_modules.php**: Új modul `iot` (vagy `iot_virtual_sensors`) a `$MODULE_DEFS`-ben.
  - enabled (checkbox)
  - Per-provider beállítások (opcionális kezdetben egy közös „providers” blokk, vagy külön setting_key-k: openaq_api_key, aqicn_api_key, openweather_api_key, weatherxm_api_key, pws_api_key, sensor_community_* stb.).
  - A prompt szerint: sync frequency, station radius/bounds, municipality filter, max stations – ezek később is beépíthetők; első lépésben: enabled + API kulcsok (ahol kell).
- Provider adapter framework és konkrét adapterek (OpenAQ, AQICN, OpenWeather, stb.) későbbi milestone (M6–M7).

---

## M4 – Gov: IoT ki/be + City Brain menü

- **api/gov_modules.php**: `$defs`-hez hozzáadni `['key' => 'iot', 'label' => '...', 'description' => '...']`.
- **gov/index.php**:
  - `$govIotEnabled = $isAdmin ? true : user_module_enabled($govUid, 'iot');`
  - IoT menüpont (tab) csak ha `$govIotEnabled`.
  - **City Brain** menüsor: új nav-header + almenük (Live Intelligence, Predictive AI, Hotspot Detection, Behavior & Trends, Environmental AI, AI Insights, Risk & Alerts). Kezdetben mindegyik egy-egy tab vagy subview (placeholder tartalom), a menü struktúra és routing kész.
- **Nyelvesítés**: gov.tab_iot, gov.city_brain, gov.city_brain_live_intelligence, gov.city_brain_predictive_ai, stb. (hu, en, de, fr, it, es, sl).

---

## M5 – Térkép réteg + dashboard widgetek (IoT)

- **api/virtual_sensors_list.php**: GET – authority_id / bounds / municipality alapján virtuális szenzorok listája (normalized), gov jogosultság ellenőrzéssel.
- Gov IoT tab: térkép (Leaflet) + a kiválasztott hatóság (pl. Orosháza) szenzorai, fölötte összesítő kártyák (összes szenzor, aktív, átlag AQI, stb.). Markerek: egyértelmű ikon (weather, air quality, external source).
- Szenzor részletek panel/drawer: név, provider, koordináta, utolsó frissítés, legfrissebb metrikák, mini trend (később).

---

## M6 – Provider adapterek és normalizálás

- Közös interface: fetchStations(), fetchLatestMetrics(), normalizeStation(), normalizeMetrics().
- Adapterek: OpenAQ, AQICN, OpenWeather, WeatherXM, PWS/Aeris, Sensor.community (Tier 1–3 sorrendben).
- Metrika normalizálás: °C, %, hPa, m/s, mm, µg/m³, AQI – egy közös belső séma.

---

## M7 – Scheduled ingestion (cron)

- **api/cron_iot_sync.php**: provider konfig alapján station discovery + latest metrics frissítés, virtual_sensor_provider_logs írása. Rate limit, hibakezelés.

---

## M8–M17

- M8: Gov dashboard IoT kártyák (Total Sensors, Average AQI, PM2.5, Temperature, stb.), chartok (trend, provider breakdown).
- M9: Térkép marker szűrők (provider, metric type, freshness).
- M10: Szenzor részletek panel (map click + táblázat click).
- M11: Admin/gov táblanézet, szűrők, export (CSV/JSON/GeoJSON).
- M12: ownership_type (external / civicai), UI jelölés.
- M13: trust_score, freshness_score, confidence_score számítás és megjelenítés.
- M14: Provider prioritás (Tier 1–3), Sensor.community experimental.
- M15: Demo mód, címkék (Imported Network, CivicAI Sensor).
- M16: Kód dokumentáció (adapterek, új provider, sync, migráció).
- M17: Jövőbeli bővítések (valódi hardware, fa/talaj szenzorok, AI anomália) – architektúra készen tartása.

---

## Nyelvesítés (közös)

Minden felhasználó felé megjelenő szöveg: **t('key')**; lang fájlok: hu, en, de, fr, it, es, sl.

- **gov.tab_iot** – már létezik (Szenzorok / Sensors).
- **gov.iot_enabled**, **gov.iot_description** – Modulok fül.
- **gov.city_brain**, **gov.city_brain_live_intelligence**, **gov.city_brain_predictive_ai**, **gov.city_brain_hotspot**, **gov.city_brain_behavior_trends**, **gov.city_brain_environmental_ai**, **gov.city_brain_ai_insights**, **gov.city_brain_risk_alerts**.
- **admin.iot_module_name**, **admin.iot_module_description**, **iot.provider_openaq**, **iot.provider_aqicn**, stb. (ahol kell).
- **iot.total_sensors**, **iot.active_sensors**, **iot.average_aqi**, stb. (dashboard kártyák).

Implementáció sorrendje: M2 → M3 → M4 (+ nyelvesítés) → M5 → M6 → M7 → …

# IoT / Virtual Sensors – Provider adapterek és sync

## Áttekintés

A virtuális szenzorok külső API-kból (OpenAQ, OpenWeather, stb.) érkeznek. Minden providerhez egy **adapter** tartozik, amely implementálja a `CivicAI\Iot\VirtualSensorProviderInterface` interface-t.

- **api/iot/VirtualSensorProviderInterface.php** – interface (fetchStations, fetchLatestMetrics, normalizeStation, normalizeMetrics)
- **api/iot/AbstractProvider.php** – alap osztály (HTTP, getIotSetting)
- **api/iot/OpenAQAdapter.php** – OpenAQ v3 (légszennyezés)
- **api/iot/OpenWeatherAdapter.php** – OpenWeatherMap (időjárás)
- **api/iot/ProviderRegistry.php** – konfigurált adapterek listája

## Új provider hozzáadása

1. **Új adapter osztály** (pl. `api/iot/AQICNAdapter.php`):
   - `extends AbstractProvider` és `implements VirtualSensorProviderInterface`
   - `getProviderKey()` – egyedi kulcs (pl. `aqicn`)
   - `isConfigured()` – pl. `get_module_setting('iot', 'aqicn_api_key')` nem üres
   - `fetchStations($options)` – API hívás, vissza: nyers állomáslista
   - `fetchLatestMetrics($externalStationIds)` – vissza: `[ external_id => [ raw measurement, ... ] ]`
   - `normalizeStation($raw)` – vissza: tömb (source_provider, external_station_id, name, latitude, longitude, municipality, country, …)
   - `normalizeMetrics($raw)` – vissza: `[ ['metric_key' => 'aqi', 'metric_value' => 42, 'metric_unit' => null, 'measured_at' => '...'], ... ]`

2. **Admin modul beállítás** (`api/admin_modules.php`):
   - Az `iot` modul `settings` tömbjébe új elem, pl. `['key' => 'aqicn_api_key', 'label' => 'AQICN API token', 'type' => 'password', 'mask' => true]`

3. **Regiszter** (`api/iot/ProviderRegistry.php`):
   - `getConfiguredAdapters()` tömbben add hozzá: `new AQICNAdapter()`

4. **Cron** (`api/cron_iot_sync.php`):
   - Ha a providernek speciális `$options` kell (pl. csak városlista), a `foreach ($adapters as $providerKey => $adapter)` blokkban kezeld a `$providerKey === 'aqicn'` esetet (pl. `$options['cities']`).

## Sync (cron)

- **Endpoint:** `GET api/cron_iot_sync.php` (opcionális: `?token=ADMIN_TOKEN`)
- **Feltétel:** IoT modul enabled, táblák léteznek.
- **Lépések:** Minden konfigurált adapterre: fetchStations → upsert `virtual_sensors` → fetchLatestMetrics (vagy beágyazott metrics) → upsert `virtual_sensor_metrics_latest`, frissítés `last_seen_at` → írás `virtual_sensor_provider_logs`.
- **Hatókör:** Authority városok és bbox (OpenAQ: bbox, OpenWeather: cities vagy egy default coord).

## Adatbázis (migráció)

- **Egységesített:** `sql/01_consolidated_migrations.sql` – tartalmazza a 2026-IOT szekciót.
- **Önálló IoT:** `sql/2026-iot-virtual-sensors.sql` – csak a virtuális szenzor táblák.

Táblák: `virtual_sensors`, `virtual_sensor_metrics_latest`, `virtual_sensor_metric_history`, `virtual_sensor_provider_logs`.

## Provider Tier (UI)

A Gov IoT részletpanelen megjelenik a provider szint (Tier 1/2/3). Jelenleg statikus megfeleltetés:
- **Tier 1:** OpenAQ
- **Tier 2:** OpenWeather, AQICN, egyéb
- **Tier 3:** Sensor.community (kísérleti)

A megfeleltetés a frontendben van: `getGovIotProviderTier(provider)`.

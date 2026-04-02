# EU Open Data – Urban Intelligence réteg

Ez a dokumentum a **CivicAI** hivatalos EU-forrású adatintegrációjának alapját írja le. A megvalósítás a meglévő PHP + MariaDB architektúrát bővíti; **nem** váltja ki az IoT virtuális szenzor modult (`module_key: iot`), a `cron_iot_sync.php` és az `admin_iot_sync.php` változatlan elvű marad.

## Milestone 1 (kész)

- **Táblák:** `external_data_cache`, `external_data_provider_logs` – migráció: `sql/2026-25-eu-open-data-foundation.sql` (benne: `sql/01_consolidated_migrations.sql`, `sql/00_run_all_migrations_safe.sql`).
- **Admin modul:** `module_key` = `eu_open_data` – Beépülő modulok fülön külön szekció („EU nyílt adatok”).
- **Szolgáltatások:**
  - `services/ExternalHttpClient.php` – időkorlátos GET, User-Agent, curl vagy `file_get_contents` fallback.
  - `services/ExternalDataCache.php` – cache olvasás/írás, lejárt törlés, provider napló.
- **Segédfüggvények** (`util.php`): `eu_open_data_module_enabled()`, `eu_open_data_feature_enabled($key)`, `eu_open_data_request_timeout_seconds()`, `eu_open_data_cache_ttl_minutes()`, `eu_open_data_sync_enabled()`.

## Milestone 2 (kész – Copernicus zöld / NDVI kontextus)

- **`services/CopernicusDataService.php`** – CDSE OAuth (client credentials, cache), **STAC** `POST https://stac.dataspace.copernicus.eu/v1/search` (Sentinel-2 L2A tételek száma a bbox-ban), helyi rács: zöld hiány / ültetési prioritás, NDVI-szerű és felszín proxy a fakataszter + bejelentések alapján.
- **`services/GreenIntelligence.php`** – ha `eu_open_data` + `copernicus_enabled`: kiegészítés `ndvi_score`, `green_deficit_score`, `sealed_surface_pressure`, `vegetation_health_score`, `planting_priority_zones`, `data_sources`, stb.
- **`api/green_metrics.php`** – válasz: `source`, `scope`, `data`, `meta` (confidence, data_sources).
- **`api/eu_green_overlay.php`** – GeoJSON pontok (`layer_type`: `ndvi`, `green_deficit`, `planting_priority`, `vegetation_health`), csak gov/admin.
- **Gov dashboard:** új kártya (EU / műholdas zöld), a meglévő Green Intelligence betöltőfüggvény bővítve.
- **Nyilvános térkép (desktop):** jelmagyarázat panelben EU réteg (csak govuser/admin/superadmin, ha Copernicus részmodul be van kapcsolva) – `inc_desktop_topbar.php` + `assets/app.js`.

Valós **pixel NDVI / Process API** nem kötelező ehhez a lépéshez; a struktúra és a cache készen áll a későbbi bővítésre.

## Milestone 3 (kész – CLMS Urban Atlas 2018)

- **`services/ClmsUrbanAtlasService.php`** – EEA Discomap ArcGIS REST: `UA_UrbanAtlas_2018` / „Land Use vector” réteg, **group statistics** (`Shape_Area` összeg `code_2018` szerint), bbox metszés EPSG:4326-ben.
- **Megoszlás** (terület-súlyozott, 0–1): `ua_built_share`, `ua_green_urban_share`, `ua_pervious_green_share`, `ua_water_share`, plusz `ua_reference_year` = 2018, `ua_class_rows`.
- **Cache** / napló: `source_key` = `clms`, provider log; TTL az EU modul cache beállítása szerint.
- **Korlát:** a hatóság bbox területe legfeljebb **~3500 km²** (FU-skála); nagyobb bboxnál nem hívjuk az EEA-t (`bbox_area_out_of_range`).
- **`GreenIntelligence` + `api/green_metrics.php`:** ha `clms_enabled` és van authority bbox, a mutatók kiegészülnek; `sealed_surface_pressure` enyhén összeolvad a beépített aránnyal, ha már létezik Copernicus-réteg. Forrás jelölés: `source` = `clms` | `eu_mixed` | `copernicus` | `local`; `data_sources` tartalmazza a `clms_urban_atlas_2018_eea` kulcsot.
- **Gov:** az EU / műholdas kártya **Copernicus vagy CLMS** bekapcsolásakor látszik; Urban Atlas sorok a zöld metrikák EU dobozában.

## Milestone 4 (kész – CAMS levegőminőség)

- **`services/CamsAirQualityService.php`** – ECMWF CAMS publikus WMS (`token=public`), `GetFeatureInfo` lekérdezés a hatóság bbox **középpontjára**: `PM2.5`, `PM10`, `NO₂`, `O₃` (µg/m³). Konzervatív, 0–1 skálás **`air_quality_index`** és `level` (`good|moderate|poor`).
- **`api/eu_air_quality.php`** – GET, csak gov/admin, válasz: `source/scope/data/meta` a már használt EU API formátumban.
- **Gov UI** – új „EU / air quality (CAMS)” kártya az Analytics oldalon (csak ha `cams_enabled`).

## Milestone 5 (kész – CDS / ERA5 klíma-kontextus)

- **`services/CdsEra5ClimateService.php`** – **ERA5** napi összesítők a hatóság bbox közepén: `temperature_2m_mean`, `precipitation_sum` (Open-Meteo **Historical Archive** API, JSON, kulcs nélkül). Időablak: **elmúlt 30 nap**, zárónap **tegnap (UTC)**. Kimenet: `temp_mean_c`, `temp_min_c` / `temp_max_c`, `precip_sum_mm`, `warm_days` (átlag ≥25 °C), `frost_days` (átlag <0 °C), `dryness_index` (0–1 proxy). **Nem** a hivatalos CDS webes letöltés / `cdsapi`; ugyanaz a reanalízis család, egyszerű integrációhoz.
- **Cache / napló:** `source_key` = `cds`, TTL alapértelmezés szerint **360 perc** erre a kulcsra.
- **`api/eu_climate_context.php`** – GET, gov/admin, `source` = `cds_era5`, `data_sources`: `era5_open_meteo_archive`.
- **Gov UI** – „EU / climate context (ERA5)” kártya az Analytics oldalon (csak ha `cds_enabled`).

## Milestone 6 (kész – Eurostat országkép)

- **`services/EurostatService.php`** – Eurostat Dissemination API (JSON, kulcs nélkül): ország-szintű `geo` meghatározás `authorities.country` alapján (ISO2 vagy név → kód map). Mutatók:
  - `demo_pjan` (népesség, `sex=T`, `age=TOTAL`)
  - `une_rt_a` (munkanélküliség, `sex=T`, `age=Y15-74`, `unit=PC_ACT`)
- **`api/eu_country_context.php`** – GET, gov/admin, kimenet: `population`, `unemployment_rate`, `year`, `geo`.
- **Gov UI** – „EU / country context (Eurostat)” kártya az Analytics oldalon (csak ha `eurostat_enabled`). Feltétel: az authority-n legyen kitöltve a `country` mező.

## Következő lépések (roadmap)

M7: EEA/INSPIRE, M8–M9: további gov UI és tesztek.

## Konfiguráció

| Forrás | Admin kulcs | Megjegyzés |
|--------|----------------|------------|
| Fő kapcsoló | `eu_open_data.enabled` | Bekapcsolva + részforrások |
| Copernicus Data Space | `copernicus_enabled`, OAuth mezők | Hivatalos regisztráció / token |
| CLMS, CAMS, CDS, Eurostat, EEA, INSPIRE | `*_enabled` | Fokozatos implementáció |
| HTTP / cache | `request_timeout_seconds`, `cache_ttl_minutes` | 5–120 s, 1–10080 perc |
| Szinkron | `sync_enabled` | Későbbi EU cron (külön az IoT-tól) |

Környezeti változók (opcionális, ha nincs admin érték): `EU_OPEN_DATA_HTTP_TIMEOUT`, `EU_OPEN_DATA_CACHE_TTL_MINUTES`.

## API válasz minta (későbbi endpointok)

Új külső adat endpointok a következő szerkezetet célozzák:

```json
{
  "ok": true,
  "source": "copernicus",
  "scope": { "authority_id": 1, "bbox": {}, "reference_period": "2026-03" },
  "data": {},
  "meta": { "fetched_at": "...", "cached": true, "confidence": "medium", "notes": [] }
}
```

## Ellenőrzés

```bash
php tests/verify_eu_open_data_foundation.php
```

Sikeres futás: mindkét tábla létezik.

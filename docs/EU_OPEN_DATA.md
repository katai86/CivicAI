# EU Open Data – Urban Intelligence réteg

Ez a dokumentum a **CivicAI** hivatalos EU-forrású adatintegrációjának alapját írja le. A megvalósítás a meglévő PHP + MariaDB architektúrát bővíti; **nem** váltja ki az IoT virtuális szenzor modult (`module_key: iot`), a `cron_iot_sync.php` és az `admin_iot_sync.php` változatlan elvű marad.

## Milestone 1 (kész)

- **Táblák:** `external_data_cache`, `external_data_provider_logs` – migráció: `sql/2026-25-eu-open-data-foundation.sql` (benne: `sql/01_consolidated_migrations.sql`, `sql/00_run_all_migrations_safe.sql`).
- **Admin modul:** `module_key` = `eu_open_data` – Beépülő modulok fülön külön szekció („EU nyílt adatok”).
- **Szolgáltatások:**
  - `services/ExternalHttpClient.php` – időkorlátos GET, User-Agent, curl vagy `file_get_contents` fallback.
  - `services/ExternalDataCache.php` – cache olvasás/írás, lejárt törlés, provider napló.
- **Segédfüggvények** (`util.php`): `eu_open_data_module_enabled()`, `eu_open_data_feature_enabled($key)`, `eu_open_data_request_timeout_seconds()`, `eu_open_data_cache_ttl_minutes()`, `eu_open_data_sync_enabled()`.

## Következő lépések (roadmap)

A részletes ütemtervet a projektben integrált **EU Open Data** prompt írja le (M2: Copernicus / NDVI, M3: CLMS, M4: CAMS, M5: CDS, M6: Eurostat, M7: EEA/INSPIRE, M8: Gov UI, M9: tesztelés és dokumentáció).

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

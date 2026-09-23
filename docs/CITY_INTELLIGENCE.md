# CivicAI City Intelligence

Adatvezérelt városi állapot: külső/helyi adat → observation → indikátor → baseline → anomália → insight → evidence.

## 1) phpMyAdmin SQL (egyszer)

Fájl: `sql/DEPLOY_city_intelligence_phpmyadmin.sql`  
Adatbázis: **`civicai_core`**  
Tartalom: City Intel táblák + forrás seed + Budaörs authority javítás + ellenőrző SELECT-ek.

## 2) Fájlok feltöltése

- `services/cityintel/*`
- `api/city_intel_*.php`, `api/cron_city_intelligence_sync.php`
- `api/report_create.php`, `services/UrbanObservationService.php`
- `gov/index.php`, `admin/index.php`, `admin/admin.js`
- `lang/hu.php`, `lang/en.php` (és többi nyelv, ha kell)
- `docs/CITY_INTELLIGENCE.md`

## 3) Cron (DirectAdmin / cPanel)

`.env` / szerver env: `ADMIN_TOKEN=` (erős, hosszú titkos érték)

**URL (minden aktív hatóság bbox-szal):**
```text
https://civicai.hu/api/cron_city_intelligence_sync.php?token=IDE_ÍRD_AZ_ADMIN_TOKENET
```

**Csak Budaörs (példa id=15):**
```text
https://civicai.hu/api/cron_city_intelligence_sync.php?token=IDE_ÍRD_AZ_ADMIN_TOKENET&authority_id=15
```

**Javasolt ütem:** minden **4 órában** (vagy 3–6 óra).

DirectAdmin példa (wget):
```text
0 */4 * * * /usr/bin/wget -q -O - "https://civicai.hu/api/cron_city_intelligence_sync.php?token=ADMIN_TOKEN" >/dev/null 2>&1
```

vagy curl:
```text
0 */4 * * * /usr/bin/curl -sS "https://civicai.hu/api/cron_city_intelligence_sync.php?token=ADMIN_TOKEN" >/dev/null 2>&1
```

## 4) Első kézi sync

1. Gov (Budaörs) → City Brain → **City Intelligence** → **Adatgyűjtés most**  
   vagy Admin → City Intelligence → **Adatgyűjtés most (összes hatóság)**
2. Ellenőrizd: sources OK, indicators, anomalies/insights, spatial térkép, evidence (Why?)

## 5) Opcionális admin modulok

Admin → Modulok → **EU Open Data**: Copernicus client id/secret, CAMS/CLMS bekapcsolás – ha van credential.

## 6) Kész funkciók / blocker

| Modul | Állapot |
|-------|---------|
| Source registry | igen (gov + admin) |
| Ingestion | Open-Meteo live + 90 nap archive, OSM, CAMS/CLMS (ha enabled), PVGIS/GBIF/OCM élő, Copernicus (OAuth), citizen, trees, vision |
| Indicator / baseline / trend / anomaly / insight | igen |
| Evidence WHAT/WHERE/WHEN/… | igen |
| Spatial + térkép | igen |
| Vision / citizen → observations | igen |
| Dashboard widget | igen |
| Cron | igen |
| HungaroMet hivatalos API | placeholder (`is_active=0`) – endpoint után kód + admin mező |
| VIIRS NASA | nem ingestálva (legacy stub) |

## Teszt (szerveren)

```bash
php tests/verify_city_intelligence.php
```

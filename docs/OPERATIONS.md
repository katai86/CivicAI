# Köz.Tér – Üzemeltetési megjegyzések

## Health check

- **URL:** `GET /api/health.php`
- **Auth:** nincs
- **Válasz:** JSON: `ok`, `db` (ok/error), `config_review` (true ha az APP_BASE_URL nincs beállítva vagy example.com).
- **HTTP:** 200 normál esetben; 503 ha az adatbázis elérhetetlen.
- Használat: load balancer, monitoring (pl. UptimeRobot), üzemeltetési dashboard.

## FixMyStreet (FMS) bridge – státusz szinkron

Ha a külső FixMyStreet/Open311 szolgáltatás be van állítva (FMS_OPEN311_BASE, FMS_OPEN311_JURISDICTION, FMS_OPEN311_API_KEY), a **státusz visszahúzás** az `api/fms_bridge/sync.php` végponton keresztül történik.

- **URL:** `GET /api/fms_bridge/sync.php?token=<ADMIN_TOKEN>`
- **Jogosultság:** admin/superadmin bejelentkezés VAGY query paraméterben megadott `token`, amely egyezik a config `ADMIN_TOKEN` értékével.
- **Teendő:** Ütemezett hívás (cron) pl. 15 percenként:  
  `curl -s "https://<domain>/api/fms_bridge/sync.php?token=<ADMIN_TOKEN>"`  
  vagy belsőben: `php api/fms_bridge/sync.php` nem használható közvetlenül (a script a külső HTTP API-t hívja), ezért a **cronnak a GET URL-t kell hívnia** (pl. wget/curl).
- A sync csak azokat a lokális bejelentéseket frissíti, amelyeknek van `fms_reports` rekordja (open311_service_request_id). Új bejelentés küldése a külső rendszerbe: `api/fms_bridge/report_create.php` (külön flow; a fő „Küldés” gomb csak lokálisan ment).

## API dokumentáció

Nyilvános link a partnereknek / integrátoroknak: **/api-docs.php** (Open311 discovery, services, requests rövid leírása).

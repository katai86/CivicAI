# CivicAI – lokális Docker tesztkörnyezet

## Előfeltétel

- Docker Desktop (WSL2 backend)
- Ubuntu WSL disztribúció **Running** állapotban

## Indítás

```powershell
cd "C:\Users\Csabai Csilla\Downloads\terkep_03_02\CivicAI"
docker compose up -d --build
```

## URL-ek

| Szolgáltatás | URL |
|---|---|
| Térkép | http://localhost:8080/ |
| Gov | http://localhost:8080/gov/ |
| Admin | http://localhost:8080/admin/ |
| Health | http://localhost:8080/api/health.php |
| MySQL (host) | localhost:3307 |

## Belépés (dev)

- Admin config: `admin` / `admin` (docker-compose env)
- DB: `civicai` / `civicai`, adatbázis: `civicai_core`

## Hasznos parancsok

```powershell
docker compose ps
docker compose logs -f web
docker compose exec web php tests/run_all_verify.php
docker compose exec web php tests/verify_city_intelligence.php
docker compose exec db mysql -ucivicai -pcivicai civicai_core
docker compose down
docker compose down -v   # DB törlése is
```

## Verify csomag (fejlesztés után)

```powershell
docker compose exec web php tests/run_all_verify.php
```

Ez lefuttatja: `verify_m1_schema`, `verify_city_intelligence`, `verify_plant_intelligence`, `verify_intelligence_platform`, `verify_eu_open_data_foundation`, `i18n_sync_keys`.

## Ha a Docker engine nem indul

1. Docker Desktop → Settings → General → **Use the WSL 2 based engine**
2. Settings → Resources → WSL Integration → **Ubuntu** bekapcsolva
3. `wsl -l -v` → Ubuntu és docker-desktop **Running**
4. Docker Desktop újraindítás

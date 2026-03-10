# CivicAI – Design szabályok (A1.3)

## Web (desktop) oldal

- **Stílus:** `assets/style.css` (közös CivicAI stílus).
- **Topbar:** Egy közös include: `inc_desktop_topbar.php`. Tartalom: brand (logo + név), opcionálisan keresőmező (csak a térképes oldalon), téma váltó, nyelv választó, navigációs linkek (Térkép, Bejelentések, Beállítások, Gov ha jogosult, stb.).
- **Testrész:** `<div class="wrap">` vagy hasonló konténer; a tartalom ebben van.
- **Admin / Gov:** AdminLTE (dashboard/dist/css és js) – saját sidebar/header; ne keverjük a sima style.css topbar-ját az AdminLTE komponensekkel.

## Mobil oldal

- **Stílus:** Mobilekit: `Mobilekit_v2-9-1/HTML/assets/css/style.css` + `assets/mobilekit_civicai.css`.
- **Shell:** `inc_mobile_header.php` (appHeader + appCapsule nyitó) és `inc_mobile_footer.php` (appCapsule záró + appBottomMenu). A lap tartalma az appCapsule belsejében van.
- **Detektálás:** `use_mobile_layout()` (util.php) – ha true, mobil layoutot és Mobilekit shell-t használunk; a `?desktop=1` vagy a `force_desktop` cookie kikapcsolja a mobil nézetet.

## Összefoglalva

- **Web oldal** = közös desktop topbar (inc_desktop_topbar.php) + style.css; Admin/Gov = AdminLTE.
- **Mobil oldal** = Mobilekit shell (appHeader, appCapsule, appBottomMenu) + mobilekit_civicai.css; egy helyen kezelt detektálás (use_mobile_layout).

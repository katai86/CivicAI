# Bemutató túra (Start intro) – megvalósítási terv

## Cél
Egy „Bemutató indítása” / „Start intro” gombra kattintva a weblap lépésről lépésre bemutatja a fő elemeket: a térképet, a bejelentés gombot, a jelmagyarzatot, menüpontokat stb. A kijelölt elem highlightolódik, mellette megjelenik egy rövid szöveg (tipp/súgó).

## Ajánlott megoldás: Driver.js
- **Driver.js** (https://driverjs.com/): könnyű, függőség nélküli, CDN-nel használható, jól testreszabható.
- Alternatívák: Intro.js, Shepherd.js – nagyobb méret vagy bonyolultabb API.

### CDN
```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css"/>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
```

## Túra lépések

### Térkép oldal (desktop: index.php)
| Sor | Cél elem (selector) | Szöveg (lang kulcs) | Megjegyzés |
|-----|----------------------|---------------------|------------|
| 1 | `#mapWrap` | tour.step_map | A térkép – itt láthatod a bejelentéseket, ötleteket, fákat. |
| 2 | `#btnNewReport` vagy `.fab-report-desktop` | tour.step_report | Új bejelentés: kattints ide vagy a térképre. |
| 3 | `#legendMenuBtn` | tour.step_legend | Jelmagyarzat: kategóriák, ötletek, fák szűrése, új fa/ötlet. |
| 4 | `#mapSearchForm` vagy `.topbar-search` | tour.step_search | Keresés cím vagy hely szerint. |
| 5 | `.topbar-links` (vagy első topbtn link) | tour.step_menu | Menü: GYIK, költségvetés, felmérések, bejelentkezés. |

### Gov dashboard (gov/index.php)
| Sor | Cél elem | Szöveg (lang kulcs) | Megjegyzés |
|-----|----------|---------------------|------------|
| 1 | `[data-tab="dashboard"]` | tour.step_gov_dashboard | Áttekintés: statisztikák, legutóbbi tevékenység. |
| 2 | `[data-tab="reports"]` | tour.step_gov_reports | Bejelentések kezelése, státusz módosítás. |
| 3 | `[data-tab="ideas"]` | tour.step_gov_ideas | Ötletek és szavazatok. |
| 4 | `[data-tab="iot"]` | tour.step_gov_iot | Szenzorok (IoT) szinkron és listák. |
| 5 | `[data-tab="citybrain-live"]` vagy első City Brain tab | tour.step_gov_citybrain | City Brain: élő adatok, előrejelzés, hotspotok, AI. |
| 6 | `[data-tab="modules"]` | tour.step_gov_modules | Modulok be-/kikapcsolása. |

## Nyelvesítés
Minden lépés szövegét a `lang/*.php` fájlokban tároljuk: `tour.start`, `tour.next`, `tour.prev`, `tour.done`, `tour.step_map`, `tour.step_report`, … (lásd a hozzáadott kulcsokat).

## Gomb elhelyezése
- **Térkép oldal:** topbar-ban, pl. a GYIK mellett: „Bemutató” / „Start intro” gomb (pl. `id="btnStartTour"`).
- **Gov oldal:** a dashboard fejlécében vagy a sidebar alján: „Bemutató indítása”.
- Opcionális: csak első látogatáskor mutatni (pl. `localStorage.getItem('civicai_tour_done')`), vagy mindig elérhetően.

## Technikai lépések
1. Driver.js betöltése (CDN) csak azokon az oldalakon, ahol a túra elérhető (pl. index, gov/index).
2. `assets/tour.js`: inicializálás, lépéssor definíció (selector + `description` a `window.LANG['tour.step_*']`-ból).
3. „Bemutató indítása” gomb: `document.getElementById('btnStartTour').addEventListener('click', () => window.civicaiTour.start())`.
4. Túra végén opcionálisan: `localStorage.setItem('civicai_tour_done', '1')` és a driver `onDestroy` callback.

## Fájlok
- **Nyelv:** `lang/hu.php`, `lang/en.php`, … – `tour.*` kulcsok.
- **Túra logika:** `assets/tour.js` (lépéssor, Driver.js konfig).
- **Gomb:** `inc_desktop_topbar.php` (térkép), `gov/index.php` (gov oldal) – egy-egy gomb, ami meghívja a túrát.

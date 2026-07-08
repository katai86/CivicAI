# Bemutató túra (Start intro / Quick tour)

## Cél

A **„Bemutató indítása”** / **„Start intro”** gombra kattintva a weblap lépésről lépésre bemutatja a fő elemeket. A kijelölt elem kiemelődik, mellette megjelenik egy rövid szöveg. Az első lépés egy **bevezető** (cím + hosszabb leírás), a **lépésszámláló** szövege nyelvenként fordított (`tour.progress`).

A gov bemutató a **bal menü aktuális szerkezetét** követi: szekciók (Ügyek, Térkép & zöld, Klíma & adat, Elemzés & AI, City Brain, Beállítások), alapból **összecsukva**. Túra közben a megfelelő szekció automatikusan kinyílik (`govSidebarRevealTab`).

## Megvalósítás: Driver.js

- **Driver.js** (https://driverjs.com/) – CDN-nel betöltve az érintett oldalakon.
- **Szövegek:** `window.LANG` (a `lang/*.php` teljes tömbje JSON-ként), kulcsok: `tour.intro_title`, `tour.intro_body_gov`, `tour.intro_body_map`, `tour.progress`, `tour.next`, `tour.prev`, `tour.done`, `tour.step_*`.
- **Logika:** `assets/tour.js` → `window.civicaiTour.start()`.

## Első lépés (bevezető)

| Oldal | Célelem (sorrendben) | Cím kulcs | Szöveg kulcs |
|--------|----------------------|-----------|---------------|
| Térkép (`index.php`) | `#btnStartTour`, ha nincs: `#mapWrap` | `tour.intro_title` | `tour.intro_body_map` |
| Gov (`gov/index.php`) | `#btnStartTour`, ha nincs: `.sidebar-menu`, `.app-sidebar` | `tour.intro_title` | `tour.intro_body_gov` |

## Lépésszámláló

- Globális beállítás: `progressText: t('tour.progress', …)` – a `{{current}}` és `{{total}}` helyőrzőket a Driver.js tölti ki.
- Példa magyarul: `{{current}}. / {{total}}. lépés`
- Példa angolul: `Step {{current}} of {{total}}`

## Térkép oldal (desktop: `index.php`)

| Sor | Cél elem | Lang kulcs |
|-----|----------|------------|
| 1 | Bevezető (lásd fent) | `tour.intro_title`, `tour.intro_body_map` |
| 2 | `#mapWrap` | `tour.step_map` |
| 3 | `#btnNewReport` / `.fab-report-desktop` / `.fab-report` | `tour.step_report` |
| 4 | `#legendMenuBtn` / `#legendToggle` | `tour.step_legend` |
| 5 | `#mapSearchForm` / `.topbar-search` | `tour.step_search` |
| 6 | `.topbar-links` | `tour.step_menu` |

## Gov dashboard (`gov/index.php`)

A menü **sorrendje** megegyezik a bal oldali navigációval. A `[data-tab="…"]` elem csak akkor létezik, ha a modul/fül megjelenik (pl. felmérés, költségvetés, EU adatok, IoT) – hiányzó elemeket a túra kihagyja.

| Sor | `data-tab` | Lang kulcs |
|-----|------------|------------|
| 1 | Bevezető (lásd fent) | `tour.intro_title`, `tour.intro_body_gov` |
| 2 | `dashboard` | `tour.step_gov_dashboard` |
| 3 | `reports` | `tour.step_gov_reports` |
| 4 | `ideas` | `tour.step_gov_ideas` |
| 5 | `surveys` | `tour.step_gov_surveys` |
| 6 | `budget` | `tour.step_gov_budget` |
| 7 | `trees` | `tour.step_gov_trees` |
| 8 | `eu-open-data` | `tour.step_gov_eu_open_data` |
| 9 | `map-layers` | `tour.step_gov_map_layers` |
| 10 | `climate` | `tour.step_gov_climate` |
| 11 | `hu-open-data` | `tour.step_gov_hu_open_data` |
| 12 | `iot` | `tour.step_gov_iot` |
| 13 | `ai` | `tour.step_gov_ai` |
| 14 | `analytics` | `tour.step_gov_analytics` |
| 15 | `intel-reports` | `tour.step_gov_intel_reports` |
| 16 | `citybrain-live` | `tour.step_gov_citybrain_live` |
| 17 | `citybrain-predictive` | `tour.step_gov_citybrain_predictive` |
| 18 | `citybrain-hotspot` | `tour.step_gov_citybrain_hotspot` |
| 19 | `citybrain-behavior` | `tour.step_gov_citybrain_behavior` |
| 20 | `citybrain-environmental` | `tour.step_gov_citybrain_environmental` |
| 21 | `citybrain-insights` | `tour.step_gov_citybrain_insights` |
| 22 | `citybrain-risk` | `tour.step_gov_citybrain_risk` |
| 23 | `modules` | `tour.step_gov_modules` |
| 24 | `#govCityHealthCard` / `#tab-dashboard` | `tour.step_gov_dashboard_explain` |

Megjegyzés: a `tour.step_gov_citybrain` kulcs a nyelvi fájlokban összefoglaló szöveghez maradhat (pl. GYIK), a túra lépéssora a fenti részletes City Brain kulcsokat használja.

## Bal menü UX (gov)

- Szekciófejlécek: `gov.nav_section_work`, `gov.nav_section_map`, `gov.nav_section_climate`, `gov.nav_section_insights`, `gov.nav_section_legacy` (City Brain), `gov.nav_section_settings`.
- Alapból minden szekció összecsukva (`sidebar-section-collapsed`).
- Görgetősáv: `assets/admin.css` – 8px széles, látható thumb; alul gradient hint ha van több tartalom (`sidebar-wrapper--scrollable`).
- Tab kattintás / túra lépés: `govSidebarRevealTab(tabKey)` kinyitja a szekciót és görgeti a menüpontot.

## Gomb elhelyezése

- **Térkép:** `inc_desktop_topbar.php` – `id="btnStartTour"`, `aria-label` és felirat: `t('tour.start')`.
- **Gov:** `gov/index.php` – ugyanígy `btnStartTour`.
- Mindkét oldalon: `window.LANG = …` után betöltött `tour.js`, majd a gomb `click` → `civicaiTour.start()`.

## Egyéb

- Túra végén: `localStorage.civicai_tour_done` (lásd `tour.js` `onDestroyed`).
- **Nyelvek:** `hu`, `en` teljes tour szövegek; `de`, `fr`, `it`, `es`, `sl` – érdemes szinkronban tartani.

## Fájlok

- `lang/*.php` – `tour.*` kulcsok
- `assets/tour.js` – lépéssor, Driver.js konfig, `progressText`, `govSidebarRevealTab` hívás
- `assets/admin.css` – sidebar görgetés, collapse, tour kiemelés
- `gov/index.php` – menü HTML, `govSidebarRevealTab` export
- `docs/INTRO_TOUR.md` – ez a dokumentum

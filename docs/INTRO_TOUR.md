# Bemutató túra (Start intro / Quick tour)

## Cél

A **„Bemutató indítása”** gomb **startup pitch minőségű**, rövid vezetett túrát indít (Driver.js).  
Gov: lean út (KPI → ügyek → zöld → klíma → AI → jelentések → City Brain összefoglaló → modulok → záró pitch).  
Térkép: térkép → bejelentés → szűrők → keresés → menü → záró.

## Újdonságok (2026-07)

- Rövidebb gov túra (nem minden City Brain almenü külön)
- Címek a popovereken (`tour.step_*_title`)
- Záró pitch (`tour.outro_gov` / `tour.outro_map`)
- Túra közben a menüpont **automatikusan aktiválódik** (tab click + sidebar reveal)
- Egységes zöld accent a popoveren (Montserrat / CivicAI brand)

## Driver.js

- CDN: `driver.js` + `driver.css`
- Szövegek: `window.LANG` → `tour.*`
- Logika: `assets/tour.js` → `window.civicaiTour.start()`

## Gov lépéssor (lényeg)

| # | Elem | Kulcs |
|---|------|--------|
| 1 | Intro | `tour.intro_title`, `tour.intro_body_gov` |
| 2 | Hero KPI | `tour.step_gov_hero` |
| 3–… | reports, ideas, budget, trees, climate, hu-open-data, ai, analytics, intel-reports | `tour.step_gov_*` |
| … | citybrain-live (összefoglaló) | `tour.step_gov_citybrain_overview` |
| … | modules | `tour.step_gov_modules` |
| utolsó | Outro | `tour.outro_title`, `tour.outro_gov` |

Hiányzó (ki kapcsolt) modulok automatikusan kimaradnak.

## Térkép lépéssor

Intro → map → report → legend → search → menu → outro.

## Nyelvek

- **HU / EN:** teljes, frissített pitch szövegek
- **DE / FR / IT / ES / SL:** outro + hiányzó climate/KSH/intel/title kulcsok pótolva

## Fájlok

- `assets/tour.js`, `assets/admin.css`, `assets/style.css`
- `lang/*.php` – `tour.*`, `gov.dash_mvp_badge`
- `docs/INTRO_TOUR.md` (ez a fájl)
- `docs/MVP_READINESS.md`

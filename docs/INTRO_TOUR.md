# Bemutató túra (Start intro / Quick tour)

## Cél

A **„Bemutató indítása”** gomb **startup pitch minőségű**, rövid vezetett túrát indít (Driver.js).

**Gov (frissítve 2026-08):** KPI → ügyek → zöld → **Rétegek** → **EU adatok** → klíma → KSH → AI → elemzés → jelentések → **Copilot & Vision** → City Brain élő → modulok → záró pitch.

**Térkép:** térkép → bejelentés → szűrők → keresés → menü → záró.

## Driver.js

- CDN: `driver.js` + `driver.css`
- Szövegek: `window.LANG` → `tour.*`
- Logika: `assets/tour.js` → `window.civicaiTour.start()`

## Nyelvek

- **HU / EN:** teljes, frissített pitch (Vision, Rétegek, EU)
- **DE / FR / IT / ES / SL:** kulcsok szinkronban EN-ből (`scripts/sync_lang_from_en.py`); `t()` EN/HU fallback ha hiányzik
- Hiányzó kulcsnál **ne** nyers `intel.rec_*` jelenjen meg

## Fájlok

- `assets/tour.js`, `assets/admin.css`, `assets/style.css`
- `lang/*.php` – `tour.*`
- `docs/INTRO_TOUR.md` (ez a fájl)

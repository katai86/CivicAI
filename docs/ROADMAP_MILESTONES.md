# CivicAI – Útmutató és milestone-ok

Ez a dokumentum a három prioritásos területet milestone-okra bontja. **Csak ezek kielégítően működnek után** érdemes további fejlesztésekre lépni.

---

## A. Topbar és designok – AdminLTE (web) + Mobilekit (mobil)

**Cél:** Két keretrendszer egységes használata: **AdminLTE** a webes (desktop) felületen, **Mobilekit** a mobil felületen; minden létező oldalon egységes megjelenés és topbar.

### Jelenlegi állapot (rövid)
- **AdminLTE:** admin/index.php, gov/index.php (dashboard/dist/css|js/adminlte)
- **Mobilekit:** mobile/index.php, user/my.php, user/settings.php, user/login.php, leaderboard.php (Mobilekit_v2-9-1 + mobilekit_civicai.css) – mobil detektálásnál
- **Egyedi topbar:** index.php (térkép), leaderboard, user/login, user/register, user/settings – részben inc_topbar_tools, részben csak topbar-links
- **Bootstrap 5:** gov, admin; Mobilekit saját Bootstrap – konfliktusok elkerülendők

### Milestone A1 – Inventár és szabályok
- [x] **A1.1** Összes belépési oldal listázása → docs/DESIGN_INVENTORY.md
- [x] **A1.2** Minden oldalhoz rögzítni: desktop = AdminLTE vagy „sima” (style.css), mobil = Mobilekit (igen/nem).
- [x] **A1.3** Egy rövid belső doc → docs/DESIGN_RULES.md

### Milestone A2 – Desktop (web) egységesítés
- [x] **A2.1** Közös desktop topbar: inc_desktop_topbar.php (brand, téma, nyelv, navigáció); opcionális kereső (index).
- [x] **A2.2** index, leaderboard, user/login, register, settings, my, profile, friends, report, case – desktop részen inc_desktop_topbar.
- [x] **A2.3** Admin és Gov: AdminLTE (dashboard/dist) – nem módosítva, konzisztens.

### Milestone A3 – Mobil egységesítés
- [x] **A3.1** Mobil detektálás egy helyen: `use_mobile_layout()` (util.php) – `?desktop=1` vagy cookie `force_desktop` kikapcsolja a mobil layoutot.
- [ ] **A3.2** Minden olyan oldal, ahol már van mobil nézet (my, settings, login, leaderboard), ugyanazt a Mobilekit shell-t használja: inc_mobile_header.php + inc_mobile_footer.php, appBottomMenu linkek konzisztensen.
- [ ] **A3.3** Olyan oldalak, ahol még nincs mobil shell (pl. case.php, user/report.php, user/profile.php, user/friends.php), mobilra is Mobilekit shell-t kapnak (header + footer + ugyanaz a tartalom).

### Milestone A4 – Tisztítás és teszt
- [ ] **A4.1** Duplikált topbar kód eltávolítása – csak egy desktop topbar include, egy mobil header/footer.
- [ ] **A4.2** Böngészőben teszt: desktop + mobil (vagy reszponzív mód) minden felsorolt oldalon; téma váltó és nyelv mindkét layouton működik.

---

## B. Beépülő modulok – AI (Mistral/ChatGPT), FixMyStreet, Open311

**Cél:** Mistral, ChatGPT, FixMyStreet, Open311 egységes kezelése az admin és a government (gov) felületekkel; ki-/bekapcsolás, API kulcsok; **AI-nál az admin felületen beállítható hívási maximum** (napi limit), hogy ne legyen túlköltés és kevesebb hiba teszt alatt.

### Jelenlegi állapot (rövid)
- **admin_modules.php:** csak **fms** (FixMyStreet/Open311) és **mistral** – enabled + api_key (és fms-nél base_url, jurisdiction). Nincs ChatGPT, nincs AI limit mező az admin UI-ban.
- **config.php:** AI_MAX_REPORTS_PER_DAY, AI_SUMMARY_LIMIT, AI_IMAGE_ANALYSIS_LIMIT – jelenleg csak env/.env, nincs admin felületen szerkeszthető.
- **Mistral:** API kulcs a module_settings-ből vagy MISTRAL_API_KEY env – „valamiért nem működik” – érdemes logolni a 401/502 választ és az adminban megjeleníteni egy rövid állapotot (pl. „Mistral: OK” / „Mistral: hibás kulcs”).
- **gov_modules.php:** gov user számára user_module_toggles (mistral, fms) ki/be – ez maradjon és legyen összehangolva az admin modulokkal.

### Milestone B1 – Admin: AI limitek beállíthatóvá tétele
- [ ] **B1.1** module_settings vagy külön tábla: `ai_max_reports_per_day`, `ai_summary_limit`, `ai_image_analysis_limit` (opcionális – először csak summary + reports).
- [ ] **B1.2** admin_modules.php: Mistral modulhoz (és később más AI modulhoz) ezen mezők megjelenítése – szám inputok, alapértelmezett értékek (pl. 20 summary, 1000 report/nap).
- [ ] **B1.3** util.php vagy config: a limitek olvasása először module_settings-ből, ha nincs akkor env (AI_SUMMARY_LIMIT stb.); AiRouter ezt használja (már használja a config konstansokat – átirányítani get_module_setting vagy új helperre).
- [ ] **B1.4** Admin „Beépülő modulok” fül: limit mezők mentése és megjelenítése; rövid szöveg: „Napi max AI összefoglaló hívás” / „Napi max bejelentés-kategorizálás”.

### Milestone B2 – Mistral működés és diagnosztika
- [ ] **B2.1** Mistral API hívás hibakezelés: gov_ai.php és report_create (AI) ág – hiba esetén egyértelmű JSON üzenet (pl. „Mistral API kulcs érvénytelen” / „Napi limit elfogyott”) és log.
- [ ] **B2.2** Admin felületen (Beépülő modulok vagy külön „AI állapot”): opcionális „Teszt Mistral” gomb – egy minimális hívás és az eredmény (siker / hibaüzenet) megjelenik, hogy ne kelljen a gov oldalon próbálkozni.
- [ ] **B2.3** Dokumentáció: API kulcs hol adható meg (admin UI vs .env), és hogy a module_settings elsőbbséget élvezzen.

### Milestone B3 – ChatGPT és FMS/Open311 modul definíció
- [ ] **B3.1** admin_modules.php MODULE_DEFS: **chatgpt** (vagy openai) modul hozzáadása – enabled, api_key (opcionális: base_url ha más endpoint); backendben még nem kell hívni, csak a beállítási felület.
- [ ] **B3.2** FMS/Open311: már van fms modul – ellenőrizni, hogy a gov és admin oldalakon a ki/be kapcsolók és a szinkron/export megfeleljenek ennek; ha kell, egy rövid „Open311” felirat vagy leírás a modul nevében (FixMyStreet / Open311 maradhat).
- [ ] **B3.3** Gov oldal: a gov_modules lista (user_module_toggles) legyen összehangolva az admin MODULE_DEFS-szel – pl. ha adminban van „chatgpt”, a gov felületen is megjelenjen opcióként (bekapcsolható a felhasználónak).

### Milestone B4 – AI provider választás (Mistral vs ChatGPT)
- [ ] **B4.1** Ha a ChatGPT modul be van kapcsolva és van kulcs, az AiRouter vagy a report_create választhasson providert (pl. config vagy module_settings: „default_ai_provider” = mistral | openai).
- [ ] **B4.2** OpenAI/ChatGPT provider osztály (OpenAIProvider.php) – hasonló a MistralProvider-hez; a hívási limitek ugyanazokkal a mezőkkel (ai_summary_limit stb.) vonatkozzanak rá is.
- [ ] **B4.3** Admin UI: AI limitek egy helyen, és „Mistral” / „OpenAI” külön modulként vagy egy „AI” modul alatt mindkét kulcs + limit megadható.

---

## C. Fa örökbe fogadás, fa feltöltő, klaszter térkép, fa öntözős beépülő

**Cél:** A fa réteg, fa feltöltés („fa feltöltés” vagy kreatív név), örökbe fogadás és öntözés végre működjön; a felhasználó **bejelentéskor** tudjon választani fa-hoz kapcsolódó akciót (pl. „Fa feltöltés” / „Új fa” / „Fa bejelentés”) – ne maradjon félbe a flow.

### Jelenlegi állapot (rövid)
- **DB:** trees tábla, tree_adopt, tree_watering, tree_create API-k; layers tábla (trees layer).
- **app.js:** trees_list, tree_adopt, tree_create, tree_watering; „Új fa felvitele” gomb a legendában; loadTrees, addTreeMode, öntözős űrlap.
- **Jelmagyarázat:** adatbázisban/langban van fa réteg; a bejelentési flow-ban (report_create, kategóriák) nincs külön „fa feltöltés” vagy „fa bejelentés” típus, amit a user kiválaszthat.

### Milestone C1 – Fa réteg és adatmodell
- [ ] **C1.1** Rögzíteni: trees tábla mezői, layer_key = 'trees' (vagy layer_type) – admin_layers és layers_public konzisztencia; a térképen a fa réteg látható és betöltődik.
- [ ] **C1.2** Jelmagyarázat (legend): „Fa örökbe fogadás” / „Fa feltöltés” / „Fa öntözés” szövegek a lang fájlokban és a frontend legendában egyértelműen; link a fa réteghez.
- [ ] **C1.3** Ha hiányzik: migráció vagy default layer a trees réteghez (aktív/inaktív), hogy minden környezetben legyen fa réteg.

### Milestone C2 – „Fa feltöltés” a bejelentési flow-ban
- [ ] **C2.1** Kategória vagy „típus” bővítés: a felhasználó a térképen / bejelentési űrlapon tudjon választani egy „Fa feltöltés” (vagy „Új fa”, „Fa bejelentés”) opciót – akár új kategória (pl. `tree_upload`), akár a meglévő „green” alatti speciális típus.
- [ ] **C2.2** Ha „Fa feltöltés” van kiválasztva: a submit ne a klasszikus report_create legyen, hanem a tree_create API hívása (ugyanazzal a helyszínnel + opcionális adatokkal), vagy a report_create kapjon egy flag-et (report_type = tree) és a backend a trees táblába is írjon – egyértelmű spec kell.
- [ ] **C2.3** Sikeres „fa feltöltés” után: visszajelzés (pl. „Fa rögzítve”), és a térképen megjelenik az új fa (refresh vagy push marker).

### Milestone C3 – Fa örökbe fogadás (adopt)
- [ ] **C3.1** Térképen: fa markerre kattintva legyen lehetőség „Örökbe fogadom” – ha be van jelentkezve a user, tree_adopt API hívás; ha nincs, átirányítás loginra.
- [ ] **C3.2** tree_adopt.php: ellenőrizni jogosultságot, dupla adopt elkerülése, és a trees tábla adopted_by_user_id (vagy kapcsolótábla) frissítése; sikeres válasz és frontend frissítés (pl. marker szín vagy felirat változik).
- [ ] **C3.3** „Saját örökbe fogadott fák” megjelenítése valahol (pl. user/my vagy külön „Fáim” blokk) – opcionális, de ajánlott a teljes flowhoz.

### Milestone C4 – Fa öntözés (watering)
- [ ] **C4.1** Térképen: örökbe fogadott fához (vagy bármely fához) „Öntözöm” gomb/űrlap – tree_watering API hívás; adatbázisban watering log (ha van ilyen tábla/mező).
- [ ] **C4.2** tree_watering.php: auth, rate limit (pl. naponta X öntözés/fa), és a frontend üzenet („Öntözve”) + opcionálisan szintén „Saját öntözések” lista.
- [ ] **C4.3** Jelmagyarázat és mobil: ha a fa funkciók mobilra is kellenek, a Mobilekit layouton is legyen lehetőség fa feltöltésre / örökbe fogadásra (ugyanaz az API, más UI).

### Milestone C5 – Klaszter térkép és teszt
- [ ] **C5.1** Sok fa esetén: marker clustering (pl. Leaflet.markercluster) a trees rétegre, hogy a térkép olvasható maradjon.
- [ ] **C5.2** Végteszt: desktop + mobil – fa réteg be, új fa felvitele, örökbe fogadás, öntözés; adatbázisban és a térképen minden konzisztens.

---

## Sorrendjavaslat

1. **Először:** A (Topbar/design) A1–A2 – hogy minden oldal konzisztens legyen, utána könnyebb a többi feladat.
2. **Majd:** B (Plugins) B1–B2 – AI limitek adminban + Mistral működés és diagnosztika; ez csökkenti a túlköltést és a „nem működik” problémát.
3. **Utána:** C (Fa) C1–C2–C3 – fa réteg, „Fa feltöltés” a bejelentési flow-ban, örökbe fogadás.
4. **Végül:** A3–A4, B3–B4, C4–C5 – mobil finomítás, ChatGPT/FMS egységesítés, fa öntözés és klaszter.

---

## Rövid összefoglaló

| Terület | Milestone-ok | Legfontosabb eredmény |
|--------|--------------|------------------------|
| **A. Topbar / design** | A1 inventár → A2 desktop egységes topbar → A3 mobil egységes shell → A4 tisztítás | Minden oldal: web = AdminLTE/sima, mobil = Mobilekit, egy topbar/include |
| **B. Plugins** | B1 AI limitek admin UI → B2 Mistral javítás + teszt gomb → B3 ChatGPT + FMS definíció → B4 provider választás | Adminban beállítható AI napi limit; Mistral működik; később ChatGPT/FMS egy helyen |
| **C. Fa** | C1 réteg + legend → C2 „Fa feltöltés” a bejelentésnél → C3 örökbe fogadás → C4 öntözés → C5 klaszter + teszt | User bejelentéskor választhat fa feltöltést; örökbe fogadás és öntözés működik |

Ezt a fájlt érdemes verziókezelni (git) és minden milestone-nál pipálni a kész feladatokat.

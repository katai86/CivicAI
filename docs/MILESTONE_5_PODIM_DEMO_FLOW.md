# MILESTONE 5 – Podim demo flow megtervezése

## Cél

Egy 3–5 perces, bemutatható flow, ami nem csak „hibabejelentő app”-ként jön át, hanem „civic operating system” / engagement platformként: térkép + bejelentés + gamification + civil/kozületi láthatóság + hatósági workflow + Open311/interoperabilitás + jövőbeni AI.

---

## Javasolt demo flow (lépések)

1. **Belépés / landing (0:00–0:30)**  
   - Rövid landing: „Köz.Tér – a városod hangja”. Belépés demo userrel (user vagy civiluser), vagy regisztráció 1 kattintással (előre kitöltött demo adat).  
   - **Kiemelni:** Egyszerű, bizalomgerjesztő első képernyő.

2. **Térkép és városi élő réteg (0:30–1:00)**  
   - Térkép betölt; markerek (bejelentések, opcionálisan civil események, facilities).  
   - **Kiemelni:** „Élő” város – minden pont valódi vagy demo adat.

3. **Új bejelentés ~20 másodperc (1:00–1:20)**  
   - Egy kattintás a térképen → kategória (pl. Úthiba), rövid leírás, Küldés.  
   - **Kiemelni:** Gyors, low-friction; cím alapú vagy GPS.

4. **AI / smart routing / kategorizáció / authority (1:20–1:45)**  
   - Narratíva: „A rendszer automatikusan hozzárendeli a hatósághoz (authority routing), kategória alapján.”  
   - **Kiemelni:** find_authority_for_report (city + service_code / bbox) = „smart routing” – nem kell kézi választás.  
   - (Később: AI kategória-javaslat, duplikáció valószínűség – M8.)

5. **Gamification / badge / XP / toplista (1:45–2:15)**  
   - Megnyitni a toplistát; mutatni XP-et, szintet, badge-eket.  
   - **Kiemelni:** Részvétel „játékos” – streak, szintek, civic badge-ek.

6. **Civil esemény / közösségi pont / szervezet (2:15–2:45)**  
   - Civil esemény a térképen (ha van) vagy rövid „civil user létrehoz egy eseményt” narratíva.  
   - Facilities (háziorvos, gyógyszertár) pontok.  
   - **Kiemelni:** Nem csak hiba – civil és közületi láthatóság is.

7. **Hatósági nézet / workflow (2:45–3:15)**  
   - Belépés govuser (vagy admin) → Közigazgatási dashboard; egy bejelentés státusza: „approved” vagy „in_progress”.  
   - **Kiemelni:** A lakosság bejelent, a hatóság egy helyen kezeli.

8. **Open311 / interoperabilitás / skálázhatóság (3:15–3:45)**  
   - Narratíva: „Köz.Tér Open311-kompatibilis API-t ad – más rendszerek is küldhetnek be kéréseket, vagy mi küldhetjük tovább FixMyStreet felé.”  
   - Mutatni: open311/v2/discovery vagy services válasz (JSON).  
   - **Kiemelni:** Szabvány, multi-city később.

9. **Jövőbeni AI governance réteg (3:45–4:00)**  
   - Egy diaszöveg: „Következő lépés: AI-alapú kategória javaslat, duplikáció detektálás, városi elemzés.”  
   - **Kiemelni:** Roadmap, nem túlígéret.

---

## Mit érdemes a demo során elrejteni

- Barátkérés / barátok lista (ha nincs idő – vagy 1 mondat).
- Részletes GDPR / consent szövegek (csak „Elfogadom” gomb).
- Admin „layers” és „authority contacts” részletek (csak „admin látja a bejelentéseket és a felhasználókat”).
- FixMyStreet bridge belső működése (csak „opcionális külső integráció” szinten).

---

## Mit kell látványosan kiemelni

- Térkép + 1 gyors bejelentés (20 mp).
- Toplista + XP + badge (gamification).
- Civil esemény / facility pontok a térképen (engagement bővítése).
- Gov dashboard 1 státuszváltás (collaboration).
- Open311 discovery/services (szabvány, skála).

---

## Mit kell egyszerűsíteni a bemutathatóságért

- Demo adatok: 1–2 előre feltöltött bejelentés, 1 civil esemény, 1 facility; opcionálisan előre belépett demo user (user + govuser).
- Ha a címsor túl zsúfolt: csak Térkép, Bejelentés, Toplista, Belépés/Kilépés a demóban.
- Státusz értékek: csak new → approved → in_progress → solved (a többi nem kell a narratívában).
- Nyelv: magyar; ha nemzetközi befektető, 1–2 kulcsképernyő angol felirattal vagy „Köz.Tér – Civic engagement platform” alcím.

---

## Konkrét teendők (M5)

- Demo script (időzített) létrehozva a docs-ban (ez a fájl).
- Opcionális: egy `demo/` mappa vagy query param `?demo=1` a főoldalon, ami előre kiválaszt egy demo user session-t vagy egyszerűsített menüt – későbbi implementáció.
- **Nem módosítottam a működő kódot** – csak terv.

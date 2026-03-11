# Prompt a ChatGPT számára – Üzleti terv, pénzügyi terv, prezentáció

**Használat:** Másold ki az alábbi „PROMPT” szekció teljes szövegét (a --- jelektől a --- jelekig), és illeszd be a ChatGPT ablakába. Kérdésként add meg pl.: „Készíts ezen alapján üzleti tervet, pénzügyi tervet és egy befektetői/üzleti prezentáció vázlatát a CivicAI szoftverhez.”

---

## PROMPT (másold ki és add át a ChatGPT-nak)

A következő szoftverről kell üzleti tervet, pénzügyi tervet és befektetői/üzleti prezentációt készíteni. A leírás teljes és pontos – ezt használd a szoftver minden működésének, feladatának és értékének megértéséhez.

---

### 1. Termék neve és pozícionálás

- **Név:** CivicAI (más néven: Köz.Tér, Problematérkép).
- **Kategória:** Civic-tech, Smart City, helyi önkormányzati és polgári részvételi platform.
- **Egy mondatban:** Térképalapú civic platform, ahol polgárok helyi problémákat jelentenek és követnek, önkormányzatok kezelik a ügyeket és analitikát látnak, opcionálisan AI, fa nyilvántartás (zöld intelligencia), Open311/FixMyStreet kompatibilitás és több város (SaaS) támogatással.

---

### 2. Célközönség és szerepkörök

- **Polgárok (citizens):** Bejelentik a helyi problémákat (út, járda, zöldterület, ötlet), nyomon követik ügyeiket, részt vesznek fa örökbefogadásban és öntözésben, XP-et és badge-eket szereznek, eseményeket és létesítményeket látnak a térképen.
- **Önkormányzatok / hatóságok (government users):** Saját terület statisztikáit és bejelentéseit látják, AI összefoglalót és jelentést generálnak (karbantartás, részvétel, fenntarthatóság), ESG dashboardot és öntözendő fák listáját használják, exportálnak (JSON, CSV, GeoJSON).
- **Platform üzemeltetők (admin):** Bejelentések státusz kezelése, felhasználók és hatóságok kezelése, AI és FixMyStreet modulok be- és kikapcsolása, limitek beállítása, analytics és ESG elérés.
- **Külső rendszerek / partnerek:** Open311 API-t használó szolgáltatások; FixMyStreet – bejelentések exportálhatók oda, státusz szinkronizálható vissza.

---

### 3. Fő funkciók és értékajánlás

**Polgári oldal:**
- Interaktív térkép (Leaflet): bejelentések kategóriák szerint (úthiba, járda, zöld, ötlet stb.), szűrők, téma és nyelv választás.
- Bejelentés: kategória, cím, leírás, fotó feltöltés, geolokáció; státusz nyomon követés; opcionális e-mail értesítés token alapján.
- Nyilvános ügy megtekintés (case) token nélkül bejelentkezés nélkül.
- Profil, saját ügyek, beállítások, barátok lista.
- Leaderboard: XP és rangsor – gamifikáció.
- Fa réteg: fák megjelenítése egészség szerint (zöld/sárga/piros), fa örökbefogadás, öntözés naplózás, új fa felvitel (jogosultság szerint), fa adat szerkesztés, AI alapú fa egészség elemzés fotó feltöltéssel.

**Hatósági (Gov) dashboard:**
- Statisztika: mai / 7 napos / összes bejelentés, státusz és kategória szerinti bontás.
- Öt panel: City Health Overview, Citizen Engagement, Urban Issues, Tree Registry, ESG Impact.
- Civic Analytics: statisztikák exportja (JSON, CSV) – bejelentések, részvétel, karbantartás.
- Urban ESG Dashboard: környezet (E), társadalom (S), irányítás (G) mutatók, év szerinti export (JSON, CSV).
- Öntözendő fák lista: fajtánkénti öntözési ajánlás alapján, „lista megtekintése” gomb.
- AI: rövid összefoglaló, ESG összefoglaló, típusos jelentés (karbantartás / részvétel / fenntarthatóság) időszak szerint (30 nap, 90 nap, 1 év); PDF export (logo), formázott szöveg.
- Bejelentések lista szűrővel (csak a hatóság scope-ja).

**Admin dashboard:**
- Bejelentések státusz kezelés (pending, approved, rejected, solved, closed stb.), megjegyzés; e-mail értesítés státusz változáskor.
- Felhasználók, rétegek, hatóságok kezelése.
- Beépülő modulok: FixMyStreet/Open311 (URL, jurisdiction, API kulcs), Mistral AI, OpenAI – enabled, API kulcs, AI limitek (napi jelentés, összefoglaló limit, kép elemzés limit); „Teszt Mistral” gomb.
- Analytics és ESG export linkek.

**Export és nyílt adat:**
- Központosított export (reports, trees, ESG): formátumok CSV, GeoJSON, JSON; jog: admin vagy hatóság (hatóság csak saját scope).

**Integrációk:**
- Open311 API v2: discovery, services, requests (GET/POST) – több város (jurisdiction_id).
- FixMyStreet bridge: bejelentés itt keletkezik; kiválasztott ügy exportálható Open311-ként külső FMS-be; státusz visszahúzás cronnal.

---

### 4. Technológia és üzemeltetés

- **Stack:** PHP, MySQL/MariaDB; frontend: HTML, CSS (Bootstrap 5), JavaScript, Leaflet (térkép), AdminLTE (admin/gov), Mobilekit (mobil web).
- **Konfiguráció:** egy config fájl, érzékeny adatok környezeti változókból (.env); több város: térkép középpont és zoom városonként beállítható.
- **AI:** Mistral és/vagy OpenAI (szöveg és kép/vision); rate limit és napi limit a költségek kordában tartására; minden AI tanácsadó, nem automatikus döntés.
- **Health check:** GET /api/health.php – monitoringra, load balancerre (DB és config állapot).

---

### 5. Üzleti modell lehetőségek (irányok)

- **SaaS önkormányzatoknak:** városonkénti / hatóságonkénti előfizetés (dashboard, analytics, ESG, AI, export).
- **Licenc / egyedi telepítés:** önkormányzat vagy régió saját szerveren; karbantartási és fejlesztési díj.
- **Pályázati / EU projekt:** fenntarthatósági (ESG) és Smart City mutatók, jelentések – pályázati anyaghoz használható adat és narratíva.
- **Integráció értékesítés:** Open311 és FixMyStreet kompatibilitás – meglévő önkormányzati rendszerek kiegészítése.

---

### 6. Elkészült milestone-ok (Smart City)

A szoftverben már megvalósított nagyobb egységek: Civic Analytics (statisztika + export), AI jelentésgenerátor (típus + időszak), ESG dashboard (E/S/G, export), fa nyilvántartás bővítés (szerkesztés, megjegyzés), fa réteg színkód (egészség), AI fa egészség elemzés (fotó → javaslat), öntözési ajánlás és öntözendő fák lista (hatósági blokk), központosított export (reports, trees, ESG – CSV, GeoJSON, JSON), öt panel a hatósági dashboardon (City Health, Engagement, Issues, Trees, ESG), jövőbeli AI architektúra dokumentum (predikció, hősziget, árvíz, zöldfedettség – placeholder). Több önkormányzat (authorities, hatósághoz tartozó adat) és multi-city térkép támogatás beépítve.

---

### 7. Kockázatok és erősségek (irányok)

- **Erősségek:** Egy helyen térkép, bejelentés, fa nyilvántartás, analitika, ESG, AI – csökkenti a sziló rendszerek számát; Open311/FMS kompatibilitás; skálázható több városra; AI kontrollált (limit, tanácsadó szerep).
- **Kockázatok / figyelendő:** AI költségek (limit és monitorozás), adatvédelem és jogi megfelelés (GDPR, helyi szabályok), önkormányzati adopció és változáskezelés.

---

Kérem, ezen alapján készíts:
1. **Üzleti terv** vázlatot: célközönség, értékajánlás, fő funkciók összefoglalva, üzleti modell opciók, piac és verseny rövid elemzés, erősségek/kockázatok.
2. **Pénzügyi terv** vázlatot: bevételi források (előfizetés, licenc, pályázat, integráció), költségstruktúra (fejlesztés, üzemeltetés, AI, marketing), egyszerű 3–5 éves becslés (opcionális táblázat), break-even és cash flow gondolat.
3. **Prezentáció** vázlatot (diák listája szövegesen): cím, probléma/megoldás, termék bemutatás (térkép, bejelentés, fa, Gov/AI, ESG), technológia röviden, üzleti modell, pénzügyi kimenetelek, csapat/partner, következő lépések, kapcsolat. A prezentáció üzleti és befektetői célú legyen, és tükrözze a fenti szoftver leírást.

---

## PROMPT vége

---

*Ezt a fájlt a projekt docs mappájában tartjuk; a fenti PROMPT szekciót másold ki és add át a ChatGPT-nak. A ChatGPT válasza után szükség szerint finomíthatod a promptot (pl. pénzügyi részletek, diaszám, nyelvek).*

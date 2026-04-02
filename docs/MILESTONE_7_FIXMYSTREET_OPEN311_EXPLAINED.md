# MILESTONE 7 – FixMyStreet / Open311 részletes magyarázat

## 1. Hogyan van jelenleg integrálva a FixMyStreet?

A **FixMyStreet** egy külső, általában önkormányzati vagy országos szinten üzemeltetett hibabejelentő rendszer, amely gyakran **Open311** kompatibilis API-t tesz elérhetővé (pl. POST kérés küldése, GET kérések listázása, státusz lekérdezése).

A Köz.Tér **nem** FixMyStreet-et futtatja – saját rendszere van (reports tábla, saját UI). A FixMyStreet **integráció** két irányból történik:

- **Kifelé (outbound):** Köz.Tér → külső FixMyStreet/Open311 szolgáltatás. Egy **opcionális** endpoint (`api/fms_bridge/report_create.php`) képes a bejelentést a külső rendszerbe is elküldeni (ha be van állítva az FMS URL és API kulcs). Ez **nem** része a fő „Küldés” gomb flow-nak: a fő bejelentés csak a lokális `api/report_create.php`-ban kerül mentésre.
- **Befelé (inbound):** Külső FixMyStreet státusz frissítései → Köz.Tér. Az `api/fms_bridge/sync.php` (cron vagy manuális hívás) lekéri a külső rendszer kéréseit, és ahol van `fms_reports` kapcsolat (report_id ↔ open311_service_request_id), ott frissíti a lokális `reports.status`-t és a `report_status_log`-ot. Így ha egy bejelentést korábban kiküldtünk a külső FMS-be, a „solved” / „closed” állapot visszajön.

**Összefoglalva:** A FixMyStreet integráció = opcionális **bridge**: kimenő küldés (külön endpoint) + bejövő státusz szinkronizálás (sync). A napi user flow (térkép → Küldés) csak a lokális adatbázist tölti.

---

## 2. Mi a szerepe a fms_bridge modulnak?

A **fms_bridge** modul két fájlból áll:

- **report_create.php:** Bejelentkezett user küldhet egy bejelentést **közvetlenül a külső FixMyStreet/Open311 API-nak**. A payload (service_code, lat, long, description, email, stb.) a külső rendszerhez megy; a válasz (service_request_id) visszajön. Ez **nem** ment a lokális `reports` táblába ebben a végpontban – tehát ez egy „külső csak” küldés. (Ha azt akarjuk, hogy lokálisan is legyen másolat és később sync, azt külön kell megoldani: pl. előbb lokális report_create, majd ugyanazzal az adattal hívni az FMS-t és elmenteni az fms_reports-ba.)
- **sync.php:** Admin vagy token védett. Lekéri a külső rendszer `requests.json` (vagy hasonló) válaszát, és ahol van `fms_reports` rekord (open311_service_request_id), ott frissíti a lokális `reports.status`-t (open→in_progress, closed→solved) és ír `report_status_log` sort.

Tehát a modul szerepe: **opcionális kétirányú kapcsolat** egy külső Open311/FixMyStreet szolgáltatással (küldés + státusz visszahúzás). Ha nincs beállítva FMS_OPEN311_BASE, a modul nem használható.

---

## 3. Mi a szerepe a saját open311 végpontoknak?

A **open311/v2/** mappa (discovery, services, service_definition, **requests**) egy **saját, Köz.Tér által üzemeltetett Open311-kompatibilis API**. Tehát **mi** vagyunk a szolgáltató: külső kliensek (más app, önkormányzat, partner) **felénk** küldhetnek kéréseket.

- **discovery.php:** Visszaadja a szolgáltatás URL-jeit (service_requests, services, service_definition).
- **services.php:** Lista a „szolgáltatástípusokról” (kategóriák) – jelenleg authority_contacts-ból vagy fallback fix listából (road, sidewalk, lighting, stb.).
- **service_definition.php:** Adott service_code attribútumai (opcionális mezők).
- **requests.php:**  
  - **POST:** Új kérés (service_code, description, lat, long, address_string, email, stb.) → a Köz.Tér **reports** táblájába menti, és visszaadja a service_request_id-t (= report id).  
  - **GET:** Kérések listázása (szűrés service_code, status, stb. alapján) – a reports táblából olvas, Open311 formátumban.

Tehát a saját Open311 végpontok = **bejövő API**: más rendszerek **nekünk** küldhetnek bejelentést, mi tároljuk és kezeljük őket. Ez üzletileg és technikailag azt jelenti: Köz.Tér lehet „hub” – harmadik fél integrálódhat velünk szabványos módon.

---

## 4. Mi a különbség: mi adunk Open311 API-t vs mi küldünk külső FMS felé?

| | Mi biztosítunk Open311 API-t (saját) | Mi küldünk külső FixMyStreet/Open311 felé |
|--|--------------------------------------|-------------------------------------------|
| **Irány** | Bejövő: mások felénk küldik a kérést | Kimenő: mi küldjük a kérést egy külső rendszerbe |
| **Hol tárolódik** | Nálunk, reports tábla | Náluk (és opcionálisan nálunk is, ha külön összekötjük a report_create + fms_bridge-ot) |
| **Fájlok** | open311/v2/requests.php (POST/GET) | api/fms_bridge/report_create.php (POST kifelé), sync.php (GET vissza) |
| **Konfig** | Nincs külső URL (mi vagyunk a szerver) | FMS_OPEN311_BASE, FMS_OPEN311_JURISDICTION, FMS_OPEN311_API_KEY |

Röviden: **saját Open311** = mi vagyunk a „városi API”; **FMS bridge** = mi vagyunk kliens egy másik városi/országos rendszerhez.

---

## 5. Miért jó ez üzletileg és technikailag?

- **Üzletileg:**  
  - Egy helyen kezelhető a lakossági bejelentés (Köz.Tér), de ugyanaz az adat szabványos formátumban (Open311) elérhető más rendszereknek, vagy továbbküldhető egy meglévő FixMyStreet példánynak.  
  - Önkormányzatok, akik már használnak FixMyStreet-et, később át tudnak állni vagy párhuzamosan használni Köz.Tért (ha bridge van).  
  - Partnerek (NGO, más app) egyetlen API-val (a mi Open311-ünk) tudnak bejelentést indítani – skálázható, szabványos.

- **Technikailag:**  
  - Open311 szabvány → kevesebb egyedi integráció, dokumentált formátum.  
  - Lokális első (saját reports) → nem függünk a külső rendszer uptime-jától a napi flow-ban.  
  - Bridge opcionális → aki nem akar FMS-t, az nem konfigurálja; aki akarja, az sync + küldés.

---

## 6. Mi maradjon meg ebből biztosan?

- **Saját Open311 API (open311/v2/)** – maradjon. Ez a „mi vagyunk a civic API” narratíva és a partner/integrátor lehetőség.
- **FMS bridge (api/fms_bridge/)** – maradjon **opcionális** modulként: ha nincs konfig, nem fut; ha van, akkor küldés + sync. Ne távolítsuk el.
- **fms_reports és fms_sync_log** táblák – maradjanak, ha a bridge-t használják.

---

## 7. Mi az, ami jelenleg csak előkészítés / részleges integráció?

- A **fő bejelentés flow (report_create.php)** **nem** hívja automatikusan az fms_bridge-ot. Tehát „egy kattintás és megy a hatósághoz” jelenleg = lokális mentés + opcionális authority_id; a „kimegy a külső FixMyStreet-re” csak akkor történik, ha valaki külön meghívja az fms_bridge/report_create-et (pl. későbbi „Küldés a nemzeti rendszerbe” gomb).
- A **sync** csak akkor frissít bármit, ha van már fms_reports rekord (report_id ↔ open311_service_request_id). Tehát ha soha nem küldtünk egy reportot a külső rendszerbe, a sync nem fogja azt frissíteni. Ez konzisztens, de tisztázni kell: a teljes „lokális + külső” flow csak akkor működik, ha valahol (pl. egy közös report_create wrapper vagy egy „Export to FMS” lépés) elmentjük a lokális reportot és utána meghívjuk a külső API-t és elmentjük az fms_reports-ba.

---

## 8. Optimális jövőkép

- **Lokális authority routing:** Maradjon: find_authority_for_report (city, service_code, bbox) → reports.authority_id. Ez a belső ügykezelés (gov dashboard, listák) miatt kell.
- **Open311 szabvány:** Saját API maradjon Open311 kompatibilis (discovery, services, requests). Így multi-city és partner integráció egy formátumon megy.
- **FixMyStreet kompatibilitás:** Opcionális „küldés + sync” maradjon; hosszú távon lehet egy „Küldés a [X] rendszerbe” gomb a report státusz oldalon (admin/gov), ami meghívja a bridge-ot és elmenti az fms_reports kapcsolatot.
- **Multi-city / multi-country:** A jelenlegi geocoder és bbox HU (pl. Orosháza) specifikus. A schema (reports, authorities, stb.) és az Open311 API már alkalmas több városra; a bbox és jurisdiction_id kezelés bővítése későbbi phase (konfig vagy tábla alapú bbox per city).

---

## Termékstratégiai értelmezés (rövid)

A Köz.Tér **nem** pótlja a FixMyStreet-et, hanem **civic engagement platform**: saját térkép, gamification, civil/kozületi rétegek, hatósági dashboard. A saját Open311 API azt mondja: „bárki integrálódhat velünk szabványosan”. A FixMyStreet bridge azt mondja: „akik már használnak egy országos vagy másik helyi rendszert, azokkal is tudunk együttműködni”. Így a platform védhető, skálázható és a Podim narratívában „interoperability” és „local + national” együtt is elmondható.

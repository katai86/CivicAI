# MILESTONE 8 – AI-réteg felvázolása

## Kiindulás

A kódbázis jelenlegi állapota: strukturált adat (reports kategória, leírás, koordináta, authority_id; users XP, badge; report_status_log). Nincs dedikált AI/ML modul vagy event log. Az alábbi roadmap **nem** ígér mindent azonnal, de tisztázza, mi mutatható be hitelesen „AI-assisted civic”ként és mi készíti elő a későbbi funkciókat.

---

## Lehetséges AI funkciók és helyük a roadmapon

| Funkció | Rövid leírás | Gyorsan megvalósítható? | Adatminőség / új tábla | Podim „AI-assisted” megjelenítés |
|---------|--------------|--------------------------|-------------------------|----------------------------------|
| Automatikus kategória-javaslat | Leírás szöveg → javasolt category (road, trash, stb.) | Igen (egyszerű szabály vagy kis modell) | Meglévő reports.description | „A rendszer javasolja a kategóriát” – dropdown előre kitöltve |
| Szövegösszefoglalás | Hosszú leírás → 1–2 mondatos összefoglaló | Igen (API vagy lokális) | Meglévő | Admin/gov listában rövid „summary” oszlop |
| Duplikáció valószínűségi ellenőrzés | Közel lévő + hasonló kategória → „Lehet duplikátum” figyelmeztetés | Igen (távolság + kategória, opcionálisan szöveg hasonlóság) | Meglévő reports | Bejelentés küldésénél „Figyelem: hasonló bejelentés a közelben” |
| Authority routing javaslat | city + category → javasolt authority_id | Már van (find_authority_for_report); „AI” = később finomabb modell | Meglévő | „Smart routing” narratíva (már elmondható) |
| Severity scoring | Leírás + kategória → 1–5 vagy low/medium/high | Később (címkézett adat kell) | Opcionális report.severity vagy event log | Későbbi phase |
| Lakossági hangulat / probléma-cluster | Térbeli és időbeli csoportosítás, hotspotok | Később (sok adat + elemzés) | Aggregátum vagy event log | Későbbi phase |
| Admin / gov statisztikai segédlet | „Legtöbb bejelentés kategória, státusz eloszlás” | Igen (SQL aggregátum, dashboard) | Meglévő | „Insights” / „Statisztika” lap – nem feltétlenül ML |
| Civil események / közösségi mintázatok | Esemény típusok, részvétel | Később | civil_events, facilities | Későbbi phase |
| Városi döntés-előkészítő dashboard | Jelentések, trendek, javaslatok | Később | Több forrás | Későbbi phase |

---

## Mi valósítható meg gyorsan

- **Kategória javaslat:** Szabályalapú (pl. kulcsszavak: „kátyú”, „út” → road; „lámpa” → lighting) vagy kis nyelvi modell / API hívás (pl. egy classification endpoint). Bejelentés űrlapon „Javasolt kategória: X” megjelenítés.
- **Duplikátum figyelmeztetés:** Már van 50 m duplikátum blokkolás. Bővítés: „Hasonló bejelentések 200 m-en belül” lista (kategória + távolság), nem blokkolás, csak figyelmeztetés. Szöveg hasonlóság opcionális (később).
- **Rövid összefoglaló:** Admin listában: ha a description hosszú, egy „summary” mező (pl. első 120 karakter vagy külső summarization API). Nem kell új tábla.
- **Statisztika / insights:** Egyszerű SQL: COUNT per category, per status, trend last 7/30 days. Admin vagy gov dashboard új „Statisztika” blokk.

---

## Mihez kell adatminőség javítás vagy új tábla

- **Severity, prioritás:** Ha címkézett adat nincs, szabályalapú (pl. „sürgős” a leírásban, vagy bizonyos kategória = magasabb). Opcionális: reports.priority vagy reports.severity mező.
- **Lakossági hangulat / cluster:** Sok report + időbeli adat; opcionális event log (report_id, event_type, created_at) vagy aggregátum tábla (daily/category/city).
- **Civil / facility mintázatok:** civil_events, facilities – megvannak; elemzés később, nem kötelező új tábla.

---

## Mi mutatható be már Podimon „AI-assisted civic ops” címkével hitelesen

- **Smart routing:** A meglévő find_authority_for_report (city + service_code + bbox) = „automatikus hatóság hozzárendelés” – elmondható, hogy a rendszer intelligensen routingol. Nem kell külön ML.
- **Duplikátum védelem:** A 50 m + kategória ellenőrzés = „automatikus duplikátum ellenőrzés”. Bővítés: „hasonló bejelentések” figyelmeztetés (nem blokkolás) → ez is elmondható.
- **Kategória javaslat:** Ha bevezetünk egy egyszerű szabályalapú vagy kis modell alapú javaslatot a űrlapon → „AI javasolja a kategóriát”.
- **Összefoglaló adminnak:** „A rendszer összefoglalja a hosszú bejelentéseket” – egy rövid summary mező (akár trim 120 char) vagy külső API.

---

## Amit nem szabad túlígérni

- Teljesen automatikus, hibátlan kategória vagy severity – mindig lehet javítani manuálisan.
- „AI dönti el a végleges hatóságot” – a routing javaslat maradjon, a döntés admin/gov kezében.
- Valós időben „sentiment” vagy „hangulat” elemzés anélkül, hogy lenne megfelelő adat és modellek.

---

## Konkrét teendők (M8)

- Roadmap dokumentálva (ez a fájl).
- Nincs azonnali kódváltozás – a következő phase-ban lehet: (1) kategória javaslat (szabály vagy API), (2) duplikátum figyelmeztetés bővítés, (3) admin statisztika blokk, (4) opcionális summary mező.

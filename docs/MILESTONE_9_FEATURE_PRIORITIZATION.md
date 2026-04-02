# MILESTONE 9 – Feature prioritization: KEEP / CUT / LATER

## A. KELL MOST (Podim demo + stabil core)

| Feature | Indoklás |
|---------|----------|
| Térkép + bejelentés (kategória, leírás, koordináta/cím) | Core érték; 20 mp demo flow |
| Regisztráció / belépés (user, civiluser, communityuser; gov opcionális) | Szerepkörök és narratíva |
| Státuszok (new → approved → in_progress → solved) + gov dashboard 1 státuszváltás | Collaboration story |
| XP, badge, szint, toplista | Gamification; wow faktor |
| Saját Open311 API (discovery, services, requests) | Interoperabilitás narratíva |
| Civil esemény (legalább 1 a térképen vagy listában) | Civic platform, nem csak hiba |
| Facility pontok (legalább 1, pl. háziorvos) | Közületi láthatóság |
| Authority routing (find_authority_for_report) | „Smart routing” |
| Admin: bejelentések listája, felhasználók, szerepkör | Működő admin |
| Jelmagyarázat, layerek (map_layers) | Térkép olvashatóság |
| Sikeres küldés visszajelzés, üres állapotok | UX, bizalom |

## B. KÉSŐBB (megtartjuk, demóban nem hangsúlyos)

| Feature | Indoklás |
|---------|----------|
| Barátok / friend request | Social mélység; demóban 1 mondat |
| Like rendszer | Engagement; nem kritikus a 3 perces demóhoz |
| Részletes GDPR szövegek (teljes tájékoztató) | Legal; link elegendő demóban |
| Email értesítés (státuszváltás, verify) | Fontos prod-ban; demóban nem kell élő email |
| FixMyStreet bridge (küldés + sync) | Opcionális integráció; narratíva szinten említés |
| Authority contacts CRUD (admin) | Ha nincs authority_contacts tábla, 503; később bővítés |
| Authority user assignment (admin) | Gov dashboardhoz kell hosszú távon; demóban 1 gov user előre rendelve |
| Részletes report_status_log megjelenítés | Admin depth; demóban nem kell |
| Multi-country / multi-city bbox | Skálázás; jelenleg HU/Orosháza |
| AI kategória javaslat / összefoglaló | M8 roadmap; későbbi phase |
| Dedikált landing/hero oldal | UX javítás; térkép = landing is ok demóban |

## C. VEDD KI / REJTSD EL / EGYSZERŰSÍTSD

| Feature | Javaslat | Indoklás |
|---------|----------|----------|
| Semmit ne töröljünk azonnal | – | A „C” főleg elrejtés / egyszerűsítés |
| Admin login dupla pálya (config + users) | Később egyesíteni vagy világosan dokumentálni „demo admin” | Zavaró; M2/M10 |
| Dashboard mappa (AdminLTE forrás) | Ne töröljük; reference. Ne építsük rá azonnal az admin UI-t | Duplikált forrás; nem futó |
| Túl sok státusz érték a demóban | Demóban csak: new, approved, in_progress, solved | Egyszerűsítés |
| Barátok/like a főmenüben demóban | Elrejteni vagy egy „Több” menü alá | Nem zsúfoljuk a demót |
| Részletes layer CRUD adminban | Demóban csak „Bejelentések + Felhasználók” lap | Összkép áttekinthető |
| Anonimitás bonyolult UI | Maradjon 1 checkbox „Névtelenül” | Egyszerű |

---

## Külön vizsgált feature-ök (összefoglalva)

- **Friend rendszer:** KEEP, LATER a demóban (1 mondat vagy elrejtve).
- **Like rendszer:** KEEP, LATER a demóban.
- **Civil event:** KEEP MOST – legalább 1 látható.
- **Facilities:** KEEP MOST – legalább 1 pont.
- **Authority bbox routing:** KEEP MOST (már benne van find_authority).
- **Leaderboard:** KEEP MOST.
- **Badge rendszer:** KEEP MOST.
- **Open311 (saját):** KEEP MOST (narratíva).
- **FixMyStreet bridge:** KEEP, LATER; demóban csak említés.
- **Gov dashboard:** KEEP MOST – 1 státuszváltás.
- **Admin dashboard:** KEEP MOST – 1–2 lap.
- **Layer rendszer:** KEEP; demóban nem kell minden CRUD.
- **Geocoder logika:** KEEP (Nominatim + Orosháza bbox).
- **Multi-country:** LATER.
- **Email értesítések:** KEEP kódban, LATER élő küldés demóban.
- **Public profile:** KEEP; demóban opcionális megjelenítés.
- **Anonimitás:** KEEP; egyszerű checkbox.

---

## Cél

A demo ne legyen túlzsúfolt, de a mélység érzete megmaradjon: térkép, egy gyors bejelentés, toplista/XP, civil/kozületi pont, gov státuszváltás, Open311 említés. A többi (barátok, like, részletes admin, FMS, AI) „van, később mutatjuk” vagy egy dián.

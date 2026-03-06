# MILESTONE 6 – UI/UX prioritások a Podimhoz

## Cél

Wow faktor, játékos civic-tech hangulat, bizalom, egyszerű első használat, mobil és desktop, nemzetközileg érthető megjelenés. Megjelölve: mi csak vizuális kozmetika, mi erősíti a terméket, mi kell a Podim demóhoz azonnal.

---

## 1. Landing / hero

- **Jelenleg:** index.php = közvetlenül a térkép; nincs külön „hero” landing.
- **Prioritás:** Közepes. Podim demóhoz: rövid hero (1–2 mondat + CTA „Térkép megnyitása”) segíthet; nem kötelező azonnal.
- **Kozmetika vs termék:** Hero erősíti a narratívát (civic platform, nem csak térkép).  
- **Podim azonnal:** Opcionális; ha nincs idő, a térkép = landing.

---

## 2. Map shell

- **Jelenleg:** Topbar (Köz.Tér, kereső, linkek), térkép, jelmagyarázat.
- **Prioritás:** Magas. A shell a központi élmény.
- **Javaslat:** Design-system (dark, glassmorphism) konzisztens alkalmazása; mobilra responsive topbar (hamburger / összecsukható linkek).
- **Podim azonnal:** Tiszta, gyors betöltés; ne legyen zsúfolt.

---

## 3. Marker design

- **Jelenleg:** Kategória alapú markerek (valószínűleg szín/kép).
- **Prioritás:** Magas. Jól felismerhető kategóriák (út, szemét, civil, stb.).
- **Kozmetika:** Ikonok, színek.  
- **Termék:** Egyértelmű kategória és státusz (pl. new vs solved) megkülönböztetés.
- **Podim azonnal:** Egységes, professzionális marker set.

---

## 4. Report modal / sheet

- **Jelenleg:** Modál vagy panel: kategória, leírás, cím, anonim, értesítés, GDPR.
- **Prioritás:** Magas. Ez a konverzió helye.
- **Javaslat:** Lépésenkénti (step) vagy egy oldalas egyszerű; „Cím alapú hely” gomb látható; GDPR egy checkbox + link.
- **Podim azonnal:** 20 másodperc alatt be lehet mutatni a küldést – kevesebb mező, világos CTA.

---

## 5. Badge / XP / level UI

- **Jelenleg:** Toplistában és profilban XP, szint, badge-ek.
- **Prioritás:** Magas a demó narratívához (gamification).
- **Javaslat:** Rövid „XP + badge” blokk a topbar-ban vagy a profil dropdown-ban; dedikált „Jelvényeim” / „Szintem” mini oldal vagy modál.
- **Podim azonnal:** Látható legyen 1 kattintással (toplista vagy profil).

---

## 6. Leaderboard UI

- **Jelenleg:** leaderboard.php + api/leaderboard.
- **Prioritás:** Magas. Közösségi részvétel érzete.
- **Javaslat:** Top 10 + „helyezésem”; szűrés kategória / idő.
- **Podim azonnal:** Egy lapon legyen meg, gyors betöltés.

---

## 7. Civil event cards

- **Jelenleg:** civil_events_list API; a térképen valószínűleg marker vagy lista.
- **Prioritás:** Közepes. Erősíti a „civil platform” narratíváát.
- **Javaslat:** Térképen civil ikon; listában kártya (cím, dátum, rövid leírás).
- **Podim azonnal:** Legalább 1 civil esemény látható a térképen vagy listában.

---

## 8. Authority dashboard usability

- **Jelenleg:** gov/index.php – ügyek listája, státuszváltás.
- **Prioritás:** Magas a „hatósági collaboration” bemutatásához.
- **Javaslat:** Egyszerű szűrők (státusz, dátum); egy kattintásos státuszváltás; rövid megjegyzés mező.
- **Podim azonnal:** 1 státuszváltás 30 mp alatt bemutatható.

---

## 9. Admin dashboard összkép

- **Jelenleg:** admin/index.php – lapok: bejelentések, felhasználók, hatóságok, layerek.
- **Prioritás:** Közepes a demóban („admin látja mindent”).
- **Javaslat:** Lapok áttekinthetők; ne legyen túl technikai (ID-k, belső kulcsok elrejtve a demóban).
- **Podim azonnal:** Egy lap (pl. Bejelentések) elegendő bemutatásra.

---

## 10. Empty states / loading / success feedback

- **Jelenleg:** Valószínűleg változó (üres lista, sikeres küldés üzenet).
- **Prioritás:** Magas. Bizalom és UX.
- **Javaslat:** Üres lista: „Még nincs bejelentés – te lehetsz az első!”; loading: spinner vagy skeleton; sikeres küldés: zöld üzenet + „Megnézheted a térképen”.
- **Podim azonnal:** Sikeres bejelentés után egyértelmű visszajelzés.

---

## Összegzés

| Terület | Csak kozmetika | Erősíti a terméket | Podim azonnal |
|---------|----------------|--------------------|----------------|
| Landing/hero | - | ✓ (narratíva) | Opcionális |
| Map shell | Részen | ✓ | ✓ Tiszta, gyors |
| Markerek | Ikonok | ✓ Kategória/státusz | ✓ Egységes set |
| Report modal | - | ✓ Konverzió | ✓ 20 mp flow |
| Badge/XP | - | ✓ Engagement | ✓ Látható |
| Leaderboard | - | ✓ | ✓ |
| Civil cards | Kártya design | ✓ Láthatóság | ✓ Legalább 1 |
| Gov dashboard | - | ✓ Workflow | ✓ 1 státuszváltás |
| Admin | - | ✓ | ✓ 1 lap |
| Empty/loading/success | - | ✓ Bizalom | ✓ Success message |

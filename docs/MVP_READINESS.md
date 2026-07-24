# CivicAI – MVP készültség (bemutató / pitch)

**Dátum:** 2026-07-24  
**Verdikt:** **Igen – bemutatható MVP** (demo / önkormányzati pitch / NotebookLM videó), **nem** még teljes production SaaS.

---

## Összegzés egy mondatban

A CivicAI **működő, demózható civic-tech MVP**: polgári térkép + gov dashboard + AI + Intelligence modulok + nyílt adatok egy rendszerben. Startup bemutatóra kész; hosszú távú skálázáshoz még kell hardening.

---

## Scorecard (0–5)

| Terület | Pont | Megjegyzés |
|--------|------|------------|
| Polgári core (térkép, bejelentés, ötlet) | **4.5** | Stabil, demózható |
| Gov dashboard / KPI | **4** | Modern áttekintés, gyorsabb betöltés |
| AI (összefoglaló, Copilot) | **3.5** | Működik API kulccsal; rate limit kell |
| Intelligence / klíma | **3.5** | Moduláris; külső API-k gyakran snapshot/fallback |
| KSH / HU adatok | **3** | Élő KSH gyakran nem elérhető → referencia OK demóra |
| IoT / City Brain | **3** | Menü + struktúra kész; tartalom helyenként preview |
| Design egységesség | **4** | Montserrat + zöld accent + színes KPI (frissítve) |
| i18n | **4** | HU/EN teljes; DE/FR/IT/ES/SL tour bővítve |
| Stabilítás / ops | **3** | Cache, timeoutok javítva; log/monitoring még vékony |
| Dokumentáció / pitch | **4** | Pitch szöveg + intro túra + ez a checklist |

**Átlag ~3.8 / 5 → MVP bemutatásra: IGEN.**

---

## Mi kész (demo script)

1. **Polgári térkép** – bejelentés, jelmagyarázat, „Bemutató indítása”
2. **Gov Áttekintés** – színes KPI, klímaindex, városi egészség
3. **Bejelentések / ötletek / költségvetés / fák**
4. **Klíma + KSH** (snapshot is elfogadható demón)
5. **AI Copilot + Intelligence jelentés** (generálás gombra)
6. **City Brain** (egy összefoglaló lépés a túrában)
7. **Modulok** – Admin beépülő modulok ki/be

---

## Mit ne ígérj élő demón

- „Minden külső API mindig élő” (KSH WAF, lassú EU API-k)
- „Teljes 3D digital twin”
- „AI dönt helyetted” (AI = tanácsadó)
- „Minden City Brain fül production-grade realtime”

---

## Bemutató előtt (15 perc checklist)

- [ ] `git pull` az éles szerveren
- [ ] AI kulcs (Mistral/OpenAI) beállítva Admin → Beépülő modulok
- [ ] Klíma modulok (HungaroMet, GBIF…) enabled
- [ ] Van legalább néhány minta bejelentés / ötlet a kiválasztott hatóságon
- [ ] `data/ksh_reference_snapshot.json` fenn van
- [ ] Ctrl+F5 a böngészőben
- [ ] „Bemutató indítása” végigfuttatva gov + térkép

---

## Következő sprint (post-MVP)

1. Egységes landing / marketing oldal a branddel
2. Admin UI ugyanarra a vizuális nyelvre
3. City Brain tartalom mélyítése (kevesebb placeholder)
4. Observability (error log dashboard)
5. PWA / mobil install
6. Partner Open Data API dokumentáció

---

*Frissítve a startup UI + intro modernizálással (2026-07).*

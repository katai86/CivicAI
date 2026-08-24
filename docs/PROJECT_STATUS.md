# CivicAI – Hol tart a projekt?

**Dátum:** 2026-08-24  
**Éles URL:** https://civicai.hu  
**Repo:** https://github.com/katai86/CivicAI (`main`)  
**Állapot:** **Bemutatható MVP** – működő civic-tech platform; nem még teljes SaaS termék.

---

## Egy mondatban

A CivicAI egy helyen fogja össze a **polgári bejelentéseket**, a **közigazgatási dashboardot**, a **zöld/fa adatokat**, az **AI döntéstámogatást** és a **klíma / nyílt adat Intelligence** modulokat. A core kész; a következő lépések a mélység, hardening és go-to-market.

---

## KÉSZ – ami demózható / használható

### Polgári oldal
- Interaktív térkép (bejelentések, ötletek, fák, rétegek)
- Bejelentés fotóval, státusz követés, nyilvános ügy-token
- Ötletek + szavazás
- Részvételi költségvetés (időszakos ki/be)
- Felmérések
- Fa örökbefogadás, öntözés, fakataszter
- Profil, XP, badge, ranglista, barátok
- Többnyelvű UI (HU, EN + DE/FR/IT/ES/SL)
- Mobil webes nézet
- Beépített bemutató túra („Bemutató indítása”)

### Közigazgatás (gov)
- Áttekintés: színes KPI, klímaindex, városi egészség, grafikonok
- Bejelentések / ötletek kezelése hatósági scope szerint
- AI Copilot, összefoglalók, ESG / analitika export
- Zöld & fák, öntözendő lista
- Intelligence Platform: klíma modulok, térképes rétegek, HTML/PDF jelentés
- KSH / magyar nyílt adat (élő vagy referencia snapshot)
- EU nyílt adatok (Copernicus, EEA, levegő, klíma – modulfüggő)
- IoT / szenzorok menü + City Brain struktúra
- Modulkezelő (ki/be, dashboard / térkép / jelentés)

### Admin
- Felhasználók, hatóságok, rétegek, bejelentések
- Beépülő modulok (AI kulcsok, FMS, klíma, EU, IoT, stb.)
- Részvételi költségvetés projektek

### Integrációk / technika
- Open311 API + FixMyStreet bridge (opcionális)
- AI: Mistral / OpenAI / Gemini (AiRouter)
- Export: CSV, JSON, GeoJSON
- Cache, lite betöltés, KSH/intelligence teljesítményjavítások
- Domain gyökér: `civicai.hu` (`APP_BASE` javítva – nem `/terkep`)
- Biztonság: uploads PHP tiltás, webshell szkenner / purge eszközök, incidens playbook

### Dokumentáció (kész)
- Pitch / bemutató szöveg, intro túra, MVP checklist
- Domain költöztetés, biztonsági incidens útmutató
- Milestone / Intelligence / Operations docok

---

## RÉSZBEN KÉSZ – működik, de finomítandó

| Terület | Állapot | Mi hiányzik |
|---------|---------|-------------|
| **KSH élő adat** | Snapshot fallback OK | Élő `ksh.hu` gyakran WAF / timeout |
| **Intelligence modulok** | Preview / referencia adat | Stabil élő API + kevesebb „üres” állapot |
| **AI Vision** | Mock / preview | Valódi modell bekötés productionben |
| **City Brain fülek** | Menü + alap logika | Mélyebb, kevesebb placeholder tartalom |
| **IoT szenzorok** | Keret + adapterek | Éles provider kulcsok, megbízható sync |
| **Admin UI** | Működik | Nem teljesen ugyanaz a startup vizuál, mint a gov Áttekintés |
| **Ops / monitoring** | Error log fájl | Dashboard, riasztás, uptime (pl. health URL) |

---

## MÉG FEJLESZTÉSRE VÁR – backlog / jövőkép

### Közeli (post-MVP sprint)
1. Egységes **marketing / landing** oldal a branddel  
2. Admin felület vizuális egységesítése a gov dashboarddal  
3. City Brain tartalom mélyítése  
4. Observability (hiba / provider napló a felületen)  
5. **PWA** / „Add to Home Screen”  
6. Partner **Open Data API** dokumentáció erősítése  
7. Régi `kataiattila.hu/CivicAI` → `civicai.hu` **301** véglegesítése + régi fertőzött fájlok eltávolítása  

### Középtáv
- Karbantartási predikció (pl. téli útkár)  
- Hősziget-index / zöldfedettség mélyebb elemzés  
- Árvíz / csapadék kockázat  
- AI képfelismerés élesben (kátyú, hulladék, fa)  
- Digitális iker (2D városnézet; 3D később)  

### Hosszú táv / nem prioritás most
- Teljes 3D Digital Twin  
- Több-tenant SaaS csomagok, billing  
- Natív iOS / Android app (PWA helyett/mellett)  

---

## Milestone összefoglaló

| Blokk | Állapot |
|-------|---------|
| Alap product (M1–M10 Phase terv) | **Kész** |
| Smart City / Green / ESG réteg | **Kész** (core) |
| Intelligence Platform M1–M10 | **Kész** (v1; élő adatok változó minőség) |
| IoT / City Brain | **Váz kész**, tartalom bővítendő |
| Jövőbeli AI (hősziget, árvíz, twin) | **Terv** |

---

## Kockázatok / üzemeltetés (aktuális)

- **Webshell incidens** volt a régi tárhelyen → új domainre **tiszta kód**; jelszavak / API kulcsok cseréje kötelező.  
- Külső API-k (KSH, EU, GBIF…) lassúak / blokkoltak lehetnek → demón a fallback elfogadható.  
- AI = **tanácsadó**, nem automatikus döntés.  

---

## Következő 3 konkrét lépés (javasolt)

1. Élesen ellenőrizni: login, gov, AI kulcsok, modulok, `https://civicai.hu/api/health.php`  
2. Régi domain 301 + fertőzött PHP-k eltávolítása  
3. Pitch / önkormányzati demó script futtatása a „Bemutató indítása” gombbal  

---

*Ez az egyetlen aktuális státuszfájl. Részletek: `docs/MVP_READINESS.md`, `docs/INTELLIGENCE_PLATFORM.md`, `docs/DOMAIN_MIGRATION.md`, `docs/SECURITY_INCIDENT_WEBSHELL.md`.*

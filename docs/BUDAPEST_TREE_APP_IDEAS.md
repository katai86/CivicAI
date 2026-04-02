# Ötletek a Budapesti Fa Tér alkalmazásból (FŐKERT / BKM)

Forrás: [A BP Fa Tér alkalmazás](https://fokert.budapestikozmuvek.hu/a-bp-fatar-alkalmazas) – FŐKERT, Budapest Közút Zrt.

## Áttekintés

A budapesti fa app a városi fák nyilvántartását, gondozását és polgári részvételt segíti. Ezekből az ötletek közül sokat a CivicAI már támogat vagy könnyen kiegészíthető.

---

## Ötletek, amik már megvannak vagy könnyen hozzáadhatók

| Ötlet | CivicAI állapot | Megjegyzés |
|-------|-----------------|------------|
| Fa felvitele a térképre (hely + fajta + fotó) | ✅ Van | `tree_create`, fotó, AI fajta/törzs/csúcs javaslat |
| Fa örökbefogadás | ✅ Van | `tree_adopt`, XP |
| Öntözés napló | ✅ Van | `tree_watering`, öntözendő fák lista (M7) |
| Fajta megadás (pl. kőris) | ✅ Van | species mező, opcionális |
| Fotó feltöltés + elemzés | ✅ Van | tree_health_analyze, fajta/törzsméret/csúcs |
| Törzsméret (cm) | ✅ Van | trunk_diameter_cm |
| Megjegyzés / állapot | ✅ Van | note, tree_logs |
| Gov dashboard öntözendő fák | ✅ Van | trees_needing_water, fajta ajánlás |

---

## Ötletek a BP appból kiegészítésre

1. **Fajtalista / előre megadott fajták**  
   Budapesten gyakori fajták legördülőben (pl. kőris, tölgy, hárs).  
   → CivicAI: opcionális `tree_species` vagy lang alapú lista a modalban.

2. **Üzenet a sikeres feltöltésről**  
   Egyértelmű visszajelzés: „A fa rögzítve, megjelenik a térképen.”  
   → CivicAI: `tree.submit_success` fordítás már van; ellenőrizni a megjelenést.

3. **Karbantartási / kockázati szint**  
   Ha a gondozó vagy a rendszer kockázatot ad meg (pl. veszélyes ág).  
   → CivicAI: `risk_level`, `health_status` mezők már a táblában; UI-ban opcionálisan megjeleníthető.

4. **Utca / cím kötés**  
   Fa hozzárendelése utcához, címhez (nem csak koordináta).  
   → CivicAI: `trees.address` opcionális; geokódolás vagy manuális cím a tree_create-nél kiegészíthető.

5. **Év megültetés**  
   Megültetés éve (planting_year).  
   → CivicAI: oszlop létezik; tree_create űrlapra vehető.

6. **Rövid útmutató a felhasználónak**  
   „Kattints a térképre → add meg a fajtát (vagy tölts fel fotót) → Küldés.”  
   → CivicAI: modal szöveg / FAQ bővítése.

7. **Szerver hiba kezelése**  
   Ha a feltöltés sikertelen, a szerver válaszából érthető hibaüzenet (pl. „A feltöltési mappa nem írható”).  
   → CivicAI: fa feltöltésnél a válasz szöveg alapú feldolgozás + szerver oldali hibaüzenet (pl. APP_DEBUG) javítva.

---

## Összefoglalás

- A **fa feltöltés hibáját** a kliens és a szerver oldalon is finomítottuk: a válasz mindig JSON, a kliens a szerver `error` szövegét jeleníti meg, és a feltöltési mappa írhatóságát is ellenőrzi a backend.
- A budapesti fa app ötletei nagy része már megvan a CivicAI-ban; a fenti listából a fajtalista, a planting_year űrlap mező és a cím/utca opció a leghasznosabb következő lépések lehetnek.

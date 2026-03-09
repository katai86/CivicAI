# SQL migrációk – sorrend és használat

## Futtatási sorrend (új környezetben)

0. **Referencia:** Táblák célállapota: **00_baseline_schema.md** (csak doc, nem futtandó SQL).
1. **Alap schema** – Ha nincs semmi: importáld a teljes adatbázis exportot (pl. kataia_civicai.sql).
2. **2026-03-admin-dashboard.sql** – users.is_active, indexek, map_layers, map_layer_points.
3. **2026-04-fms-bridge.sql** – FMS táblák, authorities (új formátum), authority_contacts, authority_users, facilities, civil_events, reports bővítés. (Ha a baseline már tartalmazza a régi authorities-t, a 2026-04 egy része „CREATE TABLE IF NOT EXISTS” vagy ALTER lehet – a fájl jelenlegi változata CREATE TABLE-t használ; ügyelj arra, hogy ne ütközzön a meglévő reports/users táblákkal.)
4. **2026-05-social.sql** – report_likes, friend_requests, friends.
5. **2026-07-authority-bbox.sql** – authorities min_lat, max_lat, min_lng, max_lng (ha még nincs).
6. **2026-08-users-role.sql** – users.role oszlop (ha hiányzik).
7. **2026-09-users-role-enum.sql** – role ENUM bővítés (civiluser, communityuser, govuser).
8. **2026-10-authorities-new-columns.sql** – opcionális; contact_email, is_active stb. (ha régi schema van: email, active).
9. **2026-11-authority-users-only.sql** – Ha a „Hatósági felhasználó hozzárendelés” 503-at dob (authority_users tábla hiányzik), futtasd ezt: `CREATE TABLE IF NOT EXISTS authority_users`.
10. **2026-12-user-preferences-lang-theme.sql** – users.preferred_lang, users.preferred_theme (Beállítások alapértelmezett nyelv és stílus mentéséhez).
11. **2026-13-tree-cadastre.sql** – trees, tree_logs; reports bővítés (related_tree_id, ai_category, ai_priority, report_gov_validated, impact_type). Civic Green Intelligence Platform M1.
12. **2026-14-tree-adoption.sql** – tree_adoptions, tree_watering_logs. Civic Green Intelligence Platform M2 (fa örökbefogadás, öntözési napló).
13. **2026-16-ai-results.sql** – ai_results tábla az AI-hívások eredményeihez és limit kontrollhoz. Civic Green Intelligence Platform M5–M10.

**Demo adatok (opcionális):**  
- **demo_seed.sql** – 2 bejelentés, 1 civil esemény, 1 facility.  
- **demo_seed_trees.sql** – 2 fa, 1 öntözési napló (futtatás csak 2026-13 után).  
- **demo_seed_full.sql** – Teljes demo: 10 felhasználó (teszt1..10@kataiattila.hu: 2 user, 2 civil, 3 community, 3 govuser), 4 hatóság (Orosháza, Nagyszénás, Tótkomlós, Mezőkovácsháza), 3 közületi pont (Orosháza: Háziorvos, Fogorvos, Gyógyszertár), 110 bejelentés (40+20+20+30, utcánként ≥3 úthiba és ≥3 fa/ágletörés a stat predikcióhoz), 8 civil esemény (4–4 a két civil usernek). Jelszó: *password*. Csak demo/teszt DB-n.

### Egy futással (feltételes migráció)

- **00_run_all_migrations_safe.sql** – Az összes séma-migráció (2026-03 … 2026-16) egy fájlban; minden lépés feltételes: ha a tábla/oszlop/index már létezik, kihagyja. Egyszer futtatható, nem kell külön ellenőrizni, mi maradt ki. Demo seed fájlok nincsenek benne.

## Megjegyzések

- A **kataia_civicai** exporttal már rendelkező környezetben a 2026-09 (role ENUM) és opcionálisan a 2026-10 fontosak a teljes szerepkör-használathoz.
- Ha „Duplicate column” vagy „Table already exists” hibát kapsz, az adott lépést átugorhatod vagy a fájlt IF NOT EXISTS / ADD COLUMN IF NOT EXISTS szerint kell alkalmazni (MySQL/MariaDB verziótól függően).

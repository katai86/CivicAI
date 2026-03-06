# SQL migrációk – sorrend és használat

## Futtatási sorrend (új környezetben)

1. **Alap schema** – Ha nincs semmi: importáld a teljes adatbázis exportot (pl. kataia_civicai.sql).
2. **2026-03-admin-dashboard.sql** – users.is_active, indexek, map_layers, map_layer_points.
3. **2026-04-fms-bridge.sql** – FMS táblák, authorities (új formátum), authority_contacts, authority_users, facilities, civil_events, reports bővítés. (Ha a baseline már tartalmazza a régi authorities-t, a 2026-04 egy része „CREATE TABLE IF NOT EXISTS” vagy ALTER lehet – a fájl jelenlegi változata CREATE TABLE-t használ; ügyelj arra, hogy ne ütközzön a meglévő reports/users táblákkal.)
4. **2026-05-social.sql** – report_likes, friend_requests, friends.
5. **2026-07-authority-bbox.sql** – authorities min_lat, max_lat, min_lng, max_lng (ha még nincs).
6. **2026-08-users-role.sql** – users.role oszlop (ha hiányzik).
7. **2026-09-users-role-enum.sql** – role ENUM bővítés (civiluser, communityuser, govuser).
8. **2026-10-authorities-new-columns.sql** – opcionális; contact_email, is_active stb. (ha régi schema van: email, active).

## Megjegyzések

- A **kataia_civicai** exporttal már rendelkező környezetben a 2026-09 (role ENUM) és opcionálisan a 2026-10 fontosak a teljes szerepkör-használathoz.
- Ha „Duplicate column” vagy „Table already exists” hibát kapsz, az adott lépést átugorhatod vagy a fájlt IF NOT EXISTS / ADD COLUMN IF NOT EXISTS szerint kell alkalmazni (MySQL/MariaDB verziótól függően).

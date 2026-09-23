-- ============================================================
-- Budaörs bejelentések helyreállítása (civicai_core)
-- Ok: Budapest (id=14) óriás bbox-a elnyelte a Budaörs pontokat → authority_id=14
--      Budaörs gov (id=15) csak a 15-ös ügyeket látja → ezért 1 db jelent meg.
-- ============================================================

-- Ellenőrzés előtte:
SELECT id, name, city, min_lat, max_lat, min_lng, max_lng
FROM authorities WHERE id IN (14, 15);

SELECT id, authority_id, city, lat, lng, title
FROM reports
WHERE city LIKE '%Budaörs%' OR id IN (281,282,283,284,285)
ORDER BY id;

-- 1) Minden Budaörs városú ügy → hatóság 15
UPDATE reports
SET authority_id = 15
WHERE city LIKE '%Budaörs%'
  AND (authority_id IS NULL OR authority_id <> 15);

-- 2) GPS a Budaörs bbox-ban → 15 (még ha city üres is)
UPDATE reports r
INNER JOIN authorities a ON a.id = 15
SET r.authority_id = 15
WHERE a.min_lat IS NOT NULL
  AND r.lat BETWEEN a.min_lat AND a.max_lat
  AND r.lng BETWEEN a.min_lng AND a.max_lng
  AND (r.authority_id IS NULL OR r.authority_id <> 15);

-- 3) Konkrét téves Budapest-re kötött Budaörs ügyek
UPDATE reports
SET authority_id = 15
WHERE id IN (281, 282, 283, 284)
  AND authority_id = 14;

-- 4) Budapest bbox szűkítése (ne fedje Budaörst) – opcionális, ajánlott
--    Régi: 47.30–47.60 / 18.90–19.30  → túl nagy
UPDATE authorities
SET min_lat = 47.35, max_lat = 47.58, min_lng = 18.92, max_lng = 19.35
WHERE id = 14
  AND city = 'Budapest';

-- 5) Ellenőrzés utána (mind authority_id=15 kell legyen a Budaörsösöknél)
SELECT id, authority_id, city, status, title
FROM reports
WHERE city LIKE '%Budaörs%' OR id BETWEEN 281 AND 285
ORDER BY id;

-- Fák is, ha Budaörsön vannak Budapesthez kötve:
UPDATE trees t
INNER JOIN authorities a ON a.id = 15
SET t.authority_id = 15
WHERE t.authority_id = 14
  AND t.lat BETWEEN a.min_lat AND a.max_lat
  AND t.lng BETWEEN a.min_lng AND a.max_lng;

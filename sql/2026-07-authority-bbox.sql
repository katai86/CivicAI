-- Csak az új mezők hozzáadása (ha a táblák már léteznek)
-- Ha "Duplicate column name" hibát kapsz, az oszlop már létezik – hagyható.

ALTER TABLE authorities ADD COLUMN min_lat DECIMAL(10,7) NULL;
ALTER TABLE authorities ADD COLUMN max_lat DECIMAL(10,7) NULL;
ALTER TABLE authorities ADD COLUMN min_lng DECIMAL(10,7) NULL;
ALTER TABLE authorities ADD COLUMN max_lng DECIMAL(10,7) NULL;

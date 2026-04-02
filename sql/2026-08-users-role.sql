-- Users role oszlop (admin kategória módosításhoz)
-- Futtasd, ha "Role frissítés sikertelen" vagy "Unknown column 'role'" hibát kapsz.

ALTER TABLE users ADD COLUMN role VARCHAR(32) NULL;

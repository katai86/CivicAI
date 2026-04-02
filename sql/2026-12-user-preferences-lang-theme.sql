-- Alapértelmezett nyelv és téma a felhasználói beállításokban
-- Futtasd egyszer; ha a oszlop már létezik, hagyd ki a megfelelő sort.
ALTER TABLE users ADD COLUMN preferred_lang VARCHAR(8) NULL;
ALTER TABLE users ADD COLUMN preferred_theme VARCHAR(8) NULL;

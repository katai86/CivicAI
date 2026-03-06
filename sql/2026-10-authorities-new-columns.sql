-- Opcionális: authorities tábla bővítése (contact_email, contact_phone, is_active stb.)
-- Ha a táblád csak email, active, category oszlopokkal rendelkezik, ezzel kompatibilis lesz az új kóddal.
-- Ha "Duplicate column" hibát kapsz, az oszlop már létezik – hagyható.

ALTER TABLE authorities ADD COLUMN contact_email VARCHAR(190) NULL;
ALTER TABLE authorities ADD COLUMN contact_phone VARCHAR(40) NULL;
ALTER TABLE authorities ADD COLUMN website VARCHAR(190) NULL;
ALTER TABLE authorities ADD COLUMN country VARCHAR(80) NULL;
ALTER TABLE authorities ADD COLUMN region VARCHAR(80) NULL;
ALTER TABLE authorities ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Ha van email oszlop: másold át contact_email-be (egy alkalommal)
-- UPDATE authorities SET contact_email = COALESCE(contact_email, email);
-- Ha van active oszlop: másold át is_active-ba
-- UPDATE authorities SET is_active = active;

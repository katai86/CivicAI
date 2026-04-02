-- Demo adatok – CSAK demo / teszt környezetben futtasd.
-- Feltételezés: users táblában van legalább egy felhasználó (pl. id=1).
-- Podim / befektetői demo: 1–2 bejelentés, 1 civil esemény, 1 közületi pont.

-- 2 bejelentés (user_id=1; ha nincs ilyen user, előbb hozz létre egyet a regisztrációval vagy INSERT-tel)
INSERT INTO reports (category, title, description, lat, lng, status, user_id, created_at) VALUES
('road', 'Kátyú a kereszteződésnél', 'A Szabadság utca és a Fő utca kereszteződésénél nagy kátyú van. Gépkocsi és kerékpár is nehezen közlekedik.', 46.5650, 20.6670, 'approved', 1, NOW() - INTERVAL 2 DAY),
('lighting', 'Kialudt utcailámpa', 'A park melletti sétányon három lámpa nem világít hete óta.', 46.5660, 20.6680, 'in_progress', 1, NOW() - INTERVAL 1 DAY);

-- 1 civil esemény (civil_events; user_id=1)
INSERT INTO civil_events (user_id, title, description, start_date, end_date, lat, lng, address, is_active, created_at) VALUES
(1, 'Környezetvédelmi nap', 'Közös takarítás a város parkjában, családoknak.', CURDATE() + INTERVAL 7 DAY, CURDATE() + INTERVAL 7 DAY, 46.5640, 20.6660, 'Városi park', 1, NOW());

-- 1 facility (facilities; user_id=1 – egy user_id egy facility a uniq miatt, vagy más user_id)
INSERT INTO facilities (user_id, name, service_type, lat, lng, address, is_active, created_at) VALUES
(1, 'Központi Egészségügyi Szolgálat', 'health', 46.5670, 20.6690, 'Fő utca 1.', 1, NOW());

-- Opcionális: ha a tábla üres és szeretnél egy demo admin/usert, a jelszót külön kell hash-elni (pl. password_hash('demo123', PASSWORD_DEFAULT)).
-- INSERT INTO users (email, pass_hash, display_name, role, ...) VALUES ('demo@example.com', '...', 'Demo User', 'user', ...);

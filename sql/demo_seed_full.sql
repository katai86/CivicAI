-- =============================================================================
-- Demo seed – Orosháza, Nagyszénás, Tótkomlós, Mezőkovácsháza (CivicAI)
-- Futtatás: 00_run_all_migrations_safe.sql és (opcionális) demo_seed_trees.sql után.
-- Jelszó minden felhasználónak: demo123 (bcrypt hash alább).
-- =============================================================================

-- Bcrypt hash. Alapértelmezett jelszó: password (Laravel közismert teszt hash).
-- Ha demo123 kell: futtasd php -r "echo password_hash('demo123', PASSWORD_DEFAULT);" és cseréld be a @pass értékét.
SET @pass = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- ========== 1. HATÓSÁGOK (Orosháza + 3 kistelepülés gov dashboardhoz) ==========
INSERT INTO authorities (name, country, region, city, email, contact_email, is_active, min_lat, max_lat, min_lng, max_lng) VALUES
('Orosháza város', 'Magyarország', 'Békés', 'Orosháza', 'info@oroshaza.hu', 'info@oroshaza.hu', 1, 46.50, 46.62, 20.58, 20.78),
('Nagyszénás község', 'Magyarország', 'Békés', 'Nagyszénás', 'hivatal@nagszenas.hu', 'hivatal@nagszenas.hu', 1, 46.66, 46.71, 20.62, 20.72),
('Tótkomlós város', 'Magyarország', 'Békés', 'Tótkomlós', 'info@totkomlos.hu', 'info@totkomlos.hu', 1, 46.38, 46.46, 20.68, 20.78),
('Mezőkovácsháza város', 'Magyarország', 'Békés', 'Mezőkovácsháza', 'hivatal@mezokovacshaza.hu', 'hivatal@mezokovacshaza.hu', 1, 46.37, 46.43, 20.88, 20.96);

-- Authority IDs (ha most inserteltük, 1..4; ha már voltak, a seed a létezőkre támaszkodik)
-- A seed feltételezi: Orosháza=1, Nagyszénás=2, Tótkomlós=3, Mezőkovácsháza=4 (vagy megfelelő id-k)
SET @auth_oroshaza   = (SELECT id FROM authorities WHERE city LIKE '%Orosháza%'   LIMIT 1);
SET @auth_nagszenas  = (SELECT id FROM authorities WHERE city LIKE '%Nagyszénás%' LIMIT 1);
SET @auth_totkomlos  = (SELECT id FROM authorities WHERE city LIKE '%Tótkomlós%'  LIMIT 1);
SET @auth_mezokovacs = (SELECT id FROM authorities WHERE city LIKE '%Mezőkovácsháza%' LIMIT 1);

-- Authority contacts (service_code a report kategóriákhoz: road, green, lighting, stb.)
-- Ha nincs created_at oszlop, töröld a created_at részt mindegyikből.
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'road',  'Úthiba', 1, NOW() FROM authorities WHERE city LIKE '%Orosháza%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'green', 'Zöld', 1, NOW() FROM authorities WHERE city LIKE '%Orosháza%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'road',  'Úthiba', 1, NOW() FROM authorities WHERE city LIKE '%Nagyszénás%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'green', 'Zöld', 1, NOW() FROM authorities WHERE city LIKE '%Nagyszénás%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'road',  'Úthiba', 1, NOW() FROM authorities WHERE city LIKE '%Tótkomlós%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'green', 'Zöld', 1, NOW() FROM authorities WHERE city LIKE '%Tótkomlós%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'road',  'Úthiba', 1, NOW() FROM authorities WHERE city LIKE '%Mezőkovácsháza%' LIMIT 1;
INSERT INTO authority_contacts (authority_id, service_code, name, is_active, created_at)
SELECT id, 'green', 'Zöld', 1, NOW() FROM authorities WHERE city LIKE '%Mezőkovácsháza%' LIMIT 1;

-- ========== 2. FELHASZNÁLÓK (teszt1..teszt10@kataiattila.hu) ==========
-- 2 user, 2 civiluser (orosháziak), 3 communityuser (Háziorvos, Fogorvos, Gyógyszertár), 3 govuser (Nagyszénás, Tótkomlós, Mezőkovácsháza)
-- is_verified: ha a users táblában nincs ilyen oszlop, töröld a is_verified, 1, részt az összes sorból.
INSERT IGNORE INTO users (email, pass_hash, display_name, role, is_active, is_verified, created_at) VALUES
('teszt1@kataiattila.hu', @pass, 'Teszt User 1', 'user', 1, 1, NOW()),
('teszt2@kataiattila.hu', @pass, 'Teszt User 2', 'user', 1, 1, NOW()),
('teszt3@kataiattila.hu', @pass, 'Teszt Civil 1', 'civiluser', 1, 1, NOW()),
('teszt4@kataiattila.hu', @pass, 'Teszt Civil 2', 'civiluser', 1, 1, NOW()),
('teszt5@kataiattila.hu', @pass, 'Orosháza Háziorvos', 'communityuser', 1, 1, NOW()),
('teszt6@kataiattila.hu', @pass, 'Orosháza Fogorvos', 'communityuser', 1, 1, NOW()),
('teszt7@kataiattila.hu', @pass, 'Fehér Kígyó Gyógyszertár', 'communityuser', 1, 1, NOW()),
('teszt8@kataiattila.hu', @pass, 'Nagyszénás Gov', 'govuser', 1, 1, NOW()),
('teszt9@kataiattila.hu', @pass, 'Tótkomlós Gov', 'govuser', 1, 1, NOW()),
('teszt10@kataiattila.hu', @pass, 'Mezőkovácsháza Gov', 'govuser', 1, 1, NOW());

-- User IDs (id=1..10 ha üres volt a users tábla; különben a beszúrt id-k)
SET @u1 = (SELECT id FROM users WHERE email = 'teszt1@kataiattila.hu' LIMIT 1);
SET @u2 = (SELECT id FROM users WHERE email = 'teszt2@kataiattila.hu' LIMIT 1);
SET @u3 = (SELECT id FROM users WHERE email = 'teszt3@kataiattila.hu' LIMIT 1);
SET @u4 = (SELECT id FROM users WHERE email = 'teszt4@kataiattila.hu' LIMIT 1);
SET @u5 = (SELECT id FROM users WHERE email = 'teszt5@kataiattila.hu' LIMIT 1);
SET @u6 = (SELECT id FROM users WHERE email = 'teszt6@kataiattila.hu' LIMIT 1);
SET @u7 = (SELECT id FROM users WHERE email = 'teszt7@kataiattila.hu' LIMIT 1);
SET @u8 = (SELECT id FROM users WHERE email = 'teszt8@kataiattila.hu' LIMIT 1);
SET @u9 = (SELECT id FROM users WHERE email = 'teszt9@kataiattila.hu' LIMIT 1);
SET @u10 = (SELECT id FROM users WHERE email = 'teszt10@kataiattila.hu' LIMIT 1);

-- Hatósági felhasználók hozzárendelése
INSERT IGNORE INTO authority_users (authority_id, user_id, role, created_at)
SELECT @auth_nagszenas,  @u8,  'govuser', NOW() FROM DUAL WHERE @auth_nagszenas IS NOT NULL AND @u8 IS NOT NULL
UNION ALL SELECT @auth_totkomlos,  @u9,  'govuser', NOW() FROM DUAL WHERE @auth_totkomlos IS NOT NULL AND @u9 IS NOT NULL
UNION ALL SELECT @auth_mezokovacs, @u10, 'govuser', NOW() FROM DUAL WHERE @auth_mezokovacs IS NOT NULL AND @u10 IS NOT NULL;

-- ========== 3. KÖZÜLETI PONTOK (Orosháza – Háziorvos, Fogorvos, Gyógyszertár) ==========
INSERT IGNORE INTO facilities (user_id, name, service_type, lat, lng, address, phone, email, is_active, created_at) VALUES
(@u5, 'Orosháza Háziorvosi Rendelő', 'health', 46.5670, 20.6640, '5900 Orosháza, Könd u. 59.', '+36 68 411 200', 'haziorvos@oroshaza.hu', 1, NOW()),
(@u6, 'Orosháza Fogorvosi Rendelő', 'dentist', 46.5665, 20.6620, '5900 Orosháza, Kossuth u. 38.', '+36 68 510 200', 'fogorvos@oroshaza.hu', 1, NOW()),
(@u7, 'Fehér Kígyó Gyógyszertár', 'pharmacy', 46.5658, 20.6650, '5900 Orosháza, Kossuth u. 42.', '+36 68 510 311', 'feherkigo@patika.hu', 1, NOW());

-- ========== 4. BEJELENTÉSEK ==========
-- Logika: azonos utcában legalább 3 úthiba és legalább 3 fa/ágletörés (green), hogy a gov stat/AI utca szinten prediktáljon.
-- Orosháza 40 db, Nagyszénás 20, Mezőkovácsháza 20, Tótkomlós 30. Státusz: sok approved (publikált), néhány in_progress, solved.

-- Orosháza utcák (koordináták körülbelül): Fő utca, Szabadság, Kossuth, Petőfi, Könd, Rákóczi
-- Minden városban pár utca, és ugyanazon az utcán 3+ road + 3+ green

-- Orosháza 40 bejelentés (authority_id = @auth_oroshaza, city = Orosháza)
-- Fő utca: 4 road, 3 green (fa)
-- Szabadság: 4 road, 3 green
-- Kossuth: 3 road, 4 green
-- Petőfi: 3 road, 3 green
-- Könd: 3 road, 2 green
-- Rákóczi: 2 road, 2 green, 2 lighting, 1 trash
INSERT INTO reports (category, title, description, lat, lng, status, user_id, city, authority_id, road, created_at) VALUES
('road', 'Kátyú a Fő utca kereszteződésnél', 'Nagy kátyú a Fő utca és Szabadság kereszteződésénél.', 46.5650, 20.6670, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 10 DAY),
('road', 'Repedt aszfalt, Fő utca 12.', 'A járdaszélen repedés, gyalogos veszély.', 46.5652, 20.6672, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 9 DAY),
('road', 'Kátyús szakasz Fő utca 20–24.', 'Több kátyú egymás mellett.', 46.5654, 20.6674, 'in_progress', @u2, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 8 DAY),
('road', 'Fő utca 30. előtt mélyedés', 'Esőzés után vízgyűjtő.', 46.5656, 20.6676, 'solved', @u2, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 7 DAY),
('green', 'Fa ágletörés Fő utca 15. mellett', 'Vihar után leszakadt ág az úttesten.', 46.5651, 20.6671, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 6 DAY),
('green', 'Fő utca – kidőlt faág a járdán', 'Gyalogosok kerülnek.', 46.5653, 20.6673, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 5 DAY),
('green', 'Fő utca park – törött ág', 'Járda mellett, takarítás szükséges.', 46.5655, 20.6675, 'solved', @u1, 'Orosháza', @auth_oroshaza, 'Fő utca', NOW() - INTERVAL 4 DAY),
('road', 'Szabadság utca kátyúk', 'Szabadság 5. és 7. között több kátyú.', 46.5660, 20.6680, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 10 DAY),
('road', 'Szabadság 10. – repedt burkolat', 'Kerékpárút sérült.', 46.5662, 20.6682, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 9 DAY),
('road', 'Szabadság 15. előtt mély kátyú', 'Autók kerülik.', 46.5664, 20.6684, 'in_progress', @u2, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 8 DAY),
('road', 'Szabadság utca 22. – úthiba', 'Éjszaka világítás hiányában veszélyes.', 46.5666, 20.6686, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 7 DAY),
('green', 'Szabadság – leszakadt ág', 'Fa a park mellett, ág az úton.', 46.5661, 20.6681, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 6 DAY),
('green', 'Szabadság 12. fa ágletörés', 'Törött ág a gyalogos útvonalon.', 46.5663, 20.6683, 'solved', @u1, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 5 DAY),
('green', 'Szabadság utca – sérült fa', 'Vihar után ágak a földön.', 46.5665, 20.6685, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Szabadság utca', NOW() - INTERVAL 4 DAY),
('road', 'Kossuth u. 20. kátyú', 'Kossuth utca rossz állapotú szakasza.', 46.5658, 20.6660, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 9 DAY),
('road', 'Kossuth 35. – repedés', 'Járdaszéli repedés.', 46.5660, 20.6662, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 8 DAY),
('road', 'Kossuth 42. (patika) előtt kátyú', 'Behajtó előtt mélyedés.', 46.5662, 20.6664, 'solved', @u1, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 7 DAY),
('green', 'Kossuth – fa ágletörés', 'Nagy ág a járdán.', 46.5659, 20.6661, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 6 DAY),
('green', 'Kossuth 25. fa sérült', 'Ágak a padkákon.', 46.5661, 20.6663, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 5 DAY),
('green', 'Kossuth 30. törött ág', 'Takarítás kell.', 46.5663, 20.6665, 'in_progress', @u2, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 4 DAY),
('green', 'Kossuth utca park – ágletörés', 'Gyerekek játéktér mellett.', 46.5664, 20.6666, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Kossuth utca', NOW() - INTERVAL 3 DAY),
('road', 'Petőfi u. 8. kátyú', 'Petőfi utca kátyús.', 46.5645, 20.6655, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Petőfi utca', NOW() - INTERVAL 8 DAY),
('road', 'Petőfi 14. – úthiba', 'Mélyedés.', 46.5647, 20.6657, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Petőfi utca', NOW() - INTERVAL 7 DAY),
('road', 'Petőfi 22. repedt aszfalt', 'Kisebb kátyúk.', 46.5649, 20.6659, 'solved', @u2, 'Orosháza', @auth_oroshaza, 'Petőfi utca', NOW() - INTERVAL 6 DAY),
('green', 'Petőfi – fa ág az úton', 'Vihar után.', 46.5646, 20.6656, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Petőfi utca', NOW() - INTERVAL 5 DAY),
('green', 'Petőfi 10. ágletörés', 'Járdán fekvő ág.', 46.5648, 20.6658, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Petőfi utca', NOW() - INTERVAL 4 DAY),
('green', 'Petőfi utca – sérült fa', 'Ágak a padkán.', 46.5650, 20.6660, 'in_progress', @u1, 'Orosháza', @auth_oroshaza, 'Petőfi utca', NOW() - INTERVAL 3 DAY),
('road', 'Könd u. 50. kátyú', 'Könd utca rossz szakasz.', 46.5670, 20.6630, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Könd utca', NOW() - INTERVAL 7 DAY),
('road', 'Könd 59. (kórház) előtt', 'Behajtó előtti kátyú.', 46.5672, 20.6632, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Könd utca', NOW() - INTERVAL 6 DAY),
('road', 'Könd u. 65. – repedés', 'Járda sérült.', 46.5674, 20.6634, 'solved', @u2, 'Orosháza', @auth_oroshaza, 'Könd utca', NOW() - INTERVAL 5 DAY),
('green', 'Könd u. fa ágletörés', 'Kórház környékén ágak.', 46.5671, 20.6631, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Könd utca', NOW() - INTERVAL 4 DAY),
('green', 'Könd 55. – törött ág', 'Takarítás szükséges.', 46.5673, 20.6633, 'approved', @u2, 'Orosháza', @auth_oroshaza, 'Könd utca', NOW() - INTERVAL 3 DAY),
('road', 'Rákóczi u. 3. kátyú', 'Rákóczi utca.', 46.5640, 20.6640, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW() - INTERVAL 6 DAY),
('road', 'Rákóczi 12. úthiba', 'Mélyedés.', 46.5642, 20.6642, 'in_progress', @u2, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW() - INTERVAL 5 DAY),
('green', 'Rákóczi – ágletörés', 'Fa ág a járdán.', 46.5641, 20.6641, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW() - INTERVAL 4 DAY),
('green', 'Rákóczi 8. sérült fa', 'Ágak a földön.', 46.5643, 20.6643, 'solved', @u2, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW() - INTERVAL 3 DAY),
('lighting', 'Rákóczi utca kialudt lámpa', 'Rákóczi 5. és 7. között sötét.', 46.5644, 20.6644, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW() - INTERVAL 2 DAY),
('lighting', 'Rákóczi 15. lámpa javítás', 'Nem világít.', 46.5645, 20.6645, 'new', @u2, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW()),
('trash', 'Rákóczi – szemetes kosár teli', 'Park mellett.', 46.5646, 20.6646, 'approved', @u1, 'Orosháza', @auth_oroshaza, 'Rákóczi utca', NOW() - INTERVAL 1 DAY);

-- Nagyszénás 20 bejelentés (Fő utca, Kossuth, Petőfi – ugyanaz a logika: 3+ road, 3+ green azonos utcában)
INSERT INTO reports (category, title, description, lat, lng, status, user_id, city, authority_id, road, created_at) VALUES
('road', 'Nagyszénás Fő u. kátyú', 'Fő utca 5. előtt nagy kátyú.', 46.6820, 20.6640, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 12 DAY),
('road', 'Fő utca 12. repedés', 'Járdaszéli repedés.', 46.6822, 20.6642, 'approved', @u2, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 11 DAY),
('road', 'Fő utca 18. kátyúk', 'Több kátyú egymás mellett.', 46.6824, 20.6644, 'in_progress', @u1, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 10 DAY),
('green', 'Nagyszénás Fő u. fa ágletörés', 'Vihar után leszakadt ág.', 46.6821, 20.6641, 'approved', @u2, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 9 DAY),
('green', 'Fő utca 8. – törött ág', 'Járdán fekvő ág.', 46.6823, 20.6643, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 8 DAY),
('green', 'Fő utca park ágak', 'Takarítás kell.', 46.6825, 20.6645, 'solved', @u2, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 7 DAY),
('road', 'Nagyszénás Kossuth u. kátyú', 'Kossuth 3. előtt.', 46.6830, 20.6650, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 11 DAY),
('road', 'Kossuth 10. úthiba', 'Mélyedés.', 46.6832, 20.6652, 'approved', @u2, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 10 DAY),
('road', 'Kossuth 20. repedt aszfalt', 'Kisebb kátyúk.', 46.6834, 20.6654, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 9 DAY),
('green', 'Kossuth u. fa ágletörés', 'Ág az úttesten.', 46.6831, 20.6651, 'approved', @u2, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 8 DAY),
('green', 'Kossuth 15. sérült fa', 'Ágak a padkán.', 46.6833, 20.6653, 'in_progress', @u1, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 7 DAY),
('green', 'Kossuth utca – törött ág', 'Takarítás szükséges.', 46.6835, 20.6655, 'solved', @u2, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 6 DAY),
('road', 'Nagyszénás Petőfi u. kátyú', 'Petőfi 4. előtt.', 46.6840, 20.6660, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Petőfi utca', NOW() - INTERVAL 10 DAY),
('road', 'Petőfi 11. úthiba', 'Repedés.', 46.6842, 20.6662, 'approved', @u2, 'Nagyszénás', @auth_nagszenas, 'Petőfi utca', NOW() - INTERVAL 9 DAY),
('road', 'Petőfi 22. kátyúk', 'Rossz állapot.', 46.6844, 20.6664, 'solved', @u1, 'Nagyszénás', @auth_nagszenas, 'Petőfi utca', NOW() - INTERVAL 8 DAY),
('green', 'Petőfi u. ágletörés', 'Vihar után.', 46.6841, 20.6661, 'approved', @u2, 'Nagyszénás', @auth_nagszenas, 'Petőfi utca', NOW() - INTERVAL 7 DAY),
('green', 'Petőfi 16. fa sérült', 'Ágak a járdán.', 46.6843, 20.6663, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Petőfi utca', NOW() - INTERVAL 6 DAY),
('lighting', 'Nagyszénás Fő u. lámpa', 'Kialudt lámpa.', 46.6826, 20.6646, 'new', @u2, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 5 DAY),
('trash', 'Nagyszénás park szemetes', 'Teli kosár.', 46.6828, 20.6648, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Fő utca', NOW() - INTERVAL 4 DAY),
('sidewalk', 'Kossuth u. járdasérülés', 'Padka repedt.', 46.6836, 20.6656, 'in_progress', @u2, 'Nagyszénás', @auth_nagszenas, 'Kossuth utca', NOW() - INTERVAL 3 DAY),
('traffic', 'Petőfi kereszteződés jelzés', 'Hiányzó stop tábla.', 46.6845, 20.6665, 'approved', @u1, 'Nagyszénás', @auth_nagszenas, 'Petőfi utca', NOW() - INTERVAL 2 DAY);

-- Mezőkovácsháza 20 bejelentés
INSERT INTO reports (category, title, description, lat, lng, status, user_id, city, authority_id, road, created_at) VALUES
('road', 'Mezőkovácsháza Fő u. kátyú', 'Fő utca 2. előtt.', 46.4020, 20.9020, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 11 DAY),
('road', 'Fő utca 8. repedés', 'Járdaszéli.', 46.4022, 20.9022, 'approved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 10 DAY),
('road', 'Fő utca 15. kátyúk', 'Több kátyú.', 46.4024, 20.9024, 'in_progress', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 9 DAY),
('green', 'Mezőkovácsháza Fő u. ágletörés', 'Fa ág az úton.', 46.4021, 20.9021, 'approved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 8 DAY),
('green', 'Fő utca 5. törött ág', 'Járdán.', 46.4023, 20.9023, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 7 DAY),
('green', 'Fő utca park fa sérült', 'Ágak.', 46.4025, 20.9025, 'solved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 6 DAY),
('road', 'Mezőkovácsháza Kossuth u.', 'Kossuth 6. kátyú.', 46.4030, 20.9030, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 10 DAY),
('road', 'Kossuth 14. úthiba', 'Mélyedés.', 46.4032, 20.9032, 'approved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 9 DAY),
('road', 'Kossuth 25. repedt aszfalt', 'Rossz szakasz.', 46.4034, 20.9034, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 8 DAY),
('green', 'Kossuth u. fa ágletörés', 'Vihar után.', 46.4031, 20.9031, 'approved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 7 DAY),
('green', 'Kossuth 18. sérült fa', 'Ágak.', 46.4033, 20.9033, 'in_progress', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 6 DAY),
('green', 'Kossuth utca törött ág', 'Takarítás.', 46.4035, 20.9035, 'solved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 5 DAY),
('road', 'Mezőkovácsháza Petőfi u.', 'Petőfi 3. kátyú.', 46.4040, 20.9040, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Petőfi utca', NOW() - INTERVAL 9 DAY),
('road', 'Petőfi 9. úthiba', 'Repedés.', 46.4042, 20.9042, 'approved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Petőfi utca', NOW() - INTERVAL 8 DAY),
('road', 'Petőfi 20. kátyúk', 'Rossz állapot.', 46.4044, 20.9044, 'solved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Petőfi utca', NOW() - INTERVAL 7 DAY),
('green', 'Petőfi u. ágletörés', 'Fa ág.', 46.4041, 20.9041, 'approved', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Petőfi utca', NOW() - INTERVAL 6 DAY),
('green', 'Petőfi 12. fa sérült', 'Ágak a padkán.', 46.4043, 20.9043, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Petőfi utca', NOW() - INTERVAL 5 DAY),
('lighting', 'Mezőkovácsháza Fő u. lámpa', 'Nem világít.', 46.4026, 20.9026, 'new', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 4 DAY),
('trash', 'Mezőkovácsháza park kosár', 'Teli.', 46.4028, 20.9028, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Fő utca', NOW() - INTERVAL 3 DAY),
('sidewalk', 'Kossuth járdasérülés', 'Padka.', 46.4036, 20.9036, 'pending', @u2, 'Mezőkovácsháza', @auth_mezokovacs, 'Kossuth utca', NOW() - INTERVAL 2 DAY),
('traffic', 'Petőfi kereszteződés', 'Jelzés hiány.', 46.4045, 20.9045, 'approved', @u1, 'Mezőkovácsháza', @auth_mezokovacs, 'Petőfi utca', NOW() - INTERVAL 1 DAY);

-- Tótkomlós 30 bejelentés
INSERT INTO reports (category, title, description, lat, lng, status, user_id, city, authority_id, road, created_at) VALUES
('road', 'Tótkomlós Fő u. kátyú', 'Fő utca 4. előtt.', 46.4180, 20.7280, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 14 DAY),
('road', 'Fő utca 9. repedés', 'Járdaszéli.', 46.4182, 20.7282, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 13 DAY),
('road', 'Fő utca 16. kátyúk', 'Több kátyú.', 46.4184, 20.7284, 'in_progress', @u1, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 12 DAY),
('green', 'Tótkomlós Fő u. ágletörés', 'Fa ág az úton.', 46.4181, 20.7281, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 11 DAY),
('green', 'Fő utca 7. törött ág', 'Járdán.', 46.4183, 20.7283, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 10 DAY),
('green', 'Fő utca park fa', 'Sérült fa.', 46.4185, 20.7285, 'solved', @u2, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 9 DAY),
('road', 'Tótkomlós Kossuth u. kátyú', 'Kossuth 2. előtt.', 46.4190, 20.7290, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 13 DAY),
('road', 'Kossuth 8. úthiba', 'Mélyedés.', 46.4192, 20.7292, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 12 DAY),
('road', 'Kossuth 17. repedés', 'Aszfalt.', 46.4194, 20.7294, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 11 DAY),
('green', 'Kossuth u. fa ágletörés', 'Vihar.', 46.4191, 20.7291, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 10 DAY),
('green', 'Kossuth 12. sérült fa', 'Ágak.', 46.4193, 20.7293, 'in_progress', @u1, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 9 DAY),
('green', 'Kossuth utca törött ág', 'Takarítás.', 46.4195, 20.7295, 'solved', @u2, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 8 DAY),
('road', 'Tótkomlós Petőfi u. kátyú', 'Petőfi 5. előtt.', 46.4200, 20.7300, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Petőfi utca', NOW() - INTERVAL 12 DAY),
('road', 'Petőfi 11. úthiba', 'Mélyedés.', 46.4202, 20.7302, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Petőfi utca', NOW() - INTERVAL 11 DAY),
('road', 'Petőfi 22. kátyúk', 'Rossz szakasz.', 46.4204, 20.7304, 'solved', @u1, 'Tótkomlós', @auth_totkomlos, 'Petőfi utca', NOW() - INTERVAL 10 DAY),
('green', 'Petőfi u. ágletörés', 'Fa ág.', 46.4201, 20.7301, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Petőfi utca', NOW() - INTERVAL 9 DAY),
('green', 'Petőfi 15. fa sérült', 'Ágak.', 46.4203, 20.7303, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Petőfi utca', NOW() - INTERVAL 8 DAY),
('road', 'Tótkomlós Szabadság u.', 'Szabadság 3. kátyú.', 46.4210, 20.7310, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 11 DAY),
('road', 'Szabadság 9. úthiba', 'Repedés.', 46.4212, 20.7312, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 10 DAY),
('road', 'Szabadság 18. kátyúk', 'Rossz állapot.', 46.4214, 20.7314, 'in_progress', @u2, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 9 DAY),
('green', 'Szabadság u. ágletörés', 'Vihar után.', 46.4211, 20.7311, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 8 DAY),
('green', 'Szabadság 7. törött ág', 'Járdán.', 46.4213, 20.7313, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 7 DAY),
('green', 'Szabadság 14. fa sérült', 'Ágak.', 46.4215, 20.7315, 'solved', @u1, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 6 DAY),
('lighting', 'Tótkomlós Fő u. lámpa', 'Kialudt.', 46.4186, 20.7286, 'new', @u2, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 5 DAY),
('lighting', 'Tótkomlós Kossuth lámpa', 'Nem világít.', 46.4196, 20.7296, 'approved', @u1, 'Tótkomlós', @auth_totkomlos, 'Kossuth utca', NOW() - INTERVAL 4 DAY),
('trash', 'Tótkomlós park szemetes', 'Teli kosár.', 46.4188, 20.7288, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Fő utca', NOW() - INTERVAL 3 DAY),
('sidewalk', 'Tótkomlós Petőfi járdasérülés', 'Padka.', 46.4205, 20.7305, 'pending', @u1, 'Tótkomlós', @auth_totkomlos, 'Petőfi utca', NOW() - INTERVAL 2 DAY),
('traffic', 'Tótkomlós Szabadság kereszteződés', 'Jelzés.', 46.4216, 20.7316, 'approved', @u2, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW() - INTERVAL 1 DAY),
('idea', 'Tótkomlós park javaslat', 'Padok pótlása.', 46.4218, 20.7318, 'new', @u1, 'Tótkomlós', @auth_totkomlos, 'Szabadság utca', NOW());

-- ========== 5. CIVIL ESEMÉNYEK (mindkét civil user 4–4 eseményt kap, random téma, random időpont) ==========
-- Szöveg ékezet nélkül (latin1/utf8 kompatibilitás); ha a táblád utf8mb4, nyugodtan használj ékezetes szöveget.
INSERT INTO civil_events (user_id, title, description, start_date, end_date, lat, lng, address, is_active, event_type, participants_count, created_at) VALUES
(@u3, 'Park takaritas - Oroshaza', 'Kozos kornyezetvedelmi nap a varosi parkban.', CURDATE() + INTERVAL 5 DAY, CURDATE() + INTERVAL 5 DAY, 46.5640, 20.6660, 'Oroshaza, Varosi park', 1, 'green_action', 12, NOW()),
(@u3, 'Faultetes az iskola mellett', 'Gyerekekkel egyutt ultetunk fakat.', CURDATE() + INTERVAL 14 DAY, CURDATE() + INTERVAL 14 DAY, 46.5635, 20.6655, 'Oroshaza, Iskola udvar', 1, 'green_action', 25, NOW()),
(@u3, 'Kornyezetvedelmi forum', 'Rovid eloadas es vita a helyi zold teruletekrol.', CURDATE() - INTERVAL 3 DAY, CURDATE() - INTERVAL 3 DAY, 46.5660, 20.6680, 'Oroshaza, Konyvtar', 1, 'civil', 18, NOW()),
(@u3, 'Setany felujitas onkentes nap', 'Segedkezes a setany karbantartasaban.', CURDATE() + INTERVAL 21 DAY, CURDATE() + INTERVAL 21 DAY, 46.5652, 20.6672, 'Oroshaza, Park setany', 1, 'green_action', 8, NOW()),
(@u4, 'Zold nap - fak ontozese', 'Nyaron ontozesi akcio a park fainal.', CURDATE() + INTERVAL 7 DAY, CURDATE() + INTERVAL 7 DAY, 46.5655, 20.6675, 'Oroshaza, Kozponti park', 1, 'green_action', 15, NOW()),
(@u4, 'Civil beszelgeto - klima', 'Beszelgetes a helyi klimavedelemrol.', CURDATE() + INTERVAL 10 DAY, CURDATE() + INTERVAL 10 DAY, 46.5665, 20.6685, 'Oroshaza, Muvelodesi haz', 1, 'civil', 22, NOW()),
(@u4, 'Udvar takaritas - iskola', 'Szulok es gyerekek kozos takaritas.', CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 5 DAY, 46.5638, 20.6658, 'Oroshaza, Altalanos iskola', 1, 'green_action', 30, NOW()),
(@u4, 'Faagak osszegyujtese', 'Vihar utan agak osszeszedese a kozteruleten.', CURDATE() + INTERVAL 18 DAY, CURDATE() + INTERVAL 18 DAY, 46.5648, 20.6668, 'Oroshaza, Rakoczi utca', 1, 'green_action', 10, NOW());

-- ========== JELSZÓ ==========
-- Alapértelmezett bejelentkezés: bármelyik teszt1@..teszt10@kataiattila.hu, jelszó: password.
-- Ha demo123 kell: php -r "echo password_hash('demo123', PASSWORD_DEFAULT);" majd UPDATE users SET pass_hash = '...' WHERE email LIKE 'teszt%@kataiattila.hu';

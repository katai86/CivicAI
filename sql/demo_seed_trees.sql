-- Demo fák (Urban Tree Cadastre) – futtasd csak 2026-13-tree-cadastre.sql után.
-- Csak demo/teszt környezetben. Orosháza környéki koordináták.

INSERT INTO trees (lat, lng, address, species, planting_year, health_status, risk_level, last_watered, public_visible, gov_validated, created_at) VALUES
(46.5655, 20.6675, 'Szabadság utca, park', 'Tilia platyphyllos', 1995, 'good', 'low', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 1, 1, NOW()),
(46.5665, 20.6685, 'Fő utca 2. mellett', 'Acer platanoides', 2010, 'fair', 'low', NULL, 1, 0, NOW());

-- Egy naplóbejegyzés (watering) az első fához (tree_id=1, user_id=1)
INSERT INTO tree_logs (tree_id, user_id, log_type, note, created_at)
SELECT 1, 1, 'watering', 'Demo öntözés', NOW() FROM trees WHERE id = 1 LIMIT 1;

-- Demo / Budaörs: intelligence modulok aktívvá tétele + zajos EU provider kikapcsolása kulcs nélkül
-- Futtatás: mysql -u user -p adatbazis < sql/2026-29-demo-intelligence-active.sql

INSERT INTO module_settings (module_key, setting_key, value) VALUES
('climate_gfw', 'enabled', '1'),
('climate_hungaromet', 'enabled', '1'),
('climate_eea', 'enabled', '1'),
('climate_gbif', 'enabled', '1'),
('climate_pvgis', 'enabled', '1'),
('climate_viirs', 'enabled', '1'),
('climate_ocm', 'enabled', '1'),
('ai_sam2', 'enabled', '1'),
('ai_sam', 'enabled', '1'),
('ai_yolo', 'enabled', '1'),
('ai_depth', 'enabled', '1'),
('ai_blip', 'enabled', '1'),
('hu_open_data', 'enabled', '1'),
('eu_open_data', 'enabled', '1'),
('eu_open_data', 'copernicus_enabled', '0'),
('eu_open_data', 'clms_enabled', '0'),
('mistral', 'enabled', '1'),
('mistral', 'ai_image_analysis_limit', '50')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- Budaörs / demo önkormányzat térhatár (GBIF, PVGIS, stb. helyi adat)
UPDATE authorities
SET min_lat = 47.42, max_lat = 47.50, min_lng = 18.90, max_lng = 19.02
WHERE name LIKE '%Budaörs%' OR name LIKE '%Budaors%' OR slug LIKE '%budaors%';

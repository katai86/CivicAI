-- =============================================================================
-- CivicAI – City Intelligence + Budaörs authority fix
-- Adatbázis: civicai_core  (phpMyAdmin → SQL fül → futtatás)
-- Idempotens: biztonságosan újrafuttatható (IF NOT EXISTS / ON DUPLICATE KEY)
-- =============================================================================

USE civicai_core;

-- ========== A) City Intelligence táblák + forrás registry ==========

CREATE TABLE IF NOT EXISTS city_data_sources (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_key VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  provider VARCHAR(120) NOT NULL DEFAULT '',
  source_type VARCHAR(64) NOT NULL DEFAULT 'open_data',
  endpoint VARCHAR(512) NULL,
  licence VARCHAR(160) NULL,
  refresh_minutes INT UNSIGNED NOT NULL DEFAULT 360,
  last_success_at DATETIME NULL,
  last_attempt_at DATETIME NULL,
  next_sync_at DATETIME NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'idle',
  records_processed INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(512) NULL,
  data_quality DECIMAL(4,3) NULL,
  geo_coverage VARCHAR(160) NULL,
  temporal_coverage VARCHAR(160) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  meta_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cds_key (source_key),
  KEY idx_cds_status (status, next_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_observations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  source_key VARCHAR(64) NOT NULL,
  source_type VARCHAR(64) NOT NULL DEFAULT 'open_data',
  external_id VARCHAR(190) NULL,
  category VARCHAR(64) NOT NULL DEFAULT 'general',
  indicator_type VARCHAR(96) NULL,
  observed_at DATETIME NOT NULL,
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  admin_area VARCHAR(160) NULL,
  value_num DECIMAL(18,6) NULL,
  value_text VARCHAR(255) NULL,
  unit VARCHAR(32) NULL,
  confidence DECIMAL(5,4) NULL,
  quality DECIMAL(4,3) NULL,
  dedupe_hash CHAR(40) NOT NULL,
  metadata_json LONGTEXT NULL,
  raw_ref VARCHAR(255) NULL,
  processing_version VARCHAR(32) NOT NULL DEFAULT 'ci-1',
  ingested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_co_dedupe (authority_id, source_key, dedupe_hash),
  KEY idx_co_auth_time (authority_id, observed_at),
  KEY idx_co_indicator (indicator_type, observed_at),
  KEY idx_co_cat (category, observed_at),
  KEY idx_co_geo (lat, lng),
  KEY idx_co_source (source_key, ingested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_indicator_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  indicator_key VARCHAR(96) NOT NULL,
  period_start DATETIME NOT NULL,
  period_end DATETIME NOT NULL,
  spatial_key VARCHAR(96) NOT NULL DEFAULT 'city',
  value_num DECIMAL(18,6) NOT NULL,
  unit VARCHAR(32) NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  quality DECIMAL(4,3) NULL,
  confidence DECIMAL(5,4) NULL,
  calc_method VARCHAR(120) NOT NULL DEFAULT 'mean',
  evidence_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_civ (authority_id, indicator_key, period_start, period_end, spatial_key),
  KEY idx_civ_key_time (indicator_key, period_end),
  KEY idx_civ_auth (authority_id, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_baselines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  indicator_key VARCHAR(96) NOT NULL,
  spatial_key VARCHAR(96) NOT NULL DEFAULT 'city',
  window_days INT UNSIGNED NOT NULL DEFAULT 90,
  baseline_value DECIMAL(18,6) NOT NULL,
  baseline_min DECIMAL(18,6) NULL,
  baseline_max DECIMAL(18,6) NULL,
  sample_count INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at DATETIME NOT NULL,
  method VARCHAR(64) NOT NULL DEFAULT 'rolling_mean',
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cbl (authority_id, indicator_key, spatial_key, window_days),
  KEY idx_cbl_key (indicator_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_anomalies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  indicator_key VARCHAR(96) NOT NULL,
  spatial_key VARCHAR(96) NOT NULL DEFAULT 'city',
  detected_at DATETIME NOT NULL,
  anomaly_score DECIMAL(10,4) NOT NULL,
  direction VARCHAR(16) NOT NULL DEFAULT 'down',
  current_value DECIMAL(18,6) NOT NULL,
  baseline_value DECIMAL(18,6) NULL,
  pct_deviation DECIMAL(10,4) NULL,
  confidence DECIMAL(5,4) NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'medium',
  evidence_json LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  PRIMARY KEY (id),
  KEY idx_ca_auth (authority_id, detected_at),
  KEY idx_ca_key (indicator_key, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_insights (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  insight_key VARCHAR(96) NOT NULL,
  title VARCHAR(255) NOT NULL,
  fact_text TEXT NOT NULL,
  interpretation_text TEXT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'info',
  confidence DECIMAL(5,4) NULL,
  trend VARCHAR(24) NULL,
  indicator_key VARCHAR(96) NULL,
  spatial_key VARCHAR(96) NOT NULL DEFAULT 'city',
  affected_area VARCHAR(160) NULL,
  period_start DATETIME NULL,
  period_end DATETIME NULL,
  cross_signals_json LONGTEXT NULL,
  evidence_json LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ci_auth (authority_id, created_at),
  KEY idx_ci_sev (severity, status),
  KEY idx_ci_key (insight_key, authority_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO city_data_sources
  (source_key, name, provider, source_type, endpoint, licence, refresh_minutes, status, geo_coverage, temporal_coverage, data_quality, is_active)
VALUES
  ('open_meteo', 'Open-Meteo forecast / climate proxy', 'Open-Meteo', 'weather', 'https://api.open-meteo.com/v1/forecast', 'CC-BY 4.0', 180, 'idle', 'authority_bbox', 'near_realtime', 0.850, 1),
  ('osm_overpass', 'OpenStreetMap Overpass aggregates', 'OpenStreetMap', 'urban_structure', 'https://overpass-api.de/api/interpreter', 'ODbL', 1440, 'idle', 'authority_bbox', 'snapshot', 0.800, 1),
  ('cams_air', 'CAMS air quality (ECMWF WMS)', 'ECMWF / CAMS', 'environment', 'https://atmosphere.copernicus.eu', 'Copernicus', 360, 'idle', 'authority_center', 'near_realtime', 0.750, 1),
  ('clms_urban_atlas', 'CLMS Urban Atlas land-cover shares', 'EEA / CLMS', 'land_cover', 'https://land.copernicus.eu', 'Copernicus', 10080, 'idle', 'authority_bbox', 'static_2018', 0.700, 1),
  ('pvgis', 'PVGIS solar potential', 'JRC', 'energy', 'https://re.jrc.ec.europa.eu/api/v5_2', 'EC', 10080, 'idle', 'authority_center', 'climatology', 0.800, 1),
  ('gbif', 'GBIF biodiversity occurrences', 'GBIF', 'biodiversity', 'https://api.gbif.org/v1', 'GBIF', 1440, 'idle', 'authority_bbox', 'occurrence', 0.700, 1),
  ('openchargemap', 'Open Charge Map EV stations', 'OpenChargeMap', 'mobility', 'https://api.openchargemap.io', 'OCM', 1440, 'idle', 'authority_bbox', 'poi', 0.750, 1),
  ('copernicus_ndvi', 'Sentinel-2 NDVI (CDSE / SH)', 'Copernicus', 'vegetation', 'https://sh.dataspace.copernicus.eu', 'Copernicus', 10080, 'idle', 'authority_bbox', 'satellite', 0.900, 1),
  ('citizen_reports', 'Citizen reports as urban signals', 'CivicAI', 'citizen', 'local://reports', 'internal', 60, 'idle', 'authority', 'event', 0.650, 1),
  ('urban_vision', 'City Brain / Vision observations', 'CivicAI', 'vision', 'local://urban_observations', 'internal', 60, 'idle', 'point', 'event', 0.600, 1),
  ('local_trees', 'Tree cadastre aggregates', 'CivicAI', 'green', 'local://trees', 'internal', 360, 'idle', 'authority', 'inventory', 0.850, 1),
  ('hungaromet', 'HungaroMet official weather (placeholder)', 'HungaroMet', 'weather', NULL, 'TBD', 180, 'idle', 'hungary', 'near_realtime', NULL, 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  provider = VALUES(provider),
  endpoint = VALUES(endpoint),
  licence = VALUES(licence),
  refresh_minutes = VALUES(refresh_minutes),
  is_active = VALUES(is_active);

-- ========== B) Budaörs bejelentések hatóság-javítás (id=15) ==========
-- Budapest (14) nagy bbox elnyelte a Budaörs pontokat → authority_id=14.
-- Ha már javítva van, az UPDATE-ek 0 sort érintnek (biztonságos).

UPDATE reports
SET authority_id = 15
WHERE city LIKE '%Budaörs%'
  AND (authority_id IS NULL OR authority_id <> 15);

UPDATE reports r
INNER JOIN authorities a ON a.id = 15
SET r.authority_id = 15
WHERE a.min_lat IS NOT NULL
  AND r.lat BETWEEN a.min_lat AND a.max_lat
  AND r.lng BETWEEN a.min_lng AND a.max_lng
  AND (r.authority_id IS NULL OR r.authority_id <> 15);

UPDATE reports
SET authority_id = 15
WHERE id IN (281, 282, 283, 284)
  AND authority_id = 14;

-- Budapest bbox szűkítése (ne fedje Budaörst) – ajánlott
UPDATE authorities
SET min_lat = 47.35, max_lat = 47.58, min_lng = 18.92, max_lng = 19.35
WHERE id = 14
  AND city = 'Budapest';

UPDATE trees t
INNER JOIN authorities a ON a.id = 15
SET t.authority_id = 15
WHERE t.authority_id = 14
  AND t.lat BETWEEN a.min_lat AND a.max_lat
  AND t.lng BETWEEN a.min_lng AND a.max_lng;

-- ========== C) Ellenőrző lekérdezések (eredményt olvasd el) ==========

SELECT 'city_data_sources' AS tbl, COUNT(*) AS n FROM city_data_sources
UNION ALL SELECT 'city_observations', COUNT(*) FROM city_observations
UNION ALL SELECT 'city_indicator_values', COUNT(*) FROM city_indicator_values
UNION ALL SELECT 'city_baselines', COUNT(*) FROM city_baselines
UNION ALL SELECT 'city_anomalies', COUNT(*) FROM city_anomalies
UNION ALL SELECT 'city_insights', COUNT(*) FROM city_insights;

SELECT id, name, city, min_lat, max_lat, min_lng, max_lng
FROM authorities WHERE id IN (14, 15);

SELECT id, authority_id, city, status, LEFT(title, 60) AS title
FROM reports
WHERE city LIKE '%Budaörs%' OR id BETWEEN 281 AND 285
ORDER BY id;

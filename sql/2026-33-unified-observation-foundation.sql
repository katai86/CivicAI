-- ========== 2026-33 Unified Observation & Evidence Foundation (M1) ==========
-- Futtatás egyszer: civicai_core
-- Ha oszlop már létezik, az adott ALTER hibát dob – hagyd ki azt a sort.

ALTER TABLE city_observations
  ADD COLUMN measurement_type VARCHAR(32) NOT NULL DEFAULT 'MEASURED' AFTER indicator_type;

ALTER TABLE city_observations
  ADD COLUMN provenance_layer VARCHAR(32) NULL AFTER measurement_type;

ALTER TABLE city_observations
  ADD COLUMN zone_key VARCHAR(96) NULL AFTER admin_area;

ALTER TABLE city_observations
  ADD COLUMN geographic_scope VARCHAR(32) NOT NULL DEFAULT 'point' AFTER zone_key;

ALTER TABLE city_observations
  ADD COLUMN model_provider VARCHAR(64) NULL AFTER processing_version;

ALTER TABLE city_observations
  ADD COLUMN model_name VARCHAR(96) NULL AFTER model_provider;

ALTER TABLE city_observations
  ADD COLUMN model_version VARCHAR(64) NULL AFTER model_name;

ALTER TABLE city_observations
  ADD COLUMN pipeline_version VARCHAR(32) NULL AFTER model_version;

ALTER TABLE city_observations
  ADD COLUMN entity_type VARCHAR(32) NULL AFTER pipeline_version;

ALTER TABLE city_observations
  ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type;

CREATE TABLE IF NOT EXISTS civic_evidence_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  parent_type VARCHAR(48) NOT NULL,
  parent_id BIGINT UNSIGNED NOT NULL,
  child_type VARCHAR(48) NOT NULL,
  child_id BIGINT UNSIGNED NOT NULL,
  relation VARCHAR(48) NOT NULL DEFAULT 'derived_from',
  layer VARCHAR(32) NOT NULL DEFAULT 'evidence',
  confidence DECIMAL(5,4) NULL,
  meta_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cel_parent (parent_type, parent_id),
  KEY idx_cel_child (child_type, child_id),
  KEY idx_cel_auth (authority_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS civic_observation_provenance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  observation_id BIGINT UNSIGNED NOT NULL,
  authority_id INT NULL,
  measurement_type VARCHAR(32) NOT NULL,
  provenance_layer VARCHAR(32) NULL,
  source_key VARCHAR(64) NULL,
  raw_ref VARCHAR(255) NULL,
  model_provider VARCHAR(64) NULL,
  model_name VARCHAR(96) NULL,
  model_version VARCHAR(64) NULL,
  pipeline_version VARCHAR(32) NULL,
  evidence_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cop_obs (observation_id),
  KEY idx_cop_auth (authority_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

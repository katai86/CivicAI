-- ========== 2026-34 Plant & Tree Intelligence (M15–M24) ==========

CREATE TABLE IF NOT EXISTS tree_inspection_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tree_id INT NULL,
  authority_id INT NULL,
  session_key VARCHAR(64) NOT NULL,
  required_shots_json TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tis_key (session_key),
  KEY idx_tis_tree (tree_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tree_inspections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tree_id INT NULL,
  authority_id INT NULL,
  session_id VARCHAR(64) NULL,
  image_paths_json LONGTEXT NULL,
  species_candidates_json LONGTEXT NULL,
  species_consensus LONGTEXT NULL,
  visual_observations_json LONGTEXT NULL,
  segmentation_json LONGTEXT NULL,
  measurements_json LONGTEXT NULL,
  health_label VARCHAR(32) NULL,
  health_score DECIMAL(5,1) NULL,
  risk_level VARCHAR(32) NULL,
  confidence_json LONGTEXT NULL,
  failure_state VARCHAR(48) NULL,
  model_metadata_json LONGTEXT NULL,
  evidence_json LONGTEXT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ti_tree (tree_id, created_at),
  KEY idx_ti_auth (authority_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plant_analysis_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NULL,
  tree_id INT NULL,
  image_hash CHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  result_json LONGTEXT NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_paj_status (status, created_at),
  KEY idx_paj_hash (image_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 2026-35 City Intelligence milestones (M2–M7) ==========

CREATE TABLE IF NOT EXISTS city_zones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NOT NULL,
  zone_key VARCHAR(96) NOT NULL,
  name VARCHAR(191) NULL,
  zone_type VARCHAR(48) NOT NULL DEFAULT 'grid',
  center_lat DECIMAL(10,7) NULL,
  center_lng DECIMAL(10,7) NULL,
  bounds_json LONGTEXT NULL,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cz_auth_key (authority_id, zone_key),
  KEY idx_cz_auth (authority_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS civic_discoveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NOT NULL,
  discovery_key VARCHAR(96) NOT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  category VARCHAR(64) NULL,
  priority_score DECIMAL(5,2) NULL,
  evidence_json LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  discovered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cd_key (authority_id, discovery_key),
  KEY idx_cd_auth_prio (authority_id, priority_score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_indicator_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NOT NULL,
  snapshot_date DATE NOT NULL,
  indicators_json LONGTEXT NOT NULL,
  health_score DECIMAL(5,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cis_auth_date (authority_id, snapshot_date),
  KEY idx_cis_auth (authority_id, snapshot_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS city_priorities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_id INT NOT NULL,
  entity_type VARCHAR(48) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  priority_score DECIMAL(5,2) NOT NULL,
  factors_json LONGTEXT NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cp_entity (authority_id, entity_type, entity_id),
  KEY idx_cp_score (authority_id, priority_score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

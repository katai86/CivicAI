-- Urban observations – City Brain / civic vision tartós városi megfigyelések (multi-tenant)
-- Futtatás: mysql -u user -p adatbazis < sql/2026-30-urban-observations.sql

CREATE TABLE IF NOT EXISTS urban_observations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'citybrain_vision',
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  image_path VARCHAR(512) NULL,
  street_condition VARCHAR(32) NULL,
  severity VARCHAR(16) NULL,
  category VARCHAR(64) NULL,
  vegetation_pct DECIMAL(6,2) NULL,
  scene_summary TEXT NULL,
  recommended_action TEXT NULL,
  wow_highlights_json MEDIUMTEXT NULL,
  trees_json MEDIUMTEXT NULL,
  green_surfaces_json MEDIUMTEXT NULL,
  street_issues_json MEDIUMTEXT NULL,
  output_json LONGTEXT NULL,
  confidence_score DECIMAL(5,4) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_uo_auth_created (authority_id, created_at),
  KEY idx_uo_geo (lat, lng),
  KEY idx_uo_source (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

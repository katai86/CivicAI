-- MILESTONE 1 – Urban Tree Cadastre (Civic Green Intelligence Platform)
-- Fák és fa-naplók; reports bővítés (related_tree_id, AI/gov mezők).
-- Futtatás: 2026-12 után.

-- ========== TREES ==========
CREATE TABLE IF NOT EXISTS trees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  address VARCHAR(255) NULL,
  species VARCHAR(120) NULL,
  estimated_age INT NULL COMMENT 'becsült életkor (év)',
  planting_year INT NULL,
  trunk_diameter DECIMAL(6,2) NULL COMMENT 'cm',
  canopy_diameter DECIMAL(6,2) NULL COMMENT 'm',
  health_status VARCHAR(32) NULL COMMENT 'good,fair,poor,critical',
  risk_level VARCHAR(32) NULL COMMENT 'low,medium,high',
  last_inspection DATE NULL,
  last_watered DATE NULL,
  adopted_by_user_id INT NULL,
  gov_validated TINYINT(1) NOT NULL DEFAULT 0,
  public_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_trees_geo (lat, lng),
  KEY idx_trees_health (health_status),
  KEY idx_trees_risk (risk_level),
  KEY idx_trees_adopted (adopted_by_user_id),
  KEY idx_trees_visible (public_visible),
  KEY idx_trees_last_watered (last_watered)
);

-- ========== TREE_LOGS ==========
CREATE TABLE IF NOT EXISTS tree_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tree_id INT NOT NULL,
  user_id INT NULL,
  log_type VARCHAR(32) NOT NULL COMMENT 'inspection,watering,damage,maintenance',
  note TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tree_logs_tree (tree_id),
  KEY idx_tree_logs_user (user_id),
  KEY idx_tree_logs_type (log_type),
  KEY idx_tree_logs_created (created_at)
);

-- ========== REPORTS bővítés (fa kapcsolat + AI/gov mezők) ==========
-- Ha az oszlop már létezik, a megfelelő ALTER sor hibát dobhat – ugorjuk át vagy futtassuk egyenként.
ALTER TABLE reports
  ADD COLUMN related_tree_id INT NULL,
  ADD COLUMN ai_category VARCHAR(64) NULL,
  ADD COLUMN ai_priority VARCHAR(32) NULL,
  ADD COLUMN report_gov_validated TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN impact_type VARCHAR(64) NULL;

ALTER TABLE reports ADD KEY idx_reports_related_tree (related_tree_id);

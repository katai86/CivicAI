-- =============================================================================
-- ÖSSZEVONT MIGRÁCIÓ – egy fájlban az összes eddigi SQL bővítés és módosítás
-- Futtatás: mysql -u user -p adatbazis < sql/01_consolidated_migrations.sql
-- Minden lépés feltételes: ha a tábla/oszlop/index már létezik, kihagyja.
-- Tartalom: 2026-03 (admin, map_layers) … 2026-19 (user_module_toggles),
--           trees, tree_logs, tree_adoptions, tree_watering_logs, ai_results,
--           FMS, authorities, facilities, civil_events, report_likes, friends, stb.
-- Demo seed fájlok (demo_seed*.sql) nincsenek benne.
-- =============================================================================

DELIMITER //

DROP PROCEDURE IF EXISTS add_column_if_not_exists//
CREATE PROCEDURE add_column_if_not_exists(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition VARCHAR(1000)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', REPLACE(p_table, '`', '``'), '` ADD COLUMN `', REPLACE(p_column, '`', '``'), '` ', p_definition);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//

DROP PROCEDURE IF EXISTS add_index_if_not_exists//
CREATE PROCEDURE add_index_if_not_exists(
  IN p_table VARCHAR(64),
  IN p_index_name VARCHAR(64),
  IN p_columns VARCHAR(500)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index_name
  ) THEN
    SET @sql = CONCAT('CREATE INDEX `', REPLACE(p_index_name, '`', '``'), '` ON `', REPLACE(p_table, '`', '``'), '` ', p_columns);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//

DELIMITER ;

-- ========== Alap táblák (ha nincs exportból: report_status_log, report_attachments) ==========
CREATE TABLE IF NOT EXISTS report_status_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  old_status VARCHAR(32) NULL,
  new_status VARCHAR(32) NOT NULL,
  note TEXT NULL,
  changed_by VARCHAR(64) NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status_log_report (report_id),
  KEY idx_status_log_report_changed (report_id, changed_at)
);

CREATE TABLE IF NOT EXISTS report_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  user_id INT NULL,
  filename VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_att_report (report_id),
  KEY idx_att_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 2026-03 Admin dashboard ==========
CALL add_column_if_not_exists('users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
CALL add_index_if_not_exists('users', 'idx_users_is_active', '(is_active)');
CALL add_index_if_not_exists('users', 'idx_users_created', '(created_at)');
CALL add_index_if_not_exists('reports', 'idx_reports_created', '(created_at)');
CALL add_index_if_not_exists('reports', 'idx_reports_status_created', '(status, created_at)');
CALL add_index_if_not_exists('reports', 'idx_reports_category_created', '(category, created_at)');
CALL add_index_if_not_exists('reports', 'idx_reports_user', '(user_id)');
CALL add_index_if_not_exists('reports', 'idx_reports_geo', '(category, lat, lng)');
CALL add_index_if_not_exists('report_status_log', 'idx_status_log_report_changed', '(report_id, changed_at)');

CREATE TABLE IF NOT EXISTS map_layers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  layer_key VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(32) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_temporary TINYINT(1) NOT NULL DEFAULT 0,
  visible_from DATE NULL,
  visible_to DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY layer_key (layer_key)
);

CREATE TABLE IF NOT EXISTS map_layer_points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  layer_id INT NOT NULL,
  name VARCHAR(120) NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  address VARCHAR(255) NULL,
  meta_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_layer_points_layer FOREIGN KEY (layer_id) REFERENCES map_layers(id) ON DELETE CASCADE
);
CALL add_index_if_not_exists('map_layer_points', 'idx_layer_points_layer', '(layer_id)');

-- ========== 2026-17 Layers + hatóság + fakataszter (M2) ==========
CALL add_column_if_not_exists('map_layers', 'authority_id', 'INT NULL');
CALL add_column_if_not_exists('map_layers', 'layer_type', 'VARCHAR(32) NULL');
INSERT IGNORE INTO map_layers (layer_key, name, category, is_active, is_temporary, layer_type) VALUES ('trees', 'Fák (fakataszter)', 'trees', 1, 0, 'trees');

-- ========== 2026-18 Beépülő modulok (FMS, Mistral, stb.) – admin felületen ki/be, API kulcs ==========
CREATE TABLE IF NOT EXISTS module_settings (
  module_key VARCHAR(64) NOT NULL,
  setting_key VARCHAR(64) NOT NULL,
  value TEXT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (module_key, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 2026-19 Govuser saját modul kapcsolók (csak UI/használat) ==========
CREATE TABLE IF NOT EXISTS user_module_toggles (
  user_id INT NOT NULL,
  module_key VARCHAR(64) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module_key),
  KEY idx_user_module (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 2026-04 FMS bridge ==========
CREATE TABLE IF NOT EXISTS fms_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  open311_service_request_id VARCHAR(64) NOT NULL,
  last_status VARCHAR(32) NULL,
  last_updated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fms_report (report_id),
  UNIQUE KEY uniq_fms_service_request (open311_service_request_id)
);

CREATE TABLE IF NOT EXISTS fms_sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  last_requests_sync_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS authorities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  country VARCHAR(80) NULL,
  region VARCHAR(80) NULL,
  city VARCHAR(80) NULL,
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(40) NULL,
  website VARCHAR(190) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  min_lat DECIMAL(10,7) NULL,
  max_lat DECIMAL(10,7) NULL,
  min_lng DECIMAL(10,7) NULL,
  max_lng DECIMAL(10,7) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS authority_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NOT NULL,
  service_code VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS authority_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_authority_user (authority_id, user_id)
);

CREATE TABLE IF NOT EXISTS facilities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  service_type VARCHAR(80) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  hours_json TEXT NULL,
  replacement_json TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_facility_user (user_id)
);

CREATE TABLE IF NOT EXISTS civil_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  address VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CALL add_index_if_not_exists('fms_reports', 'idx_fms_reports_report_id', '(report_id)');
CALL add_index_if_not_exists('fms_reports', 'idx_fms_reports_status', '(last_status)');
CALL add_index_if_not_exists('facilities', 'idx_facilities_geo', '(lat, lng)');
CALL add_index_if_not_exists('civil_events', 'idx_civil_events_geo', '(lat, lng)');
CALL add_index_if_not_exists('authorities', 'idx_authority_city', '(city)');
CALL add_index_if_not_exists('authority_contacts', 'idx_authority_contacts_code', '(service_code)');

CALL add_column_if_not_exists('reports', 'authority_id', 'INT NULL');
CALL add_column_if_not_exists('reports', 'service_code', 'VARCHAR(64) NULL');
CALL add_column_if_not_exists('reports', 'external_id', 'VARCHAR(64) NULL');
CALL add_column_if_not_exists('reports', 'external_status', 'VARCHAR(32) NULL');
CALL add_index_if_not_exists('reports', 'idx_reports_authority', '(authority_id)');

-- ========== 2026-05 Social ==========
CREATE TABLE IF NOT EXISTS report_likes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_report_like (report_id, user_id)
);

CREATE TABLE IF NOT EXISTS friend_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT NOT NULL,
  to_user_id INT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_friend_request (from_user_id, to_user_id)
);

CREATE TABLE IF NOT EXISTS friends (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  friend_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_friend_pair (user_id, friend_user_id)
);

CALL add_index_if_not_exists('report_likes', 'idx_report_likes_report', '(report_id)');
CALL add_index_if_not_exists('report_likes', 'idx_report_likes_user', '(user_id)');
CALL add_index_if_not_exists('friend_requests', 'idx_friend_requests_to', '(to_user_id, status)');
CALL add_index_if_not_exists('friend_requests', 'idx_friend_requests_from', '(from_user_id, status)');
CALL add_index_if_not_exists('friends', 'idx_friends_user', '(user_id)');

-- ========== 2026-07 Authority bbox ==========
CALL add_column_if_not_exists('authorities', 'min_lat', 'DECIMAL(10,7) NULL');
CALL add_column_if_not_exists('authorities', 'max_lat', 'DECIMAL(10,7) NULL');
CALL add_column_if_not_exists('authorities', 'min_lng', 'DECIMAL(10,7) NULL');
CALL add_column_if_not_exists('authorities', 'max_lng', 'DECIMAL(10,7) NULL');

-- ========== 2026-08 Users role ==========
CALL add_column_if_not_exists('users', 'role', 'VARCHAR(32) NULL');

-- ========== 2026-09 Users role ENUM ==========
SET @col_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' LIMIT 1);
SET @run_modify = IF(@col_type IS NOT NULL AND @col_type NOT LIKE '%govuser%', 1, 0);
SET @sql = IF(@run_modify = 1, 'ALTER TABLE users MODIFY COLUMN role ENUM(''superadmin'',''admin'',''user'',''civil'',''civiluser'',''communityuser'',''govuser'') NOT NULL DEFAULT ''user''', 'SELECT 1 AS noop');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ========== 2026-10 Authorities new columns ==========
CALL add_column_if_not_exists('authorities', 'contact_email', 'VARCHAR(190) NULL');
CALL add_column_if_not_exists('authorities', 'contact_phone', 'VARCHAR(40) NULL');
CALL add_column_if_not_exists('authorities', 'website', 'VARCHAR(190) NULL');
CALL add_column_if_not_exists('authorities', 'country', 'VARCHAR(80) NULL');
CALL add_column_if_not_exists('authorities', 'region', 'VARCHAR(80) NULL');
CALL add_column_if_not_exists('authorities', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');

-- ========== 2026-12 User preferences ==========
CALL add_column_if_not_exists('users', 'preferred_lang', 'VARCHAR(8) NULL');
CALL add_column_if_not_exists('users', 'preferred_theme', 'VARCHAR(8) NULL');

-- ========== 2026-13 Tree cadastre ==========
CREATE TABLE IF NOT EXISTS trees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  address VARCHAR(255) NULL,
  species VARCHAR(120) NULL,
  estimated_age INT NULL,
  planting_year INT NULL,
  trunk_diameter DECIMAL(6,2) NULL,
  canopy_diameter DECIMAL(6,2) NULL,
  health_status VARCHAR(32) NULL,
  risk_level VARCHAR(32) NULL,
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

CREATE TABLE IF NOT EXISTS tree_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tree_id INT NOT NULL,
  user_id INT NULL,
  log_type VARCHAR(32) NOT NULL,
  note TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tree_logs_tree (tree_id),
  KEY idx_tree_logs_user (user_id),
  KEY idx_tree_logs_type (log_type),
  KEY idx_tree_logs_created (created_at)
);

CALL add_column_if_not_exists('reports', 'related_tree_id', 'INT NULL');
CALL add_column_if_not_exists('reports', 'ai_category', 'VARCHAR(64) NULL');
CALL add_column_if_not_exists('reports', 'ai_priority', 'VARCHAR(32) NULL');
CALL add_column_if_not_exists('reports', 'report_gov_validated', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL add_column_if_not_exists('reports', 'impact_type', 'VARCHAR(64) NULL');
CALL add_index_if_not_exists('reports', 'idx_reports_related_tree', '(related_tree_id)');

-- ========== 2026-14 Tree adoption (+ 2026-18 uniq_tree_user) ==========
CREATE TABLE IF NOT EXISTS tree_adoptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tree_id INT NOT NULL,
  user_id INT NOT NULL,
  adopted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tree_user (tree_id, user_id),
  KEY idx_tree_adoptions_tree (tree_id),
  KEY idx_tree_adoptions_user (user_id),
  KEY idx_tree_adoptions_status (status)
);

CREATE TABLE IF NOT EXISTS tree_watering_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tree_id INT NOT NULL,
  user_id INT NOT NULL,
  photo VARCHAR(255) NULL,
  water_amount DECIMAL(6,2) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tree_watering_tree (tree_id),
  KEY idx_tree_watering_user (user_id),
  KEY idx_tree_watering_created (created_at)
);

-- ========== 2026-15 Green actions (civil_events bővítés) ==========
CALL add_column_if_not_exists('civil_events', 'event_type', 'VARCHAR(32) NOT NULL DEFAULT ''civil''');
CALL add_column_if_not_exists('civil_events', 'participants_count', 'INT NULL DEFAULT 0');
CALL add_index_if_not_exists('civil_events', 'idx_civil_events_type', '(event_type)');

-- ========== 2026-16 AI results ==========
CREATE TABLE IF NOT EXISTS ai_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NULL,
  task_type VARCHAR(64) NOT NULL,
  model_name VARCHAR(64) NOT NULL,
  input_hash CHAR(64) NOT NULL,
  output_json LONGTEXT NULL,
  confidence_score DECIMAL(5,4) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_results_entity (entity_type, entity_id),
  KEY idx_ai_results_task (task_type, created_at),
  KEY idx_ai_results_input (input_hash)
);

-- ========== 2026-20 M4 trees.notes ==========
CALL add_column_if_not_exists('trees', 'notes', 'TEXT NULL');

-- ========== 2026-21 M7 tree_species_care ==========
CREATE TABLE IF NOT EXISTS tree_species_care (
  id INT AUTO_INCREMENT PRIMARY KEY,
  species_name VARCHAR(120) NOT NULL,
  watering_interval_days INT NOT NULL DEFAULT 7,
  watering_volume_liters DECIMAL(6,2) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_species_name (species_name(60))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 2026-22 M3 Citizen Ideation (ideas + idea_votes) ==========
CREATE TABLE IF NOT EXISTS ideas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  address VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'submitted',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ideas_geo (lat, lng),
  KEY idx_ideas_status (status),
  KEY idx_ideas_user (user_id),
  KEY idx_ideas_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idea_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  idea_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_idea_vote (idea_id, user_id),
  KEY idx_idea_votes_idea (idea_id),
  KEY idx_idea_votes_user (user_id),
  CONSTRAINT fk_idea_votes_idea FOREIGN KEY (idea_id) REFERENCES ideas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== 2026-23 M4 Participatory Budgeting (budget_projects + budget_votes) ==========
CREATE TABLE IF NOT EXISTS budget_projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  budget DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  authority_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_budget_projects_authority (authority_id),
  KEY idx_budget_projects_status (status),
  KEY idx_budget_projects_created (created_at),
  CONSTRAINT fk_budget_projects_authority FOREIGN KEY (authority_id) REFERENCES authorities (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_budget_vote (project_id, user_id),
  KEY idx_budget_votes_project (project_id),
  KEY idx_budget_votes_user (user_id),
  CONSTRAINT fk_budget_votes_project FOREIGN KEY (project_id) REFERENCES budget_projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========== Segéd procedure-ök eltávolítása ==========
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
DROP PROCEDURE IF EXISTS add_index_if_not_exists;

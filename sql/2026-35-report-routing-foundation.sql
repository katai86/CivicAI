-- Report routing, CIV case numbers, authority join requests (M9 foundation)

CREATE TABLE IF NOT EXISTS case_serials (
  city_prefix CHAR(2) NOT NULL,
  case_date CHAR(8) NOT NULL,
  last_seq INT NOT NULL DEFAULT 0,
  PRIMARY KEY (city_prefix, case_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_routing_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  routing_target VARCHAR(64) NOT NULL,
  recipient_email VARCHAR(190) NULL,
  decision_reason VARCHAR(255) NULL,
  mail_subject VARCHAR(255) NULL,
  mail_sent TINYINT(1) NOT NULL DEFAULT 0,
  mail_error TEXT NULL,
  payload_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_routing_report (report_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS authority_join_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  authority_id INT NULL,
  municipality_city VARCHAR(120) NOT NULL,
  organization_name VARCHAR(160) NULL,
  job_title VARCHAR(120) NULL,
  message TEXT NULL,
  status ENUM('pending','approved','rejected','auto_approved') NOT NULL DEFAULT 'pending',
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL,
  review_note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_join_user (user_id, status),
  KEY idx_join_authority (authority_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reports + geo-scoped profiles (idempotent: ignore duplicate column errors on re-run)
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'case_no') = 0,
  'ALTER TABLE reports ADD COLUMN case_no VARCHAR(32) NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'routing_target') = 0,
  'ALTER TABLE reports ADD COLUMN routing_target VARCHAR(64) NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'routed_at') = 0,
  'ALTER TABLE reports ADD COLUMN routed_at TIMESTAMP NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'reports' AND INDEX_NAME = 'uniq_reports_case_no') = 0,
  'ALTER TABLE reports ADD UNIQUE KEY uniq_reports_case_no (case_no)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facilities' AND COLUMN_NAME = 'authority_id') = 0,
  'ALTER TABLE facilities ADD COLUMN authority_id INT NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'civil_events' AND COLUMN_NAME = 'authority_id') = 0,
  'ALTER TABLE civil_events ADD COLUMN authority_id INT NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'municipality_city') = 0,
  'ALTER TABLE users ADD COLUMN municipality_city VARCHAR(120) NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

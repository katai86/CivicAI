-- ========== 2026-25 EU Open Data – Milestone 1 (foundation) ==========
-- Egységes cache és provider napló hivatalos EU / Copernicus integrációkhoz.
-- Futtatás: mysql -u user -p adatbazis < sql/2026-25-eu-open-data-foundation.sql
-- (Vagy: benne van a 01_consolidated_migrations.sql / 00_run_all_migrations_safe.sql frissítésében.)

CREATE TABLE IF NOT EXISTS external_data_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_key VARCHAR(64) NOT NULL,
  cache_key VARCHAR(255) NOT NULL,
  payload_json LONGTEXT NULL,
  fetched_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ok',
  error_message VARCHAR(512) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_source_cache (source_key, cache_key),
  KEY idx_edc_expires (expires_at),
  KEY idx_edc_source (source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_data_provider_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_key VARCHAR(64) NOT NULL,
  action VARCHAR(120) NOT NULL,
  status VARCHAR(32) NOT NULL,
  message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_edpl_source (source_key),
  KEY idx_edpl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

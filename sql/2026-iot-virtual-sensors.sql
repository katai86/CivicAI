-- ========== 2026-IOT Virtual sensors (külső IoT / virtuális szenzor réteg) ==========
-- Futtatható önállóan vagy 01_consolidated_migrations.sql végére másolva.
-- Egyesített adatmodell: minden külső provider (OpenAQ, AQICN, OpenWeather, stb.) normalizált szenzorként jelenik meg.

CREATE TABLE IF NOT EXISTS virtual_sensors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_provider VARCHAR(64) NOT NULL,
  external_station_id VARCHAR(120) NOT NULL,
  name VARCHAR(255) NULL,
  sensor_type VARCHAR(64) NULL,
  category VARCHAR(64) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  address_or_area_name VARCHAR(255) NULL,
  municipality VARCHAR(120) NULL,
  country VARCHAR(80) NULL,
  ownership_type VARCHAR(32) NOT NULL DEFAULT 'external',
  display_mode VARCHAR(32) NOT NULL DEFAULT 'virtual_external',
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  trust_score DECIMAL(5,2) NULL,
  confidence_score DECIMAL(5,2) NULL,
  license_note VARCHAR(255) NULL,
  api_source_url VARCHAR(512) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_station (source_provider, external_station_id),
  KEY idx_vs_provider (source_provider),
  KEY idx_vs_active (is_active),
  KEY idx_vs_location (latitude, longitude),
  KEY idx_vs_municipality (municipality),
  KEY idx_vs_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_sensor_metrics_latest (
  id INT AUTO_INCREMENT PRIMARY KEY,
  virtual_sensor_id INT NOT NULL,
  metric_key VARCHAR(64) NOT NULL,
  metric_value DECIMAL(12,4) NULL,
  metric_unit VARCHAR(32) NULL,
  measured_at DATETIME NULL,
  quality_flag VARCHAR(32) NULL,
  raw_payload_reference VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sensor_metric (virtual_sensor_id, metric_key),
  KEY idx_vsml_sensor (virtual_sensor_id),
  KEY idx_vsml_measured (measured_at),
  CONSTRAINT fk_vsml_sensor FOREIGN KEY (virtual_sensor_id) REFERENCES virtual_sensors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_sensor_metric_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  virtual_sensor_id INT NOT NULL,
  metric_key VARCHAR(64) NOT NULL,
  metric_value DECIMAL(12,4) NULL,
  metric_unit VARCHAR(32) NULL,
  measured_at DATETIME NULL,
  quality_flag VARCHAR(32) NULL,
  raw_payload_reference VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vsmh_sensor (virtual_sensor_id),
  KEY idx_vsmh_measured (measured_at),
  KEY idx_vsmh_sensor_key (virtual_sensor_id, metric_key, measured_at),
  CONSTRAINT fk_vsmh_sensor FOREIGN KEY (virtual_sensor_id) REFERENCES virtual_sensors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_sensor_provider_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider_name VARCHAR(64) NOT NULL,
  sync_started_at DATETIME NULL,
  sync_finished_at DATETIME NULL,
  status VARCHAR(32) NULL,
  imported_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  log_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vspl_provider (provider_name),
  KEY idx_vspl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

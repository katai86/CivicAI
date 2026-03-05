-- Köz.Tér + FixMyStreet bridge + roles

-- Bridge tables
CREATE TABLE fms_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  open311_service_request_id VARCHAR(64) NOT NULL,
  last_status VARCHAR(32) NULL,
  last_updated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fms_report (report_id),
  UNIQUE KEY uniq_fms_service_request (open311_service_request_id)
);

CREATE TABLE fms_sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  last_requests_sync_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Authorities & role links (govuser)
CREATE TABLE authorities (
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

CREATE TABLE authority_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NOT NULL,
  service_code VARCHAR(64) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE authority_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_authority_user (authority_id, user_id)
);

-- Community profiles (communityuser)
CREATE TABLE facilities (
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

-- Civil events (civiluser)
CREATE TABLE civil_events (
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

CREATE INDEX idx_fms_reports_report_id ON fms_reports (report_id);
CREATE INDEX idx_fms_reports_status ON fms_reports (last_status);
CREATE INDEX idx_facilities_geo ON facilities (lat, lng);
CREATE INDEX idx_civil_events_geo ON civil_events (lat, lng);
CREATE INDEX idx_authority_city ON authorities (city);
CREATE INDEX idx_authority_contacts_code ON authority_contacts (service_code);

-- Reports table extension (local Open311/authority routing)
ALTER TABLE reports
  ADD COLUMN authority_id INT NULL,
  ADD COLUMN service_code VARCHAR(64) NULL,
  ADD COLUMN external_id VARCHAR(64) NULL,
  ADD COLUMN external_status VARCHAR(32) NULL;

CREATE INDEX idx_reports_authority ON reports (authority_id);

-- Admin dashboard: users + layers

-- 1) users: soft deactivate
ALTER TABLE users
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Optional index if you filter often
CREATE INDEX idx_users_is_active ON users (is_active);

-- Reports indexes for admin filtering
-- (skip if you already added similar indexes)
CREATE INDEX idx_reports_created ON reports (created_at);
CREATE INDEX idx_reports_status_created ON reports (status, created_at);
CREATE INDEX idx_reports_category_created ON reports (category, created_at);
CREATE INDEX idx_reports_user ON reports (user_id);
CREATE INDEX idx_reports_geo ON reports (category, lat, lng);

-- Status log index for faster latest-status join
CREATE INDEX idx_status_log_report_changed ON report_status_log (report_id, changed_at);

-- Users created_at index for stats
CREATE INDEX idx_users_created ON users (created_at);

-- 2) map layers
CREATE TABLE map_layers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  layer_key VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(32) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_temporary TINYINT(1) NOT NULL DEFAULT 0,
  visible_from DATE NULL,
  visible_to DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE map_layer_points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  layer_id INT NOT NULL,
  name VARCHAR(120) NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  address VARCHAR(255) NULL,
  meta_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_layer_points_layer
    FOREIGN KEY (layer_id) REFERENCES map_layers(id)
    ON DELETE CASCADE
);

CREATE INDEX idx_layer_points_layer ON map_layer_points (layer_id);

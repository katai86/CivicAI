-- M7 Tree watering: fajtánkénti öntözési ajánlás
-- Futtatás: 2026-20 után. Procedure: add_column_if_not_exists (00_run_all / 01_consolidated).

CREATE TABLE IF NOT EXISTS tree_species_care (
  id INT AUTO_INCREMENT PRIMARY KEY,
  species_name VARCHAR(120) NOT NULL,
  watering_interval_days INT NOT NULL DEFAULT 7,
  watering_volume_liters DECIMAL(6,2) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_species_name (species_name(60))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

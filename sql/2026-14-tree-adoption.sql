-- MILESTONE 2 – Citizen Tree Adoption (fa örökbefogadás)
-- Futtatás: 2026-13 után (trees, tree_logs már létezik).

-- ========== TREE_ADOPTIONS ==========
CREATE TABLE IF NOT EXISTS tree_adoptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tree_id INT NOT NULL,
  user_id INT NOT NULL,
  adopted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tree_user (tree_id, user_id),
  KEY idx_tree_adoptions_tree (tree_id),
  KEY idx_tree_adoptions_user (user_id),
  KEY idx_tree_adoptions_status (status)
);

-- ========== TREE_WATERING_LOGS ==========
CREATE TABLE IF NOT EXISTS tree_watering_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tree_id INT NOT NULL,
  user_id INT NOT NULL,
  photo VARCHAR(255) NULL,
  water_amount DECIMAL(6,2) NULL COMMENT 'liter',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tree_watering_tree (tree_id),
  KEY idx_tree_watering_user (user_id),
  KEY idx_tree_watering_created (created_at)
);


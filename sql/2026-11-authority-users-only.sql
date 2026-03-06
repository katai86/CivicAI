-- Csak authority_users tábla (ha a 2026-04-fms-bridge.sql nem futott le teljesen, pl. authorities már létezett)
-- Hatósági felhasználó hozzárendelés (admin: Hatóságok → Hatósági felhasználó → Hozzárendelés)

CREATE TABLE IF NOT EXISTS authority_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  authority_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_authority_user (authority_id, user_id)
);

-- M4 Participatory Budgeting: projektek + szavazás (városonként).
-- Táblák: budget_projects (title, description, budget, status, authority_id), budget_votes (user_id, project_id).

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

-- M3 Citizen Ideation: ötletek + szavazás (CitizenLab style)
-- Táblák: ideas, idea_votes. Státusz: submitted, under_review, planned, in_progress, completed.

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

-- Social features: likes + friends

CREATE TABLE report_likes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_report_like (report_id, user_id)
);

CREATE TABLE friend_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT NOT NULL,
  to_user_id INT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending/accepted/declined
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_friend_request (from_user_id, to_user_id)
);

CREATE TABLE friends (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  friend_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_friend_pair (user_id, friend_user_id)
);

CREATE INDEX idx_report_likes_report ON report_likes (report_id);
CREATE INDEX idx_report_likes_user ON report_likes (user_id);
CREATE INDEX idx_friend_requests_to ON friend_requests (to_user_id, status);
CREATE INDEX idx_friend_requests_from ON friend_requests (from_user_id, status);
CREATE INDEX idx_friends_user ON friends (user_id);

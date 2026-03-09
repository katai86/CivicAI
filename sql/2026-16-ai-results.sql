-- MILESTONE 10 – AI Cost Control / ai_results
-- AI hívások eredményeinek tárolása és limit ellenőrzés alapja.
-- Futtatás: 2026-15 után.

CREATE TABLE IF NOT EXISTS ai_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT NULL,
  task_type VARCHAR(64) NOT NULL,
  model_name VARCHAR(64) NOT NULL,
  input_hash CHAR(64) NOT NULL,
  output_json LONGTEXT NULL,
  confidence_score DECIMAL(5,4) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ai_results_entity (entity_type, entity_id),
  KEY idx_ai_results_task (task_type, created_at),
  KEY idx_ai_results_input (input_hash)
);


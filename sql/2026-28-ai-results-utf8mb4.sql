-- ai_results – magyar ékezetes AI JSON (ő, ű, stb.) latin1/utf8 hibák javítása
-- Futtatás: mysql -u user -p adatbazis < sql/2026-28-ai-results-utf8mb4.sql

ALTER TABLE ai_results
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE ai_results
  MODIFY output_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;

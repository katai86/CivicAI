-- Fakataszter UTF-8 (magyar ő/ű a species mezőben)
-- Futtatás a HELYES adatbázison (civicai_core):
--   mysql -u civicai_core -p civicai_core < sql/2026-31-trees-utf8mb4.sql

ALTER TABLE trees CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE tree_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

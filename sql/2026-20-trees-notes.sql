-- M4 Smart Tree Registry: trees.notes mező
-- Futtatás: 01_consolidated_migrations.sql vagy 00_run_all_migrations_safe.sql után (add_column_if_not_exists szükséges).
-- Vagy manuálisan: ALTER TABLE trees ADD COLUMN notes TEXT NULL;

CALL add_column_if_not_exists('trees', 'notes', 'TEXT NULL');

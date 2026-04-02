-- Fák hatósághoz kötése (gov fakataszter szűrés). Ha már létezik az oszlop, hagyható.
-- Futtatás: mysql ... < sql/2026-26-trees-authority-id.sql

ALTER TABLE trees ADD COLUMN authority_id INT NULL;
ALTER TABLE trees ADD KEY idx_trees_authority (authority_id);

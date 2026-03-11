-- tree_adoptions: egy fa–user pár csak egyszer (dupla adopt elkerülése).
-- Futtatás: 2026-14 után. Ha már vannak duplikátumok, előbb tisztítsd (pl. egy user_id–tree_id csak egy active maradjon).

ALTER TABLE tree_adoptions ADD UNIQUE KEY uniq_tree_user (tree_id, user_id);

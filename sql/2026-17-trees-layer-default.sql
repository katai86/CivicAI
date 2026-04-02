-- Default trees layer (fakataszter) – ha még nincs map_layers sor layer_key='trees'.
-- A térképen a fa réteg ezzel válik láthatóvá; a fák adatai a trees táblából jönnek (api/trees_list.php).
-- Futtatás: 2026-13 (tree-cadastre) után.

INSERT IGNORE INTO map_layers (layer_key, name, category, is_active, is_temporary, layer_type)
VALUES ('trees', 'Fák (fakataszter)', 'trees', 1, 0, 'trees');

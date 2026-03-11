# Fa réteg (fakataszter)

## Adatmodell

- **trees** tábla: lat, lng, address, species, health_status, risk_level, adopted_by_user_id, last_watered, public_visible, stb. (sql/2026-13-tree-cadastre.sql)
- **map_layers**: a térképen a fa réteg egy sor, ahol `layer_key = 'trees'` és `layer_type = 'trees'`. Ha ez aktív (`is_active = 1`), a frontend betölti a fákat az `api/trees_list.php`-ból (nem a map_layer_points-ból).

## Konzisztencia

- **admin_layers** (api/admin_layers.php): trees kategóriánál layer_key és layer_type fix „trees”; a layerhez nem adhatsz kézi pontot (a fák a Fa felvitelből jönnek).
- **layers_public** (api/layers_public.php): visszaadja a map_layers sorokat; a frontend ha lát trees layert és az aktív, meghívja a trees_list.php-t.

## Jelmagyarázat (lang)

- hu.php / en.php: `legend.tree_layer`, `legend.trees_all`, `legend.trees_adopted`, `legend.trees_needs_water`, `legend.trees_dangerous`, `legend.tree_add`
- Fa műveletek: `tree.action_adopt`, `tree.action_water`, `tree.submit_add` stb.

## Default layer

- Ha a map_layers-ben nincs trees sor: futtasd a **sql/2026-17-trees-layer-default.sql** (vagy a 00_run_all_migrations_safe.sql már tartalmazza).

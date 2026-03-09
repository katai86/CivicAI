# Baseline schema – referencia

Ez a dokumentum a Köz.Tér adatbázis **célállapotát** írja le táblaszinten. A tényleges schema a **kataia_civicai** (vagy hasonló) exportból + az **inkrementális migrációkból** (2026-03 … 2026-11) áll össze. Ne futtass baseline SQL-t nulláról, hanem a **00_README_MIGRATIONS.md** sorrendjét kövesd.

## Táblák (célállapot)

| Tábla | Forrás / megjegyzés |
|-------|----------------------|
| users | Export + 2026-03 (is_active), 2026-08 (role), 2026-09 (role ENUM) |
| reports | Export + 2026-04 (authority_id, service_code, external_*) |
| report_attachments, report_status_log | Export |
| report_likes, friends, friend_requests | 2026-05-social.sql |
| badges, user_badges, user_xp_log, user_xp_events | Export |
| authorities | Export (régi oszlopok) VAGY 2026-04 (új formátum); 2026-07 (bbox), 2026-10 (contact_email, is_active) |
| authority_contacts, authority_users | 2026-04 VAGY 2026-11 (csak authority_users) |
| facilities, civil_events | 2026-04-fms-bridge.sql |
| map_layers, map_layer_points | 2026-03-admin-dashboard.sql |
| fms_reports, fms_sync_log | 2026-04 (FMS bridge, opcionális) |
| trees, tree_logs | 2026-13 (Urban Tree Cadastre, Green Intelligence) |
| tree_adoptions, tree_watering_logs | 2026-14 (Citizen Tree Adoption, Green Intelligence) |
| reports (related_tree_id, ai_*, report_gov_validated, impact_type) | 2026-13 |
| ai_results | 2026-16 (AI cost control, AI eredmények) |

## Kapcsolatok (rövid)

- **reports.authority_id** → authorities.id  
- **reports.user_id** → users.id  
- **authority_users.authority_id** → authorities.id, **authority_users.user_id** → users.id  
- **report_status_log.report_id**, **report_attachments.report_id** → reports.id
- **reports.related_tree_id** → trees.id
- **tree_logs.tree_id** → trees.id; **tree_logs.user_id** → users.id  
- **tree_adoptions.tree_id**, **tree_watering_logs.tree_id** → trees.id  
- **tree_adoptions.user_id**, **tree_watering_logs.user_id** → users.id  

Részletes entitás–mező leírás: **docs/MILESTONE_3_DATA_MODEL_AND_MIGRATIONS.md**.

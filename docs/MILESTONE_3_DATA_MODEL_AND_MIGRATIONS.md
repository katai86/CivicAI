# MILESTONE 3 – Adatmodell és migrációk konszolidálása

## 1. Megértésem a jelenlegi állapotról

A schema több forrásból jön: kataia_civicai export (reports, users, badges, report_attachments, report_status_log, report_likes, friends, friend_requests, map_layers, map_layer_points, user_badges, user_xp_log, user_xp_events, authorities régi oszlopokkal), plusz sql/2026-03, 2026-04, 2026-05, 2026-07, 2026-08, 2026-09, 2026-10. Nincs egyetlen „futtasd ezt és kész” baseline.

## 2. Entitások és kapcsolatok (canonical terv)

| Entitás | Fő mezők | Kapcsolat | Stabilitás |
|---------|----------|-----------|------------|
| users | id, email, pass_hash, display_name, role, is_verified, consent_*, total_xp, level, streak_days, last_active_date, avatar_filename, profile_public, first_name, last_name, phone, address_*, is_active | - | Stabil |
| reports | id, category, title, description, lat, lng, address_*, status, created_at, user_id, authority_id, service_code, reporter_*, notify_*, consent_*, ip_hash | user_id → users, authority_id → authorities | Stabil |
| report_attachments | id, report_id, user_id, filename, stored_name, mime, size_bytes | report_id → reports | Stabil |
| report_status_log | id, report_id, old_status, new_status, note, changed_by, changed_at | report_id → reports | Stabil |
| report_likes | id, report_id, user_id | report_id, user_id | Stabil |
| badges | id, code, name, description, icon | - | Stabil |
| user_badges | id, user_id, badge_id, earned_at | user_id → users, badge_id → badges | Stabil |
| user_xp_log | id, user_id, points, reason, report_id, created_at | user_id → users | Stabil |
| user_xp_events | id, user_id, event_key, created_at | user_id → users | Stabil (egyszeri bonusok) |
| friends | id, user_id, friend_user_id | user_id, friend_user_id → users | Stabil |
| friend_requests | id, from_user_id, to_user_id, status | from/to → users | Stabil |
| authorities | id, name, (contact_email|email), (is_active|active), city, category?, min_lat, max_lat, min_lng, max_lng, country?, region?, contact_phone?, website? | - | Schema két formában (régi: email, active, category) |
| authority_contacts | id, authority_id, service_code, name, description, is_active | authority_id → authorities | Opcionális (nincs a régi exportban) |
| authority_users | id, authority_id, user_id, role | authority_id → authorities, user_id → users | Opcionális |
| facilities | id, user_id, name, service_type, lat, lng, address, phone, email, hours_json, replacement_json, is_active | user_id → users | Opcionális (2026-04) |
| civil_events | id, user_id, title, description, start_date, end_date, lat, lng, address, is_active | user_id → users | Opcionális (2026-04) |
| map_layers | id, layer_key, name, category, is_active, is_temporary, visible_from, visible_to | - | Stabil |
| map_layer_points | id, layer_id, name, lat, lng, address, meta_json | layer_id → map_layers | Stabil |
| fms_reports | id, report_id, open311_service_request_id, last_status, last_updated_at | report_id → reports | FMS bridge (opcionális) |
| fms_sync_log | id, last_requests_sync_at | - | FMS bridge |

## 3. Kötelező / opcionális mezők (összefoglalva)

- **reports:** category, description, lat, lng, status kötelező; authority_id, service_code, address_* opcionális (fallback INSERT-ek kezelik hiányukat).
- **users:** email, pass_hash kötelező; role default 'user'; consent_*, is_active opcionális (régi DB-n lehet hiány).
- **authorities:** name kötelező; email (régi) vagy contact_email (új); active/is_active; bbox (min/max lat/lng) opcionális.

## 4. Indexjavaslatok

- reports: (status, created_at), (category, lat, lng), (user_id), (authority_id), (ip_hash, created_at).
- users: (email UNIQUE), (is_active), (created_at).
- report_likes: (report_id), (user_id).
- friend_requests: (to_user_id, status), (from_user_id, status).
- facilities: (lat, lng), (user_id UNIQUE).
- civil_events: (lat, lng), (start_date, end_date).
- fms_reports: (report_id UNIQUE), (open311_service_request_id UNIQUE).

(A meglévő migrációk és az export már tartalmaznak sok ilyen indexet.)

## 5. Mely táblák stabilak, melyek kísérleti jellegűek

- **Stabil:** users, reports, report_attachments, report_status_log, report_likes, badges, user_badges, user_xp_log, user_xp_events, friends, friend_requests, map_layers, map_layer_points.
- **Opcionális / kísérleti:** authority_contacts, authority_users (ha nincs 2026-04 teljesen futtatva); facilities; civil_events; fms_reports, fms_sync_log.

## 6. Legacy / átnevezendő / összevonandó mezők

- **authorities:** email → contact_email (vagy marad email régi néven); active → is_active. A kód már kezeli mindkét formátumot (GET normalizálás, INSERT fallback).
- **users:** role ENUM bővítés (2026-09) – civiluser, communityuser, govuser. Régi: csak user, civil, admin, superadmin.

## 7. Javaslat: 1 baseline schema + inkrementális migrációk

- **Baseline (javasolt fájl: sql/00_baseline_schema.sql):** Ne írjunk mindent nulláról; a baseline = „a kataia_civicai exportnak megfelelő minimális schema” (reports, users, badges, report_attachments, report_status_log, report_likes, friends, friend_requests, map_layers, map_layer_points, user_badges, user_xp_log, user_xp_events, authorities a régi oszlopokkal). Ezt csak dokumentációs célra lehet összeállítani (export alapján), és nem feltétlenül futtatjuk új környezetben – inkább referencia.
- **Inkrementális migrációk (meglévőket megtartjuk, sorrend tisztázva):**
  - 2026-03-admin-dashboard.sql (users.is_active, indexek, map_layers, map_layer_points)
  - 2026-04-fms-bridge.sql (fms_*, authorities új formátum, authority_contacts, authority_users, facilities, civil_events, reports.authority_id, service_code)
  - 2026-05-social.sql (report_likes, friend_requests, friends)
  - 2026-07-authority-bbox.sql (authorities min/max lat/lng)
  - 2026-08-users-role.sql (users.role ha hiányzik)
  - 2026-09-users-role-enum.sql (role ENUM bővítés)
  - 2026-10-authorities-new-columns.sql (contact_email, is_active stb. – opcionális)
- **Dokumentáció:** docs/MIGRATION_ORDER.md – futtatási sorrend és „mi mire épül” rövid leírás (létrehozom a következő lépésben).

## 8. Konkrét teendők

- Létrehoztam docs/MILESTONE_3_DATA_MODEL_AND_MIGRATIONS.md.
- Opcionális: sql/00_README_MIGRATIONS.md (rövid sorrend + figyelmeztetések) – hozzáadom.

## 9. Rövid magyarázat laikus nyelven

Az adatbázis tábláit és kapcsolatait összeírtuk; megjelöltük, mi kötelező, mi opcionális, és mi a régi név (pl. authorities email vs contact_email). A migrációkat sorrendbe raktuk: először alap (admin, layers), majd FMS/authority/facilities/civil_events, majd social, majd role bővítés. Így karbantartható és új környezetben reprodukálható.

-- Gov felhasználó: hpb@kataiattila.hu / jelszó: Chance201910!
-- Futtasd phpMyAdminban (egyszer).

REPLACE INTO users (email, pass_hash, display_name, role, is_active, is_verified, verify_token, created_at)
VALUES (
  'hpb@kataiattila.hu',
  '$2a$10$/0M1nOIDExpfY3rHM0/gAewscDLGjFbxUvBklb5ZBUB8pl03gnSWK',
  'HPB Gov',
  'govuser',
  1,
  1,
  NULL,
  NOW()
);

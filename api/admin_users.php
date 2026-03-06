<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $q = trim((string)($_GET['q'] ?? ''));
  $role = trim((string)($_GET['role'] ?? ''));
  $active = isset($_GET['active']) ? (string)$_GET['active'] : '';
  $limit = (int)($_GET['limit'] ?? 50);
  $offset = (int)($_GET['offset'] ?? 0);
  if ($limit < 1 || $limit > 200) $limit = 50;
  if ($offset < 0) $offset = 0;

  $where = [];
  $params = [];
  if ($q !== '') {
    $where[] = "(u.email LIKE :q OR u.display_name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
  }
  if ($role !== '') {
    $where[] = "u.role = :role";
    $params[':role'] = $role;
  }
  if ($active === '0' || $active === '1') {
    $where[] = "u.is_active = :active";
    $params[':active'] = (int)$active;
  }

  $sqlBase = "
    SELECT u.id, u.email, u.display_name, u.role, u.total_xp, u.level,
           u.created_at, u.profile_public, u.is_active
    FROM users u
  ";
  $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  $sql = $sqlBase . " $sqlWhere ORDER BY u.id DESC LIMIT $limit OFFSET $offset";

  try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    // Fallback if is_active column missing
    if ($active === '0' || $active === '1') {
      $where = array_values(array_filter($where, fn($w) => strpos($w, 'is_active') === false));
      $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
      unset($params[':active']);
    }
    $sqlBase = "
      SELECT u.id, u.email, u.display_name, u.role, u.total_xp, u.level,
             u.created_at, u.profile_public
      FROM users u
    ";
    $sql = $sqlBase . " $sqlWhere ORDER BY u.id DESC LIMIT $limit OFFSET $offset";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    foreach ($rows as &$r) { $r['is_active'] = 1; }
    unset($r);
  }

  json_response(['ok'=>true,'data'=>$rows]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$body = read_json_body();
$action = (string)($body['action'] ?? '');
$userId = (int)($body['user_id'] ?? 0);
if ($userId <= 0) json_response(['ok'=>false,'error'=>'Invalid user_id'], 400);

$currentUserId = current_user_id() ?: 0;

if ($action === 'update_role') {
  $role = (string)($body['role'] ?? '');
  $allowed = ['user','civiluser','communityuser','govuser','admin','superadmin'];
  if (!in_array($role, $allowed, true)) json_response(['ok'=>false,'error'=>'Invalid role'], 400);
  try {
    db()->prepare("UPDATE users SET role = :r WHERE id = :id")->execute([':r'=>$role, ':id'=>$userId]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    log_error('admin_users update_role: ' . $msg);
    if (stripos($msg, 'Unknown column') !== false && stripos($msg, 'role') !== false) {
      json_response(['ok'=>false,'error'=>'A users táblában nincs role oszlop. Futtasd: sql/2026-08-users-role.sql'], 400);
    }
    if (stripos($msg, 'Data truncated') !== false || stripos($msg, 'enum') !== false) {
      json_response(['ok'=>false,'error'=>'A role érték nem megengedett. A különböző felhasználótípusokhoz futtasd: sql/2026-09-users-role-enum.sql'], 400);
    }
    json_response(['ok'=>false,'error'=>'Role frissítés sikertelen'], 500);
  }
}

if ($action === 'toggle_active') {
  $isActive = (int)($body['is_active'] ?? 1);
  if (!in_array($isActive, [0,1], true)) json_response(['ok'=>false,'error'=>'Invalid state'], 400);
  if ($userId === $currentUserId && $isActive === 0) {
    json_response(['ok'=>false,'error'=>'Nem tilthatod le saját magad.'], 400);
  }
  $stmt = db()->prepare("SELECT role FROM users WHERE id = :id");
  $stmt->execute([':id'=>$userId]);
  $role = (string)($stmt->fetchColumn() ?: '');
  if ($role === 'superadmin' && $isActive === 0) {
    json_response(['ok'=>false,'error'=>'Superadmin nem tiltható le.'], 400);
  }
  try {
    db()->prepare("UPDATE users SET is_active = :a WHERE id = :id")->execute([':a'=>$isActive, ':id'=>$userId]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'is_active oszlop hiányzik. Futtasd az SQL-t.'], 500);
  }
  json_response(['ok'=>true]);
}

json_response(['ok'=>false,'error'=>'Invalid action'], 400);

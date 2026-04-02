<?php
/**
 * M4 Participatory Budgeting – projektek listája (nyilvános).
 * GET: authority_id (opcionális), status (opcionális; üres = csak published), limit.
 * Ha nincs authority_id: bejelentkezett user address_city alapján keressük a hatóságot.
 * Válasz: data[], settings (frame_amount, conditions_text, description, voting_closed), user_has_address, authority_id.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'published';
$allowedStatuses = ['draft', 'published', 'closed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = 'published';
}
$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 1 || $limit > 500) $limit = 200;

$uid = current_user_id();
$uid = $uid ? (int)$uid : 0;
$userHasAddress = false;
if ($uid > 0 && $authorityId === null) {
  try {
    $st = db()->prepare("SELECT address_city FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $city = trim((string)($st->fetchColumn() ?: ''));
    $userHasAddress = $city !== '';
    if ($userHasAddress) {
      $st2 = db()->prepare("SELECT id FROM authorities WHERE TRIM(city) = ? LIMIT 1");
      $st2->execute([$city]);
      $aid = $st2->fetchColumn();
      if ($aid !== false) $authorityId = (int)$aid;
    }
  } catch (Throwable $e) {}
}

$rows = [];
$settings = null;
try {
  $sql = "
    SELECT
      p.id, p.title, p.description, p.budget, p.status, p.authority_id, p.created_at, p.submitted_by,
      a.name AS authority_name,
      COALESCE((SELECT COUNT(*) FROM budget_votes v WHERE v.project_id = p.id), 0) AS vote_count,
      (SELECT 1 FROM budget_votes v WHERE v.project_id = p.id AND v.user_id = :uid LIMIT 1) AS voted_by_me
    FROM budget_projects p
    LEFT JOIN authorities a ON a.id = p.authority_id
    WHERE 1=1
  ";
  $params = [':uid' => $uid];

  if ($statusFilter !== '') {
    $sql .= " AND p.status = :status";
    $params[':status'] = $statusFilter;
  }
  if ($authorityId !== null && $authorityId > 0) {
    $sql .= " AND p.authority_id = :aid";
    $params[':aid'] = $authorityId;
  }

  $sql .= " ORDER BY vote_count DESC, p.created_at DESC LIMIT " . (int)$limit;

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as &$r) {
    $r['budget'] = (float)($r['budget'] ?? 0);
    $r['vote_count'] = (int)($r['vote_count'] ?? 0);
    $r['voted_by_me'] = !empty($r['voted_by_me']);
  }
  unset($r);

  if ($authorityId > 0) {
    $st = db()->prepare("SELECT frame_amount, conditions_text, description, voting_closed FROM budget_settings WHERE authority_id = ? LIMIT 1");
    $st->execute([$authorityId]);
    $settings = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($settings !== null) {
      $settings['frame_amount'] = $settings['frame_amount'] !== null ? (float)$settings['frame_amount'] : null;
      $settings['voting_closed'] = (int)($settings['voting_closed'] ?? 0);
    }
  }
} catch (Throwable $e) {
  // táblák hiányozhatnak
}

json_response([
  'ok' => true,
  'data' => $rows,
  'settings' => $settings,
  'user_has_address' => $userHasAddress,
  'authority_id' => $authorityId,
]);

<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AiRouter.php';
require_once __DIR__ . '/../services/AiPromptBuilder.php';

start_secure_session();
require_user();

$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin','superadmin'], true);
if (!$isAdmin && $role !== 'govuser') {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (!$isAdmin) {
  $uid = current_user_id();
  if (!user_module_enabled($uid, 'mistral')) {
    json_response(['ok' => false, 'error' => 'AI disabled for this user'], 403);
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$body = read_json_body();
if ((string)($body['action'] ?? '') !== 'generate') {
  json_response(['ok' => false, 'error' => 'Invalid action'], 400);
}
$type = (string)($body['type'] ?? 'summary');
if (!in_array($type, ['summary','esg'], true)) {
  json_response(['ok' => false, 'error' => 'Invalid type'], 400);
}

// Kontextus: govuser első hatósága, adminnál nincs korlátozás (de itt is kérhet majd paraméterezést)
$authority = null;
$authorityId = null;
$city = null;
if ($isAdmin) {
  // Admin esetén: ha küld authority_id-t, akkor arra szűrünk, különben globális.
  $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
  if ($authorityId) {
    $stmt = db()->prepare("SELECT * FROM authorities WHERE id = ? LIMIT 1");
    $stmt->execute([$authorityId]);
    $authority = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  $city = $authority ? trim((string)($authority['city'] ?? '')) : null;
} else {
  $uid = current_user_id();
  $stmt = db()->prepare("
    SELECT a.*
    FROM authority_users au
    JOIN authorities a ON a.id = au.authority_id
    WHERE au.user_id = :uid
    ORDER BY a.name ASC
    LIMIT 1
  ");
  $stmt->execute([':uid' => $uid]);
  $authority = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$authority) {
    json_response(['ok' => false, 'error' => 'Nincs hatóság hozzárendelve.'], 403);
  }
  $authorityId = (int)$authority['id'];
  $city = trim((string)($authority['city'] ?? ''));
}

// Statisztika összeszedése a kiválasztott scope-ra
$where = '1=1';
$params = [];
if ($authorityId) {
  $where = 'r.authority_id = ?';
  $params[] = $authorityId;
} elseif ($city) {
  $where = '(r.authority_id IS NULL AND r.city = ?)';
  $params[] = $city;
}

$pdo = db();
$stats = [
  'reports_total' => 0,
  'reports_open' => 0,
  'reports_7d' => 0,
  'by_status' => [],
  'by_category' => [],
  'environment' => [],
  'social' => [],
  'governance' => [],
];

try {
  $q0 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where");
  $q0->execute($params);
  $stats['reports_total'] = (int)$q0->fetchColumn();
} catch (Throwable $e) {}
try {
  $q7 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
  $q7->execute($params);
  $stats['reports_7d'] = (int)$q7->fetchColumn();
} catch (Throwable $e) {}
try {
  $qo = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where AND r.status NOT IN ('solved','closed','rejected')");
  $qo->execute($params);
  $stats['reports_open'] = (int)$qo->fetchColumn();
} catch (Throwable $e) {}
try {
  $qs = $pdo->prepare("SELECT r.status, COUNT(*) AS cnt FROM reports r WHERE $where GROUP BY r.status");
  $qs->execute($params);
  foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stats['by_status'][(string)$row['status']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}
try {
  $qc = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $where GROUP BY r.category");
  $qc->execute($params);
  foreach ($qc->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stats['by_category'][(string)$row['category']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}

// Legutóbbi bejelentések minták (token-szűkítés)
$recent = [];
try {
  $stmt = $pdo->prepare("
    SELECT r.id, r.category, r.status, r.title, r.description, r.address_approx, r.created_at
    FROM reports r
    WHERE $where
    ORDER BY r.created_at DESC
    LIMIT 25
  ");
  $stmt->execute($params);
  $recent = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$router = new AiRouter();
if (!$router->isEnabled()) {
  json_response(['ok' => false, 'error' => 'AI disabled or not configured'], 400);
}

$scopeTitle = $city ?: ($authority ? (string)$authority['name'] : 'Terület');
$prompt = ($type === 'esg')
  ? AiPromptBuilder::govEsg($scopeTitle, $stats, $recent)
  : AiPromptBuilder::govSummary($scopeTitle, $stats, $recent);

$taskType = $type === 'esg' ? 'gov_esg' : 'gov_summary';
$inputHash = hash('sha256', $taskType . '|' . $scopeTitle . '|' . json_encode($stats) . '|' . json_encode($recent));

$resp = $router->callJson($taskType === 'gov_esg' ? 'gov_summary' : 'gov_summary', $prompt, [
  'max_tokens' => 900,
  'temperature' => 0.2,
]);
if (empty($resp['ok'])) {
  json_response(['ok' => false, 'error' => $resp['error'] ?? 'AI failed'], 502);
}

$modelName = (string)($resp['model'] ?? (defined('AI_TEXT_MODEL') ? AI_TEXT_MODEL : ''));
$data = is_array($resp['data']) ? $resp['data'] : null;

// Mentés ai_results-be (best-effort)
try {
  ai_store_result('gov', $authorityId ? (int)$authorityId : null, $taskType, $modelName, $inputHash, $data, null);
} catch (Throwable $e) { /* ignore */ }

// UI-hoz egyszerű szöveg mező (ha a JSON különböző formátumú)
$text = '';
if (is_array($data)) {
  $text = (string)($data['text'] ?? $data['summary'] ?? '');
}
json_response(['ok' => true, 'data' => ['text' => $text, 'raw' => $data]]);


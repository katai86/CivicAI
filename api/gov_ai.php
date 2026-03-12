<?php
/**
 * Gov dashboard AI: összefoglaló / ESG generálás Mistral (vagy más provider) alapján.
 * Szükséges: (1) Gov usernek legyen hatóság (authority_users), (2) Mistral modul be + API kulcs,
 * (3) AI_SUMMARY_LIMIT > 0 (config/env). A statisztika a reports tábla authority_id / city alapján készül.
 */
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
$allowedTypes = ['summary', 'esg', 'maintenance', 'engagement', 'sustainability'];
if (!in_array($type, $allowedTypes, true)) {
  json_response(['ok' => false, 'error' => 'Invalid type'], 400);
}
$timeframe = (string)($body['timeframe'] ?? 'last_90_days');
$allowedTimeframes = ['last_30_days', 'last_90_days', 'last_year'];
if (!in_array($timeframe, $allowedTimeframes, true)) {
  $timeframe = 'last_90_days';
}
// Date range for maintenance/engagement/sustainability reports
$dateFrom = null;
$dateTo = date('Y-m-d');
switch ($timeframe) {
  case 'last_30_days': $dateFrom = date('Y-m-d', strtotime('-30 days')); break;
  case 'last_90_days': $dateFrom = date('Y-m-d', strtotime('-90 days')); break;
  case 'last_year': $dateFrom = date('Y-m-d', strtotime('-1 year')); break;
}
$timeframeLabel = [
  'last_30_days' => 'Utolsó 30 nap',
  'last_90_days' => 'Utolsó 90 nap',
  'last_year' => 'Elmúlt év',
][$timeframe] ?? $timeframe;

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
    json_response(['ok' => false, 'error' => t('gov.no_authority_assigned')], 403);
  }
  $authorityId = (int)$authority['id'];
  $city = trim((string)($authority['city'] ?? ''));
}

// Statisztika összeszedése a kiválasztott scope-ra (+ időablak maintenance/engagement/sustainability esetén)
$where = '1=1';
$params = [];
if ($authorityId) {
  $where = 'r.authority_id = ?';
  $params[] = $authorityId;
} elseif ($city) {
  $where = '(r.authority_id IS NULL AND r.city = ?)';
  $params[] = $city;
}

$dateWhere = '';
if ($dateFrom !== null && in_array($type, ['maintenance', 'engagement', 'sustainability'], true)) {
  $dateWhere = ' AND r.created_at >= ? AND r.created_at <= ?';
  $params[] = $dateFrom . ' 00:00:00';
  $params[] = $dateTo . ' 23:59:59';
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
  $q0 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere");
  $q0->execute($params);
  $stats['reports_total'] = (int)$q0->fetchColumn();
} catch (Throwable $e) {}
try {
  $q7 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
  $q7->execute($params);
  $stats['reports_7d'] = (int)$q7->fetchColumn();
} catch (Throwable $e) {}
try {
  $qo = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $where $dateWhere AND r.status NOT IN ('solved','closed','rejected')");
  $qo->execute($params);
  $stats['reports_open'] = (int)$qo->fetchColumn();
} catch (Throwable $e) {}
try {
  $qs = $pdo->prepare("SELECT r.status, COUNT(*) AS cnt FROM reports r WHERE $where $dateWhere GROUP BY r.status");
  $qs->execute($params);
  foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stats['by_status'][(string)$row['status']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}
try {
  $qc = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $where $dateWhere GROUP BY r.category");
  $qc->execute($params);
  foreach ($qc->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stats['by_category'][(string)$row['category']] = (int)$row['cnt'];
  }
} catch (Throwable $e) {}

// Engagement/sustainability: active users, upvotes in period
if (in_array($type, ['engagement', 'sustainability'], true)) {
  try {
    $qu = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $where $dateWhere AND r.user_id IS NOT NULL AND r.user_id > 0");
    $qu->execute($params);
    $stats['active_users_period'] = (int)$qu->fetchColumn();
  } catch (Throwable $e) { $stats['active_users_period'] = 0; }
  try {
    $ql = $pdo->prepare("SELECT COUNT(*) FROM report_likes rl INNER JOIN reports r ON r.id = rl.report_id WHERE $where $dateWhere");
    $ql->execute($params);
    $stats['upvotes_period'] = (int)$ql->fetchColumn();
  } catch (Throwable $e) { $stats['upvotes_period'] = 0; }
}

// Environment/trees for sustainability
if ($type === 'sustainability') {
  try {
    $stats['trees_total'] = (int)$pdo->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1")->fetchColumn();
  } catch (Throwable $e) { $stats['trees_total'] = 0; }
  try {
    $stats['green_reports'] = (int)(isset($stats['by_category']['green']) ? $stats['by_category']['green'] : 0);
  } catch (Throwable $e) {}
}

// Legutóbbi bejelentések minták (token-szűkítés)
$recent = [];
try {
  $stmt = $pdo->prepare("
    SELECT r.id, r.category, r.status, r.title, r.description, r.address_approx, r.created_at
    FROM reports r
    WHERE $where $dateWhere
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

if ($type === 'maintenance') {
  $prompt = AiPromptBuilder::reportMaintenance($scopeTitle, $timeframeLabel, $stats, $recent);
} elseif ($type === 'engagement') {
  $prompt = AiPromptBuilder::reportEngagement($scopeTitle, $timeframeLabel, $stats, $recent);
} elseif ($type === 'sustainability') {
  $prompt = AiPromptBuilder::reportSustainability($scopeTitle, $timeframeLabel, $stats, $recent);
} elseif ($type === 'esg') {
  $prompt = AiPromptBuilder::govEsg($scopeTitle, $stats, $recent);
} else {
  $prompt = AiPromptBuilder::govSummary($scopeTitle, $stats, $recent);
}

$taskType = ($type === 'esg') ? 'gov_esg' : 'gov_summary';
$inputHash = hash('sha256', $taskType . '|' . $scopeTitle . '|' . $type . '|' . $timeframe . '|' . json_encode($stats) . '|' . json_encode($recent));

$resp = $router->callJson($taskType, $prompt, [
  'max_tokens' => 900,
  'temperature' => 0.2,
  'timeout' => 45,
  'response_format' => 'json_object',
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

// UI-hoz: mindig tisztított szöveg + raw objektum (JSON soha ne legyen nyersan megjelenítve)
$text = '';
if (is_array($data)) {
  $text = (string)($data['text'] ?? $data['summary'] ?? '');
}
if ($text === '' && !empty($resp['raw']['choices'][0]['message']['content'])) {
  $rawContent = trim((string)$resp['raw']['choices'][0]['message']['content']);
  // Modell néha ```json ... ```-ot ad vissza: kinyerjük a JSON-t
  if (preg_match('/^\s*```\s*\w*\s*\n?(.*?)\n?\s*```\s*$/s', $rawContent, $m)) {
    $rawContent = trim($m[1]);
  }
  if (($jsonStart = strpos($rawContent, '{')) !== false) {
    $maybe = json_decode(substr($rawContent, $jsonStart), true);
    if (is_array($maybe)) {
      $data = $maybe;
      $text = (string)($maybe['text'] ?? $maybe['summary'] ?? '');
    } else {
      $text = $rawContent;
    }
  } else {
    $text = $rawContent;
  }
}
json_response(['ok' => true, 'data' => ['text' => $text, 'raw' => $data]]);


<?php
/**
 * M4 – AI Sentiment Analysis API.
 * GET, csak gov/admin. authority_id, date_from, date_to opcionális.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/SentimentAnalyzer.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$role = current_user_role();
$uid = current_user_id() ? (int)current_user_id() : 0;
$authorityIds = [];
$authorityCities = [];

if (in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  if ($aid > 0) {
    $authorityIds = [$aid];
  } else {
    try {
      $rows = db()->query("SELECT id FROM authorities ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
      $authorityIds = array_map('intval', $rows);
    } catch (Throwable $e) {}
  }
} else {
  if ($uid > 0) {
    try {
      $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id");
      $stmt->execute([$uid]);
      $authorityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
      if (!empty($authorityIds)) {
        $stmt = db()->prepare("SELECT city FROM authorities WHERE id = ?");
        foreach ($authorityIds as $aid) {
          $stmt->execute([$aid]);
          $city = $stmt->fetchColumn();
          if ($city) $authorityCities[] = $city;
        }
        $authorityCities = array_values(array_unique(array_filter($authorityCities)));
      }
    } catch (Throwable $e) {}
  }
}

$baseWhere = '1=1';
$baseParams = [];
if (in_array($role, ['admin', 'superadmin'], true)) {
  if (!empty($authorityIds)) {
    $baseWhere = "r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
    $baseParams = $authorityIds;
  }
} else {
  if (empty($authorityIds)) {
    $baseWhere = '1=0';
  } else {
    $baseWhere = "r.authority_id IN (" . implode(',', array_fill(0, count($authorityIds), '?')) . ")";
    $baseParams = $authorityIds;
    if (!empty($authorityCities)) {
      $baseWhere .= " OR (r.authority_id IS NULL AND r.city IN (" . implode(',', array_fill(0, count($authorityCities), '?')) . "))";
      $baseParams = array_merge($baseParams, $authorityCities);
    }
  }
}

$dateFrom = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$dateTo   = isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])   ? $_GET['date_to'] : date('Y-m-d');

$scopeTitle = 'Municipality';
if (!empty($authorityIds)) {
  try {
    $stmt = db()->prepare("SELECT name FROM authorities WHERE id = ? LIMIT 1");
    $stmt->execute([$authorityIds[0]]);
    $scopeTitle = (string)($stmt->fetchColumn() ?: $scopeTitle);
  } catch (Throwable $e) {}
}

$outputLang = function_exists('current_lang') ? current_lang() : 'hu';

try {
  $analyzer = new SentimentAnalyzer();
  $data = $analyzer->analyze($baseWhere, $baseParams, $dateFrom, $dateTo, $scopeTitle, $outputLang);
  json_response(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('sentiment_analysis: ' . $e->getMessage());
  }
  json_response(['ok' => false, 'error' => 'Server error'], 500);
}

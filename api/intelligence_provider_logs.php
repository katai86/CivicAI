<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExternalDataCache.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$source = trim((string)($_GET['source'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
$logs = ExternalDataCache::recentProviderLogs($source !== '' ? $source : null, $limit);

json_response(['ok' => true, 'data' => ['logs' => $logs]]);

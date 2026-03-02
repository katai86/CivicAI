<?php
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$period = isset($_GET['period']) ? (string)$_GET['period'] : 'all';
if (!in_array($period, ['week','month','all'], true)) $period = 'all';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

$rows = get_leaderboard($period, $limit);

json_response(['ok' => true, 'data' => $rows, 'period' => $period]);

<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$category = $category === 'all' ? '' : $category;

// Publikus térképen csak "élő" státuszok jelenjenek meg
$visibleStatuses = [
  'approved',
  'needs_info',
  'forwarded',
  'waiting_reply',
  'in_progress',
];

$in = implode(',', array_fill(0, count($visibleStatuses), '?'));

$sql = "
  SELECT
    r.id, r.category, r.title, r.description, r.lat, r.lng,
    r.status,
    r.created_at,
    COALESCE(l.last_changed_at, r.created_at) AS updated_at,
    r.reporter_is_anonymous,
    CASE
      WHEN r.reporter_is_anonymous = 0 THEN r.reporter_name
      ELSE NULL
    END AS reporter_name_public
  FROM reports r
  LEFT JOIN (
    SELECT report_id, MAX(changed_at) AS last_changed_at
    FROM report_status_log
    GROUP BY report_id
  ) l ON l.report_id = r.id
  WHERE r.status IN ($in)
";

if ($category !== '') {
  $sql .= " AND r.category = ?";
}

$sql .= " ORDER BY r.created_at DESC LIMIT 2000";

$stmt = db()->prepare($sql);

$exec = array_values($visibleStatuses);
if ($category !== '') {
  $exec[] = $category;
}

$stmt->execute($exec);
$rows = $stmt->fetchAll();

json_response(['ok' => true, 'data' => $rows]);
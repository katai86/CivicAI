<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

$rid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($rid <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);

$stmt = db()->prepare("
  SELECT old_status, new_status, note, changed_by, changed_at
  FROM report_status_log
  WHERE report_id = :id
  ORDER BY changed_at DESC, id DESC
  LIMIT 200
");
$stmt->execute([':id' => $rid]);

json_response(['ok'=>true,'data'=>$stmt->fetchAll()]);
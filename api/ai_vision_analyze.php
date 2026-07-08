<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/AiVisionService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'data' => ['models' => AiVisionService::models()]]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$model = trim((string)($_POST['model'] ?? $_GET['model'] ?? 'ai_blip'));
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (is_array($body) && isset($body['model'])) {
    $model = trim((string)$body['model']);
}
$hash = '';
if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
    $hash = md5_file($_FILES['image']['tmp_name']) ?: md5((string)time());
    $fn = (string)($_FILES['image']['name'] ?? 'upload.jpg');
} else {
    $hash = md5($raw ?: (string)time());
    $fn = 'upload.jpg';
}

$result = (new AiVisionService())->analyze($model, $hash, $fn);
json_response(['ok' => !empty($result['ok']), 'data' => $result]);

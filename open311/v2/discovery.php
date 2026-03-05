<?php
require_once __DIR__ . '/../../util.php';

header('Content-Type: application/json; charset=utf-8');

$base = app_url('/open311/v2');

echo json_encode([
  [
    'changeset' => app_url('/open311/v2/requests.php'),
    'service_discovery' => app_url('/open311/v2/services.php'),
    'service_requests' => app_url('/open311/v2/requests.php'),
    'service_request' => app_url('/open311/v2/requests.php')
  ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

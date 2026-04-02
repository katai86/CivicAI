<?php
require_once __DIR__ . '/../../util.php';

header('Content-Type: application/json; charset=utf-8');

$base = app_url('/open311/v2');

$discovery = [
  'changeset' => app_url('/open311/v2/requests.php'),
  'service_discovery' => app_url('/open311/v2/services.php'),
  'service_definition' => app_url('/open311/v2/service_definition.php'),
  'service_requests' => app_url('/open311/v2/requests.php'),
  'service_request' => app_url('/open311/v2/requests.php'),
];
if (defined('APP_JURISDICTION_ID') && APP_JURISDICTION_ID !== '') {
  $discovery['jurisdiction_id'] = APP_JURISDICTION_ID;
}

echo json_encode([ $discovery ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

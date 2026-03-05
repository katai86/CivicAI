<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

header('Content-Type: application/json; charset=utf-8');

$serviceCode = safe_str($_GET['service_code'] ?? null, 64);
if (!$serviceCode) {
  http_response_code(400);
  echo json_encode(['error' => 'service_code required']);
  exit;
}

$name = $serviceCode;
$desc = '';
try {
  $stmt = db()->prepare("SELECT name, description FROM authority_contacts WHERE service_code=:code AND is_active=1 LIMIT 1");
  $stmt->execute([':code' => $serviceCode]);
  if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = (string)$row['name'];
    $desc = (string)($row['description'] ?? '');
  }
} catch (Throwable $e) {}

$attributes = [
  [
    'variable' => true,
    'code' => 'address_string',
    'datatype' => 'string',
    'required' => false,
    'description' => 'Cím'
  ],
  [
    'variable' => true,
    'code' => 'first_name',
    'datatype' => 'string',
    'required' => false,
    'description' => 'Keresztnév'
  ],
  [
    'variable' => true,
    'code' => 'last_name',
    'datatype' => 'string',
    'required' => false,
    'description' => 'Vezetéknév'
  ],
  [
    'variable' => true,
    'code' => 'phone',
    'datatype' => 'string',
    'required' => false,
    'description' => 'Telefon'
  ],
  [
    'variable' => true,
    'code' => 'email',
    'datatype' => 'string',
    'required' => false,
    'description' => 'E-mail'
  ],
  [
    'variable' => true,
    'code' => 'media_url',
    'datatype' => 'string',
    'required' => false,
    'description' => 'Csatolmány URL'
  ],
];

echo json_encode([
  [
    'service_code' => $serviceCode,
    'service_name' => $name,
    'description' => $desc,
    'metadata' => true,
    'type' => 'realtime',
    'attributes' => $attributes
  ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

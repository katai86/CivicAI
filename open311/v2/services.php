<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../util.php';

header('Content-Type: application/json; charset=utf-8');

$services = [];
try {
  $rows = db()->query("SELECT service_code, name, description FROM authority_contacts WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $services[] = [
      'service_code' => (string)$r['service_code'],
      'service_name' => (string)$r['name'],
      'description' => (string)($r['description'] ?? ''),
      'metadata' => false,
      'type' => 'realtime'
    ];
  }
} catch (Throwable $e) {
  // ignore, fallback below
}

if (!$services) {
  $fallback = [
    ['road', 'Úthiba / kátyú'],
    ['sidewalk', 'Járda / burkolat hiba'],
    ['lighting', 'Közvilágítás'],
    ['trash', 'Szemét / illegális'],
    ['green', 'Zöldterület / veszélyes fa'],
    ['traffic', 'Közlekedés / tábla'],
    ['idea', 'Ötlet / javaslat']
  ];
  foreach ($fallback as $f) {
    $services[] = [
      'service_code' => $f[0],
      'service_name' => $f[1],
      'description' => '',
      'metadata' => false,
      'type' => 'realtime'
    ];
  }
}

echo json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

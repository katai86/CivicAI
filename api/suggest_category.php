<?php
/**
 * Kategória javaslat a leírás alapján (szabályalapú).
 * Phase 4 – AI-assisted civic layer: „javaslat”, nem döntés.
 */
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$description = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  $body = json_decode($raw, true);
  $description = isset($body['description']) ? trim((string)$body['description']) : '';
} else {
  $description = isset($_GET['description']) ? trim((string)$_GET['description']) : '';
}

$description = mb_substr($description, 0, 5000);
if ($description === '') {
  json_response(['ok' => true, 'suggested_category' => null, 'label' => null]);
}

// Szabályalapú: kulcsszavak → kategória (magyar, kis- és nagybetűtelen)
$text = ' ' . mb_strtolower($description, 'UTF-8') . ' ';

$rules = [
  'road' => ['kátyú', 'bucka', 'út', 'útburkolat', 'asphalt', 'kereklyuk', 'út hiba', 'gödör', 'repedés út', 'út szél'],
  'sidewalk' => ['járda', 'burkolat', 'kövezet', 'járda hiba', 'lépcső', 'akadály', 'kerekesszekér', 'járda repedés'],
  'lighting' => ['lámpa', 'világítás', 'közvilágítás', 'kialudt', 'nem világít', 'sötét', 'világító', 'lámpaoszlop'],
  'trash' => ['szemét', 'hulladék', 'illegális', 'szemetelés', 'kidobott', 'konténer', 'szemetes', 'rongy'],
  'green' => ['fa', 'zöld', 'zöldterület', 'fű', 'bokor', 'veszélyes fa', 'levél', 'park', 'kert', 'növény'],
  'traffic' => ['közlekedés', 'tábla', 'jelző', 'stop', 'átjáró', 'kerékpár', 'parkolás', 'forgalom', 'zebra'],
  'idea' => ['ötlet', 'javaslat', 'javasolom', 'szerintem', 'kéne', 'lenne jó', 'projekt'],
];

$score = [];
foreach ($rules as $cat => $keywords) {
  $score[$cat] = 0;
  foreach ($keywords as $kw) {
    if (mb_strpos($text, $kw) !== false) {
      $score[$cat]++;
    }
  }
}

$best = null;
$bestScore = 0;
foreach ($score as $cat => $s) {
  if ($s > $bestScore) {
    $bestScore = $s;
    $best = $cat;
  }
}

$labels = [
  'road' => 'Úthiba / kátyú',
  'sidewalk' => 'Járda / burkolat hiba',
  'lighting' => 'Közvilágítás',
  'trash' => 'Szemét / illegális',
  'green' => 'Zöldterület / veszélyes fa',
  'traffic' => 'Közlekedés / tábla',
  'idea' => 'Ötlet / javaslat',
];

if ($best === null) {
  json_response(['ok' => true, 'suggested_category' => null, 'label' => null]);
}

json_response([
  'ok' => true,
  'suggested_category' => $best,
  'label' => $labels[$best] ?? $best,
]);

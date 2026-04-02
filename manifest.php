<?php
/**
 * PWA web app manifest (JSON). Dynamic start_url and name from config.
 */
require_once __DIR__ . '/util.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = rtrim(APP_BASE_URL, '/');
$start = $base . '/mobile/index.php';
$name = (function_exists('t') ? t('site.name') : null) ?: 'CivicAI';
$shortName = (function_exists('t') ? t('site.name') : null) ?: 'CivicAI';

$manifest = [
  'name' => $name,
  'short_name' => $shortName,
  'description' => 'Közérdekű bejelentések, problématerkép.',
  'start_url' => $start,
  'scope' => $base . '/',
  'display' => 'standalone',
  'orientation' => 'portrait-primary',
  'theme_color' => '#0f1721',
  'background_color' => '#0f1721',
  'icons' => [
    [
      'src' => $base . '/assets/fav_icon.png',
      'sizes' => '192x192',
      'type' => 'image/png',
      'purpose' => 'any maskable'
    ],
    [
      'src' => $base . '/assets/fav_icon.png',
      'sizes' => '384x384',
      'type' => 'image/png',
      'purpose' => 'any maskable'
    ]
  ]
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

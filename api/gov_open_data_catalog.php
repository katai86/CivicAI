<?php
/**
 * M13 – Unified catalog of gov-scoped JSON/Open-Data-style endpoints (discovery, same auth as dashboard).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role() ?: '';
$adminAid = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $a = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $adminAid = $a > 0 ? $a : null;
}

function gov_catalog_with_query(string $baseUrl, string $extraQuery): string {
  if ($extraQuery === '') {
    return $baseUrl;
  }
  $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';

  return $baseUrl . $sep . $extraQuery;
}

function gov_catalog_authority(string $url, ?int $adminAid): string {
  if ($adminAid === null || $adminAid <= 0) {
    return $url;
  }
  $sep = (strpos($url, '?') !== false) ? '&' : '?';

  return $url . $sep . 'authority_id=' . $adminAid;
}

$df = gmdate('Y-m-d', strtotime('-30 days'));
$dt = gmdate('Y-m-d');
$heatmapExampleQuery = 'type=issue_density&date_from=' . rawurlencode($df) . '&date_to=' . rawurlencode($dt);

$euOn = eu_open_data_module_enabled();

$resourceDefs = [
  ['id' => 'executive_summary', 'group' => 'executive', 'path' => 'api/executive_summary.php', 'query' => '', 'params' => []],
  ['id' => 'morning_brief', 'group' => 'executive', 'path' => 'api/morning_brief.php', 'query' => '', 'params' => []],
  ['id' => 'gov_insights', 'group' => 'executive', 'path' => 'api/gov_insights.php', 'query' => '', 'params' => []],
  ['id' => 'trends', 'group' => 'analytics', 'path' => 'api/trends.php', 'query' => '', 'params' => []],
  ['id' => 'category_stats', 'group' => 'analytics', 'path' => 'api/category_stats.php', 'query' => 'days=90', 'params' => [['name' => 'days', 'required' => false, 'example' => '90']]],
  ['id' => 'subcity_stats', 'group' => 'analytics', 'path' => 'api/subcity_stats.php', 'query' => 'days=90', 'params' => [['name' => 'days', 'required' => false, 'example' => '90']]],
  ['id' => 'priorities', 'group' => 'analytics', 'path' => 'api/priorities.php', 'query' => '', 'params' => []],
  ['id' => 'gov_statistics', 'group' => 'analytics', 'path' => 'api/gov_statistics.php', 'query' => '', 'params' => [['name' => 'date_from', 'required' => false, 'example' => $df], ['name' => 'date_to', 'required' => false, 'example' => $dt]]],
  ['id' => 'predictions', 'group' => 'analytics', 'path' => 'api/predictions.php', 'query' => '', 'params' => []],
  ['id' => 'sentiment_analysis', 'group' => 'analytics', 'path' => 'api/sentiment_analysis.php', 'query' => '', 'params' => []],
  ['id' => 'heatmap_data', 'group' => 'analytics', 'path' => 'api/heatmap_data.php', 'query' => $heatmapExampleQuery, 'params' => [['name' => 'type', 'required' => true, 'example' => 'issue_density'], ['name' => 'date_from', 'required' => true, 'example' => $df], ['name' => 'date_to', 'required' => true, 'example' => $dt]]],
  ['id' => 'analytics_json', 'group' => 'analytics', 'path' => 'api/analytics.php', 'query' => 'format=json', 'params' => [['name' => 'format', 'required' => true, 'example' => 'json']]],
  ['id' => 'analytics_csv', 'group' => 'analytics', 'path' => 'api/analytics.php', 'query' => 'format=csv', 'params' => [['name' => 'format', 'required' => true, 'example' => 'csv']]],
  ['id' => 'green_dashboard', 'group' => 'green', 'path' => 'api/green_dashboard.php', 'query' => '', 'params' => []],
  ['id' => 'gov_esg_snapshot', 'group' => 'green', 'path' => 'api/gov_esg_snapshot.php', 'query' => '', 'params' => []],
  ['id' => 'esg_metrics', 'group' => 'green', 'path' => 'api/esg_metrics.php', 'query' => '', 'params' => []],
  ['id' => 'esg_export', 'group' => 'green', 'path' => 'api/esg_export.php', 'query' => 'format=json&year=' . (int)gmdate('Y'), 'params' => [['name' => 'year', 'required' => false, 'example' => (string)gmdate('Y')], ['name' => 'format', 'required' => false, 'example' => 'json']]],
  ['id' => 'green_metrics', 'group' => 'green', 'path' => 'api/green_metrics.php', 'query' => '', 'params' => []],
  ['id' => 'eu_air_quality', 'group' => 'eu', 'path' => 'api/eu_air_quality.php', 'query' => '', 'params' => [], 'eu_module' => true],
  ['id' => 'eu_climate', 'group' => 'eu', 'path' => 'api/eu_climate_context.php', 'query' => '', 'params' => [], 'eu_module' => true],
  ['id' => 'eu_country', 'group' => 'eu', 'path' => 'api/eu_country_context.php', 'query' => '', 'params' => [], 'eu_module' => true],
  ['id' => 'eu_green_overlay', 'group' => 'eu', 'path' => 'api/eu_green_overlay.php', 'query' => 'layer_type=ndvi', 'params' => [['name' => 'layer_type', 'required' => false, 'example' => 'ndvi']], 'eu_module' => true],
  ['id' => 'eu_eea_inspire', 'group' => 'eu', 'path' => 'api/eu_eea_inspire_context.php', 'query' => '', 'params' => [], 'eu_module' => true],
  ['id' => 'citybrain_dashboard', 'group' => 'citybrain', 'path' => 'api/citybrain_dashboard.php', 'query' => '', 'params' => []],
  ['id' => 'city_health', 'group' => 'citybrain', 'path' => 'api/city_health.php', 'query' => '', 'params' => []],
];

$groupOrder = ['executive', 'analytics', 'green', 'eu', 'citybrain'];
$groupLabels = [
  'executive' => t('gov.catalog_group_executive'),
  'analytics' => t('gov.catalog_group_analytics'),
  'green' => t('gov.catalog_group_green'),
  'eu' => t('gov.catalog_group_eu'),
  'citybrain' => t('gov.catalog_group_citybrain'),
];

$groups = [];
foreach ($groupOrder as $gid) {
  $groups[$gid] = [
    'id' => $gid,
    'label' => $groupLabels[$gid] ?? $gid,
    'resources' => [],
  ];
}

foreach ($resourceDefs as $def) {
  if (!empty($def['eu_module']) && !$euOn) {
    continue;
  }
  $path = $def['path'];
  $base = app_url('/' . $path);
  $base = gov_catalog_with_query($base, (string)($def['query'] ?? ''));
  $href = gov_catalog_authority($base, $adminAid);
  $id = (string)($def['id'] ?? '');
  $gid = (string)($def['group'] ?? 'analytics');
  if (!isset($groups[$gid])) {
    $gid = 'analytics';
  }
  $groups[$gid]['resources'][] = [
    'id' => $id,
    'method' => 'GET',
    'path' => '/' . $path,
    'href' => $href,
    'title' => t('gov.catalog.res.' . $id),
    'query_params' => $def['params'] ?? [],
    'eu_open_data' => !empty($def['eu_module']),
  ];
}

$outGroups = [];
foreach ($groupOrder as $gid) {
  if (!empty($groups[$gid]['resources'])) {
    $outGroups[] = $groups[$gid];
  }
}

json_response([
  'ok' => true,
  'data' => [
    'catalog_version' => '1.0',
    'as_of' => gmdate('c'),
    'locale' => current_lang(),
    'scope' => [
      'role' => $role,
      'authority_id_example' => $adminAid,
    ],
    'notes' => [
      'auth' => t('gov.catalog_note_auth'),
      'admin_authority' => t('gov.catalog_note_admin_authority'),
    ],
    'groups' => $outGroups,
  ],
]);

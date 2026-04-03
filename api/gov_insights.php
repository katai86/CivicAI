<?php
/**
 * GET – rule-based structured insights (no LLM). Same gov/admin scope as morning_brief / priorities.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExecutiveSummaryService.php';
require_once __DIR__ . '/../services/PrioritizationEngine.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role() ?: '';
$uid = current_user_id() ? (int)current_user_id() : 0;
$adminAid = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $a = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $adminAid = $a > 0 ? $a : null;
}

$pdo = db();

try {
  [$reportWhere, $reportParams, $treeScopeIds, $healthAuthorityId] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

  $cacheKey = gov_api_cache_scope_key('insights', $role, $uid, $adminAid);
  $cacheHit = gov_api_cache_get($cacheKey);
  if ($cacheHit !== null) {
    header('X-Gov-Api-Cache: HIT');
    json_response($cacheHit);
  }

  $created24 = 0;
  $resolved24 = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE ($reportWhere) AND r.created_at >= (NOW() - INTERVAL 24 HOUR)");
    $st->execute($reportParams);
    $created24 = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $created24 = 0;
  }
  try {
    $st = $pdo->prepare("
    SELECT COUNT(DISTINCT l.report_id) FROM report_status_log l
    INNER JOIN reports r ON r.id = l.report_id
    WHERE ($reportWhere) AND l.new_status IN ('solved','closed')
      AND l.changed_at >= (NOW() - INTERVAL 24 HOUR)
  ");
    $st->execute($reportParams);
    $resolved24 = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $resolved24 = 0;
  }

  $engine = new PrioritizationEngine();
  $prio = $engine->compute($pdo, $reportWhere, $reportParams, $healthAuthorityId);
  $openBacklog = (int)($prio['totals']['open_reports'] ?? 0);
  $topCatRow = null;
  foreach ($prio['by_category'] ?? [] as $row) {
    if (is_array($row) && (int)($row['open_count'] ?? 0) > 0) {
      $topCatRow = $row;
      break;
    }
  }

  $snap = gov_compute_esg_snapshot($pdo, $treeScopeIds, $reportWhere, $reportParams);
  $treesWater = (int)($snap['environment']['trees_needing_water'] ?? 0);
  $treesDanger = (int)($snap['environment']['trees_dangerous'] ?? 0);

  $categoryHuman = static function (string $cat): string {
    $map = [
      'road' => t('cat.road_desc'),
      'sidewalk' => t('cat.sidewalk_desc'),
      'lighting' => t('cat.lighting_desc'),
      'trash' => t('cat.trash_desc'),
      'green' => t('cat.green_desc'),
      'traffic' => t('cat.traffic_desc'),
      'idea' => t('cat.idea_desc'),
      'civil_event' => t('cat.civil_event_desc'),
    ];
    return $map[$cat] ?? $cat;
  };

  $raw = [];

  if ($openBacklog === 0) {
    $raw[] = ['sev' => 2, 'severity' => 'success', 'text' => t('gov.insight_inbox_clear')];
  } else {
    if ($openBacklog >= 100) {
      $raw[] = ['sev' => 0, 'severity' => 'warning', 'text' => str_replace('%n', (string)$openBacklog, t('gov.insight_backlog_critical'))];
    } elseif ($openBacklog >= 40) {
      $raw[] = ['sev' => 0, 'severity' => 'warning', 'text' => str_replace('%n', (string)$openBacklog, t('gov.insight_backlog_elevated'))];
    }
  }

  if ($created24 >= 15) {
    $raw[] = ['sev' => 0, 'severity' => 'warning', 'text' => str_replace('%n', (string)$created24, t('gov.insight_intake_spike'))];
  } elseif ($created24 >= 6) {
    $raw[] = ['sev' => 1, 'severity' => 'info', 'text' => str_replace('%n', (string)$created24, t('gov.insight_intake_busy'))];
  }

  if ($openBacklog > 0 && $resolved24 > 0 && $resolved24 >= max(3, $created24 * 2)) {
    $raw[] = ['sev' => 2, 'severity' => 'success', 'text' => str_replace('%n', (string)$resolved24, t('gov.insight_closure_strong'))];
  }

  if ($openBacklog > 0 && $created24 === 0 && $resolved24 === 0) {
    $raw[] = ['sev' => 1, 'severity' => 'info', 'text' => t('gov.insight_quiet_day')];
  }

  if ($topCatRow && $openBacklog > 0) {
    $c = (string)($topCatRow['category'] ?? '');
    $cnt = (int)($topCatRow['open_count'] ?? 0);
    $pct = (int)round(100 * $cnt / max(1, $openBacklog));
    if ($pct >= 50 && $c !== '') {
      $raw[] = ['sev' => 0, 'severity' => 'warning', 'text' => str_replace(['%c', '%p', '%n'], [$categoryHuman($c), (string)$pct, (string)$cnt], t('gov.insight_category_skew'))];
    } elseif ($pct >= 35 && $c !== '') {
      $raw[] = ['sev' => 1, 'severity' => 'info', 'text' => str_replace(['%c', '%p'], [$categoryHuman($c), (string)$pct], t('gov.insight_category_tilt'))];
    }
  }

  if ($treesDanger > 0) {
    $raw[] = ['sev' => 0, 'severity' => 'warning', 'text' => str_replace('%n', (string)$treesDanger, t('gov.insight_trees_danger'))];
  }
  if ($treesWater >= 8) {
    $raw[] = ['sev' => 0, 'severity' => 'warning', 'text' => str_replace('%n', (string)$treesWater, t('gov.insight_trees_water_many'))];
  } elseif ($treesWater >= 3) {
    $raw[] = ['sev' => 1, 'severity' => 'info', 'text' => str_replace('%n', (string)$treesWater, t('gov.insight_trees_water_some'))];
  }

  if ($raw === []) {
    $raw[] = ['sev' => 1, 'severity' => 'info', 'text' => t('gov.insight_default_steady')];
  }

  usort($raw, static fn ($a, $b) => ($a['sev'] <=> $b['sev']));
  $bullets = [];
  foreach (array_slice($raw, 0, 8) as $row) {
    $bullets[] = [
      'severity' => $row['severity'],
      'text' => $row['text'],
    ];
  }

  $out = [
    'ok' => true,
    'data' => [
      'as_of' => gmdate('c'),
      'source' => 'rules',
      'bullets' => $bullets,
      'footer' => t('gov.insights_rule_footer'),
    ],
  ];
  gov_api_cache_set($cacheKey, $out);
  header('X-Gov-Api-Cache: MISS');
  json_response($out);
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('gov_insights: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  }
  json_response(['ok' => false, 'error' => t('common.error_load')], 500);
}

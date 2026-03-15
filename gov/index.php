<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: '';

if ($uid <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if (!$isAdmin && !in_array($role, ['govuser'], true)) {
  header('Location: ' . app_url('/'));
  exit;
}

$ok = '';
$err = '';

// Load authorities for user (or all for admin)
$authorities = [];
if ($isAdmin) {
  try {
    $rows = db()->query("SELECT * FROM authorities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Dedupe by id (egy hatóság csak egyszer)
    $byId = [];
    foreach ($rows as $a) {
      $id = (int)($a['id'] ?? 0);
      if ($id && !isset($byId[$id])) $byId[$id] = $a;
    }
    $authorities = array_values($byId);
  } catch (Throwable $e) {
    $authorities = [];
  }
} else {
  try {
    $stmt = db()->prepare("
      SELECT a.*
      FROM authority_users au
      JOIN authorities a ON a.id = au.authority_id
      WHERE au.user_id = :uid
      ORDER BY a.name ASC
    ");
    $stmt->execute([':uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byId = [];
    foreach ($rows as $a) {
      $id = (int)($a['id'] ?? 0);
      if ($id && !isset($byId[$id])) $byId[$id] = $a;
    }
    $authorities = array_values($byId);
  } catch (Throwable $e) {
    $authorities = [];
  }
}

// Gov user: csak egy "saját" hatóság jelenjen meg (pl. Orosháza felhasználónak csak Orosháza)
if (!$isAdmin && !empty($authorities)) {
  $authorities = [ $authorities[0] ];
}

$authorityIds = array_map(fn($a) => (int)$a['id'], $authorities);
$authorityCities = array_values(array_filter(array_unique(array_map(function($a) {
  return trim((string)($a['city'] ?? ''));
}, $authorities))));

$statusFilter = isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : '';
$allowedStatuses = ['pending','approved','rejected','new','needs_info','forwarded','waiting_reply','in_progress','solved','closed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = '';
}

// Gov user: ha van hatósága, láthatja a bejelentések listáját (read-only); admin mindig
$showReportList = $isAdmin || !empty($authorityIds);

// Status update: csak admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_status') {
  if (!$isAdmin) {
    $err = t('common.error_no_permission');
  } else {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $new = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
  $note = safe_str($_POST['note'] ?? null, 255);
  $allowed = ['pending','approved','rejected','new','needs_info','forwarded','waiting_reply','in_progress','solved','closed'];

  if ($id <= 0 || !in_array($new, $allowed, true)) {
    $err = t('common.error_invalid_data');
  } else {
    try {
      $pdo = db();
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("SELECT * FROM reports WHERE id=:id LIMIT 1 FOR UPDATE");
      $stmt->execute([':id' => $id]);
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$r) {
        $pdo->rollBack();
        $err = t('gov.report_not_found');
      } else {
        $aid = (int)($r['authority_id'] ?? 0);
        $rCity = trim((string)($r['city'] ?? ''));
        $allowed = $isAdmin
          || in_array($aid, $authorityIds, true)
          || ($aid <= 0 && $rCity !== '' && in_array($rCity, $authorityCities, true));
        if (!$allowed) {
          $pdo->rollBack();
          $err = t('common.error_no_permission');
        } else {
          $old = (string)$r['status'];
          if ($old !== $new) {
            $pdo->prepare("UPDATE reports SET status=:st WHERE id=:id")->execute([':st'=>$new, ':id'=>$id]);
            $pdo->prepare("
              INSERT INTO report_status_log (report_id, old_status, new_status, note, changed_by)
              VALUES (:rid, :old, :new, :note, :by)
            ")->execute([
              ':rid' => $id,
              ':old' => $old,
              ':new' => $new,
              ':note' => $note,
              ':by' => $isAdmin ? 'admin' : 'govuser'
            ]);
          }
          if (!$isAdmin && $aid <= 0 && count($authorityIds) > 0) {
            $pdo->prepare("UPDATE reports SET authority_id = ? WHERE id = ?")->execute([$authorityIds[0], $id]);
          }
          $pdo->commit();

          // XP + email best-effort
          $uidReport = (int)($r['user_id'] ?? 0);
          if ($uidReport > 0) {
            if ($new === 'approved') add_user_xp($uidReport, 20, 'status_approved', (int)$r['id']);
            if ($new === 'solved') add_user_xp($uidReport, 50, 'status_solved', (int)$r['id']);
            if ($new === 'rejected') {
              $noteStr = $note ? (function_exists('mb_strtolower') ? mb_strtolower($note) : strtolower($note)) : '';
              if ($noteStr && (strpos($noteStr, 'duplik') !== false)) {
                add_user_xp($uidReport, -5, 'status_duplicate', (int)$r['id']);
              }
            }
          }

          $to = (string)($r['reporter_email'] ?? '');
          $notifyEnabled = (int)($r['notify_enabled'] ?? 0);
          $token = (string)($r['notify_token'] ?? '');
          if ($notifyEnabled === 1 && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) && $token !== '') {
            $labels = [
              'pending' => 'Ellenőrzés alatt',
              'approved' => 'Publikálva',
              'rejected' => 'Elutasítva',
              'new' => 'Új',
              'needs_info' => 'Kiegészítésre vár',
              'forwarded' => 'Továbbítva',
              'waiting_reply' => 'Válaszra vár',
              'in_progress' => 'Folyamatban',
              'solved' => 'Megoldva',
              'closed' => 'Lezárva',
            ];
            $case = case_number((int)$r['id'], (string)$r['created_at']);
            $trackUrl = app_url('/case.php?token=' . rawurlencode($token));
            $unsubscribeUrl = app_url('/api/notify_unsubscribe.php?token=' . rawurlencode($token));
            [$subject, $bodyText] = build_status_email(
              $case,
              (int)$r['id'],
              $old,
              $new,
              $labels,
              $r['title'] ? (string)$r['title'] : null,
              $r['address_approx'] ? (string)$r['address_approx'] : null,
              $note,
              $trackUrl,
              $unsubscribeUrl
            );
            send_mail($to, $subject, $bodyText);
          }

          $ok = t('gov.status_updated');
          $redirectFilter = isset($_POST['status_filter']) ? trim((string)$_POST['status_filter']) : '';
          if ($redirectFilter !== '' && in_array($redirectFilter, $allowedStatuses, true)) {
            header('Location: ' . app_url('/gov/index.php?status_filter=' . rawurlencode($redirectFilter)));
            exit;
          }
        }
      }
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      $err = t('common.error_save_failed');
    }
  }
  }
}

// M3 Ideation: ötlet státusz módosítás (admin vagy gov user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'idea_set_status') {
  if (!$isAdmin && $role !== 'govuser') {
    $err = t('common.error_no_permission');
  } else {
    $ideaId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $ideaStatus = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
    $ideaAllowed = ['submitted', 'under_review', 'planned', 'in_progress', 'completed'];
    if ($ideaId <= 0 || !in_array($ideaStatus, $ideaAllowed, true)) {
      $err = t('common.error_invalid_data');
    } else {
      try {
        $stmt = db()->prepare("UPDATE ideas SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$ideaStatus, $ideaId]);
        if ($stmt->rowCount() > 0) {
          $ok = t('gov.status_updated');
        }
      } catch (Throwable $e) {
        $err = t('common.error_save_failed');
      }
    }
  }
}

$ideasList = [];
if ($isAdmin || !empty($authorityIds)) {
  try {
    $sql = "
      SELECT i.id, i.user_id, i.title, i.description, i.lat, i.lng, i.status, i.created_at, i.authority_id,
             u.display_name AS author_name,
             (SELECT COUNT(*) FROM idea_votes v WHERE v.idea_id = i.id) AS vote_count
      FROM ideas i
      LEFT JOIN users u ON u.id = i.user_id
    ";
    $params = [];
    if (!$isAdmin && !empty($authorityIds)) {
      $placeholders = implode(',', array_fill(0, count($authorityIds), '?'));
      $sql .= " WHERE i.authority_id IN ($placeholders)";
      $params = array_values($authorityIds);
    }
    $sql .= " ORDER BY i.created_at DESC LIMIT 200";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $ideasList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $ideasList = [];
  }
}

$reports = [];
$stats = [
  'reports_1d' => 0,
  'reports_7d' => 0,
  'reports_total' => 0,
  'by_status' => [],
  'by_category' => [],
  'environment' => [],
  'social' => [],
  'governance' => [],
];

// Gov: hatósághoz tartozó VAGY városnév alapján (authority_id még nincs beállítva)
$govWhere = 'r.authority_id IN (' . implode(',', array_fill(0, count($authorityIds), '?')) . ')';
$govParams = array_values($authorityIds);
if (!empty($authorityCities)) {
  $govWhere .= ' OR (r.authority_id IS NULL AND r.city IN (' . implode(',', array_fill(0, count($authorityCities), '?')) . '))';
  $govParams = array_merge($govParams, $authorityCities);
}
$baseWhere = $isAdmin ? '1=1' : $govWhere;
$baseParams = $isAdmin ? [] : $govParams;

$where = $baseWhere;
$params = $baseParams;
if ($statusFilter !== '') {
  $where .= ' AND r.status = ?';
  $params[] = $statusFilter;
}

if ($isAdmin || $authorityIds) {
  $pdo = db();
  // Lista csak adminnak (gov user csak statisztikát lát)
  if ($showReportList) {
    $listWhere = $where;
    $listParams = $params;
    $stmt = $pdo->prepare("
      SELECT r.id, r.category, r.title, r.description, r.status, r.created_at,
             r.address_approx, r.city, r.authority_id,
             u.display_name AS reporter_display_name, u.level AS reporter_level, u.profile_public AS reporter_profile_public
      FROM reports r
      LEFT JOIN users u ON u.id = r.user_id
      WHERE $listWhere
      ORDER BY r.created_at DESC
      LIMIT 200
    ");
    $stmt->execute($listParams);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  // Statisztika: ugyanaz a szűrő (városhoz tartozó), mind adminnak mind gov usernek
  try {
    $q0 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere");
    $q0->execute($baseParams);
    $stats['reports_total'] = (int)$q0->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $q1 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= (CURDATE())");
    $q1->execute($baseParams);
    $stats['reports_1d'] = (int)$q1->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $q7 = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.created_at >= (NOW() - INTERVAL 7 DAY)");
    $q7->execute($baseParams);
    $stats['reports_7d'] = (int)$q7->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $qs = $pdo->prepare("SELECT r.status, COUNT(*) AS cnt FROM reports r WHERE $baseWhere GROUP BY r.status");
    $qs->execute($baseParams);
    foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $stats['by_status'][(string)$row['status']] = (int)$row['cnt'];
    }
  } catch (Throwable $e) { /* ignore */ }
  try {
    $qc = $pdo->prepare("SELECT r.category, COUNT(*) AS cnt FROM reports r WHERE $baseWhere GROUP BY r.category");
    $qc->execute($baseParams);
    foreach ($qc->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $stats['by_category'][(string)$row['category']] = (int)$row['cnt'];
    }
  } catch (Throwable $e) { /* ignore */ }
  // Environment stats (fák + zöld bejelentések)
  $env = [
    'trees_total' => 0,
    'trees_needing_inspection' => 0,
    'trees_needing_water' => 0,
    'trees_dangerous' => 0,
    'green_reports' => 0,
  ];
  try {
    $env['trees_total'] = (int)db()->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1")->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $env['trees_needing_inspection'] = (int)db()->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND (last_inspection IS NULL OR last_inspection < DATE_SUB(CURDATE(), INTERVAL 365 DAY))")->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $env['trees_needing_water'] = (int)db()->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND (last_watered IS NULL OR last_watered < DATE_SUB(CURDATE(), INTERVAL 7 DAY))")->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $env['trees_dangerous'] = (int)db()->query("SELECT COUNT(*) FROM trees WHERE public_visible = 1 AND risk_level = 'high'")->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $qg = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.category = 'green'");
    $qg->execute($baseParams);
    $env['green_reports'] = (int)$qg->fetchColumn();
  } catch (Throwable $e) {}
  $stats['environment'] = $env;

  // Social stats – aktív polgárok, örökbefogadók, zöld események, öntözések
  $soc = [
    'active_citizens_30d' => 0,
    'tree_adopters' => 0,
    'green_events_active' => 0,
    'watering_actions_30d' => 0,
  ];
  try {
    $qsoc = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $baseWhere AND r.user_id IS NOT NULL AND r.created_at >= (NOW() - INTERVAL 30 DAY)");
    $qsoc->execute($baseParams);
    $soc['active_citizens_30d'] = (int)$qsoc->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $soc['tree_adopters'] = (int)db()->query("SELECT COUNT(DISTINCT user_id) FROM tree_adoptions WHERE status = 'active'")->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $soc['green_events_active'] = (int)db()->query("SELECT COUNT(*) FROM civil_events WHERE is_active = 1 AND event_type = 'green_action' AND end_date >= CURDATE()")->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $soc['watering_actions_30d'] = (int)db()->query("SELECT COUNT(*) FROM tree_watering_logs WHERE created_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
  } catch (Throwable $e) {}
  $stats['social'] = $soc;

  // Governance stats – nyitott ügyek, megoldott 30 napban, átlagos megoldási idő (nap)
  $gov = [
    'reports_total' => $stats['reports_total'],
    'reports_open' => 0,
    'reports_solved_30d' => 0,
    'avg_resolution_days' => null,
  ];
  try {
    $qo = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $baseWhere AND r.status NOT IN ('solved','closed','rejected')");
    $qo->execute($baseParams);
    $gov['reports_open'] = (int)$qo->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $qs30 = $pdo->prepare("
      SELECT COUNT(*) FROM reports r
      JOIN report_status_log l ON l.report_id = r.id AND l.new_status IN ('solved','closed')
      WHERE $baseWhere AND l.changed_at >= (NOW() - INTERVAL 30 DAY)
    ");
    $qs30->execute($baseParams);
    $gov['reports_solved_30d'] = (int)$qs30->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $qa = $pdo->prepare("
      SELECT AVG(DATEDIFF(l.changed_at, r.created_at)) AS avg_days
      FROM reports r
      JOIN report_status_log l ON l.report_id = r.id AND l.new_status IN ('solved','closed')
      WHERE $baseWhere
    ");
    $qa->execute($baseParams);
    $avg = $qa->fetchColumn();
    if ($avg !== false && $avg !== null) {
      $gov['avg_resolution_days'] = (float)$avg;
    }
  } catch (Throwable $e) {}
  $stats['governance'] = $gov;
}

// Export (JSON / CSV) – még HTML előtt
if (isset($_GET['export']) && in_array($_GET['export'], ['json','csv'], true)) {
  $format = $_GET['export'];
  if ($format === 'json') {
    json_response(['ok' => true, 'data' => $stats]);
  } elseif ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gov_stats.csv"');
    $outCsv = fopen('php://output', 'w');
    if ($outCsv) {
      fputcsv($outCsv, ['section', 'key', 'value']);
      foreach (['environment','social','governance'] as $section) {
        if (!isset($stats[$section]) || !is_array($stats[$section])) continue;
        foreach ($stats[$section] as $k => $v) {
          fputcsv($outCsv, [$section, $k, (string)$v]);
        }
      }
      fclose($outCsv);
    }
    exit;
  }
}

if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . app_url('/gov/index.php'));
  exit;
}
$currentLang = current_lang();
$statusLabels = [
  'new' => t('status.new'), 'pending' => t('status.pending'), 'approved' => t('status.approved'), 'rejected' => t('status.rejected'),
  'needs_info' => t('status.needs_info'), 'forwarded' => t('status.forwarded'), 'waiting_reply' => t('status.waiting_reply'),
  'in_progress' => t('status.in_progress'), 'solved' => t('status.solved'), 'closed' => t('status.closed'),
];
$categoryLabels = [
  'road' => t('cat.road_desc'), 'sidewalk' => t('cat.sidewalk_desc'), 'lighting' => t('cat.lighting_desc'), 'trash' => t('cat.trash_desc'),
  'green' => t('cat.green_desc'), 'traffic' => t('cat.traffic_desc'), 'idea' => t('cat.idea_desc'), 'civil_event' => t('cat.civil_event_desc'),
];

$statusOrder = ['new','approved','in_progress','solved','rejected','needs_info','forwarded','waiting_reply','closed','pending'];
$statusColors = [ 'new'=>'#0d6efd', 'approved'=>'#198754', 'in_progress'=>'#ffc107', 'solved'=>'#20c997', 'rejected'=>'#dc3545', 'needs_info'=>'#6f42c1', 'forwarded'=>'#fd7e14', 'waiting_reply'=>'#0dcaf0', 'closed'=>'#6c757d', 'pending'=>'#adb5bd' ];
$catColors = ['#e74c3c','#3498db','#f1c40f','#34495e','#27ae60','#9b59b6','#ff7a00','#0ea5e9'];
$maxStatus = 1;
if (!empty($stats['by_status'])) {
    $v = array_values($stats['by_status']);
    $maxStatus = $v ? max(1, max($v)) : 1;
}
$maxCategory = 1;
if (!empty($stats['by_category'])) {
    $v = array_values($stats['by_category']);
    $maxCategory = $v ? max(1, max($v)) : 1;
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<?php
$govUid = current_user_id();
$govAiUiEnabled = $isAdmin ? true : user_module_enabled($govUid, 'mistral');
$govFmsUiEnabled = $isAdmin ? true : user_module_enabled($govUid, 'fms');
?>
<!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= h(t('site.name')) ?> – <?= h(t('gov.title')) ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/dashboard/dist/css/adminlte.min.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/admin.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper">
  <nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
            <i class="bi bi-list"></i>
          </a>
        </li>
        <li class="nav-item d-none d-md-block">
          <span class="nav-link fw-semibold"><?= h(t('gov.title')) ?></span>
        </li>
      </ul>
      <ul class="navbar-nav ms-auto align-items-center">
        <li class="nav-item">
          <button type="button" id="themeToggle" class="btn btn-link nav-link py-2" aria-label="<?= h(t('theme.aria')) ?>" title="<?= h(t('theme.dark')) ?>" data-title-light="<?= h(t('theme.light')) ?>" data-title-dark="<?= h(t('theme.dark')) ?>">
            <span class="theme-icon theme-sun" aria-hidden="true"><i class="bi bi-sun-fill"></i></span>
            <span class="theme-icon theme-moon" aria-hidden="true"><i class="bi bi-moon-fill"></i></span>
          </button>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="govLangDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= h(strtoupper($currentLang)) ?></a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="govLangDropdown">
            <?php foreach (LANG_ALLOWED as $code): ?>
              <li><a class="dropdown-item<?= $code === $currentLang ? ' active' : '' ?>" href="<?= h(app_url('/gov/index.php?lang=' . $code)) ?>"><?= h(strtoupper($code)) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?= h(app_url('/')) ?>"><?= h(t('nav.map')) ?></a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?= h(app_url('/user/logout.php?from_gov=1')) ?>"><?= h(t('nav.logout')) ?></a>
        </li>
      </ul>
    </div>
  </nav>

  <aside class="app-sidebar bg-body-secondary shadow">
    <div class="sidebar-brand">
      <a href="<?= h(app_url('/')) ?>" class="brand-link">
        <span class="brand-text fw-light">CivicAI</span>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column">
          <li class="nav-item">
            <a href="#" class="nav-link tab active" data-tab="dashboard">
              <i class="nav-icon bi bi-speedometer2"></i>
              <p><?= h(t('gov.tab_dashboard')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="reports">
              <i class="nav-icon bi bi-flag-fill"></i>
              <p><?= h(t('gov.tab_reports')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="ideas">
              <i class="nav-icon bi bi-lightbulb"></i>
              <p><?= h(t('legend.ideas_section') ?: 'Ötletek') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="trees">
              <i class="nav-icon bi bi-tree-fill"></i>
              <p><?= h(t('gov.tab_trees')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="analytics">
              <i class="nav-icon bi bi-graph-up"></i>
              <p><?= h(t('gov.tab_analytics')) ?></p>
            </a>
          </li>
          <?php if (!$isAdmin): ?>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="modules">
              <i class="nav-icon bi bi-sliders"></i>
              <p><?= h(t('gov.tab_modules')) ?></p>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </aside>

  <main class="app-main">
    <div class="app-content">
      <div class="container-fluid">
        <?php if($ok): ?><div class="alert alert-success py-2"><?= h($ok) ?></div><?php endif; ?>
        <?php if($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

        <?php if(!$isAdmin && !$authorityIds): ?>
          <div class="alert alert-warning py-2"><?= h(t('gov.no_authority')) ?></div>
        <?php else: ?>

        <div class="admin-tab-body" id="tab-dashboard">
          <!-- M9 Dashboard UI panelek: 5 panel + magyarázat -->
          <p class="text-secondary small mb-2"><?= h(t('gov.panels_intro')) ?></p>
          <div class="row g-2 mb-3">
            <div class="col-6 col-md"><div class="card border-primary"><div class="card-body py-2"><h6 class="card-title small mb-0"><?= h(t('gov.panel_city_health')) ?></h6><br><p class="text-secondary small mb-0"><?= h(t('gov.stats_title')) ?></p></div></div></div>
            <div class="col-6 col-md"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0"><?= h(t('gov.panel_engagement')) ?></h6><br><p class="text-secondary small mb-0"><?= h(t('gov.analytics_title')) ?></p></div></div></div>
            <div class="col-6 col-md"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0"><?= h(t('gov.panel_urban_issues')) ?></h6><br><p class="text-secondary small mb-0"><?= h(t('gov.reports_list')) ?></p></div></div></div>
            <div class="col-6 col-md"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0"><?= h(t('gov.panel_tree_registry')) ?></h6><br><p class="text-secondary small mb-0"><?= (int)($stats['environment']['trees_total'] ?? 0) ?> <?= h(t('gov.esg_trees_total')) ?></p></div></div></div>
            <div class="col-6 col-md"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0"><?= h(t('gov.panel_esg')) ?></h6><br><p class="text-secondary small mb-0"><?= h(t('gov.esg_dashboard_title')) ?></p></div></div></div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title mb-2"><?= h(t('gov.panel_city_health')) ?> – <?= h(t('gov.stats_title')) ?></h6><br>
                  <div class="row g-2">
                    <div class="col-md-2"><div class="d-flex flex-column"><span class="text-secondary small"><?= h(t('gov.stat_today')) ?></span><span class="fw-bold fs-5"><?= (int)$stats['reports_1d'] ?></span></div></div>
                    <div class="col-md-2"><div class="d-flex flex-column"><span class="text-secondary small"><?= h(t('gov.stat_7d')) ?></span><span class="fw-bold fs-5"><?= (int)$stats['reports_7d'] ?></span></div></div>
                    <div class="col-md-2"><div class="d-flex flex-column"><span class="text-secondary small"><?= h(t('gov.stat_total')) ?></span><span class="fw-bold fs-5"><?= (int)$stats['reports_total'] ?></span></div></div>
                    <div class="col-md-6"><div class="text-secondary small"><?= h(t('gov.authorities')) ?>: <b><?= h(implode(', ', array_map(fn($a)=>$a['name'], $authorities))) ?></b></div></div>
                  </div>
                  <div class="row g-3 mt-2">
                    <div class="col-md-6">
                      <h6 class="text-secondary small mb-2"><?= h(t('gov.by_status')) ?></h6>
                      <div class="admin-chart">
                        <?php
                        $statusItems = [];
                        foreach ($statusOrder as $st) {
                          if (!empty($stats['by_status'][$st])) {
                            $statusItems[] = ['k' => $st, 'cnt' => (int)$stats['by_status'][$st], 'label' => $statusLabels[$st] ?? $st, 'color' => $statusColors[$st] ?? '#6c757d'];
                          }
                        }
                        if ($statusItems): ?>
                          <?php foreach ($statusItems as $x): ?>
                          <div class="admin-chart-bar">
                            <span class="label"><?= h($x['label']) ?></span>
                            <div class="bar-wrap"><div class="bar" style="width:<?= (int)round(100 * $x['cnt'] / $maxStatus) ?>%;background:<?= h($x['color']) ?>"></div></div>
                            <span class="val"><?= $x['cnt'] ?></span>
                          </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <div class="text-secondary small"><?= h(t('gov.no_data')) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <h6 class="text-secondary small mb-2"><?= h(t('gov.by_category')) ?></h6>
                      <div class="admin-chart">
                        <?php
                        $catItems = $stats['by_category'];
                        arsort($catItems);
                        $i = 0;
                        if (!empty($catItems)): ?>
                          <?php foreach ($catItems as $cat => $cnt): $cnt = (int)$cnt; $color = $catColors[$i % count($catColors)]; $i++; ?>
                          <div class="admin-chart-bar">
                            <span class="label"><?= h($categoryLabels[$cat] ?? $cat) ?></span>
                            <div class="bar-wrap"><div class="bar" style="width:<?= (int)round(100 * $cnt / $maxCategory) ?>%;background:<?= h($color) ?>"></div></div>
                            <span class="val"><?= $cnt ?></span>
                          </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <div class="text-secondary small"><?= h(t('gov.no_data')) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <p class="text-secondary small mt-2 mb-0">
                    <?= h(t('gov.integration_status')) ?>:
                    <?= ($govFmsUiEnabled && fms_enabled()) ? h(t('gov.fms_configured')) : h(t('gov.fms_not_configured')) ?>
                    |
                    <?= ($govAiUiEnabled && ai_configured()) ? h(t('gov.ai_configured')) : h(t('gov.ai_not_configured')) ?>
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title mb-1"><?= h(t('gov.analytics_title')) ?></h6><br>
                  <p class="text-secondary small mb-3"><?= h(t('gov.analytics_desc')) ?></p>
                  <a href="<?= h(app_url('/api/analytics.php?format=json')) ?>" class="btn btn-outline-primary btn-sm me-2" target="_blank" rel="noopener"><?= h(t('gov.analytics_export_json')) ?></a>
                  <a href="<?= h(app_url('/api/analytics.php?format=csv')) ?>" class="btn btn-outline-secondary btn-sm" download><?= h(t('gov.analytics_export_csv')) ?></a>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title mb-1"><?= h(t('gov.esg_dashboard_title')) ?></h6><br>
                  <p class="text-secondary small mb-3"><?= h(t('gov.esg_dashboard_desc')) ?></p>
                  <div class="row g-2 mb-3">
                    <div class="col-md-4">
                      <div class="border rounded p-2 bg-light">
                        <div class="fw-semibold small text-success"><?= h(t('gov.esg_env')) ?></div>
                        <ul class="small mb-0 ps-3">
                          <li><?= h(t('gov.esg_trees_total')) ?>: <strong><?= (int)($stats['environment']['trees_total'] ?? 0) ?></strong></li>
                          <li><?= h(t('gov.esg_green_reports')) ?>: <strong><?= (int)($stats['environment']['green_reports'] ?? 0) ?></strong></li>
                          <li><?= h(t('gov.esg_trees_water')) ?>: <?= (int)($stats['environment']['trees_needing_water'] ?? 0) ?></li>
                        </ul>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="border rounded p-2 bg-light">
                        <div class="fw-semibold small text-primary"><?= h(t('gov.esg_social')) ?></div>
                        <ul class="small mb-0 ps-3">
                          <li><?= h(t('gov.esg_active_citizens')) ?>: <strong><?= (int)($stats['social']['active_citizens_30d'] ?? 0) ?></strong></li>
                          <li><?= h(t('gov.esg_tree_adopters')) ?>: <strong><?= (int)($stats['social']['tree_adopters'] ?? 0) ?></strong></li>
                          <li><?= h(t('gov.esg_watering_30d')) ?>: <?= (int)($stats['social']['watering_actions_30d'] ?? 0) ?></li>
                        </ul>
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="border rounded p-2 bg-light">
                        <div class="fw-semibold small text-secondary"><?= h(t('gov.esg_gov')) ?></div>
                        <ul class="small mb-0 ps-3">
                          <li><?= h(t('gov.esg_open')) ?>: <strong><?= (int)($stats['governance']['reports_open'] ?? 0) ?></strong></li>
                          <li><?= h(t('gov.esg_solved_30d')) ?>: <?= (int)($stats['governance']['reports_solved_30d'] ?? 0) ?></li>
                          <li><?= h(t('gov.esg_avg_days')) ?>: <?= $stats['governance']['avg_resolution_days'] !== null ? round($stats['governance']['avg_resolution_days'], 1) : '—' ?></li>
                        </ul>
                      </div>
                    </div>
                  </div>
                  <div class="d-flex flex-wrap gap-2 align-items-center">
                    <label class="small mb-0"><?= h(t('gov.esg_report_year')) ?></label>
                    <select id="govEsgYear" class="form-select form-select-sm" style="max-width:100px">
                      <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>"<?= $y === (int)date('Y') ? ' selected' : '' ?>><?= $y ?></option>
                      <?php endfor; ?>
                    </select>
                    <a href="#" id="linkEsgJson" class="btn btn-outline-primary btn-sm">JSON</a>
                    <a href="#" id="linkEsgCsv" class="btn btn-outline-secondary btn-sm" download>CSV (Excel)</a>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- M7 Öntözendő fák -->
          <div class="row g-3 mt-1">
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title mb-1"><?= h(t('gov.trees_needing_water_title')) ?></h6><br>
                  <p class="text-secondary small mb-2"><?= h(t('gov.trees_needing_water_desc')) ?></p>
                  <p class="mb-2"><strong><?= (int)($stats['environment']['trees_needing_water'] ?? 0) ?></strong> <?= h(t('gov.esg_trees_water')) ?></p>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="btnTreesNeedingWater"><?= h(t('gov.trees_needing_water_list')) ?></button>
                  <div id="treesNeedingWaterList" class="mt-2 small" style="max-height:240px;overflow:auto;" hidden></div>
                </div>
              </div>
            </div>
          </div>

          <?php if ($govAiUiEnabled): ?>
          <p class="text-secondary small mb-2 mt-3"><?= h(t('gov.ai_summaries_intro')) ?></p>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="card h-100">
                <div class="card-body">
                  <h6 class="card-title mb-1"><?= h(t('gov.ai_panel')) ?></h6><br>
                  <p class="text-secondary small mb-3"><?= h(t('gov.ai_desc')) ?></p>
                  <button type="button" class="btn btn-primary btn-sm mb-3" id="btnGovAiSummary" <?= ai_configured() ? '' : 'disabled' ?>><?= h(t('gov.ai_request_summary')) ?></button>
                  <div id="govAiOutSummary" class="gov-ai-result border rounded p-3 bg-light small mt-2" style="min-height:80px;white-space:pre-wrap;"></div>
                  <button type="button" class="btn btn-outline-secondary btn-sm mt-2 d-none" id="btnPdfSummary"><?= h(t('gov.export_pdf')) ?></button>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card h-100">
                <div class="card-body">
                  <h6 class="card-title mb-1"><?= h(t('gov.ai_panel_esg')) ?></h6>
                  <p class="text-secondary small mb-3"><?= h(t('gov.esg_desc')) ?></p>
                  <button type="button" class="btn btn-primary btn-sm mb-3" id="btnGovEsg" <?= ai_configured() ? '' : 'disabled' ?>><?= h(t('gov.ai_request_esg')) ?></button>
                  <div id="govAiOutEsg" class="gov-ai-result border rounded p-3 bg-light small mt-2" style="min-height:80px;white-space:pre-wrap;"></div>
                  <button type="button" class="btn btn-outline-secondary btn-sm mt-2 d-none" id="btnPdfEsg"><?= h(t('gov.export_pdf')) ?></button>
                </div>
              </div>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title mb-1"><?= h(t('gov.ai_report_title')) ?></h6><br>
                  <p class="text-secondary small mb-3"><?= h(t('gov.ai_report_desc')) ?></p>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <label class="small mb-0"><?= h(t('gov.ai_report_type')) ?></label>
                    <select id="govReportType" class="form-select form-select-sm" style="max-width:220px">
                      <option value="summary"><?= h(t('gov.ai_report_type_summary')) ?></option>
                      <option value="esg"><?= h(t('gov.ai_report_type_esg')) ?></option>
                      <option value="maintenance"><?= h(t('gov.ai_report_type_maintenance')) ?></option>
                      <option value="engagement"><?= h(t('gov.ai_report_type_engagement')) ?></option>
                      <option value="sustainability"><?= h(t('gov.ai_report_type_sustainability')) ?></option>
                    </select>
                    <label class="small mb-0 ms-2"><?= h(t('gov.ai_report_timeframe')) ?></label>
                    <select id="govReportTimeframe" class="form-select form-select-sm" style="max-width:160px">
                      <option value="last_30_days"><?= h(t('gov.ai_report_30d')) ?></option>
                      <option value="last_90_days" selected><?= h(t('gov.ai_report_90d')) ?></option>
                      <option value="last_year"><?= h(t('gov.ai_report_year')) ?></option>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm" id="btnGovReport" <?= ai_configured() ? '' : 'disabled' ?>><?= h(t('gov.ai_report_generate')) ?></button>
                  </div>
                  <div id="govAiOutReport" class="gov-ai-result border rounded p-3 bg-light small mt-2" style="min-height:80px;white-space:pre-wrap;"></div>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="admin-tab-body" id="tab-trees" hidden>
          <div class="card">
            <div class="card-body">
              <h6 class="card-title"><?= h(t('gov.tab_trees')) ?></h6>
              <p class="text-secondary small mb-3"><?= h(t('gov.trees_intro')) ?></p>
              <div id="govTreesListWrap">
                <div id="govTreesList" class="admin-list" style="max-height:55vh;overflow:auto"></div>
                <p id="govTreesTotal" class="small text-secondary mt-2 mb-0"></p>
              </div>
            </div>
          </div>
          <div class="modal fade" id="govTreeEditModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
              <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title"><?= h(t('gov.tree_edit')) ?> – <span id="govTreeEditTitle">T0000</span></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <input type="hidden" id="govTreeEditId" value="">
                  <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small"><?= h(t('tree.species_label') ?: 'Fajta') ?></label><input type="text" id="govTreeSpecies" class="form-control form-control-sm" maxlength="120" placeholder="pl. kőris"></div>
                    <div class="col-md-6"><label class="form-label small"><?= h(t('gov.tree_address') ?: 'Cím') ?></label><input type="text" id="govTreeAddress" class="form-control form-control-sm" maxlength="255"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('tree.age') ?: 'Életkor (év)') ?></label><input type="number" id="govTreeEstimatedAge" class="form-control form-control-sm" min="0" max="500" placeholder="–"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('gov.tree_planting_year') ?: 'Ültetés éve') ?></label><input type="number" id="govTreePlantingYear" class="form-control form-control-sm" min="1900" max="2100" placeholder="–"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('tree.trunk_label') ?: 'Törzsméret (cm)') ?></label><input type="number" id="govTreeTrunkDiameter" class="form-control form-control-sm" min="0" max="500" step="0.1" placeholder="–"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('tree.canopy_label') ?: 'Koronaméret (m)') ?></label><input type="number" id="govTreeCanopyDiameter" class="form-control form-control-sm" min="0" max="50" step="0.1" placeholder="–"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('tree.health') ?: 'Állapot') ?></label><select id="govTreeHealthStatus" class="form-select form-select-sm"><option value="">–</option><option value="good"><?= h(t('tree.health_good') ?: 'Jó') ?></option><option value="fair"><?= h(t('tree.health_fair') ?: 'Közepes') ?></option><option value="poor"><?= h(t('tree.health_poor') ?: 'Gyenge') ?></option><option value="critical"><?= h(t('tree.health_critical') ?: 'Kritikus') ?></option></select></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('tree.risk') ?: 'Kockázat') ?></label><select id="govTreeRiskLevel" class="form-select form-select-sm"><option value="">–</option><option value="low"><?= h(t('tree.risk_low') ?: 'Alacsony') ?></option><option value="medium"><?= h(t('tree.risk_medium') ?: 'Közepes') ?></option><option value="high"><?= h(t('tree.risk_high') ?: 'Magas') ?></option></select></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('gov.trees_last_watered')) ?></label><input type="date" id="govTreeLastWatered" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('gov.tree_last_inspection') ?: 'Utolsó ellenőrzés') ?></label><input type="date" id="govTreeLastInspection" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('gov.tree_visible') ?: 'Látható') ?></label><select id="govTreePublicVisible" class="form-select form-select-sm"><option value="1"><?= h(t('common.yes') ?: 'Igen') ?></option><option value="0"><?= h(t('common.no') ?: 'Nem') ?></option></select></div>
                    <div class="col-md-4"><label class="form-label small"><?= h(t('gov.tree_validated') ?: 'Közig jóváhagyva') ?></label><select id="govTreeGovValidated" class="form-select form-select-sm"><option value="0"><?= h(t('common.no') ?: 'Nem') ?></option><option value="1"><?= h(t('common.yes') ?: 'Igen') ?></option></select></div>
                    <div class="col-12"><label class="form-label small"><?= h(t('tree.note_placeholder') ?: 'Megjegyzés') ?></label><textarea id="govTreeNotes" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea></div>
                  </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= h(t('modal.cancel') ?: 'Mégse') ?></button><button type="button" class="btn btn-primary btn-sm" id="govTreeSaveBtn"><?= h(t('gov.tree_save')) ?></button></div>
              </div>
            </div>
          </div>
        </div>

        <div class="admin-tab-body" id="tab-analytics" hidden>
          <p class="text-secondary small mb-2"><?= h(t('gov.tab_analytics') ?: 'Elemzés') ?> – <?= h(t('gov.heatmap_widget_title')) ?>, <?= h(t('gov.statistics_tab_title')) ?></p>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.heatmap_widget_title')) ?></h6>
              <p class="text-secondary small mb-2"><?= h(t('heatmap.title')) ?></p>
              <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <label class="small mb-0"><?= h(t('heatmap.type_label')) ?>:</label>
                <select id="govHeatmapType" class="form-select form-select-sm" style="max-width:220px">
                  <option value="issue_density"><?= h(t('heatmap.type_issue_density')) ?></option>
                  <option value="unresolved_issues"><?= h(t('heatmap.type_unresolved_issues')) ?></option>
                  <option value="citizen_activity"><?= h(t('heatmap.type_citizen_activity')) ?></option>
                  <option value="tree_health_risk"><?= h(t('heatmap.type_tree_health_risk')) ?></option>
                  <option value="esg_risk"><?= h(t('heatmap.type_esg_risk')) ?></option>
                </select>
                <button type="button" id="govHeatmapRefresh" class="btn btn-sm btn-outline-primary"><?= h(t('admin.refresh')) ?></button>
              </div>
              <div id="govHeatmapMap" style="height:500px;width:100%;border:1px solid #dee2e6;border-radius:0.375rem;"></div>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.statistics_tab_title')) ?></h6>
              <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <label class="small mb-0"><?= h(t('gov.statistics_date_range') ?: 'Időszak') ?>:</label>
                <input type="date" id="govStatsDateFrom" class="form-control form-control-sm" style="max-width:140px">
                <span class="small">–</span>
                <input type="date" id="govStatsDateTo" class="form-control form-control-sm" style="max-width:140px">
                <button type="button" id="govStatisticsRefresh" class="btn btn-sm btn-outline-primary"><?= h(t('gov.statistics_refresh')) ?></button>
              </div>
              <div id="govStatisticsContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
            </div>
          </div>
        </div>

        <div class="admin-tab-body" id="tab-reports" hidden>
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.reports_list')) ?></h6><br>
              <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <label for="govStatusFilter" class="text-secondary small"><?= h(t('gov.filter_status')) ?></label>
                <select id="govStatusFilter" name="status_filter" onchange="this.form.submit()" class="form-select form-select-sm" style="max-width:240px">
                  <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>><?= h(t('legend.all')) ?></option>
                  <?php foreach ($allowedStatuses as $st): ?>
                    <option value="<?= h($st) ?>"<?= $statusFilter === $st ? ' selected' : '' ?>><?= h($statusLabels[$st] ?? $st) ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="text-secondary small ms-auto"><?= h(t('gov.list_max')) ?></span>
              </form>

              <div class="admin-list" style="max-height:60vh">
                <?php foreach($reports as $r): ?>
                  <div class="admin-item">
                    <div><b>#<?= (int)$r['id'] ?></b> <span class="text-secondary small">• <?= h($r['category']) ?> • <?= h($r['status']) ?></span></div>
                    <div class="text-secondary small"><?= h($r['title'] ?: t('gov.report_anonymous')) ?></div>
                    <div class="text-secondary small"><?= h($r['address_approx'] ?: $r['city'] ?: '') ?></div>
                  </div>
                <?php endforeach; ?>
                <?php if (!$reports): ?>
                  <div class="text-secondary small"><?= h(t('gov.no_data')) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="admin-tab-body" id="tab-ideas" hidden>
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('legend.ideas_section') ?: 'Ötletek') ?></h6>
              <p class="text-secondary small mb-3"><?= h(t('gov.ideas_intro') ?: 'Ötletek listája, státusz módosítása.') ?></p>
              <div class="table-responsive">
                <table class="table table-sm table-hover">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th><?= h(t('idea.title_placeholder') ?: 'Cím') ?></th>
                      <th><?= h(t('idea.votes') ?: 'Szavazat') ?></th>
                      <th><?= h(t('common.status') ?: 'Státusz') ?></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $ideaStatusLabels = [
                      'submitted' => t('gov.idea_status_submitted') ?: 'Beküldve',
                      'under_review' => t('gov.idea_status_review') ?: 'Átnézés alatt',
                      'planned' => t('gov.idea_status_planned') ?: 'Tervezett',
                      'in_progress' => t('gov.idea_status_in_progress') ?: 'Folyamatban',
                      'completed' => t('gov.idea_status_completed') ?: 'Kész',
                    ];
                    foreach ($ideasList as $idea): ?>
                    <tr>
                      <td><?= (int)$idea['id'] ?></td>
                      <td>
                        <span class="fw-semibold"><?= h($idea['title'] ?: '—') ?></span>
                        <?php if (!empty($idea['description'])): ?>
                          <br><span class="text-secondary small"><?= h(mb_strimwidth($idea['description'], 0, 80, '…')) ?></span>
                        <?php endif; ?>
                        <br><span class="text-muted small"><?= h($idea['author_name'] ?: t('common.anonymous')) ?> · <?= date('Y-m-d H:i', strtotime($idea['created_at'])) ?></span>
                      </td>
                      <td><?= (int)($idea['vote_count'] ?? 0) ?></td>
                      <td>
                        <form method="post" class="d-inline" onchange="this.submit()">
                          <input type="hidden" name="action" value="idea_set_status">
                          <input type="hidden" name="id" value="<?= (int)$idea['id'] ?>">
                          <select name="status" class="form-select form-select-sm" style="min-width:140px">
                            <?php foreach (array_keys($ideaStatusLabels) as $st): ?>
                              <option value="<?= h($st) ?>"<?= ($idea['status'] ?? '') === $st ? ' selected' : '' ?>><?= h($ideaStatusLabels[$st]) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </form>
                      </td>
                      <td></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if (empty($ideasList)): ?>
                <p class="text-secondary small mb-0"><?= h(t('gov.no_data')) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!$isAdmin): ?>
        <div class="admin-tab-body" id="tab-modules" hidden>
          <div class="card">
            <div class="card-body">
              <p class="text-secondary small mb-3"><?= h(t('gov.modules_intro')) ?></p>
              <div id="govModuleList"><?= h(t('admin.load')) ?>...</div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php endif; // auth/no authority ?>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="<?= htmlspecialchars(app_url('/dashboard/dist/js/adminlte.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function(){
  var aiUrl = <?= json_encode(app_url('/api/gov_ai.php'), JSON_UNESCAPED_SLASHES) ?>;
  var modulesUrl = <?= json_encode(app_url('/api/gov_modules.php'), JSON_UNESCAPED_SLASHES) ?>;
  var esgExportUrl = <?= json_encode(app_url('/api/esg_export.php'), JSON_UNESCAPED_SLASHES) ?>;
  var heatmapUrl = <?= json_encode(app_url('/api/heatmap_data.php'), JSON_UNESCAPED_SLASHES) ?>;
  var authorityIdForHeatmap = <?= !empty($authorityIds) ? (int)$authorityIds[0] : '0' ?>;
  var govStatisticsUrl = <?= json_encode(app_url('/api/gov_statistics.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govStatisticsLabels = <?= json_encode([
    'issue_trend' => t('gov.statistics_issue_trend'),
    'resolution_time' => t('gov.statistics_resolution_time'),
    'engagement' => t('gov.statistics_engagement'),
    'districts' => t('gov.statistics_districts'),
    'resolution_rate' => t('gov.statistics_resolution_rate'),
    'backlog' => t('gov.statistics_backlog'),
    'participation' => t('gov.statistics_participation'),
    'trees' => t('gov.statistics_trees'),
    'avg_hours' => t('gov.statistics_avg_hours'),
    'median_hours' => t('gov.statistics_median_hours'),
    'open_issues' => t('gov.statistics_open_issues'),
    'trend_up' => t('gov.statistics_trend_up'),
    'trend_down' => t('gov.statistics_trend_down'),
    'trend_stable' => t('gov.statistics_trend_stable'),
    'resolved' => t('gov.statistics_resolved'),
    'total_issues' => t('gov.statistics_total_issues'),
    'active_users_7d' => t('gov.statistics_active_users_7d'),
    'reports_7d' => t('gov.statistics_reports_7d'),
    'trees_total' => t('gov.statistics_trees_total'),
    'trees_watered' => t('gov.statistics_trees_watered'),
    'trees_adopted' => t('gov.statistics_trees_adopted'),
    'trees_risk' => t('gov.statistics_trees_risk'),
    'new_users_7d' => t('gov.statistics_new_users_7d'),
    'no_data' => t('gov.no_data'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var mapCenterLat = <?= json_encode(defined('MAP_CENTER_LAT') ? (float)MAP_CENTER_LAT : 47.1625) ?>;
  var mapCenterLng = <?= json_encode(defined('MAP_CENTER_LNG') ? (float)MAP_CENTER_LNG : 19.5033) ?>;
  var appName = <?= json_encode(t('site.name'), JSON_UNESCAPED_UNICODE) ?>;
  var logoUrl = <?= json_encode(app_url('/assets/logo.png'), JSON_UNESCAPED_SLASHES) ?>;
  var govHeatmapMap = null;
  var govHeatmapLayer = null;

  var govEsgYear = document.getElementById('govEsgYear');
  var linkEsgJson = document.getElementById('linkEsgJson');
  var linkEsgCsv = document.getElementById('linkEsgCsv');
  function updateEsgExportLinks(){
    var y = govEsgYear ? govEsgYear.value : new Date().getFullYear();
    if (linkEsgJson) linkEsgJson.href = esgExportUrl + '?year=' + y + '&format=json';
    if (linkEsgCsv) linkEsgCsv.href = esgExportUrl + '?year=' + y + '&format=csv';
  }
  if (govEsgYear) govEsgYear.addEventListener('change', updateEsgExportLinks);
  updateEsgExportLinks();

  var btnTreesNeedingWater = document.getElementById('btnTreesNeedingWater');
  var treesNeedingWaterList = document.getElementById('treesNeedingWaterList');
  if (btnTreesNeedingWater && treesNeedingWaterList) {
    btnTreesNeedingWater.addEventListener('click', function(){
      var url = <?= json_encode(app_url('/api/trees_needing_water.php?limit=50'), JSON_UNESCAPED_SLASHES) ?>;
      btnTreesNeedingWater.disabled = true;
      fetch(url, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
        btnTreesNeedingWater.disabled = false;
        if (!j.ok || !Array.isArray(j.data)) { treesNeedingWaterList.textContent = <?= json_encode(t('gov.no_data'), JSON_UNESCAPED_UNICODE) ?>; treesNeedingWaterList.hidden = false; return; }
        if (j.data.length === 0) { treesNeedingWaterList.textContent = <?= json_encode(t('gov.trees_water_empty'), JSON_UNESCAPED_UNICODE) ?>; treesNeedingWaterList.hidden = false; return; }
        var html = '<ul class="list-unstyled mb-0">';
        var lastWateredLabel = <?= json_encode(t('gov.trees_last_watered'), JSON_UNESCAPED_UNICODE) ?>;
        j.data.forEach(function(t){
          var rec = (t.watering_volume_liters != null ? t.watering_volume_liters + <?= json_encode(' ' . t('gov.liters_recommended'), JSON_UNESCAPED_UNICODE) ?> : '');
          html += '<li class="border-bottom py-1">#' + (t.id || '') + ' ' + (t.species || '') + ' – ' + lastWateredLabel + ': ' + (t.last_watered || '—') + (rec ? ' · ' + rec : '') + '</li>';
        });
        html += '</ul>';
        treesNeedingWaterList.innerHTML = html;
        treesNeedingWaterList.hidden = false;
      }).catch(function(){ btnTreesNeedingWater.disabled = false; treesNeedingWaterList.textContent = <?= json_encode(t('common.error_generic'), JSON_UNESCAPED_UNICODE) ?>; treesNeedingWaterList.hidden = false; });
    });
  }

  function postJson(url, body){
    return fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include', body: JSON.stringify(body) })
      .then(function(r){ return r.text().then(function(t){ var j=null; try{j=JSON.parse(t);}catch(_){}; return { ok:r.ok, j:j, t:t }; }); });
  }

  function formatAiData(data){
    if (!data) return '';
    var text = (data.text || data.summary || '').trim();
    if (text) {
      var jsonStart = text.indexOf('{');
      if (jsonStart !== -1) {
        var maybeJson = text.slice(jsonStart).replace(/^\s*```\s*\w*\s*\n?|\n?\s*```\s*$/g, '').trim();
        try {
          var parsed = JSON.parse(maybeJson);
          if (parsed.text) text = parsed.text;
          else if (parsed.summary) text = parsed.summary;
          else if (parsed.esg_metrics || parsed.citizen_engagement) data.raw = parsed;
        } catch(_) {}
      }
    }
    var raw = data.raw && typeof data.raw === 'object' ? data.raw : null;
    var parts = [];
    if (text) parts.push(text);
    if (raw && raw.top_problems && Array.isArray(raw.top_problems) && raw.top_problems.length > 0) {
      parts.push('');
      parts.push('Fő problémák:');
      raw.top_problems.forEach(function(p){
        parts.push('• ' + (p.category || p.category_name || '') + (p.why_now ? ': ' + p.why_now : ''));
      });
    }
    if (raw && !text && raw.text) parts.unshift(raw.text);
    if (raw && (raw.esg_metrics || raw.citizen_engagement)) {
      if (raw.esg_metrics && Array.isArray(raw.esg_metrics)) {
        if (parts.length) parts.push('');
        parts.push('Fenntarthatósági mutatók:');
        raw.esg_metrics.forEach(function(m){
          parts.push('• ' + (m.metric || m.name || '') + ': ' + (m.current_signal != null ? m.current_signal : '') + (m.next_step ? ' – Következő lépés: ' + m.next_step : ''));
        });
      }
      if (raw.citizen_engagement && Array.isArray(raw.citizen_engagement)) {
        parts.push('');
        parts.push('Polgári részvétel:');
        raw.citizen_engagement.forEach(function(c){
          parts.push('• ' + (c.idea || c.title || '') + (c.how_to_measure ? ' – Mérés: ' + c.how_to_measure : ''));
        });
      }
    }
    if (parts.length) return parts.join('\n');
    if (raw && raw.text) return raw.text;
    return '';
  }

  function renderResult(container, pdfBtn, title, data){
    if (!container) return;
    var html = formatAiData(data);
    if (!html) { container.textContent = (data && (data.text || data.raw)) ? 'Nem sikerült formázni.' : ''; if (pdfBtn) pdfBtn.classList.add('d-none'); return; }
    container.innerHTML = html.replace(/\n/g, '<br>');
    container.setAttribute('data-pdf-title', title);
    container.setAttribute('data-pdf-content', html.replace(/<br\s*\/?>/gi, '\n'));
    if (pdfBtn) pdfBtn.classList.remove('d-none');
  }

  function downloadPdf(btnId){
    var block = btnId === 'btnPdfSummary' ? document.getElementById('govAiOutSummary') : document.getElementById('govAiOutEsg');
    if (!block || !block.getAttribute('data-pdf-content')) return;
    var title = block.getAttribute('data-pdf-title') || <?= json_encode(t('gov.ai_summary_title'), JSON_UNESCAPED_UNICODE) ?>;
    var content = block.getAttribute('data-pdf-content');
    var JsPDF = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
    if (!JsPDF) { alert(<?= json_encode(t('gov.pdf_lib_missing'), JSON_UNESCAPED_UNICODE) ?>); return; }
    var doc = new JsPDF();
    var y = 20;
    function addContent(){
      doc.setFontSize(12);
      doc.text(title, 14, y); y += 8;
      doc.setFontSize(9);
      doc.text(<?= json_encode(t('gov.pdf_created'), JSON_UNESCAPED_UNICODE) ?> + ' ' + new Date().toLocaleString(), 14, y); y += 12;
      var lines = doc.splitTextToSize(content, 180);
      doc.setFontSize(10);
      doc.text(lines, 14, y);
      doc.save('civic-ai-' + title.replace(/\s+/g, '-').toLowerCase() + '.pdf');
    }
    if (logoUrl) {
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function(){
        try {
          var c = document.createElement('canvas');
          c.width = img.naturalWidth || img.width;
          c.height = img.naturalHeight || img.height;
          var ctx = c.getContext('2d');
          ctx.drawImage(img, 0, 0);
          var dataUrl = c.toDataURL('image/png');
          var w = Math.min(24, c.width);
          var h = (c.height / c.width) * w;
          doc.addImage(dataUrl, 'PNG', 14, 10, w, h);
          doc.setFontSize(16);
          doc.text(appName || 'Civic AI', 14 + w + 6, 18);
          y = 14 + h + 8;
        } catch (_) { y = 20; doc.setFontSize(16); doc.text(appName || 'Civic AI', 14, y); y += 10; }
        addContent();
      };
      img.onerror = function(){ doc.setFontSize(16); doc.text(appName || 'Civic AI', 14, y); y += 10; addContent(); };
      img.src = logoUrl;
    } else {
      doc.setFontSize(16);
      doc.text(appName || 'Civic AI', 14, y); y += 10;
      addContent();
    }
  }

  // Tabs
  document.querySelectorAll('.tab[data-tab]').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      var key = btn.getAttribute('data-tab');
      document.querySelectorAll('.tab[data-tab]').forEach(function(x){ x.classList.toggle('active', x===btn); });
      ['dashboard','reports','ideas','trees','analytics','modules'].forEach(function(k){
        var el = document.getElementById('tab-' + k);
        if (el) el.hidden = (k !== key);
      });
      if (key === 'modules') loadGovModules();
      if (key === 'trees') loadGovTrees();
      if (key === 'analytics') { initGovHeatmapTab(); initGovStatisticsTab(); }
    });
  });

  function initGovHeatmapTab(){
    var container = document.getElementById('govHeatmapMap');
    if (!container || typeof L === 'undefined') return;
    if (!govHeatmapMap) {
      govHeatmapMap = L.map('govHeatmapMap').setView([mapCenterLat, mapCenterLng], 11);
      L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', { maxZoom: 20, attribution: '&copy; OSM' }).addTo(govHeatmapMap);
    }
    loadGovHeatmap();
  }
  function loadGovHeatmap(){
    if (!govHeatmapMap || !heatmapUrl || typeof L === 'undefined') return;
    var typeSel = document.getElementById('govHeatmapType');
    var type = typeSel ? typeSel.value : 'issue_density';
    var from = new Date();
    from.setDate(from.getDate() - 30);
    var to = new Date();
    var params = 'type=' + encodeURIComponent(type) + '&date_from=' + from.toISOString().slice(0, 10) + '&date_to=' + to.toISOString().slice(0, 10);
    if (authorityIdForHeatmap > 0) params += '&authority_id=' + authorityIdForHeatmap;
    fetch(heatmapUrl + '?' + params, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      if (govHeatmapLayer) { govHeatmapMap.removeLayer(govHeatmapLayer); govHeatmapLayer = null; }
      if (!j.ok || !Array.isArray(j.data) || j.data.length === 0) return;
      var points = j.data.map(function(p){ return [Number(p.lat), Number(p.lng), Number(p.weight) || 1]; });
      if (typeof L.heatLayer !== 'undefined') {
        govHeatmapLayer = L.heatLayer(points, { radius: 25, blur: 15, maxZoom: 17, max: 1, gradient: { 0.2: 'blue', 0.5: 'lime', 0.8: 'red' } }).addTo(govHeatmapMap);
      }
    }).catch(function(){});
  }
  document.getElementById('govHeatmapRefresh') && document.getElementById('govHeatmapRefresh').addEventListener('click', loadGovHeatmap);
  document.getElementById('govHeatmapType') && document.getElementById('govHeatmapType').addEventListener('change', loadGovHeatmap);

  function initGovStatisticsTab(){
    var fromEl = document.getElementById('govStatsDateFrom');
    var toEl = document.getElementById('govStatsDateTo');
    if (fromEl && !fromEl.value) {
      var from = new Date();
      from.setDate(from.getDate() - 30);
      fromEl.value = from.toISOString().slice(0, 10);
    }
    if (toEl && !toEl.value) toEl.value = new Date().toISOString().slice(0, 10);
    loadGovStatistics();
  }
  function loadGovStatistics(){
    var container = document.getElementById('govStatisticsContent');
    if (!container || !govStatisticsUrl) return;
    var fromEl = document.getElementById('govStatsDateFrom');
    var toEl = document.getElementById('govStatsDateTo');
    var dateFrom = (fromEl && fromEl.value) ? fromEl.value : new Date(Date.now() - 30*24*60*60*1000).toISOString().slice(0, 10);
    var dateTo = (toEl && toEl.value) ? toEl.value : new Date().toISOString().slice(0, 10);
    container.innerHTML = '<p class="text-secondary small mb-0"><?= json_encode(t('gov.loading') ?: 'Betöltés...', JSON_UNESCAPED_UNICODE) ?></p>';
    var params = 'date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
    fetch(govStatisticsUrl + '?' + params, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      var L = govStatisticsLabels || {};
      if (!j.ok || !j.data) {
        container.innerHTML = '<p class="text-secondary small mb-0">' + (L.no_data || 'Nincs adat') + '</p>';
        return;
      }
      var d = j.data;
      var html = '';
      html += '<div class="row g-3 mb-3">';
      html += '<div class="col-md-6"><div class="card"><div class="card-body py-2"><h6 class="card-title small">' + (L.resolution_rate || 'Resolution rate') + '</h6><p class="mb-0 small">' + (L.resolved || 'Resolved') + ': <b>' + (d.resolution_rate && d.resolution_rate.resolved) + '</b> / ' + (d.resolution_rate && d.resolution_rate.total) + ' (' + (d.resolution_rate && (d.resolution_rate.rate * 100).toFixed(0) + '%)') + '</p></div></div></div>';
      html += '<div class="col-md-6"><div class="card"><div class="card-body py-2"><h6 class="card-title small">' + (L.resolution_time || 'Resolution time') + '</h6><p class="mb-0 small">' + (L.avg_hours || 'Avg') + ': <b>' + (d.response_times && d.response_times.avg_hours) + ' h</b> · ' + (L.median_hours || 'Median') + ': ' + (d.response_times && d.response_times.median_hours) + ' h</p></div></div></div>';
      html += '</div>';
      html += '<div class="row g-3 mb-3">';
      var trendLabel = (d.backlog_growth && d.backlog_growth.trend === 'up') ? (L.trend_up || 'up') : (d.backlog_growth && d.backlog_growth.trend === 'down') ? (L.trend_down || 'down') : (L.trend_stable || 'stable');
      html += '<div class="col-md-4"><div class="card"><div class="card-body py-2"><h6 class="card-title small">' + (L.backlog || 'Backlog') + '</h6><p class="mb-0 small">' + (L.open_issues || 'Open') + ': <b>' + (d.backlog_growth && d.backlog_growth.current_open) + '</b> (' + trendLabel + ')</p></div></div></div>';
      html += '<div class="col-md-4"><div class="card"><div class="card-body py-2"><h6 class="card-title small">' + (L.participation || 'Participation') + '</h6><p class="mb-0 small">' + (L.active_users_7d || 'Active 7d') + ': <b>' + (d.citizen_participation_rate && d.citizen_participation_rate.active_users_7d) + '</b> · ' + (L.reports_7d || 'Reports 7d') + ': ' + (d.citizen_participation_rate && d.citizen_participation_rate.reports_7d) + '</p></div></div></div>';
      html += '<div class="col-md-4"><div class="card"><div class="card-body py-2"><h6 class="card-title small">' + (L.trees || 'Trees') + '</h6><p class="mb-0 small">' + (L.trees_total || 'Total') + ': ' + (d.tree_maintenance_stats && d.tree_maintenance_stats.total_trees) + ' · ' + (L.trees_watered || 'Watered 7d') + ': ' + (d.tree_maintenance_stats && d.tree_maintenance_stats.watered_7d) + ' · ' + (L.trees_adopted || 'Adopted') + ': ' + (d.tree_maintenance_stats && d.tree_maintenance_stats.adopted) + '</p></div></div></div>';
      html += '</div>';
      if (d.issue_trend_per_district && d.issue_trend_per_district.length > 0) {
        html += '<h6 class="small mt-2">' + (L.districts || 'Districts') + '</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Hatóság</th><th>Ügyek</th></tr></thead><tbody>';
        d.issue_trend_per_district.forEach(function(row){ html += '<tr><td>' + (row.name || row.authority_id) + '</td><td>' + row.count + '</td></tr>'; });
        html += '</tbody></table></div>';
      }
      if (d.issue_trends && d.issue_trends.length > 0) {
        var byDate = {};
        d.issue_trends.forEach(function(x){ byDate[x.date] = (byDate[x.date] || 0) + x.count; });
        var dates = Object.keys(byDate).sort();
        var maxTr = Math.max.apply(null, dates.map(function(k){ return byDate[k]; })) || 1;
        html += '<h6 class="small mt-2">' + (L.issue_trend || 'Issue trend') + '</h6><div class="admin-chart">';
        dates.slice(-14).forEach(function(date){ var cnt = byDate[date]; html += '<div class="admin-chart-bar"><span class="label">' + date + '</span><div class="bar-wrap"><div class="bar" style="width:' + Math.round(100 * cnt / maxTr) + '%;background:#0d6efd"></div></div><span class="val">' + cnt + '</span></div>'; });
        html += '</div>';
      }
      container.innerHTML = html;
    }).catch(function(){ var container = document.getElementById('govStatisticsContent'); if (container) container.innerHTML = '<p class="text-danger small">Hiba a betöltéskor.</p>'; });
  }
  document.getElementById('govStatisticsRefresh') && document.getElementById('govStatisticsRefresh').addEventListener('click', loadGovStatistics);

  var govTreesListUrl = <?= json_encode(app_url('/api/gov_trees_list.php'), JSON_UNESCAPED_SLASHES) ?>;
  var treeEditUrl = <?= json_encode(app_url('/api/tree_edit.php'), JSON_UNESCAPED_SLASHES) ?>;
  function escStr(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function loadGovTrees(){
    var wrap = document.getElementById('govTreesList');
    var totalEl = document.getElementById('govTreesTotal');
    if (!wrap) return;
    wrap.textContent = <?= json_encode(t('gov.loading') ?: 'Betöltés...', JSON_UNESCAPED_UNICODE) ?>;
    fetch(govTreesListUrl + '?limit=200&offset=0', { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok || !Array.isArray(j.data)) { wrap.textContent = <?= json_encode(t('gov.no_data'), JSON_UNESCAPED_UNICODE) ?>; if (totalEl) totalEl.textContent = ''; return; }
      var total = j.total || j.data.length;
      if (totalEl) totalEl.textContent = total + ' fa';
      var editLabel = <?= json_encode(t('gov.tree_edit'), JSON_UNESCAPED_UNICODE) ?>;
      wrap.innerHTML = j.data.map(function(t){
        var serial = 'T' + String(Number(t.id)).padStart(4, '0');
        var sp = escStr((t.species || '').trim() || '–');
        var addr = (t.address || '').trim(); addr = addr ? escStr(addr.substring(0, 40) + (addr.length > 40 ? '…' : '')) : '';
        var lastW = escStr(t.last_watered || '–');
        var health = escStr(t.health_status || '–');
        return '<div class="admin-item d-flex justify-content-between align-items-center flex-wrap gap-1">' +
          '<div><b>' + serial + '</b> ' + sp + (addr ? ' <span class="text-secondary small">' + addr + '</span>' : '') + '<br><small class="text-secondary">' + lastW + ' | ' + health + '</small></div>' +
          '<button type="button" class="btn btn-outline-primary btn-sm gov-tree-edit-btn" data-id="' + t.id + '">' + editLabel + '</button>' +
        '</div>';
      }).join('');
      wrap.querySelectorAll('.gov-tree-edit-btn').forEach(function(btn){
        btn.addEventListener('click', function(){ openGovTreeEdit(Number(btn.getAttribute('data-id')), j.data.find(function(x){ return Number(x.id) === Number(btn.getAttribute('data-id')); })); });
      });
    }).catch(function(){ wrap.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; if (totalEl) totalEl.textContent = ''; });
  }
  function openGovTreeEdit(id, t){
    if (!t) return;
    document.getElementById('govTreeEditId').value = id;
    document.getElementById('govTreeEditTitle').textContent = 'T' + String(id).padStart(4, '0');
    document.getElementById('govTreeSpecies').value = t.species || '';
    document.getElementById('govTreeAddress').value = t.address || '';
    document.getElementById('govTreeEstimatedAge').value = (t.estimated_age != null && t.estimated_age !== '') ? t.estimated_age : '';
    document.getElementById('govTreePlantingYear').value = (t.planting_year != null && t.planting_year !== '') ? t.planting_year : '';
    document.getElementById('govTreeTrunkDiameter').value = (t.trunk_diameter != null && t.trunk_diameter !== '') ? t.trunk_diameter : '';
    document.getElementById('govTreeCanopyDiameter').value = (t.canopy_diameter != null && t.canopy_diameter !== '') ? t.canopy_diameter : '';
    document.getElementById('govTreeHealthStatus').value = t.health_status || '';
    document.getElementById('govTreeRiskLevel').value = t.risk_level || '';
    document.getElementById('govTreeLastWatered').value = t.last_watered || '';
    document.getElementById('govTreeLastInspection').value = t.last_inspection || '';
    document.getElementById('govTreePublicVisible').value = (t.public_visible == 1 || t.public_visible === true) ? '1' : '0';
    document.getElementById('govTreeGovValidated').value = (t.gov_validated == 1 || t.gov_validated === true) ? '1' : '0';
    document.getElementById('govTreeNotes').value = t.notes || '';
    var modal = new bootstrap.Modal(document.getElementById('govTreeEditModal'));
    modal.show();
  }
  document.getElementById('govTreeSaveBtn') && document.getElementById('govTreeSaveBtn').addEventListener('click', function(){
    var id = document.getElementById('govTreeEditId').value;
    if (!id) return;
    var btn = document.getElementById('govTreeSaveBtn');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('tree_id', id);
    fd.append('species', document.getElementById('govTreeSpecies').value);
    fd.append('address', document.getElementById('govTreeAddress').value);
    fd.append('estimated_age', document.getElementById('govTreeEstimatedAge').value);
    fd.append('planting_year', document.getElementById('govTreePlantingYear').value);
    fd.append('trunk_diameter', document.getElementById('govTreeTrunkDiameter').value);
    fd.append('canopy_diameter', document.getElementById('govTreeCanopyDiameter').value);
    fd.append('health_status', document.getElementById('govTreeHealthStatus').value);
    fd.append('risk_level', document.getElementById('govTreeRiskLevel').value);
    fd.append('last_watered', document.getElementById('govTreeLastWatered').value);
    fd.append('last_inspection', document.getElementById('govTreeLastInspection').value);
    fd.append('public_visible', document.getElementById('govTreePublicVisible').value);
    fd.append('gov_validated', document.getElementById('govTreeGovValidated').value);
    fd.append('notes', document.getElementById('govTreeNotes').value);
    fetch(treeEditUrl, { method: 'POST', body: fd, credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      btn.disabled = false;
      if (j && j.ok) {
        bootstrap.Modal.getInstance(document.getElementById('govTreeEditModal')).hide();
        loadGovTrees();
      } else { alert(j && j.error ? j.error : <?= json_encode(t('common.error_save_failed'), JSON_UNESCAPED_UNICODE) ?>); }
    }).catch(function(){ btn.disabled = false; alert(<?= json_encode(t('common.error_generic'), JSON_UNESCAPED_UNICODE) ?>); });
  });

  var outSum = document.getElementById('govAiOutSummary');
  var outEsg = document.getElementById('govAiOutEsg');
  var outReport = document.getElementById('govAiOutReport');
  var btnSum = document.getElementById('btnGovAiSummary');
  var btnEsg = document.getElementById('btnGovEsg');
  var btnReport = document.getElementById('btnGovReport');
  function setBusy(b){
    if (btnSum) btnSum.disabled = b;
    if (btnEsg) btnEsg.disabled = b;
    if (btnReport) btnReport.disabled = b;
  }
  btnSum && btnSum.addEventListener('click', function(){
    if (!outSum) return;
    outSum.textContent = <?= json_encode(t('gov.generating'), JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('btnPdfSummary').classList.add('d-none');
    setBusy(true);
    postJson(aiUrl, { action:'generate', type:'summary' }).then(function(x){
      setBusy(false);
      if (x.ok && x.j && x.j.ok) renderResult(outSum, document.getElementById('btnPdfSummary'), <?= json_encode(t('gov.ai_summary_title'), JSON_UNESCAPED_UNICODE) ?>, x.j.data);
      else outSum.textContent = (x.j && (x.j.error || x.j.message)) ? (x.j.error || x.j.message) : (<?= json_encode(t('common.error_generic') . ': ', JSON_UNESCAPED_UNICODE) ?> + (x.t || <?= json_encode(t('common.error_unknown'), JSON_UNESCAPED_UNICODE) ?>));
    }).catch(function(){ setBusy(false); if(outSum) outSum.textContent=<?= json_encode(t('common.error_generic'), JSON_UNESCAPED_UNICODE) ?>; });
  });
  btnEsg && btnEsg.addEventListener('click', function(){
    if (!outEsg) return;
    outEsg.textContent = <?= json_encode(t('gov.generating'), JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('btnPdfEsg').classList.add('d-none');
    setBusy(true);
    postJson(aiUrl, { action:'generate', type:'esg' }).then(function(x){
      setBusy(false);
      if (x.ok && x.j && x.j.ok) renderResult(outEsg, document.getElementById('btnPdfEsg'), <?= json_encode(t('gov.esg_title'), JSON_UNESCAPED_UNICODE) ?>, x.j.data);
      else outEsg.textContent = (x.j && (x.j.error || x.j.message)) ? (x.j.error || x.j.message) : (<?= json_encode(t('common.error_generic') . ': ', JSON_UNESCAPED_UNICODE) ?> + (x.t || <?= json_encode(t('common.error_unknown'), JSON_UNESCAPED_UNICODE) ?>));
    }).catch(function(){ setBusy(false); if(outEsg) outEsg.textContent=<?= json_encode(t('common.error_generic'), JSON_UNESCAPED_UNICODE) ?>; });
  });
  document.getElementById('btnPdfSummary') && document.getElementById('btnPdfSummary').addEventListener('click', function(){ downloadPdf('btnPdfSummary'); });
  document.getElementById('btnPdfEsg') && document.getElementById('btnPdfEsg').addEventListener('click', function(){ downloadPdf('btnPdfEsg'); });

  if (btnReport && outReport) {
    btnReport.addEventListener('click', function(){
      var reportType = (document.getElementById('govReportType') && document.getElementById('govReportType').value) || 'summary';
      var timeframe = (document.getElementById('govReportTimeframe') && document.getElementById('govReportTimeframe').value) || 'last_90_days';
      outReport.textContent = <?= json_encode(t('gov.generating'), JSON_UNESCAPED_UNICODE) ?>;
      setBusy(true);
      postJson(aiUrl, { action:'generate', type: reportType, timeframe: timeframe }).then(function(x){
        setBusy(false);
        if (x.ok && x.j && x.j.ok) renderResult(outReport, null, <?= json_encode(t('gov.report_title'), JSON_UNESCAPED_UNICODE) ?>, x.j.data);
        else outReport.textContent = (x.j && (x.j.error || x.j.message)) ? (x.j.error || x.j.message) : (<?= json_encode(t('common.error_generic') . ': ', JSON_UNESCAPED_UNICODE) ?> + (x.t || <?= json_encode(t('common.error_unknown'), JSON_UNESCAPED_UNICODE) ?>));
      }).catch(function(){ setBusy(false); if(outReport) outReport.textContent=<?= json_encode(t('common.error_generic'), JSON_UNESCAPED_UNICODE) ?>; });
    });
  }

  // Gov modules list
  function loadGovModules(){
    var list = document.getElementById('govModuleList');
    if (!list) return;
    list.textContent = <?= json_encode(t('gov.loading'), JSON_UNESCAPED_UNICODE) ?>;
    fetch(modulesUrl, { credentials:'include' })
      .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, j:j }; }); })
      .then(function(x){
        if (!x.ok || !x.j || !x.j.ok) { list.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; return; }
        var mods = x.j.modules || [];
        list.innerHTML = mods.map(function(m){
          return '<div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">' +
            '<div><div class="fw-semibold">' + m.label + '</div><div class="text-secondary small">' + (m.description||'') + '</div></div>' +
            '<div class="form-check form-switch mb-0">' +
              '<input class="form-check-input gov-mod-toggle" type="checkbox" data-key="' + m.key + '" ' + (m.enabled ? 'checked' : '') + '>' +
            '</div>' +
          '</div>';
        }).join('');
        list.querySelectorAll('.gov-mod-toggle').forEach(function(sw){
          sw.addEventListener('change', function(){
            postJson(modulesUrl, { action:'save', module_key: sw.getAttribute('data-key'), enabled: sw.checked ? 1 : 0 })
              .then(function(x){ if (x && x.ok && x.j && x.j.ok) { /* ok */ } })
              .catch(function(){ /* ignore */ });
          });
        });
      })
      .catch(function(){ list.textContent=<?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; });
  }
})();
</script>
</body></html>

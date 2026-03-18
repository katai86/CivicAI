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

// Status update: admin vagy govuser (a saját hatósága alá tartozó bejelentéseknél)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_status') {
  $err = null;
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
              'pending' => t('status.pending'),
              'approved' => t('status.approved'),
              'rejected' => t('status.rejected'),
              'new' => t('status.new'),
              'needs_info' => t('status.needs_info'),
              'forwarded' => t('status.forwarded'),
              'waiting_reply' => t('status.waiting_reply'),
              'in_progress' => t('status.in_progress'),
              'solved' => t('status.solved'),
              'closed' => t('status.closed'),
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

$ideaReports = [];
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
  // Lista csak adminnak (gov user csak statisztikát lát); csak display_name (level/profile_public nincs minden telepén)
  if ($showReportList) {
    try {
      $listWhere = $where;
      $listParams = $params;
      $stmt = $pdo->prepare("
        SELECT r.id, r.category, r.title, r.description, r.status, r.created_at,
               r.address_approx, r.city, r.authority_id,
               u.display_name AS reporter_display_name
        FROM reports r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE $listWhere
        ORDER BY r.created_at DESC
        LIMIT 200
      ");
      $stmt->execute($listParams);
      $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $ideaReports = array_values(array_filter($reports, function ($r) {
        return isset($r['category']) && (string)$r['category'] === 'idea';
      }));
    } catch (Throwable $e) {
      $reports = [];
      $ideaReports = [];
    }
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
} else {
  $stats['environment'] = ['trees_total' => 0, 'trees_needing_inspection' => 0, 'trees_needing_water' => 0, 'trees_dangerous' => 0, 'green_reports' => 0];
  $stats['social'] = ['active_citizens_30d' => 0, 'tree_adopters' => 0, 'green_events_active' => 0, 'watering_actions_30d' => 0];
  $stats['governance'] = ['reports_total' => 0, 'reports_open' => 0, 'reports_solved_30d' => 0, 'avg_resolution_days' => null];
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
$govSurveysEnabled = $isAdmin ? true : user_module_enabled($govUid, 'surveys');
$govBudgetEnabled = $isAdmin ? true : user_module_enabled($govUid, 'budget');
$govIotEnabled = $isAdmin ? true : user_module_enabled($govUid, 'iot');
?>
<!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="icon" type="image/png" href="<?= h(app_url('/assets/fav_icon.png')) ?>">
  <link rel="apple-touch-icon" href="<?= h(app_url('/assets/fav_icon.png')) ?>">
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
      <a href="<?= h(app_url('/')) ?>" class="brand-link d-flex align-items-center">
        <img src="<?= h(app_url('/assets/logo_dark.png')) ?>" alt="<?= h(t('site.name')) ?>" class="civic-brand-img civic-brand-img--dark" style="height:2rem;width:auto;max-width:120px;object-fit:contain">
        <img src="<?= h(app_url('/assets/logo_light.png')) ?>" alt="<?= h(t('site.name')) ?>" class="civic-brand-img civic-brand-img--light" style="height:2rem;width:auto;max-width:120px;object-fit:contain">
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column">
          <li class="nav-item">
            <a href="#" class="nav-link tab active" data-tab="dashboard">
              <i class="nav-icon bi bi-house-door-fill"></i>
              <p><?= h(t('gov.tab_dashboard')) ?></p>
            </a>
          </li>
          <li class="nav-header mt-3 mb-1 px-3 small text-uppercase text-muted sidebar-section-header" role="button" tabindex="0"><span><?= h(t('gov.nav_section_work')) ?></span><i class="bi bi-chevron-down nav-section-chevron"></i></li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="reports">
              <i class="nav-icon bi bi-flag-fill"></i>
              <p><?= h(t('gov.tab_reports')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="ideas">
              <i class="nav-icon bi bi-lightbulb-fill"></i>
              <p><?= h(t('gov.tab_ideas')) ?></p>
            </a>
          </li>
          <?php if ($govSurveysEnabled): ?>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="surveys">
              <i class="nav-icon bi bi-clipboard-pulse"></i>
              <p><?= h(t('gov.tab_surveys')) ?></p>
            </a>
          </li>
          <?php endif; ?>
          <?php if ($govBudgetEnabled): ?>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="budget">
              <i class="nav-icon bi bi-wallet2"></i>
              <p><?= h(t('gov.tab_budget') ?: 'Részvételi költségvetés') ?></p>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="trees">
              <i class="nav-icon bi bi-tree-fill"></i>
              <p><?= h(t('gov.tab_trees')) ?></p>
            </a>
          </li>
          <li class="nav-header mt-3 mb-1 px-3 small text-uppercase text-muted sidebar-section-header" role="button" tabindex="0"><span><?= h(t('gov.nav_section_insights')) ?></span><i class="bi bi-chevron-down nav-section-chevron"></i></li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="ai">
              <i class="nav-icon bi bi-robot"></i>
              <p><?= h(t('gov.tab_ai')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="analytics">
              <i class="nav-icon bi bi-graph-up-arrow"></i>
              <p><?= h(t('gov.tab_analytics')) ?></p>
            </a>
          </li>
          <?php if ($govIotEnabled): ?>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="iot">
              <i class="nav-icon bi bi-broadcast"></i>
              <p><?= h(t('gov.tab_iot')) ?></p>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-header mt-3 mb-1 px-3 small text-uppercase text-muted sidebar-section-header" role="button" tabindex="0"><span><?= h(t('gov.nav_section_city_brain')) ?></span><i class="bi bi-chevron-down nav-section-chevron"></i></li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-live">
              <i class="nav-icon bi bi-speedometer2"></i>
              <p><?= h(t('gov.city_brain_live')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-predictive">
              <i class="nav-icon bi bi-graph-up-arrow"></i>
              <p><?= h(t('gov.city_brain_predictive')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-hotspot">
              <i class="nav-icon bi bi-geo-alt-fill"></i>
              <p><?= h(t('gov.city_brain_hotspot')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-behavior">
              <i class="nav-icon bi bi-activity"></i>
              <p><?= h(t('gov.city_brain_behavior')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-environmental">
              <i class="nav-icon bi bi-cloud-sun"></i>
              <p><?= h(t('gov.city_brain_environmental')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-insights">
              <i class="nav-icon bi bi-lightbulb"></i>
              <p><?= h(t('gov.city_brain_insights')) ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="citybrain-risk">
              <i class="nav-icon bi bi-exclamation-triangle-fill"></i>
              <p><?= h(t('gov.city_brain_risk')) ?></p>
            </a>
          </li>
          <li class="nav-header mt-3 mb-1 px-3 small text-uppercase text-muted sidebar-section-header" role="button" tabindex="0"><span><?= h(t('gov.nav_section_settings')) ?></span><i class="bi bi-chevron-down nav-section-chevron"></i></li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="modules">
              <i class="nav-icon bi bi-gear"></i>
              <p><?= h(t('gov.tab_modules')) ?></p>
            </a>
          </li>
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
          <!-- Áttekintés: City Health, időjárás, statisztikák, ESG összefoglaló -->
          <p class="text-secondary small mb-2"><?= h(t('gov.panels_intro')) ?></p>
          <div class="card border-primary mb-3" id="govCityHealthCard">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.city_health_index')) ?></h6>
              <div id="govCityHealthContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
            </div>
          </div>
          <?php if (defined('WEATHER_ENABLED') && WEATHER_ENABLED): ?>
          <div class="card mb-3" id="govWeatherCard">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.weather_title') ?: 'Időjárás') ?></h6>
              <div id="govWeatherContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
            </div>
          </div>
          <?php endif; ?>
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

        </div>

        <div class="admin-tab-body" id="tab-ai" hidden>
          <p class="text-secondary small mb-3"><?= h(t('gov.tab_ai_intro') ?: 'AI Copilot, összefoglalók és jelentések az adataid alapján.') ?></p>
          <div class="card mb-3" id="govCopilotCard">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.copilot_title') ?: 'AI Copilot') ?></h6>
              <p class="text-secondary small mb-2"><?= h(t('gov.copilot_desc') ?: 'Kérdezd az adataidról: kerületek, problémák, fák, változások.') ?></p>
              <div class="d-flex flex-column gap-2">
                <textarea id="govCopilotQuestion" class="form-control" rows="2" placeholder="<?= h(t('gov.copilot_placeholder') ?: 'Pl. Mely kerületeknek van a legtöbb problémája?') ?>"></textarea>
                <button type="button" class="btn btn-primary btn-sm align-self-start" id="govCopilotSend"><?= h(t('gov.copilot_send') ?: 'Küldés') ?></button>
              </div>
              <div id="govCopilotAnswer" class="mt-3 p-2 rounded bg-light small" style="display:none; white-space: pre-wrap;"></div>
              <div id="govCopilotError" class="mt-2 text-danger small" style="display:none;"></div>
            </div>
          </div>
          <?php if ($govAiUiEnabled): ?>
          <p class="text-secondary small mb-2"><?= h(t('gov.ai_summaries_intro')) ?></p>
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
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.tree_cadastre_title') ?: 'Fa kataszter') ?></h6>
              <p class="text-secondary small mb-2"><?= h(t('gov.tree_cadastre_desc') ?: 'A feltöltött, nyilvántartott fák megjelenítése a térképen.') ?></p>
              <div id="govTreeCadastreMap" style="height:420px; width:100%; border:1px solid #dee2e6; border-radius:0.375rem;"></div>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-1"><?= h(t('gov.trees_needing_water_title')) ?></h6><br>
              <p class="text-secondary small mb-2"><?= h(t('gov.trees_needing_water_desc')) ?></p>
              <p class="mb-2"><strong><?= (int)($stats['environment']['trees_needing_water'] ?? 0) ?></strong> <?= h(t('gov.esg_trees_water')) ?></p>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btnTreesNeedingWater"><?= h(t('gov.trees_needing_water_list')) ?></button>
              <div id="treesNeedingWaterList" class="mt-2 small" style="max-height:240px;overflow:auto;" hidden></div>
            </div>
          </div>
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
              <h6 class="card-title mb-1"><?= h(t('gov.analytics_title')) ?></h6><br>
              <p class="text-secondary small mb-3"><?= h(t('gov.analytics_desc')) ?></p>
              <a href="<?= h(app_url('/api/analytics.php?format=json')) ?>" class="btn btn-outline-primary btn-sm me-2" target="_blank" rel="noopener"><?= h(t('gov.analytics_export_json')) ?></a>
              <a href="<?= h(app_url('/api/analytics.php?format=csv')) ?>" class="btn btn-outline-secondary btn-sm" download><?= h(t('gov.analytics_export_csv')) ?></a>
            </div>
          </div>
          <div class="card mb-3">
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
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.sentiment_title')) ?></h6>
              <div id="govSentimentContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.predictions_title')) ?></h6>
              <div id="govPredictionsContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.green_intelligence_title')) ?></h6>
              <div id="govGreenMetricsContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
            </div>
          </div>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.esg_command_center_title')) ?></h6>
              <p class="text-secondary small mb-2"><?= h(t('gov.esg_command_center_desc')) ?></p>
              <div id="govEsgMetricsContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading') ?: 'Betöltés...') ?></p>
              </div>
              <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                <a href="#" id="linkEsgCommandJson" class="btn btn-outline-primary btn-sm">JSON</a>
                <a href="#" id="linkEsgCommandCsv" class="btn btn-outline-secondary btn-sm" download>CSV</a>
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
              <p class="text-secondary small mb-3"><?= h(t('gov.ideas_intro') ?: 'A hatóságodhoz beérkezett ötlet bejelentések. Státusz módosítható.') ?></p>
              <?php if (empty($ideaReports)): ?>
                <p class="text-secondary small mb-0"><?= h(t('gov.no_data')) ?></p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm table-hover">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th><?= h(t('gov.reporter_name') ?: 'Bejelentő neve') ?></th>
                        <th><?= h(t('gov.report_date') ?: 'Bejelentés ideje') ?></th>
                        <th><?= h(t('gov.report_description') ?: 'Bejelentés leírása') ?></th>
                        <th><?= h(t('gov.report_address') ?: 'Bejelentés címe') ?></th>
                        <th><?= h(t('common.status') ?: 'Státusz') ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($ideaReports as $ir): ?>
                        <tr>
                          <td><?= (int)$ir['id'] ?></td>
                          <td><?= h($ir['reporter_display_name'] ?: t('gov.report_anonymous')) ?></td>
                          <td class="text-nowrap"><?= date('Y-m-d H:i', strtotime($ir['created_at'])) ?></td>
                          <td><span class="fw-semibold"><?= h($ir['title'] ?: '—') ?></span><?php if (!empty($ir['description'])): ?><br><span class="text-secondary small"><?= h(mb_strimwidth($ir['description'], 0, 120, '…')) ?></span><?php endif; ?></td>
                          <td class="text-secondary small"><?= h($ir['address_approx'] ?: $ir['city'] ?: '—') ?></td>
                          <td>
                            <form method="post" class="d-inline" onchange="this.submit()">
                              <input type="hidden" name="action" value="set_status">
                              <input type="hidden" name="id" value="<?= (int)$ir['id'] ?>">
                              <select name="status" class="form-select form-select-sm" style="min-width:140px">
                                <?php foreach ($allowedStatuses as $st): ?>
                                  <option value="<?= h($st) ?>"<?= ($ir['status'] ?? '') === $st ? ' selected' : '' ?>><?= h($statusLabels[$st] ?? $st) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($govSurveysEnabled): ?>
        <div class="admin-tab-body" id="tab-surveys" hidden>
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.tab_surveys') ?: 'Felmérések') ?></h6>
              <p class="text-secondary small mb-3"><?= h(t('gov.surveys_intro') ?: 'Aktív és lezárt felmérések, eredmények megtekintése.') ?></p>
              <div id="govSurveysList"><?= h(t('gov.loading') ?: 'Betöltés...') ?></div>
              <div id="govSurveyResults" class="mt-3" style="display:none">
                <h6 class="mb-2"><?= h(t('gov.survey_results') ?: 'Eredmények') ?></h6>
                <div id="govSurveyResultsContent"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="govSurveyResultsBack">← <?= h(t('nav.map') ?: 'Vissza') ?></button>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($govBudgetEnabled): ?>
        <div class="admin-tab-body" id="tab-budget" hidden>
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.tab_budget') ?: 'Részvételi költségvetés') ?></h6>
              <p class="text-secondary small mb-3"><?= h(t('budget.intro') ?: 'Projektek a hatóságodhoz. Szavazat szám, költség, státusz.') ?></p>
              <div id="govBudgetList"><?= h(t('gov.loading') ?: 'Betöltés...') ?></div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($govIotEnabled): ?>
        <div class="admin-tab-body" id="tab-iot" hidden>
          <div class="card mb-3">
            <div class="card-body">
              <h6 class="card-title mb-2"><?= h(t('gov.tab_iot')) ?></h6>
              <p class="text-secondary small mb-3"><?= h(t('gov.iot_intro')) ?></p>
              <div id="govIotSummary" class="row g-2 mb-3"></div>
              <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <label class="small mb-0"><?= h(t('iot.filter_provider')) ?></label>
                <select id="govIotProviderFilter" class="form-select form-select-sm" style="width:auto;">
                  <option value=""><?= h(t('iot.filter_all')) ?></option>
                </select>
                <span class="small text-secondary">|</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="govIotViewList"><?= h(t('iot.view_list')) ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="govIotViewTable"><?= h(t('iot.view_table')) ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="govIotExportCsv"><?= h(t('iot.export_csv')) ?></button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="govIotExportJson"><?= h(t('iot.export_json')) ?></button>
              </div>
              <div id="govIotCharts" class="mb-3"></div>
              <div id="govIotMap" class="rounded border" style="height:280px;min-height:200px;"></div>
              <div id="govIotDetailPanel" class="card mt-2 mb-2" style="display:none;">
                <div class="card-body py-2">
                  <div class="d-flex justify-content-between align-items-start"><h6 class="card-title small mb-1" id="govIotDetailTitle">—</h6><button type="button" class="btn btn-sm btn-close" id="govIotDetailClose" aria-label="Close"></button></div>
                  <div id="govIotDetailBody" class="small"></div>
                </div>
              </div>
              <h6 class="small mt-3 mb-2"><?= h(t('iot.sensor_list')) ?></h6>
              <div id="govIotDeviceList">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading')) ?></p>
              </div>
              <div id="govIotDeviceTable" class="table-responsive mb-2" style="display:none;"></div>
              <p class="text-secondary small mt-2 mb-0"><?= h(t('gov.iot_hint')) ?></p>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="admin-tab-body" id="tab-citybrain-live" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_live')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>
        <div class="admin-tab-body" id="tab-citybrain-predictive" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_predictive')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>
        <div class="admin-tab-body" id="tab-citybrain-hotspot" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_hotspot')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>
        <div class="admin-tab-body" id="tab-citybrain-behavior" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_behavior')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>
        <div class="admin-tab-body" id="tab-citybrain-environmental" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_environmental')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>
        <div class="admin-tab-body" id="tab-citybrain-insights" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_insights')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>
        <div class="admin-tab-body" id="tab-citybrain-risk" hidden>
          <div class="card"><div class="card-body"><h6 class="card-title"><?= h(t('gov.city_brain_risk')) ?></h6><p class="text-secondary small mb-0"><?= h(t('gov.city_brain_placeholder')) ?></p></div></div>
        </div>

        <div class="admin-tab-body" id="tab-modules" hidden>
          <div class="card">
            <div class="card-body">
              <p class="text-secondary small mb-3"><?= h(t('gov.modules_intro')) ?></p>
              <div id="govModuleList"><?= h(t('admin.load')) ?>...</div>
            </div>
          </div>
        </div>

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
  var govCopilotUrl = <?= json_encode(app_url('/api/gov_copilot.php'), JSON_UNESCAPED_SLASHES) ?>;
  var modulesUrl = <?= json_encode(app_url('/api/gov_modules.php'), JSON_UNESCAPED_SLASHES) ?>;
  var esgExportUrl = <?= json_encode(app_url('/api/esg_export.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govSurveysUrl = <?= json_encode(app_url('/api/gov_surveys.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govBudgetUrl = <?= json_encode(app_url('/api/gov_budget.php'), JSON_UNESCAPED_SLASHES) ?>;
  var weatherUrl = <?= json_encode(app_url('/api/weather.php'), JSON_UNESCAPED_SLASHES) ?>;
  var iotDevicesUrl = <?= json_encode(app_url('/api/iot_devices.php'), JSON_UNESCAPED_SLASHES) ?>;
  var virtualSensorsListUrl = <?= json_encode(app_url('/api/virtual_sensors_list.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govIotLabels = <?= json_encode([
    'total_sensors' => t('iot.total_sensors'),
    'active_sensors' => t('iot.active_sensors'),
    'stale_sensors' => t('iot.stale_sensors'),
    'avg_aqi' => t('iot.avg_aqi'),
    'avg_pm25' => t('iot.avg_pm25'),
    'avg_temperature' => t('iot.avg_temperature'),
    'no_data' => t('gov.no_data'),
    'sensor_list' => t('iot.sensor_list'),
    'sensors_by_provider' => t('iot.sensors_by_provider'),
    'filter_provider' => t('iot.filter_provider'),
    'filter_all' => t('iot.filter_all'),
    'view_list' => t('iot.view_list'),
    'view_table' => t('iot.view_table'),
    'export_csv' => t('iot.export_csv'),
    'export_json' => t('iot.export_json'),
    'label_imported_network' => t('iot.label_imported_network'),
    'label_civicai_sensor' => t('iot.label_civicai_sensor'),
    'trust_score' => t('iot.trust_score'),
    'confidence_score' => t('iot.confidence_score'),
    'freshness' => t('iot.freshness'),
    'freshness_fresh' => t('iot.freshness_fresh'),
    'freshness_ok' => t('iot.freshness_ok'),
    'freshness_stale' => t('iot.freshness_stale'),
    'provider_tier' => t('iot.provider_tier'),
    'tier_1' => t('iot.tier_1'),
    'tier_2' => t('iot.tier_2'),
    'tier_3' => t('iot.tier_3'),
  ], JSON_UNESCAPED_UNICODE) ?>;
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
  var govSentimentUrl = <?= json_encode(app_url('/api/sentiment_analysis.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govSentimentLabels = <?= json_encode([
    'positive' => t('gov.sentiment_positive'),
    'neutral' => t('gov.sentiment_neutral'),
    'negative' => t('gov.sentiment_negative'),
    'top_concerns' => t('gov.sentiment_top_concerns'),
    'emerging_issues' => t('gov.sentiment_emerging_issues'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var govEsgMetricsUrl = <?= json_encode(app_url('/api/esg_metrics.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govEsgMetricsLabels = <?= json_encode([
    'environmental' => t('gov.esg_env'),
    'social' => t('gov.esg_social'),
    'governance' => t('gov.esg_gov'),
    'tree_coverage' => t('gov.esg_metric_tree_coverage'),
    'heat_island' => t('gov.esg_metric_heat_island'),
    'water_stress' => t('gov.esg_metric_water_stress'),
    'citizen_participation' => t('gov.esg_metric_citizen_participation'),
    'volunteer_engagement' => t('gov.esg_metric_volunteer_engagement'),
    'response_transparency' => t('gov.esg_metric_response_transparency'),
    'resolution_rate' => t('gov.esg_metric_resolution_rate'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var govGreenMetricsUrl = <?= json_encode(app_url('/api/green_metrics.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govGreenMetricsLabels = <?= json_encode([
    'canopy_coverage' => t('gov.green_canopy_coverage'),
    'carbon_absorption' => t('gov.green_carbon_absorption'),
    'biodiversity_index' => t('gov.green_biodiversity_index'),
    'drought_risk' => t('gov.green_drought_risk'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var govPredictionsUrl = <?= json_encode(app_url('/api/predictions.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govPredictionsLabels = <?= json_encode([
    'predicted_issues' => t('gov.predictions_predicted_issues'),
    'risk_zones' => t('gov.predictions_risk_zones'),
    'tree_failures' => t('gov.predictions_tree_failures'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var govCityHealthUrl = <?= json_encode(app_url('/api/city_health.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govCityHealthLabels = <?= json_encode([
    'infrastructure' => t('gov.city_health_infrastructure'),
    'environment' => t('gov.city_health_environment'),
    'engagement' => t('gov.city_health_engagement'),
    'maintenance' => t('gov.city_health_maintenance'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  var mapCenterLat = <?= json_encode(defined('MAP_CENTER_LAT') ? (float)MAP_CENTER_LAT : 47.1625) ?>;
  var mapCenterLng = <?= json_encode(defined('MAP_CENTER_LNG') ? (float)MAP_CENTER_LNG : 19.5033) ?>;
  var govWeatherHumidityLabel = <?= json_encode(t('gov.weather_humidity') ?: 'Humidity', JSON_UNESCAPED_UNICODE) ?>;
  var govIotShowOnMap = <?= json_encode(t('gov.iot_show_on_map') ?: 'Show on map', JSON_UNESCAPED_UNICODE) ?>;
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
      ['dashboard','ai','reports','ideas','surveys','budget','trees','analytics','iot','citybrain-live','citybrain-predictive','citybrain-hotspot','citybrain-behavior','citybrain-environmental','citybrain-insights','citybrain-risk','modules'].forEach(function(k){
        var el = document.getElementById('tab-' + k);
        if (el) el.hidden = (k !== key);
      });
      if (key === 'modules') loadGovModules();
      if (key === 'surveys') loadGovSurveys();
      if (key === 'budget') loadGovBudget();
      if (key === 'trees') { initGovTreeCadastreMap(); loadGovTreesMap(); loadGovTrees(); }
      if (key === 'iot') loadGovIotDevices();
      if (key === 'analytics') { initGovHeatmapTab(); initGovStatisticsTab(); loadGovSentiment(); loadGovPredictions(); loadGovGreenMetrics(); loadGovEsgMetrics(); }
      if (key === 'dashboard') { loadGovCityHealth(); loadGovWeather(); }
    });
  });

  // Összecsukható menüszekciók (sidebar): kattintásra elrejti/megmutatja a szekció elemeit
  document.querySelectorAll('.app-sidebar .sidebar-section-header').forEach(function(header){
    header.addEventListener('click', function(){
      header.classList.toggle('sidebar-section-collapsed');
      var next = header.nextElementSibling;
      while (next && !next.classList.contains('nav-header')) {
        next.classList.toggle('sidebar-section-item-hidden');
        next = next.nextElementSibling;
      }
    });
    header.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); header.click(); } });
  });

  loadGovCityHealth();
  loadGovWeather();

  (function initGovCopilot(){
    var btn = document.getElementById('govCopilotSend');
    var qEl = document.getElementById('govCopilotQuestion');
    var ansEl = document.getElementById('govCopilotAnswer');
    var errEl = document.getElementById('govCopilotError');
    if (!btn || !qEl || !govCopilotUrl) return;
    btn.addEventListener('click', function(){
      var q = (qEl.value || '').trim();
      if (!q) return;
      errEl.style.display = 'none';
      errEl.textContent = '';
      ansEl.style.display = 'block';
      ansEl.textContent = <?= json_encode(t('gov.generating') ?: 'Generating...', JSON_UNESCAPED_UNICODE) ?>;
      btn.disabled = true;
      postJson(govCopilotUrl, { question: q }).then(function(x){
        btn.disabled = false;
        if (x.ok && x.j && x.j.ok && x.j.data && x.j.data.answer) {
          ansEl.textContent = x.j.data.answer;
          errEl.style.display = 'none';
        } else {
          ansEl.style.display = 'none';
          errEl.style.display = 'block';
          errEl.textContent = (x.j && x.j.error) ? x.j.error : (<?= json_encode(t('common.error_generic'), JSON_UNESCAPED_UNICODE) ?>);
        }
      }).catch(function(){
        btn.disabled = false;
        ansEl.style.display = 'none';
        errEl.style.display = 'block';
        errEl.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>;
      });
    });
  })();

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

  function loadGovSentiment(){
    var container = document.getElementById('govSentimentContent');
    if (!container || !govSentimentUrl) return;
    var from = new Date(); from.setDate(from.getDate() - 30);
    var to = new Date();
    var params = 'date_from=' + from.toISOString().slice(0, 10) + '&date_to=' + to.toISOString().slice(0, 10);
    fetch(govSentimentUrl + '?' + params, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      var noData = (typeof govStatisticsLabels !== 'undefined' && govStatisticsLabels.no_data) ? govStatisticsLabels.no_data : '—';
      if (!j.ok || !j.data) { container.innerHTML = '<p class="text-secondary small mb-0">' + noData + '</p>'; return; }
      var d = j.data;
      var pos = parseInt(d.positive_percent, 10) || 0, neu = parseInt(d.neutral_percent, 10) || 0, neg = parseInt(d.negative_percent, 10) || 0;
      var html = '<div class="admin-chart mb-2">';
      html += '<div class="admin-chart-bar"><span class="label">' + (typeof govSentimentLabels !== 'undefined' ? govSentimentLabels.positive : 'Pozitív') + '</span><div class="bar-wrap"><div class="bar" style="width:' + pos + '%;background:#198754"></div></div><span class="val">' + pos + '%</span></div>';
      html += '<div class="admin-chart-bar"><span class="label">' + (typeof govSentimentLabels !== 'undefined' ? govSentimentLabels.neutral : 'Semleges') + '</span><div class="bar-wrap"><div class="bar" style="width:' + neu + '%;background:#6c757d"></div></div><span class="val">' + neu + '%</span></div>';
      html += '<div class="admin-chart-bar"><span class="label">' + (typeof govSentimentLabels !== 'undefined' ? govSentimentLabels.negative : 'Negatív') + '</span><div class="bar-wrap"><div class="bar" style="width:' + neg + '%;background:#dc3545"></div></div><span class="val">' + neg + '%</span></div>';
      html += '</div>';
      if (Array.isArray(d.top_concerns) && d.top_concerns.length > 0) {
        html += '<p class="small mb-1 fw-semibold">' + (typeof govSentimentLabels !== 'undefined' ? govSentimentLabels.top_concerns : 'Fő témák') + '</p><ul class="small mb-2">';
        d.top_concerns.forEach(function(c){ html += '<li>' + (typeof escStr === 'function' ? escStr(c) : String(c).replace(/&/g,'&amp;').replace(/</g,'&lt;')) + '</li>'; });
        html += '</ul>';
      }
      if (Array.isArray(d.emerging_issues) && d.emerging_issues.length > 0) {
        html += '<p class="small mb-1 fw-semibold">' + (typeof govSentimentLabels !== 'undefined' ? govSentimentLabels.emerging_issues : 'Felmerülő témák') + '</p><ul class="small mb-0">';
        d.emerging_issues.forEach(function(e){ html += '<li>' + (typeof escStr === 'function' ? escStr(e) : String(e).replace(/&/g,'&amp;').replace(/</g,'&lt;')) + '</li>'; });
        html += '</ul>';
      }
      container.innerHTML = html;
    }).catch(function(){ var c = document.getElementById('govSentimentContent'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  function loadGovPredictions(){
    var container = document.getElementById('govPredictionsContent');
    if (!container || !govPredictionsUrl) return;
    fetch(govPredictionsUrl, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      var L = govPredictionsLabels || {};
      var noData = (typeof govStatisticsLabels !== 'undefined' && govStatisticsLabels.no_data) ? govStatisticsLabels.no_data : '—';
      if (!j.ok || !j.data) { container.innerHTML = '<p class="text-secondary small mb-0">' + noData + '</p>'; return; }
      var d = j.data;
      var issues = Array.isArray(d.predicted_issues) ? d.predicted_issues : [];
      var zones = Array.isArray(d.risk_zones) ? d.risk_zones : [];
      var trees = Array.isArray(d.predicted_tree_failures) ? d.predicted_tree_failures : [];
      var html = '<p class="small mb-2">' + (L.predicted_issues || 'Predicted issues') + ': <b>' + issues.length + '</b> · ' + (L.risk_zones || 'Risk zones') + ': <b>' + zones.length + '</b> · ' + (L.tree_failures || 'Tree risk') + ': <b>' + trees.length + '</b></p>';
      if (issues.length > 0) {
        html += '<p class="small mb-1 fw-semibold">' + (L.predicted_issues || '') + '</p><ul class="small mb-2">';
        issues.slice(0, 5).forEach(function(x){ html += '<li>' + (x.category || '') + ' ' + (x.risk_level || '') + ' (' + (x.lat || '') + ', ' + (x.lng || '') + ')</li>'; });
        if (issues.length > 5) html += '<li class="text-secondary">… +' + (issues.length - 5) + '</li>';
        html += '</ul>';
      }
      if (trees.length > 0) {
        html += '<p class="small mb-1 fw-semibold">' + (L.tree_failures || '') + '</p><ul class="small mb-0">';
        trees.slice(0, 5).forEach(function(x){ html += '<li>#' + (x.tree_id || '') + ' ' + (x.risk || '') + '</li>'; });
        if (trees.length > 5) html += '<li class="text-secondary">… +' + (trees.length - 5) + '</li>';
        html += '</ul>';
      }
      if (issues.length === 0 && trees.length === 0) html += '<p class="text-secondary small mb-0">' + noData + '</p>';
      container.innerHTML = html;
    }).catch(function(){ var c = document.getElementById('govPredictionsContent'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  function loadGovGreenMetrics(){
    var container = document.getElementById('govGreenMetricsContent');
    if (!container || !govGreenMetricsUrl) return;
    fetch(govGreenMetricsUrl, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      var L = govGreenMetricsLabels || {};
      var noData = (typeof govStatisticsLabels !== 'undefined' && govStatisticsLabels.no_data) ? govStatisticsLabels.no_data : '—';
      if (!j.ok || !j.data) { container.innerHTML = '<p class="text-secondary small mb-0">' + noData + '</p>'; return; }
      var d = j.data;
      var canopy = (d.canopy_coverage != null) ? Math.round(parseFloat(d.canopy_coverage) * 100) : 0;
      var carbon = (d.carbon_absorption != null) ? parseFloat(d.carbon_absorption) : 0;
      var bio = (d.biodiversity_index != null) ? Math.round(parseFloat(d.biodiversity_index) * 100) : 0;
      var drought = (d.drought_risk != null) ? Math.round(parseFloat(d.drought_risk) * 100) : 0;
      var html = '<div class="row g-2 small">';
      html += '<div class="col-6"><span class="text-secondary">' + (L.canopy_coverage || 'Canopy') + '</span><br><b>' + canopy + '%</b></div>';
      html += '<div class="col-6"><span class="text-secondary">' + (L.carbon_absorption || 'CO2') + '</span><br><b>' + carbon + ' t/év</b></div>';
      html += '<div class="col-6"><span class="text-secondary">' + (L.biodiversity_index || 'Biodiverzitás') + '</span><br><b>' + bio + '%</b></div>';
      html += '<div class="col-6"><span class="text-secondary">' + (L.drought_risk || 'Szárazság kockázat') + '</span><br><b>' + drought + '%</b></div>';
      html += '</div>';
      container.innerHTML = html;
    }).catch(function(){ var c = document.getElementById('govGreenMetricsContent'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  function loadGovEsgMetrics(){
    var container = document.getElementById('govEsgMetricsContent');
    if (!container || !govEsgMetricsUrl) return;
    fetch(govEsgMetricsUrl, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      var L = govEsgMetricsLabels || {};
      var noData = (typeof govStatisticsLabels !== 'undefined' && govStatisticsLabels.no_data) ? govStatisticsLabels.no_data : '—';
      if (!j.ok || !j.data) { container.innerHTML = '<p class="text-secondary small mb-0">' + noData + '</p>'; return; }
      var d = j.data;
      var env = d.environmental || {};
      var soc = d.social || {};
      var gov = d.governance || {};
      var html = '<div class="row g-2 small">';
      html += '<div class="col-md-4"><div class="border rounded p-2 bg-light"><div class="fw-semibold text-success">' + (L.environmental || 'E') + '</div>';
      html += (L.tree_coverage || 'Tree coverage') + ': ' + Math.round((env.tree_coverage || 0) * 100) + '%<br>';
      html += (L.heat_island || 'Heat island') + ': ' + Math.round((env.heat_island_index || 0) * 100) + '%<br>';
      html += (L.water_stress || 'Water stress') + ': ' + Math.round((env.water_stress || 0) * 100) + '%</div></div>';
      html += '<div class="col-md-4"><div class="border rounded p-2 bg-light"><div class="fw-semibold text-primary">' + (L.social || 'S') + '</div>';
      html += (L.citizen_participation || 'Participation') + ': ' + Math.round((soc.citizen_participation || 0) * 100) + '%<br>';
      html += (L.volunteer_engagement || 'Volunteers') + ': ' + Math.round((soc.volunteer_engagement || 0) * 100) + '%</div></div>';
      html += '<div class="col-md-4"><div class="border rounded p-2 bg-light"><div class="fw-semibold text-secondary">' + (L.governance || 'G') + '</div>';
      html += (L.response_transparency || 'Transparency') + ': ' + Math.round((gov.response_transparency || 0) * 100) + '%<br>';
      html += (L.resolution_rate || 'Resolution') + ': ' + Math.round((gov.resolution_rate || 0) * 100) + '%</div></div>';
      html += '</div>';
      container.innerHTML = html;
      var y = (document.getElementById('govEsgYear') || {}).value || new Date().getFullYear();
      if (document.getElementById('linkEsgCommandJson')) document.getElementById('linkEsgCommandJson').href = esgExportUrl + '?year=' + y + '&format=json';
      if (document.getElementById('linkEsgCommandCsv')) document.getElementById('linkEsgCommandCsv').href = esgExportUrl + '?year=' + y + '&format=csv';
    }).catch(function(){ var c = document.getElementById('govEsgMetricsContent'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  function loadGovCityHealth(){
    var container = document.getElementById('govCityHealthContent');
    if (!container || !govCityHealthUrl) return;
    fetch(govCityHealthUrl, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      var L = govCityHealthLabels || {};
      if (!j.ok || !j.data) {
        var msg = (j && j.error) ? j.error : (typeof govStatisticsLabels !== 'undefined' && govStatisticsLabels.no_data ? govStatisticsLabels.no_data : '—');
        container.innerHTML = '<p class="text-secondary small mb-0">' + (msg.replace(/</g,'&lt;')) + '</p>';
        return;
      }
      var d = j.data;
      var overall = (d.city_health_score != null) ? parseInt(d.city_health_score, 10) : 0;
      var html = '<div class="d-flex align-items-center flex-wrap gap-3 mb-3"><span class="display-4 fw-bold text-primary">' + overall + '</span><span class="text-secondary small">/ 100</span></div>';
      html += '<div class="admin-chart">';
      ['infrastructure','environment','engagement','maintenance'].forEach(function(k){
        var score = (d[k + '_score'] != null) ? parseInt(d[k + '_score'], 10) : 0;
        var label = L[k] || k;
        html += '<div class="admin-chart-bar"><span class="label">' + label + '</span><div class="bar-wrap"><div class="bar" style="width:' + Math.min(100, score) + '%;background:#0d6efd"></div></div><span class="val">' + score + '</span></div>';
      });
      html += '</div>';
      container.innerHTML = html;
    }).catch(function(){ var c = document.getElementById('govCityHealthContent'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  function loadGovWeather(){
    var container = document.getElementById('govWeatherContent');
    if (!container || !weatherUrl) return;
    fetch(weatherUrl, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok || !j.data) {
        container.innerHTML = '<p class="text-secondary small mb-0">' + (j && j.error ? String(j.error).replace(/</g,'&lt;') : '—') + '</p>';
        return;
      }
      var d = j.data;
      var temp = d.temp != null ? d.temp + ' °C' : '—';
      var humidity = d.humidity != null ? d.humidity + '%' : '—';
      var desc = (d.description || '—').replace(/</g,'&lt;');
      container.innerHTML = '<div class="d-flex flex-wrap gap-3 align-items-center"><span class="fs-4 fw-bold">' + temp + '</span><span class="text-secondary small">' + desc + '</span><span class="text-secondary small">' + (typeof govWeatherHumidityLabel !== 'undefined' ? govWeatherHumidityLabel : 'Páratartalom') + ': ' + humidity + '</span></div>';
    }).catch(function(){ var c = document.getElementById('govWeatherContent'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  var govIotMap = null;
  var govIotMarkers = [];
  var govIotSensorsCache = [];
  var govIotSummaryCache = null;
  function getGovIotOwnershipLabel(s){ var L = govIotLabels || {}; return ((s.ownership_type || '').toLowerCase() === 'civicai') ? (L.label_civicai_sensor || 'CivicAI Sensor') : (L.label_imported_network || 'Imported Network'); }
  function getGovIotFreshness(lastSeenAt){ var L = govIotLabels || {}; if (!lastSeenAt) return { label: L.freshness_stale || 'Stale', class: 'text-secondary' }; var h = (Date.now() - new Date(lastSeenAt).getTime()) / 3600000; if (h < 1) return { label: L.freshness_fresh || 'Fresh', class: 'text-success' }; if (h < 24) return { label: L.freshness_ok || 'OK', class: 'text-primary' }; return { label: L.freshness_stale || 'Stale', class: 'text-secondary' }; }
  function getGovIotProviderTier(provider){ var p = (provider || '').toLowerCase(); var tier = (p === 'openaq') ? 1 : (p === 'sensor_community' || p === 'sensorcommunity') ? 3 : 2; var L = govIotLabels || {}; return L['tier_' + tier] || ('Tier ' + tier); }
  function govIotFilteredSensors(){
    var sel = document.getElementById('govIotProviderFilter');
    var p = (sel && sel.value) ? sel.value.trim().toLowerCase() : '';
    if (!p) return govIotSensorsCache;
    return govIotSensorsCache.filter(function(s){ return (s.source_provider || '').toLowerCase() === p; });
  }
  function showGovIotDetail(sensor){
    var panel = document.getElementById('govIotDetailPanel');
    var titleEl = document.getElementById('govIotDetailTitle');
    var bodyEl = document.getElementById('govIotDetailBody');
    if (!panel || !titleEl || !bodyEl) return;
    var name = (sensor.name || sensor.source_provider + ' #' + (sensor.external_station_id || '')).replace(/</g,'&lt;');
    titleEl.textContent = name;
    var rows = [];
    var L = govIotLabels || {};
    rows.push('<p class="mb-1"><strong>Provider:</strong> ' + (sensor.source_provider || '—').replace(/</g,'&lt;') + ' <span class="badge bg-secondary ms-1">' + getGovIotOwnershipLabel(sensor).replace(/</g,'&lt;') + '</span></p>');
    rows.push('<p class="mb-1"><strong>Municipality:</strong> ' + (sensor.municipality || '—').replace(/</g,'&lt;') + '</p>');
    rows.push('<p class="mb-1"><strong>Last seen:</strong> ' + (sensor.last_seen_at || '—').replace(/</g,'&lt;') + '</p>');
    var fr = getGovIotFreshness(sensor.last_seen_at); rows.push('<p class="mb-1"><strong>' + (L.freshness || 'Freshness') + ':</strong> <span class="' + fr.class + '">' + fr.label.replace(/</g,'&lt;') + '</span></p>');
    if (sensor.trust_score != null) rows.push('<p class="mb-1"><strong>' + (L.trust_score || 'Trust') + ':</strong> ' + sensor.trust_score + '</p>');
    if (sensor.confidence_score != null) rows.push('<p class="mb-1"><strong>' + (L.confidence_score || 'Confidence') + ':</strong> ' + sensor.confidence_score + '</p>');
    rows.push('<p class="mb-1"><strong>' + (L.provider_tier || 'Provider tier') + ':</strong> ' + getGovIotProviderTier(sensor.source_provider).replace(/</g,'&lt;') + '</p>');
    if (sensor.lat != null && sensor.lng != null) rows.push('<p class="mb-1"><strong>Coordinates:</strong> ' + sensor.lat + ', ' + sensor.lng + '</p>');
    if (sensor.metrics && Object.keys(sensor.metrics).length > 0) {
      rows.push('<table class="table table-sm mt-2"><thead><tr><th>Metric</th><th>Value</th><th>Unit</th></tr></thead><tbody>');
      for (var k in sensor.metrics) {
        var v = sensor.metrics[k];
        var val = (v && typeof v === 'object' && v.value != null) ? v.value : (v || '—');
        var unit = (v && typeof v === 'object' && v.unit) ? v.unit : '';
        rows.push('<tr><td>' + String(k).replace(/</g,'&lt;') + '</td><td>' + val + '</td><td>' + String(unit).replace(/</g,'&lt;') + '</td></tr>');
      }
      rows.push('</tbody></table>');
    }
    bodyEl.innerHTML = rows.join('');
    panel.style.display = 'block';
  }
  function renderGovIotFiltered(sensors){
    var listEl = document.getElementById('govIotDeviceList');
    var summaryEl = document.getElementById('govIotSummary');
    var mapEl = document.getElementById('govIotMap');
    var chartsEl = document.getElementById('govIotCharts');
    var tableEl = document.getElementById('govIotDeviceTable');
    var Lbl = govIotLabels || {};
    var summary = govIotSummaryCache || {};
    if (summaryEl) {
      summaryEl.innerHTML = '<div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.total_sensors || 'Összes') + '</h6><p class="mb-0 fw-bold">' + summary.total + '</p></div></div></div>' +
        '<div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.active_sensors || 'Aktív') + '</h6><p class="mb-0 fw-bold">' + summary.active + '</p></div></div></div>' +
        '<div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.avg_aqi || 'Átlag AQI') + '</h6><p class="mb-0 fw-bold">' + (summary.avg_aqi != null ? summary.avg_aqi : '—') + '</p></div></div></div>' +
        '<div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.avg_pm25 || 'PM2.5') + '</h6><p class="mb-0 fw-bold">' + (summary.avg_pm25 != null ? summary.avg_pm25 + ' µg/m³' : '—') + '</p></div></div></div>' +
        '<div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.avg_temperature || 'Hőm.') + '</h6><p class="mb-0 fw-bold">' + (summary.avg_temperature != null ? summary.avg_temperature + ' °C' : '—') + '</p></div></div></div>';
    }
    if (sensors.length === 0) {
      if (summaryEl) summaryEl.innerHTML = '<div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.total_sensors || 'Összes') + '</h6><p class="mb-0 fw-bold">0</p></div></div></div><div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.active_sensors || 'Aktív') + '</h6><p class="mb-0 fw-bold">0</p></div></div></div><div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.avg_aqi || 'Átlag AQI') + '</h6><p class="mb-0 fw-bold">—</p></div></div></div><div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.avg_pm25 || 'PM2.5') + '</h6><p class="mb-0 fw-bold">—</p></div></div></div><div class="col-md-2"><div class="card"><div class="card-body py-2"><h6 class="card-title small mb-0">' + (Lbl.avg_temperature || 'Hőm.') + '</h6><p class="mb-0 fw-bold">—</p></div></div></div>';
      if (listEl) listEl.innerHTML = '<p class="text-secondary small mb-0">' + (Lbl.no_data || '—') + '</p>';
      if (chartsEl) chartsEl.innerHTML = '';
      if (tableEl) tableEl.innerHTML = '';
      if (mapEl && govIotMap) { govIotMarkers.forEach(function(m){ govIotMap.removeLayer(m); }); govIotMarkers = []; }
      return;
    }
    var html = '<ul class="list-unstyled mb-0">';
    sensors.forEach(function(s){
      var name = (s.name || s.source_provider + ' #' + s.external_station_id).replace(/</g,'&lt;');
      var prov = (s.source_provider || '').replace(/</g,'&lt;');
      var last = s.last_seen_at ? s.last_seen_at.replace(/</g,'&lt;') : '—';
      var aqi = (s.metrics && s.metrics.aqi && s.metrics.aqi.value != null) ? s.metrics.aqi.value : '—';
      var ownershipLabel = getGovIotOwnershipLabel(s).replace(/</g,'&lt;');
      html += '<li class="d-flex align-items-center justify-content-between border-bottom py-2 gov-iot-sensor-row" style="cursor:pointer;" data-index="' + govIotSensorsCache.indexOf(s) + '"><div><strong>' + name + '</strong> <span class="text-secondary small">' + prov + '</span> <span class="badge bg-secondary badge-sm">' + ownershipLabel + '</span><br><span class="text-muted small">AQI: ' + aqi + ' · ' + last + '</span></div></li>';
    });
    html += '</ul>';
    if (listEl) listEl.innerHTML = html;
    listEl && listEl.querySelectorAll('.gov-iot-sensor-row').forEach(function(li){
      var idx = parseInt(li.getAttribute('data-index'), 10);
      var s = govIotSensorsCache[idx];
      if (s) li.addEventListener('click', function(){ showGovIotDetail(s); });
    });
    if (chartsEl) {
      var byProvider = {};
      sensors.forEach(function(s){ var p = (s.source_provider || 'other').toLowerCase(); byProvider[p] = (byProvider[p] || 0) + 1; });
      var maxP = Math.max(1, Math.max.apply(null, Object.values(byProvider)));
      var chartHtml = '<h6 class="small mb-2">' + (Lbl.sensors_by_provider || 'Sensors by provider') + '</h6><div class="admin-chart">';
      Object.keys(byProvider).sort().forEach(function(p){
        chartHtml += '<div class="admin-chart-bar"><span class="label">' + String(p).replace(/</g,'&lt;') + '</span><div class="bar-wrap"><div class="bar" style="width:' + Math.round(100 * byProvider[p] / maxP) + '%;background:#0d6efd"></div></div><span class="val">' + byProvider[p] + '</span></div>';
      });
      chartHtml += '</div>';
      chartsEl.innerHTML = chartHtml;
    }
    if (tableEl && tableEl.style.display !== 'none') {
      var tb = '<table class="table table-sm"><thead><tr><th>Name</th><th>Provider</th><th>Ownership</th><th>Municipality</th><th>Last seen</th><th>Freshness</th><th>AQI</th><th>PM2.5</th></tr></thead><tbody>';
      sensors.forEach(function(s){
        var aqi = (s.metrics && s.metrics.aqi && s.metrics.aqi.value != null) ? s.metrics.aqi.value : '—';
        var pm25 = (s.metrics && s.metrics.pm25 && s.metrics.pm25.value != null) ? s.metrics.pm25.value : '—';
        var ownershipLabel = getGovIotOwnershipLabel(s).replace(/</g,'&lt;');
        var fr = getGovIotFreshness(s.last_seen_at);
        tb += '<tr class="gov-iot-table-row" style="cursor:pointer;" data-index="' + govIotSensorsCache.indexOf(s) + '"><td>' + (s.name || '').replace(/</g,'&lt;') + '</td><td>' + (s.source_provider || '').replace(/</g,'&lt;') + '</td><td><span class="badge bg-secondary">' + ownershipLabel + '</span></td><td>' + (s.municipality || '').replace(/</g,'&lt;') + '</td><td>' + (s.last_seen_at || '—').replace(/</g,'&lt;') + '</td><td><span class="' + fr.class + ' small">' + fr.label.replace(/</g,'&lt;') + '</span></td><td>' + aqi + '</td><td>' + pm25 + '</td></tr>';
      });
      tb += '</tbody></table>';
      tableEl.innerHTML = tb;
      tableEl.querySelectorAll('.gov-iot-table-row').forEach(function(tr){
        var idx = parseInt(tr.getAttribute('data-index'), 10);
        var s = govIotSensorsCache[idx];
        if (s) tr.addEventListener('click', function(){ showGovIotDetail(s); });
      });
    }
    if (mapEl && typeof L !== 'undefined') {
      if (!govIotMap) {
        govIotMap = L.map('govIotMap').setView([typeof mapCenterLat !== 'undefined' ? mapCenterLat : 46.56, typeof mapCenterLng !== 'undefined' ? mapCenterLng : 20.67], 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(govIotMap);
      }
      govIotMarkers.forEach(function(m){ if (govIotMap) govIotMap.removeLayer(m); });
      govIotMarkers = [];
      var bounds = [];
      sensors.forEach(function(s){
        if (s.lat != null && s.lng != null) {
          var mk = L.marker([s.lat, s.lng]).addTo(govIotMap);
          var pop = (s.name || s.source_provider).replace(/</g,'&lt;') + '<br><small>' + (s.municipality || '') + '</small><br><span class="badge bg-secondary">' + getGovIotOwnershipLabel(s).replace(/</g,'&lt;') + '</span>';
          if (s.metrics && s.metrics.aqi && s.metrics.aqi.value != null) pop += '<br>AQI: ' + s.metrics.aqi.value;
          pop += '<br><a href="#" class="gov-iot-detail-link" data-index="' + govIotSensorsCache.indexOf(s) + '">Details</a>';
          mk.bindPopup(pop);
          mk.on('popupopen', function(){
            var wrapper = mk.getPopup().getElement();
            if (wrapper) {
              var link = wrapper.querySelector('.gov-iot-detail-link');
              if (link) link.addEventListener('click', function(e){ e.preventDefault(); showGovIotDetail(govIotSensorsCache[parseInt(link.getAttribute('data-index'),10)]); });
            }
          });
          govIotMarkers.push(mk);
          bounds.push([s.lat, s.lng]);
        }
      });
      if (bounds.length > 0 && govIotMap) govIotMap.fitBounds(bounds, { padding: [20, 20] });
    }
  }
  function loadGovIotDevices(){
    var listEl = document.getElementById('govIotDeviceList');
    var summaryEl = document.getElementById('govIotSummary');
    var mapEl = document.getElementById('govIotMap');
    var url = virtualSensorsListUrl + (typeof authorityIdForHeatmap !== 'undefined' && authorityIdForHeatmap > 0 ? '?authority_id=' + authorityIdForHeatmap : '');
    if (!listEl) return;
    if (summaryEl) summaryEl.innerHTML = '';
    listEl.innerHTML = '<p class="text-secondary small mb-0">' + (govIotLabels && govIotLabels.no_data ? govIotLabels.no_data : '') + '</p>';
    fetch(url, { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok) {
        listEl.innerHTML = '<p class="text-danger small">' + (j && j.error ? String(j.error).replace(/</g,'&lt;') : '—') + '</p>';
        return;
      }
      govIotSensorsCache = j.sensors || [];
      govIotSummaryCache = j.summary || {};
      var Lbl = govIotLabels || {};
      var filterSel = document.getElementById('govIotProviderFilter');
      if (filterSel) {
        var opts = filterSel.innerHTML;
        filterSel.innerHTML = '<option value="">' + (Lbl.filter_all || 'All') + '</option>';
        var providers = {};
        govIotSensorsCache.forEach(function(s){ var p = (s.source_provider || '').toLowerCase(); if (p) providers[p] = true; });
        Object.keys(providers).sort().forEach(function(p){ filterSel.innerHTML += '<option value="' + p.replace(/"/g,'&quot;') + '">' + p.replace(/</g,'&lt;') + '</option>'; });
      }
      renderGovIotFiltered(govIotFilteredSensors());
    }).catch(function(){ var c = document.getElementById('govIotDeviceList'); if (c) c.innerHTML = '<p class="text-danger small">—</p>'; });
  }
  document.getElementById('govIotProviderFilter') && document.getElementById('govIotProviderFilter').addEventListener('change', function(){ renderGovIotFiltered(govIotFilteredSensors()); });
  document.getElementById('govIotDetailClose') && document.getElementById('govIotDetailClose').addEventListener('click', function(){ var p = document.getElementById('govIotDetailPanel'); if (p) p.style.display = 'none'; });
  document.getElementById('govIotViewList') && document.getElementById('govIotViewList').addEventListener('click', function(){
    var listEl = document.getElementById('govIotDeviceList'); var tableEl = document.getElementById('govIotDeviceTable');
    if (listEl) listEl.style.display = ''; if (tableEl) tableEl.style.display = 'none';
  });
  document.getElementById('govIotViewTable') && document.getElementById('govIotViewTable').addEventListener('click', function(){
    var listEl = document.getElementById('govIotDeviceList'); var tableEl = document.getElementById('govIotDeviceTable');
    if (listEl) listEl.style.display = 'none'; if (tableEl) { tableEl.style.display = ''; tableEl.innerHTML = ''; renderGovIotFiltered(govIotFilteredSensors()); }
  });
  document.getElementById('govIotExportCsv') && document.getElementById('govIotExportCsv').addEventListener('click', function(){
    var sensors = govIotFilteredSensors();
    if (sensors.length === 0) return;
    var head = 'Name,Provider,Ownership,Municipality,Lat,Lng,Last seen,Freshness,AQI,PM2.5,Temperature\n';
    var csv = head + sensors.map(function(s){
      var aqi = (s.metrics && s.metrics.aqi && s.metrics.aqi.value != null) ? s.metrics.aqi.value : '';
      var pm25 = (s.metrics && s.metrics.pm25 && s.metrics.pm25.value != null) ? s.metrics.pm25.value : '';
      var temp = (s.metrics && (s.metrics.temperature || s.metrics.temp)) ? ((s.metrics.temperature || s.metrics.temp).value != null ? (s.metrics.temperature || s.metrics.temp).value : '') : '';
      var ownership = getGovIotOwnershipLabel(s).replace(/"/g,'""');
      var fr = getGovIotFreshness(s.last_seen_at);
      return '"' + (s.name || '').replace(/"/g,'""') + '","' + (s.source_provider || '').replace(/"/g,'""') + '","' + ownership + '","' + (s.municipality || '').replace(/"/g,'""') + '",' + (s.lat || '') + ',' + (s.lng || '') + ',"' + (s.last_seen_at || '').replace(/"/g,'""') + '","' + fr.label.replace(/"/g,'""') + '",' + aqi + ',' + pm25 + ',' + temp;
    }).join('\n');
    var a = document.createElement('a'); a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv); a.download = 'virtual_sensors.csv'; a.click();
  });
  document.getElementById('govIotExportJson') && document.getElementById('govIotExportJson').addEventListener('click', function(){
    var sensors = govIotFilteredSensors();
    if (sensors.length === 0) return;
    var a = document.createElement('a'); a.href = 'data:application/json;charset=utf-8,' + encodeURIComponent(JSON.stringify({ sensors: sensors })); a.download = 'virtual_sensors.json'; a.click();
  });
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
  var govTreeCadastreMap = null;
  var govTreeCadastreLayer = null;
  function initGovTreeCadastreMap(){
    var container = document.getElementById('govTreeCadastreMap');
    if (!container || typeof L === 'undefined') return;
    if (govTreeCadastreMap) {
      govTreeCadastreMap.invalidateSize();
      return;
    }
    govTreeCadastreMap = L.map('govTreeCadastreMap').setView([mapCenterLat, mapCenterLng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OSM' }).addTo(govTreeCadastreMap);
    govTreeCadastreLayer = L.layerGroup().addTo(govTreeCadastreMap);
  }
  function loadGovTreesMap(){
    if (!govTreeCadastreMap || !govTreeCadastreLayer || !govTreesListUrl) return;
    govTreeCadastreLayer.clearLayers();
    fetch(govTreesListUrl + '?limit=500&offset=0', { credentials: 'include' }).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok || !Array.isArray(j.data)) return;
      var bounds = [];
      j.data.forEach(function(t){
        var lat = parseFloat(t.lat);
        var lng = parseFloat(t.lng);
        if (!isFinite(lat) || !isFinite(lng)) return;
        var serial = 'T' + String(Number(t.id)).padStart(4, '0');
        var sp = (t.species || '').trim() || '–';
        var addr = (t.address || '').trim();
        var popup = '<strong>' + (typeof escStr === 'function' ? escStr(serial) : serial) + '</strong> ' + (typeof escStr === 'function' ? escStr(sp) : sp);
        if (addr) popup += '<br><span class="text-secondary small">' + (typeof escStr === 'function' ? escStr(addr.substring(0, 60) + (addr.length > 60 ? '…' : '')) : addr) + '</span>';
        var m = L.marker([lat, lng]).bindPopup(popup);
        govTreeCadastreLayer.addLayer(m);
        bounds.push([lat, lng]);
      });
      if (bounds.length > 0 && govTreeCadastreMap) govTreeCadastreMap.fitBounds(bounds, { maxZoom: 15, padding: [30, 30] });
    }).catch(function(){});
  }
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

  function loadGovSurveys(){
    var list = document.getElementById('govSurveysList');
    var resultsWrap = document.getElementById('govSurveyResults');
    var resultsContent = document.getElementById('govSurveyResultsContent');
    if (!list || !govSurveysUrl) return;
    list.textContent = <?= json_encode(t('gov.loading'), JSON_UNESCAPED_UNICODE) ?>;
    fetch(govSurveysUrl, { credentials:'include' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok) { list.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; return; }
        var data = j.data || [];
        var firstAid = j.first_authority_id || 0;
        var html = '<div class="mb-3"><button type="button" class="btn btn-sm btn-outline-primary" id="govSurveyNewBtn">' + (<?= json_encode(t('gov.survey_new') ?: 'Új felmérés', JSON_UNESCAPED_UNICODE) ?>) + '</button></div>';
        if (!data.length) {
          list.innerHTML = html + '<p class="text-secondary small mb-0">' + (<?= json_encode(t('gov.no_data'), JSON_UNESCAPED_UNICODE) ?>) + '</p>' +
            '<p class="text-muted small mt-2 mb-0">' + (<?= json_encode(t('gov.surveys_empty_hint') ?: 'A felmérések táblák létrehozásához futtasd a migrációt (surveys).', JSON_UNESCAPED_UNICODE) ?>) + '</p>';
          document.getElementById('govSurveyNewBtn') && document.getElementById('govSurveyNewBtn').addEventListener('click', function(){ showGovSurveyNewForm(list, firstAid); });
          return;
        }
        var statusLabels = { draft: <?= json_encode(t('survey.status_draft') ?: 'Piszkozat', JSON_UNESCAPED_UNICODE) ?>, active: <?= json_encode(t('survey.status_active') ?: 'Aktív', JSON_UNESCAPED_UNICODE) ?>, closed: <?= json_encode(t('survey.status_closed') ?: 'Lezárva', JSON_UNESCAPED_UNICODE) ?> };
        html += '<table class="table table-sm table-hover"><thead><tr><th>#</th><th>' + (<?= json_encode(t('idea.title_placeholder') ?: 'Cím', JSON_UNESCAPED_UNICODE) ?>) + '</th><th>' + (<?= json_encode(t('common.status'), JSON_UNESCAPED_UNICODE) ?>) + '</th><th>' + (<?= json_encode(t('gov.survey_responses') ?: 'Válaszok', JSON_UNESCAPED_UNICODE) ?>) + '</th><th></th></tr></thead><tbody>' +
          data.map(function(s){
            return '<tr><td>' + s.id + '</td><td><strong>' + (s.title||'').replace(/</g,'&lt;') + '</strong><br><span class="text-muted small">' + (s.authority_name||'').replace(/</g,'&lt;') + ' · ' + (s.starts_at||'').slice(0,16) + ' – ' + (s.ends_at||'').slice(0,16) + '</span></td><td>' + (statusLabels[s.status]||s.status) + '</td><td>' + (s.response_count||0) + '</td><td><button type="button" class="btn btn-sm btn-outline-primary gov-survey-results" data-id="' + s.id + '">' + (<?= json_encode(t('gov.survey_results') ?: 'Eredmények', JSON_UNESCAPED_UNICODE) ?>) + '</button></td></tr>';
          }).join('') + '</tbody></table>';
        list.innerHTML = html;
        list.querySelectorAll('.gov-survey-results').forEach(function(btn){
          btn.addEventListener('click', function(){ showGovSurveyResults(btn.getAttribute('data-id')); });
        });
        document.getElementById('govSurveyNewBtn') && document.getElementById('govSurveyNewBtn').addEventListener('click', function(){ showGovSurveyNewForm(list, firstAid); });
      })
      .catch(function(){ list.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; });
    if (resultsWrap) resultsWrap.style.display = 'none';
  }
  function showGovSurveyNewForm(container, firstAid){
    var today = new Date().toISOString().slice(0, 16);
    var end = new Date(); end.setDate(end.getDate() + 30);
    var endStr = end.toISOString().slice(0, 16);
    var statusL = { draft: <?= json_encode(t('survey.status_draft') ?: 'Piszkozat', JSON_UNESCAPED_UNICODE) ?>, active: <?= json_encode(t('survey.status_active') ?: 'Aktív', JSON_UNESCAPED_UNICODE) ?>, closed: <?= json_encode(t('survey.status_closed') ?: 'Lezárva', JSON_UNESCAPED_UNICODE) ?> };
    var form = '<div class="card mb-3" id="govSurveyNewWrap"><div class="card-body"><h6 class="card-title">' + (<?= json_encode(t('gov.survey_new') ?: 'Új felmérés', JSON_UNESCAPED_UNICODE) ?>) + '</h6>';
    form += '<input type="text" id="govSurveyTitle" class="form-control form-control-sm mb-2" placeholder="' + (<?= json_encode(t('idea.title_placeholder') ?: 'Cím', JSON_UNESCAPED_UNICODE) ?>) + '" required>';
    form += '<textarea id="govSurveyDesc" class="form-control form-control-sm mb-2" rows="2" placeholder="' + (<?= json_encode(t('gov.report_description') ?: 'Leírás', JSON_UNESCAPED_UNICODE) ?>) + '"></textarea>';
    form += '<label class="small">' + (<?= json_encode(t('gov.survey_starts') ?: 'Kezdés', JSON_UNESCAPED_UNICODE) ?>) + '</label><input type="datetime-local" id="govSurveyStarts" class="form-control form-control-sm mb-2" value="' + today + '">';
    form += '<label class="small">' + (<?= json_encode(t('gov.survey_ends') ?: 'Befejezés', JSON_UNESCAPED_UNICODE) ?>) + '</label><input type="datetime-local" id="govSurveyEnds" class="form-control form-control-sm mb-2" value="' + endStr + '">';
    form += '<label class="small">' + (<?= json_encode(t('common.status'), JSON_UNESCAPED_UNICODE) ?>) + '</label><select id="govSurveyStatus" class="form-select form-select-sm mb-2"><option value="draft">' + statusL.draft + '</option><option value="active">' + statusL.active + '</option><option value="closed">' + statusL.closed + '</option></select>';
    form += '<p class="small mb-1">' + (<?= json_encode(t('gov.survey_questions') ?: 'Kérdések', JSON_UNESCAPED_UNICODE) ?>) + '</p><input type="text" id="govSurveyQ1" class="form-control form-control-sm mb-1" placeholder="1. ' + (<?= json_encode(t('gov.survey_question_placeholder') ?: 'kérdés', JSON_UNESCAPED_UNICODE) ?>) + '"><input type="text" id="govSurveyQ2" class="form-control form-control-sm mb-1" placeholder="2. ' + (<?= json_encode(t('gov.survey_question_placeholder') ?: 'kérdés', JSON_UNESCAPED_UNICODE) ?>) + '"><input type="text" id="govSurveyQ3" class="form-control form-control-sm mb-2" placeholder="3. ' + (<?= json_encode(t('gov.survey_question_placeholder') ?: 'kérdés', JSON_UNESCAPED_UNICODE) ?>) + '">';
    form += '<button type="button" class="btn btn-sm btn-primary me-2" id="govSurveySubmit">' + (<?= json_encode(t('gov.save') ?: 'Mentés', JSON_UNESCAPED_UNICODE) ?>) + '</button><button type="button" class="btn btn-sm btn-outline-secondary" id="govSurveyCancel">' + (<?= json_encode(t('common.cancel') ?: 'Mégse', JSON_UNESCAPED_UNICODE) ?>) + '</button></div></div>';
    var wrap = document.createElement('div');
    wrap.innerHTML = form;
    container.insertBefore(wrap, container.firstChild);
    document.getElementById('govSurveySubmit').addEventListener('click', function(){
      var title = (document.getElementById('govSurveyTitle') && document.getElementById('govSurveyTitle').value || '').trim();
      if (!title) return;
      var desc = document.getElementById('govSurveyDesc') && document.getElementById('govSurveyDesc').value || '';
      var starts = (document.getElementById('govSurveyStarts') && document.getElementById('govSurveyStarts').value || '').replace('T',' ') + ':00';
      var ends = (document.getElementById('govSurveyEnds') && document.getElementById('govSurveyEnds').value || '').replace('T',' ') + ':00';
      var status = document.getElementById('govSurveyStatus') && document.getElementById('govSurveyStatus').value || 'draft';
      var qs = [];
      [document.getElementById('govSurveyQ1'), document.getElementById('govSurveyQ2'), document.getElementById('govSurveyQ3')].forEach(function(inp, i){ if (inp && inp.value.trim()) qs.push({ question_text: inp.value.trim(), question_type: 'text', sort_order: i }); });
      var body = { action: 'save', title: title, description: desc, authority_id: firstAid > 0 ? firstAid : null, starts_at: starts, ends_at: ends, status: status, questions: qs };
      postJson(govSurveysUrl, body).then(function(x){
        if (x && x.ok && x.j && x.j.ok) { var w = document.getElementById('govSurveyNewWrap'); if (w && w.parentNode) w.parentNode.removeChild(w); loadGovSurveys(); }
      });
    });
    document.getElementById('govSurveyCancel').addEventListener('click', function(){ var w = document.getElementById('govSurveyNewWrap'); if (w && w.parentNode) w.parentNode.removeChild(w); });
  }
  function showGovSurveyResults(id){
    var resultsWrap = document.getElementById('govSurveyResults');
    var resultsContent = document.getElementById('govSurveyResultsContent');
    var list = document.getElementById('govSurveysList');
    if (!resultsWrap || !resultsContent || !govSurveysUrl) return;
    resultsContent.textContent = <?= json_encode(t('gov.loading'), JSON_UNESCAPED_UNICODE) ?>;
    fetch(govSurveysUrl + '?id=' + encodeURIComponent(id) + '&results=1', { credentials:'include' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok) { resultsContent.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; return; }
        var h = '<p class="small text-secondary">' + (j.response_count||0) + ' ' + (<?= json_encode(t('gov.survey_responses') ?: 'válasz', JSON_UNESCAPED_UNICODE) ?>) + '</p>';
        (j.aggregated||[]).forEach(function(a){
          h += '<div class="mb-3"><strong>' + (a.question_text||'').replace(/</g,'&lt;') + '</strong><ul class="small mb-0">';
          var ans = a.answers || {};
          Object.keys(ans).forEach(function(k){ h += '<li>' + (k.replace(/</g,'&lt;').replace(/^"|"$/g,'')) + ': ' + ans[k] + '</li>'; });
          h += '</ul></div>';
        });
        resultsContent.innerHTML = h || '<p class="text-secondary small">' + (<?= json_encode(t('gov.no_data'), JSON_UNESCAPED_UNICODE) ?>) + '</p>';
      })
      .catch(function(){ resultsContent.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; });
    if (list) list.style.display = 'none';
    resultsWrap.style.display = 'block';
  }
  document.getElementById('govSurveyResultsBack') && document.getElementById('govSurveyResultsBack').addEventListener('click', function(){
    var resultsWrap = document.getElementById('govSurveyResults');
    var list = document.getElementById('govSurveysList');
    if (resultsWrap) resultsWrap.style.display = 'none';
    if (list) list.style.display = 'block';
  });

  function loadGovBudget(){
    var list = document.getElementById('govBudgetList');
    if (!list || !govBudgetUrl) return;
    list.textContent = <?= json_encode(t('gov.loading'), JSON_UNESCAPED_UNICODE) ?>;
    fetch(govBudgetUrl, { credentials:'include' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok) { list.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; return; }
        var projects = j.projects || [];
        var settings = j.settings || null;
        var firstAid = j.first_authority_id || 0;
        var statusL = { draft: <?= json_encode(t('survey.status_draft') ?: 'Piszkozat', JSON_UNESCAPED_UNICODE) ?>, published: <?= json_encode(t('budget.status_published') ?: 'Közzétéve', JSON_UNESCAPED_UNICODE) ?>, closed: <?= json_encode(t('survey.status_closed') ?: 'Lezárva', JSON_UNESCAPED_UNICODE) ?> };
        var html = '<p class="small text-muted mb-2"><a href="' + (<?= json_encode(app_url('/budget.php'), JSON_UNESCAPED_SLASHES) ?>) + '" target="_blank" rel="noopener">' + (<?= json_encode(t('gov.budget_public_page') ?: 'Nyilvános szavazási oldal', JSON_UNESCAPED_UNICODE) ?>) + '</a>';
        if (firstAid > 0) html += ' | <a href="' + (<?= json_encode(app_url('/budget_announce.php'), JSON_UNESCAPED_SLASHES) ?>) + '?authority_id=' + firstAid + '" target="_blank" rel="noopener">' + (<?= json_encode(t('gov.budget_announce') ?: 'Kihirdetés', JSON_UNESCAPED_UNICODE) ?>) + '</a>';
        html += '</p>';
        html += '<div class="card mb-3"><div class="card-body"><h6 class="card-title small">' + (<?= json_encode(t('gov.budget_settings') ?: 'Szavazás beállítások (keret, feltételek)', JSON_UNESCAPED_UNICODE) ?>) + '</h6>';
        html += '<input type="number" id="govBudgetFrame" class="form-control form-control-sm mb-2" placeholder="' + (<?= json_encode(t('budget.frame_amount') ?: 'Keret összeg (Ft)', JSON_UNESCAPED_UNICODE) ?>) + '" min="0" step="1" value="' + (settings && settings.frame_amount != null ? settings.frame_amount : '') + '">';
        html += '<textarea id="govBudgetConditions" class="form-control form-control-sm mb-2" rows="2" placeholder="' + (<?= json_encode(t('budget.conditions') ?: 'Feltételek, kizárások', JSON_UNESCAPED_UNICODE) ?>) + '">' + (settings && settings.conditions_text ? String(settings.conditions_text).replace(/</g,'&lt;') : '') + '</textarea>';
        html += '<textarea id="govBudgetDescSettings" class="form-control form-control-sm mb-2" rows="2" placeholder="' + (<?= json_encode(t('gov.report_description') ?: 'Leírás (szavazási oldal első blokk)', JSON_UNESCAPED_UNICODE) ?>) + '">' + (settings && settings.description ? String(settings.description).replace(/</g,'&lt;') : '') + '</textarea>';
        html += '<button type="button" class="btn btn-sm btn-primary me-2" id="govBudgetSaveSettings">' + (<?= json_encode(t('gov.save') ?: 'Mentés', JSON_UNESCAPED_UNICODE) ?>) + '</button>';
        html += '<button type="button" class="btn btn-sm btn-outline-danger" id="govBudgetCloseVoting">' + (<?= json_encode(t('gov.budget_close_voting') ?: 'Lezárás (szavazás vége)', JSON_UNESCAPED_UNICODE) ?>) + '</button></div></div>';
        html += '<div class="mb-3"><button type="button" class="btn btn-sm btn-outline-primary" id="govBudgetNewBtn">' + (<?= json_encode(t('gov.budget_new_project') ?: 'Új projekt', JSON_UNESCAPED_UNICODE) ?>) + '</button></div>';
        if (!projects.length) {
          list.innerHTML = html + '<p class="text-secondary small mb-0">' + (<?= json_encode(t('gov.no_data'), JSON_UNESCAPED_UNICODE) ?>) + '</p>';
          document.getElementById('govBudgetNewBtn') && document.getElementById('govBudgetNewBtn').addEventListener('click', function(){ showGovBudgetNewForm(list); });
          document.getElementById('govBudgetSaveSettings') && document.getElementById('govBudgetSaveSettings').addEventListener('click', function(){
            var frame = document.getElementById('govBudgetFrame'); var cond = document.getElementById('govBudgetConditions'); var desc = document.getElementById('govBudgetDescSettings');
            postJson(govBudgetUrl, { action: 'save_settings', frame_amount: frame ? (frame.value === '' ? null : parseFloat(frame.value)) : null, conditions_text: cond ? cond.value : '', description: desc ? desc.value : '' }).then(function(x){ if (x && x.ok && x.j && x.j.ok) loadGovBudget(); else alert((x && x.j && x.j.error) || (<?= json_encode(t('common.error_save_failed'), JSON_UNESCAPED_UNICODE) ?>)); });
          });
          document.getElementById('govBudgetCloseVoting') && document.getElementById('govBudgetCloseVoting').addEventListener('click', function(){
            if (!confirm(<?= json_encode(t('gov.budget_close_confirm') ?: 'Lezárja a szavazást?', JSON_UNESCAPED_UNICODE) ?>)) return;
            postJson(govBudgetUrl, { action: 'close_voting' }).then(function(x){ if (x && x.ok && x.j && x.j.ok) loadGovBudget(); else alert((x && x.j && x.j.error) || (<?= json_encode(t('common.error_save_failed'), JSON_UNESCAPED_UNICODE) ?>)); });
          });
          return;
        }
        html += '<table class="table table-sm table-hover"><thead><tr><th>#</th><th>' + (<?= json_encode(t('idea.title_placeholder') ?: 'Cím', JSON_UNESCAPED_UNICODE) ?>) + '</th><th>' + (<?= json_encode(t('budget.budget_label') ?: 'Költségvetés', JSON_UNESCAPED_UNICODE) ?>) + '</th><th>' + (<?= json_encode(t('idea.votes') ?: 'Szavazat', JSON_UNESCAPED_UNICODE) ?>) + '</th><th>' + (<?= json_encode(t('common.status'), JSON_UNESCAPED_UNICODE) ?>) + '</th></tr></thead><tbody>';
        projects.forEach(function(p){
          html += '<tr><td>' + p.id + '</td><td><strong>' + String(p.title||'').replace(/</g,'&lt;') + '</strong>' + (p.description ? '<br><span class="text-muted small">' + String(p.description).replace(/</g,'&lt;').slice(0,80) + (p.description.length > 80 ? '…' : '') + '</span>' : '') + '</td><td>' + Number(p.budget).toLocaleString('hu-HU') + ' Ft</td><td>' + (p.vote_count||0) + '</td><td><select class="form-select form-select-sm gov-budget-status" data-id="' + p.id + '" style="min-width:100px">';
          ['draft','published','closed'].forEach(function(s){ html += '<option value="' + s + '"' + (p.status === s ? ' selected' : '') + '>' + (statusL[s]||s) + '</option>'; });
          html += '</select></td></tr>';
        });
        html += '</tbody></table>';
        list.innerHTML = html;
        list.querySelectorAll('.gov-budget-status').forEach(function(sel){
          sel.addEventListener('change', function(){
            var id = parseInt(sel.getAttribute('data-id'), 10);
            postJson(govBudgetUrl, { action: 'set_status', id: id, status: sel.value }).then(function(x){ if (x && x.ok && x.j && x.j.ok) loadGovBudget(); });
          });
        });
        document.getElementById('govBudgetNewBtn') && document.getElementById('govBudgetNewBtn').addEventListener('click', function(){ showGovBudgetNewForm(list); });
        document.getElementById('govBudgetSaveSettings') && document.getElementById('govBudgetSaveSettings').addEventListener('click', function(){
          var frame = document.getElementById('govBudgetFrame');
          var cond = document.getElementById('govBudgetConditions');
          var desc = document.getElementById('govBudgetDescSettings');
          postJson(govBudgetUrl, { action: 'save_settings', frame_amount: frame ? (frame.value === '' ? null : parseFloat(frame.value)) : null, conditions_text: cond ? cond.value : '', description: desc ? desc.value : '' }).then(function(x){ if (x && x.ok && x.j && x.j.ok) loadGovBudget(); else alert((x && x.j && x.j.error) || (<?= json_encode(t('common.error_save_failed'), JSON_UNESCAPED_UNICODE) ?>)); });
        });
        document.getElementById('govBudgetCloseVoting') && document.getElementById('govBudgetCloseVoting').addEventListener('click', function(){
          if (!confirm(<?= json_encode(t('gov.budget_close_confirm') ?: 'Lezárja a szavazást? Ezután a felhasználók nem szavazhatnak.', JSON_UNESCAPED_UNICODE) ?>)) return;
          postJson(govBudgetUrl, { action: 'close_voting' }).then(function(x){ if (x && x.ok && x.j && x.j.ok) loadGovBudget(); else alert((x && x.j && x.j.error) || (<?= json_encode(t('common.error_save_failed'), JSON_UNESCAPED_UNICODE) ?>)); });
        });
      })
      .catch(function(){ list.textContent = <?= json_encode(t('common.error_load'), JSON_UNESCAPED_UNICODE) ?>; });
  }
  function showGovBudgetNewForm(container){
    var statusL = { draft: <?= json_encode(t('survey.status_draft') ?: 'Piszkozat', JSON_UNESCAPED_UNICODE) ?>, published: <?= json_encode(t('budget.status_published') ?: 'Közzétéve', JSON_UNESCAPED_UNICODE) ?> };
    var form = '<div class="card mb-3"><div class="card-body"><h6 class="card-title">' + (<?= json_encode(t('gov.budget_new_project') ?: 'Új projekt', JSON_UNESCAPED_UNICODE) ?>) + '</h6><input type="text" id="govBudgetTitle" class="form-control form-control-sm mb-2" placeholder="' + (<?= json_encode(t('idea.title_placeholder') ?: 'Cím', JSON_UNESCAPED_UNICODE) ?>) + '"><textarea id="govBudgetDesc" class="form-control form-control-sm mb-2" rows="2" placeholder="' + (<?= json_encode(t('gov.report_description') ?: 'Leírás', JSON_UNESCAPED_UNICODE) ?>) + '"></textarea><input type="number" id="govBudgetAmount" class="form-control form-control-sm mb-2" placeholder="' + (<?= json_encode(t('budget.budget_label') ?: 'Összeg (Ft)', JSON_UNESCAPED_UNICODE) ?>) + '" min="0" step="1"><button type="button" class="btn btn-sm btn-primary" id="govBudgetSubmit">' + (<?= json_encode(t('gov.save') ?: 'Mentés', JSON_UNESCAPED_UNICODE) ?>) + '</button> <button type="button" class="btn btn-sm btn-outline-secondary" id="govBudgetCancel">' + (<?= json_encode(t('common.cancel') ?: 'Mégse', JSON_UNESCAPED_UNICODE) ?>) + '</button></div></div>';
    var wrap = document.createElement('div');
    wrap.id = 'govBudgetNewWrap';
    wrap.innerHTML = form;
    container.insertBefore(wrap, container.firstChild);
    document.getElementById('govBudgetSubmit').addEventListener('click', function(){
      var title = (document.getElementById('govBudgetTitle') && document.getElementById('govBudgetTitle').value || '').trim();
      var desc = document.getElementById('govBudgetDesc') && document.getElementById('govBudgetDesc').value || '';
      var amount = parseInt(document.getElementById('govBudgetAmount') && document.getElementById('govBudgetAmount').value, 10) || 0;
      if (!title) return;
      postJson(govBudgetUrl, { action: 'create', title: title, description: desc, budget: amount, status: 'draft' }).then(function(x){
        if (x && x.ok && x.j && x.j.ok) { var w = document.getElementById('govBudgetNewWrap'); if (w) w.remove(); loadGovBudget(); }
      });
    });
    document.getElementById('govBudgetCancel').addEventListener('click', function(){ var w = document.getElementById('govBudgetNewWrap'); if (w) w.remove(); });
  }
})();
</script>
</body></html>

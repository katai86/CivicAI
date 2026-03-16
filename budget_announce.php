<?php
/**
 * Részvételi költségvetés – Kihirdetés: támogatást kapott projektek listája (nyilvános).
 * GET: authority_id (kötelező). Megjelenítés: bejelentő neve, projekt megnevezése, támogatási összeg.
 */
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';

start_secure_session();
$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();

$pageTitle = function_exists('t') ? t('gov.budget_announce') : 'Kihirdetés';
$authorityName = '';
$projects = [];

if ($authorityId > 0) {
  try {
    $st = db()->prepare("SELECT name FROM authorities WHERE id = ? LIMIT 1");
    $st->execute([$authorityId]);
    $authorityName = (string)($st->fetchColumn() ?: '');
    $stmt = db()->prepare("
      SELECT p.id, p.title, p.budget, p.submitted_by,
             COALESCE((SELECT COUNT(*) FROM budget_votes v WHERE v.project_id = p.id), 0) AS vote_count,
             u.display_name AS submitter_name
      FROM budget_projects p
      LEFT JOIN users u ON u.id = p.submitted_by
      WHERE p.authority_id = ? AND p.status IN ('published','closed')
      HAVING vote_count > 0
      ORDER BY vote_count DESC, p.budget DESC, p.created_at DESC
    ");
    $stmt->execute([$authorityId]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($projects as &$r) {
      $r['budget'] = (float)($r['budget'] ?? 0);
      $r['vote_count'] = (int)($r['vote_count'] ?? 0);
    }
    unset($r);
  } catch (Throwable $e) {
    $projects = [];
  }
}

$budgetActive = function_exists('participatory_budget_enabled') && participatory_budget_enabled();
?><!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){}</script>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .budget-page { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
    .budget-page h1 { margin: 0 0 8px 0; font-size: 1.75rem; }
    .budget-card { border: 1px solid var(--border, #dee2e6); border-radius: 12px; padding: 16px; margin-bottom: 16px; background: var(--card, #fff); }
    .budget-back { display: inline-block; margin-top: 24px; color: var(--primary); }
  </style>
</head>
<body>
<?php $desktop_topbar_show_search = false; require __DIR__ . '/inc_desktop_topbar.php'; ?>
<main class="budget-page">
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if ($authorityId <= 0): ?>
    <p class="text-muted"><?= htmlspecialchars(t('common.error_invalid_data') ?: 'Érvénytelen kérés.', ENT_QUOTES, 'UTF-8') ?></p>
  <?php else: ?>
    <?php if ($authorityName !== ''): ?>
      <p class="text-secondary small mb-3"><?= htmlspecialchars($authorityName, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('gov.budget_announce_intro') ?: 'Támogatást kapott projektek', ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (empty($projects)): ?>
      <p class="text-muted"><?= htmlspecialchars(t('gov.no_data'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th><?= htmlspecialchars(t('gov.reporter_name') ?: 'Bejelentő neve', ENT_QUOTES, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(t('idea.title_placeholder') ?: 'Projekt megnevezése', ENT_QUOTES, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(t('budget.budget_label') ?: 'Támogatási összeg', ENT_QUOTES, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(t('idea.votes') ?: 'Szavazat', ENT_QUOTES, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($projects as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['submitter_name'] ?: (t('gov.report_anonymous') ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($p['title'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= number_format((float)$p['budget'], 0, '', ' ') ?> Ft</td>
                <td><?= (int)$p['vote_count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <a class="budget-back" href="<?= htmlspecialchars(app_url($budgetActive ? '/budget.php' : '/'), ENT_QUOTES, 'UTF-8') ?>">← <?= htmlspecialchars($budgetActive ? (t('budget.page_title') ?: 'Részvételi költségvetés') : t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
</main>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: 'guest';

if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . app_url('/faq.php'));
  exit;
}
$currentLang = current_lang();

$pageTitle = t('faq.title');
?><!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .faq-page { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
    .faq-page h1 { margin: 0 0 24px 0; font-size: 1.75rem; }
    .faq-section { margin-bottom: 28px; }
    .faq-section h2 { margin: 0 0 12px 0; font-size: 1.15rem; color: var(--primary); }
    .faq-q { font-weight: 600; margin: 14px 0 6px 0; }
    .faq-a { margin: 0 0 8px 0; color: var(--muted); font-size: 0.95rem; line-height: 1.5; }
    .faq-tips { margin-top: 12px; padding: 14px; border: 1px dashed var(--border); border-radius: 12px; background: var(--card-2); }
    .faq-tips ul { margin: 8px 0 0 0; padding-left: 20px; }
    .faq-tips li { margin-bottom: 6px; color: var(--muted); font-size: 0.9rem; }
    .faq-back { display: inline-block; margin-top: 24px; color: var(--primary); }
  </style>
</head>
<body data-logged-in="<?= $uid > 0 ? '1' : '0' ?>" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" data-lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">

<?php $desktop_topbar_show_search = false; require __DIR__ . '/inc_desktop_topbar.php'; ?>

<main class="faq-page">
  <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

  <section class="faq-section" aria-labelledby="faq-general">
    <h2 id="faq-general"><?= htmlspecialchars(t('faq.section_general'), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="faq-q"><?= htmlspecialchars(t('faq.q_what'), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="faq-a"><?= nl2br(htmlspecialchars(t('faq.a_what'), ENT_QUOTES, 'UTF-8')) ?></p>
  </section>

  <section class="faq-section" aria-labelledby="faq-report">
    <h2 id="faq-report"><?= htmlspecialchars(t('faq.section_report'), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="faq-q"><?= htmlspecialchars(t('faq.q_how_report'), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="faq-a"><?= nl2br(htmlspecialchars(t('faq.a_how_report'), ENT_QUOTES, 'UTF-8')) ?></p>
  </section>

  <section class="faq-section" aria-labelledby="faq-tree">
    <h2 id="faq-tree"><?= htmlspecialchars(t('faq.section_tree'), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="faq-q"><?= htmlspecialchars(t('faq.q_how_tree'), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="faq-a"><?= nl2br(htmlspecialchars(t('faq.a_how_tree'), ENT_QUOTES, 'UTF-8')) ?></p>
    <div class="faq-tips">
      <strong><?= htmlspecialchars(t('faq.tree_photo_tips_title'), ENT_QUOTES, 'UTF-8') ?></strong>
      <ul>
        <li><?= htmlspecialchars(t('faq.tree_photo_tip_1'), ENT_QUOTES, 'UTF-8') ?></li>
        <li><?= htmlspecialchars(t('faq.tree_photo_tip_2'), ENT_QUOTES, 'UTF-8') ?></li>
        <li><?= htmlspecialchars(t('faq.tree_photo_tip_3'), ENT_QUOTES, 'UTF-8') ?></li>
        <li><?= htmlspecialchars(t('faq.tree_photo_tip_4'), ENT_QUOTES, 'UTF-8') ?></li>
      </ul>
    </div>
  </section>

  <section class="faq-section" aria-labelledby="faq-account">
    <h2 id="faq-account"><?= htmlspecialchars(t('faq.section_account'), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="faq-q"><?= htmlspecialchars(t('faq.q_login'), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="faq-a"><?= nl2br(htmlspecialchars(t('faq.a_login'), ENT_QUOTES, 'UTF-8')) ?></p>
    <p class="faq-q"><?= htmlspecialchars(t('faq.q_xp'), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="faq-a"><?= nl2br(htmlspecialchars(t('faq.a_xp'), ENT_QUOTES, 'UTF-8')) ?></p>
    <p class="faq-q"><?= htmlspecialchars(t('faq.q_mobile'), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="faq-a"><?= nl2br(htmlspecialchars(t('faq.a_mobile'), ENT_QUOTES, 'UTF-8')) ?></p>
  </section>

  <a class="faq-back" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">← <?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
</main>

</body>
</html>

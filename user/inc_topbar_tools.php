<?php
// Téma váltó + nyelv választó – belső oldalakon (settings, my, profile, friends, report, leaderboard, login, register).
// Elvárt: $currentLang = current_lang(); és a theme-lang.js betöltve.
$curLang = isset($currentLang) ? $currentLang : current_lang();
?>
<div class="topbar-right">
  <div class="topbar-tools">
    <button type="button" id="themeToggle" class="topbtn topbtn-icon" aria-label="<?= htmlspecialchars(t('theme.aria'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?>" data-title-light="<?= htmlspecialchars(t('theme.light'), ENT_QUOTES, 'UTF-8') ?>" data-title-dark="<?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="theme-icon theme-sun" aria-hidden="true">☀️</span>
      <span class="theme-icon theme-moon" aria-hidden="true">🌙</span>
    </button>
    <div class="lang-dropdown">
      <button type="button" class="topbtn lang-btn" id="langToggle" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= htmlspecialchars(t('lang.choose'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="lang-label"><?= htmlspecialchars(strtoupper($curLang), ENT_QUOTES, 'UTF-8') ?></span><span class="lang-chevron" aria-hidden="true">▼</span>
      </button>
      <div class="lang-menu" id="langMenu" role="listbox" aria-hidden="true">
        <?php foreach (LANG_ALLOWED as $code): ?>
          <a class="lang-option<?= $code === $curLang ? ' active' : '' ?>" href="?lang=<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" data-lang="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="topbar-links">
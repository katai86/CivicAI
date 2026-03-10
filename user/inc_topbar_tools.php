<?php
/** Közös topbar: téma váltó + nyelv választó (leaderboard, login, stb.) */
$curLang = isset($currentLang) ? $currentLang : (function_exists('current_lang') ? current_lang() : 'hu');
$langAllowed = defined('LANG_ALLOWED') ? LANG_ALLOWED : ['hu', 'en'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ($path !== null && $path !== '') ? $path : '/';
$langBase = function_exists('app_url') ? app_url($path) : ($path . '?');
?>
<div class="topbar-right">
  <div class="topbar-tools">
    <button type="button" id="themeToggle" class="topbtn topbtn-icon" aria-label="<?= htmlspecialchars(function_exists('t') ? t('theme.aria') : 'Téma', ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(function_exists('t') ? t('theme.dark') : 'Sötét', ENT_QUOTES, 'UTF-8') ?>" data-title-light="<?= htmlspecialchars(function_exists('t') ? t('theme.light') : 'Világos', ENT_QUOTES, 'UTF-8') ?>" data-title-dark="<?= htmlspecialchars(function_exists('t') ? t('theme.dark') : 'Sötét', ENT_QUOTES, 'UTF-8') ?>">
      <span class="theme-icon theme-sun" aria-hidden="true">☀️</span>
      <span class="theme-icon theme-moon" aria-hidden="true">🌙</span>
    </button>
    <div class="lang-dropdown">
      <button type="button" class="topbtn lang-btn" id="langToggle" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= htmlspecialchars(function_exists('t') ? t('lang.choose') : 'Nyelv', ENT_QUOTES, 'UTF-8') ?>">
        <span class="lang-label"><?= htmlspecialchars(strtoupper($curLang), ENT_QUOTES, 'UTF-8') ?></span><span class="lang-chevron" aria-hidden="true">▼</span>
      </button>
      <div class="lang-menu" id="langMenu" role="listbox" aria-hidden="true">
        <?php foreach ($langAllowed as $code): ?>
          <a class="lang-option<?= $code === $curLang ? ' active' : '' ?>" href="<?= htmlspecialchars($langBase . (strpos($langBase, '?') !== false ? '&' : '?') . 'lang=' . $code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

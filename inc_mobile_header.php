<?php
/**
 * Mobilekit webapp – header + appCapsule nyitó
 * Változók: $mobilePageTitle, $mobileActiveTab, $mobileBackUrl (opcionális), $isMobile, $role, $uid
 */
$mobileBackUrl = $mobileBackUrl ?? (isset($isMobile) && $isMobile ? app_url('/mobile/index.php') : app_url('/'));
$mobilePageTitle = isset($mobilePageTitle) ? $mobilePageTitle : (function_exists('t') ? t('site.name') : 'CivicAI');
?>
  <div class="appHeader">
    <div class="left">
      <a href="<?= htmlspecialchars($mobileBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="headerButton">
        <i class="bi bi-arrow-left"></i>
      </a>
    </div>
    <div class="pageTitle">
      <?= htmlspecialchars($mobilePageTitle ?? t('site.name'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="right"></div>
  </div>

  <div id="appCapsule" class="full-height">
    <div class="section mt-2 mb-2">

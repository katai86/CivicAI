<?php
/** Gov dashboard – KSH / kozadatportal összefoglaló (hu_open_data modul). */
if (empty($govHuOpenDataTabEnabled)) {
    return;
}
?>
          <div class="card h-100 mb-0 gov-dash-panel border-start border-success border-3 shadow-sm" id="govHuOpenDataDashboardCard">
            <div class="card-body py-2 px-3">
              <h6 class="card-title mb-1 small fw-semibold"><?= h(t('gov.hu_dashboard_card_title')) ?></h6>
              <div id="govHuOpenDataDashboardContent">
                <p class="text-secondary small mb-0"><?= h(t('gov.loading')) ?></p>
              </div>
            </div>
          </div>

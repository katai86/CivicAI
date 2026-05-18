<?php
/** Gov UI – Magyar nyílt adatok (KSH) betöltő script. */
if (empty($govHuOpenDataTabEnabled)) {
    return;
}
?>
  var govHuOpenDataUrl = <?= json_encode(app_url('/api/hu_open_data_context.php'), JSON_UNESCAPED_SLASHES) ?>;
  var govHuOpenDataLabels = <?= json_encode([
    'year' => t('gov.hu_year'),
    'green_ha' => t('gov.hu_green_ha'),
    'forest_ha' => t('gov.hu_forest_ha'),
    'temp' => t('gov.hu_temp'),
    'precip' => t('gov.hu_precip'),
    'national' => t('gov.hu_weather_card_title'),
    'city' => t('gov.hu_weather_card_title'),
  ], JSON_UNESCAPED_UNICODE) ?>;

  function govHuFormatHa(v) {
    if (v == null || isNaN(Number(v))) return '—';
    return Number(v).toLocaleString('hu-HU', { maximumFractionDigits: 0 }) + ' ha';
  }

  function govHuRenderContext(j, targetIds) {
    var L = govHuOpenDataLabels || {};
    var noData = (typeof govStatisticsLabels !== 'undefined' && govStatisticsLabels.no_data) ? govStatisticsLabels.no_data : '—';
    if (!j || !j.ok || !j.data || !j.data.ok) {
      (targetIds || []).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = '<p class="text-secondary small mb-0">' + noData + '</p>';
      });
      return;
    }
    var d = j.data;
    var greenBox = document.getElementById('govHuGreenContent');
    if (greenBox && d.green) {
      var g = d.green;
      greenBox.innerHTML = '<div class="row g-2 small"><div class="col-6"><span class="text-secondary">' + (L.year || '') + '</span><br><b>' + (g.year || '—') + '</b></div>'
        + '<div class="col-6"><span class="text-secondary">' + (L.green_ha || '') + '</span><br><b>' + govHuFormatHa(g.value_ha) + '</b></div></div>';
    }
    var forBox = document.getElementById('govHuForestryContent');
    if (forBox && d.forestry) {
      var f = d.forestry;
      forBox.innerHTML = '<div class="row g-2 small"><div class="col-6"><span class="text-secondary">' + (L.year || '') + '</span><br><b>' + (f.year || '—') + '</b></div>'
        + '<div class="col-6"><span class="text-secondary">' + (L.forest_ha || '') + '</span><br><b>' + govHuFormatHa(f.total_ha) + '</b></div></div>';
    }
    var wBox = document.getElementById('govHuWeatherContent');
    if (wBox) {
      var html = '';
      if (d.weather_national) {
        var n = d.weather_national;
        html += '<p class="small fw-semibold mb-1">' + (L.national || '') + ' (HU)</p><div class="row g-2 small mb-2">';
        html += '<div class="col-4"><span class="text-secondary">' + (L.year || '') + '</span><br><b>' + (n.year || '—') + '</b></div>';
        html += '<div class="col-4"><span class="text-secondary">' + (L.temp || '') + '</span><br><b>' + (n.temp_mean_c != null ? n.temp_mean_c + ' °C' : '—') + '</b></div>';
        html += '<div class="col-4"><span class="text-secondary">' + (L.precip || '') + '</span><br><b>' + (n.precip_mm != null ? n.precip_mm + ' mm' : '—') + '</b></div></div>';
      }
      if (d.weather_city) {
        var c = d.weather_city;
        html += '<p class="small fw-semibold mb-1">' + (c.city || L.city || '') + '</p><div class="row g-2 small">';
        html += '<div class="col-4"><span class="text-secondary">' + (L.year || '') + '</span><br><b>' + (c.year || '—') + '</b></div>';
        html += '<div class="col-4"><span class="text-secondary">' + (L.temp || '') + '</span><br><b>' + (c.temp_mean_c != null ? c.temp_mean_c + ' °C' : '—') + '</b></div>';
        html += '<div class="col-4"><span class="text-secondary">' + (L.precip || '') + '</span><br><b>' + (c.precip_mm != null ? c.precip_mm + ' mm' : '—') + '</b></div></div>';
      }
      wBox.innerHTML = html || ('<p class="text-secondary small mb-0">' + noData + '</p>');
    }
    var dash = document.getElementById('govHuOpenDataDashboardContent');
    if (dash) {
      var parts = [];
      if (d.green) parts.push((L.green_ha || '') + ': ' + govHuFormatHa(d.green.value_ha) + ' (' + (d.green.year || '') + ')');
      if (d.forestry) parts.push((L.forest_ha || '') + ': ' + govHuFormatHa(d.forestry.total_ha));
      if (d.weather_national && d.weather_national.temp_mean_c != null) {
        parts.push((L.temp || '') + ' HU: ' + d.weather_national.temp_mean_c + ' °C');
      }
      dash.innerHTML = parts.length
        ? '<p class="text-secondary small mb-0">' + parts.join(' · ') + '</p>'
        : '<p class="text-secondary small mb-0">' + noData + '</p>';
    }
    var treesHint = document.getElementById('govTreesKshHint');
    if (treesHint && d.green) {
      treesHint.textContent = (L.green_ha || 'KSH') + ' (országos): ' + govHuFormatHa(d.green.value_ha) + ' (' + (d.green.year || '') + ')';
      treesHint.hidden = false;
    }
  }

  function loadGovHuOpenDataContext() {
    if (!govHuOpenDataUrl) return;
    var ids = ['govHuGreenContent', 'govHuForestryContent', 'govHuWeatherContent', 'govHuOpenDataDashboardContent'];
    fetch(govHuOpenDataUrl + (typeof govEuAuthorityQuery === 'function' ? govEuAuthorityQuery() : ''), { credentials: 'include' })
      .then(function(r) { return r.json(); })
      .then(function(j) { govHuRenderContext(j, ids); })
      .catch(function() { govHuRenderContext(null, ids); });
  }

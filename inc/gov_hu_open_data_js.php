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
    'no_data' => t('gov.no_data'),
    'load_error' => t('gov.hu_load_error'),
    'ksh_unreachable' => t('gov.hu_ksh_unreachable'),
    'reference_snapshot' => t('gov.hu_reference_snapshot'),
  ], JSON_UNESCAPED_UNICODE) ?>;

  function govHuFormatHa(v) {
    if (v == null || isNaN(Number(v))) return '—';
    return Number(v).toLocaleString('hu-HU', { maximumFractionDigits: 0 }) + ' ha';
  }

  function govHuNoteMessage(d, j) {
    var L = govHuOpenDataLabels || {};
    if (d && (d.reference_snapshot || (Array.isArray(d.notes) && d.notes.indexOf('ksh_using_reference_snapshot') >= 0))) {
      return L.reference_snapshot || L.no_data || '—';
    }
    if (d && Array.isArray(d.notes) && d.notes.length) {
      if (d.notes.indexOf('ksh_green_unavailable') >= 0 || d.notes.indexOf('ksh_forestry_unavailable') >= 0) {
        return L.ksh_unreachable || L.no_data || '—';
      }
    }
    if (j && j.meta && Array.isArray(j.meta.notes) && j.meta.notes.length) {
      return L.ksh_unreachable || L.no_data || '—';
    }
    if (d && d.error === 'hu_module_disabled') {
      return L.no_data || '—';
    }
    return L.no_data || '—';
  }

  function govHuRenderContext(j, targetIds) {
    var L = govHuOpenDataLabels || {};
    var d = (j && j.data) ? j.data : {};
    var noData = L.no_data || '—';

    if (!j || !j.ok) {
      var errMsg = (j && j.error) ? String(j.error) : (L.load_error || noData);
      (targetIds || []).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = '<p class="text-secondary small mb-0">' + errMsg + '</p>';
      });
      return;
    }

    var greenBox = document.getElementById('govHuGreenContent');
    if (greenBox) {
      if (d.green) {
        var g = d.green;
        var refBadge = (g.reference || d.reference_snapshot) ? ' <span class="badge text-bg-warning ms-1">' + (L.reference_snapshot || 'ref') + '</span>' : '';
        greenBox.innerHTML = '<div class="row g-2"><div class="col-md-6"><div class="dash-hero-tile dash-tile-green"><div class="dash-tile-value">' + govHuFormatHa(g.value_ha) + '</div><div class="dash-tile-label">' + (L.green_ha || '') + refBadge + '</div></div></div>'
          + '<div class="col-md-6"><div class="dash-hero-tile dash-tile-slate"><div class="dash-tile-value">' + (g.year || '—') + '</div><div class="dash-tile-label">' + (L.year || '') + '</div></div></div></div>';
      } else if (greenBox.innerHTML.indexOf('Betöltés') >= 0 || greenBox.innerHTML.indexOf('Loading') >= 0) {
        greenBox.innerHTML = '<p class="text-secondary small mb-0">' + govHuNoteMessage(d, j) + '</p>';
      }
    }

    var forBox = document.getElementById('govHuForestryContent');
    if (forBox) {
      if (d.forestry) {
        var f = d.forestry;
        forBox.innerHTML = '<div class="row g-2"><div class="col-md-6"><div class="dash-hero-tile dash-tile-teal"><div class="dash-tile-value">' + govHuFormatHa(f.total_ha) + '</div><div class="dash-tile-label">' + (L.forest_ha || '') + '</div></div></div>'
          + '<div class="col-md-6"><div class="dash-hero-tile dash-tile-slate"><div class="dash-tile-value">' + (f.year || '—') + '</div><div class="dash-tile-label">' + (L.year || '') + '</div></div></div></div>';
      } else if (forBox.innerHTML.indexOf('Betöltés') >= 0 || forBox.innerHTML.indexOf('Loading') >= 0) {
        forBox.innerHTML = '<p class="text-secondary small mb-0">' + govHuNoteMessage(d, j) + '</p>';
      }
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
      if (html) {
        wBox.innerHTML = html;
      } else if (wBox.innerHTML.indexOf('Betöltés') >= 0 || wBox.innerHTML.indexOf('Loading') >= 0) {
        wBox.innerHTML = '<p class="text-secondary small mb-0">' + govHuNoteMessage(d, j) + '</p>';
      }
    }

    var dash = document.getElementById('govHuOpenDataDashboardContent');
    if (dash) {
      var parts = [];
      if (d.green) parts.push((L.green_ha || '') + ': ' + govHuFormatHa(d.green.value_ha) + ' (' + (d.green.year || '') + ')');
      if (d.forestry) parts.push((L.forest_ha || '') + ': ' + govHuFormatHa(d.forestry.total_ha));
      if (d.weather_national && d.weather_national.temp_mean_c != null) {
        parts.push((L.temp || '') + ' HU: ' + d.weather_national.temp_mean_c + ' °C');
      }
      if (parts.length) {
        var refHint = (d.reference_snapshot || (Array.isArray(d.notes) && d.notes.indexOf('ksh_using_reference_snapshot') >= 0))
          ? '<br><span class="text-warning-emphasis">' + (L.reference_snapshot || '') + '</span>' : '';
        dash.innerHTML = '<p class="text-secondary small mb-0">' + parts.join(' · ') + refHint + '</p>';
      } else {
        dash.innerHTML = '<p class="text-secondary small mb-0">' + govHuNoteMessage(d, j) + '</p>';
      }
    }

    var treesHint = document.getElementById('govTreesKshHint');
    if (treesHint && d.green) {
      treesHint.textContent = (L.green_ha || 'KSH') + ' (országos): ' + govHuFormatHa(d.green.value_ha) + ' (' + (d.green.year || '') + ')';
      treesHint.hidden = false;
    }
  }

  function loadGovHuOpenDataContext(opts) {
    if (!govHuOpenDataUrl) return;
    opts = opts || {};
    var lite = !!opts.lite;
    var ids = ['govHuGreenContent', 'govHuForestryContent', 'govHuWeatherContent', 'govHuOpenDataDashboardContent'];
    var q = (typeof govEuAuthorityQuery === 'function' ? govEuAuthorityQuery() : '');
    var url = govHuOpenDataUrl + (q || '');
    url += (url.indexOf('?') >= 0 ? '&' : '?') + 'lite=' + (lite ? '1' : '0');

    var timeoutMs = lite ? 12000 : 45000;
  var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = ctrl ? setTimeout(function() { try { ctrl.abort(); } catch (_) {} }, timeoutMs) : null;

    fetch(url, { credentials: 'include', signal: ctrl ? ctrl.signal : undefined })
      .then(function(r) { return r.json(); })
      .then(function(j) { govHuRenderContext(j, ids); })
      .catch(function(err) {
        var L = govHuOpenDataLabels || {};
        var msg = (err && err.name === 'AbortError') ? (L.load_error || '—') : (L.load_error || '—');
        ids.forEach(function(id) {
          var el = document.getElementById(id);
          if (el) el.innerHTML = '<p class="text-secondary small mb-0">' + msg + '</p>';
        });
      })
      .finally(function() { if (timer) clearTimeout(timer); });
  }

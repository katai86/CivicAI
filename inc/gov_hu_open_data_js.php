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
  ], JSON_UNESCAPED_UNICODE) ?>;

  function govHuFormatHa(v) {
    if (v == null || isNaN(Number(v))) return '—';
    return Number(v).toLocaleString('hu-HU', { maximumFractionDigits: 0 }) + ' ha';
  }

  function govHuNoteMessage(d, j) {
    var L = govHuOpenDataLabels || {};
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
        greenBox.innerHTML = '<div class="row g-2 small"><div class="col-6"><span class="text-secondary">' + (L.year || '') + '</span><br><b>' + (g.year || '—') + '</b></div>'
          + '<div class="col-6"><span class="text-secondary">' + (L.green_ha || '') + '</span><br><b>' + govHuFormatHa(g.value_ha) + '</b></div></div>';
      } else if (greenBox.innerHTML.indexOf('Betöltés') >= 0 || greenBox.innerHTML.indexOf('Loading') >= 0) {
        greenBox.innerHTML = '<p class="text-secondary small mb-0">' + govHuNoteMessage(d, j) + '</p>';
      }
    }

    var forBox = document.getElementById('govHuForestryContent');
    if (forBox) {
      if (d.forestry) {
        var f = d.forestry;
        forBox.innerHTML = '<div class="row g-2 small"><div class="col-6"><span class="text-secondary">' + (L.year || '') + '</span><br><b>' + (f.year || '—') + '</b></div>'
          + '<div class="col-6"><span class="text-secondary">' + (L.forest_ha || '') + '</span><br><b>' + govHuFormatHa(f.total_ha) + '</b></div></div>';
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
        dash.innerHTML = '<p class="text-secondary small mb-0">' + parts.join(' · ') + '</p>';
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

    var timeoutMs = lite ? 25000 : 55000;
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

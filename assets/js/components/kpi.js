/**
 * CivicAI reusable KPI widgets (Gov executive + dashboards).
 * No framework dependency; plain DOM strings + CSS classes from admin.css (.exec-kpi-*).
 */
(function (global) {
  'use strict';

  function escapeHtml(s) {
    if (s == null) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  /** 0–100 score → exec-kpi-good | warn | bad */
  function toneFromScore(n, invert) {
    var v = Number(n);
    if (!isFinite(v)) return 'exec-kpi-warn';
    if (invert) v = 100 - v;
    if (v >= 70) return 'exec-kpi-good';
    if (v >= 40) return 'exec-kpi-warn';
    return 'exec-kpi-bad';
  }

  function resolutionTone(days) {
    var d = Number(days);
    if (!isFinite(d)) return 'exec-kpi-warn';
    if (d <= 10) return 'exec-kpi-good';
    if (d <= 30) return 'exec-kpi-warn';
    return 'exec-kpi-bad';
  }

  /** Higher open count → worse (maps to 0–100 then toneFromScore) */
  function openIssuesTone(openCount) {
    var o = Number(openCount);
    if (!isFinite(o)) return 'exec-kpi-warn';
    var v = Math.max(0, 100 - Math.min(o, 100));
    return toneFromScore(v, false);
  }

  /**
   * Bootstrap grid cell with KPI box.
   * @param {{ label: string, value: string|number, toneClass?: string, colClass?: string }} o
   */
  function renderKpiCard(o) {
    var tone = o.toneClass || 'exec-kpi-neutral';
    var col = o.colClass || 'col-6 col-md-4 col-lg-3';
    var lab = escapeHtml(o.label || '');
    var val = escapeHtml(o.value != null ? o.value : '—');
    return (
      '<div class="' + col + '">' +
      '<div class="exec-kpi rounded-3 p-2 p-md-3 h-100 ' + tone + '">' +
      '<div class="exec-kpi-label small text-secondary">' + lab + '</div>' +
      '<div class="exec-kpi-value fw-bold">' + val + '</div>' +
      (o.sublabel ? '<div class="small text-secondary mt-1">' + escapeHtml(o.sublabel) + '</div>' : '') +
      '</div></div>'
    );
  }

  /**
   * Number + optional delta (e.g. "+12% vs prev.")
   */
  function renderTrendCard(o) {
    var tone = o.toneClass || 'exec-kpi-neutral';
    var col = o.colClass || 'col-6 col-md-4';
    var delta = o.deltaText ? '<div class="small mt-1 ' + (o.deltaClass || 'text-secondary') + '">' + escapeHtml(o.deltaText) + '</div>' : '';
    return (
      '<div class="' + col + '">' +
      '<div class="exec-kpi rounded-3 p-2 p-md-3 h-100 ' + tone + '">' +
      '<div class="exec-kpi-label small text-secondary">' + escapeHtml(o.label || '') + '</div>' +
      '<div class="exec-kpi-value fw-bold">' + escapeHtml(o.value != null ? o.value : '—') + '</div>' +
      delta +
      '</div></div>'
    );
  }

  /**
   * Semi-circular gauge using conic-gradient (no canvas).
   * @param {{ label: string, value: number, max?: number }} o  value/max 0..100
   */
  function renderGaugeCard(o) {
    var max = o.max != null ? Number(o.max) : 100;
    var val = Math.max(0, Math.min(max, Number(o.value) || 0));
    var pct = max > 0 ? Math.round((val / max) * 100) : 0;
    var tone = toneFromScore(pct, false);
    var col = o.colClass || 'col-6 col-md-4 col-lg-3';
    var deg = Math.round((pct / 100) * 180);
    var style =
      'background:conic-gradient(from 180deg at 50% 100%, var(--bs-primary) 0deg,' +
      ' var(--bs-primary) ' +
      deg +
      'deg, rgba(128,128,128,0.25) ' +
      deg +
      'deg 180deg);';
    return (
      '<div class="' + col + '">' +
      '<div class="exec-kpi rounded-3 p-2 p-md-3 h-100 ' +
      tone +
      ' text-center">' +
      '<div class="exec-kpi-label small text-secondary mb-2">' +
      escapeHtml(o.label || '') +
      '</div>' +
      '<div class="exec-gauge mx-auto mb-2 rounded-top-pill" style="width:min(100%,120px);height:60px;' +
      style +
      '"></div>' +
      '<div class="exec-kpi-value fw-bold">' +
      escapeHtml(String(Math.round(val))) +
      '<span class="fs-6 text-secondary">/' +
      escapeHtml(String(max)) +
      '</span></div></div></div>'
    );
  }

  global.CivicKpi = {
    escapeHtml: escapeHtml,
    toneFromScore: toneFromScore,
    resolutionTone: resolutionTone,
    openIssuesTone: openIssuesTone,
    renderKpiCard: renderKpiCard,
    renderTrendCard: renderTrendCard,
    renderGaugeCard: renderGaugeCard,
  };
})(typeof window !== 'undefined' ? window : this);

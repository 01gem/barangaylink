/**
 * BarangayLink - Official dashboard charts (panel revision D3).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Loads api/stats/dashboard.php (figures only, see api/stats/_dashboard.php)
 * and draws the dashboard charts with the vendored Chart.js
 * (js/vendor/chart.umd.min.js, loaded just before this file).
 *
 * Uses these globals from js/official.js (classic script, loaded first):
 * API_BASE, fetchJson, peso, showToastAdmin; calls window.renderDashStats
 * with the KPI block after every successful load.
 *
 * window.DashboardCharts = { load, refresh, onShow, lastLoadAt } is set when
 * this file is evaluated, so the boot chain in js/official.js can call it.
 * Only the DOM wiring (range select, palette) waits for DOMContentLoaded, in
 * the top-level initDashboardChartsSafely.
 *
 * - load(range): one request; a response older than the latest request is
 *   dropped (seq). Charts are drawn only while #tab-dashboard is the active
 *   tab (a canvas in a hidden tab has no size); otherwise the payload is
 *   kept and drawn by onShow().
 * - Every redraw destroys the previous chart first. Animation only on the
 *   first draw.
 * - Server strings reach the page through textContent or the canvas only.
 * - A series that sums to 0 shows "No data yet."; without window.Chart every
 *   card shows "Charts unavailable." and nothing throws.
 * No inline event handlers (CSP).
 */
(function () {
  'use strict';

  const RANGES = ['6m', '12m', 'ytd'];
  const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const PALETTE_VARS = {
    blue: '--blue-mid', gold: '--gold', teal: '--teal', danger: '--danger', success: '--success',
    info: '--info', amber: '--amber', navy: '--navy', text2: '--text-2', text3: '--text-3', border: '--border'
  };
  const PALETTE_FALLBACK = {
    blue: '#1976D2', gold: '#D97706', teal: '#0D9488', danger: '#EF4444', success: '#059669',
    info: '#0EA5E9', amber: '#F59E0B', navy: '#151C2E', text2: '#4B5563', text3: '#64748B', border: '#C5CBD6'
  };
  // Fixed colour per key (never by position), so a status keeps its colour when others are 0.
  const SERIES = {
    request_status: [['pending', 'Pending', 'gold'], ['processing', 'Processing', 'info'], ['ready', 'Ready', 'teal'],
      ['completed', 'Completed', 'success'], ['cancelled', 'Cancelled', 'text3']],
    complaint_status: [['open', 'Open', 'danger'], ['investigating', 'Investigating', 'amber'], ['resolved', 'Resolved', 'success']],
    households_mpi: [['poor', 'Poor', 'danger'], ['not_poor', 'Not poor', 'success'], ['incomplete', 'Incomplete', 'amber'],
      ['not_assessed', 'Not assessed', 'border']],
    mediation_outcomes: [['settled', 'Settled', 'success'], ['for_follow_up', 'For follow-up', 'amber'], ['referred', 'Referred', 'info'],
      ['no_agreement', 'No agreement', 'danger']],
    residents_age_groups: [['0-17', '0–17'], ['18-59', '18–59'], ['60+', '60+'], ['unknown', 'Unknown']],
    sectors: [['pwd', 'PWD'], ['solo_parent', 'Solo parent'], ['four_ps', '4Ps'], ['senior', 'Senior (60+)']]
  };
  const CHART_IDS = ['chartTrend', 'chartRequestStatus', 'chartComplaintStatus', 'chartHouseholdMpi', 'chartRequestsByType',
    'chartComplaintsByType', 'chartAgeGroups', 'chartPurok', 'chartSectors', 'chartCollections', 'chartMediationOutcomes'];
  const EMPTY_TEXT = 'No data yet.';
  const UNAVAILABLE_TEXT = 'Charts unavailable.';
  const RANGE_ERROR = 'Unable to load statistics for that range.';

  const state = {
    range: '6m', seq: 0, payload: null, dirty: false, drawn: false, lastLoadAt: 0,
    charts: {}, palette: null, warnedNoChart: false
  };

  const $ = id => document.getElementById(id);

  // ── Pure helpers (exposed as window.DashboardChartsUI for the Node test) ──
  function monthLabel(ym) {
    const m = /^(\d{4})-(\d{2})$/.exec(String(ym || ''));
    return m ? `${MONTHS_SHORT[Number(m[2]) - 1] || m[2]} ${m[1]}` : String(ym || '');
  }

  /** 'YYYY-MM-DD HH:MM:SS' (Asia/Manila text) -> 'Nov 18, 2026 9:00 AM', parsed as text. */
  function stampLabel(stamp) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(stamp || ''));
    if (!m) return String(stamp || '');
    const hour = Number(m[4]);
    const h12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${MONTHS_SHORT[Number(m[2]) - 1]} ${Number(m[3])}, ${m[1]} ${h12}:${m[5]} ${hour < 12 ? 'AM' : 'PM'}`;
  }

  const num = value => {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
  };
  const sum = values => values.reduce((total, value) => total + num(value), 0);
  const money = value => (typeof peso === 'function' ? peso(value) : `₱${num(value).toFixed(2)}`);
  const wholePeso = value => `₱${Math.round(num(value)).toLocaleString('en-US')}`;

  function readPalette() {
    const out = {};
    let styles = null;
    try {
      styles = window.getComputedStyle ? window.getComputedStyle(document.documentElement) : null;
    } catch (error) {
      styles = null;
    }
    Object.keys(PALETTE_VARS).forEach(key => {
      const value = styles ? String(styles.getPropertyValue(PALETTE_VARS[key]) || '').trim() : '';
      out[key] = value || PALETTE_FALLBACK[key];
    });
    return out;
  }
  const palette = () => (state.palette ||= readPalette());

  // ── Chart configs ──
  function pctTooltip() {
    return {
      callbacks: {
        label(item) {
          const values = item.dataset.data.map(num);
          const total = sum(values);
          const value = num(item.raw);
          const pct = total > 0 ? Math.round(value / total * 1000) / 10 : 0;
          return `${item.label}: ${value} (${pct}%)`;
        }
      }
    };
  }

  function roundConfig(type, block, series) {
    const colors = palette();
    const data = series.map(([key]) => num(block?.[key]));
    return {
      total: sum(data),
      config: {
        type,
        data: {
          labels: series.map(([, label]) => label),
          datasets: [{ data, backgroundColor: series.map(([, , color]) => colors[color]), borderWidth: 1 }]
        },
        options: { plugins: { legend: { position: 'bottom' }, tooltip: pctTooltip() } }
      }
    };
  }

  function barConfig(labels, values, horizontal, datasetLabel) {
    const data = values.map(num);
    return {
      total: sum(data),
      config: {
        type: 'bar',
        data: { labels, datasets: [{ label: datasetLabel, data, backgroundColor: palette().blue, borderRadius: 4, maxBarThickness: 36 }] },
        options: {
          indexAxis: horizontal ? 'y' : 'x',
          plugins: { legend: { display: false } },
          scales: { [horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      }
    };
  }

  function listBar(rows, horizontal, datasetLabel) {
    const list = Array.isArray(rows) ? rows : [];
    return barConfig(list.map(row => String(row?.label ?? '')), list.map(row => row?.count), horizontal, datasetLabel);
  }

  function keyedBar(block, series, datasetLabel) {
    return barConfig(series.map(([, label]) => label), series.map(([key]) => block?.[key]), false, datasetLabel);
  }

  function trendConfig(payload) {
    const colors = palette();
    const requests = (payload.requests_monthly || []).map(num);
    const complaints = (payload.complaints_monthly || []).map(num);
    // Monotone keeps the curve between the data points (no dip below a count).
    const line = (label, data, color) => ({ label, data, borderColor: color, backgroundColor: color, cubicInterpolationMode: 'monotone',
      fill: false, pointRadius: 3 });
    return {
      total: sum(requests) + sum(complaints),
      config: {
        type: 'line',
        data: {
          labels: (payload.months || []).map(monthLabel),
          datasets: [line('Document requests', requests, colors.blue), line('Complaints', complaints, colors.danger)]
        },
        options: {
          interaction: { mode: 'index', intersect: false },
          plugins: { legend: { position: 'bottom' } },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      }
    };
  }

  function collectionsConfig(payload) {
    const colors = palette();
    const block = payload.collections_monthly || {};
    const documents = (block.documents || []).map(num);
    const complaints = (block.complaints || []).map(num);
    return {
      total: sum(documents) + sum(complaints),
      config: {
        type: 'bar',
        data: {
          labels: (payload.months || []).map(monthLabel),
          datasets: [
            { label: 'Documents', data: documents, backgroundColor: colors.blue, stack: 'fees' },
            { label: 'Complaints', data: complaints, backgroundColor: colors.teal, stack: 'fees' }
          ]
        },
        options: {
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { position: 'bottom' },
            tooltip: {
              callbacks: {
                label: item => `${item.dataset.label}: ${money(item.raw)}`,
                footer: items => `Total: ${money(sum(items.map(item => item.raw)))}`
              }
            }
          },
          scales: {
            x: { stacked: true },
            // Axis in whole pesos (₱1,000); tooltips keep peso() with centavos.
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0, callback: value => wholePeso(value) } }
          }
        }
      }
    };
  }

  /** id -> {total, config} for every chart of a payload. */
  function chartConfigs(payload) {
    return {
      chartTrend: trendConfig(payload),
      chartRequestStatus: roundConfig('doughnut', payload.request_status, SERIES.request_status),
      chartComplaintStatus: roundConfig('doughnut', payload.complaint_status, SERIES.complaint_status),
      chartHouseholdMpi: roundConfig('pie', payload.households_mpi, SERIES.households_mpi),
      chartRequestsByType: listBar(payload.requests_by_type, true, 'Requests'),
      chartComplaintsByType: listBar(payload.complaints_by_type, true, 'Complaints'),
      chartAgeGroups: keyedBar(payload.residents_age_groups, SERIES.residents_age_groups, 'Residents'),
      chartPurok: listBar(payload.residents_by_purok, true, 'Residents'),
      chartSectors: keyedBar(payload.sectors, SERIES.sectors, 'Residents'),
      chartCollections: collectionsConfig(payload),
      chartMediationOutcomes: roundConfig('doughnut', payload.mediation_outcomes, SERIES.mediation_outcomes)
    };
  }

  // ── Drawing ──
  function cardParts(id) {
    const canvas = $(id);
    if (!canvas) return null;
    const box = canvas.parentElement;
    const card = box ? box.parentElement : null;
    const empty = card ? card.querySelector('.dash-chart-empty') : null;
    return { canvas, box, empty };
  }

  function showMessage(parts, text) {
    if (parts.box) parts.box.hidden = true;
    if (parts.empty) {
      parts.empty.textContent = text;
      parts.empty.hidden = false;
    }
  }

  function destroy(id) {
    const chart = state.charts[id];
    if (!chart) return;
    try {
      chart.destroy();
    } catch (error) {
      /* a broken instance must not stop the redraw */
    }
    delete state.charts[id];
  }

  function drawChart(id, spec, animate) {
    const parts = cardParts(id);
    destroy(id);
    if (!parts) return;
    if (!(spec.total > 0)) {
      showMessage(parts, EMPTY_TEXT);
      return;
    }
    if (parts.box) parts.box.hidden = false;
    if (parts.empty) parts.empty.hidden = true;
    const options = Object.assign({ responsive: true, maintainAspectRatio: false }, spec.config.options);
    if (!animate) options.animation = false;
    state.charts[id] = new window.Chart(parts.canvas, Object.assign({}, spec.config, { options }));
  }

  function renderUpcoming(rows) {
    const el = $('dashUpcomingMediations');
    if (!el) return;
    el.textContent = '';
    const list = Array.isArray(rows) ? rows : [];
    if (!list.length) {
      const p = document.createElement('p');
      p.className = 'dash-chart-empty';
      p.textContent = 'No mediation sessions in the next 7 days.';
      el.appendChild(p);
      return;
    }
    list.forEach(row => {
      const item = document.createElement('div');
      item.className = 'mini-row';
      const text = document.createElement('div');
      text.className = 'mr-text';
      const title = document.createElement('div');
      title.className = 'mr-title';
      title.textContent = String(row?.case_no || 'No case number');
      const sub = document.createElement('div');
      sub.className = 'mr-sub';
      sub.textContent = String(row?.complaint_type ?? '');
      text.appendChild(title);
      text.appendChild(sub);
      const when = document.createElement('span');
      when.className = 'dash-upcoming-when';
      when.textContent = stampLabel(row?.starts_at);
      item.appendChild(text);
      item.appendChild(when);
      el.appendChild(item);
    });
  }

  function render() {
    const payload = state.payload;
    if (!payload) return;
    state.dirty = false;
    renderUpcoming(payload.upcoming_mediations);
    if (typeof window.Chart !== 'function') {
      CHART_IDS.forEach(id => {
        destroy(id);
        const parts = cardParts(id);
        if (parts) showMessage(parts, UNAVAILABLE_TEXT);
      });
      if (!state.warnedNoChart && window.console) window.console.warn('Dashboard charts unavailable: Chart.js did not load.');
      state.warnedNoChart = true;
      return;
    }
    const animate = !state.drawn;
    state.drawn = true;
    const configs = chartConfigs(payload);
    CHART_IDS.forEach(id => {
      try {
        drawChart(id, configs[id], animate);
      } catch (error) {
        if (window.console) window.console.warn(`Dashboard chart ${id} failed:`, error && error.message);
      }
    });
  }

  function dashboardVisible() {
    const panel = $('tab-dashboard');
    return !!(panel && panel.classList.contains('active'));
  }

  // ── Public API ──
  async function load(range) {
    const wanted = RANGES.includes(range) ? range : state.range;
    const seq = ++state.seq;
    let data;
    try {
      data = await fetchJson(`${API_BASE}/stats/dashboard.php?range=${encodeURIComponent(wanted)}`);
    } catch (error) {
      if (seq !== state.seq) return null;   // a newer request owns the dashboard now
      throw error;
    }
    if (seq !== state.seq) return null;
    state.range = wanted;
    state.payload = data;
    state.lastLoadAt = Date.now();
    if (typeof window.renderDashStats === 'function') window.renderDashStats(data.kpis);
    if (dashboardVisible()) render();
    else state.dirty = true;
    return data;
  }

  function refresh() {
    return load(state.range);
  }

  function onShow() {
    if (!state.payload) return;
    if (state.dirty) {
      render();
      return;
    }
    Object.keys(state.charts).forEach(id => {
      try {
        state.charts[id].resize();
      } catch (error) {
        /* ignore */
      }
    });
  }

  function lastLoadAt() {
    return state.lastLoadAt;
  }

  function onRangeChange(event) {
    const select = event.target;
    const previous = state.range;
    const wanted = select.value;
    load(wanted).catch(error => {
      select.value = previous;
      if (typeof showToastAdmin === 'function') showToastAdmin('Dashboard', RANGE_ERROR);
      if (window.console) window.console.warn('Dashboard range failed:', error && error.message);
    });
  }

  function initDashboardCharts() {
    state.palette = readPalette();
    const select = $('dashRange');
    if (select) {
      select.value = state.range;
      select.addEventListener('change', onRangeChange);
    }
  }

  /* Top-level and guarded: a failure here must never stop js/official.js. */
  function initDashboardChartsSafely() {
    try {
      initDashboardCharts();
    } catch (error) {
      if (window.console) window.console.error('Dashboard charts failed to start:', error);
    }
  }

  window.DashboardCharts = { load, refresh, onShow, lastLoadAt };
  window.DashboardChartsUI = { monthLabel, stampLabel, chartConfigs, SERIES, CHART_IDS };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDashboardChartsSafely);
  else initDashboardChartsSafely();
})();

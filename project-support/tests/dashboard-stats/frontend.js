'use strict';
/**
 * BarangayLink - Official dashboard KPI tiles + charts (panel revision D2/D3).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Runs the real js/official.js and js/dashboard-charts.js in a VM against a
 * small fake DOM, a fake Chart class and a scripted fetchJson. Reads the
 * dashboard markup and script order from pages/official.php.
 * No network, no database, no browser.
 *
 *   node project-support/tests/dashboard-stats/frontend.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..', '..');
const page = fs.readFileSync(path.join(root, 'pages', 'official.php'), 'utf8');
const officialSource = fs.readFileSync(path.join(root, 'js', 'official.js'), 'utf8');
const chartsSource = fs.readFileSync(path.join(root, 'js', 'dashboard-charts.js'), 'utf8');
let passed = 0; let failed = 0;
function check(name, ok, detail) {
  if (ok) { passed++; console.log('PASS ' + name); }
  else { failed++; console.log('FAIL ' + name + (detail ? ' :: ' + detail : '')); }
}
const flush = async (n = 6) => { for (let i = 0; i < n; i++) await new Promise(r => setImmediate(r)); };

const CHART_IDS = ['chartTrend', 'chartRequestStatus', 'chartComplaintStatus', 'chartHouseholdMpi', 'chartRequestsByType',
  'chartComplaintsByType', 'chartAgeGroups', 'chartPurok', 'chartSectors', 'chartCollections', 'chartMediationOutcomes'];
const PALETTE = { '--blue-mid': '#c-blue', '--gold': '#c-gold', '--teal': '#c-teal', '--danger': '#c-danger', '--success': '#c-success',
  '--info': '#c-info', '--amber': '#c-amber', '--navy': '#c-navy', '--text-2': '#c-text2', '--text-3': '#c-text3', '--border': '#c-border' };
const XSS = '<img src=x onerror=alert(1)>';

// ── Markup ────────────────────────────────────────────────────────────────
const dashStart = page.indexOf('id="tab-dashboard"');
const dashEnd = page.indexOf('<!-- ─── DOCUMENT PRICES', dashStart);
const dashMarkup = page.slice(dashStart, dashEnd);
const scriptOrder = [...page.matchAll(/<script src="\.\.\/js\/([^"?]+)\?v=<\?= filemtime\(__DIR__ \. '\/\.\.\/js\/([^']+)'\) \?>"><\/script>/g)];
const scripts = scriptOrder.map(m => m[1]);
check('page: vendor Chart.js and dashboard-charts.js load after official.js and complaint-billing.js, vendor first, filemtime cache-busting',
  scriptOrder.every(m => m[1] === m[2]) && scripts.indexOf('official.js') < scripts.indexOf('complaint-billing.js')
  && scripts.indexOf('complaint-billing.js') < scripts.indexOf('vendor/chart.umd.min.js')
  && scripts.indexOf('vendor/chart.umd.min.js') === scripts.indexOf('dashboard-charts.js') - 1, scripts.join(','));
const canvasIds = [...dashMarkup.matchAll(/<canvas id="([A-Za-z]+)" role="img" aria-label="[^"]+"><\/canvas>/g)].map(m => m[1]);
check('page: the 11 chart canvases, each with role=img and an aria-label', JSON.stringify(canvasIds) === JSON.stringify(CHART_IDS), canvasIds.join(','));
const cards = dashMarkup.split('<div class="card dash-card dash-chart-card').slice(1);
check('page: every chart card has a canvas box and a hidden "No data yet." line',
  cards.length === 11 && cards.every(c => c.includes('<div class="dash-chart-box"><canvas') && c.includes('<p class="dash-chart-empty" hidden>No data yet.</p>')));
check('page: range select 6m (default) / 12m / ytd', /<select id="dashRange"[^>]*>\s*<option value="6m" selected>Last 6 months<\/option>\s*<option value="12m">Last 12 months<\/option>\s*<option value="ytd">This year<\/option>/.test(dashMarkup));
check('page: dashboard tab adds no inline <script>/<style> and keeps the three existing cards plus upcoming mediations',
  !/<script|<style/.test(dashMarkup) && ['dashPendingReqs', 'dashOpenComplaints', 'dashActivity', 'dashUpcomingMediations', 'dashStats'].every(id => dashMarkup.includes(`id="${id}"`)));

// ── Fake DOM ──────────────────────────────────────────────────────────────
class El {
  constructor(tag, props = {}) {
    this.tagName = String(tag).toUpperCase(); this.id = ''; this.hidden = false; this.className = ''; this.value = '';
    this.children = []; this.parentElement = null; this.innerHTML = ''; this.dataset = {}; this.style = {}; this.listeners = {};
    this._text = '';
    const classes = new Set();
    this.classList = { add: n => classes.add(n), remove: n => classes.delete(n), contains: n => classes.has(n),
      toggle: (n, on) => { if (on === undefined ? !classes.has(n) : on) classes.add(n); else classes.delete(n); } };
    Object.assign(this, props);
  }
  get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
  set textContent(v) { this._text = String(v); this.children = []; }
  appendChild(child) { child.parentElement = this; this.children.push(child); return child; }
  querySelector(sel) {
    const cls = sel.replace(/^\./, '');
    for (const c of this.children) {
      if (String(c.className).split(/\s+/).includes(cls)) return c;
      const deep = c.querySelector(sel); if (deep) return deep;
    }
    return null;
  }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
}

function setup(opts = {}) {
  const byId = {};
  const created = [];
  const panel = new El('div', { id: 'tab-dashboard' });
  if (opts.visible !== false) panel.classList.add('active');
  byId['tab-dashboard'] = panel;
  for (const id of CHART_IDS) {
    const card = new El('div', { className: 'card dash-card dash-chart-card' });
    const box = card.appendChild(new El('div', { className: 'dash-chart-box' }));
    byId[id] = box.appendChild(new El('canvas', { id }));
    card.appendChild(new El('p', { className: 'dash-chart-empty', hidden: true, _text: 'No data yet.' }));
  }
  for (const id of ['dashStats', 'dashUpcomingMediations', 'dashPendingReqs', 'dashOpenComplaints', 'dashActivity']) byId[id] = new El('div', { id });
  byId.dashRange = new El('select', { id: 'dashRange', value: '6m' });
  const docListeners = [];
  const warns = []; const errors = []; const toasts = []; const fetches = []; const charts = [];
  const pending = [];
  const fakeConsole = { log() {}, info() {}, warn: (...a) => warns.push(a.join(' ')), error: (...a) => errors.push(a.join(' ')) };
  class FakeChart {
    constructor(canvas, config) { this.canvas = canvas; this.config = config; this.destroyed = false; this.resized = 0; charts.push(this); }
    destroy() { this.destroyed = true; }
    resize() { this.resized++; }
  }
  const state = { now: 1_800_000_000_000, modal: false, visibility: 'visible' };
  class FakeDate extends Date { static now() { return state.now; } }
  const window = {
    addEventListener() {}, console: fakeConsole, IS_OFFICIAL_ADMIN: false, AI_CONFIGURED: false, SMS_CONFIGURED: false,
    getComputedStyle: () => ({ getPropertyValue: name => PALETTE[name] || '' }),
  };
  if (opts.chart !== false) window.Chart = FakeChart;
  const document = {
    readyState: 'loading',
    get visibilityState() { return state.visibility; },
    documentElement: {},
    addEventListener: (type, fn) => docListeners.push({ type, fn }),
    getElementById: id => byId[id] || null,
    querySelector: sel => {
      if (sel === '.tab-panel.active') return panel.classList.contains('active') ? panel : null;
      if (sel === '.modal-overlay.show') return state.modal ? {} : null;
      return null;
    },
    querySelectorAll: () => [],
    createElement: tag => { const e = new El(tag); created.push(e); return e; },
  };
  const context = {
    console: fakeConsole, window, document, Date: FakeDate, setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, clearInterval() {},
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    fetch: async () => { throw new Error('network disabled in test'); },
    __fetch: (url) => {
      fetches.push(url);
      return new Promise((resolve, reject) => pending.push({ url, resolve, reject }));
    },
    __toast: (t, b) => toasts.push([t, b]),
  };
  vm.createContext(context);
  vm.runInContext(officialSource, context, { filename: 'official.js' });
  vm.runInContext('fetchJson = (url, options) => __fetch(url, options); showToastAdmin = (t, b) => __toast(t, b);', context);
  vm.runInContext(chartsSource, context, { filename: 'dashboard-charts.js' });
  const answer = async (index, value, fail) => {
    const p = pending[index];
    if (fail) p.reject(new Error(value)); else p.resolve(value);
    await flush();
  };
  return { context, window, document, byId, panel, created, docListeners, warns, errors, toasts, fetches, charts, pending, answer, state };
}

function payload(over = {}) {
  return Object.assign({
    range: '6m',
    months: ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
    kpis: { active_residents: 102, new_residents_this_month: 2, households_total: 38, households_profiled: 30, households_profiled_pct: 78.9,
      pending_requests: 3, in_progress_requests: 2, open_complaints: 4, mediations_next_7_days: 1,
      collected_this_month: '300.00', collected_last_month: '200.50' },
    requests_monthly: [0, 1, 1, 1, 1, 3], complaints_monthly: [1, 0, 0, 0, 1, 2],
    request_status: { pending: 3, processing: 1, ready: 1, completed: 6, cancelled: 1 },
    complaint_status: { open: 2, investigating: 2, resolved: 2 },
    households_mpi: { poor: 14, not_poor: 19, incomplete: 2, not_assessed: 3 },
    requests_by_type: [{ label: 'Barangay Clearance', count: 3 }, { label: 'Certificate of Residency', count: 2 }],
    complaints_by_type: [{ label: 'Noise / Public Disturbance', count: 2 }],
    residents_age_groups: { '0-17': 1, '18-59': 2, '60+': 1, unknown: 1 },
    residents_by_purok: [{ label: 'Purok 1', count: 2 }, { label: 'Unassigned', count: 2 }],
    sectors: { pwd: 2, solo_parent: 1, four_ps: 2, senior: 1 },
    collections_monthly: { documents: ['0.00', '0.00', '0.00', '0.00', '50.00', '100.00'], complaints: ['0.00', '0.00', '0.00', '0.00', '150.50', '200.00'] },
    waived: { documents: '30.00', complaints: '80.00' },
    mediation_outcomes: { settled: 1, for_follow_up: 1, referred: 1, no_agreement: 0 },
    upcoming_mediations: [{ case_no: 'BC-2026-0001', starts_at: '2026-11-18 09:00:00', complaint_type: 'Noise / Public Disturbance' }],
  }, over);
}
function zeroPayload() {
  return payload({
    kpis: { active_residents: 0, new_residents_this_month: 0, households_total: 0, households_profiled: 0, households_profiled_pct: null,
      pending_requests: 0, in_progress_requests: 0, open_complaints: 0, mediations_next_7_days: 0, collected_this_month: '0.00', collected_last_month: '0.00' },
    requests_monthly: [0, 0, 0, 0, 0, 0], complaints_monthly: [0, 0, 0, 0, 0, 0],
    request_status: { pending: 0, processing: 0, ready: 0, completed: 0, cancelled: 0 },
    complaint_status: { open: 0, investigating: 0, resolved: 0 },
    households_mpi: { poor: 0, not_poor: 0, incomplete: 0, not_assessed: 0 },
    requests_by_type: [], complaints_by_type: [], residents_by_purok: [],
    residents_age_groups: { '0-17': 0, '18-59': 0, '60+': 0, unknown: 0 },
    sectors: { pwd: 0, solo_parent: 0, four_ps: 0, senior: 0 },
    collections_monthly: { documents: Array(6).fill('0.00'), complaints: Array(6).fill('0.00') },
    mediation_outcomes: { settled: 0, for_follow_up: 0, referred: 0, no_agreement: 0 },
    upcoming_mediations: [],
  });
}
const emptyOf = (env, id) => env.byId[id].parentElement.parentElement.querySelector('.dash-chart-empty');
const boxOf = (env, id) => env.byId[id].parentElement;
const live = env => env.charts.filter(c => !c.destroyed);
const byCanvas = (env, id) => live(env).find(c => c.canvas.id === id);

/** Replace every boot step except renderDashStats/bootDashboardStats with a recording stub, then fire DOMContentLoaded. */
function bootNames() {
  const start = officialSource.lastIndexOf("document.addEventListener('DOMContentLoaded', () => {");
  const block = officialSource.slice(start);
  return [...new Set([...block.matchAll(/\b((?:init|load|render|restore|start|refresh)[A-Za-z]+)\(/g)].map(m => m[1]))]
    .filter(n => !['renderDashStats', 'bootDashboardStats'].includes(n));
}
async function boot(env) {
  const names = bootNames();
  env.context.__calls = [];
  for (const name of names) vm.runInContext(`${name} = function () { __calls.push('${name}'); return Promise.resolve(); };`, env.context);
  for (const l of env.docListeners.filter(l => l.type === 'DOMContentLoaded')) l.fn();
  await flush();
  return names;
}

(async () => {
  // ── 1. Evaluation and boot ─────────────────────────────────────────────
  {
    const env = setup();
    check('both scripts evaluate without throwing; no console error', env.errors.length === 0, env.errors.join(' | '));
    check('window.DashboardCharts exists before DOMContentLoaded with load/refresh/onShow/lastLoadAt functions',
      ['load', 'refresh', 'onShow', 'lastLoadAt'].every(k => typeof env.window.DashboardCharts?.[k] === 'function')
      && env.docListeners.filter(l => l.type === 'DOMContentLoaded').length === 2);
    check('window.renderDashStats is exposed by official.js', typeof env.window.renderDashStats === 'function');
    const names = await boot(env);
    const inits = names.filter(n => n.startsWith('init'));
    check('boot chain still calls every init (' + inits.length + ')', inits.length >= 15 && inits.every(n => env.context.__calls.includes(n)),
      inits.filter(n => !env.context.__calls.includes(n)).join(','));
    check('boot calls DashboardCharts.load(\'6m\') exactly once (one stats request)',
      env.fetches.filter(u => u.includes('/stats/dashboard.php')).length === 1 && env.fetches[0] === '../api/stats/dashboard.php?range=6m', env.fetches.join(','));
    check('KPI tiles show "—" before the first stats load', (env.byId.dashStats.innerHTML.match(/<div class="sc-val">—<\/div>/g) || []).length === 6);
    check('range select is wired after DOMContentLoaded', (env.byId.dashRange.listeners.change || []).length === 1);
    await env.answer(0, payload());
    check('after the boot load: 11 charts, KPI tiles filled', live(env).length === 11 && env.byId.dashStats.innerHTML.includes('78.9%'));
  }

  // ── 2. Chart configs ──────────────────────────────────────────────────
  {
    const env = setup();
    env.window.DashboardCharts.load('6m');
    await env.answer(0, payload());
    const types = CHART_IDS.map(id => byCanvas(env, id)?.config.type);
    check('chart types: line, doughnut x2, pie, bar x6, doughnut',
      JSON.stringify(types) === JSON.stringify(['line', 'doughnut', 'doughnut', 'pie', 'bar', 'bar', 'bar', 'bar', 'bar', 'bar', 'doughnut']), types.join(','));
    const trend = byCanvas(env, 'chartTrend').config;
    check('trend: months as "Jun 2026", requests blue + complaints danger',
      trend.data.labels[0] === 'Jun 2026' && trend.data.labels[5] === 'Nov 2026'
      && trend.data.datasets[0].borderColor === '#c-blue' && trend.data.datasets[1].borderColor === '#c-danger'
      && JSON.stringify(trend.data.datasets[0].data) === '[0,1,1,1,1,3]');
    const req = byCanvas(env, 'chartRequestStatus').config.data;
    check('request status: colours fixed by key',
      JSON.stringify(req.datasets[0].backgroundColor) === JSON.stringify(['#c-gold', '#c-info', '#c-teal', '#c-success', '#c-text3'])
      && JSON.stringify(req.datasets[0].data) === '[3,1,1,6,1]');
    check('complaint status: open danger, investigating amber, resolved success',
      JSON.stringify(byCanvas(env, 'chartComplaintStatus').config.data.datasets[0].backgroundColor) === JSON.stringify(['#c-danger', '#c-amber', '#c-success']));
    const mpi = byCanvas(env, 'chartHouseholdMpi').config.data;
    check('MPI pie: Poor / Not poor / Incomplete / Not assessed with danger/success/amber/border',
      JSON.stringify(mpi.labels) === JSON.stringify(['Poor', 'Not poor', 'Incomplete', 'Not assessed'])
      && JSON.stringify(mpi.datasets[0].backgroundColor) === JSON.stringify(['#c-danger', '#c-success', '#c-amber', '#c-border']));
    const med = byCanvas(env, 'chartMediationOutcomes').config.data;
    check('mediation outcomes: labels and success/amber/info/danger',
      JSON.stringify(med.labels) === JSON.stringify(['Settled', 'For follow-up', 'Referred', 'No agreement'])
      && JSON.stringify(med.datasets[0].backgroundColor) === JSON.stringify(['#c-success', '#c-amber', '#c-info', '#c-danger']));
    const tip = byCanvas(env, 'chartRequestStatus').config.options.plugins.tooltip.callbacks.label({ label: 'Completed', raw: 6, dataset: req.datasets[0] });
    check('doughnut tooltip shows count and %', tip === 'Completed: 6 (50%)', tip);
    const axis = id => byCanvas(env, id).config.options.indexAxis;
    check('by-type and purok bars are horizontal; age and sectors vertical; all blue',
      ['chartRequestsByType', 'chartComplaintsByType', 'chartPurok'].every(id => axis(id) === 'y')
      && ['chartAgeGroups', 'chartSectors'].every(id => axis(id) === 'x')
      && ['chartRequestsByType', 'chartComplaintsByType', 'chartPurok', 'chartAgeGroups', 'chartSectors']
        .every(id => byCanvas(env, id).config.data.datasets[0].backgroundColor === '#c-blue'));
    check('purok labels come from the payload as data (canvas text)',
      JSON.stringify(byCanvas(env, 'chartPurok').config.data.labels) === JSON.stringify(['Purok 1', 'Unassigned']));
    const col = byCanvas(env, 'chartCollections').config;
    const items = [{ raw: 50, dataset: col.data.datasets[0] }, { raw: 150.5, dataset: col.data.datasets[1] }];
    check('collections: stacked Documents (blue) / Complaints (teal), amounts as numbers, peso tooltip + month total footer',
      col.options.scales.x.stacked && col.options.scales.y.stacked
      && col.data.datasets[0].label === 'Documents' && col.data.datasets[0].backgroundColor === '#c-blue'
      && col.data.datasets[1].label === 'Complaints' && col.data.datasets[1].backgroundColor === '#c-teal'
      && JSON.stringify(col.data.datasets[1].data) === '[0,0,0,0,150.5,200]'
      && col.options.plugins.tooltip.callbacks.label(items[1]) === 'Complaints: ₱150.50'
      && col.options.plugins.tooltip.callbacks.footer(items) === 'Total: ₱200.50'
      && col.options.scales.y.ticks.callback(100) === '₱100' && col.options.scales.y.ticks.callback(1000) === '₱1,000'
      && col.options.scales.y.ticks.callback(1234.56) === '₱1,235' && col.options.scales.y.precision === undefined
      && col.options.scales.y.ticks.precision === 0);
    check('every chart: responsive, no aspect ratio, animated on the first draw',
      live(env).every(c => c.config.options.responsive === true && c.config.options.maintainAspectRatio === false && c.config.options.animation === undefined));
    const up = env.byId.dashUpcomingMediations;
    check('upcoming mediations: case no, type and "Nov 18, 2026 9:00 AM"',
      up.children.length === 1 && up.textContent === 'BC-2026-0001Noise / Public DisturbanceNov 18, 2026 9:00 AM', up.textContent);
    const first = live(env).slice();
    env.window.DashboardCharts.refresh();
    await env.answer(1, payload());
    check('refresh destroys every previous chart and redraws 11 without animation',
      first.every(c => c.destroyed) && live(env).length === 11 && live(env).every(c => c.config.options.animation === false));
  }

  // ── 3. Empty state, hidden tab, stale, no Chart.js ────────────────────
  {
    const env = setup();
    env.window.DashboardCharts.load('6m');
    await env.answer(0, zeroPayload());
    check('all-zero payload: no chart drawn, every "No data yet." visible, every box hidden',
      live(env).length === 0 && CHART_IDS.every(id => !emptyOf(env, id).hidden && emptyOf(env, id).textContent === 'No data yet.' && boxOf(env, id).hidden));
    check('all-zero payload: "No mediation sessions in the next 7 days."', env.byId.dashUpcomingMediations.textContent === 'No mediation sessions in the next 7 days.');
    check('all-zero payload: households profiled "—"', /<div class="sc-val">—<\/div>\s*<div class="sc-lbl">Households Profiled/.test(env.byId.dashStats.innerHTML));
    env.window.DashboardCharts.refresh();
    await env.answer(1, payload());
    check('data after an empty load: boxes shown again, empty lines hidden', live(env).length === 11 && CHART_IDS.every(id => emptyOf(env, id).hidden && !boxOf(env, id).hidden));
  }
  {
    const env = setup({ visible: false });
    env.window.DashboardCharts.load('6m');
    await env.answer(0, payload());
    check('hidden dashboard tab: no chart created on load (KPIs still filled)', env.charts.length === 0 && env.byId.dashStats.innerHTML.includes('78.9%'));
    env.panel.classList.add('active');
    env.window.DashboardCharts.onShow();
    check('onShow() draws the stored payload', live(env).length === 11);
    env.window.DashboardCharts.onShow();
    check('onShow() again only resizes', live(env).length === 11 && live(env).every(c => c.resized === 1));
  }
  {
    const env = setup();
    env.context.__stats = [];
    vm.runInContext('window.renderDashStats = k => __stats.push(k && k.active_residents);', env.context);
    env.window.DashboardCharts.load('6m');
    env.window.DashboardCharts.load('12m');
    await env.answer(1, payload({ range: '12m', kpis: Object.assign(payload().kpis, { active_residents: 12 }) }));
    await env.answer(0, payload({ kpis: Object.assign(payload().kpis, { active_residents: 6 }) }));
    check('stale response (older seq) is dropped: only the newer payload is drawn',
      JSON.stringify(env.context.__stats) === '[12]' && env.charts.length === 11);
  }
  {
    const env = setup({ chart: false });
    let threw = false;
    try { env.window.DashboardCharts.load('6m'); await env.answer(0, payload()); } catch { threw = true; }
    check('window.Chart missing: no throw, every card says "Charts unavailable.", one console.warn',
      !threw && CHART_IDS.every(id => !emptyOf(env, id).hidden && emptyOf(env, id).textContent === 'Charts unavailable.')
      && env.warns.filter(w => w.includes('Chart.js did not load')).length === 1, env.warns.join(' | '));
  }

  // ── 4. KPI tiles ──────────────────────────────────────────────────────
  {
    const env = setup();
    const render = k => { env.window.renderDashStats(k); return env.byId.dashStats.innerHTML; };
    const html = render(payload().kpis);
    check('KPI: six real tiles with subtitles',
      ['Active Residents', '+2 new this month', 'Households Profiled', '78.9%', '30 of 38', 'Pending Requests', '2 in progress',
        'Open Complaints', 'open + investigating', 'Mediations — Next 7 Days', 'Collected This Month', '₱300.00', 'vs ₱200.50 last month']
        .every(s => html.includes(s)) && (html.match(/class="stat-card"/g) || []).length === 6);
    check('KPI: no hardcoded fake trend text', !/0 this month|0 pending|0 open|0 verified|fa-arrow-trend-up/.test(html));
    const nullPct = render(Object.assign(payload().kpis, { households_profiled_pct: null, households_total: 0, households_profiled: 0 }));
    check('KPI: pct null -> "—" and no "0 of 0"', /<div class="sc-val">—<\/div>\s*<div class="sc-lbl">Households Profiled/.test(nullPct) && !nullPct.includes('0 of 0'));
    const before = render(undefined);
    check('KPI: undefined -> every value "—", no subtitles', (before.match(/<div class="sc-val">—<\/div>/g) || []).length === 6 && !before.includes('sc-sub'));
    const evil = render(Object.assign(payload().kpis, { active_residents: XSS, new_residents_this_month: XSS, collected_this_month: XSS }));
    check('XSS: strings in KPI figures never reach the HTML', !evil.includes('<img') && !evil.includes('onerror'));
  }

  // ── 5. XSS + activity date in the list cards ──────────────────────────
  {
    const env = setup();
    env.window.DashboardCharts.load('6m');
    await env.answer(0, payload({ upcoming_mediations: [{ case_no: XSS, starts_at: '2026-11-18 14:05:00', complaint_type: XSS }] }));
    const up = env.byId.dashUpcomingMediations;
    check('XSS: upcoming mediations render server strings as text (no element created from them)',
      up.textContent.startsWith(XSS) && up.textContent.includes('2:05 PM') && up.innerHTML === ''
      && env.created.every(e => ['DIV', 'SPAN', 'P'].includes(e.tagName)));
    vm.runInContext(`
      DOC_REQUESTS = [{ status: 'pending', resident: ${JSON.stringify(XSS)}, type: ${JSON.stringify(XSS)}, date: ${JSON.stringify(XSS)} }];
      COMPLAINTS_ADMIN = [{ status: 'open', cat: ${JSON.stringify(XSS)}, filer: ${JSON.stringify(XSS)}, loc: ${JSON.stringify(XSS)} }];
      AUDIT_LOG = [{ ts: '2026-11-17 08:44:10', user: ${JSON.stringify(XSS)}, action: ${JSON.stringify(XSS)}, module: ${JSON.stringify(XSS)} },
                   { ts: 'not a stamp <b>', user: 'A', action: 'Login', module: 'auth' }];
      renderDashPendingReqs(); renderDashOpenComplaints(); renderDashActivity();`, env.context);
    for (const id of ['dashPendingReqs', 'dashOpenComplaints', 'dashActivity']) {
      const html = env.byId[id].innerHTML;
      check(`XSS: ${id} escapes every interpolated value`, html.includes('&lt;img src=x onerror=alert(1)&gt;') && !html.includes('<img'), html.slice(0, 160));
    }
    const activity = env.byId.dashActivity.innerHTML;
    check('activity (AUDIT_LOG[].ts) shows date + time "Nov 17 · 08:44"; an unparsable stamp is shown escaped',
      activity.includes('<div class="act-time">Nov 17 · 08:44</div>') && activity.includes('not a stamp &lt;b&gt;'));
  }

  // ── 6. Range select ───────────────────────────────────────────────────
  {
    const env = setup();
    await boot(env);
    await env.answer(0, payload());
    const select = env.byId.dashRange;
    const before = live(env).slice();
    select.value = '12m';
    select.listeners.change[0]({ target: select });
    await flush();
    check('range change requests ?range=12m', env.fetches[1] === '../api/stats/dashboard.php?range=12m', env.fetches.join(','));
    await env.answer(1, 'Server request failed (HTTP 500).', true);
    check('range change failure: select reverts to 6m, charts kept, toast + console.warn',
      select.value === '6m' && before.every(c => !c.destroyed) && live(env).length === 11
      && env.toasts.length === 1 && env.toasts[0][0] === 'Dashboard' && env.toasts[0][1] === 'Unable to load statistics for that range.'
      && env.warns.some(w => w.includes('Dashboard range failed')), JSON.stringify(env.toasts));
    select.value = 'ytd';
    select.listeners.change[0]({ target: select });
    await flush();
    await env.answer(2, payload({ range: 'ytd' }));
    env.window.DashboardCharts.refresh();
    await flush();
    check('range change success: later refresh() keeps the new range', env.fetches[3] === '../api/stats/dashboard.php?range=ytd', env.fetches.join(','));
  }

  // ── 7. Poll hook ──────────────────────────────────────────────────────
  {
    const env = setup();
    vm.runInContext(`loadComplaints = () => Promise.resolve(); refreshDocRequestsTable = () => {}; refreshComplaintsAdmin = () => {};
      refreshDashboard = async () => {};`, env.context);
    await boot(env);
    await env.answer(0, 'boot failed', true);
    check('boot load rejects: console.warn, lastLoadAt() stays 0, boot settled',
      env.window.DashboardCharts.lastLoadAt() === 0 && env.warns.some(w => w.includes('Dashboard stats unavailable')) && vm.runInContext('dashStatsBooted', env.context));
    const statsCalls = () => env.fetches.filter(u => u.includes('/stats/dashboard.php')).length;
    const backoff = () => vm.runInContext('pollBackoff', env.context);
    const poll = async () => { vm.runInContext('pollOfficialUpdates()', env.context); await flush(); };
    const answerRequests = async () => {
      for (let i = 0; i < env.pending.length; i++) {
        if (env.pending[i].url.includes('/requests/list.php') && !env.pending[i].done) { env.pending[i].done = true; env.pending[i].resolve({ requests: [] }); }
      }
      await flush();
    };
    await poll();
    check('next dashboard poll after a failed boot load calls refresh()', statsCalls() === 2);
    const last = env.pending.length - 1;
    const statsIndex = env.pending.findIndex((p, i) => i > 0 && p.url.includes('/stats/dashboard.php'));
    await env.answer(statsIndex, 'still failing', true);
    await answerRequests();
    check('a failing stats refresh never changes the main poll backoff', backoff() === 5000, String(backoff()));
    await poll(); await answerRequests();
    check('still failing (lastLoadAt 0): the next poll retries', statsCalls() === 3);
    const third = env.pending.findIndex((p, i) => i > statsIndex && p.url.includes('/stats/dashboard.php'));
    await env.answer(third, payload());
    await answerRequests();
    await poll(); await answerRequests();
    check('within 60s of a successful load: no refresh', statsCalls() === 3);
    env.state.now += 60000;
    await poll(); await answerRequests();
    check('60s after the last load: refresh()', statsCalls() === 4);
    env.state.now += 120000; env.state.modal = true;
    await poll();
    check('modal open: no refresh', statsCalls() === 4);
    env.state.modal = false; env.state.visibility = 'hidden';
    await poll();
    check('page hidden: no refresh', statsCalls() === 4);
    env.state.visibility = 'visible'; env.panel.classList.remove('active');
    await poll();
    check('another tab active: no refresh', statsCalls() === 4);
    void last;
  }

  console.log(`DASHBOARD FRONTEND ${passed} passed, ${failed} failed`);
  process.exit(failed === 0 ? 0 : 1);
})().catch(error => {
  console.log('FAIL unexpected error :: ' + (error && error.stack || error));
  process.exit(1);
});

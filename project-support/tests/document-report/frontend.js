'use strict';
/**
 * BarangayLink - AI hub tiles without an AI key (Phase 6.1 follow-up).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Runs the real initAiHub() from js/official.js in a VM against the tile list
 * read from pages/official.php. No network, no database.
 *
 *   node project-support/tests/document-report/frontend.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..', '..');
const page = fs.readFileSync(path.join(root, 'pages', 'official.php'), 'utf8');
const source = fs.readFileSync(path.join(root, 'js', 'official.js'), 'utf8');
let passed = 0; let failed = 0;
function check(name, ok, detail) {
  if (ok) { passed++; console.log('PASS ' + name); }
  else { failed++; console.log('FAIL ' + name + (detail ? ' :: ' + detail : '')); }
}

const tileViews = [...page.matchAll(/<button[^>]*class="ai-tool-tile[^"]*"[^>]*data-ai-view="([a-z]+)"/g)].map(m => m[1]);

function run(aiConfigured) {
  const listeners = [];
  const node = (extra = {}) => {
    const classes = new Set();
    return Object.assign({ disabled: false, title: '', textContent: '', dataset: {},
      classList: { toggle(name, on) { if (on) classes.add(name); else classes.delete(name); }, contains: name => classes.has(name) },
      addEventListener(type, fn) { listeners.push({ node: this, type, fn }); } }, extra);
  };
  const tiles = tileViews.map(view => node({ dataset: { aiView: view } }));
  const inserted = [];
  const parent = { querySelector: () => (inserted.length ? inserted[0] : null), insertBefore: n => inserted.push(n) };
  const hub = { parentElement: parent, querySelectorAll: sel => (sel === 'button.ai-tool-tile' ? tiles : []) };
  const byId = {
    aiHub: hub,
    docReportsAiBtn: node(), docReportsAiOutput: node({ textContent: 'No commentary generated yet.' }),
    complaintReportsAiBtn: node(), complaintReportsAiOutput: node({ textContent: 'No commentary generated yet.' }),
  };
  const context = {
    console, setTimeout, clearTimeout, setInterval: () => 0, clearInterval() {},
    window: { AI_CONFIGURED: aiConfigured, SMS_CONFIGURED: false, addEventListener() {} },
    document: {
      addEventListener() {},
      getElementById: id => byId[id] || null,
      querySelectorAll: sel => (sel === 'button[data-ai-view]' ? tiles : []),
      createElement: () => ({ className: '', textContent: '' }),
    },
    localStorage: { getItem: () => null, setItem() {} }, sessionStorage: { getItem: () => null, setItem() {} },
    fetch: async () => ({}),
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  const opened = [];
  vm.runInContext('showAiView = v => __opened.push(v);', Object.assign(context, { __opened: opened }));
  vm.runInContext('initAiHub()', context);
  return { tiles, byId, inserted, listeners, opened };
}

check('page has the five AI hub tiles', JSON.stringify(tileViews) === JSON.stringify(['registry', 'workforce', 'docreports', 'complaintreports', 'echo']), tileViews.join(','));

const off = run(false);
const enabled = off.tiles.filter(t => !t.disabled).map(t => t.dataset.aiView);
check('AI off: only the Document Requests and Complaints report tiles stay enabled',
  JSON.stringify(enabled) === JSON.stringify(['docreports', 'complaintreports']), enabled.join(','));
check('AI off: AI-only and mixed tiles (registry, workforce, echo) are disabled',
  off.tiles.filter(t => ['registry', 'workforce', 'echo'].includes(t.dataset.aiView)).every(t => t.disabled));
check('AI off: disabled tiles carry is-disabled (greyed out), the two report tiles do not',
  off.tiles.every(t => t.classList.contains('is-disabled') === t.disabled));
const docTile = off.tiles.find(t => t.dataset.aiView === 'docreports');
off.listeners.filter(l => l.node === docTile && l.type === 'click').forEach(l => l.fn());
const complaintTile = off.tiles.find(t => t.dataset.aiView === 'complaintreports');
off.listeners.filter(l => l.node === complaintTile && l.type === 'click').forEach(l => l.fn());
check('AI off: clicking the two report tiles opens their reports', JSON.stringify(off.opened) === JSON.stringify(['docreports', 'complaintreports']), off.opened.join(','));
const hint = 'AI features are disabled in this build (GEMINI_API_KEY not configured).';
check('AI off: both "Generate AI Commentary" buttons are disabled with the hint',
  off.byId.docReportsAiBtn.disabled && off.byId.complaintReportsAiBtn.disabled
  && off.byId.docReportsAiBtn.title === hint && off.byId.complaintReportsAiBtn.title === hint);
check('AI off: both commentary boxes show the hint and the hub note is added once',
  off.byId.docReportsAiOutput.textContent === hint && off.byId.complaintReportsAiOutput.textContent === hint
  && off.inserted.length === 1 && off.inserted[0].textContent === hint);

const on = run(true);
check('AI on: every tile and both commentary buttons stay enabled, no note',
  on.tiles.every(t => !t.disabled) && !on.byId.docReportsAiBtn.disabled && !on.byId.complaintReportsAiBtn.disabled
  && on.inserted.length === 0 && on.byId.docReportsAiOutput.textContent === 'No commentary generated yet.');

console.log(`AI HUB ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);

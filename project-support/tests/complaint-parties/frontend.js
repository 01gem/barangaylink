'use strict';
/**
 * BarangayLink - Walk-in complaints and complaint parties, browser-side logic (complaint revision Phase 5).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Loads js/official.js, js/complaint-types.js and js/complaint-walkin.js in a VM
 * with a stub DOM (no browser, no network), the same way
 * complaint-types/frontend.js does, and checks the request bodies against what
 * api/complaints/_parties.php and create_official.php accept.
 *
 *   node project-support/tests/complaint-parties/frontend.js
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.join(__dirname, '../../..');
let passed = 0; let failed = 0;
function check(name, ok, detail = '') {
  if (ok) { passed++; console.log('PASS ' + name); }
  else { failed++; console.log('FAIL ' + name + (detail === '' ? '' : ': ' + detail)); }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const elements = new Map();
const el = id => {
  if (!elements.has(id)) elements.set(id, { id, textContent: '', innerHTML: '', value: '', disabled: false, hidden: false, style: {}, dataset: {},
    classList: { contains: () => false, add() {}, remove() {}, toggle() {} }, addEventListener() {}, appendChild() {}, setAttribute() {},
    querySelectorAll: () => [], querySelector: () => null, closest: () => null });
  return elements.get(id);
};
const listeners = [];
const context = vm.createContext({
  console: { log() {}, warn() {}, error() {} }, setTimeout, clearTimeout, setInterval, clearInterval,
  document: { readyState: 'loading', addEventListener: (type, fn) => listeners.push([type, fn]), getElementById: el,
    querySelectorAll: () => [], querySelector: () => null, createElement: () => el('created'), body: el('body'), visibilityState: 'visible' },
  window: { confirm: () => true, prompt: () => null, addEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {} }) },
  sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} }, localStorage: { getItem: () => null, setItem() {} },
  fetch: async () => ({}), URL, URLSearchParams, navigator: {}, location: { href: '' },
});
vm.runInContext('var window = this.window; window.document = this.document;', context);
const officialSource = fs.readFileSync(path.join(root, 'js/official.js'), 'utf8');
const typesSource = fs.readFileSync(path.join(root, 'js/complaint-types.js'), 'utf8');
const walkinSource = fs.readFileSync(path.join(root, 'js/complaint-walkin.js'), 'utf8');
vm.runInContext(officialSource, context, { filename: 'official.js' });
vm.runInContext(typesSource, context, { filename: 'complaint-types.js' });
vm.runInContext(walkinSource, context, { filename: 'complaint-walkin.js' });
const ui = vm.runInContext('window.ComplaintWalkinUI', context);
const api = vm.runInContext('window.ComplaintWalkin', context);
const plain = value => JSON.parse(JSON.stringify(value));

// Loading and isolation.
check('script exposes ComplaintWalkin.openParties and the pure helpers', !!ui && !!api && typeof api.openParties === 'function');
check('escape helper comes from official.js (no second helper defined)',
  !/function\s+escapeHtml\b/.test(walkinSource) && /escapeHtml\(/.test(walkinSource)
    && vm.runInContext('typeof escapeHtml', context) === 'function');
check('no inline event handlers in the markup it writes', !/\son[a-z]+\s*=/.test(walkinSource.replace(/\/\*[\s\S]*?\*\//g, '')));
check('official.js renders the Parties block and redraws it with top-level functions',
  vm.runInContext('typeof complaintPartiesSectionHtml === "function" && typeof syncOpenComplaintParties === "function"', context));
const initEntry = listeners.find(([type, fn]) => type === 'DOMContentLoaded' && fn.name === 'initComplaintWalkinSafely');
check('init is registered on DOMContentLoaded as a top-level guarded function', !!initEntry);
let initThrew = false;
try {
  const saved = context.document.getElementById;
  context.document.getElementById = () => { throw new Error('boom'); };
  initEntry[1]();
  context.document.getElementById = saved;
} catch (error) { initThrew = true; }
check('a failure inside init is caught and does not propagate', !initThrew);

// Party rows and payloads.
const typed = ui.newPartyRow('respondent', 'unregistered');
typed.full_name = '  Pedro Santos ';
typed.address = '  ';
typed.contact_no = ' 0917 123 4567 ';
check('typed party payload: trimmed name, empty address as null, contact sent as typed (the server normalizes)',
  same(plain(ui.partyPayload(typed)), { full_name: 'Pedro Santos', address: null, contact_no: '0917 123 4567' }));
const registered = ui.newPartyRow('complainant', 'registered');
check('a registered row without a picked resident is incomplete', ui.partyPayload(registered) === null
  && ui.partyRowProblem(registered).includes('Pick a registered resident'));
registered.resident = { id: 42, full_name: 'Pia Party', purok_zone: 'Purok 4', address: 'x' };
registered.full_name = 'ignored';
check('registered party payload is only {resident_id}', same(plain(ui.partyPayload(registered)), { resident_id: 42 }));
const empty = ui.newPartyRow('complainant', 'unregistered');
check('a typed row without a name is incomplete', ui.partyPayload(empty) === null && ui.partyRowProblem(empty) === 'Enter the full name for every person.');
check('rows get distinct keys and a safe role', ui.newPartyRow('x', 'y').role === 'complainant' && ui.newPartyRow('respondent').key !== typed.key);
check('limits: 5 complainants, 10 respondents',
  ui.canAddParty(new Array(4).fill(0), 'complainant') && !ui.canAddParty(new Array(5).fill(0), 'complainant')
    && ui.canAddParty(new Array(9).fill(0), 'respondent') && !ui.canAddParty(new Array(10).fill(0), 'respondent'));

// Walk-in body.
const form = { typeId: '2', location: ' Purok 4 ', description: ' Loud music ', severity: 'high', extraFields: { noise_source: 'music' },
  respondentMode: 'optional', complainants: [registered], respondents: [typed] };
const built = ui.buildWalkinPayload(form);
check('walk-in body: integer type id, trimmed text, both party lists, no unknown keys',
  same(plain(built.payload), { complaint_type_id: 2, location_text: 'Purok 4', description: 'Loud music', severity: 'high',
    extra_fields: { noise_source: 'music' }, complainants: [{ resident_id: 42 }],
    respondents: [{ full_name: 'Pedro Santos', address: null, contact_no: '0917 123 4567' }] }), JSON.stringify(built));
check('walk-in body for a "none" type sends no respondents even if rows exist',
  same(plain(ui.buildWalkinPayload({ ...form, respondentMode: 'none' }).payload.respondents), []));
check('walk-in: a "required" type with no respondent is stopped before sending',
  ui.buildWalkinPayload({ ...form, respondentMode: 'required', respondents: [] }).problem === 'Add at least one person this complaint is about.');
check('walk-in: no complainant, no type, missing text, or an incomplete row is stopped before sending',
  ui.buildWalkinPayload({ ...form, complainants: [] }).problem === 'Add at least one complainant.'
    && ui.buildWalkinPayload({ ...form, typeId: '' }).problem === 'Select the type of concern.'
    && ui.buildWalkinPayload({ ...form, location: '  ' }).problem === 'Enter the location and the description.'
    && !!ui.buildWalkinPayload({ ...form, respondents: [empty] }).problem);

// Parties modal bodies.
check('add-party body: complaint id, role and the party fields',
  same(plain(ui.buildAddPartyPayload('13', typed).payload), { complaint_id: 13, role: 'respondent', full_name: 'Pedro Santos', address: null, contact_no: '0917 123 4567' })
    && same(plain(ui.buildAddPartyPayload(13, registered).payload), { complaint_id: 13, role: 'complainant', resident_id: 42 }));
const update = ui.buildUpdatePartyPayload(13, { id: 7, version: 3, resident_id: 99 }, { full_name: ' New Name ', address: 'Purok 1', contact_no: '' });
check('update body: id and version from the last load, never resident_id or role',
  same(plain(update.payload), { complaint_id: 13, id: 7, version: 3, full_name: 'New Name', address: 'Purok 1', contact_no: null }));
check('update with an empty name is stopped before sending', ui.buildUpdatePartyPayload(13, { id: 7, version: 3 }, { full_name: ' ' }).problem === 'Enter the full name.');
check('only the stale-version 409 counts as "changed by someone else"; the resolved 409 does not',
  ui.isStale({ status: 409, message: 'This item was changed by someone else. Reload and try again.' })
    && !ui.isStale({ status: 409, message: 'Resolved complaints cannot be changed.' })
    && !ui.isStale({ status: 422, message: 'This item was changed by someone else. Reload and try again.' }));

// The Parties block in official.js escapes every value.
const html = vm.runInContext(`complaintPartiesSectionHtml({ parties: [
  { role: 'complainant', full_name: '<img src=x onerror=alert(1)>', is_registered: false, address: '<b>a</b>', contact_no: null },
  { role: 'respondent', full_name: 'Pia Party', is_registered: true, address: 'Purok 4', contact_no: '+639171110021' } ] })`, context);
check('Parties block escapes names and addresses and shows the registration badge',
  !html.includes('<img') && html.includes('&lt;img') && html.includes('&lt;b&gt;') && html.includes('Not registered')
    && html.includes('is-registered') && html.includes('data-manage-parties'));
check('Parties block with no parties says so',
  vm.runInContext('complaintPartiesSectionHtml({ parties: [] })', context).includes('No parties recorded.'));

console.log(`${passed}/${passed + failed} passed`);
process.exit(failed === 0 ? 0 : 1);

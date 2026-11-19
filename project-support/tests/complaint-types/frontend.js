'use strict';
/**
 * BarangayLink - Complaint Types tab, browser-side logic (complaint revision Phase 4).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Loads js/official.js and js/complaint-types.js in a VM with a stub DOM (no
 * browser, no network), the same way document-forms/frontend.js does, and
 * checks the options editor and the request bodies against what
 * api/complaint_types/_admin.php accepts.
 *
 *   node project-support/tests/complaint-types/frontend.js
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
vm.runInContext(officialSource, context, { filename: 'official.js' });
vm.runInContext(typesSource, context, { filename: 'complaint-types.js' });
const ui = vm.runInContext('window.ComplaintTypesUI', context);
const api = vm.runInContext('window.ComplaintTypes', context);

// Loading and isolation.
check('script exposes ComplaintTypes.load and the pure helpers', !!ui && !!api && typeof api.load === 'function');
check('escape helper comes from official.js (no second helper defined)',
  !/function\s+escapeHtml\b/.test(typesSource) && /escapeHtml\(/.test(typesSource)
    && vm.runInContext('typeof escapeHtml', context) === 'function');
check('no inline event handlers in the markup it writes', !/\son[a-z]+\s*=/.test(typesSource.replace(/\/\*[\s\S]*?\*\//g, '')));
check('init is registered on DOMContentLoaded as a top-level guarded function',
  listeners.some(([type, fn]) => type === 'DOMContentLoaded' && fn.name === 'initComplaintTypesSafely'));
let initThrew = false;
try {
  // A broken DOM (getElementById throws) must not escape the guarded init.
  const saved = context.document.getElementById;
  context.document.getElementById = () => { throw new Error('boom'); };
  listeners.find(([type, fn]) => type === 'DOMContentLoaded' && fn.name === 'initComplaintTypesSafely')[1]();
  context.document.getElementById = saved;
} catch (error) { initThrew = true; }
check('a failure inside init is caught and does not propagate', !initThrew);

// Options editor.
const field = { id: 7, version: 3, field_type: 'select', options: [
  { value: 'dog', label: 'Dog', is_active: true, usage: 2 },
  { value: 'cat', label: 'Cat', is_active: false, usage: 0 },
] };
let rows = ui.optionRowsFromField(field);
check('saved options become saved rows with their usage', rows.length === 2 && rows.every(row => row.saved === true)
  && rows[0].usage === 2 && rows[1].is_active === false);
check('saved options cannot be removed', !ui.canRemoveOption(rows[0]) && same(ui.removeOptionRow(rows, 0), rows));
rows = ui.addOptionRow(rows);
check('"Add option" appends one unsaved, active, empty row', rows.length === 3 && rows[2].saved === false && rows[2].is_active === true && rows[2].value === '');
check('only the unsaved new row can be removed', ui.canRemoveOption(rows[2]) && ui.removeOptionRow(rows, 2).length === 2);
rows[2].value = 'goat'; rows[2].label = 'Goat';
const up = ui.moveOptionRow(rows, 2, -1);
check('move up swaps with the row above', same(up.map(row => row.value), ['dog', 'goat', 'cat']));
check('move down swaps with the row below', same(ui.moveOptionRow(rows, 0, 1).map(row => row.value), ['cat', 'dog', 'goat']));
check('moves past either end change nothing',
  same(ui.moveOptionRow(rows, 0, -1).map(row => row.value), ['dog', 'cat', 'goat'])
    && same(ui.moveOptionRow(rows, 2, 1).map(row => row.value), ['dog', 'cat', 'goat']));
check('moving does not change the original list', same(rows.map(row => row.value), ['dog', 'cat', 'goat']));
const options = ui.optionsPayload(up.map(row => (row.value === 'goat' ? { ...row, value: ' goat ', label: ' Goat ' } : row)));
check('options payload: every saved value kept, in display order, only value/label/is_active, trimmed',
  same(options, [{ value: 'dog', label: 'Dog', is_active: true }, { value: 'goat', label: 'Goat', is_active: true }, { value: 'cat', label: 'Cat', is_active: false }]));

// Request bodies.
const editBody = ui.fieldPayload(4, { label: ' Animal type ', is_required: true, max_length: '9', min_value: '1' }, field, up);
check('field update body: id + version from the last load, no key/type, select sends options only',
  same(Object.keys(editBody).sort(), ['id', 'is_required', 'label', 'options', 'type_id', 'version'])
    && editBody.id === 7 && editBody.version === 3 && editBody.type_id === 4 && editBody.label === 'Animal type' && editBody.options.length === 3);
const textCreate = ui.fieldPayload(4, { label: 'Details', field_key: 'details', field_type: 'text', is_required: false, max_length: '200', min_value: '5' }, null, []);
check('text field create body: key, type, max_length as a number, nothing from other kinds',
  same(textCreate, { type_id: 4, label: 'Details', is_required: false, field_key: 'details', field_type: 'text', max_length: 200 }));
check('text max_length left empty is not sent (server default 255)',
  !('max_length' in ui.fieldPayload(4, { label: 'D', field_key: 'd', field_type: 'text', max_length: '' }, null, [])));
const numberCreate = ui.fieldPayload(4, { label: 'How many', field_key: 'how_many', field_type: 'number', min_value: '1', max_value: '' }, null, []);
check('number field body: min as a JSON number, empty max as null',
  numberCreate.min_value === 1 && numberCreate.max_value === null && !('options' in numberCreate) && !('max_length' in numberCreate));
check('date field body has no settings',
  same(Object.keys(ui.fieldPayload(4, { label: 'Seen', field_key: 'seen_on', field_type: 'date' }, null, [])).sort(),
    ['field_key', 'field_type', 'is_required', 'label', 'type_id']));
check('a non-number in a number box is sent as typed so the server answers 422',
  ui.fieldPayload(4, { label: 'L', field_key: 'l', field_type: 'text', max_length: '1.5' }, null, []).max_length === '1.5');

const typeCreate = ui.typePayload({ type_name: ' Stray Animals ', type_code: '', description: '', respondent_mode: 'optional', sort_order: '' }, null);
check('type create body: trimmed name, no empty code, no empty sort order',
  same(typeCreate, { type_name: 'Stray Animals', description: '', respondent_mode: 'optional' }));
check('type create body sends a typed code',
  ui.typePayload({ type_name: 'X type', type_code: ' x_type ', respondent_mode: 'none' }, null).type_code === 'x_type');
const typeEdit = ui.typePayload({ type_name: 'Strays', type_code: 'ignored', description: 'd', respondent_mode: 'none', sort_order: '70' },
  { id: 6, version: 5, type_code: 'stray_animals' });
check('type update body: id + version from the last load, never a code, sort order as a number',
  same(typeEdit, { type_name: 'Strays', description: 'd', respondent_mode: 'none', sort_order: 70, id: 6, version: 5 }));

// Protected rows and conflicts.
const other = { type_code: 'other' };
check('protected rows: type "other" and Other.specify are recognised by code and key',
  ui.isOtherType(other) && !ui.isOtherType({ type_code: 'others' })
    && ui.isOtherSpecify(other, { field_key: 'specify' }) && !ui.isOtherSpecify({ type_code: 'road' }, { field_key: 'specify' }));
check('only the stale-version 409 counts as "changed by someone else"; duplicate-name 409 does not',
  ui.isStale({ status: 409, message: 'This item was changed by someone else. Reload and try again.' })
    && !ui.isStale({ status: 409, message: 'A complaint type with this name already exists.' })
    && !ui.isStale({ status: 422, message: 'This item was changed by someone else. Reload and try again.' }));
check('field keys suggested from a label follow the key pattern',
  ui.deriveKey('Animal Kind') === 'animal_kind' && ui.deriveKey('2nd floor') === 'f_2nd_floor' && ui.deriveKey('!!') === '');

console.log(`${passed}/${passed + failed} passed`);
process.exit(failed === 0 ? 0 : 1);

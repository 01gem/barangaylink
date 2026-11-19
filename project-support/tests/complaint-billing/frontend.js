'use strict';
/**
 * BarangayLink - Complaint billing, browser-side logic (complaint revision Phase 10).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Loads js/official.js, js/complaint-types.js, js/complaint-walkin.js,
 * js/hearings.js and js/complaint-billing.js in a VM with a stub DOM (no
 * browser, no network), the same way complaint-hearings/frontend.js does, and
 * checks the pure helpers: money rule, list badges ("Unpaid fee" / "Fee not
 * issued"), payloads, the Billing block, the type fee payload. Also checks the
 * resident Fee section in js/resident.js.
 *
 *   node project-support/tests/complaint-billing/frontend.js
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const nodeCrypto = require('crypto');

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
    querySelectorAll: () => [], querySelector: () => null, closest: () => null, options: [] });
  return elements.get(id);
};
const listeners = [];
const context = vm.createContext({
  console: { log() {}, warn() {}, error() {} }, setTimeout, clearTimeout, setInterval, clearInterval,
  document: { readyState: 'loading', addEventListener: (type, fn) => listeners.push([type, fn]), getElementById: el,
    querySelectorAll: () => [], querySelector: () => null, createElement: () => el('created'), body: el('body'), visibilityState: 'visible' },
  window: { confirm: () => true, prompt: () => null, addEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {} }),
    crypto: { getRandomValues: array => nodeCrypto.randomFillSync(array) } },
  sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} }, localStorage: { getItem: () => null, setItem() {} },
  fetch: async () => ({}), URL, URLSearchParams, navigator: {}, location: { href: '' }, Uint8Array,
});
vm.runInContext('var window = this.window; window.document = this.document;', context);
const sources = {};
for (const file of ['official.js', 'complaint-types.js', 'complaint-walkin.js', 'hearings.js', 'complaint-billing.js']) {
  sources[file] = fs.readFileSync(path.join(root, 'js', file), 'utf8');
  vm.runInContext(sources[file], context, { filename: file });
}
const ui = vm.runInContext('window.ComplaintBillingUI', context);
const api = vm.runInContext('window.ComplaintBilling', context);
const types = vm.runInContext('window.ComplaintTypesUI', context);
const plain = value => JSON.parse(JSON.stringify(value));
const billingSource = sources['complaint-billing.js'];

// Loading and isolation.
check('script exposes ComplaintBilling.renderBlock and the pure helpers', !!ui && !!api && typeof api.renderBlock === 'function');
check('escape helper comes from official.js (no second helper defined)',
  !/function\s+escapeHtml\b/.test(billingSource) && /escapeHtml\(/.test(billingSource));
check('no inline event handlers and no external libraries',
  !/\son[a-z]+\s*=/.test(billingSource.replace(/\/\*[\s\S]*?\*\//g, '')) && !/https?:\/\//.test(billingSource) && !/\bimport\b|\brequire\(/.test(billingSource));
const initEntry = listeners.find(([type, fn]) => type === 'DOMContentLoaded' && fn.name === 'initComplaintBillingSafely');
check('init is registered on DOMContentLoaded as a top-level guarded function', !!initEntry);
let initThrew = false;
try {
  const saved = context.document.addEventListener;
  context.document.addEventListener = () => { throw new Error('boom'); };
  initEntry[1]();
  context.document.addEventListener = saved;
} catch (error) { initThrew = true; }
check('a failure inside init is caught and does not propagate', !initThrew);
check('official.js draws the Billing slot before Mediation and loads the block',
  sources['official.js'].includes('<div data-billing-slot></div>\n            <div data-mediation-slot></div>')
    && sources['official.js'].includes("window.ComplaintBilling.renderBlock(c, box.querySelector('[data-billing-slot]'))"));

// Money rule (same as the server).
check('money ok: 50, 50.5, 50.50, 0.01, 100000', ['50', '50.5', '50.50', '0.01', '100000'].every(v => ui.moneyValid(v, false)));
check("money refused: '-1', '0', '1e2', '50.555', ' 50', '100000.01', '', '50.'",
  ['-1', '0', '1e2', '50.555', ' 50', '100000.01', '', '50.'].every(v => !ui.moneyValid(v, false)));
check('type fee allows 0', ui.moneyValid('0', true) && ui.moneyValid('0.00', true) && !ui.moneyValid('-1', true));
check('display: 50.00 -> ₱50.00; garbage -> ₱0.00', ui.money('50.00') === '₱50.00' && ui.money('<b>') === '₱0.00');

// Badges (D4).
const badge = c => plain(ui.feeBadge(c));
check('"Unpaid fee" for an unpaid bill (any status)',
  same(badge({ status: 'open', bill: { status: 'unpaid' }, typeDefaultFee: '0.00' }), { kind: 'unpaid', label: 'Unpaid fee' })
    && same(badge({ status: 'resolved', bill: { status: 'unpaid' }, typeDefaultFee: '50.00' }), { kind: 'unpaid', label: 'Unpaid fee' }));
check('"Fee not issued" for no bill + type fee > 0 + open or investigating',
  same(badge({ status: 'open', bill: null, typeDefaultFee: '50.00' }), { kind: 'not-issued', label: 'Fee not issued' })
    && same(badge({ status: 'investigating', bill: null, typeDefaultFee: '0.01' }), { kind: 'not-issued', label: 'Fee not issued' }));
check('no badge: resolved without bill, 0-fee type without bill, paid, waived, missing data',
  badge({ status: 'resolved', bill: null, typeDefaultFee: '50.00' }) === null
    && badge({ status: 'open', bill: null, typeDefaultFee: '0.00' }) === null
    && badge({ status: 'open', bill: { status: 'paid' }, typeDefaultFee: '50.00' }) === null
    && badge({ status: 'open', bill: { status: 'waived' }, typeDefaultFee: '50.00' }) === null
    && badge({ status: 'open' }) === null && badge(null) === null);
const officialRow = vm.runInContext(`complaintFeeBadgeHtml({ status: 'open', bill: null, typeDefaultFee: '50.00' })`, context);
check('official list card renders the badge through escapeHtml', officialRow.includes('Fee not issued') && officialRow.includes('is-not-issued'), officialRow);

// Payloads.
check('issue payload: trimmed amount, note only when typed',
  same(plain(ui.issuePayload('7', { amount: ' 50.00 ', billing_note: '  ' })), { complaint_id: 7, amount: '50.00' })
    && same(plain(ui.issuePayload(7, { amount: '50', billing_note: ' Filing fee ' })), { complaint_id: 7, amount: '50', billing_note: 'Filing fee' }));
check('update payload: version from the last load; note always sent ("" clears)',
  same(plain(ui.updatePayload(7, { version: 3 }, { amount: '60', billing_note: '' })), { complaint_id: 7, version: 3, amount: '60', billing_note: '' }));
check('mark paid payload: receipt only when typed',
  same(plain(ui.paidPayload(7, { version: 2 }, ' ')), { complaint_id: 7, version: 2 })
    && same(plain(ui.paidPayload(7, { version: 2 }, ' OR-1 ')), { complaint_id: 7, version: 2, receipt_no: 'OR-1' }));
check('waive payload: trimmed reason', same(plain(ui.waivePayload(7, { version: 2 }, ' indigent ')), { complaint_id: 7, version: 2, reason: 'indigent' }));
check('only the stale-version 409 counts as "changed by someone else"',
  ui.isStale({ status: 409, message: 'This item was changed by someone else. Reload and try again.' })
    && !ui.isStale({ status: 409, message: 'This fee is already settled and cannot be changed.' }));

// Block states.
const gate = 'The complaint fee must be paid or waived first.';
const notBilled = ui.blockHtml({ complaint: { id: 7, status: 'open' }, default_fee: '50.00', clear: false, blocker: gate, can_issue: true, bill: null }, null, '');
check('not billed, fee > 0: fee shown, Issue bill button, gate message',
  notBilled.includes('₱50.00') && notBilled.includes('data-bl-action="issue"') && notBilled.includes(gate), notBilled);
const issueForm = ui.blockHtml({ complaint: { id: 7, status: 'open' }, default_fee: '50.00', clear: false, blocker: gate, can_issue: true, bill: null }, 'issue', '');
check('issue form prefilled with the type fee', issueForm.includes('data-bl-form="issue"') && issueForm.includes('value="50.00"'), issueForm);
const zero = ui.blockHtml({ complaint: { id: 7, status: 'open' }, default_fee: '0.00', clear: true, blocker: null, can_issue: true, bill: null }, null, '');
check('not billed, fee 0: "No fee for this type", optional Issue bill, no gate',
  zero.includes('No fee for this type') && zero.includes('Issue bill (optional)') && !zero.includes(gate), zero);
const unpaid = ui.blockHtml({ complaint: { id: 7, status: 'open' }, default_fee: '50.00', clear: false, blocker: gate, can_issue: false,
  bill: { amount: '50.00', billing_note: '<b>note</b>', status: 'unpaid', issued_at: '2026-11-17 09:00:00', issued_by_name: 'Ana <Cruz>', version: 1 } }, null, '');
check('unpaid: amount, escaped note and name, Edit / Mark paid / Waive',
  unpaid.includes('Unpaid') && unpaid.includes('&lt;b&gt;note') && unpaid.includes('Ana &lt;Cruz&gt;') && unpaid.includes('Nov 17, 2026')
    && ['edit', 'paid', 'waive'].every(a => unpaid.includes(`data-bl-action="${a}"`)) && !unpaid.includes('<b>note'), unpaid);
const unpaidResolved = ui.blockHtml({ complaint: { id: 7, status: 'resolved' }, default_fee: '50.00', clear: false, blocker: gate, can_issue: false,
  bill: { amount: '50.00', status: 'unpaid', issued_at: '2026-11-17 09:00:00', version: 1 } }, null, '');
check('unpaid on a resolved complaint: no Edit (D2), Mark paid and Waive stay',
  !unpaidResolved.includes('data-bl-action="edit"') && unpaidResolved.includes('data-bl-action="paid"') && unpaidResolved.includes('data-bl-action="waive"'));
const paid = ui.blockHtml({ complaint: { id: 7, status: 'open' }, default_fee: '50.00', clear: true, blocker: null, can_issue: false,
  bill: { amount: '50.00', status: 'paid', receipt_no: 'OR-1234', issued_at: '2026-11-17 09:00:00', settled_at: '2026-11-18 10:00:00', settled_by_name: 'Ana Cruz', version: 2 } }, null, '');
check('paid: who / when / receipt; no action buttons; no gate',
  paid.includes('Paid Nov 18, 2026 by Ana Cruz') && paid.includes('Receipt OR-1234') && !paid.includes('data-bl-action') && !paid.includes(gate), paid);
const waived = ui.blockHtml({ complaint: { id: 7, status: 'open' }, default_fee: '50.00', clear: true, blocker: null, can_issue: false,
  bill: { amount: '50.00', status: 'waived', waive_reason: 'Indigent <family>', issued_at: '2026-11-17 09:00:00', settled_at: '2026-11-18 10:00:00', version: 2 } }, null, '');
check('waived (official view): reason shown, escaped', waived.includes('Waived') && waived.includes('Reason: Indigent &lt;family&gt;'), waived);

// Complaint Types fee.
check('type payload: default_fee sent as trimmed text, "" -> "0"; omitted when the form has no fee field',
  types.typePayload({ type_name: 'X', respondent_mode: 'none', default_fee: ' 50.00 ' }, null).default_fee === '50.00'
    && types.typePayload({ type_name: 'X', respondent_mode: 'none', default_fee: '' }, null).default_fee === '0'
    && !('default_fee' in types.typePayload({ type_name: 'X', respondent_mode: 'none' }, null)));
check('type table fee label: 0 -> "No fee", 50.00 -> ₱50.00', types.feeLabel('0.00') === 'No fee' && types.feeLabel('50.00') === '₱50.00' && types.feeLabel(undefined) === 'No fee');

// Resident view (js/resident.js): the Fee section.
const residentSource = fs.readFileSync(path.join(root, 'js/resident.js'), 'utf8');
const rContext = vm.createContext({ console: { log() {}, warn() {}, error() {} }, document: { addEventListener() {}, getElementById: () => null, querySelectorAll: () => [], querySelector: () => null },
  window: { addEventListener() {} }, setTimeout, clearTimeout, setInterval, clearInterval, sessionStorage: { getItem: () => null, setItem() {} }, localStorage: { getItem: () => null, setItem() {} }, URLSearchParams, navigator: {}, location: { href: '' } });
vm.runInContext('var window = this.window;', rContext);
vm.runInContext(residentSource, rContext, { filename: 'resident.js' });
const rUnpaid = vm.runInContext(`renderComplaintFeeHtml(mapComplaintBill({ amount: '50.00', status: 'unpaid', billing_note: 'Filing <fee>', waive_reason: 'SECRET', receipt_no: 'OR-SECRET' }))`, rContext);
check('resident Fee: amount, escaped note, "Unpaid — pay at the barangay hall"; reason and receipt never shown',
  rUnpaid.includes('₱50.00') && rUnpaid.includes('Filing &lt;fee&gt;') && rUnpaid.includes('Unpaid — pay at the barangay hall') && !rUnpaid.includes('SECRET'), rUnpaid);
const rWaived = vm.runInContext(`renderComplaintFeeHtml(mapComplaintBill({ amount: '50.00', status: 'waived', waive_reason: 'SECRET' }))`, rContext);
check('resident Fee: "Waived", not the reason', rWaived.includes('Waived') && !rWaived.includes('SECRET'), rWaived);
check('resident Fee: "Paid"; nothing without a bill',
  vm.runInContext(`renderComplaintFeeHtml(mapComplaintBill({ amount: '5.00', status: 'paid' }))`, rContext).includes('>Paid<')
    && vm.runInContext('renderComplaintFeeHtml(mapComplaintBill(null))', rContext) === ''
    && vm.runInContext(`renderComplaintFeeHtml(mapComplaintBill({ amount: '5.00', status: 'bogus' }))`, rContext) === '');

console.log(`${passed}/${passed + failed} passed`);
process.exit(failed === 0 ? 0 : 1);

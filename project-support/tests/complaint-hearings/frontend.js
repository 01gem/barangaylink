'use strict';
/**
 * BarangayLink - Mediation calendar, browser-side logic (complaint revision Phase 6).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Loads js/official.js, js/complaint-types.js, js/complaint-walkin.js and
 * js/hearings.js in a VM with a stub DOM (no browser, no network), the same
 * way complaint-parties/frontend.js does, and checks the pure helpers:
 * month grid, request_key, time options, payloads, escaping. Also checks the
 * resident Mediation section in js/resident.js.
 *
 *   node project-support/tests/complaint-hearings/frontend.js
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
for (const file of ['official.js', 'complaint-types.js', 'complaint-walkin.js', 'hearings.js']) {
  sources[file] = fs.readFileSync(path.join(root, 'js', file), 'utf8');
  vm.runInContext(sources[file], context, { filename: file });
}
const ui = vm.runInContext('window.HearingsUI', context);
const api = vm.runInContext('window.Hearings', context);
const plain = value => JSON.parse(JSON.stringify(value));
const hearingsSource = sources['hearings.js'];

// Loading and isolation.
check('script exposes Hearings.load / openSession / openSchedule / renderComplaintBlock and the pure helpers',
  !!ui && !!api && ['load', 'openSession', 'openSchedule', 'renderComplaintBlock'].every(name => typeof api[name] === 'function'));
check('escape helper comes from official.js (no second helper defined)',
  !/function\s+escapeHtml\b/.test(hearingsSource) && /escapeHtml\(/.test(hearingsSource) && vm.runInContext('typeof escapeHtml', context) === 'function');
check('no inline event handlers and no external libraries',
  !/\son[a-z]+\s*=/.test(hearingsSource.replace(/\/\*[\s\S]*?\*\//g, '')) && !/https?:\/\//.test(hearingsSource) && !/\bimport\b|\brequire\(/.test(hearingsSource));
check('official.js has the top-level after-write sync for the open complaint detail',
  vm.runInContext('typeof syncOpenComplaintAfterHearing === "function"', context));
check('official.js switchTab loads the calendar and the detail key handler steps aside for hearing windows',
  /name === 'hearings' && window\.Hearings/.test(sources['official.js']) && sources['official.js'].includes(".walkin-modal.show, .hearing-modal.show")
    && sources['official.js'].includes('window.Hearings.renderComplaintBlock(c,'));
const initEntry = listeners.find(([type, fn]) => type === 'DOMContentLoaded' && fn.name === 'initHearingsSafely');
check('init is registered on DOMContentLoaded as a top-level guarded function', !!initEntry);
let initThrew = false;
try {
  const saved = context.document.addEventListener;
  context.document.addEventListener = () => { throw new Error('boom'); };
  initEntry[1]();
  context.document.addEventListener = saved;
} catch (error) { initThrew = true; }
check('a failure inside init is caught and does not propagate', !initThrew);

// Month grid: weeks start on Monday, days outside the month are flagged.
const nov = plain(ui.monthGrid(2026, 10));
check('November 2026: 6 weeks of 7 days, first cell Mon Oct 26, last cell Sun Dec 6',
  nov.length === 6 && nov.every(week => week.length === 7) && nov[0][0].date === '2026-10-26' && nov[5][6].date === '2026-12-06', JSON.stringify([nov[0][0], nov[5][6]]));
check('November 2026: Nov 1 is the Sunday of the first week; outside days flagged, inside not',
  nov[0][6].date === '2026-11-01' && nov[0][6].inMonth === true && nov[0][0].inMonth === false && nov[5][6].inMonth === false
    && nov.flat().filter(d => d.inMonth).length === 30);
const feb = plain(ui.monthGrid(2027, 1));
check('February 2027 starts on a Monday: exactly 4 weeks, no outside days',
  feb.length === 4 && feb[0][0].date === '2027-02-01' && feb.flat().every(d => d.inMonth) && feb[3][6].date === '2027-02-28');
const mar = plain(ui.monthGrid(2026, 2));
check('March 2026 (starts on a Sunday): first week begins Mon Feb 23',
  mar[0][0].date === '2026-02-23' && mar[0][6].date === '2026-03-01' && mar[0][6].inMonth === true && mar[0][0].inMonth === false);
const dec = plain(ui.monthGrid(2026, 11));
check('December 2026 into January 2027: the year rolls over in the last week',
  dec[dec.length - 1][6].date === '2027-01-03' && dec[dec.length - 1][6].inMonth === false);
const range = plain(ui.gridRange(ui.monthGrid(2026, 10)));
check('grid range covers the visible weeks and stays within the 62-day list limit',
  range.from === '2026-10-26' && range.to === '2026-12-06' && (Date.parse(range.to) - Date.parse(range.from)) / 864e5 <= 62);

// request_key.
const keys = [ui.newRequestKey(), ui.newRequestKey()];
check('request_key is 32 lower-case hex characters and differs per call',
  keys.every(k => /^[0-9a-f]{32}$/.test(k)) && keys[0] !== keys[1], JSON.stringify(keys));
check('request_key comes from crypto.getRandomValues (no Math.random)',
  /getRandomValues\(/.test(hearingsSource) && !/Math\.random/.test(hearingsSource));

// Time and duration options.
const times = plain(ui.timeOptions());
check('start times: 48 options from 07:00 to 18:45 in 15-minute steps',
  times.length === 48 && times[0] === '07:00' && times[times.length - 1] === '18:45' && times.includes('12:15') && !times.includes('19:00'));
const durations = plain(ui.durationOptions());
check('durations: 15 to 240 in steps of 15', durations.length === 16 && durations[0] === 15 && durations[15] === 240 && durations.every(n => n % 15 === 0));

// Time text is read as text (no browser time zone).
check('formatRange reads the server strings as text',
  ui.formatRange('2026-11-28 09:00:00', '2026-11-28 10:30:00') === 'Sat, Nov 28, 2026 · 9:00 AM – 10:30 AM'
    && ui.formatRange('2026-11-28 12:00:00', '2026-11-28 13:15:00') === 'Sat, Nov 28, 2026 · 12:00 PM – 1:15 PM', ui.formatRange('2026-11-28 09:00:00', '2026-11-28 10:30:00'));
check('defaultDate is the next day, skipping Sunday',
  ui.defaultDate('2026-11-16 17:00:00') === '2026-11-17' && ui.defaultDate('2026-11-21 08:00:00') === '2026-11-23');
check('canRecord: only scheduled sessions that have started (server now)',
  ui.canRecord({ status: 'scheduled', starts_at: '2026-11-16 09:00:00' }, '2026-11-16 09:00:00')
    && !ui.canRecord({ status: 'scheduled', starts_at: '2026-11-16 09:15:00' }, '2026-11-16 09:00:00')
    && !ui.canRecord({ status: 'completed', starts_at: '2026-11-01 09:00:00' }, '2026-11-16 09:00:00'));
check('chip class follows the status and flags sessions to record',
  ui.chipClass({ status: 'scheduled', needs_recording: true }) === 'hearing-chip is-scheduled needs-recording'
    && ui.chipClass({ status: 'cancelled' }) === 'hearing-chip is-cancelled' && ui.chipClass({ status: '"><x' }) === 'hearing-chip is-scheduled');

// Payloads.
const values = { date: '2026-11-28', start_time: '09:00', duration_minutes: '60', venue: '  Barangay Hall ', presiding_official_id: '3', reason: '  Moved  ' };
check('schedule body has exactly the server keys, numbers as numbers, venue trimmed',
  same(plain(ui.buildSchedulePayload('schedule', values, { requestKey: 'k'.repeat(32), complaintId: 12 })),
    { complaint_id: 12, date: '2026-11-28', start_time: '09:00', duration_minutes: 60, venue: 'Barangay Hall', presiding_official_id: 3, request_key: 'k'.repeat(32) }));
check('reschedule body adds hearing_id, version and the trimmed reason, no complaint_id',
  same(plain(ui.buildSchedulePayload('reschedule', values, { requestKey: 'k'.repeat(32), hearingId: 5, version: 2 })),
    { hearing_id: 5, version: 2, date: '2026-11-28', start_time: '09:00', duration_minutes: 60, venue: 'Barangay Hall', presiding_official_id: 3, request_key: 'k'.repeat(32), reason: 'Moved' }));
const sessionRow = { id: 5, version: 3, attendance: [{ party_id: 41, status: 'present' }, { party_id: 42, status: 'not_recorded' }] };
check('record body sends only changed attendance, empty texts as null, complete flag',
  same(plain(ui.buildRecordPayload(sessionRow, { attendance: { 41: 'present', 42: 'absent' }, outcome: '', public_summary: '  ', private_notes: ' note ' }, true)),
    { hearing_id: 5, version: 3, attendance: { 42: 'absent' }, outcome: null, public_summary: null, private_notes: 'note', complete: true }));
check('only the stale-version 409 counts as "changed by someone else"',
  ui.isStale({ status: 409, message: 'This item was changed by someone else. Reload and try again.' })
    && !ui.isStale({ status: 409, message: 'This time conflicts with another scheduled session.' }));

// Complaint detail block.
const block = ui.complaintBlockHtml({ complaint: { id: 7, case_no: null, can_schedule: false, schedule_blocker: 'Add at least one respondent before scheduling mediation.' }, sessions: [] });
check('detail block without sessions: "Case no. —", disabled button with the reason',
  block.includes('Case no. <strong>—</strong>') && /data-hr-action="schedule"[^>]*disabled/.test(block)
    && block.includes('Add at least one respondent before scheduling mediation.') && block.includes('No mediation sessions yet.'));
const block2 = ui.complaintBlockHtml({ complaint: { id: 7, case_no: '<b>BC</b>', can_schedule: true },
  sessions: [{ id: 9, session_no: 1, starts_at: '2026-11-28 09:00:00', ends_at: '2026-11-28 10:00:00', venue: '<img src=x>', status: 'scheduled' }] });
check('detail block escapes server text, lists the session with an Open button, enabled schedule button',
  !block2.includes('<img') && block2.includes('&lt;img') && block2.includes('&lt;b&gt;BC') && block2.includes('data-hearing-id="9"')
    && !/data-hr-action="schedule"[^>]*disabled/.test(block2));

// Resident view (js/resident.js): the Mediation section.
const residentSource = fs.readFileSync(path.join(root, 'js/resident.js'), 'utf8');
const rContext = vm.createContext({ console: { log() {}, warn() {}, error() {} }, document: { addEventListener() {}, getElementById: () => null, querySelectorAll: () => [], querySelector: () => null },
  window: { addEventListener() {} }, setTimeout, clearTimeout, setInterval, clearInterval, sessionStorage: { getItem: () => null, setItem() {} }, localStorage: { getItem: () => null, setItem() {} }, URLSearchParams, navigator: {}, location: { href: '' } });
vm.runInContext('var window = this.window;', rContext);
vm.runInContext(residentSource, rContext, { filename: 'resident.js' });
const residentHtml = vm.runInContext(`renderComplaintMediationHtml({ caseNo: 'BC-2026-0001', hearings: mapComplaintHearings([
  { session_no: 1, starts_at: '2026-11-21 09:00:00', ends_at: '2026-11-21 10:00:00', venue: 'Barangay Hall', status: 'rescheduled', outcome: null, public_summary: null },
  { session_no: 2, starts_at: '2026-11-28 09:00:00', ends_at: '2026-11-28 10:00:00', venue: '<i>Hall</i>', status: 'completed', outcome: 'settled', public_summary: 'Agreed <script>' , private_notes: 'SECRET' } ]) })`, rContext);
check('resident Mediation section: case number, sessions, outcome label, escaped summary, nothing private',
  residentHtml.includes('Case no. BC-2026-0001') && residentHtml.includes('Session 2') && residentHtml.includes('Sat, Nov 28, 2026, 9:00 AM–10:00 AM')
    && residentHtml.includes('Outcome: Settled') && residentHtml.includes('Agreed &lt;script&gt;') && residentHtml.includes('&lt;i&gt;Hall')
    && residentHtml.includes('Rescheduled') && !residentHtml.includes('SECRET'), residentHtml);
check('resident Mediation section is absent without a case number or sessions',
  vm.runInContext('renderComplaintMediationHtml({ caseNo: null, hearings: [] })', rContext) === '');

// ---------------------------------------------------------------- Phase 7: summons section
// (No inline handlers / no CDN for js/hearings.js is checked above for the whole file.)
const now = '2026-11-16 10:00:00';
check('summons: section only for a scheduled session that has not started',
  ui.summonsVisible({ status: 'scheduled', starts_at: '2026-11-28 09:00:00' }, now) === true
    && ui.summonsVisible({ status: 'scheduled', starts_at: '2026-11-16 09:00:00' }, now) === false
    && ui.summonsVisible({ status: 'completed', starts_at: '2026-11-28 09:00:00' }, now) === false
    && ui.summonsVisible({ status: 'cancelled', starts_at: '2026-11-28 09:00:00' }, now) === false
    && ui.summonsVisible(null, now) === false);
const issued = { id: 5, generated_at: '2026-11-16 14:03:22', view_url: '../api/hearings/summons_view.php?id=5&format=pdf', download_url: '../api/hearings/summons_view.php?id=5&format=docx' };
const notIssued = ui.summonsRowState({ party_id: 1, full_name: 'A', current: null, superseded_count: 0, can_generate: true, reason: null });
check('summons: not issued -> "Not issued", Generate only', notIssued.label === 'Not issued' && same(notIssued.buttons, ['generate']) && notIssued.reason === '', JSON.stringify(notIssued));
const issuedState = ui.summonsRowState({ party_id: 1, full_name: 'A', current: issued, superseded_count: 0, can_generate: true, reason: null });
check('summons: issued -> "Issued <date>", Regenerate, Open PDF, Download DOCX',
  issuedState.label === 'Issued Mon, Nov 16, 2026 · 2:03 PM' && same(issuedState.buttons, ['regenerate', 'open-pdf', 'download-docx']), JSON.stringify(issuedState));
const supersededState = ui.summonsRowState({ party_id: 1, full_name: 'A', current: issued, superseded_count: 2, can_generate: true, reason: null });
check('summons: superseded copies counted ("Superseded ×2")', supersededState.label === 'Issued Mon, Nov 16, 2026 · 2:03 PM · Superseded ×2', supersededState.label);
const blockedIssued = ui.summonsRowState({ party_id: 1, full_name: 'A', current: issued, superseded_count: 1, can_generate: false, reason: 'Summons can only be issued for an upcoming scheduled session.' });
check('summons: not allowed -> no Generate/Regenerate, copies still open, server reason kept',
  same(blockedIssued.buttons, ['open-pdf', 'download-docx']) && blockedIssued.reason === 'Summons can only be issued for an upcoming scheduled session.');
const lateState = ui.summonsRowState({ party_id: 9, full_name: 'Late', current: null, superseded_count: 0, can_generate: false, on_session: false, reason: 'This respondent was added after the session was scheduled. Reschedule to include them.' });
check('summons: added after scheduling -> no buttons, reason shown', same(lateState.buttons, []) && lateState.reason.startsWith('This respondent was added after'));
const section = ui.summonsSectionHtml({ blocker: null, respondents: [
  { party_id: 3, full_name: '<b>Pedro</b>', current: issued, superseded_count: 1, can_generate: true, reason: null },
  { party_id: 4, full_name: 'Maria', current: null, superseded_count: 0, can_generate: true, reason: null },
  { party_id: 9, full_name: 'Late', current: null, superseded_count: 0, can_generate: false, reason: 'Reschedule to include them.' }] });
check('summons: section HTML — escaped names, delegated buttons, confirm marker on Regenerate, links to the view endpoint',
  section.includes('&lt;b&gt;Pedro&lt;/b&gt;') && !section.includes('<b>Pedro')
    && section.includes('data-hr-action="summons-generate" data-party-id="3" data-replace="1">Regenerate')
    && section.includes('data-hr-action="summons-generate" data-party-id="4">Generate')
    && section.includes('href="../api/hearings/summons_view.php?id=5&amp;format=pdf" target="_blank" rel="noopener">Open PDF')
    && section.includes('href="../api/hearings/summons_view.php?id=5&amp;format=docx" download>Download DOCX')
    && section.includes('Reschedule to include them.') && (section.match(/summons-generate/g) || []).length === 2, section);
const blockedSection = ui.summonsSectionHtml({ blocker: 'Document signatory is not configured.', respondents: [
  { party_id: 4, full_name: 'Maria', current: null, superseded_count: 0, can_generate: false, reason: 'Document signatory is not configured.' }] });
check('summons: a server blocker is shown once and no Generate button is drawn',
  (blockedSection.match(/Document signatory is not configured\./g) || []).length === 1 && !blockedSection.includes('summons-generate'));
check('summons: regenerate confirm text', ui.SUMMONS_CONFIRM === 'This replaces the current copy; the old copy stays in the history.');
check('summons: complaint block count "Summons: x of y issued" on scheduled sessions only',
  ui.summonsCountText({ status: 'scheduled', summons: { issued: 1, total: 2 } }) === 'Summons: 1 of 2 issued'
    && ui.summonsCountText({ status: 'completed', summons: { issued: 1, total: 2 } }) === ''
    && ui.summonsCountText({ status: 'scheduled' }) === '');
const blockWithCount = ui.complaintBlockHtml({ complaint: { id: 7, case_no: 'BC-2026-0001', can_schedule: false },
  sessions: [{ id: 3, session_no: 1, starts_at: '2026-11-28 09:00:00', ends_at: '2026-11-28 10:00:00', venue: 'Hall', status: 'scheduled', summons: { issued: 2, total: 2 } }] });
check('summons: complaint Mediation block shows the count', blockWithCount.includes('Summons: 2 of 2 issued'), blockWithCount);

console.log(`${passed}/${passed + failed} passed`);
process.exit(failed === 0 ? 0 : 1);

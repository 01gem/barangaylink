/**
 * BarangayLink - Password reset UI checks (Phase P4). Node only, no browser.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Static checks on js/login.js + pages/login.php, then js/login.js runs in a
 * VM with a tiny fake DOM, fake fetch and fake timers.
 *
 *   node project-support/tests/contact-recovery/frontend.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert/strict');

const root = path.join(__dirname, '../../..');
// Both files use CRLF; normalize so the slicing below sees '\n' only.
const source = fs.readFileSync(path.join(root, 'js/login.js'), 'utf8').replace(/\r\n/g, '\n');
const page = fs.readFileSync(path.join(root, 'pages/login.php'), 'utf8').replace(/\r\n/g, '\n');
let passed = 0;
function check(name, value) { assert.ok(value, name); passed++; console.log('PASS ' + name); }

// ---------------------------------------------------------------- static
const start = source.indexOf('function initPasswordReset()');
const end = source.indexOf('\n}\n', start);
const resetSource = source.slice(start, end + 2);
check('initPasswordReset body was located (guards the checks below)', start > 0 && end > start && resetSource.length > 2000
  && resetSource.includes("loginApiRequest('reset_password.php'"));
check('initPasswordReset is defined once, at top level', (source.match(/^function initPasswordReset\(\)/gm) || []).length === 1
  && (source.match(/function initPasswordReset\(/g) || []).length === 1);
const ready = source.slice(source.indexOf("document.addEventListener('DOMContentLoaded'"));
check('initPasswordReset is called once, from the DOMContentLoaded chain',
  (source.match(/initPasswordReset\(\);/g) || []).length === 1 && ready.includes('initPasswordReset();'));
check("reset code sends no 'username' key", !/username/.test(resetSource));
check('reset code never uses innerHTML', !/innerHTML/.test(resetSource));
check('hint is written with textContent', /hint\.textContent\s*=/.test(resetSource));
check('no fetch credentials override (same-origin default sends the session cookie)', !/credentials\s*:/.test(source));
check('login.php has #resetIdentifier and #resetHint, no #resetUsername',
  page.includes('id="resetIdentifier"') && page.includes('id="resetHint"') && !page.includes('resetUsername'));
check('login.php reset field: label, maxlength 100, required, autocomplete username',
  page.includes('>Username or mobile number</label>') && /id="resetIdentifier"[^>]*maxlength="100"/.test(page)
  && /id="resetIdentifier"[^>]*required/.test(page) && /id="resetIdentifier"[^>]*autocomplete="username"/.test(page));
check('login.php has the static help line and no inline <script>/<style> block',
  page.includes("Didn't get a code? Contact the barangay office.") && !/<script>(?!<\/script>)/.test(page) && !/<style[\s>]/.test(page));

// ---------------------------------------------------------------- fake DOM
const elements = new Map();
function makeEl(id, extra = {}) {
  const listeners = {};
  const classes = new Set();
  const node = Object.assign({
    id, value: '', textContent: '', className: '', disabled: false, hidden: false, style: { display: '' }, dataset: {},
    attributes: {},
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    fire(type, event = {}) { return Promise.all((listeners[type] || []).map(fn => fn(Object.assign({ preventDefault() {} }, event)))); },
    setAttribute(name, value) { this.attributes[name] = String(value); },
    focus() {},
    classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c), toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) },
  }, extra);
  elements.set(id, node);
  return node;
}
const inputs = ['resetIdentifier', 'resetCode', 'resetNewPassword', 'resetConfirmPassword'];
inputs.forEach(id => makeEl(id));
const form = (id, fields) => makeEl(id, { reset() { fields.forEach(f => { elements.get(f).value = ''; }); } });
form('resetRequestForm', ['resetIdentifier']);
form('resetCodeForm', ['resetCode']);
form('resetPasswordForm', ['resetNewPassword', 'resetConfirmPassword']);
['resetOverlay', 'resetComplete', 'resetDoneBtn', 'resetMessage', 'resetAccountLabel', 'resetHint', 'resendResetCodeBtn',
  'resetClose', 'resetTitle', 'sendResetCodeBtn', 'verifyResetCodeBtn', 'resetPasswordBtn'].forEach(id => makeEl(id));
const forgotResident = makeEl('forgot-resident', { dataset: { accountType: 'resident' } });
const forgotOfficial = makeEl('forgot-official', { dataset: { accountType: 'official' } });
const tab = makeEl('tab-resident', { dataset: { tab: 'resident-login' } });

let domReady = null;
const intervals = new Map();
let nextTimer = 1;
const calls = [];
let respond = null;
const context = vm.createContext({
  console, JSON, Promise, String, Object,
  window: { location: { hash: '' } },
  document: {
    addEventListener(type, fn) { if (type === 'DOMContentLoaded') domReady = fn; },
    getElementById: id => elements.get(id) || null,
    querySelectorAll: selector => selector === '.forgot-link' ? [forgotResident, forgotOfficial]
      : selector === '.auth-tab' ? [tab] : [],
  },
  setInterval(fn) { const id = nextTimer++; intervals.set(id, fn); return id; },
  clearInterval(id) { intervals.delete(id); },
  fetch: async (url, options) => {
    calls.push({ url, options, body: JSON.parse(options.body) });
    const [status, data] = respond(url);
    return { ok: status >= 200 && status < 300, status, json: async () => data };
  },
});
vm.runInContext(source, context);
const $ = id => elements.get(id);
const visible = id => $(id).style.display !== 'none';
const tick = n => { for (let i = 0; i < n; i++) [...intervals.values()].forEach(fn => fn()); };

async function main() {
  check('DOMContentLoaded handler registered', typeof domReady === 'function');
  domReady();

  // Resident tab -> Forgot password.
  await forgotResident.fire('click');
  check('opening shows the request step and the account type', visible('resetRequestForm') && !visible('resetCodeForm')
    && $('resetAccountLabel').textContent === 'Resetting a resident account' && $('resetOverlay').classList.contains('show'));

  // 429 stays on step 1 and shows the server message.
  respond = () => [429, { success: false, message: 'Too many requests. Please try again later.' }];
  $('resetIdentifier').value = 'juan_delacruz';
  await $('resetRequestForm').fire('submit');
  check('429 -> message shown, still on the request step', visible('resetRequestForm') && !visible('resetCodeForm')
    && $('resetMessage').textContent === 'Too many requests. Please try again later.' && $('resetMessage').className.includes('error'));

  // 200 with a hint.
  respond = () => [200, { success: true, message: 'Generic message.', hint: '+63 9** *** *567' }];
  calls.length = 0;
  $('resetIdentifier').value = '  0917 123 4567 ';
  await $('resetRequestForm').fire('submit');
  check('request payload is exactly {account_type, identifier}',
    calls.length === 1 && calls[0].url.endsWith('request_otp.php')
    && JSON.stringify(Object.keys(calls[0].body)) === '["account_type","identifier"]'
    && calls[0].body.account_type === 'resident' && calls[0].body.identifier === '0917 123 4567');
  check('200 -> code step; hint line reads "Code sent to <hint>"', visible('resetCodeForm') && !visible('resetRequestForm')
    && $('resetHint').textContent === 'Code sent to +63 9** *** *567');
  check('resend is disabled for 60 s with a countdown', $('resendResetCodeBtn').disabled === true
    && $('resendResetCodeBtn').textContent === 'Resend code (60s)');
  tick(1);
  check('countdown ticks', $('resendResetCodeBtn').textContent === 'Resend code (59s)');
  calls.length = 0;
  await $('resendResetCodeBtn').fire('click');
  check('resend while counting down sends nothing', calls.length === 0);
  tick(59);
  check('after 60 s resend is enabled again', $('resendResetCodeBtn').disabled === false && $('resendResetCodeBtn').textContent === 'Resend code');

  // Resend with hint null -> the generic message is shown instead.
  respond = () => [200, { success: true, message: 'Generic message.', hint: null }];
  await $('resendResetCodeBtn').fire('click');
  check('resend payload is {account_type, identifier}; hint null -> message shown; countdown restarts',
    calls.length === 1 && JSON.stringify(Object.keys(calls[0].body)) === '["account_type","identifier"]'
    && $('resetHint').textContent === 'Generic message.' && $('resendResetCodeBtn').textContent === 'Resend code (60s)');

  // Verify + reset send only the code (and the new password).
  respond = () => [200, { success: true, message: 'ok' }];
  calls.length = 0;
  $('resetCode').value = '123456';
  await $('resetCodeForm').fire('submit');
  check('verify payload is exactly {code}', calls.length === 1 && JSON.stringify(calls[0].body) === '{"code":"123456"}'
    && visible('resetPasswordForm') && intervals.size === 0);
  $('resetNewPassword').value = 'New-pass-2026';
  $('resetConfirmPassword').value = 'New-pass-2026';
  await $('resetPasswordForm').fire('submit');
  check('reset payload is exactly {code, new_password}',
    calls.length === 2 && JSON.stringify(calls[1].body) === '{"code":"123456","new_password":"New-pass-2026"}'
    && $('resetComplete').hidden === false);

  // Close / reopen clears everything.
  await forgotOfficial.fire('click');
  respond = () => [200, { success: true, message: 'Generic message.', hint: '+63 9** *** *006' }];
  $('resetIdentifier').value = '+639401110006';
  await $('resetRequestForm').fire('submit');
  check('official tab: account type sent and shown', calls[calls.length - 1].body.account_type === 'official'
    && $('resetAccountLabel').textContent === 'Resetting an official account');
  await $('resetClose').fire('click');
  check('close clears hint, fields, message, timer and step', $('resetHint').textContent === '' && $('resetIdentifier').value === ''
    && $('resetMessage').textContent === '' && intervals.size === 0 && visible('resetRequestForm') && !visible('resetCodeForm')
    && !$('resetOverlay').classList.contains('show'));
  await forgotResident.fire('click');
  check('reopen starts clean on the request step', visible('resetRequestForm') && $('resetHint').textContent === ''
    && $('resendResetCodeBtn').disabled === false);

  // Switching the login tab abandons the reset.
  respond = () => [200, { success: true, message: 'Generic message.', hint: '+63 9** *** *001' }];
  $('resetIdentifier').value = 'rec_user';
  await $('resetRequestForm').fire('submit');
  await tab.fire('click');
  check('tab switch closes the modal and clears state', !$('resetOverlay').classList.contains('show') && intervals.size === 0
    && $('resetHint').textContent === '' && $('resetIdentifier').value === '');

  console.log(`TOTAL ${passed} passed, 0 failed`);
}
main().catch(error => { console.error('FAIL ' + error.message); console.log(`TOTAL ${passed} passed, 1 failed`); process.exit(1); });

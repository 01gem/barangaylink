'use strict';
/**
 * BarangayLink - Phase 5B frontend checks (registry-driven request forms).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Loads js/resident.js and js/official.js in a VM with a stub DOM (no browser,
 * no network) and checks the renderers against golden/seeded_forms.json, the
 * HTML the pre-5B hardcoded renderers produced for the five seeded types.
 *
 *   node project-support/tests/document-forms/frontend.js
 *
 * Field definitions come from the PHP constants (defs.php). run.php also passes
 * what type_fields.php really answered on its scratch database:
 *   FORMS_RESIDENT_JSON / FORMS_OFFICIAL_JSON = paths of the saved responses.
 */
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.join(__dirname, '../../..');
const php = process.env.PHP_BINARY || 'php';
const golden = JSON.parse(fs.readFileSync(path.join(__dirname, 'golden/seeded_forms.json'), 'utf8'));
const phpDefs = JSON.parse(execFileSync(php, [path.join(__dirname, 'defs.php')], { encoding: 'utf8' }));
const residentSource = fs.readFileSync(path.join(root, 'js/resident.js'), 'utf8');
const officialSource = fs.readFileSync(path.join(root, 'js/official.js'), 'utf8');
const registrySource = fs.readFileSync(path.join(root, 'api/documents/_registry.php'), 'utf8');
const SEEDED = ['Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Certificate of Good Moral Character', 'Business Permit Endorsement'];
const CREATED = 'Certificate of Cohabitation';

let passed = 0; let failed = 0;
function check(name, ok, detail = '') {
  if (ok) { passed++; console.log('PASS ' + name); }
  else { failed++; console.log('FAIL ' + name + (detail === '' ? '' : ': ' + detail)); }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

function makeContext(extraWindow = {}) {
  const elements = new Map();
  const el = id => {
    if (!elements.has(id)) elements.set(id, { id, textContent: '', innerHTML: '', value: '', disabled: false, hidden: false, src: 'about:blank', style: {}, dataset: {}, options: [],
      classList: { contains: () => false, add() {}, remove() {}, toggle() {} }, addEventListener() {}, appendChild() {}, querySelectorAll: () => [], querySelector: () => null });
    return elements.get(id);
  };
  const storage = new Map();
  const state = { inputs: [] };
  const context = vm.createContext({ console: { log() {}, warn() {}, error() {} }, setTimeout, clearTimeout, setInterval, clearInterval,
    document: { addEventListener() {}, getElementById: el, querySelectorAll: selector => (String(selector).includes('data-doc-field') ? state.inputs : []), querySelector: () => null,
      createElement: () => el('created'), body: el('body'), visibilityState: 'visible' },
    window: { confirm: () => true, prompt: () => null, addEventListener() {}, matchMedia: () => ({ matches: false, addEventListener() {} }), ...extraWindow },
    sessionStorage: { getItem: k => storage.get(k) || null, setItem: (k, v) => storage.set(k, v), removeItem: k => storage.delete(k) },
    localStorage: { getItem: () => null, setItem() {} }, fetch: async () => ({}), URL, URLSearchParams, navigator: {}, location: { href: '' } });
  return { context, el, state, run: code => vm.runInContext(code, context) };
}
// "Today" is 15 Jan 2026, 12:00 local, as when the goldens were captured.
const FIX_DATE = `const __RealDate = Date; Date = class extends __RealDate { constructor(...a) { if (a.length) super(...a); else super(2026, 0, 15, 12, 0, 0); } };`;

// ---------------------------------------------------------------- fixtures
const pick = (field, withSource) => {
  const out = { field_key: field.field_key, label: field.label };
  if (withSource) out.source = field.source;
  return Object.assign(out, { data_type: field.data_type, min_value: field.min_value, max_value: field.max_value, max_length: field.max_length, is_required: field.is_required });
};
const catalogueField = key => Object.assign({ field_key: key }, phpDefs.catalogue[key]);
const createdKeys = Object.keys(phpDefs.catalogue).filter(k => ['full_name', 'address', 'age', 'civil_status', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'].includes(k));
const allTypes = Object.assign({}, phpDefs.types, { [CREATED]: createdKeys.map(catalogueField) });
// What type_fields.php answers, built from the PHP constants.
const residentResponse = { success: true, registry: true, types: Object.keys(allTypes).map(name => ({ name,
  fields: allTypes[name].filter(f => f.source === 'resident_supplied').map(f => pick(f, false)) })) };
const officialResponse = { success: true, registry: true, types: Object.keys(allTypes).map(name => ({ name, status: 'active', has_active_version: true,
  fields: allTypes[name].filter(f => f.source !== 'system').map(f => pick(f, true)) })) };
const readJson = file => (file && fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : null);
const liveResident = readJson(process.env.FORMS_RESIDENT_JSON);
const liveOfficial = readJson(process.env.FORMS_OFFICIAL_JSON);

// The only attribute changes 5B makes to the seeded resident forms: the limits
// the server already enforces are now on the inputs too.
function expectedResidentHtml(type, html) {
  let out = html; const applied = [];
  const swap = (from, to) => { if (out.includes(from)) { out = out.replace(from, to); applied.push(to); } };
  if (type === 'Certificate of Residency') swap(' min="0" required />', ' min="0" max="120" required />');
  if (type === 'Business Permit Endorsement') {
    swap('value="" required />', 'value="" maxlength="100" required />');
    swap('value="" placeholder=', 'value="" maxlength="100" placeholder=');
  }
  return { html: out, applied: applied.length };
}

async function main() {
  // ============================================================== resident
  const residentRender = (r, type) => {
    r.el('reqDocType').value = type;
    r.el('reqExtraFields').innerHTML = 'UNSET'; r.el('reqExtraFields').style.display = 'UNSET';
    r.run('renderExtraRequestFields()');
    return { html: r.el('reqExtraFields').innerHTML, display: r.el('reqExtraFields').style.display };
  };
  const newResident = async (profile, response) => {
    const r = makeContext({ RESIDENT_PROFILE: profile });
    r.run(FIX_DATE);
    vm.runInContext(residentSource, r.context);
    if (response !== undefined) {
      r.context.__response = response;
      r.run('fetchJson = async () => { if (__response && __response.__throw) throw new Error(__response.__throw); return __response; };');
      await r.run('loadRequestTypeFields()');
    }
    return r;
  };
  const deltas = { 'Certificate of Residency': 1, 'Business Permit Endorsement': 2 };
  const residentSources = [['registry definitions', residentResponse], ['legacy fallback (definitions never loaded)', undefined], ['legacy fallback (registry: false)', { success: true, registry: false, types: [] }]];
  if (liveResident) residentSources.push(['type_fields.php response from the scratch database', liveResident]);
  for (const [label, response] of residentSources) {
    for (const [bucket, profile] of [['resident', { birthdate: '1990-05-20', civil_status: 'Married' }], ['residentEmptyProfile', {}]]) {
      const r = await newResident(profile, response);
      const wrong = [];
      for (const type of SEEDED) {
        const want = expectedResidentHtml(type, golden[bucket][type].html);
        const got = residentRender(r, type);
        if (got.html !== want.html || got.display !== golden[bucket][type].display || want.applied !== (deltas[type] || 0)) wrong.push(type);
      }
      check(`resident form, ${label}, ${bucket === 'resident' ? 'profile filled' : 'empty profile'}: five seeded types equal the pre-5B HTML (+ max/maxlength only)`, wrong.length === 0, wrong.join(', '));
    }
  }
  const r = await newResident({ birthdate: '1990-05-20', civil_status: 'Married' }, residentResponse);
  check('resident form: Barangay Clearance is byte-identical to the pre-5B HTML (no attribute change at all)',
    residentRender(r, 'Barangay Clearance').html === golden.resident['Barangay Clearance'].html);
  check('resident form: a created type with age + civil_status shows the same two inputs as Barangay Clearance',
    residentRender(r, CREATED).html === golden.resident['Barangay Clearance'].html && residentRender(r, CREATED).display === 'grid');
  check('resident form: a type without resident fields shows nothing (container hidden)',
    same(residentRender(r, 'Certificate of Indigency'), { html: '', display: 'none' }));
  check('resident form: a type the registry does not list shows nothing', same(residentRender(r, 'No Such Type'), { html: '', display: 'none' }));
  check('resident form: no selection shows nothing', same(residentRender(r, ''), { html: '', display: 'none' }));
  check('resident form: prototype keys are not types', same(residentRender(r, 'constructor'), { html: '', display: 'none' }) && same(residentRender(r, '__proto__'), { html: '', display: 'none' }));

  // Generic path: a field with no presentation hint renders from the definition alone.
  r.context.__defs = [
    { field_key: 'months_employed', label: 'Months employed', data_type: 'int', min_value: 1, max_value: 600, max_length: null, is_required: 0 },
    { field_key: 'employer_name', label: 'Name of the employer', data_type: 'text', min_value: null, max_value: null, max_length: 80, is_required: 1 },
    { field_key: 'remarks', label: 'Remarks <b>', data_type: 'text', min_value: null, max_value: null, max_length: null, is_required: 0 },
    { field_key: 'open_count', label: 'Open count', data_type: 'int', min_value: null, max_value: null, max_length: 5, is_required: 0 },
  ];
  const generic = r.run('__defs.map(d => requestFieldInputHtml(requestFieldView(d), {}))');
  check('generic field: int -> number input with min and max, not required', generic[0] === '<div class="field"><label>Months Employed</label><input type="number" class="form-input" id="req-extra-months_employed" data-extra-field="months_employed"\n        value="" min="1" max="600" /></div>', generic[0]);
  check('generic field: text -> text input with maxlength; is_required -> required; label title-cased with small words', generic[1] === '<div class="field"><label>Name of the Employer</label><input type="text" class="form-input" id="req-extra-employer_name" data-extra-field="employer_name"\n        value="" maxlength="80" required /></div>', generic[1]);
  check('generic field: no limits -> no min/max/maxlength; label escaped', generic[2] === '<div class="field"><label>Remarks &lt;b&gt;</label><input type="text" class="form-input" id="req-extra-remarks" data-extra-field="remarks"\n        value="" /></div>', generic[2]);
  check('generic field: int ignores max_length, text ignores min/max', !generic[3].includes('maxlength') && !generic[3].includes('min=') && !generic[3].includes('max='), generic[3]);
  check('display label: sentence case -> title case, small words kept low except first',
    same(r.run(`['Age','Civil status','Years of residency','Business name','of mice and men',''].map(requestFieldDisplayLabel)`), ['Age', 'Civil Status', 'Years of Residency', 'Business Name', 'Of Mice and Men', '']));
  check('hints: keyed by field_key only (no type names), exactly the five resident keys',
    same(r.run('Object.keys(REQUEST_FIELD_HINTS).sort()'), ['age', 'business_name', 'business_type', 'civil_status', 'years_of_residency'])
    && same(Object.keys(phpDefs.catalogue).filter(k => phpDefs.catalogue[k].source === 'resident_supplied').sort(), ['age', 'business_name', 'business_type', 'civil_status', 'years_of_residency']));
  check('required list for the submit check: Clearance -> Age, Civil Status; Indigency -> none',
    same(r.run(`requestFieldViews('Barangay Clearance').filter(d => d.required).map(d => d.label)`), ['Age', 'Civil Status'])
    && same(r.run(`requestFieldViews('Certificate of Indigency').filter(d => d.required).map(d => d.label)`), []));
  check('detail label: registry key -> form label; unknown key -> Title Case of the key',
    same(r.run(`['civil_status','years_of_residency','old_field'].map(requestFieldLabel)`), ['Civil Status', 'Years of Residency', 'Old Field']));
  check('resident source no longer has the per-type hardcoded list', !residentSource.includes('DOC_REQUEST_EXTRA_FIELDS'));
  const failing = await newResident({}, residentResponse);
  failing.context.__response = { __throw: 'offline' };
  await failing.run('loadRequestTypeFields()');
  check('definitions: a failed reload keeps the last good definitions', residentRender(failing, CREATED).display === 'grid');

  // ================================================================ staff
  const newOfficial = async (response) => {
    const s = makeContext();
    s.run(FIX_DATE);
    vm.runInContext(officialSource, s.context);
    s.context.toasts = []; s.context.calls = [];
    s.run(`showToastAdmin = (title, message) => toasts.push({ title, message });
      RESIDENTS = [{ dbId: 7, name: 'Juan Dela Cruz', addr: 'Purok 2, Brgy. Sampaguita' }];`);
    if (response !== undefined) {
      s.context.__response = response;
      s.run('fetchJson = async () => { if (__response && __response.__throw) throw new Error(__response.__throw); return __response; };');
      try { await s.run('loadDocTypeFields()'); } catch { /* load failure is a scenario */ }
    }
    return s;
  };
  const STORED = { 'Barangay Clearance': { age: 35, civil_status: 'Married' }, 'Certificate of Residency': { years_of_residency: 12 },
    'Business Permit Endorsement': { business_name: 'A & B "Store"', business_type: 'Sari-sari <store>' } };
  const staffRender = (s, type, stored) => {
    s.context.__req = { type, residentId: 7, resident: 'Juan Dela Cruz', purpose: 'Local "Employment" & more', requestFields: stored };
    s.el('docGenFields').innerHTML = 'UNSET';
    s.run('renderDocumentGenerationFields(__req)');
    return s.el('docGenFields').innerHTML;
  };
  const staffSources = [['registry definitions', officialResponse], ['legacy fallback (definitions never loaded)', undefined], ['legacy fallback (registry: false)', { success: true, registry: false, types: [] }]];
  if (liveOfficial) staffSources.push(['type_fields.php response from the scratch database', liveOfficial]);
  for (const [label, response] of staffSources) {
    const s = await newOfficial(response);
    const wrong = [];
    for (const type of SEEDED) {
      if (staffRender(s, type, STORED[type] || {}) !== golden.staff[type]) wrong.push(type + ' (stored fields)');
      if (staffRender(s, type, {}) !== golden.staffNoStored[type]) wrong.push(type + ' (no stored fields)');
    }
    check(`staff modal, ${label}: five seeded types byte-identical to the pre-5B HTML`, wrong.length === 0, wrong.join(', '));
  }
  const s = await newOfficial(officialResponse);
  const createdHtml = staffRender(s, CREATED, { age: 41, civil_status: 'Single' });
  const createdKeysShown = [...createdHtml.matchAll(/data-doc-field="([a-z_]+)"/g)].map(m => m[1]);
  check('staff modal: created type shows its non-system fields in registry order', same(createdKeysShown, ['full_name', 'address', 'age', 'civil_status', 'purpose', 'date_issued']), createdKeysShown.join(','));
  check('staff modal: created type prefills resident record, stored fields, purpose and today',
    createdHtml.includes('data-doc-field="full_name" value="Juan Dela Cruz"') && createdHtml.includes('data-doc-field="age" value="41"')
    && createdHtml.includes('data-doc-field="civil_status" value="Single"') && createdHtml.includes('data-doc-field="date_issued" value="2026-01-15"')
    && createdHtml.includes('<input type="date" class="form-input"\n          data-doc-field="date_issued"'));
  check('staff modal: system fields are never rendered', !/reference_no|official_name|official_title/.test(createdHtml + SEEDED.map(t => staffRender(s, t, {})).join('')));
  check('staff modal: labels are the registry labels, title-cased', createdHtml.includes('<label>Full Name</label>') && createdHtml.includes('<label>Civil Status</label>') && createdHtml.includes('<label>Date Issued</label>'));
  check('default date is the local calendar date, not UTC', s.run(`localIsoDate(new __RealDate(2026, 0, 15, 0, 30, 0))`) === '2026-01-15' && s.run(`localIsoDate(new __RealDate(2026, 11, 31, 23, 59, 0))`) === '2026-12-31'
    && !officialSource.includes('toISOString().slice(0, 10);\n  const purposeDefault'));

  // ---- message map: JS mirror == PHP map (read from both files)
  const phpBlock = (registrySource.match(/const DOCUMENT_FIELD_MESSAGES = \[([\s\S]*?)\];/) || [])[1] || '';
  const jsBlock = (officialSource.match(/const DOC_FIELD_MESSAGES = \{([\s\S]*?)\};/) || [])[1] || '';
  const phpMap = Object.fromEntries([...phpBlock.matchAll(/'([a-z_]+)'\s*=>\s*'([^']*)'/g)].map(m => [m[1], m[2]]));
  const jsMap = Object.fromEntries([...jsBlock.matchAll(/([a-z_]+):\s*'([^']*)'/g)].map(m => [m[1], m[2]]));
  check('messages: PHP map parsed from _registry.php has the six rules', same(Object.keys(phpMap), ['required', 'int', 'between', 'min', 'max', 'max_length']), JSON.stringify(phpMap));
  check('messages: DOC_FIELD_MESSAGES in official.js equals DOCUMENT_FIELD_MESSAGES in _registry.php (source text)', Object.keys(jsMap).length === 6 && same(jsMap, phpMap), JSON.stringify(jsMap));
  check('messages: the loaded JS and PHP constants are equal too', same(s.run('DOC_FIELD_MESSAGES'), phpDefs.messages));

  // ---- rule parity: the JS mirror answers exactly what the PHP implementation answers
  const F = (extra) => Object.assign({ field_key: 'x', label: 'Test field', source: 'staff_entered', data_type: 'text', min_value: null, max_value: null, max_length: null, is_required: 0 }, extra);
  const purpose = pick(catalogueField('purpose'), true); const dateIssued = pick(catalogueField('date_issued'), true);
  const cases = [
    [purpose, ''], [purpose, '   '], [purpose, 'Employment'], [dateIssued, ''], [dateIssued, '2026-11-15'],
    [F({}), ''], [F({ is_required: 1 }), ''], [F({ is_required: 1 }), ' ok '],
    [F({ data_type: 'int' }), 'abc'], [F({ data_type: 'int' }), '1.5'], [F({ data_type: 'int' }), '1e3'], [F({ data_type: 'int' }), '12'], [F({ data_type: 'int' }), '-3'],
    [F({ data_type: 'int' }), '1234567890'], [F({ data_type: 'int' }), ' 7 '], [F({ data_type: 'int', is_required: 1 }), ''],
    [F({ data_type: 'int', min_value: 1, max_value: 10 }), '0'], [F({ data_type: 'int', min_value: 1, max_value: 10 }), '11'], [F({ data_type: 'int', min_value: 1, max_value: 10 }), '1'], [F({ data_type: 'int', min_value: 1, max_value: 10 }), '10'],
    [F({ data_type: 'int', min_value: 5 }), '4'], [F({ data_type: 'int', min_value: 5 }), '5'], [F({ data_type: 'int', max_value: 9 }), '10'], [F({ data_type: 'int', max_value: 9 }), '9'],
    [F({ data_type: 'int', min_value: 0, max_value: 120 }), '-1'], [F({ data_type: 'int', max_length: 1 }), '55'],
    [F({ max_length: 5 }), 'abcde'], [F({ max_length: 5 }), 'abcdef'], [F({ max_length: 5 }), 'ñañañ'], [F({ max_length: 5 }), 'ñañaña'], [F({ max_length: 5 }), '  abcde  '],
    [F({ max_length: 3, min_value: 9, max_value: 9 }), 'abc'], [F({ label: 'Años <b>', is_required: 1 }), ''],
  ];
  const phpAnswers = JSON.parse(execFileSync(php, [path.join(__dirname, 'defs.php'), 'rules'], { input: JSON.stringify(cases), encoding: 'utf8' }));
  s.context.__cases = cases;
  const jsAnswers = s.run('__cases.map(([field, value]) => docFieldRuleError(field, value))');
  const diff = cases.map((c, i) => (jsAnswers[i] === phpAnswers[i] ? null : `${JSON.stringify(c)} js=${jsAnswers[i]} php=${phpAnswers[i]}`)).filter(Boolean);
  check(`rules: JS mirror equals the PHP implementation on ${cases.length} cases`, diff.length === 0 && phpAnswers.length === cases.length, diff.join(' | '));
  const expectMessages = { 0: 'Purpose is required.', 3: 'Date issued is required.', 8: 'Test field must be a whole number.', 16: 'Test field must be between 1 and 10.',
    20: 'Test field must be at least 5.', 22: 'Test field must be at most 9.', 27: 'Test field must be 5 characters or fewer.', 32: 'Años <b> is required.' };
  check('rules: exact texts (required, whole number, between, at least, at most, characters)', Object.entries(expectMessages).every(([i, text]) => phpAnswers[i] === text && jsAnswers[i] === text),
    JSON.stringify(Object.keys(expectMessages).map(i => phpAnswers[i])));
  check('rules: valid values pass', [2, 4, 5, 7, 11, 12, 18, 19, 21, 23, 26, 28, 30, 31].every(i => phpAnswers[i] === null && jsAnswers[i] === null));

  // ---- validateDocGenFields: staff_entered only
  s.context.__clearance = officialResponse.types.find(t => t.name === 'Barangay Clearance').fields;
  check('validate: blank purpose -> "Purpose is required."', same(s.run(`validateDocGenFields(__clearance, { full_name: 'A', purpose: '', date_issued: '2026-11-15' })`), { key: 'purpose', message: 'Purpose is required.' }));
  check('validate: blank date -> "Date issued is required."', same(s.run(`validateDocGenFields(__clearance, { purpose: 'Work', date_issued: '' })`), { key: 'date_issued', message: 'Date issued is required.' }));
  check('validate: a missing key counts as blank', same(s.run(`validateDocGenFields(__clearance, { purpose: 'Work' })`), { key: 'date_issued', message: 'Date issued is required.' }));
  check('validate: blank resident_record / resident_supplied fields do not block (server fallbacks stay)',
    s.run(`validateDocGenFields(__clearance, { full_name: '', age: '', civil_status: '', address: '', purpose: 'Work', date_issued: '2026-11-15' })`) === null);
  check('validate: no definitions -> nothing to check', s.run(`validateDocGenFields(null, {})`) === null && s.run(`validateDocGenFields([], {})`) === null);

  // ---- generateAndPreviewDocument blocks before any request
  const g = await newOfficial(officialResponse);
  const inputFor = (key, value) => ({ value, getAttribute: () => key });
  g.run(`requestAction = async (endpoint, ref, payload) => { calls.push({ endpoint, ref, payload }); return { pdf_path: 'x.pdf' }; }; requestRefreshAfterSuccess = async () => {};`);
  g.state.inputs = [inputFor('full_name', 'Juan'), inputFor('address', 'Purok 2'), inputFor('purpose', '   '), inputFor('date_issued', '2026-11-15')];
  g.run(`ACTIVE_DOC_GEN_REQUEST = { id: 1, ref: 'A', rowVersion: 3, type: 'Certificate of Indigency' };`);
  await g.run('generateAndPreviewDocument()');
  check('generate: blank required staff field is blocked client-side (no request sent)', g.context.calls.length === 0);
  check('generate: the block shows the server text', g.context.toasts.at(-1)?.title === 'Generation Failed' && g.context.toasts.at(-1)?.message === 'Purpose is required.'
    && g.el('docGenPreviewEmpty').textContent === 'Purpose is required.');
  check('generate: the button stays usable after a client-side block', g.el('generateDocBtn').disabled === false);
  g.state.inputs[2].value = 'Scholarship';
  await g.run('generateAndPreviewDocument()');
  check('generate: with the field filled the request is sent with every modal value', g.context.calls.length === 1
    && same(g.context.calls[0].payload.fields, { full_name: 'Juan', address: 'Purok 2', purpose: 'Scholarship', date_issued: '2026-11-15' }));

  // ---- opening the modal: which types are refused, and with what
  const openCase = async (response, type, reload) => {
    const o = await newOfficial(response);
    o.context.__reload = reload; o.context.reloads = 0;
    o.run('fetchJson = async () => { reloads++; if (__reload && __reload.__throw) throw new Error(__reload.__throw); return __reload; };');
    const ok = await o.run(`ensureDocGenFieldDefs(${JSON.stringify(type)})`);
    return { ok, toast: o.context.toasts.at(-1) || null, reloads: o.context.reloads };
  };
  const without = name => Object.assign({}, officialResponse, { types: officialResponse.types.filter(t => t.name !== name) });
  const unpublished = name => Object.assign({}, officialResponse, { types: officialResponse.types.map(t => (t.name === name ? Object.assign({}, t, { has_active_version: false }) : t)) });
  let o = await openCase(officialResponse, 'Barangay Clearance', { __throw: 'must not reload' });
  check('open: registered type with an active version opens, no toast, no reload', o.ok === true && o.toast === null && o.reloads === 0);
  o = await openCase(officialResponse, CREATED, { __throw: 'must not reload' });
  check('open: a created type with an active version opens (never "Template Missing")', o.ok === true && o.toast === null);
  o = await openCase(without(CREATED), CREATED, officialResponse);
  check('open: a type created after the page loaded is picked up by one reload', o.ok === true && o.toast === null && o.reloads === 1);
  o = await openCase(unpublished(CREATED), CREATED, officialResponse);
  check('open: a type activated after the page loaded is picked up by one reload', o.ok === true && o.toast === null && o.reloads === 1);
  o = await openCase(unpublished(CREATED), CREATED, unpublished(CREATED));
  check('open: registered type without an active version -> "Document template is unavailable."', o.ok === false && same(o.toast, { title: 'Template Unavailable', message: 'Document template is unavailable.' }));
  o = await openCase(officialResponse, 'No Such Type', officialResponse);
  check('open: type the registry does not know -> "Document type is unavailable." (not "Template Missing")', o.ok === false && same(o.toast, { title: 'Document Type Unavailable', message: 'Document type is unavailable.' }));
  const legacy = { success: true, registry: false, types: [] };
  o = await openCase(legacy, 'Barangay Clearance', { __throw: 'must not reload' });
  check('open: no registry (legacy) + built-in type opens from the legacy list', o.ok === true && o.toast === null && o.reloads === 0);
  o = await openCase(legacy, CREATED, legacy);
  check('open: "Template Missing" only in the no-registry legacy case', o.ok === false && o.toast?.title === 'Template Missing' && o.toast?.message === `No template configuration found for "${CREATED}".`);
  o = await openCase({ __throw: 'offline' }, 'Barangay Clearance', { __throw: 'offline' });
  check('open: definitions unreachable + built-in type still opens from the legacy list', o.ok === true && o.toast === null && o.reloads === 1);
  o = await openCase({ __throw: 'offline' }, CREATED, { __throw: 'offline' });
  check('open: definitions unreachable + other type -> "Fields Unavailable"', o.ok === false && o.toast?.title === 'Fields Unavailable');
  const templateMissingUses = (officialSource.match(/'Template Missing'/g) || []).length;
  check('source: one "Template Missing" path left, and no per-type list outside the legacy constant', templateMissingUses === 1 && !/[^_]DOC_TEMPLATE_FIELDS/.test(officialSource.replace(/LEGACY_DOC_TEMPLATE_FIELDS/g, '')));

  // ---- request detail labels
  check('detail: stored key shown with its registry label', s.run(`requestFieldDetailLabel('Barangay Clearance', 'civil_status')`) === 'Civil status'
    && s.run(`requestFieldDetailLabel('Certificate of Residency', 'years_of_residency')`) === 'Years of residency');
  check('detail: unknown key shown as the key with spaces for underscores', s.run(`requestFieldDetailLabel('Barangay Clearance', 'old_field_name')`) === 'old field name'
    && s.run(`requestFieldDetailLabel('No Such Type', 'age')`) === 'age');
  const d = await newOfficial(undefined);
  check('detail: without definitions every key falls back to the spaced key', d.run(`requestFieldDetailLabel('Barangay Clearance', 'civil_status')`) === 'civil status');
  check('init functions are still top-level in both controllers', /^function initDocumentGenerationModal\(/m.test(officialSource) && /^async function openDocumentGenerationModal\(/m.test(officialSource)
    && /^function initRequestPricing\(/m.test(residentSource) && /^async function loadRequestTypeFields\(/m.test(residentSource));

  console.log(`FRONTEND ${passed} passed, ${failed} failed`);
  process.exitCode = failed === 0 ? 0 : 1;
}
main().catch(error => { console.error(error); process.exitCode = 1; });

/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

/* ─────────────────────────────────────────
   BARANGAYLINK — RESIDENT.JS
───────────────────────────────────────── */

const API_BASE = '../api';
const SIDEBAR_STATE_KEY = 'barangalink.sidebarCollapsed.resident';
const ACTIVE_SECTION_KEY = 'resident_active_section';
let DOC_PRICES = {};
function pesoR(n) { return '₱' + Number(n || 0).toFixed(2); }

// ── RETRY STATE ───────────────────────────
// Keyed by the form element. One entry per active retry session, scoped to the
// lifetime of the form. WeakMap so a detached form (e.g. a modal-based composer
// in a later phase) doesn't leak its state. Generic — reusable by any future
// resource that has a "parent created, children failed" split.
const RETRY_STATES = new WeakMap();

function setRetryState(formEl, state) {
  RETRY_STATES.set(formEl, state);
}

function getRetryState(formEl) {
  return RETRY_STATES.get(formEl) || null;
}

function clearRetryState(formEl) {
  RETRY_STATES.delete(formEl);
}

async function loadDocumentPrices() {
  try {
    const data = await fetchJson(`${API_BASE}/document_prices/list.php`);
    const rows = data.prices || [];
    DOC_PRICES = {};
    (Array.isArray(rows) ? rows : []).forEach(p => {
      if (p && p.document_type) DOC_PRICES[p.document_type] = Number(p.unit_price || 0);
    });
  } catch { DOC_PRICES = {}; }
}

// Phase 5A: the dropdown lists only types that are not archived and have an
// active template version. On failure the built-in options stay; the server
// still refuses anything that is not requestable.
let REQUESTABLE_TYPES = null;
async function loadRequestableTypes() {
  try {
    const data = await fetchJson(`${API_BASE}/documents/requestable_types.php`);
    const rows = (Array.isArray(data.types) ? data.types : []).filter(t => t && typeof t.name === 'string' && t.name !== '');
    const select = document.getElementById('reqDocType');
    if (!select) return;
    const current = select.value;
    [...select.options].forEach(option => { if (option.value !== '') option.remove(); });
    rows.forEach(t => {
      const option = document.createElement('option');
      option.value = t.name;
      option.textContent = t.name;
      select.appendChild(option);
    });
    select.value = rows.some(t => t.name === current) ? current : '';
    REQUESTABLE_TYPES = rows;
  } catch (err) {
    console.warn('Requestable document types could not be loaded; keeping the built-in list.', err);
  }
}

function renderRequestTypeInfo() {
  const info = document.getElementById('reqTypeInfo');
  const typeEl = document.getElementById('reqDocType');
  if (!info || !typeEl) return;
  const t = (REQUESTABLE_TYPES || []).find(row => row.name === typeEl.value);
  const parts = [];
  if (t && Number.isInteger(t.processing_days) && t.processing_days > 0) {
    parts.push(`Processing: ${t.processing_days} day${t.processing_days === 1 ? '' : 's'}.`);
  }
  if (t && typeof t.requirements === 'string' && t.requirements.trim() !== '') {
    parts.push(`Requirements: ${t.requirements.trim()}`);
  }
  info.textContent = parts.join(' ');
  info.hidden = parts.length === 0;
}

function hasPostedPrice(type) {
  return Object.prototype.hasOwnProperty.call(DOC_PRICES, type);
}

function updateRequestTotal() {
  const typeEl   = document.getElementById('reqDocType');
  const copiesEl = document.getElementById('reqCopies');
  const submitEl = document.getElementById('reqSubmitBtn');
  if (!typeEl || !copiesEl) return;
  const copies = Math.max(1, Math.min(5, parseInt(copiesEl.value, 10) || 1));
  document.getElementById('reqCopiesOut').textContent = String(copies);
  if (!hasPostedPrice(typeEl.value)) {
    document.getElementById('reqUnitPrice').textContent   = '—';
    document.getElementById('reqTotalAmount').textContent = '—';
    if (submitEl) submitEl.disabled = true;
    return;
  }
  const unit = Number(DOC_PRICES[typeEl.value]);
  document.getElementById('reqUnitPrice').textContent   = pesoR(unit);
  document.getElementById('reqTotalAmount').textContent = pesoR(unit * copies);
  if (submitEl) submitEl.disabled = false;
}

// Phase 5B: which extra inputs a type shows comes from the registry
// (api/documents/type_fields.php -> the type's resident_supplied rows:
// label, data_type, min/max, max_length, is_required).
//
// REQUEST_FIELD_HINTS are presentation hints keyed by field_key; the registry
// stays the source of which fields exist. A hint only adds what the registry
// cannot express: a fixed option list, a prefill from the resident's own
// profile, a placeholder, and "required in this form".
const REQUEST_FIELD_HINTS = {
  age:                { required: true, prefill: 'age' },
  civil_status:       { required: true, prefill: 'civil_status', options: ['Single', 'Married', 'Widowed', 'Separated'] },
  years_of_residency: { required: true },
  business_name:      { required: true },
  business_type:      { required: true, placeholder: 'e.g. Sari-sari store, Carinderia, Repair shop' },
};

// Legacy fallback, used only while the registry definitions are unavailable
// (type_fields.php failed, or it reports registry: false on an old database).
// Same shape and limits as LEGACY_RESIDENT_FIELDS on the server.
const LEGACY_REQUEST_FIELDS = {
  'Barangay Clearance': [
    { field_key: 'age', label: 'Age', data_type: 'int', min_value: 0, max_value: 120, max_length: null, is_required: 0 },
    { field_key: 'civil_status', label: 'Civil status', data_type: 'text', min_value: null, max_value: null, max_length: 100, is_required: 0 },
  ],
  'Certificate of Residency': [
    { field_key: 'years_of_residency', label: 'Years of residency', data_type: 'int', min_value: 0, max_value: 120, max_length: null, is_required: 0 },
  ],
  'Business Permit Endorsement': [
    { field_key: 'business_name', label: 'Business name', data_type: 'text', min_value: null, max_value: null, max_length: 100, is_required: 0 },
    { field_key: 'business_type', label: 'Business type', data_type: 'text', min_value: null, max_value: null, max_length: 100, is_required: 0 },
  ],
};

// type name -> resident_supplied definitions. null until type_fields.php answers.
let REQUEST_TYPE_FIELDS = null;
async function loadRequestTypeFields() {
  try {
    const data = await fetchJson(`${API_BASE}/documents/type_fields.php`);
    if (data.registry !== true || !Array.isArray(data.types)) { REQUEST_TYPE_FIELDS = null; return; }
    const map = Object.create(null);
    data.types.forEach(type => {
      if (!type || typeof type.name !== 'string') return;
      map[type.name] = (Array.isArray(type.fields) ? type.fields : []).filter(f => f && /^[a-z_]{1,50}$/.test(String(f.field_key)));
    });
    REQUEST_TYPE_FIELDS = map;
  } catch (err) {
    console.warn('Request field definitions could not be loaded; using the built-in list.', err);
  }
}

function requestFieldDefs(type) {
  const source = REQUEST_TYPE_FIELDS || LEGACY_REQUEST_FIELDS;
  return Object.prototype.hasOwnProperty.call(source, type) ? source[type] : [];
}

// Registry labels are sentence case ("Years of residency"); the form shows
// them title-cased ("Years of Residency").
const REQUEST_LABEL_SMALL_WORDS = ['a', 'an', 'and', 'for', 'in', 'of', 'on', 'or', 'the', 'to'];
function requestFieldDisplayLabel(label) {
  return String(label ?? '')
    .split(' ')
    .filter(Boolean)
    .map((word, index) => (index > 0 && REQUEST_LABEL_SMALL_WORDS.includes(word.toLowerCase()))
      ? word.toLowerCase()
      : word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

// Registry definition + presentation hint -> what the form renders.
function requestFieldView(def) {
  const hint = Object.prototype.hasOwnProperty.call(REQUEST_FIELD_HINTS, def.field_key) ? REQUEST_FIELD_HINTS[def.field_key] : {};
  const isInt = def.data_type === 'int';
  const num = value => (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) ? undefined : Number(value);
  return {
    name: String(def.field_key),
    label: requestFieldDisplayLabel(def.label || def.field_key),
    type: Array.isArray(hint.options) ? 'select' : (isInt ? 'number' : 'text'),
    options: Array.isArray(hint.options) ? hint.options : [],
    min: isInt ? num(def.min_value) : undefined,
    max: isInt ? num(def.max_value) : undefined,
    maxLength: isInt ? undefined : num(def.max_length),
    required: Number(def.is_required) === 1 || hint.required === true,
    prefill: hint.prefill || '',
    placeholder: hint.placeholder || '',
  };
}

function requestFieldViews(type) {
  return requestFieldDefs(type).map(requestFieldView);
}

// Resolve a stored request_fields_json key to the same label the
// resident saw on the submit form. Falls back to Title Case for any
// key no loaded definition carries (e.g. a field of an archived type).
function requestFieldLabel(key) {
  for (const source of [REQUEST_TYPE_FIELDS, LEGACY_REQUEST_FIELDS]) {
    if (!source) continue;
    for (const type of Object.keys(source)) {
      const def = source[type].find(d => d.field_key === key);
      if (def) return requestFieldDisplayLabel(def.label || def.field_key);
    }
  }
  return String(key)
    .split('_')
    .filter(Boolean)
    .map(part => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

function computeAgeFromBirthdate(birthdate) {
  if (!birthdate) return '';
  const b = new Date(`${birthdate}T00:00:00`);
  if (Number.isNaN(b.getTime())) return '';
  const now = new Date();
  let age = now.getFullYear() - b.getFullYear();
  const m = now.getMonth() - b.getMonth();
  if (m < 0 || (m === 0 && now.getDate() < b.getDate())) age--;
  return age >= 0 ? String(age) : '';
}

// One extra input. Pure: the view (requestFieldView) and the resident's profile in, HTML out.
function requestFieldInputHtml(def, profile) {
  const id = `req-extra-${def.name}`;
  let value = '';
  if (def.prefill === 'age') value = computeAgeFromBirthdate(profile.birthdate);
  if (def.prefill === 'civil_status') value = profile.civil_status || '';
  const req = def.required ? ' required' : '';
  let inner;
  if (def.type === 'select') {
    inner = `<select class="form-input" id="${id}" data-extra-field="${def.name}"${req}>
        <option value="">— Select —</option>
        ${def.options.map(o => `<option value="${escapeHtml(o)}"${value === o ? ' selected' : ''}>${escapeHtml(o)}</option>`).join('')}
      </select>`;
  } else {
    const min = def.min !== undefined ? ` min="${def.min}"` : '';
    const max = def.max !== undefined ? ` max="${def.max}"` : '';
    const len = def.maxLength !== undefined ? ` maxlength="${def.maxLength}"` : '';
    const ph  = def.placeholder ? ` placeholder="${escapeHtml(def.placeholder)}"` : '';
    inner = `<input type="${def.type}" class="form-input" id="${id}" data-extra-field="${def.name}"
        value="${escapeHtml(value)}"${min}${max}${len}${ph}${req} />`;
  }
  return `<div class="field"><label>${escapeHtml(def.label)}</label>${inner}</div>`;
}

function renderExtraRequestFields() {
  const typeEl = document.getElementById('reqDocType');
  const wrap   = document.getElementById('reqExtraFields');
  if (!typeEl || !wrap) return;
  const defs = requestFieldViews(typeEl.value);
  // A type without resident fields shows nothing here.
  if (!defs.length) { wrap.innerHTML = ''; wrap.style.display = 'none'; return; }
  wrap.style.display = 'grid';
  const profile = window.RESIDENT_PROFILE || {};
  wrap.innerHTML = defs.map(def => requestFieldInputHtml(def, profile)).join('');
}

function collectExtraRequestFields() {
  const out = {};
  document.querySelectorAll('#reqExtraFields [data-extra-field]').forEach(el => {
    const key = el.getAttribute('data-extra-field');
    const val = (el.value || '').trim();
    if (key && val !== '') out[key] = val;
  });
  return out;
}

function initRequestPricing() {
  document.getElementById('reqDocType')?.addEventListener('change', () => {
    renderExtraRequestFields();
    renderRequestTypeInfo();
    updateRequestTotal();
  });
  document.getElementById('reqCopies')?.addEventListener('input', updateRequestTotal);
  renderExtraRequestFields();
  updateRequestTotal();
}

let REQUESTS = [];
let COMPLAINTS = [];
const complaintResolutionHold = new Map();
let complaintDetailSeq = 0;
let complaintOpenGeneration = 0;
let NOTIFICATIONS = [];
let ANNOUNCEMENTS = [];
let SERVICES = [];
let announcementsLoaded = false;
let servicesLoaded = false;

// Get all notifications
function getAllNotifications() {
  return NOTIFICATIONS;
}

const SECTION_PLAQUES = {
  announcements: { icon: 'fa-bullhorn', label: 'Community Announcements' },
  services: { icon: 'fa-store', label: 'Local Services Directory' },
  dashboard: { icon: 'fa-house', label: 'Resident Dashboard' },
  requests: { icon: 'fa-file-lines', label: 'My Document Requests' },
  complaints: { icon: 'fa-triangle-exclamation', label: 'My Complaints & Concerns' },
  notifications: { icon: 'fa-bell', label: 'Notifications' },
  profile: { icon: 'fa-user', label: 'My Profile' }
};

// ── TAB SWITCHING ─────────────────────────
function switchTab(name) {
  document.querySelectorAll('.snav-item').forEach(b => {
    b.classList.toggle('active', b.dataset.tab === name);
  });
  document.querySelectorAll('.tab-panel').forEach(p => {
    p.classList.toggle('active', p.id === `tab-${name}`);
  });
  const plaque = SECTION_PLAQUES[name];
  const plaqueIcon = document.getElementById('sectionPlaqueIcon');
  const plaqueText = document.getElementById('sectionPlaque');
  if (plaque && plaqueIcon && plaqueText) {
    plaqueText.parentElement.className = `door-plaque door-plaque-${name}`;
    plaqueIcon.className = `fa-solid ${plaque.icon}`;
    plaqueText.textContent = plaque.label;
  }
  if (name === 'announcements' && !announcementsLoaded) {
    loadAnnouncements().catch(err => showToast('Announcements Load Failed', err.message));
  }
  if (name === 'services' && !servicesLoaded) {
    loadServices().catch(err => showToast('Services Load Failed', err.message));
  }
  try {
    sessionStorage.setItem(ACTIVE_SECTION_KEY, name);
  } catch (err) {
    /* ignore storage failures */
  }
}

function isValidSectionName(name) {
  if (!name) return false;
  return !!document.querySelector(`.snav-item[data-tab="${name}"]`) && !!document.getElementById(`tab-${name}`);
}

function restoreActiveSection() {
  let saved = '';
  try {
    saved = sessionStorage.getItem(ACTIVE_SECTION_KEY) || '';
  } catch (err) {
    saved = '';
  }
  if (isValidSectionName(saved)) {
    switchTab(saved);
  } else if (saved) {
    try {
      sessionStorage.removeItem(ACTIVE_SECTION_KEY);
    } catch (err) {
      /* ignore storage failures */
    }
  }
}

function initNav() {
  document.querySelectorAll('.snav-item[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });
}

function applySidebarState(collapsed) {
  document.body.classList.toggle('sidebar-collapsed', collapsed);
  const toggle = document.getElementById('sidebarToggle');
  const icon = toggle?.querySelector('i');
  if (toggle) {
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Show sidebar' : 'Hide sidebar');
  }
  if (icon) {
    icon.className = collapsed ? 'fa-solid fa-angles-right' : 'fa-solid fa-angles-left';
  }
}

function initSidebarToggle() {
  const toggle = document.getElementById('sidebarToggle');
  if (!toggle) return;
  let collapsed = false;
  try {
    collapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === '1';
  } catch (err) {
    collapsed = false;
  }
  // Phones: default to collapsed so the fixed 260px sidebar doesn't eat
  // the viewport. Don't persist this override — localStorage is for the
  // user's desktop preference.
  const phoneQuery = window.matchMedia('(max-width: 640px)');
  if (phoneQuery.matches) {
    collapsed = true;
  }
  applySidebarState(collapsed);
  phoneQuery.addEventListener('change', () => {
    if (phoneQuery.matches) {
      applySidebarState(true);
      return;
    }
    let stored = false;
    try {
      stored = localStorage.getItem(SIDEBAR_STATE_KEY) === '1';
    } catch (err) {
      stored = false;
    }
    applySidebarState(stored);
  });
  toggle.addEventListener('click', () => {
    const next = !document.body.classList.contains('sidebar-collapsed');
    applySidebarState(next);
    try {
      localStorage.setItem(SIDEBAR_STATE_KEY, next ? '1' : '0');
    } catch (err) {
      /* ignore storage failures */
    }
  });
}

function initLogoutConfirmation() {
  document.querySelectorAll('.logout-item, .logout-btn').forEach(link => {
    link.addEventListener('click', (event) => {
      const ok = window.confirm('Do you want to log out?');
      if (!ok) {
        event.preventDefault();
      } else {
        try {
          sessionStorage.removeItem(ACTIVE_SECTION_KEY);
        } catch (err) {
          /* ignore storage failures */
        }
      }
    });
  });
}

// ── DASHBOARD STATS ───────────────────────
function renderDashStats() {
  const el = document.getElementById('dashStats');
  if (!el) return;
  const totalRequests = REQUESTS.length;
  const pendingPickup = REQUESTS.filter(r => r.status === 'ready').length;
  const activeComplaints = COMPLAINTS.filter(c => c.status !== 'resolved').length;
  const unreadNotifications = getAllNotifications().filter(n => n.unread).length;
  const stats = [
    { val: String(totalRequests), lbl: 'Total Requests', ico: 'fa-file-lines', bg: '#DBEAFE', color: '#1976D2' },
    { val: String(pendingPickup), lbl: 'Pending Pickup', ico: 'fa-bell', bg: '#FEF9C3', color: '#D97706' },
    { val: String(activeComplaints), lbl: 'Active Complaints', ico: 'fa-triangle-exclamation', bg: '#FFF7ED', color: '#EA580C' },
    { val: String(unreadNotifications), lbl: 'Unread Notifications', ico: 'fa-envelope', bg: '#DCFCE7', color: '#059669' },
  ];
  el.innerHTML = stats.map(s => `
    <div class="stat-card">
      <div class="sc-ico" style="background:${s.bg}; color:${s.color}">
        <i class="fa-solid ${s.ico}"></i>
      </div>
      <div class="sc-data">
        <div class="sc-val">${s.val}</div>
        <div class="sc-lbl">${s.lbl}</div>
      </div>
    </div>
  `).join('');
}

// ── RECENT ITEMS (DASHBOARD) ──────────────
function renderRecentRequests() {
  const el = document.getElementById('recentRequestsList');
  if (!el) return;
  el.innerHTML = REQUESTS.slice(0,3).map(r => `
    <div class="mini-row">
      <div class="mr-icon" style="background:#DBEAFE; color:#1976D2"><i class="fa-solid fa-file-lines"></i></div>
      <div class="mr-text">
        <div class="mr-title">${r.type}</div>
        <div class="mr-sub">${r.ref} · ${r.date}</div>
      </div>
      <span class="status-badge status-${r.status}">${r.status}</span>
    </div>
  `).join('');
}

function renderRecentComplaints() {
  const el = document.getElementById('recentComplaintsList');
  if (!el) return;
  el.innerHTML = COMPLAINTS.slice(0,2).map(c => `
    <div class="mini-row">
      <div class="mr-icon" style="background:#FFF7ED; color:#EA580C"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div class="mr-text">
        <div class="mr-title">${escapeHtml(c.cat)}</div>
        <div class="mr-sub">${escapeHtml(c.ref)} · ${escapeHtml(c.date)}</div>
      </div>
      <span class="status-badge status-${complaintStatusToken(c.status)}">${escapeHtml(c.status)}</span>
    </div>
  `).join('');
}

// ── REQUESTS TABLE ────────────────────────
function renderRequestsTable(data) {
  const tbody = document.getElementById('reqTableBody');
  if (!tbody) return;
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:var(--text-3);padding:32px;">No requests found.</td></tr>`;
    return;
  }
  tbody.innerHTML = data.map(r => `
    <tr>
      <td><code style="font-size:12px;color:var(--blue-mid)">${r.ref}</code></td>
      <td style="font-weight:600;color:var(--text)">${r.type}</td>
      <td>${r.purpose}</td>
      <td>${r.date}</td>
      <td>
        <div style="font-weight:600">${pesoR(r.totalAmount)}</div>
        <span class="status-badge status-${r.paymentStatus || 'unpaid'}">${r.paymentStatus || 'unpaid'}</span>
      </td>
      <td><span class="status-badge status-${r.status}">${r.status}</span></td>
      <td><button onclick="viewRequest(${r.id})" style="font-size:12px;padding:5px 12px;background:var(--surface);border:1.5px solid var(--border);border-radius:6px;cursor:pointer;font-weight:600;color:var(--text-2);">View</button></td>
    </tr>
  `).join('');
}

function complaintStatusToken(status) {
  const token = String(status || '').toLowerCase();
  return ['open', 'investigating', 'resolved'].includes(token) ? token : 'unknown';
}

function complaintDomId(value) {
  const n = Number(value);
  return Number.isInteger(n) && n > 0 ? String(n) : '0';
}

function complaintProgressWidth(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return 0;
  return Math.min(100, Math.max(0, n));
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function viewRequest(id) {
  const req = REQUESTS.find(r => Number(r.id) === Number(id));
  if (!req || !residentComplaintCanClose()) return;
  releaseResidentComplaintDialog();
  const copies = Number(req.copies || 1);
  const copiesLabel = copies === 1 ? '1 copy' : `${copies} copies`;
  const extraRows = Object.entries(req.requestFields || {})
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => [requestFieldLabel(k), String(v)]);
  const fields = [
    ['Reference Number', req.ref],
    ['Document Type', req.type],
    ['Purpose', req.purpose],
    ...extraRows,
    ['Quantity', copiesLabel],
    ['Total Amount', pesoR(req.totalAmount)],
    ['Payment Status', req.paymentStatus || 'unpaid'],
    ['Status', req.status],
    ['Date Requested', req.date],
  ];
  const box = document.getElementById('requestModalBox');
  if (!box) return;
  box.classList.remove('complaint-detail-box');
  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--border)">
      <h3 style="font-size:1.2rem;font-weight:600;color:var(--text)">Request Details</h3>
      <button type="button" onclick="closeRequestModal()" style="background:none;border:none;font-size:18px;color:var(--text-3);cursor:pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <dl style="display:grid;gap:8px;margin:0 0 20px">
      ${fields.map(([label, value]) => `
        <div style="display:flex;justify-content:space-between;gap:12px;padding:8px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:7px">
          <dt style="color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:.04em">${escapeHtml(label)}</dt>
          <dd style="margin:0;color:var(--text);font-size:13px;font-weight:600;text-align:right;overflow-wrap:anywhere">${escapeHtml(value)}</dd>
        </div>`).join('')}
    </dl>
    <button type="button" onclick="closeRequestModal()" style="padding:9px 24px;background:var(--blue-mid);color:white;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer">Close</button>
  `;
  document.getElementById('requestModal').classList.add('show');
}

function closeRequestModal(discard = false) {
  if (!residentComplaintCanClose(discard === true)) return;
  const opener = residentComplaintDialog?.opener;
  const complaintId = residentComplaintDialog?.id;
  if (discard === true && complaintId && complaintResolutionHold.has(complaintId)) {
    // Keep the resolution recovery lock, but honor the explicit draft discard.
    complaintResolutionHold.set(complaintId, { ...complaintResolutionHold.get(complaintId), reopenReason: '', commentDraft: '' });
  }
  releaseResidentComplaintDialog();
  const box = document.getElementById('requestModalBox');
  document.getElementById('requestModal')?.classList.remove('show');
  box?.classList.remove('complaint-detail-box');
  complaintOpenGeneration += 1;
  if (opener?.isConnected) opener.focus();
  else if (complaintId) document.querySelector(`[data-resident-complaint="${complaintId}"]`)?.focus();
}

function initRequestsTable() {
  renderRequestsTable(REQUESTS);
  document.getElementById('reqSearch')?.addEventListener('input', filterRequests);
  document.getElementById('reqFilter')?.addEventListener('change', filterRequests);
}

function filterRequests() {
  const q = document.getElementById('reqSearch').value.toLowerCase();
  const status = document.getElementById('reqFilter').value;
  const filtered = REQUESTS.filter(r => {
    const matchQ = r.type.toLowerCase().includes(q) || r.ref.toLowerCase().includes(q) || r.purpose.toLowerCase().includes(q);
    const matchS = status === 'all' || r.status === status;
    return matchQ && matchS;
  });
  renderRequestsTable(filtered);
}

// ── REQUEST FORM TOGGLE ───────────────────
function initReqFormToggle() {
  const openBtn  = document.getElementById('newReqBtn');
  const closeBtn = document.getElementById('closeReqForm');
  const cancelBtn= document.getElementById('cancelReqForm');
  const form     = document.getElementById('newReqForm');
  const formEl   = document.getElementById('reqFormEl');

  const requestClose = () => {
    setFormModalOpen(form, false);
    openBtn?.focus();
  };

  openBtn?.addEventListener('click', () => {
    setFormModalOpen(form, true);
    formEl?.querySelector('select, input, textarea')?.focus();
  });
  closeBtn?.addEventListener('click', requestClose);
  cancelBtn?.addEventListener('click', requestClose);
  formEl?.addEventListener('submit', e => {
    e.preventDefault();
    submitRequest(formEl, form);
  });
}

// ── COMPLAINTS LIST ───────────────────────
// Keep in sync with the identical severityChip() in js/official.js.
function severityChip(severity) {
  const map = {
    low:      { bg: '#DCFCE7', color: '#15803D', label: 'Low' },
    medium:   { bg: '#FEF9C3', color: '#A16207', label: 'Medium' },
    high:     { bg: '#FFEDD5', color: '#C2410C', label: 'High' },
    critical: { bg: '#FEE2E2', color: '#B91C1C', label: 'Critical' }
  };
  const s = map[String(severity).toLowerCase()] || map.medium;
  return `<span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;background:${s.bg};color:${s.color}">${s.label}</span>`;
}

async function loadComplaintTimeline(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/timeline.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.updates || [];
}

function renderTimelineHtml(updates) {
  if (!updates.length) {
    return '<p class="complaint-meta">No timeline entries yet.</p>';
  }
  return '<ol style="list-style:none;padding:0;margin:0">' + updates.map(u => {
    const when = u.created_at ? escapeHtml(String(u.created_at)) : '';
    const who = escapeHtml(u.actor_name || (u.actor_type === 'system' ? 'System' : u.actor_type));
    const from = u.from_status ? `${escapeHtml(u.from_status)} → ` : '';
    const to = escapeHtml(u.to_status || '');
    const note = u.note ? `<div class="complaint-timeline-note">${escapeHtml(u.note)}</div>` : '';
    return `<li class="complaint-timeline-item">
      <div class="complaint-timeline-when">${when}</div>
      <div class="complaint-timeline-who">${who} — ${from}${to}</div>
      ${note}
    </li>`;
  }).join('') + '</ol>';
}

async function loadComplaintAttachments(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/list_attachments.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.attachments || [];
}

function renderAttachmentsHtml(attachments) {
  if (!attachments.length) {
    return '<p class="complaint-meta">No photos attached.</p>';
  }
  return '<div class="complaint-photos" aria-label="Complaint photos">' + attachments.map((a, index) => {
    const href = escapeHtml(a.url);
    const label = `Photo ${index + 1}`;
    return `<a class="complaint-photo-link" href="${href}" target="_blank" rel="noopener" title="Open ${label}">
      <img src="${href}" alt="${label} evidence thumbnail" loading="lazy"><span>${label} <span aria-hidden="true">↗</span></span>
    </a>`;
  }).join('') + '</div>';
}

function complaintCommentsSectionHtml() {
  return `<section data-complaint-comments class="complaint-section">
    <h4>Comments <span data-comment-count></span></h4>
    <div data-comment-list aria-live="polite" class="complaint-scroll-well">Loading comments…</div>
    <form data-comment-form class="complaint-controls">
      <label class="complaint-field">Add a comment (up to 2,000 characters)
        <textarea name="body" rows="3" required></textarea>
      </label>
      <button type="submit" disabled class="complaint-btn">Post comment</button>
      <p data-comment-status role="status" class="complaint-meta"></p>
    </form>
  </section>`;
}

async function loadComplaintComments(complaintId) {
  const data = await fetchJson(`${API_BASE}/complaints/list_comments.php?complaint_id=${encodeURIComponent(complaintId)}`);
  return data.comments || [];
}

function renderComplaintCommentsHtml(comments) {
  if (!comments.length) return '<p class="complaint-meta">No comments yet.</p>';
  return comments.map(comment => {
    const name = escapeHtml(comment.actor_name || (comment.actor_type === 'official' ? 'Official' : 'Resident'));
    const role = comment.actor_type === 'official' ? 'Official' : 'Resident';
    const when = escapeHtml(String(comment.created_at || ''));
    const body = escapeHtml(String(comment.body || ''));
    return `<article class="complaint-comment">
      <div class="complaint-comment-name">${name} · ${role}</div>
      <div class="complaint-comment-when">${when}</div>
      <div class="complaint-comment-body">${body}</div>
    </article>`;
  }).join('');
}

async function initComplaintComments(root, complaintId) {
  if (!root) return;
  const list = root.querySelector('[data-comment-list]');
  const form = root.querySelector('[data-comment-form]');
  const textarea = form.elements.namedItem('body');
  const button = form.querySelector('button[type="submit"]');
  const status = root.querySelector('[data-comment-status]');
  let busy = true;
  let pollTimer = null;
  let pollInflight = false;
  const refresh = async () => {
    const comments = await loadComplaintComments(complaintId);
    if (root.isConnected) {
      list.innerHTML = renderComplaintCommentsHtml(comments);
      updateResidentCommentCount(root, comments.length);
    }
  };
  const stopPolling = () => {
    if (pollTimer !== null) clearInterval(pollTimer);
    pollTimer = null;
    document.removeEventListener('visibilitychange', pollOnVisibilityChange);
    delete root._commentPollingCleanup;
  };
  const poll = async () => {
    if (!root.isConnected) {
      stopPolling();
      return;
    }
    if (document.visibilityState !== 'visible' || busy || pollInflight) return;
    pollInflight = true;
    try {
      await refresh();
    } catch (error) {
      if (root.isConnected && !busy) status.textContent = error.message || 'Live comment updates are temporarily unavailable.';
    } finally {
      pollInflight = false;
    }
  };
  const pollOnVisibilityChange = () => {
    if (document.visibilityState === 'visible') poll();
  };
  root._commentPollingCleanup = stopPolling;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !root.isConnected) return;
    const body = textarea.value.trim();
    if (!body || Array.from(body).length > 2000) {
      status.textContent = 'Enter a comment of 1–2,000 characters.';
      return;
    }
    busy = true;
    root.dataset.commentBusy = '1';
    syncResidentDiscard(root.closest('[role="dialog"]'));
    button.disabled = true;
    textarea.disabled = true;
    status.textContent = 'Posting…';
    try {
      await fetchJson(`${API_BASE}/complaints/add_comment.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ complaint_id: complaintId, body })
      });
    } catch (error) {
      if (root.isConnected) {
        status.textContent = error.message || 'Could not post comment.';
        busy = false;
        button.disabled = false;
        textarea.disabled = false;
        root.dataset.commentBusy = '0';
        syncResidentDiscard(root.closest('[role="dialog"]'));
        if (document.activeElement === document.body) button.focus();
      }
      return;
    }
    if (!root.isConnected) return;
    textarea.value = '';
    const held = complaintResolutionHold.get(Number(complaintId));
    if (held) complaintResolutionHold.set(Number(complaintId), { ...held, commentDraft: '' });
    if (residentComplaintDialog?.box === root.closest('[role="dialog"]') && !residentComplaintDirty()) {
      residentComplaintDialog.box.querySelector('[data-resident-unsaved]').hidden = true;
    }
    status.textContent = 'Comment posted.';
    try {
      await refresh();
    } catch {
      if (root.isConnected) status.textContent = 'Comment saved. Reopen this complaint to refresh the discussion.';
    } finally {
      if (root.isConnected) {
        busy = false;
        button.disabled = false;
        textarea.disabled = false;
        root.dataset.commentBusy = '0';
        syncResidentDiscard(root.closest('[role="dialog"]'));
        if (document.activeElement === document.body) button.focus();
      }
    }
  });
  try {
    await refresh();
  } catch {
    if (root.isConnected) list.textContent = 'Comments unavailable. Reopen this complaint to retry.';
  } finally {
    if (root.isConnected) {
      busy = false;
      button.disabled = false;
      pollTimer = setInterval(poll, 5000);
      document.addEventListener('visibilitychange', pollOnVisibilityChange);
    }
  }
}

function updateResidentCommentCount(root, count) {
  const countNode = root.querySelector('[data-comment-count]');
  if (countNode) countNode.textContent = `(${count})`;
  const tabCount = root.closest('[role="dialog"]')?.querySelector('[data-comment-tab-count]');
  if (tabCount) tabCount.textContent = `(${count})`;
}

// Keep in sync with the identical complaintResidentResponseText() in js/official.js.
function complaintResidentResponseText(c) {
  if (c.status === 'resolved') {
    return c.residentConfirmation === 'confirmed'
      ? 'Resident confirmed the resolution'
      : 'Awaiting resident confirmation';
  }
  return c.residentConfirmation === 'reopened' ? 'Reopened by resident' : '';
}

// Keep in sync with the identical complaintResidentResponseTimesHtml() in js/official.js.
function complaintResidentResponseTimesHtml(c) {
  const parts = [];
  if (c.residentConfirmation === 'confirmed' && c.confirmedAt) {
    parts.push(`Confirmed ${escapeHtml(String(c.confirmedAt))}`);
  }
  if (c.residentConfirmation === 'reopened' && c.reopenedAt) {
    parts.push(`Reopened ${escapeHtml(String(c.reopenedAt))}`);
  }
  if (!parts.length) return '';
  return `<p data-response-times class="complaint-meta">${parts.join(' · ')}</p>`;
}

// Keep in sync with the identical complaintIdOrNull() in js/official.js.
function complaintIdOrNull(value) {
  if (value === null || value === undefined || value === '') return null;
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed <= 0) return null;
  return parsed;
}

// Keep in sync with the identical complaintAssigneeLabel() in js/official.js.
function complaintAssigneeLabel(c) {
  if (c.assignedOfficialId === null || c.assignedOfficialId === undefined) return 'Unassigned';
  const name = String(c.assignedOfficialName || '').trim();
  return name !== '' ? name : 'Assigned official';
}

// Keep in sync with the identical complaintTriageStateText() in js/official.js.
function complaintTriageStateText(c) {
  if (c.status === 'resolved') {
    return c.slaDueAt
      ? 'Resolved. The response target is kept for history.'
      : 'Resolved. No response target was recorded.';
  }
  if (c.isOverdue) return 'Overdue';
  if (c.slaDueAt) return 'Within the response target';
  return 'No response target is set';
}

// Keep in sync with the identical complaintTriageSummaryHtml() in js/official.js.
function complaintTriageSummaryHtml(c) {
  const assignee = escapeHtml(complaintAssigneeLabel(c));
  const state = escapeHtml(complaintTriageStateText(c));
  const target = c.slaDueAt
    ? `<p data-triage-target class="complaint-meta">Response target ${escapeHtml(String(c.slaDueAt))}</p>`
    : '';
  let flagged = '';
  if (c.escalatedAt && c.status === 'resolved') {
    flagged = `<p data-triage-flagged class="complaint-meta">Previously flagged ${escapeHtml(String(c.escalatedAt))}</p>`;
  } else if (c.escalatedAt) {
    flagged = `<p data-triage-flagged class="complaint-meta">Flagged ${escapeHtml(String(c.escalatedAt))}</p>`;
  }
  return `<p data-triage-assignee class="complaint-strong">${assignee}</p>${target}<p data-triage-state class="complaint-meta">${state}</p>${flagged}`;
}

function complaintTriageSectionHtml(c) {
  return `<section data-complaint-triage data-complaint-id="${Number(c.id)}" class="complaint-section complaint-section-block">
    <h4>Assignment</h4>
    <div data-triage-summary>${complaintTriageSummaryHtml(c)}</div>
  </section>`;
}

function paintComplaintTriageSummary(root, complaint) {
  if (!root || !complaint) return;
  const summary = root.querySelector('[data-triage-summary]');
  if (summary) summary.innerHTML = complaintTriageSummaryHtml(complaint);
}

function complaintResolutionActionsHtml(c) {
  if (!c || c.status !== 'resolved') return '';
  const confirm = c.residentConfirmation === 'confirmed'
    ? ''
    : '<button type="button" data-confirm-resolution class="complaint-btn">Confirm resolution</button>';
  return `<div data-response-actions class="complaint-control-row">
      ${confirm}
      <button type="button" data-reopen-toggle aria-expanded="false" class="complaint-btn complaint-btn-ghost">Still broken? Reopen complaint</button>
      <div data-reopen-panel hidden class="complaint-reopen">
        <label class="complaint-field">What is still broken? This reason is posted as a comment.
          <textarea data-reopen-reason rows="3"></textarea>
        </label>
        <button type="button" data-reopen-submit class="complaint-btn">Reopen complaint</button>
      </div>
    </div>`;
}

function complaintResidentResponseSectionHtml(c) {
  const text = complaintResidentResponseText(c);
  if (!text) return '';
  return `<section data-resident-response data-complaint-id="${Number(c.id)}" class="complaint-section complaint-section-block">
    <h4>Resident response</h4>
    <p data-response-summary class="complaint-strong">${escapeHtml(text)}</p>
    ${complaintResidentResponseTimesHtml(c)}
    ${complaintResolutionActionsHtml(c)}
    <p data-response-status role="status" class="complaint-meta"></p>
  </section>`;
}

function initResidentResolutionActions(section, complaint, box, options = {}) {
  if (!section) return;
  const confirmBtn = section.querySelector('[data-confirm-resolution]');
  const toggleBtn = section.querySelector('[data-reopen-toggle]');
  const statusEl = section.querySelector('[data-response-status]');
  if (!confirmBtn && !toggleBtn) return;

  const complaintId = Number(complaint.id);
  let expectedResolutionId = Number(complaint.resolutionUpdateId || 0);
  const modal = document.getElementById('requestModal');
  let busy = false;
  let recovering = false;
  section.dataset.resolutionToken = String(expectedResolutionId);

  const liveControls = () => ({
    confirmBtn: section.querySelector('[data-confirm-resolution]'),
    toggleBtn: section.querySelector('[data-reopen-toggle]'),
    reasonEl: section.querySelector('[data-reopen-reason]'),
    submitBtn: section.querySelector('[data-reopen-submit]')
  });

  const setBusy = (on) => {
    busy = on;
    const controls = liveControls();
    [controls.confirmBtn, controls.toggleBtn, controls.submitBtn, controls.reasonEl].forEach(control => {
      if (control && control.isConnected) control.disabled = on;
    });
  };

  const stillThisComplaint = () => section.isConnected
    && modal
    && modal.classList.contains('show')
    && Number(box.dataset.complaintId) === complaintId;

  const markHold = (fallbackReason = '') => {
    const existing = complaintResolutionHold.get(complaintId) || {};
    let reopenReason = existing.reopenReason || fallbackReason || '';
    let commentDraft = existing.commentDraft || '';
    if (stillThisComplaint()) {
      const liveReason = section.querySelector('[data-reopen-reason]');
      if (liveReason) reopenReason = liveReason.value;
      const commentEl = box.querySelector('[data-comment-form] textarea');
      if (commentEl) commentDraft = commentEl.value;
    }
    complaintResolutionHold.set(complaintId, { reopenReason, commentDraft });
  };

  const hideRefresh = () => {
    const button = section.querySelector('[data-refresh-resolution]');
    if (button) button.hidden = true;
  };

  const ensureRefreshButton = () => {
    let button = section.querySelector('[data-refresh-resolution]');
    if (button) return button;
    button = document.createElement('button');
    button.type = 'button';
    button.dataset.refreshResolution = '';
    button.textContent = 'Refresh status';
    button.className = 'complaint-btn complaint-btn-ghost';
    if (statusEl) statusEl.before(button);
    else section.append(button);
    button.addEventListener('click', () => {
      recoverResolutionState().catch(() => {
        if (!stillThisComplaint()) return;
        button.disabled = false;
        if (statusEl && statusEl.isConnected) {
          statusEl.textContent = 'Status could not be refreshed. Use Refresh status to try again, or reload the page.';
        }
      });
    });
    return button;
  };

  const showRecovery = (message, remember = true) => {
    if (remember) markHold();
    setBusy(true);
    if (!stillThisComplaint()) return;
    const button = ensureRefreshButton();
    button.hidden = false;
    button.disabled = false;
    if (statusEl && statusEl.isConnected) statusEl.textContent = message;
  };

  const paintResolution = (updated) => {
    paintComplaintTriageSummary(box, updated);
    const statusNode = box.querySelector('[data-complaint-status]');
    if (statusNode && statusNode.isConnected) {
      statusNode.textContent = updated.status;
      statusNode.className = `status-badge status-${updated.status}`;
    }
    const summary = section.querySelector('[data-response-summary]');
    if (summary) summary.textContent = complaintResidentResponseText(updated);
    section.querySelector('[data-response-times]')?.remove();
    const times = complaintResidentResponseTimesHtml(updated);
    if (times && summary) summary.insertAdjacentHTML('afterend', times);
    const previousReason = section.querySelector('[data-reopen-reason]')?.value || '';
    const panelOpen = !!section.querySelector('[data-reopen-panel]')
      && !section.querySelector('[data-reopen-panel]').hasAttribute('hidden');
    const existing = section.querySelector('[data-response-actions]');
    const html = complaintResolutionActionsHtml(updated);
    if (!html) {
      existing?.remove();
    } else if (existing) {
      existing.outerHTML = html;
    } else {
      const anchor = section.querySelector('[data-response-status]') || section.querySelector('[data-refresh-resolution]');
      if (anchor) anchor.insertAdjacentHTML('beforebegin', html);
      else section.insertAdjacentHTML('beforeend', html);
    }
    if (updated.status === 'resolved' && (previousReason || panelOpen)) {
      const reasonInput = section.querySelector('[data-reopen-reason]');
      if (reasonInput && previousReason) reasonInput.value = previousReason;
      if (panelOpen || previousReason) {
        section.querySelector('[data-reopen-panel]')?.removeAttribute('hidden');
        section.querySelector('[data-reopen-toggle]')?.setAttribute('aria-expanded', 'true');
      }
    }
    bindResolutionControls(section);
    expectedResolutionId = Number(updated.resolutionUpdateId || 0);
    section.dataset.resolutionToken = String(expectedResolutionId);
  };

  const enableFor = (updated) => {
    hideRefresh();
    complaintResolutionHold.delete(complaintId);
    if (!updated || updated.status !== 'resolved') {
      setBusy(true);
      return;
    }
    setBusy(false);
  };

  async function refreshTimelineAndComments() {
    const timeline = box.querySelector('#complaintTimeline');
    if (timeline && timeline.isConnected) {
      const timelineToken = `${Date.now()}-${Math.random()}`;
      box.dataset.timelineToken = timelineToken;
      const updates = await loadComplaintTimeline(complaintId);
      if (!stillThisComplaint() || box.dataset.timelineToken !== timelineToken) return;
      if (timeline.isConnected) timeline.innerHTML = renderTimelineHtml(updates);
    }
    const commentRoot = box.querySelector('[data-complaint-comments]');
    if (!commentRoot || !commentRoot.isConnected || !stillThisComplaint()) return;
    const textarea = commentRoot.querySelector('textarea[name="body"]');
    const started = Date.now();
    while (textarea && textarea.isConnected && textarea.disabled && Date.now() - started < 20000) {
      await new Promise(resolve => setTimeout(resolve, 40));
    }
    if (!stillThisComplaint()) return;
    const list = commentRoot.querySelector('[data-comment-list]');
    if (list && list.isConnected) {
      const comments = await loadComplaintComments(complaintId);
      if (list.isConnected && (!textarea || textarea.isConnected) && stillThisComplaint()) {
        list.innerHTML = renderComplaintCommentsHtml(comments);
        updateResidentCommentCount(commentRoot, comments.length);
      }
    }
  }

  async function recoverResolutionState() {
    if (recovering) return;
    recovering = true;
    const button = ensureRefreshButton();
    button.disabled = true;
    if (stillThisComplaint() && statusEl) statusEl.textContent = 'Refreshing status…';
    try {
      await loadComplaints();
      renderComplaintsList();
    } catch {
      recovering = false;
      if (!stillThisComplaint()) return;
      button.disabled = false;
      if (statusEl && statusEl.isConnected) {
        statusEl.textContent = 'Status could not be refreshed. Use Refresh status to try again, or reload the page.';
      }
      return;
    }
    if (!stillThisComplaint()) {
      complaintResolutionHold.delete(complaintId);
      recovering = false;
      return;
    }
    const updated = COMPLAINTS.find(item => Number(item.id) === complaintId);
    if (!updated) {
      recovering = false;
      button.disabled = false;
      if (statusEl && statusEl.isConnected) {
        statusEl.textContent = 'Status could not be refreshed. Use Refresh status to try again, or reload the page.';
      }
      return;
    }
    paintResolution(updated);
    enableFor(updated);
    if (statusEl && statusEl.isConnected) statusEl.textContent = 'Status refreshed.';
    try {
      await refreshTimelineAndComments();
    } catch {
      if (stillThisComplaint() && statusEl && statusEl.isConnected) {
        statusEl.textContent = 'Status refreshed. Some details could not be loaded. Use Refresh status to try again, or reload the page.';
        const retry = section.querySelector('[data-refresh-resolution]');
        if (retry) {
          retry.hidden = false;
          retry.disabled = false;
        }
      }
    }
    recovering = false;
  }

  async function adoptFreshComplaint(message) {
    await loadComplaints();
    renderComplaintsList();
    if (!stillThisComplaint()) {
      complaintResolutionHold.delete(complaintId);
      return;
    }
    const updated = COMPLAINTS.find(item => Number(item.id) === complaintId);
    if (!updated) throw new Error('Complaint missing after refresh.');
    paintResolution(updated);
    enableFor(updated);
    if (statusEl && statusEl.isConnected) statusEl.textContent = message;
    try {
      await refreshTimelineAndComments();
    } catch {
      if (stillThisComplaint() && statusEl && statusEl.isConnected) {
        statusEl.textContent = `${message} Some details could not be loaded. Use Refresh status to try again.`;
        const retry = ensureRefreshButton();
        retry.hidden = false;
        retry.disabled = false;
      }
    }
  }

  const runResolution = (kind) => {
    if (busy || section.dataset.responsePending === '1') return;
    section.dataset.responsePending = '1';
    const discard = box.querySelector('[data-resident-discard]');
    if (discard) discard.disabled = true;
    sendResolution(kind).catch(() => {
      showRecovery('The result could not be verified. Refresh status before trying again.');
    }).finally(() => {
      section.dataset.responsePending = '0';
      if (discard && box.querySelector('[data-complaint-comments]')?.dataset.commentBusy !== '1') discard.disabled = false;
    });
  };

  function bindResolutionControls(root) {
    const confirmControl = root.querySelector('[data-confirm-resolution]');
    const toggleControl = root.querySelector('[data-reopen-toggle]');
    const submitControl = root.querySelector('[data-reopen-submit]');
    if (confirmControl && confirmControl.dataset.resolutionBound !== '1') {
      confirmControl.dataset.resolutionBound = '1';
      confirmControl.addEventListener('click', () => runResolution('confirm'));
    }
    if (toggleControl && toggleControl.dataset.resolutionBound !== '1') {
      toggleControl.dataset.resolutionBound = '1';
      toggleControl.addEventListener('click', () => {
        if (busy) return;
        const livePanel = section.querySelector('[data-reopen-panel]');
        const liveToggle = section.querySelector('[data-reopen-toggle]');
        const liveReason = section.querySelector('[data-reopen-reason]');
        if (!livePanel || !liveToggle) return;
        const willOpen = livePanel.hasAttribute('hidden');
        if (willOpen) {
          livePanel.removeAttribute('hidden');
          liveToggle.setAttribute('aria-expanded', 'true');
          liveReason?.focus();
        } else {
          livePanel.setAttribute('hidden', '');
          liveToggle.setAttribute('aria-expanded', 'false');
        }
      });
    }
    if (submitControl && submitControl.dataset.resolutionBound !== '1') {
      submitControl.dataset.resolutionBound = '1';
      submitControl.addEventListener('click', () => runResolution('reopen'));
    }
  }

  async function sendResolution(kind) {
    if (busy || !section.isConnected) return;
    const liveReason = section.querySelector('[data-reopen-reason]');
    let reason = '';
    if (kind === 'reopen') {
      const trimmed = String(liveReason?.value ?? '').trim();
      if (!trimmed || Array.from(trimmed).length > 2000) {
        if (statusEl) statusEl.textContent = 'Enter a reason of 1–2,000 characters. It is posted as a comment.';
        return;
      }
      reason = trimmed;
    }
    setBusy(true);
    if (statusEl) statusEl.textContent = kind === 'confirm' ? 'Saving confirmation…' : 'Reopening complaint…';

    let response;
    try {
      const payload = { complaint_id: complaintId, expected_resolution_id: expectedResolutionId };
      if (kind === 'reopen') payload.reason = reason;
      response = await fetch(`${API_BASE}/complaints/${kind === 'confirm' ? 'confirm_resolution' : 'reopen_complaint'}.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    } catch {
      if (stillThisComplaint() && statusEl) statusEl.textContent = 'Could not reach the server.';
      setBusy(false);
      return;
    }

    let data = null;
    try {
      const raw = await response.text();
      if (raw.trim() !== '') data = JSON.parse(raw);
    } catch {
      showRecovery('The result could not be verified. Refresh status before trying again.');
      return;
    }
    if (!data || typeof data !== 'object') {
      showRecovery('The result could not be verified. Refresh status before trying again.');
      return;
    }
    if (response.status === 409) {
      try {
        await adoptFreshComplaint(`${data.message || 'This complaint changed.'} Review the current status before responding.`);
      } catch {
        showRecovery('This complaint changed. Refresh status to load the current resolution before responding again.');
      }
      return;
    }
    if (!response.ok || !data.success) {
      complaintResolutionHold.delete(complaintId);
      hideRefresh();
      if (stillThisComplaint() && statusEl) statusEl.textContent = data.message || 'Could not save the complaint.';
      setBusy(false);
      return;
    }

    const savedMessage = data.message || 'Saved.';
    if (stillThisComplaint() && statusEl) statusEl.textContent = savedMessage;
    try {
      await adoptFreshComplaint(savedMessage);
    } catch {
      showRecovery('Saved. Refresh status to load the latest details.');
    }
  }

  bindResolutionControls(section);
  if (options.locked) {
    showRecovery('Current status could not be loaded. Use Refresh status to try again, or reload the page.', false);
  }
}

function restoreComplaintDrafts(box, draft) {
  if (!draft) return;
  const reasonInput = box.querySelector('[data-reopen-reason]');
  if (reasonInput && draft.reopenReason) {
    reasonInput.value = draft.reopenReason;
    box.querySelector('[data-reopen-panel]')?.removeAttribute('hidden');
    box.querySelector('[data-reopen-toggle]')?.setAttribute('aria-expanded', 'true');
  }
  const commentInput = box.querySelector('[data-comment-form] textarea');
  if (commentInput && draft.commentDraft) commentInput.value = draft.commentDraft;
}

function complaintOwnSharingHtml(c) {
  const sharing = c.isPublic
    ? 'Sharing enabled for the resident community'
    : 'Private';
  const link = c.isDuplicate
    ? '<p class="complaint-meta">Linked to a related report; each complaint remains tracked separately.</p>'
    : '';
  return `<section data-complaint-own-sharing class="complaint-section complaint-section-block">
    <h4>Sharing</h4>
    <p class="complaint-strong">${escapeHtml(sharing)}</p>
    ${link}
  </section>`;
}

// Keep in sync with js/official.js renderComplaintExtraFieldsHtml.
function renderComplaintExtraFieldsHtml(fields) {
  if (!Array.isArray(fields)) return '';
  const rows = fields.filter(field => field
    && typeof field.label === 'string' && typeof field.value === 'string'
    && field.label !== '' && field.value !== '');
  if (!rows.length) return '';
  return `<section data-complaint-extra-details class="complaint-section complaint-section-block">
    <h4>Additional details</h4>
    <dl class="complaint-extra-list">${rows.map(field => `
      <div>
        <dt>${escapeHtml(field.label)}</dt>
        <dd>${escapeHtml(field.value)}</dd>
      </div>`).join('')}
    </dl>
  </section>`;
}

async function viewComplaint(id) {
  if (!residentComplaintCanClose()) return;
  const seq = ++complaintDetailSeq;
  const openGeneration = complaintOpenGeneration;
  const numericId = Number(id);
  let lockActions = false;
  let stagedDraft = null;
  if (complaintResolutionHold.has(numericId)) {
    stagedDraft = complaintResolutionHold.get(numericId);
    try {
      await loadComplaints();
      if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
      renderComplaintsList();
      complaintResolutionHold.delete(numericId);
    } catch {
      if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
      lockActions = true;
    }
  }
  if (seq !== complaintDetailSeq || openGeneration !== complaintOpenGeneration) return;
  const c = COMPLAINTS.find(x => Number(x.id) === numericId);
  if (!c) return;
  if (!residentComplaintCanClose()) return;
  const box = document.getElementById('requestModalBox');
  const modal = document.getElementById('requestModal');
  if (!box || !modal) return;
  const opener = residentComplaintDialog?.opener || document.activeElement;
  releaseResidentComplaintDialog();
  box.dataset.complaintId = String(c.id);
  const timelineToken = `${Date.now()}-${Math.random()}`;
  const attachmentToken = `${Date.now()}-${Math.random()}`;
  box.dataset.timelineToken = timelineToken;
  box.dataset.attachmentToken = attachmentToken;
  box.classList.add('complaint-detail-box', 'resident-complaint-dialog');
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-modal', 'true');
  box.setAttribute('aria-labelledby', 'residentComplaintTitle');
  box.innerHTML = `
    <header class="rc-head">
      <div class="rc-heading">
        <div><p class="rc-reference">${escapeHtml(c.ref)}</p><h3 id="residentComplaintTitle">${escapeHtml(c.cat)}</h3></div>
        <div class="rc-badges"><span>Status: <span class="status-badge status-${complaintStatusToken(c.status)}" data-complaint-status>${escapeHtml(c.status)}</span></span><span>Severity: ${severityChip(c.severity)}</span></div>
        <button type="button" class="rc-close" onclick="closeRequestModal()" aria-label="Close complaint details">×</button>
      </div>
      <p class="rc-filing"><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i> ${escapeHtml(c.loc)}</span><span><i class="fa-regular fa-calendar" aria-hidden="true"></i> Filed ${escapeHtml(c.date)}</span></p>
      <div class="rc-tabs" role="tablist" aria-label="Complaint details">
        ${['Overview', 'Timeline', 'Comments'].map((name, index) => `<button type="button" role="tab" id="residentTab${name}" aria-controls="residentPanel${name}" aria-selected="${index === 0}" tabindex="${index === 0 ? 0 : -1}">${name}${name === 'Comments' ? ' <span data-comment-tab-count></span>' : ''}</button>`).join('')}
      </div>
    </header>
    <div class="rc-scroll">
      <section role="tabpanel" id="residentPanelOverview" aria-labelledby="residentTabOverview" tabindex="0">
        <div class="rc-overview">
          <div class="rc-main">
            <section class="rc-section"><h4>Description</h4><div class="rc-copy">${escapeHtml(c.description || 'No description provided.')}</div></section>
            <section class="rc-note"><h4><i class="fa-regular fa-comment" aria-hidden="true"></i> Official note</h4><div class="rc-copy">${escapeHtml(c.note || 'No official note yet.')}</div></section>
            ${renderComplaintExtraFieldsHtml(c.extraFieldsDisplay)}
            ${renderComplaintPartiesHtml(c.parties)}
            ${renderComplaintMediationHtml(c)}
            ${renderComplaintFeeHtml(c.bill)}
            ${complaintResidentResponseSectionHtml(c)}
            <section class="rc-section"><h4>Photos <span data-photo-count></span></h4><div id="complaintAttachments" class="rc-evidence" aria-live="polite">Loading photos…</div></section>
          </div>
          <aside class="rc-information" aria-labelledby="residentComplaintInfo"><h4 id="residentComplaintInfo">Complaint information</h4><dl><dt>Date filed</dt><dd>${escapeHtml(c.date || 'Not provided')}</dd></dl></aside>
        </div>
      </section>
      <section role="tabpanel" id="residentPanelTimeline" aria-labelledby="residentTabTimeline" tabindex="0" hidden><h4>Timeline</h4><div id="complaintTimeline" aria-live="polite">Loading timeline…</div></section>
      <section role="tabpanel" id="residentPanelComments" aria-labelledby="residentTabComments" tabindex="0" hidden>${complaintCommentsSectionHtml()}</section>
    </div>
    <footer class="rc-footer">
      <div data-resident-unsaved hidden><p role="alert">You have an unsent comment or reopen reason. Discard it?</p><div class="complaint-control-row"><button type="button" data-resident-keep class="complaint-btn">Keep editing</button><button type="button" data-resident-discard class="complaint-btn complaint-btn-ghost">Discard draft</button></div></div>
      <span>Your complaint details</span><button type="button" class="complaint-btn complaint-btn-ghost" onclick="closeRequestModal()">Close</button>
    </footer>
  `;
  const state = { box, id: Number(c.id), opener, oldOverflow: document.body.style.overflow };
  residentComplaintDialog = state;
  box.querySelector('[data-resident-keep]').addEventListener('click', () => {
    box.querySelector('[data-resident-unsaved]').hidden = true;
    state.returnFocus?.focus();
  });
  box.querySelector('[data-resident-discard]').addEventListener('click', () => {
    if (residentComplaintDialog === state && !residentComplaintBusy(box)) closeRequestModal(true);
  });
  const tabs = Array.from(box.querySelectorAll('[role="tab"]'));
  const activate = selected => tabs.forEach(tab => {
    const on = selected === tab;
    tab.setAttribute('aria-selected', String(on));
    tab.tabIndex = on ? 0 : -1;
    box.querySelector('#' + tab.getAttribute('aria-controls')).hidden = !on;
  });
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab));
    tab.addEventListener('keydown', event => {
      const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length
        : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length
        : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
      if (next === null) return;
      event.preventDefault(); activate(tabs[next]); tabs[next].focus();
    });
  });
  state.keyHandler = event => {
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeRequestModal(); }
    if (event.key !== 'Tab') return;
    const controls = Array.from(box.querySelectorAll('button, textarea, a[href], [tabindex="0"]'))
      .filter(node => !node.disabled && !node.closest('[hidden]') && node.getClientRects().length);
    const first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && (document.activeElement === first || !box.contains(document.activeElement))) {
      event.preventDefault(); last?.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !box.contains(document.activeElement))) {
      event.preventDefault(); first?.focus();
    }
  };
  state.unloadHandler = event => {
    if (residentComplaintDirty() || residentComplaintBusy(box)) { event.preventDefault(); event.returnValue = ''; }
  };
  document.addEventListener('keydown', state.keyHandler, true);
  window.addEventListener('beforeunload', state.unloadHandler);
  modal.classList.add('show');
  document.body.style.overflow = 'hidden';
  box.querySelector('.rc-close').focus();
  initComplaintComments(box.querySelector('[data-complaint-comments]'), c.id);
  initResidentResolutionActions(box.querySelector('[data-resident-response]'), c, box, { locked: lockActions });
  restoreComplaintDrafts(box, stagedDraft);
  const sameResidentDetail = () => residentComplaintDialog === state && modal.classList.contains('show');
  loadComplaintTimeline(c.id).then(updates => {
    if (!sameResidentDetail() || box.dataset.timelineToken !== timelineToken) return;
    box.querySelector('#complaintTimeline').innerHTML = renderTimelineHtml(updates);
  }).catch(() => {
    if (sameResidentDetail() && box.dataset.timelineToken === timelineToken) box.querySelector('#complaintTimeline').textContent = 'Timeline unavailable. Close and reopen this complaint to retry.';
  });
  loadComplaintAttachments(c.id).then(attachments => {
    if (!sameResidentDetail() || box.dataset.attachmentToken !== attachmentToken) return;
    const root = box.querySelector('#complaintAttachments');
    root.innerHTML = renderAttachmentsHtml(attachments);
    box.querySelector('[data-photo-count]').textContent = `(${attachments.length})`;
    initResidentPhotoFallbacks(root);
  }).catch(() => {
    if (sameResidentDetail() && box.dataset.attachmentToken === attachmentToken) box.querySelector('#complaintAttachments').textContent = 'Photos unavailable. Close and reopen this complaint to retry.';
  });
}

let residentComplaintDialog = null;

function residentComplaintDirty() {
  const box = residentComplaintDialog?.box;
  return !!box && !!(box.querySelector('[name="body"]')?.value.trim() || box.querySelector('[data-reopen-reason]')?.value.trim());
}

function residentComplaintBusy(box) {
  return !!box && (box.querySelector('[data-complaint-comments]')?.dataset.commentBusy === '1'
    || box.querySelector('[data-resident-response]')?.dataset.responsePending === '1');
}

function syncResidentDiscard(box) {
  const discard = box?.querySelector('[data-resident-discard]');
  if (discard) discard.disabled = residentComplaintBusy(box);
}

function residentComplaintCanClose(discard = false) {
  const state = residentComplaintDialog;
  if (!state) return true;
  if (residentComplaintBusy(state.box)) {
    const status = state.box.querySelector('[data-comment-status]');
    if (status) status.textContent = 'An update is in progress. Please wait before closing.';
    return false;
  }
  if (discard || !residentComplaintDirty()) return true;
  state.returnFocus = document.activeElement;
  state.box.querySelector('[data-resident-unsaved]').hidden = false;
  state.box.querySelector('[data-resident-keep]').focus();
  return false;
}

function releaseResidentComplaintDialog() {
  const state = residentComplaintDialog;
  if (!state) return;
  state.box.querySelector('[data-complaint-comments]')?._commentPollingCleanup?.();
  document.removeEventListener('keydown', state.keyHandler, true);
  window.removeEventListener('beforeunload', state.unloadHandler);
  document.body.style.overflow = state.oldOverflow;
  state.box.classList.remove('resident-complaint-dialog');
  ['role', 'aria-modal', 'aria-labelledby'].forEach(name => state.box.removeAttribute(name));
  residentComplaintDialog = null;
}

function initResidentPhotoFallbacks(root) {
  root.querySelectorAll('.complaint-photo-link img').forEach(img => {
    img.addEventListener('error', () => {
      img.hidden = true;
      img.parentElement.classList.add('rc-photo-unavailable');
      img.parentElement.querySelector('span').textContent = 'Photo unavailable · Open original';
    });
  });
}
function renderComplaintsList() {
  const el = document.getElementById('complaintsList');
  if (!el) return;
  el.innerHTML = COMPLAINTS.map(c => `
    <div class="complaint-item">
      <div class="ci-head">
        <div>
          <div class="ci-ref">${escapeHtml(c.ref)} · ${escapeHtml(c.cat)}</div>
          <div class="ci-title">${escapeHtml(c.loc)}</div>
        </div>
        <div class="ci-badges">
          ${severityChip(c.severity)}
          <span class="status-badge status-${complaintStatusToken(c.status)}">${escapeHtml(c.status)}</span>
        </div>
      </div>
      <div class="ci-meta">
        <span><i class="fa-regular fa-calendar"></i> Filed: ${escapeHtml(c.date)}</span>
        <span><i class="fa-solid fa-comment-dots"></i> ${escapeHtml(c.note)}</span>
      </div>
      <div class="ci-progress">
        <div class="progress-track"><div class="progress-fill" style="width:${complaintProgressWidth(c.progress)}%"></div></div>
        <span class="progress-label">${complaintProgressWidth(c.progress)}% resolved</span>
      </div>
      <div class="ci-actions">
        <button type="button" class="ci-view-btn" data-resident-complaint="${complaintDomId(c.id)}" onclick="viewComplaint(${complaintDomId(c.id)})">View Details</button>
      </div>
    </div>
  `).join('');
}

// #reqCategory holds the complaint type id; each extra-field group carries the
// id of its type in data-complaint-extra-category. Controls are select or input.
function syncComplaintExtraFields(formEl) {
  if (!formEl) return;
  const typeId = formEl.querySelector('#reqCategory')?.value || '';
  const locked = !!getRetryState(formEl);
  formEl.querySelectorAll('[data-complaint-extra-category]').forEach(group => {
    const active = typeId !== '' && group.dataset.complaintExtraCategory === typeId;
    group.hidden = !active;
    group.disabled = locked || !active;
    group.querySelectorAll('[data-complaint-extra-key]').forEach(control => {
      if (!locked && !active) control.value = '';
      control.disabled = locked || !active;
    });
  });
}

function collectComplaintExtraFields(formEl) {
  const values = {};
  if (!formEl || getRetryState(formEl)) return values;
  const typeId = formEl.querySelector('#reqCategory')?.value || '';
  formEl.querySelectorAll('[data-complaint-extra-category]').forEach(group => {
    if (group.hidden || group.disabled || group.dataset.complaintExtraCategory !== typeId) return;
    group.querySelectorAll('[data-complaint-extra-key]').forEach(control => {
      const value = String(control.value ?? '').trim();
      if (!control.disabled && value !== '') values[control.dataset.complaintExtraKey] = value;
    });
  });
  return values;
}

function initComplaintExtraFields() {
  const formEl = document.getElementById('complaintFormEl');
  if (!formEl) return;
  formEl.querySelector('#reqCategory')?.addEventListener('change', () => syncComplaintExtraFields(formEl));
  formEl.addEventListener('reset', () => {
    queueMicrotask(() => syncComplaintExtraFields(formEl));
  });
  syncComplaintExtraFields(formEl);
}

// ── Person involved (complaint revision Phase 5) ──
// The selected type's respondent mode (#complaintTypeModes [data-type-id]) decides the section:
// none = hidden; optional = a checkbox reveals it; required = shown, first row required.
// Rows are built with the DOM (no HTML strings), so typed names are never parsed.
const COMPLAINT_PERSON_MAX = 5;
let complaintPersonRowSeq = 0;

function complaintSelectedRespondentMode(formEl) {
  const typeId = String(formEl?.querySelector('#reqCategory')?.value || '');
  if (!/^[1-9][0-9]*$/.test(typeId)) return 'none';
  const entry = formEl.querySelector(`#complaintTypeModes [data-type-id="${typeId}"]`);
  const mode = entry?.dataset?.respondentMode;
  return mode === 'optional' || mode === 'required' ? mode : 'none';
}

function complaintPersonRows(formEl) {
  return Array.from(formEl?.querySelectorAll('[data-person-row]') || []);
}

function complaintPersonInput(rowId, key, labelText, attrs) {
  const field = document.createElement('div');
  field.className = 'field';
  const label = document.createElement('label');
  const input = document.createElement('input');
  input.type = attrs.type || 'text';
  input.className = 'form-input';
  input.id = `complaint-person-${rowId}-${key}`;
  input.dataset.personKey = key;
  input.maxLength = attrs.maxLength;
  if (attrs.inputMode) input.inputMode = attrs.inputMode;
  if (attrs.autocomplete) input.autocomplete = attrs.autocomplete;
  label.htmlFor = input.id;
  label.textContent = labelText;
  field.append(label, input);
  return field;
}

function addComplaintPersonRow(formEl) {
  const list = formEl?.querySelector('#complaintPersonRows');
  if (!list || complaintPersonRows(formEl).length >= COMPLAINT_PERSON_MAX) return null;
  const rowId = ++complaintPersonRowSeq;
  const row = document.createElement('div');
  row.className = 'complaint-person-row';
  row.dataset.personRow = String(rowId);
  const fields = document.createElement('div');
  fields.className = 'form-3col';
  fields.append(
    complaintPersonInput(rowId, 'full_name', 'Full name', { maxLength: 150, autocomplete: 'off' }),
    complaintPersonInput(rowId, 'address', 'Address (optional)', { maxLength: 255, autocomplete: 'off' }),
    complaintPersonInput(rowId, 'contact_no', 'Contact number (optional)', { type: 'tel', maxLength: 20, inputMode: 'tel', autocomplete: 'off' }),
  );
  const remove = document.createElement('button');
  remove.type = 'button';
  remove.className = 'btn-cancel-form complaint-person-remove';
  remove.dataset.personRemove = String(rowId);
  remove.textContent = 'Remove';
  remove.setAttribute('aria-label', 'Remove this person');
  row.append(fields, remove);
  list.appendChild(row);
  syncComplaintPersonRows(formEl);
  return row;
}

function syncComplaintPersonRows(formEl) {
  const mode = complaintSelectedRespondentMode(formEl);
  const rows = complaintPersonRows(formEl);
  rows.forEach((row, index) => {
    const name = row.querySelector('[data-person-key="full_name"]');
    if (name) name.required = true;
    const remove = row.querySelector('[data-person-remove]');
    if (remove) remove.disabled = mode === 'required' && index === 0 && rows.length === 1;
  });
  const add = formEl.querySelector('#complaintPersonAdd');
  const limit = formEl.querySelector('#complaintPersonLimit');
  if (add) add.disabled = rows.length >= COMPLAINT_PERSON_MAX;
  if (limit) limit.hidden = rows.length < COMPLAINT_PERSON_MAX;
}

function syncComplaintPersonSection(formEl) {
  const section = formEl?.querySelector('#complaintPersonSection');
  if (!section) return;
  const toggleWrap = formEl.querySelector('#complaintPersonToggleWrap');
  const toggle = formEl.querySelector('#complaintPersonToggle');
  const body = formEl.querySelector('#complaintPersonBody');
  const locked = !!getRetryState(formEl);
  const mode = complaintSelectedRespondentMode(formEl);
  if (mode !== 'optional' && toggle) toggle.checked = false;
  const open = mode === 'required' || (mode === 'optional' && !!toggle?.checked);
  section.hidden = mode === 'none';
  section.disabled = locked || mode === 'none';
  if (toggleWrap) toggleWrap.hidden = mode !== 'optional';
  if (body) body.hidden = !open;
  if (locked) return;
  if (!open) {
    complaintPersonRows(formEl).forEach(row => row.remove());
  } else if (complaintPersonRows(formEl).length === 0) {
    addComplaintPersonRow(formEl);
  }
  syncComplaintPersonRows(formEl);
}

/** respondents[] for create.php, or null when the section is not in use (the key is then omitted). */
function collectComplaintRespondents(formEl) {
  const section = formEl?.querySelector('#complaintPersonSection');
  const body = formEl?.querySelector('#complaintPersonBody');
  if (!section || section.hidden || section.disabled || !body || body.hidden) return null;
  return complaintPersonRows(formEl).map(row => {
    const value = key => String(row.querySelector(`[data-person-key="${key}"]`)?.value ?? '').trim();
    return { full_name: value('full_name'), address: value('address') || null, contact_no: value('contact_no') || null };
  });
}

function setComplaintFormError(message) {
  const box = document.getElementById('complaintFormError');
  if (!box) return;
  box.textContent = message || '';
  box.hidden = !message;
}

function initComplaintPersonSection() {
  const formEl = document.getElementById('complaintFormEl');
  if (!formEl) return;
  formEl.querySelector('#reqCategory')?.addEventListener('change', () => {
    setComplaintFormError('');
    syncComplaintPersonSection(formEl);
  });
  formEl.querySelector('#complaintPersonToggle')?.addEventListener('change', () => syncComplaintPersonSection(formEl));
  formEl.querySelector('#complaintPersonAdd')?.addEventListener('click', () => {
    const row = addComplaintPersonRow(formEl);
    row?.querySelector('input')?.focus();
  });
  formEl.querySelector('#complaintPersonRows')?.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-person-remove]') : null;
    if (!button || button.disabled) return;
    button.closest('[data-person-row]')?.remove();
    if (complaintPersonRows(formEl).length === 0) {
      const toggle = formEl.querySelector('#complaintPersonToggle');
      if (complaintSelectedRespondentMode(formEl) === 'optional' && toggle) toggle.checked = false;
    }
    syncComplaintPersonSection(formEl);
  });
  formEl.addEventListener('reset', () => {
    queueMicrotask(() => {
      setComplaintFormError('');
      complaintPersonRows(formEl).forEach(row => row.remove());
      syncComplaintPersonSection(formEl);
    });
  });
  syncComplaintPersonSection(formEl);
}

// Names only (resident view). Keep in sync with js/official.js complaintPartiesSectionHtml.
function renderComplaintPartiesHtml(parties) {
  if (!Array.isArray(parties) || parties.length === 0) return '';
  const names = role => parties.filter(p => p.role === role).map(p => escapeHtml(p.full_name));
  const complainants = names('complainant');
  const respondents = names('respondent');
  const rows = [];
  if (complainants.length) rows.push(`<div><dt>${complainants.length === 1 ? 'Complainant' : 'Complainants'}</dt><dd>${complainants.join('<br>')}</dd></div>`);
  if (respondents.length) rows.push(`<div><dt>${respondents.length === 1 ? 'Respondent' : 'Respondents'}</dt><dd>${respondents.join('<br>')}</dd></div>`);
  return `<section data-complaint-parties class="rc-section"><h4>People involved</h4><dl class="complaint-extra-list">${rows.join('')}</dl></section>`;
}

// ── Mediation (complaint revision Phase 6) ──
// Filer view only: case number, date/time, venue, status, outcome and public summary.
// Times are Asia/Manila strings from the server, read as text (no browser time zone).
const MEDIATION_STATUS_LABELS = { scheduled: 'Scheduled', completed: 'Completed', cancelled: 'Cancelled', rescheduled: 'Rescheduled' };
const MEDIATION_OUTCOME_LABELS = { settled: 'Settled', for_follow_up: 'For follow-up', referred: 'Referred', no_agreement: 'No agreement' };

function mediationRangeText(startsAt, endsAt) {
  const parse = value => {
    const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(value || ''));
    return m ? { y: +m[1], mo: +m[2] - 1, d: +m[3], h: +m[4], mi: +m[5] } : null;
  };
  const clock = p => `${p.h % 12 === 0 ? 12 : p.h % 12}:${String(p.mi).padStart(2, '0')} ${p.h < 12 ? 'AM' : 'PM'}`;
  const s = parse(startsAt);
  const e = parse(endsAt);
  if (!s) return '';
  const day = new Date(Date.UTC(s.y, s.mo, s.d));
  const weekday = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][day.getUTCDay()];
  const month = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][s.mo];
  return `${weekday}, ${month} ${s.d}, ${s.y}, ${clock(s)}${e ? `–${clock(e)}` : ''}`;
}

function renderComplaintMediationHtml(c) {
  const sessions = Array.isArray(c.hearings) ? c.hearings : [];
  if (!c.caseNo && sessions.length === 0) return '';
  const rows = sessions.map(s => {
    const outcome = s.status === 'completed' && MEDIATION_OUTCOME_LABELS[s.outcome]
      ? `<p class="rc-mediation-outcome">Outcome: ${escapeHtml(MEDIATION_OUTCOME_LABELS[s.outcome])}</p>` : '';
    const summary = s.status === 'completed' && s.public_summary
      ? `<p class="rc-copy rc-mediation-summary">${escapeHtml(s.public_summary)}</p>` : '';
    return `<li><p><strong>Session ${Number(s.session_no)}</strong> — ${escapeHtml(mediationRangeText(s.starts_at, s.ends_at))} · ${escapeHtml(s.venue || '')}
      · <span class="rc-mediation-status is-${escapeHtml(s.status)}">${escapeHtml(MEDIATION_STATUS_LABELS[s.status] || s.status)}</span></p>${outcome}${summary}</li>`;
  }).join('');
  return `<section data-complaint-mediation class="rc-section rc-mediation"><h4>Mediation${c.caseNo ? ` · Case no. ${escapeHtml(c.caseNo)}` : ''}</h4>
    ${rows ? `<ul class="rc-mediation-list">${rows}</ul>` : '<p class="rc-copy">No mediation sessions yet.</p>'}</section>`;
}

// ── Fee (complaint revision Phase 10) ──
// Filer view only: amount, note, status and dates. No receipt no., waive reason or official.
const COMPLAINT_FEE_STATUS = { unpaid: 'Unpaid — pay at the barangay hall', paid: 'Paid', waived: 'Waived' };

function renderComplaintFeeHtml(bill) {
  if (!bill || !COMPLAINT_FEE_STATUS[bill.status]) return '';
  const amount = /^\d+\.\d{2}$/.test(String(bill.amount)) ? String(bill.amount) : '0.00';
  return `<section data-complaint-fee class="rc-section rc-fee"><h4>Fee</h4>
    <p class="rc-fee-amount">₱${escapeHtml(amount)}</p>
    ${bill.billing_note ? `<p class="rc-copy">${escapeHtml(bill.billing_note)}</p>` : ''}
    <p><span class="rc-fee-status is-${escapeHtml(bill.status)}">${escapeHtml(COMPLAINT_FEE_STATUS[bill.status])}</span></p></section>`;
}

function mapComplaintBill(bill) {
  if (!bill || typeof bill !== 'object' || !COMPLAINT_FEE_STATUS[bill.status]) return null;
  return {
    amount: typeof bill.amount === 'string' ? bill.amount : '0.00',
    status: bill.status,
    billing_note: typeof bill.billing_note === 'string' ? bill.billing_note : null
  };
}

function mapComplaintHearings(rows) {
  if (!Array.isArray(rows)) return [];
  return rows.filter(s => s && typeof s.starts_at === 'string' && MEDIATION_STATUS_LABELS[s.status]).map(s => ({
    session_no: Number(s.session_no) || 0,
    starts_at: s.starts_at,
    ends_at: typeof s.ends_at === 'string' ? s.ends_at : '',
    venue: typeof s.venue === 'string' ? s.venue : '',
    status: s.status,
    outcome: typeof s.outcome === 'string' ? s.outcome : null,
    public_summary: typeof s.public_summary === 'string' ? s.public_summary : null
  }));
}

function setFormModalOpen(form, open) {
  if (!form) return;
  form.classList.toggle('show', open);
  form.style.display = '';
  form.setAttribute('aria-hidden', open ? 'false' : 'true');
}

function initComplaintFormToggle() {
  const openBtn  = document.getElementById('newComplaintBtn');
  const closeBtn = document.getElementById('closeComplaintForm');
  const cancelBtn= document.getElementById('cancelComplaintForm');
  const form     = document.getElementById('newComplaintForm');
  const formEl   = document.getElementById('complaintFormEl');

  const requestClose = () => {
    if (getRetryState(formEl)) { skipPendingComplaintUploads(formEl); return; }
    setFormModalOpen(form, false);
    openBtn?.focus();
  };

  openBtn?.addEventListener('click', () => {
    setFormModalOpen(form, true);
    formEl?.querySelector('select, input, textarea')?.focus();
  });
  closeBtn?.addEventListener('click', requestClose);
  cancelBtn?.addEventListener('click', requestClose);
  formEl?.addEventListener('submit', e => {
    e.preventDefault();
    submitComplaint(formEl, form);
  });
  document.getElementById('complaintRetryBtn')?.addEventListener('click', () => retryPendingComplaintUploads(formEl));
  document.getElementById('complaintSkipBtn')?.addEventListener('click', () => skipPendingComplaintUploads(formEl));
}

function initComplaintPhotoInput() {
  const input = document.getElementById('reqPhotos');
  const preview = document.getElementById('reqPhotosPreview');
  const error = document.getElementById('reqPhotosError');
  if (!input || !preview) return;

  const MAX_FILES = 3;
  const MAX_BYTES = 50 * 1024 * 1024;
  const ALLOWED = ['image/jpeg', 'image/png'];

  const showError = (msg) => {
    if (!error) return;
    error.textContent = msg;
    error.style.display = msg ? 'block' : 'none';
  };

  input.addEventListener('change', () => {
    showError('');
    preview.innerHTML = '';
    const files = Array.from(input.files || []);
    if (files.length > MAX_FILES) {
      showError(`Maximum ${MAX_FILES} photos per complaint.`);
      input.value = '';
      return;
    }
    for (const file of files) {
      if (!ALLOWED.includes(file.type)) {
        showError(`Unsupported file: ${file.name}. Use JPEG or PNG.`);
        input.value = '';
        return;
      }
      if (file.size > MAX_BYTES) {
        showError(`Photo too large: ${file.name}. Max 50MB.`);
        input.value = '';
        return;
      }
    }
    files.forEach(file => {
      const url = URL.createObjectURL(file);
      const img = document.createElement('img');
      img.src = url;
      img.alt = file.name;
      img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid var(--border);';
      img.addEventListener('load', () => URL.revokeObjectURL(url));
      preview.appendChild(img);
    });
  });

  // Reset preview whenever the form is reset (close form, successful submit).
  const formEl = document.getElementById('complaintFormEl');
  formEl?.addEventListener('reset', () => {
    preview.innerHTML = '';
    showError('');
  });
}

// ── NOTIFICATIONS ─────────────────────────
function renderNotifications() {
  const list = document.getElementById('notifList');
  const drawerList = document.getElementById('notifDrawerList');
  const allNotifs = getAllNotifications();  // get notifications
  const html = allNotifs.map(n => `
    <div class="notif-item ${n.unread ? 'unread' : ''}" onclick="markRead(${n.id})">
      <div class="ni-icon" style="background:${n.iconBg}; color:${n.iconColor}">
        <i class="fa-solid ${n.icon}"></i>
      </div>
      <div>
        <div class="ni-title">${n.title}</div>
        <div class="ni-body">${n.body}</div>
        <div class="ni-time"><i class="fa-regular fa-clock"></i> ${n.time}</div>
      </div>
    </div>
  `).join('');
  if (list) list.innerHTML = html;
  if (drawerList) drawerList.innerHTML = html;
}

async function markAllNotificationsRead() {
  const button = document.getElementById('markAllReadBtn');
  if (button) button.disabled = true;
  try {
    await fetchJson(`${API_BASE}/notifications/mark_all_read.php`, { method: 'POST' });
    await loadNotifications();
    await refreshResidentView();
    showToast('Notifications Updated', 'All notifications are marked as read.');
  } catch (err) {
    showToast('Update Failed', err.message);
  } finally {
    if (button) button.disabled = false;
  }
}

function markRead(id) {
  const allNotifs = getAllNotifications();
  const n = allNotifs.find(x => x.id === id);
  if (n) n.unread = false;
  renderNotifications();
}

// Notification badge/count logic removed — notifications render without numeric badge.

// ── NOTIFICATION DRAWER ───────────────────
function initNotifDrawer() {
  const drawer  = document.getElementById('notifDrawer');
  const overlay = document.getElementById('drawerOverlay');
  const toggle  = document.getElementById('notifToggle');
  const close   = document.getElementById('closeNotifDrawer');

  toggle?.addEventListener('click', () => { drawer.classList.toggle('open'); overlay.classList.toggle('show'); });
  close?.addEventListener('click',  () => { drawer.classList.remove('open'); overlay.classList.remove('show'); });
  overlay?.addEventListener('click',() => { drawer.classList.remove('open'); overlay.classList.remove('show'); });
}

function initMarkAllRead() {
  document.getElementById('markAllReadBtn')?.addEventListener('click', markAllNotificationsRead);
}

function mapRequestRow(row) {
  let requestFields = {};
  try {
    requestFields = row.request_fields_json ? JSON.parse(row.request_fields_json) : {};
  } catch { requestFields = {}; }
  return {
    id: Number(row.id),
    ref: row.reference_no,
    type: row.document_type,
    purpose: row.purpose,
    date: row.date_requested,
    status: row.status,
    copies: Number(row.copies || 1),
    totalAmount: Number(row.total_amount || 0),
    paymentStatus: row.payment_status || 'unpaid',
    requestFields,
  };
}

function mapComplaintRow(row) {
  const statusToProgress = { open: 0, investigating: 50, resolved: 100 };
  return {
    id: Number(row.id),
    ref: row.reference_no,
    cat: row.type_name || row.category,
    severity: (row.severity || 'medium').toLowerCase(),
    loc: row.location_text,
    description: row.description || '',
    date: row.date_filed,
    status: row.status,
    progress: statusToProgress[row.status] ?? 0,
    note: row.official_note || 'Received. Awaiting assignment.',
    residentConfirmation: row.resident_confirmation || null,
    confirmedAt: row.confirmed_at || null,
    reopenedAt: row.reopened_at || null,
    resolutionUpdateId: Number(row.resolution_update_id || 0),
    assignedOfficialId: complaintIdOrNull(row.assigned_official_id),
    assignedOfficialName: row.assigned_official_name == null || String(row.assigned_official_name).trim() === ''
      ? null
      : String(row.assigned_official_name).trim(),
    assignedOfficialStatus: row.assigned_official_status === 'active' || row.assigned_official_status === 'inactive'
      ? row.assigned_official_status
      : null,
    slaDueAt: row.sla_due_at ? String(row.sla_due_at) : null,
    escalatedAt: row.escalated_at ? String(row.escalated_at) : null,
    isOverdue: Number(row.is_overdue) === 1,
    extraFields: row.extra_fields && typeof row.extra_fields === 'object' && !Array.isArray(row.extra_fields)
      ? row.extra_fields : {},
    extraFieldsDisplay: Array.isArray(row.extra_fields_display) ? row.extra_fields_display : [],
    parties: Array.isArray(row.parties)
      ? row.parties.filter(p => p && (p.role === 'complainant' || p.role === 'respondent') && typeof p.full_name === 'string' && p.full_name !== '')
      : [],
    caseNo: typeof row.case_no === 'string' && row.case_no !== '' ? row.case_no : null,
    hearings: mapComplaintHearings(row.hearings),
    bill: mapComplaintBill(row.bill),
    isPublic: row.is_public === true || row.is_public === 1,
    isDuplicate: row.is_duplicate === true || row.is_duplicate === 1,
  };
}

function mapNotificationRow(row) {
  return {
    id: Number(row.id),
    unread: Number(row.is_read) === 0,
    iconBg: '#DBEAFE',
    iconColor: '#1976D2',
    icon: 'fa-bell',
    title: row.title,
    body: row.body,
    time: row.created_at
  };
}

async function fetchJson(url, options) {
  const res = await fetch(url, options);
  const raw = await res.text();
  let data = null;

  if (raw.trim() !== '') {
    try {
      data = JSON.parse(raw);
    } catch (err) {
      data = null;
    }
  }

  if (!res.ok) {
    // status lets callers show a validation message (422) inline.
    const error = new Error(data && data.message ? data.message : `Server request failed (HTTP ${res.status}).`);
    error.status = res.status;
    throw error;
  }

  if (!data || typeof data !== 'object') {
    throw new Error('Server returned an invalid response format.');
  }

  if (!data.success) {
    const error = new Error(data.message || 'Request failed.');
    error.status = res.status;
    throw error;
  }

  return data;
}

async function loadRequests() {
  // Resident identity is provided by the server-side PHP session.
  const data = await fetchJson(`${API_BASE}/requests/list.php`);
  REQUESTS = (data.requests || []).map(mapRequestRow);
}

async function loadComplaints() {
  const data = await fetchJson(`${API_BASE}/complaints/list.php`);
  COMPLAINTS = (data.complaints || []).map(mapComplaintRow);
}

async function loadNotifications() {
  const data = await fetchJson(`${API_BASE}/notifications/list.php`);
  NOTIFICATIONS = (data.notifications || []).map(mapNotificationRow);
}

function renderAnnouncements() {
  const grid = document.getElementById('residentAnnouncementsGrid');
  if (!grid) return;
  if (!ANNOUNCEMENTS.length) {
    grid.innerHTML = '<p class="community-empty">No announcements have been posted yet.</p>';
    return;
  }
  grid.innerHTML = ANNOUNCEMENTS.slice(0, 6).map(a => `
    <article class="community-card announcement-card">
      <div class="community-meta">
        ${Number(a.is_pinned) === 1 ? '<span class="community-badge">Pinned</span>' : ''}
        <span><i class="fa-regular fa-calendar"></i> ${formatCommunityDate(a.created_at)}</span>
      </div>
      <h3>${a.title}</h3>
      <p>${a.content}</p>
    </article>
  `).join('');
}

async function loadAnnouncements() {
  const data = await fetchJson(`${API_BASE}/announcements/list.php`);
  ANNOUNCEMENTS = data.announcements || [];
  announcementsLoaded = true;
  renderAnnouncements();
}

function renderServices(filter = 'all') {
  const grid = document.getElementById('residentServicesGrid');
  if (!grid) return;
  const filtered = filter === 'all'
    ? SERVICES
    : SERVICES.filter(service => String(service.category || '').toLowerCase() === filter);
  if (!filtered.length) {
    grid.innerHTML = '<p class="community-empty">No local services have been added yet.</p>';
    return;
  }
  grid.innerHTML = filtered.map(service => `
    <article class="community-card service-card">
      <div class="service-card-head">
        <div class="service-icon">🏪</div>
        <div>
          <h3>${service.service_name}</h3>
          <span>${service.category}</span>
        </div>
      </div>
      <div class="service-verified"><i class="fa-solid fa-circle-check"></i> Barangay Verified</div>
      <p>${service.description || 'No description provided.'}</p>
      <div class="service-detail"><i class="fa-solid fa-location-dot"></i> ${service.address || 'Address not provided'}</div>
      <div class="service-detail"><i class="fa-solid fa-phone"></i> ${service.contact_number || 'Contact not provided'}</div>
      ${service.operating_hours ? `<div class="service-detail"><i class="fa-regular fa-clock"></i> ${service.operating_hours}</div>` : ''}
    </article>
  `).join('');
}

function renderServiceFilters() {
  const row = document.getElementById('residentServiceFilters');
  if (!row) return;
  const filters = ['All', ...new Set(SERVICES.map(service => service.category).filter(Boolean))];
  row.innerHTML = filters.map((filter, index) =>
    `<button class="filter-btn ${index === 0 ? 'active' : ''}" data-filter="${filter.toLowerCase()}">${filter}</button>`
  ).join('');
  row.querySelectorAll('.filter-btn').forEach(button => {
    button.addEventListener('click', () => {
      row.querySelectorAll('.filter-btn').forEach(item => item.classList.remove('active'));
      button.classList.add('active');
      renderServices(button.dataset.filter);
    });
  });
}

async function loadServices() {
  const data = await fetchJson(`${API_BASE}/services/list.php`);
  SERVICES = data.services || [];
  servicesLoaded = true;
  renderServiceFilters();
  renderServices();
}

function formatCommunityDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric'
  });
}

async function refreshResidentView() {
  const wbPending = document.getElementById('wbPendingRequests');
  const wbActive  = document.getElementById('wbActiveComplaints');
  if (wbPending) wbPending.textContent = String(
    REQUESTS.filter(r => r.status === 'pending').length
  );
  if (wbActive) wbActive.textContent = String(
    COMPLAINTS.filter(c => c.status !== 'resolved').length
  );
  renderDashStats();
  renderRecentRequests();
  renderRecentComplaints();
  renderRequestsTable(REQUESTS);
  renderComplaintsList();
  renderNotifications();
}

async function submitRequest(formEl, form) {
  const type   = document.getElementById('reqDocType')?.value || '';
  const purpose = document.getElementById('reqPurpose')?.value || '';
  const copies = Math.min(5, Math.max(1, parseInt(document.getElementById('reqCopies')?.value || '1', 10) || 1));
  if (!type || !purpose) {
    showToast('Missing Fields', 'Please select a document type and purpose.');
    return;
  }
  if (!hasPostedPrice(type)) {
    showToast('Price Unavailable', 'This document type has no posted price. Choose another type or try again later.');
    return;
  }

  // Enforce required extras client-side to give a clean error before the request.
  const extras = collectExtraRequestFields();
  const required = requestFieldViews(type).filter(d => d.required);
  const missing = required.filter(d => !extras[d.name]);
  if (missing.length) {
    showToast('Missing Fields', `Please fill in: ${missing.map(d => d.label).join(', ')}.`);
    return;
  }

  try {
    await fetchJson(`${API_BASE}/requests/create.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ document_type: type, purpose, copies, fields: extras })
    });
    setFormModalOpen(form, false);
    formEl.reset();
    renderExtraRequestFields();
    renderRequestTypeInfo();
    updateRequestTotal();
    await loadRequests();
    await refreshResidentView();
    showToast('Request Submitted!', 'Your document request has been submitted.');
  } catch (err) {
    showToast('Submit Failed', err.message);
  }
}

async function submitComplaint(formEl, form) {
  const complaintTypeId = Number(document.getElementById('reqCategory')?.value || 0);
  const location = document.getElementById('reqLocation')?.value || '';
  const description = document.getElementById('reqDescription')?.value || '';
  const severity = document.getElementById('reqSeverity')?.value || 'medium';

  if (!Number.isInteger(complaintTypeId) || complaintTypeId <= 0 || !location || !description) {
    showToast('Missing Fields', 'Please complete all complaint details.');
    return;
  }

  const fileInput = document.getElementById('reqPhotos');
  const files = fileInput ? Array.from(fileInput.files || []) : [];
  const submitBtn = formEl.querySelector('button[type="submit"]');
  const originalBtnText = submitBtn ? submitBtn.textContent : '';
  if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting...'; }

  let complaintId = 0;
  let created;
  const payload = { complaint_type_id: complaintTypeId, location_text: location, description, severity, extra_fields: collectComplaintExtraFields(formEl) };
  const respondents = collectComplaintRespondents(formEl);
  if (respondents !== null) payload.respondents = respondents;
  setComplaintFormError('');
  try {
    created = await fetchJson(`${API_BASE}/complaints/create.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    complaintId = Number(created.id) || 0;
  } catch (err) {
    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalBtnText; }
    if (err.status === 422 || err.status === 400) {
      setComplaintFormError(err.message);
    } else {
      showToast('Submit Failed', err.message);
    }
    return;
  }

  // Complaint is now committed. Attachments are best-effort; failures route to retry mode.
  let uploaded = 0;
  const failedFiles = [];
  for (let i = 0; i < files.length; i++) {
    if (submitBtn) submitBtn.textContent = `Uploading ${i + 1}/${files.length}...`;
    try {
      const body = new FormData();
      body.append('complaint_id', String(complaintId));
      body.append('photo', files[i]);
      const res = await fetch(`${API_BASE}/complaints/upload_attachment.php`, { method: 'POST', body });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || !data.success) throw new Error((data && data.message) || `HTTP ${res.status}`);
      uploaded++;
    } catch (err) {
      failedFiles.push(files[i]);
    }
  }

  if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalBtnText; }
  await loadComplaints();
  await refreshResidentView();

  if (failedFiles.length === 0) {
    setFormModalOpen(form, false);
    formEl.reset();
    formEl.querySelectorAll('input[type="file"]').forEach(el => { el.value = ''; });
    if (files.length === 0) {
      showToast('Complaint Submitted!', 'Your complaint has been submitted for review.');
    } else {
      showToast('Complaint Submitted!', `${uploaded} photo${uploaded === 1 ? '' : 's'} attached.`);
    }
  } else {
    enterComplaintRetryMode(formEl, form, complaintId, String(created.reference_no || ''), failedFiles);
  }
}

function enterComplaintRetryMode(formEl, form, complaintId, referenceNo, pendingFiles) {
  // Snapshot which controls were enabled before we lock everything, so teardown
  // restores the original state rather than blanket-enabling every node.
  const originallyEnabled = new Set(
    Array.from(formEl.querySelectorAll('input, select, textarea'))
      .filter(el => !el.disabled)
  );
  setRetryState(formEl, {
    formEl,
    form,
    complaintId,
    referenceNo,
    pendingFiles,
    originallyEnabled,
  });
  // Complaint is filed — lock every field so resubmission can't duplicate it.
  formEl.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = true; });
  syncComplaintPersonSection(formEl);
  const actions = formEl.querySelector('.form-actions');
  if (actions) actions.style.display = 'none';
  const banner = document.getElementById('complaintRetryBanner');
  const message = document.getElementById('complaintRetryMessage');
  const retryBtn = document.getElementById('complaintRetryBtn');
  if (!banner || !message || !retryBtn) return;
  message.textContent = `Complaint ${referenceNo} was filed, but ${pendingFiles.length} photo${pendingFiles.length === 1 ? '' : 's'} failed to upload.`;
  retryBtn.textContent = `Retry Upload (${pendingFiles.length})`;
  retryBtn.disabled = false;
  banner.style.display = '';
}

async function retryPendingComplaintUploads(formEl) {
  const state = getRetryState(formEl);
  if (!state) return;
  const { complaintId, referenceNo } = state;
  const pendingFiles = state.pendingFiles;
  const retryBtn = document.getElementById('complaintRetryBtn');
  if (!retryBtn) return;
  retryBtn.disabled = true;
  const stillFailed = [];
  for (let i = 0; i < pendingFiles.length; i++) {
    retryBtn.textContent = `Uploading ${i + 1}/${pendingFiles.length}...`;
    try {
      const body = new FormData();
      body.append('complaint_id', String(complaintId));
      body.append('photo', pendingFiles[i]);
      const res = await fetch(`${API_BASE}/complaints/upload_attachment.php`, { method: 'POST', body });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data || !data.success) throw new Error((data && data.message) || `HTTP ${res.status}`);
    } catch (err) {
      stillFailed.push(pendingFiles[i]);
    }
  }
  if (stillFailed.length === 0) {
    closeComplaintRetryMode(formEl);
    await loadComplaints();
    await refreshResidentView();
    showToast('Photos Uploaded', `All remaining photos attached to ${referenceNo}.`);
  } else {
    state.pendingFiles = stillFailed;
    retryBtn.disabled = false;
    retryBtn.textContent = `Retry Upload (${stillFailed.length})`;
    const message = document.getElementById('complaintRetryMessage');
    if (message) {
      message.textContent = `${stillFailed.length} photo${stillFailed.length === 1 ? '' : 's'} still failing to upload.`;
    }
  }
}

function closeComplaintRetryMode(formEl) {
  const banner = document.getElementById('complaintRetryBanner');
  if (banner) banner.style.display = 'none';
  const state = getRetryState(formEl);
  if (!state) return;
  // Restore the exact enabled/disabled state from before the lock. Any
  // conditionally-disabled field (present or future) stays that way.
  state.formEl.querySelectorAll('input, select, textarea').forEach(el => {
    el.disabled = !state.originallyEnabled.has(el);
  });
  const actions = state.formEl.querySelector('.form-actions');
  if (actions) actions.style.display = '';
  setFormModalOpen(state.form, false);
  state.formEl.reset();
  // form.reset() is unreliable for FileList across engines — force-clear.
  state.formEl.querySelectorAll('input[type="file"]').forEach(el => { el.value = ''; });
  clearRetryState(formEl);
  syncComplaintExtraFields(formEl);
}

function skipPendingComplaintUploads(formEl) {
  const state = getRetryState(formEl);
  if (!state) return;
  const referenceNo = state.referenceNo;
  const remaining = state.pendingFiles.length;
  closeComplaintRetryMode(formEl);
  showToast('Complaint Filed', `${referenceNo} was filed without ${remaining} photo${remaining === 1 ? '' : 's'}.`);
}

// ── TOAST ─────────────────────────────────
let toastTimeout;
async function initSmsPreferences() {
  const form = document.getElementById('smsPrefForm');
  if (!form) return;
  try {
    const data = await fetchJson(`${API_BASE}/residents/sms_preferences.php`);
    form.complaints_enabled.checked = Number(data.complaints_enabled) === 1;
    form.requests_enabled.checked   = Number(data.requests_enabled)   === 1;
    form.announcements_enabled.checked = Number(data.announcements_enabled) === 1;
  } catch { /* non-fatal */ }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await fetchJson(`${API_BASE}/residents/sms_preferences.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          complaints_enabled: form.complaints_enabled.checked ? 1 : 0,
          requests_enabled: form.requests_enabled.checked ? 1 : 0,
          announcements_enabled: form.announcements_enabled.checked ? 1 : 0,
        }),
      });
      showToast('SMS Preferences', 'Your notification preferences were saved.');
    } catch (err) {
      showToast('Save Failed', err.message);
    }
  });
}

function initProfilePhotoUpload() {
  const form = document.getElementById('profilePhotoForm');
  const input = document.getElementById('profilePhotoInput');
  if (!form || !input) return;
  input.addEventListener('change', () => {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const modal = document.createElement('div');
    modal.className = 'photo-confirm-overlay';
    modal.innerHTML = `
      <div class="photo-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="photoConfirmTitle">
        <h3 id="photoConfirmTitle">Change your profile photo to this?</h3>
        <img class="photo-confirm-preview" alt="Selected profile photo preview" />
        <div class="photo-confirm-actions">
          <button type="button" class="btn-submit-form photo-confirm-btn">Confirm</button>
          <button type="button" class="btn-cancel-form photo-cancel-btn">Cancel</button>
        </div>
      </div>`;
    const preview = modal.querySelector('.photo-confirm-preview');
    const objectUrl = URL.createObjectURL(file);
    preview.src = objectUrl;
    document.body.appendChild(modal);
    const close = () => {
      URL.revokeObjectURL(objectUrl);
      input.value = '';
      modal.remove();
    };
    modal.querySelector('.photo-cancel-btn').addEventListener('click', close);
    modal.querySelector('.photo-confirm-btn').addEventListener('click', async () => {
      const body = new FormData();
      body.append('photo', file);
      try {
        const response = await fetch('../api/residents/upload_photo.php', { method: 'POST', body });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Photo upload failed.');
        close();
        showToast('Profile Photo Updated', 'Your profile photo was updated.');
        window.location.reload();
      } catch (error) {
        close();
        showToast('Upload Failed', error.message);
      }
    });
  });
}

function showToast(title, body) {
  let toast = document.getElementById('residentToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'residentToast';
    toast.style.cssText = `
      position:fixed; bottom:24px; right:24px; background:white;
      border-radius:12px; padding:16px 20px; box-shadow:0 8px 32px rgba(11,30,69,0.18);
      border:1px solid #C5CBD6; border-left:4px solid #059669; display:flex; align-items:flex-start; gap:12px;
      max-width:380px; z-index:9999; transform:translateY(120px); opacity:0;
      transition:all 0.4s cubic-bezier(0.34,1.56,0.64,1);
    `;
    document.body.appendChild(toast);
  }

  toast.innerHTML = `
    <i class="fa-solid fa-circle-check" style="color:#059669; font-size:20px; margin-top:2px; flex-shrink:0;"></i>
    <div><div style="font-size:14px;font-weight:700;color:#0B1E45;margin-bottom:3px">${title}</div><div style="font-size:13px;color:#475569">${body}</div></div>
  `;
  clearTimeout(toastTimeout);
  requestAnimationFrame(() => {
    toast.style.transform = 'translateY(0)';
    toast.style.opacity = '1';
  });
  toastTimeout = setTimeout(() => {
    toast.style.transform = 'translateY(120px)';
    toast.style.opacity = '0';
  }, 5000);
}

const POLL_INTERVAL_MS = 5000;
const POLL_BACKOFF_MAX_MS = 120000;

let pollTimer = null;
let pollBackoff = POLL_INTERVAL_MS;
let pollInflight = false;

async function pollResidentUpdates() {
  if (document.visibilityState !== 'visible') {
    scheduleNextPoll();
    return;
  }
  if (document.querySelector('.modal-overlay.show')) {
    scheduleNextPoll();
    return;
  }
  if (pollInflight) return;

  const activeSection = document.querySelector('.tab-panel.active')?.id || '';
  const fetches = [];
  if (activeSection === 'tab-requests' || activeSection === 'tab-dashboard') {
    fetches.push(fetchJson(`${API_BASE}/requests/list.php`)
      .then(d => { REQUESTS = (d.requests || []).map(mapRequestRow); }));
  }
  if (activeSection === 'tab-complaints' || activeSection === 'tab-dashboard') {
    fetches.push(fetchJson(`${API_BASE}/complaints/list.php`)
      .then(d => { COMPLAINTS = (d.complaints || []).map(mapComplaintRow); }));
  }
  if (activeSection === 'tab-notifications' || activeSection === 'tab-dashboard') {
    fetches.push(fetchJson(`${API_BASE}/notifications/list.php`)
      .then(d => { NOTIFICATIONS = (d.notifications || []).map(mapNotificationRow); }));
  }
  if (!fetches.length) { scheduleNextPoll(); return; }

  pollInflight = true;
  try {
    await Promise.all(fetches);
    await refreshResidentView();
    filterRequests();
    pollBackoff = POLL_INTERVAL_MS;
  } catch {
    pollBackoff = Math.min(pollBackoff * 2, POLL_BACKOFF_MAX_MS);
  } finally {
    pollInflight = false;
    scheduleNextPoll();
  }
}

function scheduleNextPoll() {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(pollResidentUpdates, pollBackoff);
}

function startResidentPolling() {
  if (pollTimer) return;
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollResidentUpdates();
  });
  pollResidentUpdates();
}

// ── INIT ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initNav();
  restoreActiveSection();
  initSidebarToggle();
  initLogoutConfirmation();
  initRequestsTable();
  initReqFormToggle();
  initComplaintFormToggle();
  initComplaintExtraFields();
  initComplaintPersonSection();
  initComplaintPhotoInput();
  initNotifDrawer();
  initMarkAllRead();
  initProfilePhotoUpload();
  initSmsPreferences();
  startResidentPolling();
  (async () => {
    try {
      await Promise.all([loadDocumentPrices(), loadRequestableTypes(), loadRequestTypeFields()]);
      initRequestPricing();
      await Promise.all([loadRequests(), loadComplaints(), loadNotifications()]);
      await refreshResidentView();
    } catch (err) {
      showToast('Data Load Failed', err.message);
    }
  })();
});

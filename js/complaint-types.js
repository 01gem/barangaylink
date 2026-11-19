/**
 * BarangayLink - Complaint Types tab (complaint revision Phase 4).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Officials (staff and admin) manage complaint types and their extra fields
 * through api/complaint_types/. The server enforces every rule; this screen
 * only disables what the server would refuse, and explains why.
 *
 * Uses these globals from js/official.js (classic script, loaded first):
 * escapeHtml, fetchJson, showToastAdmin, API_BASE. No second escape helper.
 *
 * No inline event handlers (CSP). Listeners are attached in initComplaintTypes,
 * which is a top-level function wrapped in try/catch: a failure here never
 * stops official.js from starting.
 */
(function () {
  'use strict';

  const STALE_MESSAGE = 'This item was changed by someone else. Reload and try again.';
  const RESPONDENT_LABELS = { none: 'None', optional: 'Optional', required: 'Required' };
  const FIELD_TYPE_LABELS = { select: 'Choice list', text: 'Text', number: 'Number', date: 'Date' };
  const TIP_OTHER_TYPE = 'The "Other" type must stay active: it is the fallback for every complaint.';
  const TIP_OTHER_SPECIFY = '"Please specify" is how an "Other" complaint describes itself, so it must stay active and required.';

  const state = {
    types: [],
    limits: { max_active_fields: 15, max_options: 50, max_text_length: 1000 },
    loaded: false,
    loading: null,
    typeId: null,      // type shown in the type modal; null = creating
    field: null,       // { typeId, fieldId (null = creating), options: rows }
    busy: false,
    opener: new Map()
  };

  // -------------------------------------------------------------------------
  // Pure helpers (no DOM). Exposed as window.ComplaintTypesUI for the Node test.
  // -------------------------------------------------------------------------

  /** Editor rows for a saved field's options. Saved rows can never be removed. */
  function optionRowsFromField(field) {
    return ((field && field.options) || []).map(option => ({
      value: String(option.value),
      label: String(option.label),
      is_active: option.is_active !== false,
      usage: Number(option.usage) || 0,
      saved: true,
      valueDirty: true
    }));
  }

  function addOptionRow(rows) {
    return rows.concat([{ value: '', label: '', is_active: true, usage: 0, saved: false, valueDirty: false }]);
  }

  function canRemoveOption(row) {
    return !!row && row.saved !== true;
  }

  /** Only unsaved rows can be removed; a saved row is returned unchanged. */
  function removeOptionRow(rows, index) {
    if (!canRemoveOption(rows[index])) return rows.slice();
    return rows.filter((_, i) => i !== index);
  }

  /** Move one row up (-1) or down (+1). Out-of-range moves change nothing. */
  function moveOptionRow(rows, index, delta) {
    const target = index + delta;
    const copy = rows.slice();
    if (index < 0 || index >= rows.length || target < 0 || target >= rows.length) return copy;
    [copy[index], copy[target]] = [copy[target], copy[index]];
    return copy;
  }

  /** Exactly what save_field.php accepts for options, in display order. */
  function optionsPayload(rows) {
    return rows.map(row => ({ value: String(row.value).trim(), label: String(row.label).trim(), is_active: row.is_active === true }));
  }

  /** Same rule as the server's type code: lowercase, symbols to "_". */
  function deriveKey(text) {
    let key = String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]+/g, '')
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (key && !/^[a-z]/.test(key)) key = `f_${key}`;
    return key.slice(0, 50).replace(/_+$/, '');
  }

  function optionValueFromLabel(label) {
    return String(label || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]+/g, '')
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 50);
  }

  /** '' -> undefined (key left out), a whole number -> number, anything else -> the raw text (server answers 422). */
  function intOrRaw(raw) {
    const text = String(raw ?? '').trim();
    if (text === '') return undefined;
    const number = Number(text);
    return Number.isInteger(number) ? number : text;
  }

  /** Phase 10: '50.00' -> '₱50.00'; 0 / missing -> 'No fee'. Display only (no arithmetic). */
  function feeLabel(fee) {
    const text = String(fee ?? '').trim();
    if (!/^\d+(\.\d{1,2})?$/.test(text) || /^0+(\.0+)?$/.test(text)) return 'No fee';
    return `₱${text}`;
  }

  /** '' -> null, a number -> number, anything else -> the raw text. */
  function numberOrNull(raw) {
    const text = String(raw ?? '').trim();
    if (text === '') return null;
    const number = Number(text);
    return Number.isFinite(number) ? number : text;
  }

  /**
   * save_type.php body. form: {type_name, type_code, description, respondent_mode, sort_order, default_fee?}.
   * default_fee (Phase 10) is sent as typed text ('' -> '0'); the server checks the money rule.
   * type: the loaded type when editing (id + version from the last load), null when creating.
   */
  function typePayload(form, type) {
    const body = {
      type_name: String(form.type_name || '').trim(),
      description: String(form.description || '').trim(),
      respondent_mode: form.respondent_mode
    };
    const sort = intOrRaw(form.sort_order);
    if (sort !== undefined) body.sort_order = sort;
    if (form.default_fee !== undefined) body.default_fee = String(form.default_fee ?? '').trim() || '0';
    if (type) {
      body.id = Number(type.id);
      body.version = Number(type.version);
    } else if (String(form.type_code || '').trim() !== '') {
      body.type_code = String(form.type_code).trim();
    }
    return body;
  }

  /**
   * save_field.php body. form: {label, field_key, field_type, is_required, max_length, min_value, max_value}.
   * field: the loaded field when editing, null when creating. Only settings of
   * the field's own kind are sent.
   */
  function fieldPayload(typeId, form, field, rows) {
    const kind = field ? field.field_type : form.field_type;
    const body = { type_id: Number(typeId), label: String(form.label || '').trim(), is_required: form.is_required === true };
    if (field) {
      body.id = Number(field.id);
      body.version = Number(field.version);
    } else {
      body.field_key = String(form.field_key || '').trim();
      body.field_type = kind;
    }
    if (kind === 'text') {
      const max = intOrRaw(form.max_length);
      if (max !== undefined) body.max_length = max;
    }
    if (kind === 'number') {
      body.min_value = numberOrNull(form.min_value);
      body.max_value = numberOrNull(form.max_value);
    }
    if (kind === 'select') body.options = optionsPayload(rows || []);
    return body;
  }

  function isOtherType(type) {
    return !!type && type.type_code === 'other';
  }

  function isOtherSpecify(type, field) {
    return isOtherType(type) && !!field && field.field_key === 'specify';
  }

  function isStale(error) {
    return !!error && error.status === 409 && error.message === STALE_MESSAGE;
  }

  window.ComplaintTypesUI = {
    optionRowsFromField, addOptionRow, canRemoveOption, removeOptionRow, moveOptionRow, optionsPayload,
    typePayload, fieldPayload, feeLabel, deriveKey, isOtherType, isOtherSpecify, isStale
  };

  // -------------------------------------------------------------------------
  // Data
  // -------------------------------------------------------------------------

  function $(id) {
    return document.getElementById(id);
  }

  function findType(typeId) {
    return state.types.find(type => Number(type.id) === Number(typeId)) || null;
  }

  function findField(type, fieldId) {
    return type ? (type.fields || []).find(field => Number(field.id) === Number(fieldId)) || null : null;
  }

  async function reload() {
    const body = $('ctypeTypesBody');
    if (body && !state.loaded) body.innerHTML = '<tr><td colspan="7" class="registry-empty">Loading complaint types…</td></tr>';
    const data = await fetchJson(`${API_BASE}/complaint_types/list.php`);
    state.types = Array.isArray(data.types) ? data.types : [];
    if (data.limits && typeof data.limits === 'object') state.limits = data.limits;
    state.loaded = true;
    renderTypes();
    if (isOpen('ctypeTypeModal') && state.typeId !== null) renderFieldList();
    return state.types;
  }

  /** First open of the tab loads the list; later opens reuse it (writes reload it). */
  function ensureLoaded() {
    if (state.loaded) return Promise.resolve(state.types);
    if (!state.loading) {
      state.loading = reload().finally(() => { state.loading = null; });
    }
    return state.loading;
  }

  async function post(endpoint, body) {
    return fetchJson(`${API_BASE}/complaint_types/${endpoint}.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
  }

  function toast(title, body) {
    // showToastAdmin writes HTML: escape both parts.
    showToastAdmin(escapeHtml(title), escapeHtml(body));
  }

  /** 409 for a stale version: tell the official, reload, and refill open windows from fresh data. */
  async function handleStale() {
    toast('Changed by someone else — reloaded.', 'Check the current values, then save again.');
    try {
      await reload();
    } catch (error) {
      toast('Reload Failed', error.message || 'The list could not be reloaded.');
      return;
    }
    if (isOpen('ctypeTypeModal') && state.typeId !== null) {
      const type = findType(state.typeId);
      if (type) fillTypeForm(type); else closeModal($('ctypeTypeModal'));
    }
    if (isOpen('ctypeFieldModal') && state.field && state.field.fieldId !== null) {
      const field = findField(findType(state.field.typeId), state.field.fieldId);
      if (field) fillFieldForm(findType(state.field.typeId), field); else closeModal($('ctypeFieldModal'));
    }
  }

  // -------------------------------------------------------------------------
  // Rendering
  // -------------------------------------------------------------------------

  function statusBadge(active) {
    return active
      ? '<span class="status-badge status-active">Active</span>'
      : '<span class="status-badge status-inactive">Inactive</span>';
  }

  function toggleButton(kind, id, active, disabledReason) {
    const label = active ? 'Deactivate' : 'Activate';
    const cls = active ? 'reject' : 'approve';
    const disabled = active && disabledReason;
    return `<button type="button" class="btn-action ${cls}" data-ct-action="toggle-${kind}" data-id="${escapeHtml(Number(id))}"`
      + `${disabled ? ` disabled title="${escapeHtml(disabledReason)}" aria-label="${escapeHtml(`${label} (not allowed): ${disabledReason}`)}"` : ''}>${escapeHtml(label)}</button>`;
  }

  function renderTypes() {
    const body = $('ctypeTypesBody');
    if (!body) return;
    if (!state.types.length) {
      body.innerHTML = '<tr><td colspan="8" class="registry-empty">No complaint types yet.</td></tr>';
      return;
    }
    body.innerHTML = state.types.map(type => {
      const activeFields = (type.fields || []).filter(field => field.is_active).length;
      return `<tr data-ct-type-row="${escapeHtml(Number(type.id))}">
        <td><strong>${escapeHtml(type.type_name)}</strong>${type.description ? `<div class="doctpl-note">${escapeHtml(type.description)}</div>` : ''}</td>
        <td><code>${escapeHtml(type.type_code)}</code></td>
        <td>${escapeHtml(RESPONDENT_LABELS[type.respondent_mode] || type.respondent_mode)}</td>
        <td>${escapeHtml(feeLabel(type.default_fee))}</td>
        <td>${escapeHtml(activeFields)}</td>
        <td>${escapeHtml(Number(type.usage) || 0)}</td>
        <td>${statusBadge(type.is_active)}</td>
        <td class="action-column"><div class="doctpl-actions">
          <button type="button" class="btn-action view" data-ct-action="edit-type" data-id="${escapeHtml(Number(type.id))}">Edit</button>
          ${toggleButton('type', type.id, type.is_active, isOtherType(type) ? TIP_OTHER_TYPE : '')}
        </div></td>
      </tr>`;
    }).join('');
  }

  function renderFieldList() {
    const type = findType(state.typeId);
    const body = $('ctypeFieldsBody');
    if (!type || !body) return;
    const fields = type.fields || [];
    const active = fields.filter(field => field.is_active).length;
    $('ctypeFieldsNote').textContent = `${active} of at most ${Number(state.limits.max_active_fields) || 15} fields active.`;
    if (!fields.length) {
      body.innerHTML = '<tr><td colspan="7" class="registry-empty">No extra fields. Residents only fill in the standard form.</td></tr>';
      return;
    }
    body.innerHTML = fields.map(field => {
      const usage = Number(field.usage) || 0;
      return `<tr>
        <td><strong>${escapeHtml(field.label)}</strong></td>
        <td><code>${escapeHtml(field.field_key)}</code></td>
        <td>${escapeHtml(FIELD_TYPE_LABELS[field.field_type] || field.field_type)}</td>
        <td>${field.is_required ? 'Yes' : 'No'}</td>
        <td>${escapeHtml(usage === 1 ? '1 complaint' : `${usage} complaints`)}</td>
        <td>${statusBadge(field.is_active)}</td>
        <td class="action-column"><div class="doctpl-actions">
          <button type="button" class="btn-action view" data-ct-action="edit-field" data-id="${escapeHtml(Number(field.id))}">Edit</button>
          ${toggleButton('field', field.id, field.is_active, isOtherSpecify(type, field) ? TIP_OTHER_SPECIFY : '')}
        </div></td>
      </tr>`;
    }).join('');
  }

  function renderOptions() {
    const list = $('ctfOptions');
    if (!list || !state.field) return;
    const rows = state.field.options;
    if (!rows.length) {
      list.innerHTML = '<li class="doctpl-note">No options yet. Add at least one.</li>';
      return;
    }
    list.innerHTML = rows.map((row, index) => {
      const n = index + 1;
      const value = row.saved
        ? `<code class="ctype-readonly" title="Saved values never change">${escapeHtml(row.value)}</code>`
        : `<input type="text" class="form-input" data-ct-option="value" data-index="${n - 1}" value="${escapeHtml(row.value)}" maxlength="50" spellcheck="false" aria-label="Value of option ${n}" />`;
      const tail = row.saved
        ? `<span class="ctype-usage">used in ${escapeHtml(row.usage)}</span>`
        : `<button type="button" class="btn-action reject" data-ct-action="remove-option" data-index="${n - 1}" aria-label="Remove new option ${n}">Remove</button>`;
      return `<li class="ctype-option-row${row.is_active ? '' : ' is-inactive'}">
        ${value}
        <input type="text" class="form-input" data-ct-option="label" data-index="${n - 1}" value="${escapeHtml(row.label)}" maxlength="100" aria-label="Label of option ${n}" />
        <label class="ctype-check"><input type="checkbox" data-ct-option="active" data-index="${n - 1}"${row.is_active ? ' checked' : ''} /> <span class="ctype-sr">Option ${n} </span>active</label>
        <span class="ctype-move">
          <button type="button" class="btn-action view" data-ct-action="move-option" data-index="${n - 1}" data-delta="-1"${index === 0 ? ' disabled' : ''} aria-label="Move option ${n} up">↑</button>
          <button type="button" class="btn-action view" data-ct-action="move-option" data-index="${n - 1}" data-delta="1"${index === rows.length - 1 ? ' disabled' : ''} aria-label="Move option ${n} down">↓</button>
        </span>
        ${tail}
      </li>`;
    }).join('');
  }

  function showSettingsFor(kind) {
    document.querySelectorAll('#ctypeFieldModal [data-ctf-setting]').forEach(node => {
      node.hidden = node.dataset.ctfSetting !== kind;
    });
    if (kind === 'select') renderOptions();
  }

  // -------------------------------------------------------------------------
  // Modals
  // -------------------------------------------------------------------------

  function isOpen(id) {
    const modal = $(id);
    return !!modal && modal.classList.contains('show');
  }

  function focusables(modal) {
    return [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')]
      .filter(node => !node.closest('[hidden]'));
  }

  function openModal(id, opener) {
    const modal = $(id);
    if (!modal) return;
    if (!modal.classList.contains('show')) state.opener.set(id, opener || document.activeElement);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    const first = modal.querySelector('form input:not([disabled]):not([type="radio"])');
    if (first) window.setTimeout(() => first.focus(), 0);
  }

  function closeModal(modal) {
    if (!modal || !modal.classList.contains('show')) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if (modal.id === 'ctypeFieldModal') state.field = null;
    if (modal.id === 'ctypeTypeModal') {
      state.typeId = null;
      closeModal($('ctypeFieldModal'));
    }
    const opener = state.opener.get(modal.id);
    state.opener.delete(modal.id);
    if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
  }

  function topModal() {
    if (isOpen('ctypeFieldModal')) return $('ctypeFieldModal');
    if (isOpen('ctypeTypeModal')) return $('ctypeTypeModal');
    return null;
  }

  function setError(id, message) {
    const node = $(id);
    if (!node) return;
    node.textContent = message || '';
    node.hidden = !message;
  }

  // ---- type modal

  function fillTypeForm(type) {
    const creating = !type;
    $('ctypeTypeTitle').textContent = creating ? 'New complaint type' : `Edit: ${type.type_name}`;
    $('ctypeName').value = creating ? '' : type.type_name;
    $('ctypeCode').value = '';
    $('ctypeCodeWrap').hidden = !creating;
    $('ctypeCodeReadWrap').hidden = creating;
    $('ctypeCodeRead').textContent = creating ? '' : type.type_code;
    $('ctypeDescription').value = creating ? '' : (type.description || '');
    const mode = creating ? 'none' : type.respondent_mode;
    document.querySelectorAll('input[name="ctypeRespondent"]').forEach(radio => { radio.checked = radio.value === mode; });
    $('ctypeSort').value = creating ? '' : String(type.sort_order);
    const fee = $('ctypeFee');
    if (fee) fee.value = creating ? '0.00' : String(type.default_fee || '0.00');
    $('ctypeFieldsSection').hidden = creating;
    setError('ctypeTypeError', '');
    if (!creating) renderFieldList();
  }

  function showType(typeId, opener) {
    const type = typeId === null ? null : findType(typeId);
    if (typeId !== null && !type) return;
    state.typeId = type ? Number(type.id) : null;
    fillTypeForm(type);
    openModal('ctypeTypeModal', opener);
  }

  function typeForm() {
    const mode = document.querySelector('input[name="ctypeRespondent"]:checked');
    return {
      type_name: $('ctypeName').value,
      type_code: $('ctypeCode').value,
      description: $('ctypeDescription').value,
      respondent_mode: mode ? mode.value : 'none',
      sort_order: $('ctypeSort').value,
      ...($('ctypeFee') ? { default_fee: $('ctypeFee').value } : {})
    };
  }

  async function submitType(event) {
    event.preventDefault();
    if (state.busy) return;
    const type = state.typeId === null ? null : findType(state.typeId);
    const body = typePayload(typeForm(), type);
    if (body.type_name.length < 2) {
      setError('ctypeTypeError', 'Enter a name of 2 to 100 characters.');
      return;
    }
    const button = $('ctypeTypeSubmit');
    state.busy = true;
    button.disabled = true;
    setError('ctypeTypeError', '');
    try {
      const data = await post('save_type', body);
      const saved = data.type || {};
      await reload();
      // A new type stays open in edit mode so fields can be added straight away.
      state.typeId = Number(saved.id);
      const fresh = findType(state.typeId);
      if (fresh) fillTypeForm(fresh);
      toast(type ? 'Complaint Type Saved' : 'Complaint Type Created',
        data.changed === false ? 'Nothing changed.' : `${saved.type_name || body.type_name} was saved.`);
    } catch (error) {
      if (isStale(error)) await handleStale();
      else setError('ctypeTypeError', error.message || 'The complaint type could not be saved.');
    } finally {
      state.busy = false;
      button.disabled = false;
    }
  }

  // ---- field modal

  function fillFieldForm(type, field) {
    const creating = !field;
    const kind = creating ? $('ctfType').value || 'select' : field.field_type;
    $('ctypeFieldTitle').textContent = creating ? `Add field to ${type.type_name}` : `Edit field: ${field.label}`;
    $('ctfLabel').value = creating ? '' : field.label;
    $('ctfKey').value = '';
    $('ctfKey').dataset.dirty = '';
    $('ctfKeyWrap').hidden = !creating;
    $('ctfKeyReadWrap').hidden = creating;
    $('ctfKeyRead').textContent = creating ? '' : field.field_key;
    $('ctfTypeWrap').hidden = !creating;
    $('ctfTypeReadWrap').hidden = creating;
    $('ctfTypeRead').textContent = creating ? '' : (FIELD_TYPE_LABELS[field.field_type] || field.field_type);
    if (creating) $('ctfType').value = 'select';
    const required = $('ctfRequired');
    required.checked = creating ? false : field.is_required === true;
    const locked = !creating && isOtherSpecify(type, field);
    required.disabled = locked;
    required.title = locked ? TIP_OTHER_SPECIFY : '';
    required.closest('label').title = locked ? TIP_OTHER_SPECIFY : '';
    $('ctfMaxLength').value = !creating && field.max_length !== null ? String(field.max_length) : '';
    $('ctfMaxLength').max = String(Number(state.limits.max_text_length) || 1000);
    $('ctfMin').value = !creating && field.min_value !== null ? String(field.min_value) : '';
    $('ctfMax').value = !creating && field.max_value !== null ? String(field.max_value) : '';
    state.field.options = creating ? addOptionRow([]) : optionRowsFromField(field);
    setError('ctypeFieldError', '');
    showSettingsFor(creating ? 'select' : kind);
  }

  function showField(fieldId, opener) {
    const type = findType(state.typeId);
    if (!type) return;
    const field = fieldId === null ? null : findField(type, fieldId);
    if (fieldId !== null && !field) return;
    state.field = { typeId: Number(type.id), fieldId: field ? Number(field.id) : null, options: [] };
    fillFieldForm(type, field);
    openModal('ctypeFieldModal', opener);
  }

  /** Copy what the official typed in the option rows back into state before re-rendering. */
  function readOptionInputs() {
    if (!state.field) return;
    document.querySelectorAll('#ctfOptions [data-ct-option]').forEach(input => {
      const row = state.field.options[Number(input.dataset.index)];
      if (!row) return;
      if (input.dataset.ctOption === 'value' && !row.saved) row.value = input.value;
      if (input.dataset.ctOption === 'label') row.label = input.value;
      if (input.dataset.ctOption === 'active') row.is_active = input.checked;
    });
  }

  async function submitField(event) {
    event.preventDefault();
    if (state.busy || !state.field) return;
    readOptionInputs();
    const type = findType(state.field.typeId);
    const field = state.field.fieldId === null ? null : findField(type, state.field.fieldId);
    const form = {
      label: $('ctfLabel').value,
      field_key: $('ctfKey').value,
      field_type: $('ctfType').value,
      is_required: $('ctfRequired').checked,
      max_length: $('ctfMaxLength').value,
      min_value: $('ctfMin').value,
      max_value: $('ctfMax').value
    };
    const body = fieldPayload(state.field.typeId, form, field, state.field.options);
    if (body.label === '') {
      setError('ctypeFieldError', 'Enter a label.');
      return;
    }
    const button = $('ctypeFieldSubmit');
    state.busy = true;
    button.disabled = true;
    setError('ctypeFieldError', '');
    try {
      const data = await post('save_field', body);
      closeModal($('ctypeFieldModal'));
      await reload();
      toast(field ? 'Field Saved' : 'Field Added', data.changed === false ? 'Nothing changed.' : `${body.label} was saved.`);
    } catch (error) {
      if (isStale(error)) await handleStale();
      else setError('ctypeFieldError', error.message || 'The field could not be saved.');
    } finally {
      state.busy = false;
      button.disabled = false;
    }
  }

  // ---- activate / deactivate

  async function toggleType(typeId) {
    const type = findType(typeId);
    if (!type || state.busy) return;
    const next = !type.is_active;
    const message = next
      ? `Activate "${type.type_name}"? Residents will be able to choose it for new complaints again.`
      : `Deactivate "${type.type_name}"? Existing complaints keep this type. New complaints cannot choose it.`;
    if (!window.confirm(message)) return;
    await runToggle('toggle_type', { id: Number(type.id), version: Number(type.version), is_active: next },
      next ? 'Complaint Type Activated' : 'Complaint Type Deactivated', type.type_name);
  }

  async function toggleField(fieldId) {
    const type = findType(state.typeId);
    const field = findField(type, fieldId);
    if (!field || state.busy) return;
    const next = !field.is_active;
    const message = next
      ? `Activate the field "${field.label}"? It will appear on the filing form again.`
      : `Deactivate the field "${field.label}"? Existing complaints keep their answer. New complaints will not ask for it.`;
    if (!window.confirm(message)) return;
    await runToggle('toggle_field', { id: Number(field.id), version: Number(field.version), is_active: next },
      next ? 'Field Activated' : 'Field Deactivated', field.label);
  }

  async function runToggle(endpoint, body, title, name) {
    state.busy = true;
    try {
      await post(endpoint, body);
      await reload();
      if (isOpen('ctypeTypeModal') && state.typeId !== null) {
        const fresh = findType(state.typeId);
        if (fresh) $('ctypeTypeTitle').textContent = `Edit: ${fresh.type_name}`;
      }
      toast(title, `${name} was updated.`);
    } catch (error) {
      if (isStale(error)) {
        await handleStale();
      } else {
        const inline = endpoint === 'toggle_field' && isOpen('ctypeTypeModal') ? 'ctypeTypeError' : '';
        if (inline) setError(inline, error.message);
        else toast('Not Changed', error.message || 'The change could not be saved.');
      }
    } finally {
      state.busy = false;
    }
  }

  // -------------------------------------------------------------------------
  // Events
  // -------------------------------------------------------------------------

  function handleClick(event) {
    const button = event.target.closest('[data-ct-action]');
    if (!button) {
      const overlay = event.target.closest('.ctype-modal');
      if (overlay && event.target === overlay) closeModal(overlay);
      return;
    }
    if (!button.closest('#tab-complainttypes, .ctype-modal') || button.disabled) return;
    event.preventDefault();
    const action = button.dataset.ctAction;
    const id = Number(button.dataset.id);
    if (action === 'close') closeModal(button.closest('.ctype-modal'));
    if (action === 'new-type') ensureLoaded().then(() => showType(null, button)).catch(error => toast('Load Failed', error.message));
    if (action === 'edit-type') showType(id, button);
    if (action === 'toggle-type') toggleType(id);
    if (action === 'new-field') showField(null, button);
    if (action === 'edit-field') showField(id, button);
    if (action === 'toggle-field') toggleField(id);
    if (!state.field) return;
    if (action === 'add-option') {
      readOptionInputs();
      if (state.field.options.length >= (Number(state.limits.max_options) || 50)) {
        setError('ctypeFieldError', `A field can have at most ${Number(state.limits.max_options) || 50} options.`);
        return;
      }
      state.field.options = addOptionRow(state.field.options);
      renderOptions();
      const inputs = document.querySelectorAll('#ctfOptions [data-ct-option="label"]');
      if (inputs.length) inputs[inputs.length - 1].focus();
    }
    if (action === 'remove-option') {
      readOptionInputs();
      state.field.options = removeOptionRow(state.field.options, Number(button.dataset.index));
      renderOptions();
    }
    if (action === 'move-option') {
      readOptionInputs();
      const index = Number(button.dataset.index);
      const delta = Number(button.dataset.delta);
      state.field.options = moveOptionRow(state.field.options, index, delta);
      renderOptions();
      const moved = document.querySelector(`#ctfOptions [data-ct-action="move-option"][data-index="${index + delta}"][data-delta="${delta}"]:not([disabled])`)
        || document.querySelector(`#ctfOptions [data-ct-option="label"][data-index="${index + delta}"]`);
      if (moved) moved.focus();
    }
  }

  function handleKeydown(event) {
    const modal = topModal();
    if (!modal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(modal);
      return;
    }
    if (event.key !== 'Tab') return;
    const nodes = focusables(modal);
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function handleOptionInput(event) {
    const input = event.target;
    if (!state.field || !input.dataset || input.dataset.ctOption === undefined) return;
    const row = state.field.options[Number(input.dataset.index)];
    if (!row) return;
    if (input.dataset.ctOption === 'value') {
      row.value = input.value;
      row.valueDirty = input.value.trim() !== '';
    }
    if (input.dataset.ctOption === 'label') {
      row.label = input.value;
      // Suggest a value for a new option until the official types one.
      if (!row.saved && !row.valueDirty) {
        row.value = optionValueFromLabel(input.value);
        const valueInput = document.querySelector(`#ctfOptions [data-ct-option="value"][data-index="${Number(input.dataset.index)}"]`);
        if (valueInput) valueInput.value = row.value;
      }
    }
    if (input.dataset.ctOption === 'active') {
      row.is_active = input.checked;
      input.closest('.ctype-option-row')?.classList.toggle('is-inactive', !input.checked);
    }
  }

  function initComplaintTypes() {
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', handleKeydown);
    $('ctypeTypeForm')?.addEventListener('submit', submitType);
    $('ctypeFieldForm')?.addEventListener('submit', submitField);
    $('ctfOptions')?.addEventListener('input', handleOptionInput);
    $('ctfOptions')?.addEventListener('change', handleOptionInput);
    $('ctfType')?.addEventListener('change', event => {
      if (state.field && state.field.options.length === 0) state.field.options = addOptionRow([]);
      showSettingsFor(event.target.value);
    });
    $('ctfKey')?.addEventListener('input', event => { event.target.dataset.dirty = event.target.value.trim() !== '' ? '1' : ''; });
    $('ctfLabel')?.addEventListener('input', event => {
      const key = $('ctfKey');
      if (state.field && state.field.fieldId === null && key && !key.dataset.dirty) key.value = deriveKey(event.target.value);
    });
  }

  /* Top-level and guarded: a failure here must never stop js/official.js. */
  function initComplaintTypesSafely() {
    try {
      initComplaintTypes();
    } catch (error) {
      if (window.console) window.console.error('Complaint Types tab failed to start:', error);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initComplaintTypesSafely);
  else initComplaintTypesSafely();

  window.ComplaintTypes = { load: ensureLoaded, reload };
})();

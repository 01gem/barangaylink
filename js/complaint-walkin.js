/**
 * BarangayLink - Walk-in complaints and complaint parties (complaint revision Phase 5).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Two windows in the official console:
 *  - "New walk-in complaint": an official files a complaint at the barangay hall
 *    for registered or unregistered complainants (api/complaints/create_official.php).
 *  - "Parties": add, edit, link and remove the complainants and respondents of
 *    any complaint (api/complaints/party_save.php, party_link.php, party_remove.php).
 * The server enforces every rule; this file only builds requests and shows answers.
 *
 * Uses these globals from js/official.js (classic script, loaded first):
 * escapeHtml, fetchJson, showToastAdmin, API_BASE, loadComplaints,
 * syncOpenComplaintParties. No second escape helper.
 *
 * No inline event handlers (CSP). Listeners are attached in initComplaintWalkin,
 * called from the top-level initComplaintWalkinSafely: a failure here never
 * stops official.js.
 */
(function () {
  'use strict';

  const STALE_MESSAGE = 'This item was changed by someone else. Reload and try again.';
  const SEARCH_DELAY_MS = 300;
  const LIMITS = { complainant: 5, respondent: 10 };
  const ROLE_LABELS = { complainant: 'Complainant', respondent: 'Respondent' };
  const RESPONDENT_NOTES = {
    optional: 'Optional. Add the person this complaint is about, if known.',
    required: 'Required. Add at least one person this complaint is about.'
  };

  let rowSeq = 0;

  // -------------------------------------------------------------------------
  // Pure helpers (no DOM). Exposed as window.ComplaintWalkinUI for the Node test.
  // -------------------------------------------------------------------------

  /** One editable party row. mode: 'registered' (search and pick) or 'unregistered' (typed). */
  function newPartyRow(role, mode) {
    rowSeq += 1;
    return {
      key: rowSeq,
      role: role === 'respondent' ? 'respondent' : 'complainant',
      mode: mode === 'registered' ? 'registered' : 'unregistered',
      resident: null,
      full_name: '',
      address: '',
      contact_no: '',
      query: '',
      results: [],
      searchSeq: 0,
      timer: null
    };
  }

  function canAddParty(rows, role) {
    return rows.length < (LIMITS[role] || 0);
  }

  /** The request object for one row, or null when it is not complete. */
  function partyPayload(row) {
    if (!row) return null;
    if (row.mode === 'registered') {
      const id = row.resident ? Number(row.resident.id) : 0;
      return Number.isInteger(id) && id > 0 ? { resident_id: id } : null;
    }
    const name = String(row.full_name || '').trim();
    if (name === '') return null;
    const address = String(row.address || '').trim();
    const contact = String(row.contact_no || '').trim();
    return { full_name: name, address: address === '' ? null : address, contact_no: contact === '' ? null : contact };
  }

  function partyRowProblem(row) {
    if (partyPayload(row) !== null) return '';
    return row && row.mode === 'registered' ? 'Pick a registered resident for every row, or switch it to "Not registered".'
      : 'Enter the full name for every person.';
  }

  /**
   * The create_official.php body. Returns {payload} or {problem}.
   * form: {typeId, location, description, severity, extraFields, respondentMode, complainants, respondents}
   */
  function buildWalkinPayload(form) {
    const typeId = Number(form.typeId);
    if (!Number.isInteger(typeId) || typeId <= 0) return { problem: 'Select the type of concern.' };
    if (!String(form.location || '').trim() || !String(form.description || '').trim()) {
      return { problem: 'Enter the location and the description.' };
    }
    const complainants = form.complainants || [];
    if (complainants.length === 0) return { problem: 'Add at least one complainant.' };
    const mode = form.respondentMode === 'optional' || form.respondentMode === 'required' ? form.respondentMode : 'none';
    const respondents = mode === 'none' ? [] : (form.respondents || []);
    if (mode === 'required' && respondents.length === 0) return { problem: 'Add at least one person this complaint is about.' };
    for (const row of complainants.concat(respondents)) {
      const problem = partyRowProblem(row);
      if (problem) return { problem };
    }
    return {
      payload: {
        complaint_type_id: typeId,
        location_text: String(form.location).trim(),
        description: String(form.description).trim(),
        severity: form.severity || 'medium',
        extra_fields: Object.assign({}, form.extraFields || {}),
        complainants: complainants.map(partyPayload),
        respondents: respondents.map(partyPayload)
      }
    };
  }

  /** party_save.php body to add one party to an existing complaint. */
  function buildAddPartyPayload(complaintId, row) {
    const party = partyPayload(row);
    if (party === null) return { problem: partyRowProblem(row) };
    return { payload: Object.assign({ complaint_id: Number(complaintId), role: row.role }, party) };
  }

  /** party_save.php body to update a party that is not registered. Never sends resident_id. */
  function buildUpdatePartyPayload(complaintId, party, values) {
    const name = String(values.full_name || '').trim();
    if (name === '') return { problem: 'Enter the full name.' };
    const address = String(values.address || '').trim();
    const contact = String(values.contact_no || '').trim();
    return {
      payload: {
        complaint_id: Number(complaintId), id: Number(party.id), version: Number(party.version),
        full_name: name, address: address === '' ? null : address, contact_no: contact === '' ? null : contact
      }
    };
  }

  /** Only the stale-version 409 means "changed by someone else". */
  function isStale(error) {
    return !!error && error.status === 409 && error.message === STALE_MESSAGE;
  }

  // -------------------------------------------------------------------------
  // DOM helpers
  // -------------------------------------------------------------------------

  function $(id) { return document.getElementById(id); }

  const walkin = { types: [], type: null, complainants: [], respondents: [], busy: false, typeSeq: 0 };
  const parties = { complaintId: 0, data: null, editing: 0, linking: null, addRow: null, busy: false, changed: false, loadSeq: 0 };
  const openers = new Map();

  function isOpen(id) {
    const modal = $(id);
    return !!modal && modal.classList.contains('show');
  }

  function topModal() {
    if (isOpen('partiesModal')) return $('partiesModal');
    if (isOpen('walkinModal')) return $('walkinModal');
    return null;
  }

  function focusables(modal) {
    return [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary')]
      .filter(node => !node.closest('[hidden]'));
  }

  function openModal(id, opener) {
    const modal = $(id);
    if (!modal) return;
    if (!modal.classList.contains('show')) openers.set(id, opener || document.activeElement);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal(modal) {
    if (!modal || !modal.classList.contains('show')) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if (modal.id === 'partiesModal') closedParties();
    if (modal.id === 'walkinModal') clearTimers(walkin.complainants.concat(walkin.respondents));
    const opener = openers.get(modal.id);
    openers.delete(modal.id);
    if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
  }

  function setError(id, message) {
    const node = $(id);
    if (!node) return;
    node.textContent = message || '';
    node.hidden = !message;
  }

  function toast(title, body) {
    showToastAdmin(escapeHtml(title), escapeHtml(body));
  }

  function clearTimers(rows) {
    rows.forEach(row => { if (row && row.timer) window.clearTimeout(row.timer); });
  }

  // -------------------------------------------------------------------------
  // Party editor row (walk-in modal and "Add a party")
  // -------------------------------------------------------------------------

  function partyEditorHtml(row, removable) {
    const k = row.key;
    const registered = row.mode === 'registered';
    const name = `wkMode${k}`;
    let body;
    if (registered) {
      body = row.resident
        ? `<p class="walkin-picked">Selected: <strong>${escapeHtml(row.resident.full_name)}</strong>${row.resident.purok_zone ? ' · ' + escapeHtml(row.resident.purok_zone) : ''}
             <button type="button" class="btn-cancel-form" data-wk-action="unpick" data-key="${k}">Change</button></p>`
        : `<label class="doctpl-field"><span>Search registered residents</span>
             <input type="search" data-wk-key="${k}" data-wk-field="query" value="${escapeHtml(row.query)}" maxlength="50" autocomplete="off" placeholder="Type at least 2 letters of the name" /></label>
           <ul class="walkin-results" data-wk-results="${k}" aria-live="polite">${resultsHtml(row)}</ul>`;
    } else {
      body = `<div class="walkin-typed">
          <label class="doctpl-field"><span>Full name</span><input type="text" data-wk-key="${k}" data-wk-field="full_name" value="${escapeHtml(row.full_name)}" maxlength="150" autocomplete="off" /></label>
          <label class="doctpl-field"><span>Address (optional)</span><input type="text" data-wk-key="${k}" data-wk-field="address" value="${escapeHtml(row.address)}" maxlength="255" autocomplete="off" /></label>
          <label class="doctpl-field"><span>Contact number (optional)</span><input type="tel" data-wk-key="${k}" data-wk-field="contact_no" value="${escapeHtml(row.contact_no)}" maxlength="20" inputmode="tel" autocomplete="off" /></label>
        </div>`;
    }
    return `<div class="walkin-party-row" data-wk-row="${k}">
      <div class="walkin-party-mode" role="radiogroup" aria-label="Registration">
        <label><input type="radio" name="${name}" value="registered" data-wk-key="${k}" data-wk-field="mode"${registered ? ' checked' : ''} /> Registered resident</label>
        <label><input type="radio" name="${name}" value="unregistered" data-wk-key="${k}" data-wk-field="mode"${registered ? '' : ' checked'} /> Not registered</label>
        ${removable ? `<button type="button" class="btn-cancel-form walkin-remove" data-wk-action="remove-party" data-key="${k}">Remove</button>` : ''}
      </div>
      ${body}
    </div>`;
  }

  function resultsHtml(row) {
    if (row.query.trim().length < 2) return '';
    if (row.searching) return '<li class="doctpl-note">Searching…</li>';
    if (row.searchError) return `<li class="doctpl-note">${escapeHtml(row.searchError)}</li>`;
    if (!row.results.length) return '<li class="doctpl-note">No active resident found.</li>';
    return row.results.map(resident => `<li><button type="button" class="walkin-result" data-wk-action="pick" data-key="${row.key}" data-resident-id="${Number(resident.id)}">
        <strong>${escapeHtml(resident.full_name)}</strong>${resident.purok_zone ? ' · ' + escapeHtml(resident.purok_zone) : ''}${resident.address ? ' · ' + escapeHtml(resident.address) : ''}
      </button></li>`).join('');
  }

  function allRows() {
    const rows = walkin.complainants.concat(walkin.respondents);
    if (parties.addRow) rows.push(parties.addRow);
    return rows;
  }

  function findRow(key) {
    return allRows().find(row => row.key === Number(key)) || null;
  }

  function paintResults(row) {
    const list = document.querySelector(`[data-wk-results="${row.key}"]`);
    if (list) list.innerHTML = resultsHtml(row);
  }

  function scheduleSearch(row) {
    if (row.timer) window.clearTimeout(row.timer);
    row.results = [];
    row.searchError = '';
    const q = row.query.trim();
    if (q.length < 2) { row.searching = false; paintResults(row); return; }
    row.searching = true;
    paintResults(row);
    row.timer = window.setTimeout(async () => {
      const seq = ++row.searchSeq;
      try {
        const data = await fetchJson(`${API_BASE}/complaints/resident_search.php?q=${encodeURIComponent(q)}`);
        if (seq !== row.searchSeq) return;
        row.results = Array.isArray(data.residents) ? data.residents : [];
      } catch (error) {
        if (seq !== row.searchSeq) return;
        row.searchError = error.message || 'Search failed.';
      }
      row.searching = false;
      paintResults(row);
    }, SEARCH_DELAY_MS);
  }

  function handleEditorInput(event) {
    const input = event.target;
    if (!(input instanceof Element) || !input.dataset || input.dataset.wkKey === undefined) return;
    const row = findRow(input.dataset.wkKey);
    if (!row) return;
    const field = input.dataset.wkField;
    if (field === 'mode') {
      if (event.type !== 'change') return;
      clearTimers([row]);
      row.mode = input.value === 'registered' ? 'registered' : 'unregistered';
      row.resident = null;
      row.query = '';
      row.results = [];
      repaintRow(row);
      return;
    }
    if (field === 'query') {
      row.query = input.value;
      scheduleSearch(row);
      return;
    }
    if (field === 'full_name' || field === 'address' || field === 'contact_no') row[field] = input.value;
  }

  /** Redraw the editor that holds this row. */
  function repaintRow(row) {
    if (parties.addRow && row.key === parties.addRow.key) paintAddRow();
    else paintWalkinParties();
  }

  // -------------------------------------------------------------------------
  // Walk-in modal
  // -------------------------------------------------------------------------

  function selectedType() {
    const id = Number($('walkinType')?.value || 0);
    return walkin.types.find(type => type.id === id) || null;
  }

  function respondentMode() {
    const type = selectedType();
    return type && (type.respondent_mode === 'optional' || type.respondent_mode === 'required') ? type.respondent_mode : 'none';
  }

  function paintWalkinParties() {
    const complainants = $('walkinComplainants');
    const respondents = $('walkinRespondents');
    if (complainants) complainants.innerHTML = walkin.complainants.map(row => partyEditorHtml(row, walkin.complainants.length > 1)).join('');
    const mode = respondentMode();
    if (respondents) respondents.innerHTML = walkin.respondents.map(row => partyEditorHtml(row, !(mode === 'required' && walkin.respondents.length === 1))).join('');
    const section = $('walkinRespondentsSection');
    if (section) section.hidden = mode === 'none';
    const note = $('walkinRespondentNote');
    if (note) note.textContent = RESPONDENT_NOTES[mode] || '';
    document.querySelectorAll('#walkinModal [data-wk-action="add-party"]').forEach(button => {
      const role = button.dataset.role;
      button.disabled = !canAddParty(role === 'respondent' ? walkin.respondents : walkin.complainants, role);
    });
  }

  function extraFieldHtml(field, index) {
    const id = `walkinExtra${index}`;
    const required = field.required ? ' <span class="doctpl-note">(required)</span>' : ' <span class="doctpl-note">(optional)</span>';
    let control;
    if (field.type === 'select') {
      control = `<select id="${id}" class="form-input" data-wk-extra="${escapeHtml(field.key)}">
        <option value="">${field.required ? 'Select an option' : 'Not specified'}</option>
        ${(field.options || []).map(option => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`).join('')}
      </select>`;
    } else if (field.type === 'number') {
      control = `<input id="${id}" type="number" step="any" data-wk-extra="${escapeHtml(field.key)}"${field.min !== null && field.min !== undefined ? ` min="${Number(field.min)}"` : ''}${field.max !== null && field.max !== undefined ? ` max="${Number(field.max)}"` : ''} />`;
    } else if (field.type === 'date') {
      control = `<input id="${id}" type="date" data-wk-extra="${escapeHtml(field.key)}" />`;
    } else {
      control = `<input id="${id}" type="text" data-wk-extra="${escapeHtml(field.key)}" maxlength="${Number(field.max_length) || 255}" autocomplete="off" />`;
    }
    return `<label class="doctpl-field" for="${id}"><span>${escapeHtml(field.label)}${required}</span>${control}</label>`;
  }

  async function loadTypeFields() {
    const box = $('walkinExtra');
    const type = selectedType();
    const seq = ++walkin.typeSeq;
    walkin.type = null;
    if (box) { box.innerHTML = ''; box.hidden = true; }
    paintWalkinParties();
    if (!type) return;
    try {
      const data = await fetchJson(`${API_BASE}/complaint_types/fields.php?type_id=${encodeURIComponent(type.id)}`);
      if (seq !== walkin.typeSeq) return;
      walkin.type = data.type || null;
      const fields = walkin.type && Array.isArray(walkin.type.fields) ? walkin.type.fields : [];
      if (box && fields.length) {
        box.innerHTML = `<p class="walkin-subhead">Additional details</p><div class="doctpl-type-grid">${fields.map(extraFieldHtml).join('')}</div>`;
        box.hidden = false;
      }
    } catch (error) {
      if (seq !== walkin.typeSeq) return;
      setError('walkinError', error.message || 'Could not load the details for this type.');
    }
  }

  function collectExtraFields() {
    const values = {};
    document.querySelectorAll('#walkinExtra [data-wk-extra]').forEach(control => {
      const value = String(control.value ?? '').trim();
      if (value !== '') values[control.dataset.wkExtra] = value;
    });
    return values;
  }

  async function openWalkin(opener) {
    clearTimers(walkin.complainants.concat(walkin.respondents));
    walkin.complainants = [newPartyRow('complainant', 'unregistered')];
    walkin.respondents = [];
    walkin.busy = false;
    $('walkinForm')?.reset();
    setError('walkinError', '');
    const select = $('walkinType');
    if (select) select.innerHTML = '<option value="">Loading types…</option>';
    paintWalkinParties();
    openModal('walkinModal', opener);
    try {
      const data = await fetchJson(`${API_BASE}/complaint_types/list.php`);
      walkin.types = (Array.isArray(data.types) ? data.types : []).filter(type => type.is_active);
      if (select) {
        select.innerHTML = '<option value="">Select the type of concern</option>'
          + walkin.types.map(type => `<option value="${Number(type.id)}">${escapeHtml(type.type_name)}</option>`).join('');
        select.focus();
      }
    } catch (error) {
      setError('walkinError', error.message || 'Could not load the complaint types.');
    }
    await loadTypeFields();
  }

  function onTypeChange() {
    setError('walkinError', '');
    const mode = respondentMode();
    if (mode === 'none') { clearTimers(walkin.respondents); walkin.respondents = []; }
    if (mode === 'required' && walkin.respondents.length === 0) walkin.respondents = [newPartyRow('respondent', 'unregistered')];
    loadTypeFields();
  }

  async function submitWalkin(event) {
    event.preventDefault();
    if (walkin.busy) return;
    const built = buildWalkinPayload({
      typeId: $('walkinType')?.value,
      location: $('walkinLocation')?.value,
      description: $('walkinDescription')?.value,
      severity: $('walkinSeverity')?.value,
      extraFields: collectExtraFields(),
      respondentMode: respondentMode(),
      complainants: walkin.complainants,
      respondents: walkin.respondents
    });
    if (built.problem) { setError('walkinError', built.problem); return; }
    setError('walkinError', '');
    walkin.busy = true;
    const submit = $('walkinSubmit');
    if (submit) submit.disabled = true;
    try {
      const data = await fetchJson(`${API_BASE}/complaints/create_official.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(built.payload)
      });
      closeModal($('walkinModal'));
      toast('Walk-in complaint filed', `Reference ${data.reference_no || ''}.`);
      try { await loadComplaints(); } catch (error) { /* the list refreshes on the next poll */ }
    } catch (error) {
      setError('walkinError', error.message || 'The complaint could not be filed.');
    } finally {
      walkin.busy = false;
      if (submit) submit.disabled = false;
    }
  }

  // -------------------------------------------------------------------------
  // Parties modal
  // -------------------------------------------------------------------------

  function activeParties() {
    return parties.data ? parties.data.parties.filter(party => party.is_active) : [];
  }

  function isResolved() {
    return !!parties.data && parties.data.complaint.status === 'resolved';
  }

  function partyRowHtml(party) {
    const resolved = isResolved();
    const off = resolved || parties.busy ? ' disabled' : '';
    if (parties.editing === party.id) {
      return `<tr data-party-id="${party.id}" class="is-editing">
        <td>${escapeHtml(ROLE_LABELS[party.role] || '')}</td>
        <td><input type="text" class="form-input" data-wk-edit="full_name" value="${escapeHtml(party.full_name)}" maxlength="150" aria-label="Full name" /></td>
        <td><span class="party-badge">Not registered</span></td>
        <td><input type="text" class="form-input" data-wk-edit="address" value="${escapeHtml(party.address || '')}" maxlength="255" aria-label="Address" /></td>
        <td><input type="tel" class="form-input" data-wk-edit="contact_no" value="${escapeHtml(party.contact_no || '')}" maxlength="20" inputmode="tel" aria-label="Contact number" /></td>
        <td class="action-column"><button type="button" class="btn-submit-form" data-wk-action="party-save" data-party-id="${party.id}"${off}>Save</button>
          <button type="button" class="btn-cancel-form" data-wk-action="party-cancel">Cancel</button></td>
      </tr>`;
    }
    const actions = [];
    if (!party.is_registered) {
      actions.push(`<button type="button" class="btn-cancel-form" data-wk-action="party-edit" data-party-id="${party.id}"${off}>Edit</button>`);
      actions.push(`<button type="button" class="btn-cancel-form" data-wk-action="party-link" data-party-id="${party.id}"${off}>Link</button>`);
    }
    actions.push(`<button type="button" class="btn-cancel-form" data-wk-action="party-remove" data-party-id="${party.id}"${off}>Remove</button>`);
    let html = `<tr data-party-id="${party.id}">
      <td>${escapeHtml(ROLE_LABELS[party.role] || '')}</td>
      <td>${escapeHtml(party.full_name)}</td>
      <td><span class="party-badge${party.is_registered ? ' is-registered' : ''}">${party.is_registered ? 'Registered' : 'Not registered'}</span></td>
      <td>${escapeHtml(party.address || '—')}</td>
      <td>${escapeHtml(party.contact_no || '—')}</td>
      <td class="action-column">${actions.join(' ')}</td>
    </tr>`;
    if (parties.linking && parties.linking.partyId === party.id) html += linkRowHtml(party);
    return html;
  }

  function linkRowHtml(party) {
    const link = parties.linking;
    let inner;
    if (link.picked) {
      inner = `<p>Link <strong>${escapeHtml(party.full_name)}</strong> to <strong>${escapeHtml(link.picked.full_name)}</strong>? Address and contact will be read from the resident's profile.</p>
        <button type="button" class="btn-submit-form" data-wk-action="link-confirm"${parties.busy ? ' disabled' : ''}>Link</button>
        <button type="button" class="btn-cancel-form" data-wk-action="link-cancel">Cancel</button>`;
    } else {
      inner = `<label class="doctpl-field"><span>Search registered residents</span>
          <input type="search" data-wk-link="query" value="${escapeHtml(link.row.query)}" maxlength="50" autocomplete="off" placeholder="Type at least 2 letters of the name" /></label>
        <ul class="walkin-results" data-wk-results="${link.row.key}" aria-live="polite">${resultsHtml(link.row)}</ul>
        <button type="button" class="btn-cancel-form" data-wk-action="link-cancel">Cancel</button>`;
    }
    return `<tr class="walkin-link-row"><td colspan="6">${inner}</td></tr>`;
  }

  function paintAddRow() {
    const holder = $('partiesAddRow');
    if (holder && parties.addRow) holder.innerHTML = partyEditorHtml(parties.addRow, false);
  }

  function paintParties() {
    const body = $('partiesBody');
    if (!body) return;
    if (!parties.data) { body.innerHTML = '<tr><td colspan="6" class="registry-empty">Loading…</td></tr>'; return; }
    const active = activeParties();
    body.innerHTML = active.length ? active.map(partyRowHtml).join('') : '<tr><td colspan="6" class="registry-empty">No parties recorded.</td></tr>';
    const removed = parties.data.parties.filter(party => !party.is_active);
    const wrap = $('partiesRemovedWrap');
    if (wrap) wrap.hidden = removed.length === 0;
    const summary = $('partiesRemovedSummary');
    if (summary) summary.textContent = `Removed (${removed.length})`;
    const list = $('partiesRemoved');
    if (list) list.innerHTML = removed.map(party => `<li>${escapeHtml(ROLE_LABELS[party.role] || '')}: ${escapeHtml(party.full_name)}</li>`).join('');
    const title = $('partiesTitle');
    if (title) title.textContent = `Parties — ${parties.data.complaint.reference_no}`;
    const resolved = isResolved();
    const notice = $('partiesResolved');
    if (notice) notice.hidden = !resolved;
    const add = $('partiesAddSection');
    if (add) add.hidden = resolved;
    const role = $('partiesAddRole');
    const respondentOption = role ? role.querySelector('option[value="respondent"]') : null;
    if (respondentOption) {
      respondentOption.disabled = parties.data.complaint.respondent_mode === 'none';
      if (respondentOption.disabled && role.value === 'respondent') role.value = 'complainant';
    }
    if (role && parties.addRow) parties.addRow.role = role.value === 'respondent' ? 'respondent' : 'complainant';
  }

  async function loadParties() {
    const seq = ++parties.loadSeq;
    try {
      const data = await fetchJson(`${API_BASE}/complaints/parties_list.php?complaint_id=${encodeURIComponent(parties.complaintId)}`);
      if (seq !== parties.loadSeq) return;
      parties.data = { complaint: data.complaint, limits: data.limits, parties: Array.isArray(data.parties) ? data.parties : [] };
      paintParties();
    } catch (error) {
      if (seq !== parties.loadSeq) return;
      setError('partiesError', error.message || 'Could not load the parties.');
    }
  }

  function openParties(complaintId, opener) {
    const id = Number(complaintId);
    if (!Number.isInteger(id) || id <= 0) return;
    parties.complaintId = id;
    parties.data = null;
    parties.editing = 0;
    parties.linking = null;
    parties.busy = false;
    parties.changed = false;
    parties.addRow = newPartyRow('complainant', 'unregistered');
    const role = $('partiesAddRole');
    if (role) role.value = 'complainant';
    setError('partiesError', '');
    paintParties();
    paintAddRow();
    openModal('partiesModal', opener);
    window.setTimeout(() => $('partiesModal')?.querySelector('.close-card-btn')?.focus(), 0);
    loadParties();
  }

  /** After the modal closes, refresh the complaint list and the open detail once. */
  async function closedParties() {
    const id = parties.complaintId;
    const changed = parties.changed;
    if (parties.addRow) clearTimers([parties.addRow]);
    if (parties.linking) clearTimers([parties.linking.row]);
    parties.linking = null;
    parties.editing = 0;
    if (!changed) return;
    try {
      await loadComplaints();
      syncOpenComplaintParties(id);
    } catch (error) { /* the list refreshes on the next poll */ }
  }

  /** One write. 409 stale: toast and reload. Other errors: inline. */
  async function partyWrite(endpoint, body, doneMessage) {
    if (parties.busy) return false;
    parties.busy = true;
    setError('partiesError', '');
    try {
      await fetchJson(`${API_BASE}/complaints/${endpoint}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      parties.changed = true;
      parties.editing = 0;
      parties.linking = null;
      toast('Parties updated', doneMessage);
      return true;
    } catch (error) {
      if (isStale(error)) {
        toast('Changed by someone else', 'Reloaded.');
        parties.editing = 0;
        parties.linking = null;
      } else {
        setError('partiesError', error.message || 'The change could not be saved.');
        if (error.status === 409) parties.editing = 0;
      }
      return false;
    } finally {
      parties.busy = false;
      await loadParties();
    }
  }

  function partyById(id) {
    return parties.data ? parties.data.parties.find(party => party.id === Number(id)) || null : null;
  }

  async function addParty() {
    const role = $('partiesAddRole')?.value === 'respondent' ? 'respondent' : 'complainant';
    parties.addRow.role = role;
    const built = buildAddPartyPayload(parties.complaintId, parties.addRow);
    if (built.problem) { setError('partiesError', built.problem); return; }
    const ok = await partyWrite('party_save.php', built.payload, `${ROLE_LABELS[role]} added.`);
    if (ok) {
      clearTimers([parties.addRow]);
      parties.addRow = newPartyRow(role, 'unregistered');
      paintAddRow();
    }
  }

  async function saveEdit(id) {
    const party = partyById(id);
    const row = document.querySelector(`#partiesBody tr[data-party-id="${Number(id)}"]`);
    if (!party || !row) return;
    const value = key => row.querySelector(`[data-wk-edit="${key}"]`)?.value || '';
    const built = buildUpdatePartyPayload(parties.complaintId, party, { full_name: value('full_name'), address: value('address'), contact_no: value('contact_no') });
    if (built.problem) { setError('partiesError', built.problem); return; }
    await partyWrite('party_save.php', built.payload, 'Details saved.');
  }

  async function removeParty(id) {
    const party = partyById(id);
    if (!party) return;
    if (!window.confirm(`Remove ${party.full_name} from this complaint?`)) return;
    await partyWrite('party_remove.php', { id: party.id, version: party.version }, `${ROLE_LABELS[party.role]} removed.`);
  }

  async function confirmLink() {
    const link = parties.linking;
    const party = link ? partyById(link.partyId) : null;
    if (!party || !link.picked) return;
    await partyWrite('party_link.php', { id: party.id, version: party.version, resident_id: Number(link.picked.id) }, 'Linked to the registered resident.');
  }

  // -------------------------------------------------------------------------
  // Events
  // -------------------------------------------------------------------------

  function handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    // No close on a backdrop click: a half-filled walk-in form would be lost.
    const button = target.closest('[data-wk-action]');
    if (!button) return;
    const inWalkinArea = button.id === 'walkinNewBtn' || button.closest('.walkin-modal');
    if (!inWalkinArea || button.disabled) return;
    const action = button.dataset.wkAction;
    if (action === 'new-walkin') { openWalkin(button); return; }
    if (action === 'close') { closeModal(button.closest('.walkin-modal')); return; }
    if (action === 'add-party') {
      const role = button.dataset.role === 'respondent' ? 'respondent' : 'complainant';
      const rows = role === 'respondent' ? walkin.respondents : walkin.complainants;
      if (canAddParty(rows, role)) rows.push(newPartyRow(role, 'unregistered'));
      paintWalkinParties();
      return;
    }
    if (action === 'remove-party') {
      const key = Number(button.dataset.key);
      clearTimers(walkin.complainants.concat(walkin.respondents).filter(row => row.key === key));
      walkin.complainants = walkin.complainants.filter(row => row.key !== key);
      walkin.respondents = walkin.respondents.filter(row => row.key !== key);
      paintWalkinParties();
      return;
    }
    if (action === 'pick') {
      const row = parties.linking && parties.linking.row.key === Number(button.dataset.key) ? parties.linking.row : findRow(button.dataset.key);
      if (!row) return;
      const resident = row.results.find(item => Number(item.id) === Number(button.dataset.residentId));
      if (!resident) return;
      clearTimers([row]);
      if (parties.linking && parties.linking.row === row) {
        parties.linking.picked = resident;
        paintParties();
        return;
      }
      row.resident = resident;
      row.results = [];
      repaintRow(row);
      return;
    }
    if (action === 'unpick') {
      const row = findRow(button.dataset.key);
      if (!row) return;
      row.resident = null;
      row.query = '';
      repaintRow(row);
      return;
    }
    if (action === 'party-add') { addParty(); return; }
    if (action === 'party-edit') { parties.editing = Number(button.dataset.partyId); parties.linking = null; paintParties(); return; }
    if (action === 'party-cancel') { parties.editing = 0; paintParties(); return; }
    if (action === 'party-save') { saveEdit(button.dataset.partyId); return; }
    if (action === 'party-remove') { removeParty(button.dataset.partyId); return; }
    if (action === 'party-link') {
      parties.editing = 0;
      parties.linking = { partyId: Number(button.dataset.partyId), row: newPartyRow('complainant', 'registered'), picked: null };
      paintParties();
      document.querySelector('#partiesBody [data-wk-link="query"]')?.focus();
      return;
    }
    if (action === 'link-cancel') { if (parties.linking) clearTimers([parties.linking.row]); parties.linking = null; paintParties(); return; }
    if (action === 'link-confirm') confirmLink();
  }

  function handleLinkInput(event) {
    const input = event.target;
    if (!(input instanceof Element) || input.dataset.wkLink !== 'query' || !parties.linking) return;
    parties.linking.row.query = input.value;
    scheduleSearch(parties.linking.row);
  }

  function handleKeydown(event) {
    const modal = topModal();
    if (!modal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      closeModal(modal);
      return;
    }
    if (event.key !== 'Tab') return;
    const nodes = focusables(modal);
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  }

  function initComplaintWalkin() {
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', handleKeydown);
    $('walkinForm')?.addEventListener('submit', submitWalkin);
    $('walkinType')?.addEventListener('change', onTypeChange);
    ['walkinComplainants', 'walkinRespondents', 'partiesAddRow'].forEach(id => {
      $(id)?.addEventListener('input', handleEditorInput);
      $(id)?.addEventListener('change', handleEditorInput);
    });
    $('partiesBody')?.addEventListener('input', handleLinkInput);
    $('partiesAddRole')?.addEventListener('change', () => {
      if (parties.addRow) parties.addRow.role = $('partiesAddRole').value === 'respondent' ? 'respondent' : 'complainant';
    });
  }

  /* Top-level and guarded: a failure here must never stop js/official.js. */
  function initComplaintWalkinSafely() {
    try {
      initComplaintWalkin();
    } catch (error) {
      if (window.console) window.console.error('Walk-in complaints failed to start:', error);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initComplaintWalkinSafely);
  else initComplaintWalkinSafely();

  window.ComplaintWalkin = { openParties, openWalkin };
  window.ComplaintWalkinUI = {
    newPartyRow, canAddParty, partyPayload, partyRowProblem, buildWalkinPayload,
    buildAddPartyPayload, buildUpdatePartyPayload, isStale, LIMITS
  };
})();

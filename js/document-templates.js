/* BarangayLink document template management controller. */
(function () {
  'use strict';

  const MAX_BYTES = 2097152;
  const SIGNATORY_NOT_CONFIGURED = 'Signatory is not configured.';
  const state = {
    types: [],
    signatory: null,
    signatoryError: null,
    signatoryErrorShown: false,
    signatoryDirty: false,
    modalOpener: new Map(),
    previewUrl: null,
    confirmAction: null,
    testBusy: false,
    historyTypeId: null,
    historyHighlightId: null,
    review: null,
    reviewSeq: 0,
    catalogue: [],
    canCreate: false,
    typeCodeDirty: false,
    typeBusy: false,
    highlightTypeId: null
  };

  /* Phase 5A add document type. Limits mirror api/documents/_type_create.php. */
  const TYPE_SOURCE_LABELS = {
    resident_record: 'Resident record',
    resident_supplied: 'Resident supplies',
    staff_entered: 'Staff enters',
    system: 'System'
  };
  const TYPE_NO_ACTIVE_TEXT = 'No active version \u2014 upload a template to publish';
  // Phase 7: internal types (Summons) are never offered to residents.
  const TYPE_INTERNAL_TEXT = 'Internal \u2014 complaints';

  /* Phase 4B review screen. */
  // Machine-readable "code" values sent with these two errors (message text is for people).
  const REVIEW_BUSY_CODE = 'analysis_busy';          // api/documents/template_analyze.php, 409
  const REVIEW_BUSY_COOLDOWN_MS = 5000;
  const REVIEW_NO_ANALYSIS_CODE = 'no_analysis';     // api/documents/template_analysis_get.php, 404
  // Every skip reason produced by api/documents/_apply.php, in plain words.
  const REVIEW_SKIP_LABELS = {
    'text mismatch': 'The text no longer matches the document at that position.',
    'overlaps another selection': 'Overlaps another selected suggestion.',
    'text box': 'Inside a text box, which is not supported. Edit it in Word.',
    'crosses a tab or break': 'Spans a tab or line break.',
    'crosses a field code': 'Spans a Word field (for example a date or page number).',
    'crosses a hyperlink boundary': 'Spans the edge of a hyperlink.',
    'crosses a content boundary': 'Spans tracked changes or a content control.',
    'crosses non-text content': 'Spans a symbol, picture or special hyphen.'
  };

  /* Phase 4C manual edits. Limits mirror api/documents/_manual_edit.php;
     the server re-checks everything against its own copy of the text. */
  const MANUAL_MAX_EDITS = 40;
  const MANUAL_MAX_PLACEHOLDER_SPAN = 200;
  const MANUAL_MAX_TEXT_SPAN = 500;
  const MANUAL_MAX_NEW_TEXT = 500;
  const MANUAL_CONFLICT_MESSAGE = 'Save or clear one kind of change first.';
  // Same set the server refuses: C0/C1 controls, line/paragraph separators, bidi controls.
  function manualHasForbiddenChar(text) {
    return Array.from(text).some(ch => {
      const code = ch.codePointAt(0);
      return code <= 0x1F || (code >= 0x7F && code <= 0x9F) || code === 0x2028 || code === 0x2029
        || (code >= 0x202A && code <= 0x202E) || (code >= 0x2066 && code <= 0x2069);
    });
  }

  function getElement(id) {
    return document.getElementById(id);
  }

  function setFeedback(element, message, kind) {
    if (!element) return;
    element.textContent = message || '';
    element.className = `doctpl-feedback${kind ? ` is-${kind}` : ''}`;
  }

  function setResult(message, errors, kind) {
    const result = getElement('doctplUploadResult');
    if (!result) return;
    result.className = `doctpl-result${kind ? ` is-${kind}` : ''}`;
    result.innerHTML = message ? `<div>${escapeHtml(message)}</div>` : '';
    if (errors && errors.length) {
      result.innerHTML += `<ul>${errors.map(error => `<li>${escapeHtml(error)}</li>`).join('')}</ul>`;
    }
  }

  function modalFocusable(modal) {
    return [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), iframe')];
  }

  /* Re-rendering a table replaces its buttons, so a remembered control may be
     detached. Find the equivalent control by action and ids instead. */
  function resolveFocusTarget(element) {
    if (!element || typeof element.focus !== 'function') return null;
    if (element.isConnected) return element;
    const openModals = [...document.querySelectorAll('.doctpl-modal.show')].reverse();
    const data = element.dataset || {};
    if (/^[a-z-]+$/.test(data.action || '')) {
      let selector = `[data-action="${data.action}"]`;
      if (data.versionId) selector += `[data-version-id="${Number(data.versionId)}"]`;
      if (data.typeId) selector += `[data-type-id="${Number(data.typeId)}"]`;
      for (const scope of [...openModals, getElement('tab-documents')]) {
        const match = scope ? scope.querySelector(`${selector}:not([disabled])`) : null;
        if (match) return match;
      }
    }
    return openModals.length ? (modalFocusable(openModals[0])[0] || null) : null;
  }

  function replaceContent(container, html) {
    const focused = container.contains(document.activeElement) ? document.activeElement : null;
    container.innerHTML = html;
    if (!focused) return;
    const target = resolveFocusTarget(focused);
    if (target) target.focus();
  }

  function openModal(id, opener) {
    const modal = getElement(id);
    if (!modal) return;
    if (!modal.classList.contains('show')) {
      state.modalOpener.set(id, opener || document.activeElement);
    }
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    const first = modalFocusable(modal)[0];
    if (first) window.setTimeout(() => first.focus(), 0);
  }

  function revokePreviewUrl() {
    if (state.previewUrl) {
      URL.revokeObjectURL(state.previewUrl);
      state.previewUrl = null;
    }
  }

  function closeModal(modal) {
    if (!modal) return;
    if (modal.id === 'doctplPreviewModal') {
      revokePreviewUrl();
      const frame = getElement('doctplPreviewFrame');
      if (frame) frame.src = 'about:blank';
    }
    if (modal.id === 'doctplHistoryModal') {
      state.historyTypeId = null;
      state.historyHighlightId = null;
    }
    if (modal.id === 'doctplReviewModal') {
      const pending = state.review && !state.review.locked ? manualEdits().length : 0;
      if (pending && !window.confirm(`Discard ${pending} unsaved edit(s)?`)) return;
      resetReview();
    }
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    const opener = resolveFocusTarget(state.modalOpener.get(modal.id));
    state.modalOpener.delete(modal.id);
    if (opener) opener.focus();
  }

  function closeTopModal() {
    const modals = [...document.querySelectorAll('.doctpl-modal.show')];
    closeModal(modals[modals.length - 1]);
  }

  function signatoryHonorific() {
    return (state.signatory && state.signatory.honorific) || 'Hon.';
  }

  function signatoryTitle() {
    return (state.signatory && state.signatory.title) || 'Barangay Captain';
  }

  function renderSignatoryPreview(name) {
    const previewName = getElement('doctplSignatoryPreviewName');
    const previewTitle = getElement('doctplSignatoryPreviewTitle');
    if (previewName) previewName.textContent = `${signatoryHonorific()} ${name || '—'}`;
    if (previewTitle) previewTitle.textContent = signatoryTitle();
  }

  function renderSignatory() {
    const input = getElement('doctplSignatoryName');
    const save = getElement('doctplSignatorySave');
    const note = getElement('doctplSignatoryNote');
    const feedback = getElement('doctplSignatoryFeedback');
    const admin = !!window.IS_OFFICIAL_ADMIN;
    if (input) {
      // Never replace text the captain is still typing.
      if (!state.signatoryDirty && state.signatory) input.value = state.signatory.captain_name;
      input.disabled = !admin;
    }
    if (save) save.disabled = !admin;
    if (note) note.hidden = admin;
    const typed = input ? input.value.trim() : '';
    renderSignatoryPreview(state.signatoryDirty || !state.signatory ? typed : state.signatory.captain_name);
    if (state.signatoryError) {
      setFeedback(feedback, state.signatoryError, 'error');
      state.signatoryErrorShown = true;
    } else if (state.signatoryErrorShown) {
      setFeedback(feedback, '', '');
      state.signatoryErrorShown = false;
    }
  }

  async function loadSignatory() {
    try {
      const data = await fetchJson(`${API_BASE}/settings/signatory_get.php`);
      state.signatory = {
        captain_name: String(data.captain_name || ''),
        honorific: String(data.honorific || 'Hon.'),
        title: String(data.title || 'Barangay Captain')
      };
      state.signatoryError = null;
    } catch (error) {
      state.signatory = null;
      if (error && error.message === SIGNATORY_NOT_CONFIGURED) {
        state.signatoryError = 'The captain name is not configured. Ask an administrator.';
      } else {
        state.signatoryError = (error && error.status && error.message) || 'The signatory could not be loaded.';
      }
    }
    renderSignatory();
    return state.signatory;
  }

  /* Always refetches so callers never see a stale name. */
  async function getSignatory() {
    const signatory = await loadSignatory();
    if (!signatory) throw new Error(state.signatoryError || 'Signatory unavailable');
    return signatory;
  }

  function statusBadge(status) {
    const value = String(status || '').toLowerCase();
    const label = value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Unknown';
    return `<span class="status-badge status-${escapeAttribute(value)}">${escapeHtml(label)}</span>`;
  }

  function activeVersion(type) {
    return (type.versions || []).find(version => version.status === 'active') || null;
  }

  function findType(typeId) {
    return state.types.find(type => Number(type.id) === Number(typeId)) || null;
  }

  function findVersion(versionId) {
    for (const type of state.types) {
      const version = (type.versions || []).find(item => Number(item.id) === Number(versionId));
      if (version) return { type, version };
    }
    return null;
  }

  function renderDocumentTemplates() {
    const body = getElement('doctplTypesBody');
    if (!body) return;
    if (!state.types.length) {
      replaceContent(body, '<tr><td colspan="4" class="registry-empty">No document types configured.</td></tr>');
      return;
    }
    replaceContent(body, state.types.map(type => {
      const active = activeVersion(type);
      const highlight = !active && Number(type.id) === Number(state.highlightTypeId);
      const uploadClass = active ? 'btn-action process' : 'btn-action process doctpl-primary-upload';
      const archiveLabel = type.status === 'archived' ? 'Unarchive' : 'Archive';
      const archiveAction = window.IS_OFFICIAL_ADMIN
        ? `<button type="button" class="btn-action ${type.status === 'archived' ? 'approve' : 'reject'}" data-action="archive" data-type-id="${Number(type.id)}" data-archived="${type.status !== 'archived'}">${archiveLabel}</button>`
        : '';
      return `<tr data-type-row="${Number(type.id)}" class="${highlight ? 'doctpl-type-new' : ''}">
        <td><strong>${escapeHtml(type.name)}</strong></td>
        <td>${statusBadge(type.status)}${type.audience === 'internal' ? ` <span class="status-badge doctpl-internal-badge">${escapeHtml(TYPE_INTERNAL_TEXT)}</span>` : ''}${active ? '' : ' <span class="status-badge doctpl-no-active-badge">No active version</span>'}</td>
        <td>${active ? `<div class="doctpl-version-meta"><strong>v${Number(active.version_no)}</strong><span>${escapeHtml(active.created_by_name || 'Unknown uploader')}</span><span>${escapeHtml(active.created_at || 'Date unavailable')}</span></div>` : `<span class="doctpl-note doctpl-no-active">${escapeHtml(TYPE_NO_ACTIVE_TEXT)}</span>`}</td>
        <td class="action-column"><div class="doctpl-actions">
          <button type="button" class="${uploadClass}" data-action="upload" data-type-id="${Number(type.id)}">${active ? 'Upload new version' : 'Upload template'}</button>
          <button type="button" class="btn-action view" data-action="history" data-type-id="${Number(type.id)}">History</button>
          ${active ? `<button type="button" class="btn-action view" data-action="download" data-version-id="${Number(active.id)}">Download active</button>` : ''}
          ${archiveAction}
        </div></td>
      </tr>`;
    }).join(''));
  }

  function isHistoryOpen() {
    const modal = getElement('doctplHistoryModal');
    return !!modal && modal.classList.contains('show') && state.historyTypeId !== null;
  }

  function renderHistory() {
    const body = getElement('doctplHistoryBody');
    if (!body) return;
    const type = findType(state.historyTypeId);
    if (!type) {
      replaceContent(body, '<tr><td colspan="5" class="registry-empty">This document type is no longer available.</td></tr>');
      return;
    }
    getElement('doctplHistoryTitle').textContent = `Version History: ${type.name}`;
    replaceContent(body, (type.versions || []).map(version => {
      const canActivate = ['draft', 'retired'].includes(version.status);
      const tested = Number(version.test_ok) === 1;
      const checkPassed = Number(version.check_ok) === 1;
      const errors = Array.isArray(version.check_errors) ? version.check_errors : [];
      const originalName = String(version.original_name || '').trim();
      const notes = String(version.notes || '').trim();
      const disabledReason = !checkPassed ? 'Template check must pass first.' : (!tested ? 'Run a successful test render first.' : '');
      const activateButton = canActivate
        ? `<button type="button" class="btn-action approve" data-action="activate" data-version-id="${Number(version.id)}" data-type-id="${Number(type.id)}" title="${escapeAttribute(disabledReason)}" ${disabledReason ? 'disabled' : ''}>Activate</button>`
        : '';
      const discardButton = version.status === 'draft'
        ? '<button type="button" class="btn-action reject" data-action="discard" data-version-id="' + Number(version.id) + '">Discard</button>'
        : '';
      const reviewButton = version.status === 'draft'
        ? `<button type="button" class="btn-action view" data-action="review" data-version-id="${Number(version.id)}" data-type-id="${Number(type.id)}">Review / edit</button>`
        : '';
      const testButton = version.status === 'discarded'
        ? ''
        : `<button type="button" class="btn-action process" data-action="test" data-version-id="${Number(version.id)}" ${state.testBusy ? 'disabled' : ''}>Test render</button>`;
      return `<tr data-version-row="${Number(version.id)}" class="${Number(version.id) === Number(state.historyHighlightId) ? 'doctpl-history-new' : ''}">
        <td><strong>v${Number(version.version_no)}</strong>${originalName ? `<div class="doctpl-note">${escapeHtml(originalName)}</div>` : ''}</td>
        <td>${statusBadge(version.status)}
          <div class="doctpl-note">${escapeHtml(checkPassed ? 'Check passed' : 'Check failed')} · ${escapeHtml(tested ? 'Tested' : 'Not tested')}</div>
          ${errors.length ? `<ul class="doctpl-check-errors">${errors.map(error => `<li>${escapeHtml(error)}</li>`).join('')}</ul>` : ''}
          ${notes ? `<div class="doctpl-note">Notes: ${escapeHtml(notes)}</div>` : ''}
        </td>
        <td>${escapeHtml(version.created_by_name || 'Unknown uploader')}</td>
        <td>${escapeHtml(version.created_at || 'Date unavailable')}</td>
        <td><div class="doctpl-actions">
          ${testButton}
          ${reviewButton}
          <button type="button" class="btn-action view" data-action="download" data-version-id="${Number(version.id)}">Download</button>
          ${activateButton}
          ${discardButton}
        </div></td>
      </tr>`;
    }).join('') || '<tr><td colspan="5" class="registry-empty">No versions found.</td></tr>');
  }

  async function loadDocumentTemplates() {
    const body = getElement('doctplTypesBody');
    if (body && !state.types.length) body.innerHTML = '<tr><td colspan="4" class="registry-empty">Loading document templates...</td></tr>';
    const data = await fetchJson(`${API_BASE}/documents/types_list.php`);
    state.types = Array.isArray(data.types) ? data.types : [];
    state.catalogue = Array.isArray(data.catalogue) ? data.catalogue : [];
    state.canCreate = data.can_create === true;
    const addButton = getElement('doctplAddTypeBtn');
    if (addButton) {
      addButton.disabled = !state.canCreate;
      addButton.title = state.canCreate ? '' : 'Run the 20261115 database migration first.';
    }
    renderDocumentTemplates();
    if (isHistoryOpen()) renderHistory();
    await loadSignatory();
    return state.types;
  }

  /* Refresh after an action without turning a reload problem into a second error. */
  async function reloadQuietly() {
    try {
      await loadDocumentTemplates();
      return true;
    } catch (error) {
      return false;
    }
  }

  function showUpload(type, opener) {
    getElement('doctplUploadType').textContent = type.name;
    getElement('doctplUploadTypeId').value = String(Number(type.id));
    getElement('doctplUploadForm').reset();
    getElement('doctplUploadTypeId').value = String(Number(type.id));
    setResult('', [], '');
    openModal('doctplUploadModal', opener);
  }

  function showHistory(type, highlightId, opener) {
    state.historyTypeId = Number(type.id);
    state.historyHighlightId = highlightId ? Number(highlightId) : null;
    renderHistory();
    openModal('doctplHistoryModal', opener);
  }

  function showConfirm(message, action, opener) {
    state.confirmAction = action;
    getElement('doctplConfirmMessage').textContent = message;
    openModal('doctplConfirmModal', opener);
  }

  function fileNameFromDisposition(header) {
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header || '');
    if (!match) return '';
    let name = match[1].trim();
    try { name = decodeURIComponent(name); } catch (error) { /* keep the raw value */ }
    return name.replace(/[\\/:*?"<>|]+/g, '_');
  }

  function fallbackDownloadName(versionId) {
    const found = findVersion(versionId);
    if (!found) return 'template.docx';
    return `${String(found.type.name).replace(/[^a-z0-9_-]+/gi, '_')}_v${Number(found.version.version_no)}.docx`;
  }

  async function downloadVersion(versionId) {
    try {
      const response = await fetch(`${API_BASE}/documents/template_download.php?version_id=${Number(versionId)}`);
      if (!response.ok) {
        let message = response.status === 404
          ? 'This template version could not be found.'
          : `The template could not be downloaded (HTTP ${response.status}).`;
        try {
          const data = await response.json();
          if (data && data.message) message = String(data.message);
        } catch (error) { /* no JSON body */ }
        throw new Error(message);
      }
      const blob = await response.blob();
      const name = fileNameFromDisposition(response.headers.get('content-disposition')) || fallbackDownloadName(versionId);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = name;
      link.style.display = 'none';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      showToastAdmin('Download Failed', escapeHtml(error.message));
    }
  }

  async function openPreview(token, opener) {
    const modal = getElement('doctplPreviewModal');
    const result = getElement('doctplPreviewResult');
    const frame = getElement('doctplPreviewFrame');
    setFeedback(result, 'Loading PDF preview...', '');
    openModal('doctplPreviewModal', opener || document.activeElement);
    try {
      const response = await fetch(`${API_BASE}/documents/template_preview.php?token=${encodeURIComponent(token)}`);
      const contentType = response.headers.get('content-type') || '';
      if (!response.ok || !contentType.toLowerCase().includes('application/pdf')) {
        throw new Error('Template preview could not be loaded.');
      }
      const blob = await response.blob();
      revokePreviewUrl();
      state.previewUrl = URL.createObjectURL(blob);
      frame.src = state.previewUrl;
      setFeedback(result, '', 'success');
    } catch (error) {
      closeModal(modal);
      showToastAdmin('Preview Failed', error.message);
    }
  }

  function setTestButtonsDisabled(disabled) {
    document.querySelectorAll('.doctpl-modal [data-action="test"]').forEach(button => { button.disabled = disabled; });
  }

  async function testVersion(versionId, opener) {
    if (state.testBusy) return;
    state.testBusy = true;
    setTestButtonsDisabled(true);
    try {
      const data = await fetchJson(`${API_BASE}/documents/template_test_render.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version_id: Number(versionId) })
      });
      // Refresh first so History shows "Tested" and enables Activate under the preview.
      await reloadQuietly();
      await openPreview(data.preview_token, opener);
    } catch (error) {
      showToastAdmin('Test Render Failed', error.message);
      await reloadQuietly();
    } finally {
      state.testBusy = false;
      setTestButtonsDisabled(false);
    }
  }

  async function activateVersion(versionId, typeId) {
    try {
      await fetchJson(`${API_BASE}/documents/template_activate.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version_id: Number(versionId), expected_active_version_id: Number(findType(typeId)?.active_version_id || 0) })
      });
      await loadDocumentTemplates();
      closeTopModal();
      showToastAdmin('Template Activated', 'This version is now used for new documents.');
    } catch (error) {
      if (error.status === 409) {
        await reloadQuietly();
        showToastAdmin('Activation Conflict', 'The active version changed. Review and try again.');
      } else {
        showToastAdmin('Activation Failed', error.message);
      }
    }
  }

  async function discardVersion(versionId) {
    try {
      await fetchJson(`${API_BASE}/documents/template_discard.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version_id: Number(versionId) })
      });
      await loadDocumentTemplates();
      closeTopModal();
    } catch (error) {
      showToastAdmin('Discard Failed', error.message);
      await reloadQuietly();
    }
  }

  async function archiveType(typeId, archived) {
    try {
      await fetchJson(`${API_BASE}/documents/type_archive.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ document_type_id: Number(typeId), archived: !!archived })
      });
      await loadDocumentTemplates();
      showToastAdmin(archived ? 'Type Archived' : 'Type Unarchived', archived ? 'New requests are now blocked.' : 'New requests are now allowed.');
    } catch (error) {
      showToastAdmin('Archive Update Failed', error.message);
    }
  }

  /* ---------------------------------------------------------- add type */
  // Mirror of document_type_derive_code(): accents dropped, symbols -> _.
  function deriveTypeCode(name) {
    let code = String(name || '').replace(/ñ/g, 'n').replace(/Ñ/g, 'N')
      .normalize('NFD').replace(/\p{Mn}+/gu, '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (code && !/^[a-z]/.test(code)) code = `doc_${code}`;
    return code.slice(0, 50).replace(/_+$/, '');
  }

  function typeResult(message, kind) {
    const result = getElement('doctplTypeResult');
    if (!result) return;
    result.className = `doctpl-result${kind ? ` is-${kind}` : ''}`;
    result.textContent = message || '';
  }

  function renderTypeChecklist() {
    const list = getElement('doctplTypeFields');
    if (!list) return;
    list.replaceChildren();
    state.catalogue.forEach(field => {
      const key = String(field.field_key || '');
      const item = el('li', 'doctpl-type-field');
      const label = el('label', 'doctpl-type-field-label');
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.value = key;
      box.dataset.typeField = key;
      box.checked = !!field.default || !!field.always;
      box.disabled = !!field.always;
      label.appendChild(box);
      label.appendChild(el('span', 'doctpl-type-field-name', field.label));
      label.appendChild(el('code', 'doctpl-type-field-key', `{{${key}}}`));
      item.appendChild(label);
      const badges = el('span', 'doctpl-type-badges');
      badges.appendChild(el('span', `doctpl-source-badge source-${String(field.source || '').replace(/[^a-z_]/g, '')}`,
        TYPE_SOURCE_LABELS[field.source] || field.source));
      if (field.always) badges.appendChild(el('span', 'doctpl-source-badge doctpl-always-badge', 'always included'));
      if (Number(field.is_required) === 1) badges.appendChild(el('span', 'doctpl-source-badge doctpl-required-badge', 'required'));
      item.appendChild(badges);
      list.appendChild(item);
    });
  }

  function showAddType(opener) {
    if (!state.canCreate) {
      showToastAdmin('Not Available', 'Run the 20261115 database migration first.');
      return;
    }
    getElement('doctplTypeForm').reset();
    state.typeCodeDirty = false;
    typeResult('', '');
    renderTypeChecklist();
    openModal('doctplTypeModal', opener);
  }

  function typeNameInput(event) {
    if (state.typeCodeDirty) return;
    const code = getElement('doctplTypeCode');
    if (code) code.value = deriveTypeCode(event.target.value);
  }

  function typeCodeInput(event) {
    // Once the official types a code, stop auto-filling it. Clearing it hands control back.
    state.typeCodeDirty = event.target.value.trim() !== '';
  }

  async function submitType(event) {
    event.preventDefault();
    if (state.typeBusy) return;
    const name = getElement('doctplTypeName').value.trim();
    if (name.length < 3 || name.length > 100) {
      typeResult('Enter a document name of 3 to 100 characters.', 'error');
      return;
    }
    const fieldKeys = [...getElement('doctplTypeFields').querySelectorAll('input[data-type-field]')]
      .filter(box => box.checked && !box.disabled).map(box => box.value);
    const payload = {
      name,
      code: getElement('doctplTypeCode').value.trim(),
      fee: getElement('doctplTypeFee').value.trim(),
      processing_days: getElement('doctplTypeDays').value.trim(),
      requirements: getElement('doctplTypeRequirements').value,
      field_keys: fieldKeys
    };
    const button = getElement('doctplTypeSubmit');
    state.typeBusy = true;
    button.disabled = true;
    typeResult('Creating document type...', '');
    try {
      const data = await fetchJson(`${API_BASE}/documents/type_create.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      state.highlightTypeId = Number(data.type_id);
      closeModal(getElement('doctplTypeModal'));
      showToastAdmin('Document Type Created', `${name} was added. Upload a template to publish it.`);
      const reloaded = await reloadQuietly();
      if (!reloaded) showToastAdmin('List Not Refreshed', 'The type was created, but the list could not be refreshed. Reopen this tab.');
      const row = document.querySelector(`#doctplTypesBody tr[data-type-row="${Number(data.type_id)}"]`);
      if (row) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    } catch (error) {
      typeResult((error && error.message) || 'The document type could not be created.', 'error');
    } finally {
      state.typeBusy = false;
      button.disabled = false;
    }
  }

  function uploadError(message) {
    setResult(message, [], 'error');
  }

  async function submitUpload(event) {
    event.preventDefault();
    const file = getElement('doctplUploadFile').files[0];
    const notes = getElement('doctplUploadNotes').value.trim();
    if (!file || !/\.docx$/i.test(file.name)) {
      uploadError('Select a DOCX file.');
      return;
    }
    if (file.size > MAX_BYTES) {
      uploadError('The file must be 2 MB or smaller.');
      return;
    }
    if (notes.length > 255) {
      uploadError('Notes must be 255 characters or fewer.');
      return;
    }
    const button = getElement('doctplUploadSubmit');
    button.disabled = true;
    setResult('Uploading and checking template...', [], '');
    const formData = new FormData();
    formData.append('document_type_id', getElement('doctplUploadTypeId').value);
    formData.append('file', file);
    formData.append('notes', notes);
    try {
      const data = await fetchJson(`${API_BASE}/documents/template_upload.php`, { method: 'POST', body: formData });
      const typeId = Number(getElement('doctplUploadTypeId').value);
      const versionId = Number(data.version_id);
      const errors = Array.isArray(data.errors) ? data.errors : [];
      await reloadQuietly();
      const type = findType(typeId);
      const version = type ? (type.versions || []).find(item => Number(item.id) === versionId) : null;
      if (!data.check_ok) {
        // Keep the modal open so the errors stay visible next to the file picker.
        const saved = version ? `Saved as draft v${Number(version.version_no)}.` : 'Saved as a draft.';
        setResult(`${saved} Fix the template and upload again.`, errors, 'error');
        return;
      }
      closeModal(getElement('doctplUploadModal'));
      if (type) {
        showHistory(type, versionId, document.activeElement);
      } else {
        showToastAdmin('Template Uploaded', 'The draft was saved, but the list could not be refreshed. Reopen this tab.');
      }
    } catch (error) {
      setResult(error.message, error.data?.errors || [], 'error');
    } finally {
      button.disabled = false;
    }
  }

  async function submitSignatory(event) {
    event.preventDefault();
    if (!window.IS_OFFICIAL_ADMIN) return;
    const input = getElement('doctplSignatoryName');
    const name = input.value.trim();
    if (!name) {
      setFeedback(getElement('doctplSignatoryFeedback'), 'Enter a valid signatory name.', 'error');
      return;
    }
    const button = getElement('doctplSignatorySave');
    button.disabled = true;
    setFeedback(getElement('doctplSignatoryFeedback'), 'Saving...', '');
    try {
      const data = await fetchJson(`${API_BASE}/settings/signatory_update.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ captain_name: name })
      });
      // Keep the loaded honorific and title; only the name changes here.
      state.signatory = {
        captain_name: String(data.captain_name || name),
        honorific: signatoryHonorific(),
        title: signatoryTitle()
      };
      state.signatoryError = null;
      state.signatoryErrorShown = false;
      state.signatoryDirty = false;
      renderSignatory();
      setFeedback(getElement('doctplSignatoryFeedback'), 'Signatory updated.', 'success');
    } catch (error) {
      setFeedback(getElement('doctplSignatoryFeedback'), error.message, 'error');
    } finally {
      button.disabled = false;
    }
  }

  /* ------------------------------------------------------------------
     Phase 4B: review AI placeholder suggestions for one draft version.
     All server text is written with textContent. Listeners live on the
     review modal only. state.review.edits is the single list of pending
     edits; every item carries a source ('ai' now, 'manual' in Phase 4C).
     ------------------------------------------------------------------ */

  function aiAvailable() {
    return typeof featureEnabled === 'function' ? featureEnabled('ai') : !!window.AI_CONFIGURED;
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function resetReview() {
    // Invalidates any request still in flight for the closed review.
    state.reviewSeq += 1;
    if (state.review && state.review.cooldownTimer) window.clearTimeout(state.review.cooldownTimer);
    state.review = null;
  }

  function reviewFields() {
    const type = state.review ? findType(state.review.typeId) : null;
    return type && Array.isArray(type.fields) ? type.fields : [];
  }

  function reviewKeyAllowed(key) {
    return reviewFields().some(field => String(field.field_key) === String(key));
  }

  /* state.review.edits holds both kinds; each helper looks at one source only. */
  function aiEdits() {
    return state.review ? state.review.edits.filter(edit => edit.source === 'ai') : [];
  }

  function manualEdits() {
    return state.review ? state.review.edits.filter(edit => edit.source === 'manual') : [];
  }

  function selectedEdits() {
    return aiEdits().filter(edit => edit.selected && edit.key);
  }

  /* Only AI items become apply selections; index + key, never text. */
  function aiSelections() {
    return selectedEdits()
      .filter(edit => edit.source === 'ai')
      .map(edit => ({ index: Number(edit.index), key: String(edit.key) }));
  }

  function skipReasonLabel(reason) {
    return REVIEW_SKIP_LABELS[reason] || String(reason || 'Could not be applied.');
  }

  // fetchJson attaches the parsed error body as error.data.
  function errorCode(error) {
    return error && error.data && typeof error.data.code === 'string' ? error.data.code : '';
  }

  function analyzeErrorMessage(error) {
    const status = error && error.status;
    const message = error && error.message ? String(error.message) : '';
    if (status === 401) return 'Your session has expired. Sign in again.';
    if (status === 404) return 'This template version no longer exists. Close this window and reload the list.';
    if (status === 409) return message || 'This version cannot be analyzed right now.';
    if (status === 422) return message || 'This document cannot be analyzed.';
    if (status === 429) return message || 'AI analysis limit reached for this hour. Try again later.';
    if (status === 502) return message || 'The AI analysis failed. Try again or mark placeholders manually.';
    if (status === 503) return 'AI analysis is not configured. Mark placeholders manually in Word.';
    return 'The analysis could not be completed. Try again.';
  }

  function applyErrorMessage(error) {
    const status = error && error.status;
    const message = error && error.message ? String(error.message) : '';
    if (status === 401) return 'Your session has expired. Sign in again.';
    if (status === 404) return `${message || 'This version or analysis no longer exists.'} The analysis was reloaded.`;
    if (status === 409) return `${message || 'This version changed.'} The analysis was reloaded.`;
    if (status === 422) return message || 'The selection could not be applied.';
    return 'The suggestions could not be applied. Try again.';
  }

  function showReview(type, version, opener) {
    resetReview();
    state.review = {
      typeId: Number(type.id),
      typeName: String(type.name || ''),
      versionId: Number(version.id),
      versionNo: Number(version.version_no),
      analysis: null,
      stale: false,
      lastFailed: false,
      loading: true,
      loadError: '',
      busy: null,
      cooldown: false,
      cooldownTimer: null,
      locked: false,
      edits: [],
      manual: {
        loading: true,
        error: '',
        sha: '',
        paragraphs: [],
        placeholders: [],
        selection: null,
        chip: null,
        nextId: 1
      }
    };
    getElement('doctplReviewTitle').textContent = `Review Template: ${state.review.typeName} v${state.review.versionNo}`;
    setFeedback(getElement('doctplManualStatus'), '', '');
    setFeedback(getElement('doctplManualSelectionStatus'), '', '');
    getElement('doctplManualNewText').value = '';
    getElement('doctplManualChipText').value = '';
    // Consent is never remembered: unchecked on every open.
    getElement('doctplReviewAck').checked = false;
    setFeedback(getElement('doctplReviewAnalyzeStatus'), '', '');
    setFeedback(getElement('doctplReviewApplyStatus'), '', '');
    const result = getElement('doctplReviewResult');
    result.replaceChildren();
    result.hidden = true;
    getElement('doctplReviewAiOff').hidden = aiAvailable();
    renderReview();
    openModal('doctplReviewModal', opener);
    loadReviewAnalysis();
    // Manual editing never depends on the analysis (or on AI being configured).
    loadManualParagraphs();
  }

  async function loadReviewAnalysis() {
    const review = state.review;
    if (!review) return;
    const token = state.reviewSeq;
    review.loading = true;
    review.loadError = '';
    updateReviewControls();
    let data = null;
    let failure = null;
    try {
      data = await fetchJson(`${API_BASE}/documents/template_analysis_get.php?version_id=${Number(review.versionId)}`);
    } catch (error) {
      failure = error;
    }
    if (token !== state.reviewSeq || state.review !== review) return;
    review.loading = false;
    // Pending manual edits survive an analysis reload; only AI items are replaced.
    const keptManual = manualEdits();
    review.edits = keptManual;
    review.analysis = null;
    review.stale = false;
    review.lastFailed = false;
    if (failure) {
      if (!(failure.status === 404 && errorCode(failure) === REVIEW_NO_ANALYSIS_CODE)) {
        review.loadError = failure.status === 404
          ? 'This template version no longer exists. Close this window and reload the list.'
          : 'The analysis could not be loaded. Close this window and try again.';
      }
      renderReview();
      return;
    }
    review.analysis = {
      id: Number(data.analysis_id),
      status: String(data.status || ''),
      createdAt: String(data.created_at || ''),
      paragraphCount: Number(data.paragraph_count) || 0,
      droppedCount: Number(data.dropped_count) || 0
    };
    review.stale = data.stale === true;
    review.lastFailed = data.last_attempt_failed === true;
    const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
    const aiItems = suggestions.map((suggestion, index) => {
      const suggestedKey = String((suggestion && suggestion.key) || '');
      return {
        source: 'ai',
        index,
        key: reviewKeyAllowed(suggestedKey) ? suggestedKey : '',
        suggestedKey,
        text: String((suggestion && suggestion.text) || ''),
        paragraph: Number(suggestion && suggestion.paragraph) || 0,
        start: Number(suggestion && suggestion.start) || 0,
        reason: String((suggestion && suggestion.reason) || ''),
        confidence: Number(suggestion && suggestion.confidence) || 0,
        selected: false
      };
    }).sort((a, b) => (a.paragraph - b.paragraph) || (a.start - b.start) || (a.index - b.index));
    review.edits = aiItems.concat(keptManual);
    renderReview();
  }

  function renderReviewBanners() {
    const review = state.review;
    const container = getElement('doctplReviewBanners');
    container.replaceChildren();
    if (!review) return;
    const add = (kind, text) => {
      const banner = el('p', `doctpl-review-banner is-${kind}`, text);
      banner.setAttribute('role', kind === 'error' ? 'alert' : 'status');
      container.appendChild(banner);
    };
    if (review.loadError) add('error', review.loadError);
    if (!review.analysis) return;
    if (review.stale) add('warning', 'Template changed since this analysis. Re-run analysis.');
    if (review.analysis.status !== 'completed') {
      add('info', 'Last analysis attempt failed; no result.');
    } else if (review.lastFailed) {
      add('info', 'Last analysis attempt failed; showing previous result.');
    }
  }

  function renderReviewList() {
    const review = state.review;
    const list = getElement('doctplReviewList');
    list.replaceChildren();
    if (!review) return;
    const items = aiEdits();
    if (!items.length) {
      list.appendChild(el('li', 'doctpl-review-empty', 'The analysis found no placeholder suggestions for this document.'));
      return;
    }
    const fields = reviewFields();
    items.forEach(edit => {
      const item = el('li', 'doctpl-review-item');
      item.dataset.reviewItem = String(edit.index);

      const check = el('label', 'doctpl-review-check');
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.dataset.reviewCheck = String(edit.index);
      box.checked = edit.selected;
      check.appendChild(box);
      // The span text is the checkbox's accessible label.
      check.appendChild(el('span', 'doctpl-review-text', edit.text));
      item.appendChild(check);

      const confidence = Math.round(Math.max(0, Math.min(1, edit.confidence)) * 100);
      item.appendChild(el('p', 'doctpl-review-meta', `Paragraph ${edit.paragraph + 1} · AI confidence ${confidence}%`));
      if (edit.reason) item.appendChild(el('p', 'doctpl-review-reason', `AI reason: ${edit.reason}`));

      const keyLabel = el('label', 'doctpl-review-key');
      keyLabel.appendChild(el('span', '', 'Placeholder'));
      const select = document.createElement('select');
      select.dataset.reviewKey = String(edit.index);
      if (!edit.key) {
        const blank = el('option', '', '— choose a placeholder —');
        blank.value = '';
        select.appendChild(blank);
      }
      fields.forEach(field => {
        const option = el('option', '', `${String(field.label || field.field_key)} ({{${String(field.field_key)}}})`);
        option.value = String(field.field_key);
        select.appendChild(option);
      });
      select.value = edit.key;
      keyLabel.appendChild(select);
      item.appendChild(keyLabel);
      list.appendChild(item);
    });
  }

  function updateReviewControls() {
    const review = state.review;
    if (!review) return;
    const busy = !!review.busy;
    const ai = aiAvailable();
    const ack = getElement('doctplReviewAck');
    const analyze = getElement('doctplReviewAnalyze');
    // A locked (applied) review stays read-only; reopen the review on the new draft instead.
    ack.disabled = busy || !ai || review.locked;
    if (ack.disabled) ack.checked = false;
    analyze.disabled = !ai || !ack.checked || busy || review.cooldown || review.loading || review.locked;
    analyze.textContent = review.busy === 'analyze' ? 'Analyzing…' : 'Analyze with AI';
    analyze.setAttribute('aria-busy', review.busy === 'analyze' ? 'true' : 'false');

    const completed = !!review.analysis && review.analysis.status === 'completed';
    getElement('doctplReviewSuggestions').hidden = !completed;
    const frozen = busy || review.locked;
    const selectable = aiEdits().filter(edit => edit.key);
    const selected = selectedEdits().length;
    getElement('doctplReviewSelectAll').disabled = frozen || !selectable.length;
    getElement('doctplReviewClearAll').disabled = frozen || !selected;
    getElement('doctplReviewCounter').textContent = `${selected} of ${aiEdits().length} selected`;
    getElement('doctplReviewList').querySelectorAll('[data-review-check]').forEach(box => {
      const edit = aiEdits().find(item => item.index === Number(box.dataset.reviewCheck));
      box.checked = !!(edit && edit.selected);
      box.disabled = frozen || !edit || !edit.key;
      box.closest('.doctpl-review-item')?.classList.toggle('is-selected', box.checked);
    });
    getElement('doctplReviewList').querySelectorAll('[data-review-key]').forEach(select => { select.disabled = frozen; });
    // AI apply and manual save never run together: with both kinds pending, neither is allowed.
    const conflict = selected > 0 && manualEdits().length > 0;
    const apply = getElement('doctplReviewApply');
    apply.disabled = !completed || selected === 0 || busy || review.stale || review.locked || review.loading || conflict;
    apply.textContent = review.busy === 'apply' ? 'Applying…' : 'Apply selected';
    apply.setAttribute('aria-busy', review.busy === 'apply' ? 'true' : 'false');
    const applyConflict = getElement('doctplReviewApplyConflict');
    applyConflict.textContent = MANUAL_CONFLICT_MESSAGE;
    applyConflict.hidden = !conflict || review.locked;
    updateManualControls();

    const meta = getElement('doctplReviewMeta');
    meta.textContent = completed
      ? `Analysis of ${review.analysis.paragraphCount} paragraph(s)${review.analysis.createdAt ? ` on ${review.analysis.createdAt}` : ''}. Nothing is selected until you choose it.`
      : '';
  }

  function renderReview() {
    renderReviewBanners();
    renderReviewList();
    renderManual();
    updateReviewControls();
  }

  async function runAnalyze() {
    const review = state.review;
    const ack = getElement('doctplReviewAck');
    if (!review || review.busy || review.cooldown || review.loading || !ack.checked || !aiAvailable()) return;
    const token = state.reviewSeq;
    const status = getElement('doctplReviewAnalyzeStatus');
    review.busy = 'analyze';
    updateReviewControls();
    setFeedback(status, 'Analyzing the document with Google Gemini. This can take up to a minute…', '');
    let failure = null;
    try {
      await fetchJson(`${API_BASE}/documents/template_analyze.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version_id: Number(review.versionId), acknowledge_external_processing: true })
      });
    } catch (error) {
      failure = error;
    }
    if (token !== state.reviewSeq || state.review !== review) return;
    review.busy = null;
    // Each analysis needs a fresh acknowledgement.
    ack.checked = false;
    if (!failure) {
      setFeedback(status, 'Analysis complete. Review the suggestions below.', 'success');
      getElement('doctplReviewResult').hidden = true;
      await loadReviewAnalysis();
      return;
    }
    setFeedback(status, analyzeErrorMessage(failure), 'error');
    if (failure.status === 409 && errorCode(failure) === REVIEW_BUSY_CODE) {
      // Keep Analyze disabled for a short time, then re-fetch once. No polling loop.
      review.cooldown = true;
      review.cooldownTimer = window.setTimeout(() => {
        if (token !== state.reviewSeq || state.review !== review) return;
        review.cooldown = false;
        review.cooldownTimer = null;
        loadReviewAnalysis();
      }, REVIEW_BUSY_COOLDOWN_MS);
      updateReviewControls();
      return;
    }
    if (failure.status === 502) {
      await loadReviewAnalysis();
      return;
    }
    updateReviewControls();
  }

  function skippedList(skipped, sentManual) {
    const list = el('ul');
    skipped.forEach(skip => {
      const index = Number(skip && skip.index);
      const label = skipReasonLabel(skip && skip.reason);
      if (sentManual) {
        const edit = sentManual[index];
        list.appendChild(el('li', '', edit ? `${describeManualEdit(edit)}: ${label}` : label));
        return;
      }
      const edit = state.review ? aiEdits().find(item => item.index === index) : null;
      list.appendChild(el('li', '', edit ? `"${edit.text}": ${label}` : label));
    });
    return list;
  }

  /* sentManual: the manual edits in the order they were sent (skip indexes refer to it). */
  function renderApplyResult(data, sentManual) {
    const result = getElement('doctplReviewResult');
    result.replaceChildren();
    const versionNo = Number(data.version_no);
    const heading = el('h4', '', `Created draft v${versionNo}`);
    heading.id = 'doctplReviewResultTitle';
    heading.tabIndex = -1;
    result.appendChild(heading);
    const applied = Number(data.applied) || 0;
    result.appendChild(el('p', '', sentManual
      ? `${applied} edit(s) applied. The original version was not changed.`
      : `${applied} placeholder(s) applied. The original version was not changed.`));

    const skipped = Array.isArray(data.skipped) ? data.skipped : [];
    if (skipped.length) {
      result.appendChild(el('p', '', sentManual ? `${skipped.length} edit(s) were not applied:` : `${skipped.length} suggestion(s) were not applied:`));
      result.appendChild(skippedList(skipped, sentManual));
    }

    const errors = Array.isArray(data.check_errors) ? data.check_errors : [];
    if (data.check_ok) {
      result.appendChild(el('p', 'is-success', 'Template check passed.'));
    } else {
      result.appendChild(el('p', 'is-error', 'Template check found problems. Fix them before this draft can be activated:'));
      const list = el('ul', 'is-error');
      errors.forEach(error => list.appendChild(el('li', '', error)));
      result.appendChild(list);
    }
    result.appendChild(el('p', '', 'The new draft is not active. Test render and activate it from History when it is ready.'));
    const actions = el('div', 'doctpl-review-row');
    const open = el('button', 'btn-action view', `Open v${versionNo} in History`);
    open.type = 'button';
    open.dataset.reviewAction = 'open-history';
    open.dataset.newVersionId = String(Number(data.new_version_id));
    actions.appendChild(open);
    result.appendChild(actions);
    result.hidden = false;
    heading.focus();
  }

  async function runApply() {
    const review = state.review;
    if (!review || review.busy || review.locked || review.stale || review.loading || !review.analysis) return;
    const selections = aiSelections();
    if (!selections.length || manualEdits().length) return;
    const token = state.reviewSeq;
    const status = getElement('doctplReviewApplyStatus');
    review.busy = 'apply';
    updateReviewControls();
    setFeedback(status, 'Creating a new draft version…', '');
    let data = null;
    let failure = null;
    try {
      data = await fetchJson(`${API_BASE}/documents/template_apply_suggestions.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version_id: Number(review.versionId), analysis_id: Number(review.analysis.id), selections })
      });
    } catch (error) {
      failure = error;
    }
    if (token !== state.reviewSeq || state.review !== review) return;
    review.busy = null;
    if (!failure) {
      // Re-applying the same analysis would create duplicate drafts: lock this review.
      review.locked = true;
      setFeedback(status, 'Applied. This review is now locked; open the new draft to continue.', 'success');
      updateReviewControls();
      renderApplyResult(data);
      await reloadQuietly();
      return;
    }
    if (failure.status === 409 || failure.status === 404) {
      await reloadQuietly();
      if (token !== state.reviewSeq || state.review !== review) return;
      await loadReviewAnalysis();
    }
    setFeedback(status, applyErrorMessage(failure), 'error');
    updateReviewControls();
  }

  /* ------------------------------------------------------------------
     Phase 4C: edit the document text of a draft by hand. Works with AI
     off and without an analysis. The paragraph view is plain read-only
     DOM (no contenteditable), so typing, paste and drop cannot change it;
     only the browser selection is read. Offsets sent to the server are
     Unicode code points: Array.from() over the text a DOM Range covers
     converts the browser's UTF-16 offsets.
     ------------------------------------------------------------------ */

  function manualSpanLength(edit) {
    return Array.from(edit.op === 'remove_placeholder' ? edit.placeholderText : edit.text).length;
  }

  function manualOverlaps(a, b) {
    if (a.paragraph !== b.paragraph) return false;
    const aEnd = a.start + manualSpanLength(a);
    const bEnd = b.start + manualSpanLength(b);
    return a.start < bEnd && b.start < aEnd;
  }

  function quoteText(text) {
    return text === '' ? '(nothing)' : `"${text}"`;
  }

  function describeManualEdit(edit) {
    const where = `Paragraph ${edit.paragraph + 1}`;
    if (edit.op === 'placeholder') return `${where}: make "${edit.text}" the placeholder {{${edit.key}}}`;
    if (edit.op === 'replace_text') return `${where}: change "${edit.text}" to ${quoteText(edit.new_text)}`;
    return `${where}: turn {{${edit.key}}} into ${quoteText(edit.new_text)}`;
  }

  /* Exactly the fields the server accepts for each op; never the client-only ones. */
  function manualPayload(edit) {
    if (edit.op === 'placeholder') return { op: 'placeholder', paragraph: edit.paragraph, start: edit.start, text: edit.text, key: edit.key };
    if (edit.op === 'replace_text') return { op: 'replace_text', paragraph: edit.paragraph, start: edit.start, text: edit.text, new_text: edit.new_text };
    return { op: 'remove_placeholder', paragraph: edit.paragraph, start: edit.start, key: edit.key, new_text: edit.new_text };
  }

  /* Same wording and order as the server checks in _manual_edit.php. */
  function manualNewTextProblem(newText) {
    if (Array.from(newText).length > MANUAL_MAX_NEW_TEXT) return 'New text must be at most 500 characters.';
    if (manualHasForbiddenChar(newText)) return 'New text cannot contain tabs, line breaks or control characters.';
    if (newText.includes('{') || newText.includes('}')) return 'New text cannot contain { or }.';
    return '';
  }

  function manualErrorMessage(error) {
    const status = error && error.status;
    const message = error && error.message ? String(error.message) : '';
    if (status === 401) return 'Your session has expired. Sign in again.';
    if (status === 404) return 'This template version no longer exists. Close this window and reload the list.';
    if (status === 409 || status === 422) return message || 'The edits could not be saved.';
    return 'The edits could not be saved. Try again.';
  }

  async function loadManualParagraphs() {
    const review = state.review;
    if (!review) return;
    const token = state.reviewSeq;
    const manual = review.manual;
    manual.loading = true;
    manual.error = '';
    renderManual();
    updateReviewControls();
    let data = null;
    let failure = null;
    try {
      data = await fetchJson(`${API_BASE}/documents/template_paragraphs.php?version_id=${Number(review.versionId)}`);
    } catch (error) {
      failure = error;
    }
    if (token !== state.reviewSeq || state.review !== review) return;
    manual.loading = false;
    manual.selection = null;
    manual.chip = null;
    manual.sha = '';
    manual.paragraphs = [];
    manual.placeholders = [];
    if (failure) {
      manual.error = failure.status === 401
        ? 'Your session has expired. Sign in again.'
        : failure.status === 404
          ? 'This template version no longer exists. Close this window and reload the list.'
          : 'The document text could not be loaded. Close this window and try again.';
    } else if (String(data.status || '') !== 'draft') {
      manual.error = 'Only a draft version can be edited. Close this window and reload the list.';
    } else {
      manual.sha = String(data.sha256 || '');
      manual.paragraphs = (Array.isArray(data.paragraphs) ? data.paragraphs : []).map(row => ({
        index: Number(row && row.index),
        text: String((row && row.text) || ''),
        editable: !!row && row.editable === true,
        reason: String((row && row.reason) || '')
      }));
      manual.placeholders = (Array.isArray(data.placeholders) ? data.placeholders : []).map(row => ({
        paragraph: Number(row && row.paragraph),
        start: Number(row && row.start),
        key: String((row && row.key) || ''),
        text: String((row && row.text) || ''),
        removable: !!row && row.removable === true
      }));
    }
    renderManual();
    updateReviewControls();
  }

  function renderManualParagraphs() {
    const review = state.review;
    const view = getElement('doctplManualParagraphs');
    view.replaceChildren();
    if (!review) return;
    const manual = review.manual;
    if (manual.loading) {
      view.appendChild(el('p', 'doctpl-note', 'Loading the document text…'));
      return;
    }
    if (manual.error) {
      const banner = el('p', 'doctpl-review-banner is-error', manual.error);
      banner.setAttribute('role', 'alert');
      view.appendChild(banner);
      return;
    }
    const visible = manual.paragraphs.filter(paragraph => paragraph.text !== '');
    if (!visible.length) {
      view.appendChild(el('p', 'doctpl-note', 'This document has no text to edit.'));
      return;
    }
    const pending = manualEdits();
    visible.forEach(paragraph => {
      const row = el('div', 'doctpl-manual-para');
      row.dataset.manualPara = String(paragraph.index);
      row.classList.toggle('is-locked', !paragraph.editable);
      row.classList.toggle('has-pending', pending.some(edit => edit.paragraph === paragraph.index));
      const label = el('span', 'doctpl-manual-label', `¶ ${paragraph.index + 1}`);
      label.setAttribute('aria-hidden', 'true');
      row.appendChild(label);

      const text = el('div', 'doctpl-manual-text');
      text.dataset.manualText = String(paragraph.index);
      const chars = Array.from(paragraph.text);
      let cursor = 0;
      manual.placeholders
        .filter(placeholder => placeholder.paragraph === paragraph.index)
        .sort((a, b) => a.start - b.start)
        .forEach(placeholder => {
          if (placeholder.start < cursor) return;
          if (placeholder.start > cursor) text.appendChild(document.createTextNode(chars.slice(cursor, placeholder.start).join('')));
          // The chip shows the placeholder exactly as written, so the paragraph's
          // DOM text stays identical to the server text and offsets line up.
          const chip = el('button', 'doctpl-manual-chip', placeholder.text);
          chip.type = 'button';
          chip.dataset.reviewAction = 'manual-chip';
          chip.dataset.chipParagraph = String(paragraph.index);
          chip.dataset.chipStart = String(placeholder.start);
          chip.dataset.chipRemovable = placeholder.removable && paragraph.editable ? 'true' : 'false';
          chip.classList.toggle('is-pending', pending.some(edit => edit.op === 'remove_placeholder' && edit.paragraph === paragraph.index && edit.start === placeholder.start));
          chip.title = placeholder.removable
            ? `Turn {{${placeholder.key}}} into text`
            : 'Only a placeholder written exactly as {{key}} can be turned into text here.';
          text.appendChild(chip);
          cursor = placeholder.start + Array.from(placeholder.text).length;
        });
      if (cursor < chars.length) text.appendChild(document.createTextNode(chars.slice(cursor).join('')));
      row.appendChild(text);
      if (!paragraph.editable) {
        row.appendChild(el('span', 'doctpl-manual-reason', paragraph.reason === 'text box'
          ? 'Inside a text box. Edit it in Word.'
          : 'Contains a Word field. Edit it in Word.'));
      }
      view.appendChild(row);
    });
  }

  function renderManualPending() {
    const list = getElement('doctplManualPending');
    list.replaceChildren();
    const pending = manualEdits();
    if (!pending.length) {
      list.appendChild(el('li', 'doctpl-review-empty', 'No unsaved edits.'));
      return;
    }
    pending.forEach(edit => {
      const item = el('li', 'doctpl-manual-pending-item');
      item.appendChild(el('span', 'doctpl-manual-pending-text', describeManualEdit(edit)));
      const remove = el('button', 'btn-action view', 'Remove');
      remove.type = 'button';
      remove.dataset.reviewAction = 'manual-unqueue';
      remove.dataset.manualId = String(edit.id);
      remove.setAttribute('aria-label', `Remove edit: ${describeManualEdit(edit)}`);
      item.appendChild(remove);
      list.appendChild(item);
    });
  }

  function renderManualKeyOptions() {
    const select = getElement('doctplManualKey');
    const current = select.value;
    select.replaceChildren();
    const blank = el('option', '', '— choose a placeholder —');
    blank.value = '';
    select.appendChild(blank);
    reviewFields().forEach(field => {
      const option = el('option', '', `${String(field.label || field.field_key)} ({{${String(field.field_key)}}})`);
      option.value = String(field.field_key);
      select.appendChild(option);
    });
    select.value = reviewKeyAllowed(current) ? current : '';
  }

  function renderManual() {
    if (!state.review) return;
    renderManualParagraphs();
    renderManualPending();
    renderManualKeyOptions();
  }

  function updateManualControls() {
    const review = state.review;
    if (!review) return;
    const manual = review.manual;
    const frozen = !!review.busy || review.locked || manual.loading || !!manual.error;
    const selection = manual.selection;
    const chip = manual.chip;

    getElement('doctplManualSelectionTools').hidden = frozen || !selection;
    getElement('doctplManualSelectionText').textContent = selection
      ? `Selected in paragraph ${selection.paragraph + 1}: "${selection.text}"`
      : '';
    const key = getElement('doctplManualKey');
    key.disabled = frozen;
    getElement('doctplManualMakePlaceholder').disabled = frozen || !selection || !key.value;
    getElement('doctplManualNewText').disabled = frozen;
    getElement('doctplManualReplace').disabled = frozen || !selection;

    getElement('doctplManualChipTools').hidden = frozen || !chip;
    getElement('doctplManualChipLabel').textContent = chip
      ? `Turn the placeholder {{${chip.key}}} in paragraph ${chip.paragraph + 1} into text:`
      : '';
    getElement('doctplManualChipText').disabled = frozen;
    getElement('doctplManualRemove').disabled = frozen || !chip;

    getElement('doctplManualParagraphs').querySelectorAll('.doctpl-manual-chip').forEach(button => {
      button.disabled = frozen || button.dataset.chipRemovable !== 'true';
      button.classList.toggle('is-active', !!chip && chip.paragraph === Number(button.dataset.chipParagraph) && chip.start === Number(button.dataset.chipStart));
    });

    const pending = manualEdits().length;
    getElement('doctplManualCounter').textContent = `${pending} unsaved edit(s)`;
    getElement('doctplManualPending').querySelectorAll('[data-review-action="manual-unqueue"]').forEach(button => { button.disabled = frozen; });
    const conflict = pending > 0 && selectedEdits().length > 0;
    const save = getElement('doctplManualSave');
    save.disabled = frozen || pending === 0 || conflict;
    save.textContent = review.busy === 'manual' ? 'Saving…' : 'Save manual edits';
    save.setAttribute('aria-busy', review.busy === 'manual' ? 'true' : 'false');
    const conflictNote = getElement('doctplManualConflict');
    conflictNote.textContent = MANUAL_CONFLICT_MESSAGE;
    conflictNote.hidden = !conflict || review.locked;
  }

  function manualTextContainer(node) {
    const element = node && (node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement);
    const container = element ? element.closest('[data-manual-text]') : null;
    return container && getElement('doctplManualParagraphs').contains(container) ? container : null;
  }

  /* Reads the browser selection into review.manual.selection, or says why it cannot be used. */
  function readManualSelection() {
    const review = state.review;
    if (!review || review.busy || review.locked || review.manual.loading || review.manual.error) return;
    const manual = review.manual;
    const view = getElement('doctplManualParagraphs');
    const status = getElement('doctplManualSelectionStatus');
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    if (!view.contains(range.startContainer) && !view.contains(range.endContainer)) return;
    if (selection.isCollapsed) {
      manual.selection = null;
      setFeedback(status, '', '');
      updateManualControls();
      return;
    }
    manual.selection = null;
    manual.chip = null;
    const refuse = message => {
      setFeedback(status, message, 'error');
      updateManualControls();
    };
    const startText = manualTextContainer(range.startContainer);
    const endText = manualTextContainer(range.endContainer);
    if (!startText || startText !== endText) {
      refuse('Select text inside one paragraph.');
      return;
    }
    const paragraph = manual.paragraphs.find(row => row.index === Number(startText.dataset.manualText));
    if (!paragraph || !paragraph.editable) {
      refuse('This paragraph cannot be edited here. Edit it in Word.');
      return;
    }
    if ([...startText.querySelectorAll('.doctpl-manual-chip')].some(chip => range.intersectsNode(chip))) {
      refuse('A selection cannot include a placeholder. Click the placeholder to turn it into text.');
      return;
    }
    // UTF-16 DOM offsets -> code points: count the code points before the selection.
    const prefix = document.createRange();
    prefix.selectNodeContents(startText);
    prefix.setEnd(range.startContainer, range.startOffset);
    const start = Array.from(prefix.toString()).length;
    const selected = range.toString();
    const length = Array.from(selected).length;
    const text = Array.from(paragraph.text).slice(start, start + length).join('');
    if (!length || text !== selected) {
      refuse('This selection cannot be used. Select the text again.');
      return;
    }
    manual.selection = { paragraph: paragraph.index, start, text };
    setFeedback(status, '', '');
    updateManualControls();
  }

  function selectManualChip(button) {
    const review = state.review;
    if (!review || review.busy || review.locked || button.dataset.chipRemovable !== 'true') return;
    const paragraph = Number(button.dataset.chipParagraph);
    const start = Number(button.dataset.chipStart);
    const placeholder = review.manual.placeholders.find(row => row.paragraph === paragraph && row.start === start && row.removable);
    if (!placeholder) return;
    window.getSelection()?.removeAllRanges();
    review.manual.selection = null;
    review.manual.chip = { paragraph, start, key: placeholder.key, text: placeholder.text };
    setFeedback(getElement('doctplManualSelectionStatus'), '', '');
    updateManualControls();
    getElement('doctplManualChipText').focus();
  }

  /* Adds one pending manual edit after the client-side checks. */
  function queueManualEdit(edit) {
    const review = state.review;
    const status = getElement('doctplManualSelectionStatus');
    const pending = manualEdits();
    if (pending.length >= MANUAL_MAX_EDITS) {
      setFeedback(status, 'Save at most 40 edits at a time.', 'error');
      return;
    }
    if (pending.some(other => manualOverlaps(edit, other))) {
      setFeedback(status, 'This overlaps an edit that is already pending. Remove that edit first.', 'error');
      return;
    }
    edit.source = 'manual';
    edit.id = review.manual.nextId;
    review.manual.nextId += 1;
    review.edits.push(edit);
    review.manual.selection = null;
    review.manual.chip = null;
    window.getSelection()?.removeAllRanges();
    getElement('doctplManualNewText').value = '';
    getElement('doctplManualChipText').value = '';
    setFeedback(status, 'Edit added. Save to create a new draft version.', 'success');
    renderManual();
    updateReviewControls();
  }

  function addManualPlaceholder() {
    const review = state.review;
    const selection = review && review.manual.selection;
    const key = getElement('doctplManualKey').value;
    const status = getElement('doctplManualSelectionStatus');
    if (!selection) return;
    if (!reviewKeyAllowed(key)) {
      setFeedback(status, 'Choose a placeholder for this document type.', 'error');
      return;
    }
    if (Array.from(selection.text).length > MANUAL_MAX_PLACEHOLDER_SPAN) {
      setFeedback(status, 'Selected text must be 1 to 200 characters.', 'error');
      return;
    }
    queueManualEdit({ op: 'placeholder', paragraph: selection.paragraph, start: selection.start, text: selection.text, key });
  }

  function addManualReplace() {
    const review = state.review;
    const selection = review && review.manual.selection;
    const newText = getElement('doctplManualNewText').value;
    const status = getElement('doctplManualSelectionStatus');
    if (!selection) return;
    if (Array.from(selection.text).length > MANUAL_MAX_TEXT_SPAN) {
      setFeedback(status, 'Selected text must be 1 to 500 characters.', 'error');
      return;
    }
    const problem = manualNewTextProblem(newText);
    if (problem) {
      setFeedback(status, problem, 'error');
      return;
    }
    if (newText === selection.text) {
      setFeedback(status, 'The new text is the same as the old text.', 'error');
      return;
    }
    queueManualEdit({ op: 'replace_text', paragraph: selection.paragraph, start: selection.start, text: selection.text, new_text: newText });
  }

  function addManualRemove() {
    const review = state.review;
    const chip = review && review.manual.chip;
    const newText = getElement('doctplManualChipText').value;
    const status = getElement('doctplManualSelectionStatus');
    if (!chip) return;
    const problem = manualNewTextProblem(newText);
    if (problem) {
      setFeedback(status, problem, 'error');
      return;
    }
    queueManualEdit({ op: 'remove_placeholder', paragraph: chip.paragraph, start: chip.start, key: chip.key, new_text: newText, placeholderText: chip.text });
  }

  function unqueueManualEdit(id) {
    const review = state.review;
    if (!review || review.busy || review.locked) return;
    review.edits = review.edits.filter(edit => !(edit.source === 'manual' && edit.id === id));
    setFeedback(getElement('doctplManualSelectionStatus'), '', '');
    renderManual();
    updateReviewControls();
  }

  async function runManualSave() {
    const review = state.review;
    if (!review || review.busy || review.locked || !review.manual.sha) return;
    const sent = manualEdits();
    if (!sent.length || selectedEdits().length) return;
    const token = state.reviewSeq;
    const status = getElement('doctplManualStatus');
    review.busy = 'manual';
    updateReviewControls();
    setFeedback(status, 'Creating a new draft version…', '');
    let data = null;
    let failure = null;
    try {
      data = await fetchJson(`${API_BASE}/documents/template_manual_edit.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version_id: Number(review.versionId), expected_sha256: review.manual.sha, edits: sent.map(manualPayload) })
      });
    } catch (error) {
      failure = error;
    }
    if (token !== state.reviewSeq || state.review !== review) return;
    review.busy = null;
    if (!failure) {
      // Saving the same edits again would create a duplicate draft: lock this review.
      review.locked = true;
      review.edits = review.edits.filter(edit => edit.source !== 'manual');
      review.manual.selection = null;
      review.manual.chip = null;
      setFeedback(status, 'Saved. This review is now locked; open the new draft to continue.', 'success');
      renderManual();
      updateReviewControls();
      renderApplyResult(data, sent);
      await reloadQuietly();
      return;
    }
    if (failure.status === 409 || failure.status === 404) {
      // The version or its file changed: the pending edits were made against old text.
      review.edits = review.edits.filter(edit => edit.source !== 'manual');
      setFeedback(status, `${manualErrorMessage(failure)} Your unsaved edits were cleared.`, 'error');
      await reloadQuietly();
      if (token !== state.reviewSeq || state.review !== review) return;
      await loadManualParagraphs();
      return;
    }
    setFeedback(status, manualErrorMessage(failure), 'error');
    const skipped = failure.data && Array.isArray(failure.data.skipped) ? failure.data.skipped : [];
    if (skipped.length) status.appendChild(skippedList(skipped, sent));
    updateReviewControls();
  }

  async function openNewVersionInHistory(newVersionId) {
    const review = state.review;
    if (!review) return;
    const typeId = review.typeId;
    closeModal(getElement('doctplReviewModal'));
    await reloadQuietly();
    const type = findType(typeId);
    if (!type) return;
    showHistory(type, newVersionId, document.activeElement);
    const row = getElement('doctplHistoryBody')?.querySelector(`[data-version-row="${Number(newVersionId)}"]`);
    if (row) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  function reviewClick(event) {
    const button = event.target.closest('[data-review-action]');
    if (!button || !event.currentTarget.contains(button) || button.disabled) return;
    const review = state.review;
    if (!review) return;
    const action = button.dataset.reviewAction;
    if (action === 'analyze') runAnalyze();
    if (action === 'apply') runApply();
    if (action === 'open-history') openNewVersionInHistory(Number(button.dataset.newVersionId));
    if (action === 'manual-chip') selectManualChip(button);
    if (action === 'manual-placeholder') addManualPlaceholder();
    if (action === 'manual-replace') addManualReplace();
    if (action === 'manual-remove') addManualRemove();
    if (action === 'manual-unqueue') unqueueManualEdit(Number(button.dataset.manualId));
    if (action === 'manual-chip-cancel') {
      review.manual.chip = null;
      updateManualControls();
    }
    if (action === 'manual-save') runManualSave();
    if ((action === 'select-all' || action === 'clear-all') && !review.busy && !review.locked) {
      aiEdits().forEach(edit => { edit.selected = action === 'select-all' && !!edit.key; });
      updateReviewControls();
    }
  }

  function reviewChange(event) {
    const review = state.review;
    if (!review) return;
    const target = event.target;
    if (target.id === 'doctplReviewAck' || target.id === 'doctplManualKey') {
      updateReviewControls();
      return;
    }
    if (review.busy || review.locked) return;
    if (target.dataset.reviewCheck !== undefined) {
      const edit = aiEdits().find(item => item.index === Number(target.dataset.reviewCheck));
      if (edit) edit.selected = target.checked && !!edit.key;
      updateReviewControls();
      return;
    }
    if (target.dataset.reviewKey !== undefined) {
      const edit = aiEdits().find(item => item.index === Number(target.dataset.reviewKey));
      // Changing the key never changes the row's checkbox state.
      if (edit && (target.value === '' || reviewKeyAllowed(target.value))) edit.key = target.value;
      if (edit && !edit.key) edit.selected = false;
      updateReviewControls();
    }
  }

  function handleAction(button) {
    const action = button.dataset.action;
    const typeId = Number(button.dataset.typeId);
    const versionId = Number(button.dataset.versionId);
    const type = findType(typeId);
    if (action === 'upload' && type) showUpload(type, button);
    if (action === 'add-type') showAddType(button);
    if (action === 'history' && type) showHistory(type, null, button);
    if (action === 'download') downloadVersion(versionId);
    if (action === 'test') testVersion(versionId, button);
    if (action === 'review') {
      const found = findVersion(versionId);
      if (found && found.version.status === 'draft') showReview(found.type, found.version, button);
    }
    if (action === 'activate' && type) {
      showConfirm('This version will be used for all new documents of this type', () => activateVersion(versionId, typeId), button);
    }
    if (action === 'discard') {
      showConfirm('Discard this draft version? It will no longer be available for activation.', () => discardVersion(versionId), button);
    }
    if (action === 'archive' && type) {
      const archived = button.dataset.archived === 'true';
      showConfirm(archived ? 'New requests for this type will be blocked. Existing requests can still be processed.' : 'New requests for this type will be allowed again.', () => archiveType(typeId, archived), button);
    }
    if (action === 'doctpl-close') closeModal(button.closest('.doctpl-modal'));
  }

  function handleClick(event) {
    const actionButton = event.target.closest('[data-action]');
    if (actionButton) {
      // Other parts of the console may use data-action; leave those alone.
      if (!actionButton.closest('#tab-documents, .doctpl-modal')) return;
      event.preventDefault();
      handleAction(actionButton);
      return;
    }
    const overlay = event.target.closest('.doctpl-modal');
    if (overlay && event.target === overlay) closeModal(overlay);
  }

  function handleKeydown(event) {
    const modals = [...document.querySelectorAll('.doctpl-modal.show')];
    if (!modals.length) return;
    const modal = modals[modals.length - 1];
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(modal);
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = modalFocusable(modal);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  async function handleConfirm() {
    const action = state.confirmAction;
    state.confirmAction = null;
    closeModal(getElement('doctplConfirmModal'));
    if (action) await action();
  }

  function initDocumentTemplates() {
    document.addEventListener('click', handleClick);
    document.addEventListener('keydown', handleKeydown);
    getElement('doctplUploadForm')?.addEventListener('submit', submitUpload);
    getElement('doctplSignatoryForm')?.addEventListener('submit', submitSignatory);
    // Add-type listeners are scoped to the add-type modal.
    getElement('doctplTypeForm')?.addEventListener('submit', submitType);
    getElement('doctplTypeName')?.addEventListener('input', typeNameInput);
    getElement('doctplTypeCode')?.addEventListener('input', typeCodeInput);
    getElement('doctplConfirmSubmit')?.addEventListener('click', handleConfirm);
    // Review listeners are scoped to the review modal.
    getElement('doctplReviewModal')?.addEventListener('click', reviewClick);
    getElement('doctplReviewModal')?.addEventListener('change', reviewChange);
    // Selection is read when the mouse or a selection key is released; readManualSelection
    // ignores selections that do not touch the paragraph view (e.g. inside the text input).
    getElement('doctplReviewModal')?.addEventListener('mouseup', readManualSelection);
    getElement('doctplReviewModal')?.addEventListener('keyup', event => {
      if (event.shiftKey || event.key === 'Shift') readManualSelection();
    });
    getElement('doctplSignatoryName')?.addEventListener('input', event => {
      state.signatoryDirty = true;
      renderSignatoryPreview(event.target.value.trim());
    });
    renderSignatory();
  }

  document.addEventListener('DOMContentLoaded', initDocumentTemplates);

  window.DocTemplates = {
    load: loadDocumentTemplates,
    getSignatory
  };
})();

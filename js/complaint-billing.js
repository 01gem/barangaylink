/**
 * BarangayLink - Complaint fee: the "Billing" block of the official complaint detail
 * (complaint revision Phase 10).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * States: not billed (Issue bill, prefilled with the type's default fee),
 * unpaid (Edit, Mark paid with an optional receipt no., Waive with a reason),
 * paid / waived (who, when, receipt or reason). The server enforces every rule
 * (api/complaints/_billing.php); this file only builds requests and shows answers.
 * Money is handled as text: no arithmetic on amounts.
 *
 * Uses these globals from js/official.js (classic script, loaded first):
 * escapeHtml, fetchJson, showToastAdmin, API_BASE, loadComplaints.
 * After a write it redraws the Mediation block (window.Hearings) so the fee
 * gate shows at once.
 *
 * No inline event handlers (CSP). One delegated click listener and one submit
 * listener, attached in initComplaintBilling, called from the top-level
 * initComplaintBillingSafely.
 */
(function () {
  'use strict';

  const STALE_MESSAGE = 'This item was changed by someone else. Reload and try again.';
  const MAX_CENTS = 10000000;
  const STATUS_LABELS = { unpaid: 'Unpaid', paid: 'Paid', waived: 'Waived' };
  const OPEN_STATUSES = ['open', 'investigating'];

  // -------------------------------------------------------------------------
  // Pure helpers (no DOM). Exposed as window.ComplaintBillingUI for the Node test.
  // -------------------------------------------------------------------------

  /** Same rule as the server: 'N' / 'N.N' / 'N.NN' (1-6 digits); range checked through integer cents. */
  function moneyValid(text, allowZero) {
    const m = /^(\d{1,6})(?:\.(\d{1,2}))?$/.exec(String(text ?? ''));
    if (!m) return false;
    const cents = Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0'));
    return cents <= MAX_CENTS && (allowZero ? cents >= 0 : cents >= 1);
  }

  /** '50.00' -> '₱50.00' (display only; anything else -> '₱0.00'). */
  function money(text) {
    const value = String(text ?? '');
    return `₱${/^\d+\.\d{2}$/.test(value) ? value : '0.00'}`;
  }

  /** A type fee above zero. */
  function feeDue(fee) {
    return moneyValid(String(fee ?? ''), true) && !/^0+(\.0+)?$/.test(String(fee));
  }

  /**
   * List-card badge for a mapped complaint {status, bill, typeDefaultFee}:
   * an unpaid bill -> "Unpaid fee"; no bill, a type fee and the complaint open or
   * investigating -> "Fee not issued"; otherwise null.
   */
  function feeBadge(c) {
    if (!c) return null;
    if (c.bill && c.bill.status === 'unpaid') return { kind: 'unpaid', label: 'Unpaid fee' };
    if (!c.bill && feeDue(c.typeDefaultFee) && OPEN_STATUSES.includes(String(c.status))) return { kind: 'not-issued', label: 'Fee not issued' };
    return null;
  }

  function issuePayload(complaintId, form) {
    const body = { complaint_id: Number(complaintId), amount: String(form.amount ?? '').trim() };
    const note = String(form.billing_note ?? '').trim();
    if (note !== '') body.billing_note = note;
    return body;
  }

  /** The note is always sent: '' clears it. */
  function updatePayload(complaintId, bill, form) {
    return {
      complaint_id: Number(complaintId),
      version: Number(bill.version),
      amount: String(form.amount ?? '').trim(),
      billing_note: String(form.billing_note ?? '').trim()
    };
  }

  function paidPayload(complaintId, bill, receipt) {
    const body = { complaint_id: Number(complaintId), version: Number(bill.version) };
    const text = String(receipt ?? '').trim();
    if (text !== '') body.receipt_no = text;
    return body;
  }

  function waivePayload(complaintId, bill, reason) {
    return { complaint_id: Number(complaintId), version: Number(bill.version), reason: String(reason ?? '').trim() };
  }

  function isStale(error) {
    return !!error && error.status === 409 && error.message === STALE_MESSAGE;
  }

  function dateText(stamp) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(stamp || ''));
    if (!m) return '';
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${months[Number(m[2]) - 1]} ${Number(m[3])}, ${m[1]}`;
  }

  function formHtml(mode, data) {
    const bill = data.bill || {};
    if (mode === 'issue' || mode === 'edit') {
      const amount = mode === 'issue' ? (feeDue(data.default_fee) ? data.default_fee : '') : bill.amount;
      const note = mode === 'issue' ? '' : (bill.billing_note || '');
      return `<form class="billing-form" data-bl-form="${mode}" novalidate>
        <label class="complaint-field">Amount (₱)<input name="amount" type="text" inputmode="decimal" maxlength="9" autocomplete="off" value="${escapeHtml(amount || '')}" required /></label>
        <label class="complaint-field">Note for the complainant (optional)<input name="billing_note" type="text" maxlength="255" autocomplete="off" value="${escapeHtml(note)}" /></label>
        <div class="complaint-control-row"><button type="submit" class="complaint-btn">${mode === 'issue' ? 'Issue bill' : 'Save'}</button>
          <button type="button" class="complaint-btn complaint-btn-ghost" data-bl-action="cancel">Cancel</button></div>
      </form>`;
    }
    if (mode === 'paid') {
      return `<form class="billing-form" data-bl-form="paid" novalidate>
        <label class="complaint-field">Receipt no. (optional)<input name="receipt_no" type="text" maxlength="50" autocomplete="off" /></label>
        <div class="complaint-control-row"><button type="submit" class="complaint-btn">Confirm paid</button>
          <button type="button" class="complaint-btn complaint-btn-ghost" data-bl-action="cancel">Cancel</button></div>
      </form>`;
    }
    if (mode === 'waive') {
      return `<form class="billing-form" data-bl-form="waive" novalidate>
        <label class="complaint-field">Reason (5 to 500 characters, officials only)<textarea name="reason" rows="3" maxlength="500" required></textarea></label>
        <div class="complaint-control-row"><button type="submit" class="complaint-btn">Confirm waive</button>
          <button type="button" class="complaint-btn complaint-btn-ghost" data-bl-action="cancel">Cancel</button></div>
      </form>`;
    }
    return '';
  }

  /** The block for bill_get.php data. mode: null | 'issue' | 'edit' | 'paid' | 'waive'. Server text escaped. */
  function blockHtml(data, mode, message) {
    const bill = data && data.bill ? data.bill : null;
    const fee = data ? data.default_fee : '0.00';
    const parts = [];
    if (!bill) {
      parts.push(feeDue(fee)
        ? `<p class="complaint-meta">Fee for this type: <strong>${escapeHtml(money(fee))}</strong> — not issued yet.</p>`
        : '<p class="complaint-meta">No fee for this type.</p>');
      if (data && data.can_issue && mode !== 'issue') {
        parts.push(`<div class="complaint-control-row"><button type="button" class="complaint-btn${feeDue(fee) ? '' : ' complaint-btn-ghost'}" data-bl-action="issue">${feeDue(fee) ? 'Issue bill' : 'Issue bill (optional)'}</button></div>`);
      }
    } else {
      const status = String(bill.status);
      parts.push(`<p><strong>${escapeHtml(money(bill.amount))}</strong> <span class="billing-status is-${escapeHtml(status)}">${escapeHtml(STATUS_LABELS[status] || status)}</span></p>`);
      if (bill.billing_note) parts.push(`<p class="complaint-meta">Note: ${escapeHtml(bill.billing_note)}</p>`);
      parts.push(`<p class="complaint-meta">Issued ${escapeHtml(dateText(bill.issued_at))}${bill.issued_by_name ? ` by ${escapeHtml(bill.issued_by_name)}` : ''}</p>`);
      if (status !== 'unpaid') {
        parts.push(`<p class="complaint-meta">${status === 'paid' ? 'Paid' : 'Waived'} ${escapeHtml(dateText(bill.settled_at))}${bill.settled_by_name ? ` by ${escapeHtml(bill.settled_by_name)}` : ''}`
          + `${status === 'paid' && bill.receipt_no ? ` · Receipt ${escapeHtml(bill.receipt_no)}` : ''}</p>`);
        if (status === 'waived' && bill.waive_reason) parts.push(`<p class="complaint-meta">Reason: ${escapeHtml(bill.waive_reason)}</p>`);
      } else if (!mode) {
        const editable = data.complaint && OPEN_STATUSES.includes(String(data.complaint.status));
        parts.push(`<div class="complaint-control-row">
          ${editable ? '<button type="button" class="complaint-btn complaint-btn-ghost" data-bl-action="edit">Edit</button>' : ''}
          <button type="button" class="complaint-btn" data-bl-action="paid">Mark paid</button>
          <button type="button" class="complaint-btn complaint-btn-ghost" data-bl-action="waive">Waive</button></div>`);
      }
    }
    if (mode) parts.push(formHtml(mode, data || {}));
    if (data && data.blocker) parts.push(`<p class="billing-gate" role="note">${escapeHtml(data.blocker)}</p>`);
    parts.push(`<p class="billing-error" role="alert"${message ? '' : ' hidden'}>${escapeHtml(message || '')}</p>`);
    return `<section class="complaint-overview-section billing-block" data-complaint-billing><h4>Billing</h4>${parts.join('')}</section>`;
  }

  // -------------------------------------------------------------------------
  // DOM
  // -------------------------------------------------------------------------

  let blockSeq = 0;
  const state = { complaintId: null, slot: null, data: null, mode: null, busy: false };

  function toast(title, body) {
    showToastAdmin(escapeHtml(title), escapeHtml(body));
  }

  function draw(message) {
    if (!state.slot || !state.data) return;
    state.slot.innerHTML = blockHtml(state.data, state.mode, message);
    const field = state.slot.querySelector('[data-bl-form] input, [data-bl-form] textarea');
    if (field) field.focus();
  }

  /** Draws the block into slot (inside the complaint detail). c: the mapped complaint. */
  async function renderBlock(c, slot) {
    if (!slot || !c) return;
    const token = String(++blockSeq);
    slot.dataset.billingToken = token;
    state.complaintId = Number(c.id);
    state.slot = slot;
    state.mode = null;
    slot.innerHTML = '<section class="complaint-overview-section billing-block"><h4>Billing</h4><p class="complaint-meta">Loading fee…</p></section>';
    try {
      const data = await fetchJson(`${API_BASE}/complaints/bill_get.php?complaint_id=${encodeURIComponent(Number(c.id))}`);
      if (slot.dataset.billingToken !== token) return;
      state.data = data;
      draw('');
    } catch (error) {
      if (slot.dataset.billingToken !== token) return;
      state.data = null;
      slot.innerHTML = `<section class="complaint-overview-section billing-block"><h4>Billing</h4><p class="complaint-meta">${escapeHtml(error.message || 'Billing is unavailable.')}</p></section>`;
    }
  }

  /** After a write: this block, the Mediation block (fee gate) and the complaints list. */
  async function afterWrite(complaintId) {
    const box = document.getElementById('actionModalBox');
    const open = box && Number(box.dataset.complaintId) === Number(complaintId);
    const jobs = [];
    if (open) {
      const slot = box.querySelector('[data-billing-slot]');
      if (slot) jobs.push(renderBlock({ id: complaintId }, slot));
      const mediation = box.querySelector('[data-mediation-slot]');
      if (mediation && window.Hearings) jobs.push(window.Hearings.renderComplaintBlock({ id: complaintId }, mediation));
    }
    if (typeof loadComplaints === 'function') jobs.push(Promise.resolve().then(() => loadComplaints()).catch(() => null));
    await Promise.allSettled(jobs);
  }

  async function submit(form) {
    if (state.busy || !state.data) return;
    const mode = form.dataset.blForm;
    const values = Object.fromEntries(new FormData(form).entries());
    const complaintId = state.complaintId;
    const bill = state.data.bill || {};
    let endpoint; let body; let done;
    if (mode === 'issue' || mode === 'edit') {
      if (!moneyValid(String(values.amount || '').trim(), false)) { draw('Enter an amount from 0.01 to 100,000.00.'); return; }
      endpoint = mode === 'issue' ? 'bill_issue.php' : 'bill_update.php';
      body = mode === 'issue' ? issuePayload(complaintId, values) : updatePayload(complaintId, bill, values);
      done = mode === 'issue' ? 'Fee issued.' : 'Fee updated.';
    } else if (mode === 'paid') {
      endpoint = 'bill_mark_paid.php'; body = paidPayload(complaintId, bill, values.receipt_no); done = 'Fee marked paid.';
    } else if (mode === 'waive') {
      const reason = String(values.reason || '').trim();
      if (reason.length < 5 || reason.length > 500) { draw('Enter a reason (5 to 500 characters).'); return; }
      endpoint = 'bill_waive.php'; body = waivePayload(complaintId, bill, reason); done = 'Fee waived.';
    } else return;
    state.busy = true;
    form.querySelectorAll('button').forEach(b => { b.disabled = true; });
    try {
      await fetchJson(`${API_BASE}/complaints/${endpoint}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      toast('Billing', done);
      state.busy = false;
      await afterWrite(complaintId);
    } catch (error) {
      state.busy = false;
      if (isStale(error)) {
        await afterWrite(complaintId);
        toast('Billing', STALE_MESSAGE);
        return;
      }
      draw(error.message || 'The fee could not be saved.');
    }
  }

  function handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    const button = target ? target.closest('[data-bl-action]') : null;
    if (!button || button.disabled || !button.closest('[data-billing-slot]') || state.busy) return;
    const action = button.dataset.blAction;
    state.mode = action === 'cancel' ? null : action;
    draw('');
  }

  function handleSubmit(event) {
    const form = event.target instanceof Element ? event.target.closest('[data-bl-form]') : null;
    if (!form || !form.closest('[data-billing-slot]')) return;
    event.preventDefault();
    submit(form);
  }

  function initComplaintBilling() {
    document.addEventListener('click', handleClick);
    document.addEventListener('submit', handleSubmit);
  }

  /* Top-level and guarded: a failure here must never stop js/official.js. */
  function initComplaintBillingSafely() {
    try {
      initComplaintBilling();
    } catch (error) {
      if (window.console) window.console.error('Billing failed to start:', error);
    }
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initComplaintBillingSafely);
    else initComplaintBillingSafely();
  }

  window.ComplaintBilling = { renderBlock };
  window.ComplaintBillingUI = {
    moneyValid, money, feeDue, feeBadge, issuePayload, updatePayload, paidPayload, waivePayload, isStale, blockHtml, dateText, STATUS_LABELS
  };
})();

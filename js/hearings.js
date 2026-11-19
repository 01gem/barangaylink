/**
 * BarangayLink - Mediation calendar (complaint revision Phase 6).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * The "Mediation Calendar" tab (month grid Mon-Sun, "Needs recording" list),
 * the session window (attendance, outcome, summary, notes, reschedule, cancel),
 * the schedule / reschedule window (live check through api/hearings/check.php),
 * and the "Mediation" block of the official complaint detail.
 * Phase 7: the session window's "Summons" section (generate / regenerate,
 * open PDF, download DOCX through api/hearings/summons_*.php) and the
 * "Summons: x of y issued" count on scheduled sessions of the block.
 * The server enforces every rule; this file only builds requests and shows answers.
 *
 * Times are Asia/Manila local strings 'YYYY-MM-DD HH:MM:SS' from the server.
 * They are parsed as text, never through the browser's time zone, and "now"
 * is the server's `now` from the last answer.
 *
 * Uses these globals from js/official.js (classic script, loaded first):
 * escapeHtml, fetchJson, showToastAdmin, API_BASE, loadComplaints,
 * syncOpenComplaintAfterHearing. No second escape helper.
 *
 * No inline event handlers (CSP). One delegated click listener (data-hr-action),
 * one input/change listener and one keydown listener, attached in
 * initHearings, called from the top-level initHearingsSafely.
 */
(function () {
  'use strict';

  const STALE_MESSAGE = 'This item was changed by someone else. Reload and try again.';
  const CHECK_DELAY_MS = 400;
  const DEFAULT_VENUE = 'Barangay Hall';
  const DEFAULT_DURATION = 60;
  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const MONTHS_SHORT = MONTHS.map(name => name.slice(0, 3));
  const DAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const STATUS_LABELS = { scheduled: 'Scheduled', completed: 'Completed', cancelled: 'Cancelled', rescheduled: 'Rescheduled' };
  const OUTCOME_LABELS = { settled: 'Settled', for_follow_up: 'For follow-up', referred: 'Referred', no_agreement: 'No agreement' };
  const ATTENDANCE_LABELS = { not_recorded: 'Not recorded', present: 'Present', absent: 'Absent', excused: 'Excused' };
  const ROLE_LABELS = { complainant: 'Complainant', respondent: 'Respondent' };
  const CONFLICT_LABELS = { official: 'same presiding official', venue: 'same venue', party: 'a party is in that session' };

  // -------------------------------------------------------------------------
  // Pure helpers (no DOM). Exposed as window.HearingsUI for the Node test.
  // -------------------------------------------------------------------------

  const pad2 = n => String(n).padStart(2, '0');
  const ymd = (y, m, d) => `${y}-${pad2(m + 1)}-${pad2(d)}`;

  /**
   * Weeks of a month view, Monday first. month is 0-11.
   * @returns {Array<Array<{date:string, day:number, inMonth:boolean}>>}
   */
  function monthGrid(year, month) {
    const first = new Date(Date.UTC(year, month, 1));
    const offset = (first.getUTCDay() + 6) % 7; // Monday = 0
    const days = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
    const weeks = Math.ceil((offset + days) / 7);
    const grid = [];
    for (let w = 0; w < weeks; w++) {
      const week = [];
      for (let i = 0; i < 7; i++) {
        const d = new Date(Date.UTC(year, month, 1 - offset + w * 7 + i));
        week.push({
          date: ymd(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()),
          day: d.getUTCDate(),
          inMonth: d.getUTCMonth() === month && d.getUTCFullYear() === year
        });
      }
      grid.push(week);
    }
    return grid;
  }

  function gridRange(grid) {
    const last = grid[grid.length - 1];
    return { from: grid[0][0].date, to: last[last.length - 1].date };
  }

  /** 32 lower-case hex characters from crypto.getRandomValues. */
  function newRequestKey(cryptoObject) {
    const source = cryptoObject || window.crypto;
    const bytes = new Uint8Array(16);
    source.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  }

  /** Start times 07:00 .. 18:45 in 15-minute steps. */
  function timeOptions() {
    const out = [];
    for (let minutes = 7 * 60; minutes <= 18 * 60 + 45; minutes += 15) out.push(`${pad2(Math.floor(minutes / 60))}:${pad2(minutes % 60)}`);
    return out;
  }

  function durationOptions() {
    const out = [];
    for (let minutes = 15; minutes <= 240; minutes += 15) out.push(minutes);
    return out;
  }

  /** 'YYYY-MM-DD HH:MM[:SS]' -> parts, or null. */
  function parseStamp(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/.exec(String(value || ''));
    if (!match) return null;
    return { y: +match[1], m: +match[2] - 1, d: +match[3], h: +match[4], mi: +match[5] };
  }

  function clock(h, mi) {
    const suffix = h < 12 ? 'AM' : 'PM';
    return `${h % 12 === 0 ? 12 : h % 12}:${pad2(mi)} ${suffix}`;
  }

  function dayLabel(p) {
    const weekday = DAYS_SHORT[new Date(Date.UTC(p.y, p.m, p.d)).getUTCDay()];
    return `${weekday}, ${MONTHS_SHORT[p.m]} ${p.d}, ${p.y}`;
  }

  /** "Sat, Nov 28, 2026 · 9:00 AM – 10:00 AM" */
  function formatRange(startsAt, endsAt) {
    const s = parseStamp(startsAt);
    const e = parseStamp(endsAt);
    if (!s) return '';
    return `${dayLabel(s)} · ${clock(s.h, s.mi)}${e ? ` – ${clock(e.h, e.mi)}` : ''}`;
  }

  function chipTime(startsAt) {
    const s = parseStamp(startsAt);
    return s ? clock(s.h, s.mi) : '';
  }

  function chipClass(hearing) {
    const status = STATUS_LABELS[hearing.status] ? hearing.status : 'scheduled';
    return `hearing-chip is-${status}${hearing.needs_recording ? ' needs-recording' : ''}`;
  }

  /** Recording opens once the session has started (server rule; strings compare in time order). */
  function canRecord(session, now) {
    return !!session && session.status === 'scheduled' && typeof now === 'string' && String(session.starts_at) <= now;
  }

  /** Default date for a new session: tomorrow (server date), Monday if that is a Sunday. */
  function defaultDate(now) {
    const p = parseStamp(now);
    if (!p) return '';
    const d = new Date(Date.UTC(p.y, p.m, p.d + 1));
    if (d.getUTCDay() === 0) d.setUTCDate(d.getUTCDate() + 1);
    return ymd(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
  }

  /** Body for schedule.php / reschedule.php from the form values. */
  function buildSchedulePayload(mode, values, context) {
    const body = {
      date: String(values.date || ''),
      start_time: String(values.start_time || ''),
      duration_minutes: Number(values.duration_minutes),
      venue: String(values.venue || '').trim(),
      presiding_official_id: Number(values.presiding_official_id),
      request_key: context.requestKey
    };
    if (mode === 'reschedule') {
      return { hearing_id: Number(context.hearingId), version: Number(context.version), ...body, reason: String(values.reason || '').trim() };
    }
    return { complaint_id: Number(context.complaintId), ...body };
  }

  /** Body for record.php: only the attendance values that changed. */
  function buildRecordPayload(session, values, complete) {
    const attendance = {};
    (session.attendance || []).forEach(row => {
      const next = values.attendance[row.party_id];
      if (next && next !== row.status) attendance[row.party_id] = next;
    });
    return {
      hearing_id: Number(session.id),
      version: Number(session.version),
      attendance,
      outcome: values.outcome || null,
      public_summary: String(values.public_summary || '').trim() || null,
      private_notes: String(values.private_notes || '').trim() || null,
      complete: complete === true
    };
  }

  const isStale = error => !!error && error.status === 409 && error.message === STALE_MESSAGE;

  // Summons (complaint revision Phase 7) -------------------------------------

  const SUMMONS_CONFIRM = 'This replaces the current copy; the old copy stays in the history.';

  /** The Summons section is shown only for a scheduled session that has not started (server `now`). */
  function summonsVisible(s, now) {
    return !!s && s.status === 'scheduled' && String(s.starts_at || '') > String(now || '');
  }

  /**
   * One respondent row of summons_list.php -> what the window shows.
   * buttons: 'generate' | 'regenerate' (only when the server allows it), 'open-pdf', 'download-docx' (when issued).
   */
  function summonsRowState(row) {
    const current = row && row.current ? row.current : null;
    const superseded = Number(row && row.superseded_count) || 0;
    const parts = [current ? `Issued ${formatRange(current.generated_at)}` : 'Not issued'];
    if (superseded > 0) parts.push(`Superseded \u00d7${superseded}`);
    const buttons = [];
    if (row && row.can_generate === true) buttons.push(current ? 'regenerate' : 'generate');
    if (current) buttons.push('open-pdf', 'download-docx');
    return { label: parts.join(' \u00b7 '), buttons, reason: row && row.can_generate !== true ? String(row.reason || '') : '' };
  }

  /** The Summons section body for summons_list.php data. Server text escaped. */
  function summonsSectionHtml(data) {
    const list = data && Array.isArray(data.respondents) ? data.respondents : [];
    const blocker = data && data.blocker ? String(data.blocker) : '';
    const rows = list.map(row => {
      const state = summonsRowState(row);
      const id = Number(row.party_id);
      const current = row.current || {};
      const html = {
        generate: `<button type="button" class="btn-action process" data-hr-action="summons-generate" data-party-id="${id}">Generate</button>`,
        regenerate: `<button type="button" class="btn-action process" data-hr-action="summons-generate" data-party-id="${id}" data-replace="1">Regenerate</button>`,
        'open-pdf': `<a class="btn-action view" href="${escapeHtml(current.view_url || '')}" target="_blank" rel="noopener">Open PDF</a>`,
        'download-docx': `<a class="btn-action view" href="${escapeHtml(current.download_url || '')}" download>Download DOCX</a>`
      };
      const reason = state.reason && state.reason !== blocker ? `<div class="complaint-meta">${escapeHtml(state.reason)}</div>` : '';
      return `<tr data-summons-party="${id}">
        <td>${escapeHtml(row.full_name || '')}</td>
        <td>${escapeHtml(state.label)}${reason}</td>
        <td><div class="summons-actions">${state.buttons.map(key => html[key]).join(' ')}</div></td>
      </tr>`;
    }).join('');
    return `<h4 class="hearing-subhead">Summons</h4>
      ${blocker ? `<p class="doctpl-note">${escapeHtml(blocker)}</p>` : ''}
      ${rows ? `<div class="table-wrap"><table class="data-table summons-table"><thead><tr><th>Respondent</th><th>Status</th><th>Summons</th></tr></thead><tbody>${rows}</tbody></table></div>`
        : '<p class="complaint-meta">No respondents on this session.</p>'}`;
  }

  /** "Summons: x of y issued" for a scheduled session of for_complaint.php, or ''. */
  function summonsCountText(s) {
    if (!s || s.status !== 'scheduled' || !s.summons) return '';
    return `Summons: ${Number(s.summons.issued) || 0} of ${Number(s.summons.total) || 0} issued`;
  }

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  const $ = id => document.getElementById(id);
  const openers = new Map();
  const calendar = { year: 0, month: 0, hearings: [], now: '', seq: 0, loaded: false };
  let officials = [];
  let officialsPromise = null;
  const session = { complaintId: 0, hearingId: 0, data: null, busy: false, cancelOpen: false, summonsSeq: 0, summonsBusy: false };
  const schedule = { mode: 'schedule', complaintId: 0, hearing: null, requestKey: '', timer: null, checkSeq: 0, busy: false, now: '' };
  let blockSeq = 0;

  function toast(title, body) {
    showToastAdmin(escapeHtml(title), escapeHtml(body));
  }

  function setError(id, message) {
    const node = $(id);
    if (!node) return;
    node.textContent = message || '';
    node.hidden = !message;
  }

  function isOpen(id) {
    const modal = $(id);
    return !!modal && modal.classList.contains('show');
  }

  function topModal() {
    if (isOpen('hearingScheduleModal')) return $('hearingScheduleModal');
    if (isOpen('hearingSessionModal')) return $('hearingSessionModal');
    return null;
  }

  function focusables(modal) {
    return [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')]
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
    if (modal.id === 'hearingScheduleModal' && schedule.busy) return;
    if (modal.id === 'hearingSessionModal' && session.busy) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if (modal.id === 'hearingScheduleModal' && schedule.timer) { window.clearTimeout(schedule.timer); schedule.timer = null; }
    if (modal.id === 'hearingSessionModal') { session.data = null; session.cancelOpen = false; }
    const opener = openers.get(modal.id);
    openers.delete(modal.id);
    if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
  }

  async function ensureOfficials() {
    if (!officialsPromise) {
      officialsPromise = fetchJson(`${API_BASE}/hearings/officials.php`)
        .then(data => { officials = Array.isArray(data.officials) ? data.officials : []; fillOfficialFilter(); return officials; })
        .catch(error => { officialsPromise = null; throw error; });
    }
    return officialsPromise;
  }

  function fillOfficialFilter() {
    const select = $('hearingOfficialFilter');
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="all">Everyone presiding</option>'
      + officials.map(o => `<option value="${Number(o.id)}">${escapeHtml(o.name)}</option>`).join('');
    if ([...select.options].some(option => option.value === current)) select.value = current;
  }

  // -------------------------------------------------------------------------
  // Calendar tab
  // -------------------------------------------------------------------------

  async function load() {
    if (!calendar.loaded) {
      const today = new Date();
      calendar.year = today.getFullYear();
      calendar.month = today.getMonth();
      calendar.loaded = true;
    }
    await Promise.all([
      ensureOfficials().catch(() => null),
      loadMonth(),
      loadNeeds()
    ]);
  }

  async function loadMonth() {
    const grid = monthGrid(calendar.year, calendar.month);
    const range = gridRange(grid);
    const label = $('hearingMonthLabel');
    if (label) label.textContent = `${MONTHS[calendar.month]} ${calendar.year}`;
    const params = new URLSearchParams({ from: range.from, to: range.to });
    const status = $('hearingStatusFilter')?.value || 'all';
    const official = $('hearingOfficialFilter')?.value || 'all';
    if (status !== 'all') params.set('status', status);
    if (official !== 'all') params.set('official_id', official);
    const seq = ++calendar.seq;
    setError('hearingCalendarError', '');
    try {
      const data = await fetchJson(`${API_BASE}/hearings/list.php?${params}`);
      if (seq !== calendar.seq) return;
      calendar.hearings = Array.isArray(data.hearings) ? data.hearings : [];
      calendar.now = String(data.now || '');
      renderGrid(grid);
    } catch (error) {
      if (seq !== calendar.seq) return;
      calendar.hearings = [];
      renderGrid(grid);
      setError('hearingCalendarError', error.message || 'Could not load the calendar.');
    }
  }

  function renderGrid(grid) {
    const box = $('hearingMonthGrid');
    if (!box) return;
    const byDay = new Map();
    calendar.hearings.forEach(h => {
      const day = String(h.starts_at || '').slice(0, 10);
      if (!byDay.has(day)) byDay.set(day, []);
      byDay.get(day).push(h);
    });
    const today = String(calendar.now).slice(0, 10);
    box.innerHTML = grid.map(week => `<div class="hearing-week" role="row">${week.map(cell => {
      const items = byDay.get(cell.date) || [];
      const chips = items.map((h, index) => `<li${index >= 3 ? ' class="hearing-more-item" hidden' : ''}><button type="button" class="${chipClass(h)}" data-hr-action="open-session"
          data-complaint-id="${Number(h.complaint_id)}" data-hearing-id="${Number(h.id)}"
          title="${escapeHtml(`${STATUS_LABELS[h.status] || ''} · ${h.type_name || ''} · ${h.venue || ''} · ${h.presiding_name || ''}`)}">
          <span class="hearing-chip-time">${escapeHtml(chipTime(h.starts_at))}</span> ${escapeHtml(h.case_no || h.reference_no || '')}</button></li>`).join('');
      const more = items.length > 3 ? `<button type="button" class="hearing-more" data-hr-action="more">+${items.length - 3} more</button>` : '';
      return `<div class="hearing-day${cell.inMonth ? '' : ' is-outside'}${cell.date === today ? ' is-today' : ''}" role="gridcell" aria-label="${escapeHtml(cell.date)}">
        <span class="hearing-daynum">${cell.day}</span>${items.length ? `<ul class="hearing-chips">${chips}</ul>${more}` : ''}</div>`;
    }).join('')}</div>`).join('');
    // Venues for the datalist: most recent first, unique, at most 15.
    const list = $('hearingVenueList');
    if (list) {
      const seen = new Set();
      const venues = [DEFAULT_VENUE];
      calendar.hearings.slice().reverse().forEach(h => {
        const key = String(h.venue || '').trim().toLowerCase();
        if (key && !seen.has(key) && key !== DEFAULT_VENUE.toLowerCase()) { seen.add(key); venues.push(String(h.venue).trim()); }
      });
      list.innerHTML = venues.slice(0, 15).map(v => `<option value="${escapeHtml(v)}"></option>`).join('');
    }
  }

  async function loadNeeds() {
    const wrap = $('hearingNeedsWrap');
    const list = $('hearingNeedsList');
    if (!wrap || !list) return;
    try {
      const data = await fetchJson(`${API_BASE}/hearings/needs_recording.php`);
      const rows = Array.isArray(data.hearings) ? data.hearings : [];
      wrap.hidden = rows.length === 0;
      const count = $('hearingNeedsCount');
      if (count) count.textContent = rows.length ? `(${rows.length})` : '';
      list.innerHTML = rows.map(h => `<li>
        <span>${escapeHtml(formatRange(h.starts_at, h.ends_at))}</span>
        <strong>${escapeHtml(h.case_no || h.reference_no || '')}</strong>
        <span>${escapeHtml(h.type_name || '')}</span>
        <span>${escapeHtml(h.presiding_name || '')}</span>
        <button type="button" class="btn-action" data-hr-action="open-session" data-complaint-id="${Number(h.complaint_id)}" data-hearing-id="${Number(h.id)}">Open</button>
      </li>`).join('');
    } catch (error) {
      wrap.hidden = true;
    }
  }

  function moveMonth(step) {
    if (step === 0) {
      const p = parseStamp(calendar.now);
      const today = p || { y: new Date().getFullYear(), m: new Date().getMonth() };
      calendar.year = today.y;
      calendar.month = today.m;
    } else {
      const d = new Date(Date.UTC(calendar.year, calendar.month + step, 1));
      calendar.year = d.getUTCFullYear();
      calendar.month = d.getUTCMonth();
    }
    loadMonth();
  }

  // -------------------------------------------------------------------------
  // Session window
  // -------------------------------------------------------------------------

  async function fetchComplaintHearings(complaintId) {
    return fetchJson(`${API_BASE}/hearings/for_complaint.php?complaint_id=${encodeURIComponent(Number(complaintId))}`);
  }

  async function openSession(complaintId, hearingId, opener) {
    session.complaintId = Number(complaintId);
    session.hearingId = Number(hearingId);
    session.cancelOpen = false;
    setError('hearingSessionError', '');
    const body = $('hearingSessionBody');
    if (body) body.innerHTML = '<p class="registry-empty">Loading…</p>';
    const actions = $('hearingSessionActions');
    if (actions) actions.innerHTML = '';
    openModal('hearingSessionModal', opener);
    await reloadSession();
    $('hearingSessionModal')?.querySelector('.close-card-btn')?.focus();
  }

  async function reloadSession() {
    try {
      const data = await fetchComplaintHearings(session.complaintId);
      if (!isOpen('hearingSessionModal')) return;
      session.data = data;
      renderSession();
    } catch (error) {
      setError('hearingSessionError', error.message || 'Could not load the session.');
    }
  }

  function currentSession() {
    const sessions = session.data && Array.isArray(session.data.sessions) ? session.data.sessions : [];
    return sessions.find(s => Number(s.id) === session.hearingId) || null;
  }

  function renderSession() {
    const data = session.data;
    const s = currentSession();
    const body = $('hearingSessionBody');
    const actions = $('hearingSessionActions');
    const title = $('hearingSessionTitle');
    if (!body || !actions) return;
    if (!data || !s) { body.innerHTML = '<p class="registry-empty">Session not found.</p>'; actions.innerHTML = ''; return; }
    const complaint = data.complaint || {};
    if (title) title.textContent = `Session ${Number(s.session_no)} · ${complaint.case_no || complaint.reference_no || ''}`;
    const editable = canRecord(s, data.now);
    const scheduled = s.status === 'scheduled';
    const previous = s.rescheduled_from_id
      ? (data.sessions || []).find(item => Number(item.id) === Number(s.rescheduled_from_id)) : null;
    const attendance = (s.attendance || []).map(row => `<tr>
        <td>${escapeHtml(ROLE_LABELS[row.role] || '')}</td>
        <td>${escapeHtml(row.full_name || '')}${row.party_active ? '' : ' <span class="party-badge">removed from complaint</span>'}</td>
        <td><span class="party-badge${row.is_registered ? ' is-registered' : ''}">${row.is_registered ? 'Registered' : 'Not registered'}</span></td>
        <td>${editable
          ? `<select class="form-input" data-hr-attendance="${Number(row.party_id)}" aria-label="${escapeHtml(`Attendance of ${row.full_name || ''}`)}">${Object.keys(ATTENDANCE_LABELS)
            .map(key => `<option value="${key}"${row.status === key ? ' selected' : ''}>${ATTENDANCE_LABELS[key]}</option>`).join('')}</select>`
          : escapeHtml(ATTENDANCE_LABELS[row.status] || '')}</td>
      </tr>`).join('');
    const added = (s.added_after || []).map(p => `<li>${escapeHtml(p.full_name || '')} (${escapeHtml((ROLE_LABELS[p.role] || '').toLowerCase())}) — added after scheduling, not on this session</li>`).join('');
    const summary = s.public_summary || '';
    body.innerHTML = `
      <dl class="hearing-facts">
        <div><dt>Case no.</dt><dd>${escapeHtml(complaint.case_no || '—')}</dd></div>
        <div><dt>Reference</dt><dd>${escapeHtml(complaint.reference_no || '')}</dd></div>
        <div><dt>Type</dt><dd>${escapeHtml(complaint.type_name || '')}</dd></div>
        <div><dt>When</dt><dd>${escapeHtml(formatRange(s.starts_at, s.ends_at))}</dd></div>
        <div><dt>Venue</dt><dd>${escapeHtml(s.venue || '')}</dd></div>
        <div><dt>Presiding</dt><dd>${escapeHtml(s.presiding_name || '')}</dd></div>
        <div><dt>Status</dt><dd><span class="hearing-status is-${escapeHtml(s.status)}">${escapeHtml(STATUS_LABELS[s.status] || s.status)}</span></dd></div>
        ${s.completed_at ? `<div><dt>Completed</dt><dd>${escapeHtml(formatRange(s.completed_at))}</dd></div>` : ''}
      </dl>
      ${previous ? `<p class="doctpl-note">Moved from session ${Number(previous.session_no)}${previous.change_reason ? ` — reason: ${escapeHtml(previous.change_reason)}` : ''}</p>` : ''}
      ${s.change_reason && s.status !== 'scheduled' ? `<p class="doctpl-note">${s.status === 'cancelled' ? 'Cancelled' : 'Rescheduled'} — reason: ${escapeHtml(s.change_reason)}</p>` : ''}
      <h4 class="hearing-subhead">Attendance</h4>
      ${attendance ? `<div class="table-wrap"><table class="data-table complaint-party-table"><thead><tr><th>Role</th><th>Name</th><th>Status</th><th>Attendance</th></tr></thead><tbody>${attendance}</tbody></table></div>` : '<p class="complaint-meta">No parties on this session.</p>'}
      ${added ? `<ul class="hearing-added">${added}</ul>` : ''}
      ${summonsVisible(s, data.now) ? '<div id="hearingSummonsBox" class="summons-box"><h4 class="hearing-subhead">Summons</h4><p class="complaint-meta">Loading summons…</p></div>' : ''}
      ${scheduled && !editable ? '<p class="doctpl-note">Recording opens when the session starts.</p>' : ''}
      <h4 class="hearing-subhead">Outcome</h4>
      ${editable
        ? `<label class="doctpl-field"><span>Outcome</span><select id="hearingOutcome" class="form-input"><option value="">Choose…</option>${Object.keys(OUTCOME_LABELS)
            .map(key => `<option value="${key}"${s.outcome === key ? ' selected' : ''}>${OUTCOME_LABELS[key]}</option>`).join('')}</select></label>
           <label class="doctpl-field"><span>Public summary (shown to the filer)</span><textarea id="hearingSummary" rows="3" maxlength="1000"></textarea>
             <small class="complaint-meta" id="hearingSummaryCount"></small></label>
           <label class="doctpl-field"><span>Private notes (officials only)</span><textarea id="hearingNotes" rows="3" maxlength="5000"></textarea></label>`
        : `<p><strong>${escapeHtml(s.outcome ? OUTCOME_LABELS[s.outcome] : 'Not recorded')}</strong></p>
           ${summary ? `<p class="complaint-description">${escapeHtml(summary)}</p>` : ''}
           ${s.private_notes ? `<p class="complaint-meta">Private notes</p><p class="complaint-description">${escapeHtml(s.private_notes)}</p>` : ''}`}
      <div id="hearingCancelBox" class="hearing-cancel" ${session.cancelOpen ? '' : 'hidden'}>
        <label class="doctpl-field"><span>Reason for cancelling</span><textarea id="hearingCancelReason" rows="2" maxlength="500"></textarea></label>
        <div class="doctpl-modal-actions"><button type="button" class="btn-cancel-form" data-hr-action="cancel-back">Keep session</button>
          <button type="button" class="btn-submit-form hearing-danger" data-hr-action="cancel-confirm">Cancel session ${Number(s.session_no)}</button></div>
      </div>`;
    if (editable) {
      $('hearingSummary').value = summary;
      $('hearingNotes').value = s.private_notes || '';
      updateSummaryCount();
    }
    actions.innerHTML = `
      <button type="button" class="btn-cancel-form" data-hr-action="close">Close</button>
      ${editable ? '<button type="button" class="btn-cancel-form" data-hr-action="record-save">Save attendance</button>' : ''}
      ${editable ? '<button type="button" class="btn-submit-form" data-hr-action="record-complete">Complete session</button>' : ''}
      ${scheduled ? '<button type="button" class="btn-cancel-form" data-hr-action="reschedule">Reschedule</button>' : ''}
      ${scheduled ? '<button type="button" class="btn-cancel-form hearing-danger" data-hr-action="cancel-open">Cancel session</button>' : ''}`;
    if (summonsVisible(s, data.now)) loadSummons();
    else session.summonsSeq++;
  }

  async function loadSummons() {
    const seq = ++session.summonsSeq;
    const hearingId = session.hearingId;
    try {
      const data = await fetchJson(`${API_BASE}/hearings/summons_list.php?hearing_id=${encodeURIComponent(hearingId)}`);
      const box = $('hearingSummonsBox');
      if (seq !== session.summonsSeq || !box || !isOpen('hearingSessionModal')) return;
      box.innerHTML = summonsSectionHtml(data);
    } catch (error) {
      const box = $('hearingSummonsBox');
      if (seq !== session.summonsSeq || !box) return;
      box.innerHTML = `<h4 class="hearing-subhead">Summons</h4><p class="complaint-meta">${escapeHtml(error.message || 'Summons are unavailable.')}</p>`;
    }
  }

  async function generateSummons(button) {
    if (session.summonsBusy) return;
    if (button.dataset.replace === '1' && !window.confirm(SUMMONS_CONFIRM)) return;
    session.summonsBusy = true;
    setError('hearingSessionError', '');
    const box = $('hearingSummonsBox');
    const buttons = box ? [...box.querySelectorAll('[data-hr-action="summons-generate"]')] : [];
    buttons.forEach(item => { item.disabled = true; });
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="summons-spinner" aria-hidden="true"></span> Generating…';
    const complaintId = session.complaintId;
    try {
      await fetchJson(`${API_BASE}/hearings/summons_generate.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ hearing_id: session.hearingId, party_id: Number(button.dataset.partyId) })
      });
      toast('Summons', 'Summons generated. Open the PDF to print it.');
    } catch (error) {
      setError('hearingSessionError', error.message || 'Summons could not be generated.');
    } finally {
      session.summonsBusy = false;
      await loadSummons();
      const detail = document.getElementById('actionModalBox');
      const slot = detail && Number(detail.dataset.complaintId) === Number(complaintId) ? detail.querySelector('[data-mediation-slot]') : null;
      if (slot) await renderComplaintBlock({ id: complaintId }, slot);
    }
  }

  function updateSummaryCount() {
    const area = $('hearingSummary');
    const count = $('hearingSummaryCount');
    if (area && count) count.textContent = `${Array.from(area.value.trim()).length} / 1000`;
  }

  function sessionValues() {
    const values = { attendance: {}, outcome: $('hearingOutcome')?.value || '', public_summary: $('hearingSummary')?.value || '', private_notes: $('hearingNotes')?.value || '' };
    document.querySelectorAll('#hearingSessionBody [data-hr-attendance]').forEach(select => {
      values.attendance[Number(select.dataset.hrAttendance)] = select.value;
    });
    return values;
  }

  async function sessionWrite(endpoint, body, success) {
    if (session.busy) return;
    session.busy = true;
    setError('hearingSessionError', '');
    const buttons = [...document.querySelectorAll('#hearingSessionModal button')];
    buttons.forEach(button => { button.disabled = true; });
    try {
      await fetchJson(`${API_BASE}/hearings/${endpoint}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      toast('Mediation', success);
      session.cancelOpen = false;
      await afterWrite(session.complaintId);
      await reloadSession();
    } catch (error) {
      if (isStale(error)) {
        toast('Changed by someone else', 'Reloaded.');
        await reloadSession();
      } else {
        setError('hearingSessionError', error.message || 'Could not save.');
      }
    } finally {
      session.busy = false;
      buttons.forEach(button => { if (button.isConnected) button.disabled = false; });
    }
  }

  function record(complete) {
    const s = currentSession();
    if (!s) return;
    const payload = buildRecordPayload(s, sessionValues(), complete);
    sessionWrite('record.php', payload, complete ? 'Session completed.' : 'Attendance saved.');
  }

  function confirmCancel() {
    const s = currentSession();
    if (!s) return;
    const reason = ($('hearingCancelReason')?.value || '').trim();
    sessionWrite('cancel.php', { hearing_id: Number(s.id), version: Number(s.version), reason }, `Session ${Number(s.session_no)} cancelled.`);
  }

  // -------------------------------------------------------------------------
  // Schedule / reschedule window
  // -------------------------------------------------------------------------

  function fillScheduleSelects() {
    const start = $('hearingStart');
    const duration = $('hearingDuration');
    if (start && !start.options.length) {
      start.innerHTML = timeOptions().map(t => {
        const [h, m] = t.split(':').map(Number);
        return `<option value="${t}">${clock(h, m)}</option>`;
      }).join('');
    }
    if (duration && !duration.options.length) {
      duration.innerHTML = durationOptions().map(n => `<option value="${n}">${n} minutes</option>`).join('');
    }
  }

  function fillPresiding(selectedId) {
    const select = $('hearingPresiding');
    if (!select) return;
    select.innerHTML = officials.map(o => `<option value="${Number(o.id)}">${escapeHtml(o.name)}${o.position ? ` — ${escapeHtml(o.position)}` : ''}</option>`).join('')
      || '<option value="">No active officials</option>';
    const wanted = String(selectedId || window.OFFICIAL_ID || '');
    if ([...select.options].some(option => option.value === wanted)) select.value = wanted;
  }

  /**
   * Opens the schedule window. options: {complaintId} for a new session, or
   * {complaintId, hearing} for a reschedule.
   */
  async function openSchedule(complaintId, opener, hearing) {
    schedule.mode = hearing ? 'reschedule' : 'schedule';
    schedule.complaintId = Number(complaintId);
    schedule.hearing = hearing || null;
    schedule.requestKey = newRequestKey();
    schedule.checkSeq++;
    setError('hearingScheduleError', '');
    fillScheduleSelects();
    const check = $('hearingCheck');
    if (check) check.innerHTML = '';
    const title = $('hearingScheduleTitle');
    if (title) title.textContent = hearing ? `Reschedule session ${Number(hearing.session_no)}` : 'Schedule mediation';
    const submit = $('hearingScheduleSubmit');
    if (submit) submit.textContent = hearing ? 'Reschedule session' : 'Schedule session';
    $('hearingReasonWrap').hidden = !hearing;
    $('hearingReason').value = '';
    updateReasonCount();
    openModal('hearingScheduleModal', opener);
    try {
      const [data] = await Promise.all([fetchComplaintHearings(complaintId), ensureOfficials()]);
      schedule.now = String(data.now || '');
      const sub = $('hearingScheduleSub');
      if (sub) sub.textContent = `${data.complaint.reference_no || ''}${data.complaint.case_no ? ` · ${data.complaint.case_no}` : ''} · ${data.complaint.type_name || ''}`;
      const dateInput = $('hearingDate');
      const today = schedule.now.slice(0, 10);
      if (dateInput) dateInput.min = today;
      if (hearing) {
        const s = parseStamp(hearing.starts_at);
        const e = parseStamp(hearing.ends_at);
        dateInput.value = s ? ymd(s.y, s.m, s.d) : defaultDate(schedule.now);
        $('hearingStart').value = s ? `${pad2(s.h)}:${pad2(s.mi)}` : '09:00';
        const minutes = s && e ? (e.h * 60 + e.mi) - (s.h * 60 + s.mi) : DEFAULT_DURATION;
        $('hearingDuration').value = String(minutes);
        $('hearingVenue').value = hearing.venue || DEFAULT_VENUE;
        fillPresiding(hearing.presiding_official_id);
      } else {
        dateInput.value = defaultDate(schedule.now);
        $('hearingStart').value = '09:00';
        $('hearingDuration').value = String(DEFAULT_DURATION);
        $('hearingVenue').value = DEFAULT_VENUE;
        fillPresiding(window.OFFICIAL_ID);
        if (!data.complaint.can_schedule && data.complaint.schedule_blocker) setError('hearingScheduleError', data.complaint.schedule_blocker);
      }
      scheduleCheck();
      $('hearingDate')?.focus();
    } catch (error) {
      setError('hearingScheduleError', error.message || 'Could not prepare the form.');
    }
  }

  function updateReasonCount() {
    const area = $('hearingReason');
    const count = $('hearingReasonCount');
    if (area && count) count.textContent = `${Array.from(area.value.trim()).length} / 500`;
  }

  function scheduleValues() {
    return {
      date: $('hearingDate')?.value || '',
      start_time: $('hearingStart')?.value || '',
      duration_minutes: $('hearingDuration')?.value || '',
      venue: $('hearingVenue')?.value || '',
      presiding_official_id: $('hearingPresiding')?.value || '',
      reason: $('hearingReason')?.value || ''
    };
  }

  function scheduleCheck() {
    if (schedule.timer) window.clearTimeout(schedule.timer);
    schedule.timer = window.setTimeout(runCheck, CHECK_DELAY_MS);
  }

  function conflictText(conflict) {
    return `${conflict.case_no || 'Another case'}, ${formatRange(conflict.starts_at, conflict.ends_at)} (${CONFLICT_LABELS[conflict.reason] || conflict.reason})`;
  }

  function renderCheck(result) {
    const box = $('hearingCheck');
    if (!box) return;
    const problems = Array.isArray(result.problems) ? result.problems : [];
    const conflicts = Array.isArray(result.conflicts) ? result.conflicts : [];
    if (result.ok || (!problems.length && !conflicts.length)) {
      box.className = 'hearing-check is-ok';
      box.innerHTML = '<p><i class="fa-solid fa-check"></i> This time is free.</p>';
      return;
    }
    box.className = 'hearing-check is-warning';
    box.innerHTML = `<ul>${problems.map(p => `<li>${escapeHtml(p.message)}</li>`).join('')}${conflicts
      .map(c => `<li>Conflicts with ${escapeHtml(conflictText(c))}</li>`).join('')}</ul>`;
  }

  async function runCheck() {
    schedule.timer = null;
    if (!isOpen('hearingScheduleModal')) return;
    const values = scheduleValues();
    const params = new URLSearchParams({
      complaint_id: String(schedule.complaintId), date: values.date, start_time: values.start_time,
      duration_minutes: values.duration_minutes, venue: values.venue, presiding_official_id: values.presiding_official_id
    });
    if (schedule.hearing) params.set('exclude_hearing_id', String(Number(schedule.hearing.id)));
    const seq = ++schedule.checkSeq;
    const box = $('hearingCheck');
    if (box) { box.className = 'hearing-check'; box.textContent = 'Checking…'; }
    try {
      const result = await fetchJson(`${API_BASE}/hearings/check.php?${params}`);
      if (seq === schedule.checkSeq) renderCheck(result);
    } catch (error) {
      if (seq === schedule.checkSeq && box) { box.className = 'hearing-check is-warning'; box.textContent = error.message || 'Could not check this time.'; }
    }
  }

  async function submitSchedule(event) {
    event.preventDefault();
    if (schedule.busy) return;
    const context = schedule.hearing
      ? { requestKey: schedule.requestKey, hearingId: schedule.hearing.id, version: schedule.hearing.version }
      : { requestKey: schedule.requestKey, complaintId: schedule.complaintId };
    const payload = buildSchedulePayload(schedule.mode, scheduleValues(), context);
    schedule.busy = true;
    setError('hearingScheduleError', '');
    const submit = $('hearingScheduleSubmit');
    if (submit) submit.disabled = true;
    try {
      const data = await fetchJson(`${API_BASE}/hearings/${schedule.mode === 'reschedule' ? 'reschedule' : 'schedule'}.php`,
        { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      schedule.busy = false;
      const complaintId = schedule.complaintId;
      const hearing = data.hearing || {};
      closeModal($('hearingScheduleModal'));
      toast('Mediation', `${schedule.mode === 'reschedule' ? 'Rescheduled' : 'Scheduled'}: ${data.case_no || ''}, ${formatRange(hearing.starts_at, hearing.ends_at)}.`);
      await afterWrite(complaintId);
      if (isOpen('hearingSessionModal') && hearing.id) {
        session.hearingId = Number(hearing.id);
        await reloadSession();
      }
    } catch (error) {
      if (error.data && Array.isArray(error.data.conflicts)) renderCheck({ ok: false, problems: [], conflicts: error.data.conflicts });
      if (isStale(error)) {
        toast('Changed by someone else', 'Reloaded.');
        closeModal($('hearingScheduleModal'));
        if (isOpen('hearingSessionModal')) await reloadSession();
      } else {
        setError('hearingScheduleError', error.message || 'Could not save.');
      }
    } finally {
      schedule.busy = false;
      if (submit) submit.disabled = false;
    }
  }

  // -------------------------------------------------------------------------
  // Complaint detail "Mediation" block
  // -------------------------------------------------------------------------

  function complaintBlockHtml(data) {
    const complaint = data.complaint || {};
    const sessions = Array.isArray(data.sessions) ? data.sessions : [];
    const rows = sessions.map(s => `<li>
        <span>#${Number(s.session_no)} ${escapeHtml(formatRange(s.starts_at, s.ends_at))} · ${escapeHtml(s.venue || '')}</span>
        <span class="hearing-status is-${escapeHtml(s.status)}">${escapeHtml(STATUS_LABELS[s.status] || s.status)}</span>
        ${summonsCountText(s) ? `<span class="complaint-meta summons-count">${escapeHtml(summonsCountText(s))}</span>` : ''}
        <button type="button" class="complaint-btn complaint-btn-ghost" data-hr-action="open-session" data-complaint-id="${Number(complaint.id)}" data-hearing-id="${Number(s.id)}">Open</button>
      </li>`).join('');
    const disabled = !complaint.can_schedule;
    return `<section class="complaint-overview-section hearing-block" data-complaint-mediation>
      <div class="complaint-parties-head"><h4>Mediation</h4><span class="complaint-meta">Case no. <strong>${escapeHtml(complaint.case_no || '—')}</strong></span></div>
      ${rows ? `<ul class="hearing-session-list">${rows}</ul>` : '<p class="complaint-meta">No mediation sessions yet.</p>'}
      <div class="hearing-block-actions">
        <button type="button" class="complaint-btn" data-hr-action="schedule" data-complaint-id="${Number(complaint.id)}"${disabled ? ' disabled aria-describedby="hearingBlockReason"' : ''}>Schedule mediation</button>
        ${disabled && complaint.schedule_blocker ? `<span id="hearingBlockReason" class="complaint-meta">${escapeHtml(complaint.schedule_blocker)}</span>` : ''}
      </div>
    </section>`;
  }

  /** Draws the block into slot (inside the complaint detail). c: the mapped complaint. */
  async function renderComplaintBlock(c, slot) {
    if (!slot || !c) return;
    const token = String(++blockSeq);
    slot.dataset.hearingToken = token;
    slot.innerHTML = '<section class="complaint-overview-section hearing-block"><h4>Mediation</h4><p class="complaint-meta">Loading mediation…</p></section>';
    try {
      const data = await fetchComplaintHearings(c.id);
      if (slot.dataset.hearingToken !== token) return;
      slot.innerHTML = complaintBlockHtml(data);
    } catch (error) {
      if (slot.dataset.hearingToken !== token) return;
      slot.innerHTML = `<section class="complaint-overview-section hearing-block"><h4>Mediation</h4><p class="complaint-meta">${escapeHtml(error.message || 'Mediation is unavailable.')}</p></section>`;
    }
  }

  /** After any write: calendar, needs list, complaints list, open complaint detail. */
  async function afterWrite(complaintId) {
    const jobs = [];
    if (document.getElementById('tab-hearings')?.classList.contains('active')) jobs.push(loadMonth(), loadNeeds());
    if (typeof loadComplaints === 'function') {
      jobs.push(loadComplaints().then(() => {
        if (typeof syncOpenComplaintAfterHearing === 'function') syncOpenComplaintAfterHearing(complaintId);
      }).catch(() => null));
    }
    await Promise.allSettled(jobs);
    const box = document.getElementById('actionModalBox');
    const slot = box && Number(box.dataset.complaintId) === Number(complaintId) ? box.querySelector('[data-mediation-slot]') : null;
    if (slot) await renderComplaintBlock({ id: complaintId }, slot);
  }

  // -------------------------------------------------------------------------
  // Events
  // -------------------------------------------------------------------------

  function handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const button = target.closest('[data-hr-action]');
    if (!button || button.disabled) return;
    if (!button.closest('#tab-hearings, .hearing-modal, [data-mediation-slot]')) return;
    const action = button.dataset.hrAction;
    if (action === 'close') { closeModal(button.closest('.hearing-modal')); return; }
    if (action === 'prev') { moveMonth(-1); return; }
    if (action === 'next') { moveMonth(1); return; }
    if (action === 'today') { moveMonth(0); return; }
    if (action === 'more') {
      button.closest('.hearing-day')?.querySelectorAll('.hearing-more-item').forEach(item => { item.hidden = false; });
      button.remove();
      return;
    }
    if (action === 'open-session') { openSession(button.dataset.complaintId, button.dataset.hearingId, button); return; }
    if (action === 'schedule') { openSchedule(button.dataset.complaintId, button, null); return; }
    if (action === 'reschedule') { const s = currentSession(); if (s) openSchedule(session.complaintId, button, s); return; }
    if (action === 'record-save') { record(false); return; }
    if (action === 'summons-generate') { generateSummons(button); return; }
    if (action === 'record-complete') { record(true); return; }
    if (action === 'cancel-open') {
      session.cancelOpen = true;
      $('hearingCancelBox').hidden = false;
      $('hearingCancelReason')?.focus();
      return;
    }
    if (action === 'cancel-back') { session.cancelOpen = false; $('hearingCancelBox').hidden = true; return; }
    if (action === 'cancel-confirm') confirmCancel();
  }

  function handleInput(event) {
    const node = event.target;
    if (!(node instanceof Element)) return;
    if (node.id === 'hearingSummary') { updateSummaryCount(); return; }
    if (node.id === 'hearingReason') { updateReasonCount(); return; }
    if (node.id === 'hearingStatusFilter' || node.id === 'hearingOfficialFilter') { if (event.type === 'change') loadMonth(); return; }
    if (node.closest('#hearingScheduleForm') && ['hearingDate', 'hearingStart', 'hearingDuration', 'hearingVenue', 'hearingPresiding'].includes(node.id)) scheduleCheck();
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

  function initHearings() {
    document.addEventListener('click', handleClick);
    document.addEventListener('input', handleInput);
    document.addEventListener('change', handleInput);
    document.addEventListener('keydown', handleKeydown);
    $('hearingScheduleForm')?.addEventListener('submit', submitSchedule);
  }

  /* Top-level and guarded: a failure here must never stop js/official.js. */
  function initHearingsSafely() {
    try {
      initHearings();
    } catch (error) {
      if (window.console) window.console.error('Mediation calendar failed to start:', error);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initHearingsSafely);
  else initHearingsSafely();

  window.Hearings = { load, openSession, openSchedule, renderComplaintBlock };
  window.HearingsUI = {
    monthGrid, gridRange, newRequestKey, timeOptions, durationOptions, parseStamp, formatRange, chipClass,
    canRecord, defaultDate, buildSchedulePayload, buildRecordPayload, isStale, complaintBlockHtml,
    summonsVisible, summonsRowState, summonsSectionHtml, summonsCountText, SUMMONS_CONFIRM,
    STATUS_LABELS, OUTCOME_LABELS
  };
})();

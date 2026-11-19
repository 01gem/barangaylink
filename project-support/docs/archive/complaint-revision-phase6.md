# Complaint revision — Phase 6: mediation calendar

Written 2026-11-16. Code committed as `d0b9b1c`. The real database was migrated on 2026-11-16 (see "Migration on the real database"); the manual checklist passed.

## Purpose

1. Officials (staff or admin) schedule mediation sessions for a complaint directly, with no approval step.
2. The first scheduling gives the complaint a barangay case number `BC-YYYY-NNNN`.
3. Overlaps are refused under a lock:
   - the same presiding official;
   - the same venue;
   - a registered party who is already in another session at that time.
4. Each session records:
   - attendance for each party;
   - an outcome;
   - a public summary (shown to the filer);
   - private notes (officials only).
5. Reschedule and cancel need a reason.
6. Registered parties get an SMS when a session is scheduled, moved or cancelled.
7. Screens:
   - a "Mediation Calendar" tab;
   - a "Mediation" block in the official complaint detail;
   - a "Mediation" section in the resident's complaint detail.

Not in this phase: summons (Phase 7), statistics and AI (Phase 8). The complaint type and party rules were not changed.

## Time rules (read this before touching any time code)

- The PHP CLI and `php -S` run in **UTC** (no `date.timezone`). MySQL `NOW()` uses the system zone, **+08:00**.
- The OTP bug came from mixing the two. The OTP fix keeps OTP expiry entirely in MySQL.
- The hearing module does the opposite. It has one clock, `hearing_now()`, which is `new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))`.
- Every hearing datetime is Manila local time in a `DATETIME`.
- Every comparison and insert in this phase uses `Y-m-d H:i:s` strings from that clock:
  - overlap checks;
  - "needs recording" and "can be recorded";
  - `completed_at`, `created_at`, `updated_at`;
  - the `complaint_updates.created_at` of the timeline rows. That column is a `TIMESTAMP`; the session zone is SYSTEM (+08:00), so the Manila string is stored without a shift.
- There is no `NOW()`, `CURRENT_TIMESTAMP` or `date()` in any Phase 6 statement. The column defaults remain only as a backstop for manual inserts.
- Input:
  - `date` is `Y-m-d`. It is checked with a `/D` regex, `DateTimeImmutable::createFromFormat('!Y-m-d', …, Manila)`, `getLastErrors()` and a round trip, so `2026-02-30` and `2026-11-23\n` are refused.
  - `start_time` is `HH:MM` on the quarter hour.
  - `duration_minutes` is a JSON integer.
- Allowed sessions:
  - start at least 15 minutes after now;
  - Monday to Saturday;
  - start at 07:00 or later and end by 19:00;
  - 15 to 240 minutes, in steps of 15;
  - at most 365 days ahead.
- The browser never converts times. It reads the server strings as text and uses the server's `now` from the last answer.

## Files

| File | Change |
|------|--------|
| `project-support/migrations/20261118_complaint_hearings.sql` | New. Four tables, `complaints.case_no` + unique key, guard row, count report. Safe to run again. |
| `database/database.sql` | The same DDL (`case_no` inside `CREATE TABLE complaints`; the tables after `complaint_parties`). |
| `api/hearings/_hearings.php` | New. All rules, reads, writes, SMS texts. |
| `api/hearings/list.php`, `needs_recording.php`, `for_complaint.php`, `check.php`, `officials.php` | New GET endpoints. |
| `api/hearings/schedule.php`, `reschedule.php`, `cancel.php`, `record.php` | New POST JSON endpoints. |
| `api/complaints/_problem.php` | `ComplaintProblem` takes an optional `array $extra` (the conflict list for a 409). |
| `api/complaints/update_status.php`, `notify_resolved.php` | Resolving with a scheduled session: 409 `Complete or cancel the scheduled mediation first.` Nothing else changed. |
| `api/complaints/list.php` | `case_no` on every row. Residents also get `hearings` (public keys only). |
| `pages/official.php` | Sidebar button, `tab-hearings`, session window, schedule window, `hearings.js` after `complaint-walkin.js`. |
| `js/hearings.js` | New. Calendar tab, session window, schedule/reschedule window, detail block. |
| `js/official.js` | Plaque, `switchTab` hook, `caseNo` mapping, `data-mediation-slot` in the detail, `syncOpenComplaintAfterHearing()`, the detail's key handler steps aside for `.hearing-modal.show`. |
| `js/resident.js` | `caseNo` + `hearings` mapping, `renderComplaintMediationHtml()`. |
| `css/official.css`, `css/resident.css` | One commented Phase 6 block at the end of each. |
| `project-support/tests/complaint-types/run.php` | Phase 6 section (live invariants; seed checks). |
| `project-support/tests/complaint-interface/api-checks.php` | Phase 6 HTTP checks a–n, m2. |
| `project-support/tests/review-2026-10-24/run.php` | Sources the Phase 6 migration on the scratch database. |
| `project-support/tests/complaint-hearings/frontend.js` | New. Node checks for `js/hearings.js` and the resident section. |
| `project-support/docs/complaint-revision-phase5.md` | Step 0: "Rollback" section. |

### Decisions agreed before writing (D1–D13)

1. **D1** `notify_resolved.php` also resolves complaints, so it has the same 409 guard as `update_status.php`.
2. **D2** The server requires quarter-hour start times.
3. **D3** Sessions can be at most 365 days ahead.
4. **D4** `record` with `complete: false` writes an audit row but no timeline note. Completing writes `Mediation session N completed. Outcome: X.`
5. **D5** The first scheduling moves the complaint from open to investigating, as `update_status` does: one timeline row from `open` to `investigating`, the resident-confirmation rule, and the in-app notification. There is no separate "being investigated" SMS; the hearing SMS covers it.
6. **D6** On Windows `php -S` serves one request at a time, so the parallel-request check (m) cannot prove the lock. Check m2 proves it.
7. **D7** A party conflict compares the `resident_id` of this complaint's active registered parties with the other session's parties: its attendance rows plus its complaint's current active parties.
8. **D8** Every Phase 6 time comes from the PHP Manila clock.
9. **D9** The code needs the migration. `complaints/list.php`, `update_status.php` and `notify_resolved.php` read the new column and table, so deploy the code only after migrating.
10. **D10** Complaints marked as duplicates can still be scheduled.
11. **D11** Scheduling starts from the complaint detail; the calendar has no "new" button.
12. **D12** Completing needs every attendance row recorded, including parties removed after scheduling. The window labels them "removed from complaint".
13. **D13** Conflicts travel in `ComplaintProblem::$extra`.

## Schema

```
complaint_case_counters   year SMALLINT PK, last_no INT NOT NULL
complaints                + case_no VARCHAR(20) NULL, UNIQUE uq_complaint_case_no
complaint_hearing_guard   id TINYINT PK; row (1) — the calendar lock
complaint_hearings
  id INT AI PK
  complaint_id INT            FK complaints RESTRICT
  session_no INT              UNIQUE (complaint_id, session_no); reschedules count as new sessions
  starts_at, ends_at DATETIME (Manila)      CHECK ends_at > starts_at
  venue VARCHAR(180), venue_key VARCHAR(180)   lower case, spaces collapsed
  presiding_official_id INT   FK barangay_officials RESTRICT
  status ENUM(scheduled, completed, cancelled, rescheduled)
  outcome ENUM(settled, for_follow_up, referred, no_agreement) NULL
  public_summary VARCHAR(1000) NULL, private_notes TEXT NULL
  rescheduled_from_id INT NULL FK complaint_hearings RESTRICT
  change_reason VARCHAR(500) NULL
  request_key CHAR(32) NULL UNIQUE
  version INT, created_by / updated_by INT FK barangay_officials RESTRICT
  completed_at, created_at, updated_at DATETIME
  KEYS (status, starts_at), (presiding_official_id, status, starts_at), (venue_key, status, starts_at), (complaint_id, status)
complaint_hearing_attendance
  hearing_id FK complaint_hearings CASCADE, party_id FK complaint_parties RESTRICT
  status ENUM(not_recorded, present, absent, excused), PK (hearing_id, party_id)
```

## Rules (`api/hearings/_hearings.php`)

**Write order.** Every write runs in one transaction:
1. `SELECT … FROM complaint_hearing_guard WHERE id = 1 FOR UPDATE`;
2. the `request_key` lookup (`FOR SHARE`);
3. the complaint `FOR UPDATE`;
4. the hearing `FOR UPDATE` + version check;
5. rules;
6. writes;
7. timeline row;
8. commit;
9. audit row;
10. SMS.

**Why the order is safe:**
- Phase 5 party writes lock the complaint, then the party. `update_status` and `notify_resolved` lock the complaint, then read hearings `FOR SHARE`. Locks are always taken in the order guard → complaint → hearing or party, so no deadlock cycle can form.
- Reads made before the complaint lock are locking reads. The read view opens only after the locks, so overlap and party reads see every committed change.

**Schedule:**

| Rule | Status | Message |
|------|--------|---------|
| Complaint resolved | 409 | `Resolved complaints cannot be scheduled.` |
| Another scheduled session on this complaint | 409 | `This complaint already has a scheduled session.` |
| Fewer than one active complainant or one active respondent | 422 | `Add at least one respondent before scheduling mediation.` |
| Presiding official missing or inactive | 422 | `Choose an active official to preside.` |
| Time rules | 422 | See below. |
| Venue not 2–180 characters | 422 | `Enter a venue (2 to 180 characters).` |
| Overlap (scheduled sessions of other complaints, `[start, end)`) | 409 | `This time conflicts with another scheduled session.` + `code: hearing_conflict`, `conflicts: [{hearing_id, case_no, starts_at, ends_at, reason}]`, reason `official` / `venue` / `party`, no names |
| Unknown field | 422 | `Unknown field: <key>.` |
| `request_key` used for another complaint or change | 409 | `This request was already used for another change.` |

Time rule messages (422): `Enter a valid date.`, `Enter a valid start time.`, `Start time must be on the quarter hour.`, `Sessions start at 7:00 AM or later.`, `The session must end by 7:00 PM.`, `Mediation is held Monday to Saturday.`, `Duration must be 15 to 240 minutes, in steps of 15.`, `Choose a time at least 15 minutes from now.`, `Choose a date within the next 12 months.`

- **Idempotent.** The same `request_key` from the same kind of request returns the original session with `idempotent: true`. Nothing is written and no SMS is sent.
- **Case number.** The first session gets a number from:
  ```sql
  INSERT INTO complaint_case_counters (year, last_no) VALUES (?, LAST_INSERT_ID(1))
  ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)
  ```
  - It runs in the same transaction.
  - `YYYY` is the Manila year of the scheduling, not of the session.
  - The number is never reassigned.
- **Attendance.** One `not_recorded` attendance row is created per active party at that moment.

**Reschedule:**
- Only scheduled sessions; `reason` must be 5–500 characters.
- The old session becomes `rescheduled` and keeps its data plus the reason.
- The new session gets `session_no + 1` and `rescheduled_from_id`.
- The same rules apply, with the old session excluded from overlaps.
- Attendance is rebuilt from the current active parties.

**Cancel:** only scheduled sessions; `reason` must be 5–500 characters; the session becomes `cancelled`. The slot is free again.

**Record:**
- Only scheduled sessions whose `starts_at` is not after now. A future session answers 422 `A session can be recorded once it has started.`
- `attendance` is `{party_id: present|absent|excused|not_recorded}`. Only parties on the session are accepted, else 422 `Attendance includes someone who is not on this session.`
- Omitted optional keys keep what is stored; `null` or `""` clears.
- `complete: false` saves only. An identical save answers `changed: false` and leaves the version unchanged.
- `complete: true` needs:
  - no `not_recorded` row (`Record attendance for everyone before completing.`);
  - an outcome (`Choose an outcome.`);
  - a public summary of 10–1000 characters.
  It sets `completed` and `completed_at` from the Manila clock.
- Private notes can be at most 5000 characters. Line breaks are allowed; control and text-direction characters are refused.

**Party changes while a session is scheduled.** They are allowed (Phase 5 rules unchanged). Attendance rows are not changed afterwards: the session window lists new parties as "added after scheduling".

**Timeline notes (residents read them).** They contain no names, reasons or notes:
- `Mediation session 1 scheduled for Mon, Nov 23, 2026, 9:00 AM at Barangay Hall.`
- `Mediation session 1 rescheduled; new session 2 on … at ….`
- `Mediation session 2 cancelled.`
- `Mediation session 2 completed. Outcome: Settled.`

**Audit.** Target type `complaint_hearing`; reference = case number. Actions: `Scheduled mediation`, `Rescheduled mediation`, `Cancelled mediation`, `Recorded mediation`, `Completed mediation`. Details hold ids, session number, start, duration, presiding id, attendance counts and outcome. They never hold reasons, notes or names.

## Endpoints (`api/hearings/`, any official; residents and anonymous callers get 401)

| Endpoint | Method | Request | Response |
|----------|--------|---------|----------|
| `list.php` | GET | `from`, `to` (Y-m-d, at most 62 days apart), `status?`, `official_id?` | `{from, to, now, hearings: [{id, complaint_id, case_no, reference_no, type_name, session_no, starts_at, ends_at, venue, presiding_official_id, presiding_name, status, outcome, party_count, needs_recording, version}]}` |
| `needs_recording.php` | GET | — | `{now, hearings: [same]}`, scheduled sessions with `ends_at` before now |
| `for_complaint.php` | GET | `complaint_id` | `{complaint: {id, reference_no, case_no, status, type_name, can_schedule, schedule_blocker}, now, sessions: [official view + attendance {party_id, role, full_name, is_registered, party_active, status} + added_after]}` |
| `check.php` | GET | schedule fields + `exclude_hearing_id?` | `{ok, problems: [{field, message}], conflicts: [...]}`. Same rule code; no writes. |
| `officials.php` | GET | — | `{officials: [{id, name, position}]}` (active) |
| `schedule.php` | POST JSON | `{complaint_id, date, start_time, duration_minutes, venue, presiding_official_id, request_key}` | `{idempotent, case_no, complaint_status, hearing, sms: [{resident_id, status}]}` |
| `reschedule.php` | POST JSON | `{hearing_id, version, date, start_time, duration_minutes, venue, presiding_official_id, reason, request_key}` | the same + `previous: {id, status, version}` |
| `cancel.php` | POST JSON | `{hearing_id, version, reason}` | `{hearing, sms}` |
| `record.php` | POST JSON | `{hearing_id, version, attendance, outcome?, public_summary?, private_notes?, complete}` | `{changed, hearing}` |

Codes:

| Code | When |
|------|------|
| 401 | Not an official. |
| 404 | Unknown complaint or session. |
| 405 | Wrong method. |
| 409 | Stale version (`This item was changed by someone else. Reload and try again.`), not scheduled, conflicts, resolved, already scheduled, key reused. |
| 415 | Not JSON. |
| 422 | Rule failures. |
| 500 | Database failure; the detail is logged only. |

## SMS

- Sent after the commit to **registered** parties on the session, both complainants and respondents.
- Preference `complaints_enabled`, through `notify_resident` and `sms_log`.
- `sms_log` endpoints: `complaint_hearing_scheduled`, `complaint_hearing_rescheduled`, `complaint_hearing_cancelled`.
- No names. Plain ASCII. At most 160 characters: a long venue is shortened with `...`.

| Event | Text |
|-------|------|
| scheduled | `Brgy. Sampaguita: Mediation for case BC-2026-0001 is set on Sat, Nov 28, 2026, 9:00 AM at Barangay Hall. Please come on time.` |
| rescheduled | `Brgy. Sampaguita: Mediation for case BC-2026-0001 was moved to Sat, Nov 28, 2026, 9:00 AM at Barangay Hall. Please come on time.` |
| cancelled | `Brgy. Sampaguita: Mediation for case BC-2026-0001 on Sat, Nov 28, 2026, 9:00 AM was cancelled. The barangay will contact you.` |

The longest case (5-digit number, `Wed, Nov 25, 2026, 10:45 AM`) is 127 / 130 / 127 characters with "Barangay Hall". A 240-character venue gives 159 / 160 characters.

## Privacy: who sees what

| Data | Officials (`api/hearings/*`) | Filer (`complaints/list.php`) | Other parties | Community board | Timeline |
|------|------|------|------|------|------|
| Case number | yes | yes | — | no | no |
| Date, time, venue, status | yes | yes | SMS only (registered) | no | yes (no names) |
| Outcome, public summary | yes | yes, once completed | no | no | outcome only |
| Presiding official | yes | no | no | no | no |
| Attendance (with names) | yes | no | no | no | no |
| Private notes | yes | no | no | no | no |
| Reschedule / cancel reason | yes | no | no | no | no |
| Conflicts on 409 | case number + times + reason, no names | — | — | — | — |

## Screens

- **Mediation Calendar tab** (sidebar after Complaint Types, all officials):
  - The toolbar has ‹ Today ›, the month name, a status filter and a presiding-official filter.
  - The month grid runs Monday to Sunday. Days outside the month are dimmed and today is outlined.
  - Each session is a chip showing the time and case number. The colour follows the status; a session that needs recording has an amber border. More than 3 chips in a day collapse into "+N more".
  - "Needs recording (n)" sits above the grid when it is not empty.
  - The recent venues feed the datalist in the schedule window.
- **Session window:**
  - Shows the case number, reference, type, time, venue, presiding official and status.
  - Attendance has a select for each party. Removed parties are labelled; parties added after scheduling are listed separately.
  - Outcome select, public summary with a counter, private notes.
  - Buttons:
    - Save attendance and Complete session, once the session has started;
    - Reschedule and Cancel session, while it is scheduled. Cancel opens an inline reason box with a confirmation.
  - A stale 409 shows "Changed by someone else — Reloaded."
- **Schedule / Reschedule window:**
  - Fields: date, start time (07:00–18:45 in 15-minute steps), duration (15–240), venue (default "Barangay Hall", datalist), presiding official (default: the signed-in official).
  - A live `check.php` call runs 400 ms after the last change, guarded by a sequence number, and shows problems and conflicts before submitting.
  - Reschedule adds a reason box with a counter.
  - `request_key` comes from `crypto.getRandomValues`, once per opening, so a retry in the same window is idempotent.
- **Complaint detail, "Mediation" block** (below Parties):
  - Case number (or "—") and the sessions with an Open button.
  - "Schedule mediation" is disabled, with the server's reason, when scheduling is not allowed.
  - After any write: the list reloads; the open detail's status badge, status select and saved snapshot move to the new status (unless the official changed the select); the timeline reloads.
- **Resident detail, "Mediation" section** (only with a case number or sessions): case number, then each session's date and time, venue, status, outcome and public summary.
- **Code rules:**
  - No inline handlers.
  - One delegated click listener (`data-hr-action`), limited to the tab, the hearing windows and the detail slot.
  - One input/change listener and one keydown listener (Escape, focus trap).
  - `escapeHtml`, `fetchJson`, `showToastAdmin` and `API_BASE` come from `official.js`.
  - `initHearingsSafely` runs at the top level, inside try/catch.

## Tests

### Results (2026-11-16)

| Suite | Before Phase 6 | After Phase 6 | Exit |
|-------|---------------:|--------------:|-----:|
| complaint-types, live, **real database** (not migrated) | 85 / 85 | **85 / 86** | 1 (expected) |
| complaint-types, live, throwaway copy of the real database + migration (run twice) | — | 100 / 100 (85 + 15) | 0 |
| complaint-types, seed mode (inside the review runner) | 136 / 136 | 154 / 154 (136 + 18) | 0 |
| complaint-hearings `frontend.js` (Node, new) | — | 29 / 29 | 0 |
| complaint-parties `frontend.js` (Node) | 22 / 22 | 22 / 22 | 0 |
| complaint-types `frontend.js` (Node) | 26 / 26 | 26 / 26 | 0 |
| document-forms `frontend.js` (Node) | 60 / 60 | 60 / 60 | 0 |
| review-2026-10-24 (whole runner) | 329 / 329 | **413 / 413** | 0 |
| — `interface:` checks | 105 / 105 | 171 / 171 (105 + 66) | — |
| — `types-seed:` checks | 137 / 137 | 155 / 155 (154 + "run completed") | — |
| — all other runner checks | 87 / 87 | 87 / 87 | — |
| document-baseline | passed | passed | 0 |
| document-registry | 27 / 27 | 27 / 27 | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |
| document-templates endpoints | — | 76 / 76 | 0 |
| document-analysis | 104 / 104 | 104 / 104 | 0 |
| document-apply | 117 / 117 | 117 / 117 | 0 |
| document-manual-edit | 162 / 162 | 162 / 162 | 0 |
| document-type-create | 153 / 153 | 153 / 153 | 0 |
| document-forms | 126 / 126 | 126 / 126 | 0 |

- **Real-database live run.** The one failure is `P6 hearing tables and complaints.case_no exist (migration 20261118_complaint_hearings.sql applied)`. The other 14 Phase 6 live checks run only once the tables exist. On the migrated copy they all passed.
- **First runner pass.** It had 55 Phase 6 failures, all 401: the shared `staff` session fixture is invalidated by earlier runner checks that change that official's role. The Phase 6 section now creates its own staff official (as Phase 5 does) and removes it in cleanup. No other check was changed.
- **Phase 2–5 checks.** All Phase 2–5 interface checks passed unchanged.
- **`document_templates/` and `generated_documents/`.** The SHA-256 listing is identical before and after all runs (24 files).
- **Browser pass.** Not run by the assistant (the built-in browser pane was hidden). The developer ran the manual checklist on the real application after the migration: all 8 steps passed.

### complaint-types/run.php, "Phase 6"

Both modes (read-only):
- Tables and `case_no` exist.
- Foreign-key actions; INT types match the referenced keys; unique keys.
- Exactly one guard row.
- Every hearing's complaint exists.
- At most one scheduled hearing per complaint.
- No overlapping scheduled hearings for the same official or venue.
- Every hearing ends after it starts.
- Case numbers are unique and match `^BC-\d{4}-\d{4,}$`.
- Every complaint with a hearing has a case number.
- Every completed hearing has an outcome, a summary of at least 10 characters, `completed_at`, and no `not_recorded` attendance.
- Attendance rows belong to the hearing's own complaint.
- Reschedule chains point to a `rescheduled` session of the same complaint.
- `last_no` is at least the highest number used for each year.
- Nothing written.

Seed mode:
- Counters are empty, there are no case numbers and no hearings.
- Inside a rolled-back transaction, the counter statement gives 1, then 2.
- The rollback is confirmed.

### complaint-interface/api-checks.php, "Phase 6" (scratch database)

The base date is 7 days ahead in Manila (Monday if that is a Sunday) at 09:00.

- **a) Schedule:**
  - answers 200 with `BC-YYYY-0001`;
  - the stored Manila times, venue key and presiding official are right;
  - the complaint is now investigating, with an in-app notice for the filer;
  - attendance rows match the active parties, all `not_recorded`;
  - the open → investigating timeline note contains no names.
- **b) Refusals:** a second schedule (409), no respondent (422), a resolved complaint (409); nothing stored.
- **c) Time rules (422 each, nothing stored):** past, 06:30, ending after 19:00, Sunday, duration 20, `2026-02-30`, 09:10, a date with a trailing newline, a duration sent as text, less than 15 minutes ahead.
- **d) Overlaps:**
  - `check.php` warns before submitting and lists problems by field;
  - same official → 409 `official`;
  - `"  BARANGAY   hall "` → 409 `venue`;
  - shared registered party → 409 `party`, with no names in the body;
  - back-to-back sessions → 200 (admin session).
- **e) request_key:**
  - a repeat → 200, same id, `idempotent`, no second row, one SMS row;
  - a key used for another complaint → 409;
  - a malformed key or an unknown field → 422.
- **f) Reschedule:**
  - no reason → 422; stale version → 409;
  - success: old session `rescheduled` with its reason, new session 2 with `rescheduled_from_id`;
  - the old slot is free again;
  - the timeline note has no reason.
- **g) Record:**
  - a future session → 422; it appears in "needs recording" once moved into the past;
  - completing with a `not_recorded` party → 422;
  - a party not on the session, an unknown status, a 9-character summary, or a missing flag → 422;
  - a save → stays scheduled, version 2, no note; an identical save → `changed: false`;
  - complete → completed, with notes and `completed_at` near now (Manila) and a timeline note;
  - recording again → 409;
  - the complaint can then be resolved.
- **h) Resolve guard:** `update_status` and `notify_resolved` → 409 with the new message; other saves still work.
- **i) Cancel:**
  - no reason → 422; success → cancelled with its reason, and a note without it;
  - a second cancel → 409;
  - the same slot can be scheduled again (session 2, same case number).
- **j) Case numbers:** the next complaint gets `-0002`; the case number does not change on reschedule.
- **k) Who sees what:**
  - the filer gets exactly the public keys, the outcome and summary once completed, and no private data;
  - the official list has `case_no` and no `hearings`;
  - the community card keeps its seven keys, with no case number or session data;
  - `for_complaint` shows attendance names, notes, reasons and the blocker;
  - a party added later is listed as "added after";
  - `list.php`: sessions in range, more than 62 days → 422, the status filter works, a bad filter → 422;
  - `officials.php` returns `id, name, position`.
- **l) SMS:**
  - rows only for the registered parties (Rae, Lito, Mara), for all three events;
  - the schedule answer lists exactly the registered parties;
  - texts are at most 160 characters and contain no names.
- **m) Two parallel schedules** for the same official and time → exactly one 200 and one 409.
- **m2) The lock:**
  - the runner holds the guard row on another connection;
  - a schedule request is still waiting after 1.5 s;
  - a conflicting session is committed meanwhile;
  - the request then answers 409 `official` with that session's id.
- **n) Access:**
  - a resident session or no session → 401 on all 9 endpoints;
  - wrong method → 405; not JSON → 415; unknown complaint → 404;
  - the official page has the button after Complaint Types, the tab, both windows, and `hearings.js` after `complaint-walkin.js`.
- **Audit:** 4 scheduled, 1 rescheduled, 1 cancelled, 1 recorded, 1 completed; no reasons, notes or names.
- **Invariants:** the complaint-types live run on the scratch database has no P6 failures.
- **Cleanup:** sessions, attendance, complaints, parties, counters, fixtures and the staff official are removed, and the removal is confirmed.

### complaint-hearings/frontend.js (Node)

- **Loading:**
  - API exposed; `escapeHtml` from `official.js`;
  - no inline handlers, external URLs or imports;
  - `official.js` hooks are in place;
  - guarded top-level init; a failure inside init does not propagate.
- **Month grid:**
  - Nov 2026: 6 weeks from Mon Oct 26 to Sun Dec 6; days outside the month are flagged; 30 days inside;
  - Feb 2027: exactly 4 weeks;
  - Mar 2026: starts on a Sunday;
  - Dec 2026: rolls over into the next year;
  - the grid range stays within 62 days.
- **request_key:** 32 hex characters, unique per call, from `getRandomValues` (no `Math.random`).
- **Options:** 48 start times from 07:00 to 18:45; durations 15..240.
- **Text and rules:**
  - time text is read as text;
  - `defaultDate` skips Sunday;
  - `canRecord` follows the server's now;
  - chip classes follow the status.
- **Bodies:**
  - schedule and reschedule bodies have exact keys;
  - the record body sends only changed attendance;
  - only the stale 409 counts as "changed by someone else".
- **Detail block:** a disabled button with the reason, escaping.
- **Resident section:** case number, outcome label, escaped summary, no private data; absent when empty.

## Migration on the real database (2026-11-16)

| Step | Output |
|------|--------|
| Backup | `C:/laragon/backups/barangay_before_complaint_hearings.sql` (303,781 bytes; no `USE` line; dump completed). Taken before the delete below. |
| Clean-up | `DELETE FROM poverty_thresholds WHERE id BETWEEN 13 AND 18 AND created_at >= '2026-11-16 17:00:00'` removed exactly 6 rows (an accidental import of the old `database.sql`, which began with `USE`). 12 rows remain. |
| Rehearsal | `capstone_hearings_copy` from the backup: migration `1 / 0 / 0 / 0`; complaint-types live 100 / 100; copy dropped. |
| Real database | Migration `1 / 0 / 0 / 0`; complaint-types live **100 / 100**. |

Still open, not part of this phase: `poverty_thresholds` ids 4-12 duplicate ids 1-3 (three imports on 2026-11-08), so ids 1, 4, 7 and 10 are all active. They hold the same values, so MPI results do not change.

## Running the migration (you)

1. Back up `barangay_system_test_environment` (for example to `C:/laragon/backups/barangay_before_complaint_hearings.sql`).
2. Restore the backup to a test copy, and run `project-support/migrations/20261118_complaint_hearings.sql` on the copy. Expect `guard_rows 1, hearings 0, case_numbers 0, counters 0`.
3. Run `php project-support/tests/complaint-types/run.php --db=<copy>`. Expect all checks to pass.
4. Run the migration on the real database.
5. Run `php project-support/tests/complaint-types/run.php`. Expect all checks to pass, including the Phase 6 section.
6. Only then deploy the Phase 6 code (D9).

## Rollback

Preferred: restore the backup taken before the migration (step 1). That loses rows written after the backup.

To undo only the Phase 6 schema instead (**this deletes every session, attendance row and case number; there is no undo**):

```sql
DROP TABLE complaint_hearing_attendance;
DROP TABLE complaint_hearings;
DROP TABLE complaint_hearing_guard;
DROP TABLE complaint_case_counters;
ALTER TABLE complaints DROP KEY uq_complaint_case_no, DROP COLUMN case_no;
```

Roll back the code at the same time: `complaints/list.php`, `update_status.php` and `notify_resolved.php` read the new column and table.

## Manual browser checklist

Done on the real application on 2026-11-16, after the migration: all 8 steps passed.

1. Schedule from the complaint detail; the case number appears and the complaint shows Investigating.
2. Try an overlapping time for the same official: a warning shows before submitting, and submitting is refused.
3. The calendar month view shows the chip; Today / ‹ › navigation; filters.
4. Reschedule with a reason; the old session shows Rescheduled.
5. After the time passes (or schedule 15 minutes ahead and wait), record attendance, outcome and summary, then complete.
6. The resident sees date, venue, status and summary, and no private notes.
7. Resolve the complaint; try resolving another one with a scheduled session (refused).
8. A registered party receives the SMS (check `sms_log`); an unregistered party does not.

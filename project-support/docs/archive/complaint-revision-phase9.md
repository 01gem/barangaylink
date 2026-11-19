# Complaint revision — Phase 9: final verification and demo material

Written 2026-11-17. Not committed. No new features.

The real database was **read only**: the Step A version check. Demo seeding was tested on scratch databases only; running it on the real database is the developer's decision (`complaint-revision-demo.md`).

## Files

| File | Change |
|---|---|
| `api/complaints/_community.php` | new `complaint_require_json_body()`: Content-Type `application/json` (415) and a JSON **object** body (422). Accepts `{}`. The existing `complaint_require_json_object()` is unchanged (it refuses `{}`). |
| `api/complaints/create.php`, `update_status.php`, `notify_resolved.php` | `complaint_require_json_body()` before the existing validation; existing messages unchanged |
| `api/ai/analyze_complaints.php` | same JSON gate (the body is unused but must be a JSON object) |
| `api/stats/complaints_report.php`, `api/complaints/list.php` | GET only (405 otherwise) |
| `api/documents/_template_check.php` | new `document_summons_wording_problems()` / `document_summons_template_problems()` |
| `api/documents/template_activate.php` | Summons type only: refuses (422) a version that fails the wording rule |
| `api/hearings/_summons.php` | new `summons_venue()`: one leading "the" dropped from the venue |
| `project-support/tests/complaint-summons/run.php` | section j (7 checks) |
| `project-support/tests/complaint-e2e/run.php` | **new**: 12 scenarios + guard sweep (65 checks) |
| `project-support/tests/complaint-install/run.php` | **new**: fresh install compared with migrated install (25 checks) |
| `project-support/scripts/demo-complaints.php` | **new**: `--seed` / `--cleanup` with a manifest |
| `project-support/tests/demo-complaints/run.php` | **new**: seed → cleanup proves an identical database and storage (15 checks) |
| `project-support/demo/sample-summons-DEMO.pdf` | **new**: LibreOffice fallback (made by `demo-complaints/run.php --save-sample` on scratch) |
| `project-support/docs/complaint-revision-demo.md`, `complaint-revision-summary.md` | **new** |

## Defects found and fixed (each reported before fixing)

| # | Defect | Fix |
|---|---|---|
| 1 | `stats/complaints_report.php` answered any method (a POST returned the report). | GET only → 405 |
| 2 | `ai/analyze_complaints.php` never checked its body; `text/plain` or garbage started an analysis. | JSON gate (415 / 422) |
| 3 | `complaints/list.php` answered any method. | GET only → 405 |
| 4 | `update_status.php` and `notify_resolved.php` ignored Content-Type and turned a non-JSON body into `[]` (then 400). | JSON gate (415 / 422) before validation; existing 400 messages unchanged |
| 4b | `create.php` accepted valid JSON sent as `text/plain`. Such a body can be sent cross-site without a CORS preflight, so this was a CSRF path. | JSON gate (415 / 422) before validation (approved with 1–4) |
| 5 | Test-found: on a fresh install, the first summons creates `generated_documents/complaints/.htaccess`, and the new demo `--cleanup` left it. | The demo script records a folder it creates and removes it at cleanup only when nothing else is in it |

`create_official.php` already refuses `text/plain` with 415 (guard table below); it was not changed.

### JS callers of the changed endpoints

Each one already sends `Content-Type: application/json`; none needed a change.

| Endpoint | Caller |
|---|---|
| `complaints/create.php` | `js/resident.js:2275` (complaint form) |
| `complaints/create_official.php` | `js/complaint-walkin.js:443` |
| `complaints/update_status.php` / `notify_resolved.php` | `js/official.js:2903` (complaint detail save) |
| `ai/analyze_complaints.php` | `js/official.js:1205` (body `{}`) |
| `complaints/list.php` | `js/official.js:2494`, `:3673`; `js/resident.js:2099`, `:2555` (GET) |
| `stats/complaints_report.php` | `js/official.js:996` (GET) |

`js/official.js:546` `requestAction('update_status', …)` goes to `api/requests/update_status.php` (document requests), not complaints.

### Session cookie settings (report only, no change)

`db.php` runs before every `session_start()` and sets:
- `session.cookie_httponly = 1`
- `session.cookie_samesite = Lax`
- `session.use_strict_mode = 1`

**`session.cookie_secure` is not set (Off).** The cookie is also sent over plain HTTP, which is how Laragon serves the site. Set it to 1 if the site is ever served over HTTPS.

PHP defaults (`php.ini`) are HttpOnly Off, SameSite empty, Secure Off and strict mode Off. They are overridden by `db.php` as above. `session.use_only_cookies` is On.

## Step A — Summons safeguards

- **Activation rule** (`template_activate.php`, Summons type only). The version is refused with 422 and "This Summons template is not ready: …" when:
  - it does not hold the 13 keys exactly once each (no other key);
  - "morning/afternoon" is anywhere in the text;
  - a "____" run is anywhere in the text;
  - the summons sentence ("You are hereby summoned …") is missing or has two spaces in a row.

  The rule runs after the existing checked + tested + sha checks.
- **Venue:** `summons_values()` uses `summons_venue()`, which removes one leading separate word "the" (case-insensitive, trimmed):
  - "The Covered Court" gives "at the Covered Court";
  - "Barangay Hall" and "Theater Hall" are unchanged;
  - "The" alone is kept.
- **Real database (read-only):** every Summons version checked against the new rule.

| v | status | rule |
|---|---|---|
| 1 | retired | passes |
| 2, 4 | discarded | fail: no placeholders, "morning/afternoon", "____" |
| 3 | discarded | fails: venue missing, "morning/afternoon" |
| 5 | discarded | fails: venue missing, "morning/afternoon", "____" |
| 6, 7, 9 | retired / discarded | fail: "morning/afternoon" |
| 8 | retired | fails: two spaces in the summons sentence |
| 10 | discarded | passes |
| **11** | **active** | **passes** |

**Current `complaint_documents` made from a failing version: none.**

## Step B — Fresh install = migrated install (`tests/complaint-install`)

- **A:** empty database + `database.sql`.
- **B:** restore of `C:/laragon/backups/barangay_before_complaint_types.sql` (pre-P1; it has no `complaint_types`), then 20261116 → 20261117 → 20261118 → 20261119, then `build-summons-template.php --apply` on B (template storage in a temp folder).

Results:
- **Complaint-revision tables** (`complaint*`, `document_types`, `document_type_fields`, `document_template_versions`): **no difference** in tables, columns (type, null, default, extra, generated expression, collation), indexes, foreign keys or CHECK constraints.
- **Seeds:**
  - `complaint_type_fields` (9), the Summons type (1) and the Summons fields (13) are equal.
  - `complaint_types` (5) is equal except the **documented** P5 follow-up: `road_infrastructure` and `flooding_drainage` are `optional` in `database.sql` and `none` after 20261116 alone. Phase 5 decided to keep the migration's original seed; the real database was switched through the Complaint Types screen (complaint-revision-phase5.md).
- **Other differences, outside the complaint revision** (predate P1; reported, not changed):
  - `residents.household_id`: A `NOT NULL` / FK `ON DELETE RESTRICT`; B `NULL` / `ON DELETE SET NULL`.
  - Column order of `request_notification_events` (`retry_count` position). This is information only and has no effect.
- **Second run of every migration on B:** no error, and no change to schema, seeds, backfill counts or template versions.
- Both scratch databases were dropped; real database counts were unchanged.

## Step C — End-to-end (`tests/complaint-e2e`, scratch database, HTTP through `php -S`)

1. Staff creates "Boundary Dispute" (respondent required) with a select field and a text field; residents can load its fields.
2. Rosa files it against 2 respondents and gets a reference number. Her list shows names only (keys `role`, `full_name`). The extra fields are stored.
3. Staff links one respondent to registered resident Ben. The third, unregistered respondent is added right after step 4, so step 5 can show the refusal.
4. Session 1 is scheduled: case `BC-YYYY-NNNN`, status Investigating. `sms_log` rows exist only for Rosa and Ben (the registered parties).
5. Summons generated for the 2 original respondents. The third is refused: 422 "This respondent was added after the session was scheduled. Reschedule to include them." The PDF opens. Venue "The Covered Court" prints as "Covered Court".
6. Reschedule → session 2 (session 1 becomes "rescheduled"). Summons generated for all 3. Session 1 copies still open (200).
7. Session 2 moved into the past (test-only SQL, commented). Attendance for 4 parties, Settled, public summary → Completed.
8. Resolved. Rosa sees the public summary. No private notes, no change reason and no summons anywhere in her list.
9. Walk-in (unregistered complainant against Ben): in the official list, in no resident's list (Rosa, Ben, Carla).
10. Report:
    - "Boundary Dispute" has 2 complaints.
    - Mediation: 1 completed, 1 rescheduled, settled 1, rate 100.0, 1 case number, 5 current summonses.
    - Neither the AI payload nor the report body contains any fixture name, address, contact, note, reason, reference or case number.
11. Rename to "Lot Boundary Dispute", then deactivate:
    - official and resident lists show the new name;
    - the report has one row (new name, 2 complaints, inactive);
    - the resident page no longer offers the type; `fields.php` answers 404.
12. Made public: the card has exactly `filed_on, id, published_report_count, severity, status, type_id, type_name`, the new type name, and no party data.

## Step D — Security sweep

### Guard table (asserted by the e2e suite)

Columns:
- **out** — logged-out status.
- **role** — status for the wrong role: a resident on official endpoints, an official on resident endpoints. `-` means the endpoint is open to both.
- **method** — status for the other HTTP method.
- **text/plain** — status for a POST with body `x=1` as `text/plain`.
- **list** — status for a POST with `[1,2]` as JSON.

| Endpoint | Method | Session | out | role | method | text/plain | list |
|---|---|---|---|---|---|---|---|
| complaints/create.php | POST | resident | 401 | 401 | 405 | 415 | 422 |
| complaints/create_official.php | POST | official | 401 | 401 | 405 | 415 | 422 |
| complaints/list.php | GET | any | 401 | - | 405 | | |
| complaints/public_list.php | GET | resident | 401 | 403 | 405 | | |
| complaints/update_status.php | POST | official | 401 | 401 | 405 | 415 | 422 |
| complaints/notify_resolved.php | POST | official | 401 | 401 | 405 | 415 | 422 |
| complaints/parties_list.php | GET | official | 401 | 401 | 405 | | |
| complaints/resident_search.php | GET | official | 401 | 401 | 405 | | |
| complaints/party_save.php, party_link.php, party_remove.php | POST | official | 401 | 401 | 405 | 415 | 422 |
| complaint_types/list.php | GET | official | 401 | 401 | 405 | | |
| complaint_types/fields.php | GET | any | 401 | - | 405 | | |
| complaint_types/save_type.php, save_field.php, toggle_type.php, toggle_field.php | POST | official | 401 | 401 | 405 | 415 | 422 |
| hearings/list.php, check.php, for_complaint.php, needs_recording.php, officials.php, summons_list.php, summons_view.php | GET | official | 401 | 401 | 405 | | |
| hearings/schedule.php, reschedule.php, cancel.php, record.php, summons_generate.php | POST | official | 401 | 401 | 405 | 415 | 422 |
| stats/complaints_report.php | GET | official | 401 | 401 | 405 | | |
| ai/analyze_complaints.php | POST | official | 401 | 401 | 405 | 415 | 422 |

Valid JSON sent as `text/plain` is refused with 415 by every strict endpoint above. No endpoint was found accepting it.

### File access

- `summons_view.php` answers 404 for:
  - an unknown id;
  - an id of another kind (an attachment-sized id);
  - `id=1 OR 1`;
  - a path in `format`;
  - a path as the id.
- The file path comes from the database row only.
- `view_attachment.php` answers 403/404 for another resident's complaint attachment and for an unknown id.

### JavaScript sweep

Files: `hearings.js`, `complaint-types.js`, `complaint-walkin.js`, `community-complaints.js`, and the P3–P8 parts of `official.js` / `resident.js`.

- **Inline event handlers in P2–P8 code:** none. The `onclick=` attributes in `js/resident.js` (lines 521, 577, 586, 1403, 1430) date from 2026-10-11 and 2026-10-25, before P2. Reported, not changed.
- **innerHTML with server data:** every interpolated server value goes through `escapeHtml()` or `Number()`.
  - Toasts pass through each module's `toast()`, which escapes both parts before `showToastAdmin()`.
  - Titles use `textContent`; confirmations use `confirm()`.
  - No unescaped value found.
- **Nested init functions:** none. Each module is one IIFE with `initX()` / `initXSafely()` at module level.

## Step E — Full regression (2026-11-17)

| Suite | Result |
|---|---|
| review-2026-10-24 (whole runner) | **438 / 438** (interface 195, types-seed 156, other 87); all existing interface checks unchanged |
| review-2026-10-24 `render-check.js` (Node) | 131 / 131 |
| complaint-types live, **real database**, read-only | **107 / 107** |
| complaint-types `--seed` (inside the runner, group types-seed) | 156 / 156 |
| complaint-summons | **77 / 77** (70 + 7 Phase 9) |
| complaint-e2e (new) | **65 / 65** |
| complaint-install (new) | **25 / 25** |
| demo-complaints (new) | **15 / 15** |
| complaint-hearings `frontend.js` | 40 / 40 |
| complaint-types `frontend.js` | 26 / 26 |
| complaint-parties `frontend.js` | 22 / 22 |
| complaint-interface `storage-config-check.php` | 9 / 9 |
| document-baseline (goldens) | passed |
| document-registry | 28 / 28 |
| document-templates / endpoints | 11 / 11, 76 / 76 |
| document-forms + `frontend.js` | 126 / 126, 60 / 60 |
| document-manual-edit | 162 / 162 |
| document-type-create | 153 / 153 |
| document-apply | 117 / 117 |
| document-analysis | 104 / 104 |
| storage-override | 14 / 14 |
| request-concurrency + `frontend.js` | 81 / 81, 14 / 14 |

`php -l` passes on every touched PHP file. The review runner's leftover test server and scratch database were removed after the run.

## Not run by the assistant

- `demo-complaints.php --seed` on the real database (the developer's decision).
- The browser walkthrough in `complaint-revision-demo.md`.

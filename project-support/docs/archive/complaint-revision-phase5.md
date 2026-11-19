# Complaint revision — Phase 5: complaint parties and walk-in complaints

Written 2026-11-16. Not committed. The real database has **not** been migrated (see "Running the migration").

## Purpose

1. A complaint has **parties**: one or more complainants and zero or more respondents. Each party is either a **registered** resident (`resident_id`) or a person who is **not registered** (typed name, address, contact).
2. Residents can name respondents when they file. They type them; they cannot pick registered residents.
3. Officials can file **walk-in** complaints at the barangay hall for registered or unregistered complainants, and manage the parties of any complaint that is not resolved.
4. The complaint type's `respondent_mode` (`none` / `optional` / `required`) controls the respondent section everywhere.

Not in this phase: hearings and calendar (Phase 6), summons (Phase 7), statistics and AI (Phase 8). No complaint type data was changed.

## Files

| File | Change |
|------|--------|
| `project-support/migrations/20261117_complaint_parties.sql` | New. Table, two `complaints` columns, backfill, count report. Safe to run again. |
| `database/database.sql` | The same DDL, written into `CREATE TABLE complaints` and a new `CREATE TABLE complaint_parties`, plus the backfill (does nothing on a fresh database). |
| `api/complaints/_problem.php` | New. `ComplaintProblem` (message + HTTP status), shared by `_filing.php` and `_parties.php`. |
| `api/complaints/_parties.php` | New. All party rules, views and writes. |
| `api/complaints/_filing.php` | New. Shared filing code: type/extra-field validation (moved from `create.php`, same messages), `complaint_new_reference_no()`, complaint insert, first timeline row, SMS text. |
| `api/complaints/create.php` | Uses `_filing.php`; accepts `respondents`; one transaction. |
| `api/complaints/create_official.php` | New. Walk-in filing. |
| `api/complaints/parties_list.php`, `party_save.php`, `party_link.php`, `party_remove.php`, `resident_search.php` | New. Thin endpoints. |
| `api/complaints/list.php` | Adds `filed_by_type` and `parties`. Every existing key kept. |
| `pages/resident.php` | Hidden list of respondent modes per type, "Person involved" section, inline error line. |
| `js/resident.js` | Person-involved logic, `respondents` in the request, inline 422, "People involved" in the detail; `fetchJson` errors now carry `.status`. |
| `pages/official.php` | "New walk-in complaint" button, walk-in window, parties window, `complaint-walkin.js` after `complaint-types.js`. |
| `js/official.js` | `parties` and `filedByType` in the complaint mapping; Parties block and "Manage parties" in the detail; `syncOpenComplaintParties()`; the detail's key handler steps aside while a walk-in or parties window is open. |
| `js/complaint-walkin.js` | New. Walk-in window, party editor, parties window. |
| `css/official.css`, `css/resident.css` | One commented Phase 5 block at the end of each. |
| `project-support/tests/complaint-types/run.php` | Phase 5 section (live invariants; seed: backfill, unique key, RESTRICT). |
| `project-support/tests/complaint-interface/api-checks.php` | 29 Phase 5 HTTP checks. |
| `project-support/tests/review-2026-10-24/run.php` | Sources the Phase 5 migration on the scratch database. |
| `project-support/tests/complaint-parties/frontend.js` | New. Node checks for `complaint-walkin.js` and the official Parties block. |

Not changed: `_community.php`, `public_list.php`, `timeline.php`, `api/complaint_types/*`, documents, statistics, AI.

### Differences from the brief

1. **`data-respondent-mode` is not on the `<option>` tags.** The Phase 3 check "types g)" pins the exact markup `<option value="ID">Name</option>`, and every Phase 2–4 check had to pass unchanged. The modes are in a hidden list next to the section instead: `<div id="complaintTypeModes" hidden><span data-type-id="ID" data-respondent-mode="optional"></span>…</div>`. `js/resident.js` reads the mode by the selected type id.
2. **Unique key on `active_resident_id`** (approved). It is a stored generated column, `IF(is_active = 1, resident_id, NULL)`, so a removed registered party never blocks adding the same resident again. `fk_party_resident` stays `ON DELETE RESTRICT`: MySQL forbids CASCADE and SET NULL on a base column of a stored generated column. This is commented in the migration and in `database.sql`.
3. **The filing resident cannot be removed** from a complaint they filed (approved). `complaints.resident_id` never changes after filing (approved): linking or removing parties does not change who sees the complaint.
4. **`create.php` timeline row.** Before, a failed `complaint_updates` insert was only logged. It is now part of the transaction, so it rolls the complaint back.
5. **`list.php` official view** has the same keys as `parties_list.php`, including `version` and `is_active`. It lists active parties only.
6. **Walk-in timeline note** is "Complaint filed at the barangay hall." (official actor). `official_note` is "Filed at the barangay hall." as specified.
7. **Walk-in requests are strict.** An unknown top-level key, including the legacy `category`, answers 422. `create.php` still ignores unknown keys, as before.
8. **The walk-in window does not close on a backdrop click.** A half-filled form would be lost. Escape and the close buttons close it.
9. **Audit target types:** a walk-in filing is logged as `complaint`; party changes are logged as `complaint_party`. Both use the reference number.

## Schema

```
complaint_parties
  id                 INT AI PK
  complaint_id       INT NOT NULL      FK complaints(id) ON DELETE CASCADE
  role               ENUM('complainant','respondent') NOT NULL
  resident_id        INT NULL          FK residents(id) ON DELETE RESTRICT   -- NULL = not registered
  full_name          VARCHAR(150) NOT NULL                                  -- snapshot for documents
  address            VARCHAR(255) NULL                                      -- not registered only
  contact_no         VARCHAR(20) NULL                                       -- not registered only, +639XXXXXXXXX
  sort_order         INT NOT NULL DEFAULT 0
  is_active          TINYINT(1) NOT NULL DEFAULT 1
  version            INT NOT NULL DEFAULT 1
  created_by_type    ENUM('resident','official','system') NOT NULL
  created_by_id      INT NULL
  created_at, updated_at
  active_resident_id INT AS (IF(is_active = 1, resident_id, NULL)) STORED
  KEY idx_party_complaint (complaint_id, role, is_active, sort_order)
  KEY idx_party_resident (resident_id)
  UNIQUE KEY uq_party_resident (complaint_id, role, active_resident_id)

complaints
  + filed_by_type        ENUM('resident','official') NOT NULL DEFAULT 'resident'
  + filed_by_official_id INT NULL  FK barangay_officials(id) ON DELETE SET NULL  (KEY idx_complaint_filed_by)
```

**Backfill.** One complainant row per complaint that has none: `resident_id` and `full_name = complaints.resident_name`, `created_by_type 'system'`. A complaint with no `resident_id` gets an unregistered complainant. The statement sits between `-- BACKFILL BEGIN` and `-- BACKFILL END`; the seed test runs exactly that text.

Expected on the real database today (read-only count, 2026-11-16): **8 registered, 0 unregistered, 0 complaints left without a complainant**. On a copy of the real database the migration printed `8 / 0 / 0`, and a second run printed the same (nothing added).

## Rules (`api/complaints/_parties.php`)

- Party input is a JSON object with no unknown keys:
  - registered: `{"resident_id": int}` (officials only);
  - unregistered: `{"full_name": str, "address": str|null, "contact_no": str|null}`.
  - Mixing the two forms, or any other key, answers 422 `Unknown field: <key>.`
- `full_name`: trimmed, 2–150 characters, no control or text-direction characters. `address`: at most 255 characters, empty becomes null. `contact_no`: empty becomes null, otherwise it must pass `normalize_ph_phone`, else 422 `Invalid contact number.`
- `resident_id` must exist and be active: else 422 `Select an active registered resident.` The stored name is `fname lname`.
- The same resident cannot be listed twice in one role (422 `This resident is already listed.`), nor as both complainant and respondent (422 `The same resident cannot be both a complainant and a respondent.`). Add, link and walk-in all check this.
- `respondent_mode`:
  - `none`: no respondents (`Respondents cannot be added for this type of concern.`);
  - `required`: at least one when filing (`Add at least one person this complaint is about.`) and after every removal (`This type of concern needs at least one respondent.`).
  - The mode is read from the complaint's type at the time of the request.
- At least one active complainant always (`Add at least one complainant.` when filing; `A complaint needs at least one complainant.` on removal).
- Limits: 5 active complainants and 10 active respondents per complaint. A resident filing may add at most 5 respondents (`You can add at most 5 people.`).
- Writes on a resolved complaint answer 409 `Resolved complaints cannot be changed.`
- Every party write:
  - runs in one transaction;
  - locks the complaint row, then the party row (`FOR UPDATE`);
  - checks `version` (409 `This item was changed by someone else. Reload and try again.`);
  - adds a timeline row `Parties updated.` (official actor, status unchanged, no personal details);
  - writes the audit row after the commit.
  - A 1062 on the unique key (two requests racing) answers 422 `This resident is already listed.`
- Only unregistered parties can be edited (`Registered residents are edited in their profile.`). The role never changes. An update that matches what is stored answers `changed: false`, does not raise the version and writes no audit row.
- Linking sets `resident_id`, replaces `full_name` with the resident's name, and clears `address` and `contact_no`; from then on they are read live from `residents`.
- Removing sets `is_active = 0`. Removed parties stay in `parties_list.php`.

### Module functions

```php
final class ComplaintProblem extends RuntimeException { public int $status; }   // _problem.php

// _parties.php
function complaint_party_validate(mixed $raw, bool $allowRegistered, array $extraKeys = []): array
function complaint_party_validate_list(mixed $raw, string $role, bool $allowRegistered, int $max, string $tooMany): array
function complaint_party_resolve(mysqli $db, array $party): array
function complaint_parties_check_set(array $complainants, array $respondents, string $respondentMode): void
function complaint_parties_insert(mysqli $db, int $complaintId, array $complainants, array $respondents, string $byType, ?int $byId): void
function complaint_party_official_view(array $row): array
function complaint_party_resident_view(array $row): array
function complaint_parties_for_complaints(mysqli $db, array $complaintIds, string $view): array
function complaint_parties_list(mysqli $db, int $complaintId): array
function complaint_party_add(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_party_update(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_party_link(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_party_remove(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_party_search_residents(mysqli $db, string $q): array
function complaint_party_transaction(mysqli $db, callable $work): mixed

// _filing.php
function complaint_new_reference_no(): string            // CSR-YYYYMMDD-XXXXXX, used by both filing endpoints
function complaint_filing_read_body(): array
function complaint_filing_validate(array $input, bool $allowLegacyCategory): array
function complaint_filing_insert(mysqli $db, array $filing, array $filer): array
function complaint_filing_update_row(mysqli $db, int $complaintId, string $actorType, ?int $actorId, string $note): void
function complaint_filing_sms_body(string $firstName, string $reference): string
```

## Endpoints

Errors are `{"success": false, "message": "..."}`.

| Endpoint | Method | Who | Request | Response |
|----------|--------|-----|---------|----------|
| `create.php` | POST | resident | as before + `respondents?: [unregistered party]` | unchanged `{id, reference_no, message}` |
| `create_official.php` | POST JSON | official | `{complaint_type_id, location_text, description, severity?, extra_fields?, complainants: [party] (1..5), respondents?: [party] (0..10)}` | `{id, reference_no, message, sms: [{resident_id, status}]}` |
| `parties_list.php?complaint_id=N` | GET | official | — | `{complaint: {id, reference_no, status, respondent_mode, filer_resident_id}, limits, parties: [official view, removed included]}` |
| `party_save.php` | POST JSON | official | add: `{complaint_id, role, party}`; update: `{complaint_id, id, version, full_name, address?, contact_no?}` | `{changed, party}` |
| `party_link.php` | POST JSON | official | `{id, version, resident_id}` | `{changed, party}` |
| `party_remove.php` | POST JSON | official | `{id, version}` | `{changed, party}` |
| `resident_search.php?q=` | GET | official | `q`: 2–50 characters | `{residents: [{id, full_name, purok_zone, address}]}` (max 10, active only) |
| `list.php` | GET | both | — | adds `filed_by_type` and `parties` to each row |

Status codes:

| Code | When |
|------|------|
| 401 | Not an official on the official endpoints, including a resident session. |
| 404 | Unknown complaint or party. |
| 405 | Wrong method. |
| 409 | Stale version; resolved complaint. |
| 415 | Not JSON (walk-in and party writes). |
| 422 | Rule failures. |
| 500 | Database failure; the detail is logged only. |

**Walk-in specifics:**
- `complaints.resident_id` is the first complainant's `resident_id` if registered, else NULL.
- `resident_name` is the first complainant's name, shortened with `mb_substr(…, 0, 100, 'UTF-8')`.
- `filed_by_type` is `official` and `filed_by_official_id` is the session official.
- The filing SMS (`complaint_filed`, preference `complaints_enabled`, through `notify_resident`) goes after the commit to registered complainants only. Respondents never get an SMS.
- Audit row: `Filed walk-in complaint` / `complaint` / reference number, with details `{complainants, registered_complainants, respondents}` (counts only).

**Resident search** matches `CONCAT(fname, ' ', lname) LIKE ? ESCAPE '\\'` after escaping `\`, `%` and `_`. It never returns contact numbers.

## Privacy: who sees what

| Data | Official (`list.php`, `parties_list.php`) | Resident who owns the complaint (`list.php`) | Community board (`public_list.php`) | Timeline (both) |
|------|------|------|------|------|
| Party role and name | yes | yes | no | no |
| Registered / not registered, `resident_id` | yes | no | no | no |
| Address, contact | yes (registered: live from `residents`) | no, not even their own | no | no |
| Removed parties | yes (`parties_list.php`) | no | no | no |
| "Parties updated." note | yes | yes | no | yes (no names) |

- After an official **links** a typed party to a registered resident, the owner sees that resident's **registered name** in place of the name they typed. Check (g) asserts this.
- The community card projection is unchanged: the same seven keys and no party data (check j).
- Audit details for party changes hold party id, role, registered flag, resident id and the names of the changed fields. They never hold an address, contact number or typed name.

## Screens

- **Resident form.**
  - "Person involved" sits below the extra fields. It is hidden for `none`. For `optional`, the checkbox "This concern involves a specific person" reveals it. For `required`, it is shown and the first row cannot be removed.
  - Each row has full name (required), address and contact number (optional). "Add another person" stops at 5.
  - The helper text reads "The barangay will verify the person's details."
  - Rows are built with DOM calls, never from HTML strings.
  - Hidden section → `respondents` is left out of the request.
  - A 400 or 422 answer is shown inline (`#complaintFormError`); other errors stay a toast. Retry-upload mode locks the section.
- **Resident detail.** A "People involved" section lists the complainant(s) and respondent(s) by name.
- **Official walk-in window** (toolbar button "New walk-in complaint"):
  - Active types come from `complaint_types/list.php`; the extra fields come from `fields.php` (all four kinds).
  - Then location, severity, description, complainants and respondents (the section follows `respondent_mode`).
  - Each party row toggles between "Registered resident" (search with a 300 ms debounce, pick one, "Change") and "Not registered" (name, address, contact).
  - Server messages show inline. Success closes the window, shows a toast and reloads the list.
- **Official detail.** A "Parties" block (role, name, Registered / Not registered badge, address, contact) and a "Manage parties" button.
- **Parties window.**
  - Per party: Edit and Link (unregistered parties only), Remove (with a confirmation). Link uses the same search, then a "Link X to Y?" confirmation.
  - "Removed (n)" is collapsed. "Add a party" has a role selector and one party row.
  - On a resolved complaint, every action is hidden or disabled and the note "Resolved complaints cannot be changed." is shown.
  - A stale-version 409 shows the toast "Changed by someone else — Reloaded." and reloads. Other errors show inline.
  - On close, the list reloads and the open detail's Parties block is redrawn, with focus kept on "Manage parties".
- **Code rules.** No inline handlers. One delegated click listener (`data-wk-action` only) and one keydown listener (Escape, focus trap). Every server value written as HTML goes through `escapeHtml` from `official.js`. `initComplaintWalkinSafely` runs on `DOMContentLoaded` inside try/catch.

## Tests

### Results (2026-11-16)

| Suite | Before Phase 5 | After Phase 5 | Exit |
|-------|---------------:|--------------:|-----:|
| complaint-types, live, **real database** (not migrated) | 73 / 73 | **73 / 74** | 1 (expected) |
| complaint-types, live, throwaway copy of the real database + migration | — | 85 / 85 (73 + 12) | 0 |
| complaint-types, seed mode (inside the review runner) | 118 / 118 | 136 / 136 (118 + 18) | 0 |
| complaint-parties `frontend.js` (Node, new) | — | 22 / 22 | 0 |
| complaint-types `frontend.js` (Node) | 26 / 26 | 26 / 26 | 0 |
| document-forms `frontend.js` (Node) | 60 / 60 | 60 / 60 | 0 |
| review-2026-10-24 (whole runner) | 282 / 282 | 329 / 329 | 0 |
| — `interface:` checks | 76 / 76 | 105 / 105 (76 + 29) | — |
| — `types-seed:` checks | 119 / 119 | 137 / 137 (136 + "run completed") | — |
| — all other runner checks | 87 / 87 | 87 / 87 | — |
| document-baseline | 6 / 6 | passed | 0 |
| document-forms | 126 / 126 | 126 / 126 | 0 |
| document-registry | 27 / 27 | 27 / 27 | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |
| document-analysis | — | 104 / 104 | 0 |
| document-apply | — | 117 / 117 | 0 |
| document-manual-edit | — | 162 / 162 | 0 |
| document-type-create | — | 153 / 153 | 0 |

**Real-database live run.** The one failure is `P5 complaint_parties table exists (migration 20261117_complaint_parties.sql applied)`. The other 11 Phase 5 live checks only run once the table exists. After you migrate, this run should give 85 / 85, as the copy did.

All 76 Phase 2–4 interface checks passed unchanged: same names, same messages, same assertions. The first runner pass after the change had one failure, Phase 3 "types g)", because `data-respondent-mode` was on the `<option>` tag. That led to difference 1, and the check passes unchanged now.

**Database state after the runs.**
- Real database unchanged: 8 complaints, no `complaint_parties` table. The runner's own "application database counts unchanged" check passed.
- The throwaway copy `capstone_p5_copy` was dropped.
- The runner's test server was stopped. Its scratch database `capstone_fix24_*` remains; the runner drops it at the start of its next run.

**Browser pass** (built-in browser, against the runner's test server):
- Walk-in with a searched registered complainant and a typed respondent. A bad contact number showed `Invalid contact number.` inline; after fixing it, the complaint was filed.
- The detail showed the Parties block, with a typed `<b>` in a name shown as text.
- In Manage parties, the respondent was linked to "Ott Other". Removing the only complainant was refused inline. Escape closed only the parties window, and the detail redrew.
- Resident form: the Road type shows no section; Noise shows the checkbox, which reveals the rows. A complaint was filed with two people, and the detail shows "People involved".

### complaint-types/run.php, "Phase 5"

Both modes (read-only):
- The table exists.
- Schema: party resident FK RESTRICT, complaint FK CASCADE, `fk_complaint_filed_by` SET NULL, unique key `(complaint_id, role, active_resident_id)`, column types matching the referenced keys.
- Every complaint has an active complainant.
- No active respondent was added while its type was `none` (`p.created_at >= t.updated_at`).
- Every `resident_id` exists.
- No duplicate resident in one role, and no resident in both roles.
- Registered parties store no address or contact.
- Contacts are normalized.
- Limits hold.
- A walk-in's `complaints.resident_id` is one of its registered complainants.
- Nothing written.

Seed mode, inside a transaction that always rolls back:
- The backfill text read from the migration file adds exactly one complainant to each of two legacy complaints (one registered, one not), with the right name, role, type and order.
- A second run adds nothing.
- The unique key refuses a second active row and allows re-adding after removal.
- Deleting a resident who is a party fails with 1451 (RESTRICT).
- The rollback is confirmed.

### complaint-interface/api-checks.php, "Phase 5" (scratch database)

- a) A resident files with two respondents: 200; the resident is the complainant and the typed respondents are stored with a normalized contact. The resident list shows `role` and `full_name` only.
- b) Each answers 422 with its message and stores no complaint: `resident_id` in a respondent; respondents on a `none` type; no respondent on a `required` type; 6 respondents; a bad contact.
- c) A `BEFORE INSERT` trigger on `complaint_parties` fails on the name `FORCE_FAIL`. Filing then answers 500 and leaves no complaint, party or timeline row. The trigger is dropped in `finally`, and the check confirms it is gone.
- d) Walk-in, unregistered complainant against a registered respondent: `resident_id` NULL, `filed_by_type` official, `filed_by_official_id`, note, extra fields, parties, an official timeline row. No SMS row.
- e) Walk-in, registered plus unregistered complainant: `complaints.resident_id` is the registered one, who sees it in My Complaints. Exactly one SMS row, for that resident.
- f) Each walk-in answers 422 and stores nothing: same resident in both roles; twice in one role; inactive resident; unknown resident; no complainant; unknown top-level key; mixed party keys. A resident session gets 401.
- g) Link:
  - the name is replaced, address and contact are cleared, the version rises;
  - the official view shows the live address and contact, and follows a later profile change;
  - the filer sees the registered name.
  - Linking to a resident already in the other role, or in the same role, answers 422; so does linking an already registered party.
- `party_save`:
  - add, update (contact normalized, version 2), and an identical save (`changed: false`) work;
  - editing a registered party is refused, and so is adding a resident who is already in either role.
  - "Parties updated." notes are written, with no names in them.
- h) Removal:
  - the last complainant, the filing resident, and the last respondent of a `required` type: 422;
  - a stale version: 409; the current version removes it, and the removed row stays listed;
  - every write on a resolved complaint: 409 with its message, nothing changed;
  - a resident session on any party endpoint: 401.
- i) `resident_search`: a resident session gets 401; 1 or 51 characters answer 422; `%%` and `__` match nothing; results have exactly `id, full_name, purok_zone, address` and no contact; inactive residents are not found.
- j) `public_list` cards keep the seven keys; no names and no `parties` appear in the body.
- k) Audit rows: 3 walk-ins, 1 add, 1 update, 1 link, 1 remove. The walk-in row carries counts only, and no contact numbers appear anywhere in the details.
- Also checked:
  - the official list carries the official view next to the existing keys;
  - the resident page has the mode list and the section;
  - the official page has the button, both windows, and the script after `complaint-types.js`.
- Cleanup removes the test complaints (parties and timeline rows cascade), audit and SMS rows, fixtures and the test type, and confirms it.

### complaint-parties/frontend.js (Node)

- `escapeHtml` comes from `official.js`. The file has no inline handlers. Init is the top-level guarded `initComplaintWalkinSafely`, and a failure inside it does not propagate.
- Party payloads:
  - typed rows are trimmed, with empty fields sent as null;
  - a registered row sends only `resident_id`;
  - incomplete rows are stopped before sending.
- The walk-in body has exact keys; a `none` type sends no respondents; a `required` type, a missing complainant, a missing type or missing text is stopped before sending.
- Add and update bodies: an update never sends `resident_id` or role.
- Only the stale-version 409 counts as "changed by someone else".
- The official Parties block escapes values and shows the badges.

## Running the migration (you)

1. Back up `barangay_system_test_environment`.
2. Restore the backup to a test copy, and run `project-support/migrations/20261117_complaint_parties.sql` on the copy. Expect `backfilled_registered 8, backfilled_unregistered 0, complaints_without_complainant 0` (if no complaints were added since this was written).
3. Run `php project-support/tests/complaint-types/run.php --db=<copy>`. Expect all checks to pass.
4. Run the migration on the real database.
5. Run `php project-support/tests/complaint-types/run.php`. Expect all checks to pass, including the Phase 5 section.

## Rollback

Preferred: restore the backup taken before the migration.

```
cmd /c "mysql -u root barangay_system_test_environment < C:/laragon/backups/barangay_before_complaint_parties.sql"
```

Restoring brings back the pre-Phase 5 state but loses any complaints, comments or other rows written after the backup. If newer data must be kept, undo only the Phase 5 schema instead. **This deletes every party row, including walk-in complainants and typed respondents; there is no undo.** Back up first.

```sql
DROP TABLE complaint_parties;
ALTER TABLE complaints
  DROP FOREIGN KEY fk_complaint_filed_by,
  DROP KEY idx_complaint_filed_by,
  DROP COLUMN filed_by_official_id,
  DROP COLUMN filed_by_type;
```

Notes:
- Run the code rollback (previous commit) at the same time; the Phase 5 code reads `complaint_parties` and `filed_by_type`.
- Walk-in complaints whose complainant was not registered keep `resident_id` NULL; after the rollback they show no complainant name except `resident_name`.
- Once Phase 6 (hearings) is migrated, roll Phase 6 back first: `complaint_hearing_attendance` references `complaint_parties`.

## Manual browser checklist

Not done yet on the real application (needs the migration).

1. Resident: Noise type → the checkbox "This concern involves a specific person" reveals the respondent rows; file with 2 respondents.
2. Resident: Road type → the same checkbox appears (every type is now `optional`, see below).
3. Official: walk-in for a NOT registered complainant against a registered respondent (search works).
4. Official: walk-in for a registered complainant → it appears in that resident's My Complaints.
5. Manage parties: link the unregistered respondent from step 1 to a registered resident; remove one; try removing the last complainant (refused).
6. Community board: no names anywhere.
7. Audit Log shows the actions.

Notes:
- Step 1: the respondent rows only appear for types whose respondent mode is `optional` or `required`. Since 2026-11-16 every type is `optional` (next section).
- Step 5: removing the resident who filed the complaint is also refused, by design.
- Step 6: only complaints an official made public appear on the board.

## Follow-up (2026-11-16): every type allows a person involved

At the barangay's request, every complaint type now lets the resident name a person involved.

- **Real database.** "Road / Infrastructure" and "Flooding / Drainage" were switched from `none` to `optional`. The switch went through `complaint_type_save()`, the code behind the Complaint Types screen, so each type's version went from 1 to 2. Each change has an audit row: "Updated complaint type", official "System maintenance (all types allow a person involved)", details `{"respondent_mode":["none","optional"]}`. Audit ids are 294 and 295. All five active types (and the inactive "Stray Animal") are now `optional`.
- **`database/database.sql`.** The seed for a fresh install now creates every type as `optional`.
- **Not changed.**
  - The migration `20261116_complaint_types.sql` keeps its original seed, because the tests check that seed exactly.
  - The `none` choice still exists in the Complaint Types screen. Staff can still give a type `none`, and the form then hides the section for that type.
- **Tests.** The live run on the real database still passes: 85 / 85.

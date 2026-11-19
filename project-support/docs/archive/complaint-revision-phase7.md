# Complaint revision — Phase 7: summons

Written 2026-11-16. Not committed.

The real database was migrated on 2026-11-16, at the developer's request:
- backup `C:\laragon-backups\barangay_before_summons.sql`;
- migration 20261119;
- build script dry run, then `--apply`: Summons type id 48, v1 = version id 25, `tpl_48_v1_ba857c07.docx`, active;
- complaint-types live **107 / 107**;
- a re-run of the build script answered "nothing to do";
- `summon_letter.docx` sha256 unchanged.

The developer ran the manual browser checklist on the real application on 2026-11-17: all 6 steps passed.

## Purpose

1. An official (staff or admin) generates a printable **Summons** for one respondent of one **scheduled, upcoming** mediation session.
2. The output is a DOCX and a PDF. They come from the same DOCX fill and LibreOffice conversion as document requests.
3. Regenerating replaces the current copy. The old copy stays in the history and can still be opened.
4. Residents never see summons, the Summons document type, its fields or its price.

Not in this phase: SMS for summons, statistics and AI (Phase 8). Party and hearing rules were not changed; this phase only reads them.

## Files

| File | Change |
|---|---|
| `project-support/migrations/20261119_summons.sql` | new: `document_types.audience`, the Summons type and its 13 fields, `complaint_documents` |
| `database/database.sql` | same changes folded in; the versions backfill skips `summon_letter.docx` |
| `project-support/migrations/20261109_document_template_versions.sql` | backfill guard: never makes the blank `summon_letter.docx` a version |
| `project-support/migrations/20261110_official_title_field.sql` | guard: never adds `official_title` to Summons on a re-run |
| `project-support/scripts/build-summons-template.php` | new: builds and activates Summons template v1 (dry run by default) |
| `api/documents/_docx_render.php` | new: `document_fill_docx()` and `document_convert_pdf()`, shared by requests and summons |
| `api/requests/_render.php` | `request_render_document()` calls the two shared functions (no behaviour change) |
| `api/documents/_registry.php` | `audience` on type rows, `document_types_audience_ready()`, `document_types_resident_sql()`, the internal-type refusals, `document_storage_dir('complaints')`, `DOCUMENT_SUMMONS_CODE` / `DOCUMENT_SUMMONS_FIELDS` |
| `api/documents/_templates.php` | `DOCUMENT_SAMPLE_VALUES` for the Summons keys (Test render) |
| `api/documents/_type_create.php` | a new type's `sort_order` counts resident types only |
| `api/documents/requestable_types.php`, `type_fields.php` | resident types only |
| `api/document_prices/list.php`, `update.php` | never list or edit a price of an internal type |
| `project-support/scripts/analysis-accuracy.php` | resident templates only |
| `api/hearings/_summons.php` | new: rules, values, generation, list, counts, file lookup |
| `api/hearings/summons_generate.php`, `summons_list.php`, `summons_view.php` | new endpoints |
| `api/hearings/for_complaint.php` | adds `summons {issued, total}` to scheduled sessions (read only) |
| `js/hearings.js` | Summons section in the session window; count line in the complaint Mediation block |
| `js/document-templates.js`, `css/official.css` | "Internal — complaints" badge; summons row styles and spinner |
| `generated_documents/complaints/.htaccess`, `.gitignore` | deny rule for the new folder; generated files stay out of git |
| tests | new `complaint-summons/run.php`; additions to `complaint-types/run.php` (live) and `complaint-hearings/frontend.js`; scoped assertions in `document-registry`, `document-type-create`; one fixture line in `document-templates/endpoints.php` |

## Template

`document_templates/summon_letter.docx` is **only read** (sha256 `ae818c4a09bcc16cdc55de812278db0c3ea71e71e23ce3a2057c4c382e16d2aa`, unchanged by every run). The build script writes Summons template version 1 as a new file, `tpl_<typeId>_v1_<hex>.docx`.

It uses the Phase 4C manual-edit functions (`document_manual_plan` → `document_apply_xml` → `document_apply_verify` + `document_manual_verify_keys`), so each placeholder sits in one run that keeps that run's formatting. The blanks were underlined, so the filled values are underlined too.

| Paragraph | Before | After |
|---|---|---|
| 5 | `Barangay Case No.: ____________________` | `Barangay Case No.: {{case_no}}` |
| 6 | `For: __________________________________` | `For: {{case_title}}` |
| 7 | `Complainant/s: ____________________________________` | `Complainant/s: {{complainant_names}}` |
| 9 | `Respondent/s: ____________________________________` | `Respondent/s: {{respondent_names}}` |
| 11 | `TO: ____________________________________________` | `TO: {{respondent_to}}` |
| 13 | `…witnesses, on the ____ day of ____________ at ____________ in the morning/afternoon, then and there…` | `…witnesses, on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} at {{venue}}, then and there…` (rest unchanged) |
| 16 | `Issued this ____ day of ____________, 20____, at Barangay Sampaguita, Tagana-an, Surigao del Norte.` | `Issued this {{issued_day}} day of {{issued_month}}, {{issued_year}}, at Barangay Sampaguita, Tagana-an, Surigao del Norte.` |
| 18 | `Hon. Wenchilita D. Salse` | `{{official_name}}` |

All other paragraphs are unchanged, including `Barangay Captain / Punong Barangay`.

How the script works:
- **Pass 1** makes 12 edits:
  - 10 `placeholder` edits (every blank and the old name);
  - `" in the morning/afternoon"` → `" at VENUE"`;
  - `", 20"` → `", "`, so the year uses the underlined blank like the day and month.
- **Pass 2** turns the marker `VENUE` into `{{venue}}`. A manual edit refuses braces in new text, which is why this takes a second pass.
- **Checks before storing.** Nothing is stored unless all of these pass:
  - exact after-texts, and no `____` left in the rewritten paragraphs;
  - no other paragraph changed;
  - archive entries identical except `word/document.xml`;
  - upload safety checks;
  - `document_template_check` with the 13 keys required;
  - each key appears exactly once.
- **`--apply`** stores v1 as a draft, test-renders it with LibreOffice, then activates it with the same checks as `template_activate.php`. `created_by` / `activated_by` is the `gem` admin, or NULL when that account is missing.
- **Re-runs:**
  - v1 active → "nothing to do";
  - v1 draft → resumes the test render and activation;
  - anything else → stops with a message.

## Field mapping (`summons_values()`)

| Key | Source | Example |
|---|---|---|
| `case_no` | `complaints.case_no` | `BC-2026-0007` |
| `case_title` | current complaint type name | `Noise / Public Disturbance` |
| `complainant_names` | active complainants' `full_name`, in party order, at generation time | `Juan Dela Cruz` |
| `respondent_names` | active respondents' `full_name` (all of them, the case caption) | `Pedro Santos and Maria Reyes`, `A, B and C` |
| `respondent_to` | this respondent's name + `, ` + address when there is one (registered: live `residents.address`; not registered: stored address) | `Pedro Santos, Purok 3` / `Maria Reyes` |
| `hearing_day` | ordinal of the Manila day of `starts_at` | `1st 2nd 3rd 4th … 11th 12th 13th … 21st 22nd 23rd 31st` |
| `hearing_month_year` | `F Y` | `November 2026` |
| `hearing_time` | `g:i` + ` in the morning` (before 12:00) / ` in the afternoon` (12:00 and later) | `9:00 in the morning`, `1:30 in the afternoon` |
| `venue` | hearing venue | `Barangay Hall` |
| `issued_day` / `issued_month` / `issued_year` | generation date, Manila (`hearing_now()`) | `16th` / `November` / `2026` |
| `official_name` | `Hon.` + captain setting | `Hon. Erwin Astronomo` |

Rendered body sentence: "…on the 28th day of November 2026 at 9:00 in the morning at Barangay Hall, then and there…"

## Schema

- `document_types.audience ENUM('resident','internal') NOT NULL DEFAULT 'resident'` (after `status`).
- The Summons type:

  | Column | Value |
  |---|---|
  | name | `Summons` |
  | code | `summons` |
  | audience | `internal` |
  | status | `active` |
  | sort_order | 1000 (internal band) |
  | processing_days | 0 |
  | template_file | `summon_letter.docx` until v1 is activated |

  It is inserted only when no type has code `summons` or name `Summons`. No price row is created.
- 13 fields: source `system`, `text`, required. `official_name` has the same label, source and required flag as the catalogue row. No `official_title`.
- `complaint_documents`:
  - `id`, `complaint_id`, `hearing_id`, `party_id`, `kind ENUM('summons')`, `template_version_id NULL`, `file_uuid CHAR(36) UNIQUE`, `pdf_sha256`, `docx_sha256`, `status ENUM('current','superseded')`, `generated_by`, `generated_at DATETIME` (Manila, from PHP);
  - `active_key = IF(status='current', CONCAT(hearing_id,':',party_id), NULL) STORED` with `UNIQUE (active_key)`: one current summons per hearing + respondent;
  - all foreign keys are `ON DELETE RESTRICT`;
  - `fk_cdoc_version` is added by a guarded ALTER only when `document_template_versions` exists.

## Audience rules (residents never see `internal`)

| Reader | What it does now |
|---|---|
| `document_registry_requestable()` | internal → `null` (request create answers 422 "This document is not available yet.") |
| `document_registry_context()` (request generation only) | internal → `RequestProblem('Document type is unavailable.', 422)` |
| `requestable_types.php` | resident types only |
| `type_fields.php` (resident and official request forms) | resident types only |
| `document_prices/list.php` | never lists a price whose type is internal (Summons has none; defense) |
| `document_prices/update.php` | such a price → 404 "Document price was not found." |
| `type_create` | new types stay `resident`. Their `sort_order` is max(resident) + 1. Names stay unique across audiences. There is no UI for creating internal types |
| `types_list.php` (Document Templates tab) | lists Summons with `audience: internal`. The tab shows the badge "Internal — complaints". Upload, analyze, manual edit, test render and activate work as for any type |
| AI request analysis, request statistics | read `document_requests` only; no Summons request can exist, so no change |

Before the migration, `document_types_audience_ready()` is false and every reader behaves exactly as before.

## Generation (`api/hearings/_summons.php`)

1. Strict body `{hearing_id, party_id}` (positive ints, no other key; otherwise 422). The table must exist (otherwise 503).
2. Rule checks; the first failure answers:

   | Status | Case |
   |---|---|
   | 404 | session not found / party not found |
   | 409 | `Summons can only be issued for an upcoming scheduled session.` (status not `scheduled`, or `starts_at` ≤ now) |
   | 422 | `Summons can only be issued to an active respondent of this complaint.` (complainant, removed, or party of another complaint) |
   | 422 | `This respondent was added after the session was scheduled. Reschedule to include them.` (no attendance row) |

3. `GET_LOCK('summons_<hearing>_<party>', 0)`. If busy → 409 `Summons for this respondent is being generated. Try again shortly.`
4. Configuration:

   | Status | Case |
   |---|---|
   | 503 | `Document signatory is not configured.` (captain empty) |
   | 503 | `Summons template is unavailable.` (type missing or archived, or no active version) |

5. Render in `%TEMP%/barangay-summons-<hex>`, outside any transaction. Any fill or conversion failure → 503 `Summons could not be generated. Try again.` The detail goes to the error log only.
6. Copy both files exclusively to `generated_documents/complaints/<uuid>.pdf` / `.docx`. The folder and its deny `.htaccess` are created on first use.
7. One transaction:
   1. complaint `FOR UPDATE`, then hearing `FOR SHARE` (the same complaint → hearing order the hearing writers use; the hearing guard is not taken);
   2. every rule re-checked;
   3. the current row → `superseded`;
   4. insert the new row;
   5. timeline note `Summons issued for session <n>.` (no names; the status is unchanged).
8. After the commit, audit `Generated summons`, target = case no., details `{"hearing_id":…, "party_id":…, "version":1}`.
9. Any failure deletes the published files and the workspace and releases the lock. No row is written.

## Endpoints (`api/hearings/`, any official; residents and anonymous callers get 401)

| Endpoint | Request | Response |
|---|---|---|
| `summons_generate.php` | POST `{hearing_id, party_id}` | `{document: {id, hearing_id, party_id, status, generated_at, template_version, view_url, download_url}, superseded_id}` |
| `summons_list.php` | GET `?hearing_id=` | `{hearing: {id, session_no, status, starts_at, venue, case_no, upcoming}, can_issue, blocker, respondents: [{party_id, full_name, on_session, party_active, current \| null, superseded_count, history, can_generate, reason}]}` |
| `summons_view.php` | GET `?id=&format=pdf\|docx` | file stream |
| `for_complaint.php` | (existing) | scheduled sessions gain `summons: {issued, total}` |

`summons_view.php`:
- The path comes **only** from the row's `file_uuid`: uuid regex, `realpath` containment in `generated_documents/complaints`, then a PDF/ZIP magic check.
- PDF is served `inline`, DOCX as an `attachment`, with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.
- Filename: `Summons-<case_no>-<respondent-slug>.pdf` / `.docx`.
- Unknown id, bad format, or missing file → 404. Superseded copies stay viewable.

## Privacy

- Residents:
  - no summons endpoint answers them (401);
  - `api/complaints/list.php` returns no summons data;
  - the timeline note names no one.
- The audit log holds ids, the case no. and the version number only. No names, file names or hashes.
- Files sit under `generated_documents/`, which is denied over HTTP by both the root and the folder `.htaccess`.
- No SMS is sent for summons.

## Screens

- **Session window** (scheduled sessions that have not started): "Summons" section.
  - One row per respondent on the session, plus respondents added after scheduling, shown with the reason.
  - Status shows "Not issued", "Issued <date>" and, when there are old copies, "Superseded ×n".
  - Buttons:
    - Generate, or Regenerate after a `confirm()` "This replaces the current copy; the old copy stays in the history.";
    - Open PDF (new tab, for printing);
    - Download DOCX.
  - While generating, a spinner shows, the generate buttons are disabled, and the label reads "Generating…".
  - Server reasons are shown as sent. A blocker (signatory or template missing) shows once.
- **Complaint detail → Mediation:** scheduled sessions show "Summons: x of y issued".
- **Document Templates tab:** Summons row with the "Internal — complaints" badge.

## Template edited after Phase 7

Checked 2026-11-17 on the real database (`barangay_system_test_environment`), read-only. Nothing was activated, discarded or uploaded.

**New letter.** `document_templates/summon_letter.docx` was edited on 2026-11-16 at 23:58 and committed in c142e0c, sha256 `3da9caca…ceaa1`. The body sentence gained a venue blank:

> … on the ____ day of ____________ at ____________ in the morning/afternoon **in _______**, then and there …

The added ` in _______` is a run of its own. The build script's expected paragraph no longer matched it, so complaint-summons failed (2 / 7).

**Versions found (Summons, type id 48).** All were uploaded by `gem` (official id 1).

| id | v | File | Original name | Status | Created | check / test |
|---|---|---|---|---|---|---|
| 25 | 1 | `tpl_48_v1_ba857c07.docx` | summon_letter.docx (build script) | retired | 2026-11-16 23:12:59 | 1 / 1 |
| 26 | 2 | `tpl_48_v2_653e8097.docx` | summon_letter.docx | discarded | 2026-11-16 23:51:48 | 0 / 0 |
| 27 | 3 | `tpl_48_v3_d1cd46f9.docx` | ai-assisted-from-v2.docx | discarded | 2026-11-16 23:55:40 | 0 / 0 |
| 28 | 4 | `tpl_48_v4_cfeb8280.docx` | summon_letter.docx (the new letter, sha `3da9…`) | discarded | 2026-11-16 23:59:44 | 0 / 0 |
| 29 | 5 | `tpl_48_v5_427d69ab.docx` | ai-assisted-from-v4.docx | discarded | 2026-11-17 00:01:40 | 0 / 0 |
| 30 | 6 | `tpl_48_v6_0ef61270.docx` | manual-edit-from-v5.docx | **active** (since 2026-11-17 00:02:14) | 2026-11-17 00:01:53 | 1 / 1 |

`document_types.template_file` points at v6. The file's sha256 equals the stored sha256 and tested_sha256 (`3ea11296…e924c9`).

**Checks on the active v6.**
- Placeholders: `case_no, case_title, complainant_names, respondent_names, respondent_to, hearing_day, hearing_month_year, hearing_time, venue, issued_day, issued_month, issued_year, official_name`. All 13 registered keys appear exactly once and there are no others. **Pass.**
- No `____` is left in the rewritten paragraphs. There are 8 of them (5, 6, 7, 9, 11, 13, 16, 18); the body sentence carries 4 placeholders. **Pass.**
- `document_template_check` (registered keys, required present, no stray braces): **OK.**
- Test render (fill + LibreOffice) in a temp folder, which was removed afterwards: **valid PDF**, 194 134 bytes.
- Main sentence in v6:
  > You are hereby summoned to appear before me in person, together with your witnesses, on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} **in the morning/afternoon** in {{venue}}, then and there …
- **Wording defect (not one of the listed checks).** v6 keeps the literal " in the morning/afternoon". `{{hearing_time}}` is already "9:00 in the morning", so every summons from v6 reads:
  > … at 9:00 in the morning **in the morning/afternoon** in Barangay Hall, then and there …

  To fix it, delete " in the morning/afternoon" from v6 with a manual edit in the Document Templates tab, then test and activate the new version. A version built by the script from the new letter does exactly that. Neither was done: activation is the developer's call.

**Wording fixed in the app (2026-11-17, by the developer).** These checks were re-run read-only afterwards.

| id | v | File | Original name | Status | Created | check / test | Notes |
|---|---|---|---|---|---|---|---|
| 30 | 6 | `tpl_48_v6_0ef61270.docx` | manual-edit-from-v5.docx | **retired** | 2026-11-17 00:01:53 | 1 / 1 | |
| 31 | 7 | `tpl_48_v7_26c35f58.docx` | Summons_v6.docx | discarded | 2026-11-17 01:01:25 | 1 / 0 | copy of v6 for wording fix (same sha as v6) |
| 32 | 8 | `tpl_48_v8_9cd1e685.docx` | manual-edit-from-v7.docx | **active** (since 2026-11-17 01:12:04) | 2026-11-17 01:11:37 | 1 / 1 | Manual edits from v7: 1 edit(s) applied |

There is no v9 or v10. `document_types.template_file` points at v8. The file's sha256 equals the stored sha256 and tested_sha256 (`6ab2c08b…87276a`).

Checks on the active v8:
- All 13 keys appear exactly once and there are no others. **Pass.**
- No `____` is left in the 8 rewritten paragraphs. **Pass.**
- `document_template_check`: **OK.**
- Test render in a temp folder, which was removed afterwards: **valid PDF**, 194 122 bytes.
- Main sentence:
  > You are hereby summoned to appear before me in person, together with your witnesses, on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}}␣␣in {{venue}}, then and there …

  Rendered: "… at 9:00 in the morning␣␣in Barangay Hall, then and there …". "In the morning/afternoon" is gone.
- **Remaining cosmetic issue:** there were **two spaces** before "in {{venue}}" (shown as ␣␣). The manual edit removed " in the morning/afternoon" and left the space that came before it.

Summons documents on the real database at that check:
- one **current** row, generated from v8;
- one **superseded** row, generated from v1.

No current row was generated from v6, so nothing needed regenerating.

**Double space fixed in the app (2026-11-17, by the developer).** Re-checked read-only afterwards.

| id | v | File | Original name | Status | Created | check / test | Notes |
|---|---|---|---|---|---|---|---|
| 32 | 8 | `tpl_48_v8_9cd1e685.docx` | manual-edit-from-v7.docx | **retired** | 2026-11-17 01:11:37 | 1 / 1 | |
| 33 | 9 | `tpl_48_v9_65793552.docx` | Summons_v6.docx | discarded | 2026-11-17 01:22:35 | 1 / 0 | a copy of **v6** (same sha `3ea11296…`), not of v8 |
| 34 | 10 | `tpl_48_v10_78680c60.docx` | manual-edit-from-v9.docx | discarded | 2026-11-17 01:23:18 | 1 / 1 | Manual edits from v9: 1 edit(s) applied |
| 35 | 11 | `tpl_48_v11_ab46e370.docx` | manual-edit-from-v10.docx | **active** (since 2026-11-17 01:25:20) | 2026-11-17 01:25:00 | 1 / 1 | Manual edits from v10: 1 edit(s) applied |

`document_types.template_file` points at v11. The file's sha256 equals the stored sha256 and tested_sha256 (`557d8e62…09aa75`).

Checks on the active v11:
- All 13 keys appear exactly once and there are no others. **Pass.**
- No `____` is left in the 8 rewritten paragraphs. **Pass.**
- `document_template_check`: **OK.**
- Test render in a temp folder, which was removed afterwards: **valid PDF**, 194 118 bytes.
- Main sentence (single spaces):
  > You are hereby summoned to appear before me in person, together with your witnesses, on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} at the {{venue}}, then and there …

  Rendered: "… at 9:00 in the morning at the Barangay Hall, then and there …".
- **Note on wording.** v11 says "at the {{venue}}". The build script's output from the letter says "in {{venue}}". A venue typed with its own article (for example "the Covered Court") would read "at the the Covered Court". Venues are typed when a session is scheduled.

Summons documents on the real database at this check:
- one **current** row, generated from v11;
- two **superseded** rows, generated from v1 and v8.

No current row was generated from v6 or v8.

**Code changes (no change to summon_letter.docx).**
- `project-support/scripts/build-summons-template.php`:
  - **Current letter:** one pass of 13 edits gives "… at {{hearing_time}} in {{venue}}, then and there …". " in the morning/afternoon" is removed and the blank becomes `{{venue}}`.
  - **Old letter** (no "in _______"): still produces "… at {{hearing_time}} at {{venue}}, …" in two passes, as before.
  - **Any other wording:** the same "Expected paragraph not found: You are hereby summoned …" error.
  - **Nothing to do:** the script now answers "nothing to do" when *any* Summons version is active. Before, a retired v1 (the real database's state) made it fail with "v1 exists with status retired".
  - **Library mode:** the script can be included with `SUMMONS_BUILD_LIBRARY` defined. Its functions are then available and nothing runs.
  - **Real database, dry run:** "Summons v6 is already active. Nothing to do.", source sha unchanged. `--apply` was not run on the real database.
- `project-support/tests/complaint-summons/run.php`:
  - There was no fixed sha pin; the suite already compared the real letter's sha before and after, and still does.
  - **(a):** the body sentence now expects the new wording.
  - **(b):** the PDF text expects "9:00 in the morning in Barangay Hall" and no "morning/afternoon".
  - **New in (b):** the old wording, built from a fixture copy of the letter in test storage (the venue run removed). It must build as "at {{hearing_time}} at {{venue}}" and render "at 9:00 in the morning at Barangay Hall".
  - **New in (b):** any other wording fails with the expected error.
  - **New in (b):** the real letter is unchanged by the fixture work.

**Results (2026-11-17).**

| Suite | Result |
|---|---|
| complaint-summons | **70 / 70** (67 + 3 new) |
| review-2026-10-24 | 438 / 438 |
| complaint-types live, real database | 107 / 107 |
| document-baseline, registry, templates, templates endpoints, forms (+ frontend), manual-edit, type-create, apply, analysis | passed, 28, 11, 76, 126 (60), 162, 153, 117, 104 (all pass) |

## Tests

### Results (2026-11-16)

| Suite | Before (Phase 6 doc) | After | Failed |
|---|---|---|---|
| **complaint-summons** (new, scratch DB + TestStorage + LibreOffice) | — | **67 / 67** | 0 |
| complaint-types, live, **real database** (not migrated) | 100 / 100 | **100 / 101** | 1 (expected: `P7 complaint_documents and document_types.audience exist`) |
| complaint-types, live, throwaway copy of the real database + 20261119 (run twice) | — | 107 / 107 (100 + 7) | 0 |
| complaint-hearings `frontend.js` (Node) | 29 / 29 | 40 / 40 (29 + 11) | 0 |
| complaint-types / complaint-parties `frontend.js` | 26 / 22 | 26 / 22 | 0 |
| document-baseline (goldens) | 6 passed | 6 passed | 0 |
| document-registry | 27 / 27 | 28 / 28 (+1 Summons check) | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |
| document-templates endpoints | 76 / 76 | 76 / 76 | 0 |
| document-forms + `frontend.js` | 126 / 60 | 126 / 60 | 0 |
| document-manual-edit | 162 / 162 | 162 / 162 | 0 |
| document-type-create | 153 / 153 | 153 / 153 | 0 |
| document-apply | 117 / 117 | 117 / 117 | 0 |
| document-analysis | 104 / 104 | 104 / 104 | 0 |
| storage-override | — | 14 / 14 | 0 |
| request-concurrency + `frontend.js` | — | 81 / 81, 14 / 14 | 0 |
| review-2026-10-24 (whole runner) | 413 / 413 | 414 / 414 | 0 |

- The review runner's +1 (types-seed 155 → 156) is `P6 [seed] database.sql has no line starting with USE or CREATE DATABASE`. That check was already in the uncommitted `complaint-types/run.php` before Phase 7; the same seed run with the committed file gives 155. Phase 7 checks run in live mode only.
- The real `document_templates/` and `generated_documents/` were proven untouched: a sha256 listing of 24 files before and after the full run was identical. `summon_letter.docx` sha256 is `ae818c4a…6d2aa` before and after.
- The first run found two real problems, both fixed:
  1. Re-running `20261110` added `official_title` to Summons. It is now guarded.
  2. The build script left its temp workspace, because `exit()` skips `finally`. Cleanup now runs at shutdown.
- `php -l` and `node --check` pass on every touched file.
- Not run by the assistant: the browser checklist below (layout, printing, Word opening the DOCX).

### Assertions changed (old → new) and why

The Summons type is now part of a fresh install, so 5 existing checks that read *every* type were narrowed to the resident types. A new check covers Summons exactly; nothing was loosened.

| Suite / check | Old query | New query | Covered for Summons by |
|---|---|---|---|
| document-registry "both installs contain 40 registry fields" | `SELECT COUNT(*) FROM document_type_fields` | same, joined to `document_types` with `audience='resident'` | new check "both installs: internal Summons type with 13 system fields" |
| document-type-create "fresh install: five seeded types carry their codes" | `SELECT name, code FROM document_types ORDER BY sort_order` | `… WHERE audience = 'resident' …` | complaint-summons setup check (code, audience, sort_order) |
| document-type-create "migration: seeded codes filled" | same query after the pre-5A strip | `… WHERE audience = 'resident' …` (the strip drops `code`; 20261115 only knows the five seed names) | — |
| document-type-create "catalogue: identical to the seeded field definitions" | all `document_type_fields` | resident types only | complaint-summons "v1 placeholders are exactly the 13 Summons keys" + registry check |
| document-type-create "catalogue: exactly the seeded key set" | all `document_type_fields` | resident types only | same |

Fixture change: document-templates/endpoints.php simulates a pre-Phase-2 install by dropping `document_template_versions`. It now drops `complaint_documents` first, as it already did for `document_template_analyses` (both reference the versions table).

## Running the migration (you)

1. Back up `barangay_system_test_environment` (for example to `C:\laragon-backups\barangay_before_summons.sql`, with `cmd /c "mysqldump … > file"`).
2. Restore the backup to a test copy. Run `20261119_summons.sql` on the copy twice; the second run changes nothing.
3. Run `php project-support/tests/complaint-types/run.php --db=<copy>`. Expect 107 / 107.
4. Run `php project-support/scripts/build-summons-template.php --database=<copy>` (dry run). Expect `template check: OK` and the same sha before and after.

   Do **not** run `--apply` against a copy: it writes the v1 file into the real `document_templates/`.
5. Run the migration on the real database. Then `php project-support/tests/complaint-types/run.php`: expect 107 / 107.
6. Run `php project-support/scripts/build-summons-template.php` (dry run), then `php project-support/scripts/build-summons-template.php --apply`.

   Expect `stored v1 as draft`, `test render: OK`, `activated Summons v1`, and the same source sha before and after. It writes one preview PDF to `generated_documents/previews/`, like the UI Test render.
7. Deploy the Phase 7 code. The code works before the migration: no filters apply and summons answer 503.

## Rollback

Preferred: restore the backup from step 1.

To undo only Phase 7 instead (**this deletes every generated summons record; there is no undo**):

```sql
DROP TABLE complaint_documents;
DELETE v FROM document_template_versions v JOIN document_types t ON t.id = v.document_type_id WHERE t.code = 'summons';
DELETE FROM document_types WHERE code = 'summons';           -- its fields go with it (ON DELETE CASCADE)
ALTER TABLE document_types DROP COLUMN audience;
```

Then delete by hand:
- `generated_documents/complaints/*.pdf|*.docx`;
- the `tpl_<summonsTypeId>_v1_*.docx` file in `document_templates/`;
- the guarded lines in 20261109 / 20261110 can stay; they are harmless.

Roll back the code at the same time, because `_registry.php` and the prices and type endpoints read `audience` once it exists.

## Manual browser checklist

1. Document Templates tab shows **Summons** with "Internal — complaints" and an active v1. Test render works.
2. Schedule a session for a complaint with 2 respondents, then generate both summons. Open the PDFs and check:
   - layout;
   - ordinal day;
   - time wording ("9:00 in the morning");
   - venue;
   - "Hon. <captain>";
   - both names in "Respondent/s".
3. Change the Captain setting, regenerate, and check the new name. The old copy still opens.
4. Print one PDF (A4/Letter, as the other documents).
5. As a resident:
   - no summons visible anywhere;
   - Summons is not in the request form or the prices.
6. On a completed session, Generate is not offered; the session window shows no Summons section.

# Document Management module — overview

One-page guide to the whole module for the panel. Detail for each phase lives in
`archive/document-management-phase0.md` … `archive/document-management-phase5b.md`; this file is the map.

## 1. What the module does

Barangay staff manage the Word templates behind every printed document (clearance,
residency, indigency, good moral character, business permit endorsement, and any type
added later). A template is a `.docx` file with `{{placeholder}}` keys. When a resident's
request is processed, the server fills the placeholders, converts the result to PDF with
LibreOffice, and stores the output.

Design rules that hold everywhere:

- Templates are DOCX only and stay editable in Word. PDFs are output, never templates.
- A stored template file is never overwritten. Every change is a **new version**.
- Nothing is published without a passing check **and** a test render of that exact file.
- AI only suggests. A person confirms every change.
- "Remove" means archive. Document types are never hard-deleted, because requests refer to them.

## 2. Roles

| Action | Resident | Staff | Admin (Captain) |
|---|---|---|---|
| See requestable document types and their request fields | yes | yes | yes |
| Submit a document request | yes | – | – |
| View template list, version history, download a version | – | yes | yes |
| Upload a version, test render, activate, roll back, discard a draft | – | yes | yes |
| AI analysis, apply suggestions, manual edits | – | yes | yes |
| Add a document type | – | yes | yes |
| Archive / unarchive a document type | – | – | yes |
| Change the signatory (captain name) | – | – | yes |
| Process a request, generate and view the PDF | – | yes | yes |

The server enforces this in each endpoint (`require_official_session()`,
`require_official_admin()`, or `enforce_active_session()` in `api/common.php`); the
buttons in the page only mirror it. Every session is re-validated against the account's
current status and role on each call, so a demoted or deactivated official loses access
immediately. The signatory printed on a document is a setting, not a login account.

## 3. Flow, from upload to a generated document

1. **Document type exists.** Five types are seeded. Staff can add more (section 6).
2. **Upload** (`template_upload.php`). `.docx` only, 2 MB limit. The archive is inspected
   before it is kept: ZIP signature, required parts, at most 300 entries, 25 MB
   uncompressed, compression-ratio (zip bomb) check, unsafe paths, macros, embedded
   objects and external relationships are all refused. The file is stored under a
   server-generated name (`tpl_<type>_v<n>_<random>.docx`) with its SHA-256.
3. **Template check.** The placeholders found in the file are compared with the fields
   registered for that type. Unknown, retired, malformed or missing required
   placeholders are listed as check errors and block activation.
4. **Test render** (`template_test_render.php`). The draft is rendered with fictional
   sample data and converted to PDF; staff see the PDF preview. A successful render
   records the hash of the file that was tested.
5. **Activate** (`template_activate.php`). Allowed only when the check passed, the test
   render passed, and the file on disk still has the tested hash. The previous active
   version becomes *retired*. The request carries the version the official was looking
   at, so two people activating at once cannot silently overwrite each other.
6. **Resident requests** a document. The request form is built from the registry
   (`type_fields.php`): only fields marked "resident supplies" are asked, and only types
   that are not archived **and** have an active version are offered.
7. **Staff generate** the document. Values come from four sources: system (date,
   reference number, signatory), the resident's record, the resident's request, and
   fields staff type in the modal. The server validates them, fills the active template,
   converts to PDF, and publishes the files in one transaction under a short lease so a
   retry or a second click cannot produce two documents.
8. **Viewing the result.** The PDF is read through `api/requests/view_document.php`,
   which requires an official session. Already generated files never change when a
   template or the signatory changes later.

## 4. Versioning and rollback

- Version states: `draft` → `active` → `retired`; a draft can be `discarded`.
- The database allows exactly one active version per type (unique key on a generated column).
- Rollback is activating a retired version again. It passes the same gate as a new
  version: a fresh test render of the stored file, and the stored checksum must still match.
- History shows who uploaded and who activated each version, and when.
- Every upload, test render, download, activation, discard, archive and type creation is
  written to the audit log. Audit rows hold counts and ids only, never document text,
  file names or hashes.

## 5. AI assistance: consent and limits

Purpose: after staff upload a plain Word document, Gemini proposes which pieces of text
should become placeholders (for example the resident's name → `{{full_name}}`).

- **Consent.** Each analysis needs a fresh tick of "I understand the document text will
  be sent to Google Gemini for analysis." The server rejects the call without it. Only
  the template text is sent; no resident data is involved, because drafts hold sample wording.
- **Suggest-only.** The analysis endpoint never changes a file. It stores suggestions.
- **Server validation of every suggestion.** The text must exist at the stated position,
  the key must be one registered for that type, suggestions must not overlap, and the
  list is capped. Anything else is dropped and counted.
- **Limits.** Draft versions only; at most 200 paragraphs and 20,000 characters; at most
  40 suggestions; 30-second timeout; one analysis per version at a time (a second call
  gets HTTP 409 with `code: "analysis_busy"`); a per-official hourly AI budget.
- **Staleness.** An analysis is tied to the hash of the file it read. If the file is no
  longer the same, the suggestions cannot be applied.
- **Applying** (`template_apply_suggestions.php`). Staff tick the suggestions they
  accept. The result is always a **new draft version**; the analysed version is left as
  it was. The new draft then goes through check → test render → activate like any upload.
- **Prompt injection.** Document text is treated as data. Model output is parsed as
  JSON, validated field by field and never executed.
- **AI switched off.** With no `GEMINI_API_KEY` the Analyze button is disabled and the
  rest of the module works unchanged (manual edits, or mark placeholders in Word).

## 6. Adding and archiving document types

- **Add** (`type_create.php`, staff or admin). Name, optional code, fee, processing days,
  requirements, and a choice of fields from a fixed catalogue. Each catalogue field has a
  source (system / resident record / resident supplies / staff enters). Date issued,
  reference number and the signatory fields are always included. Names and codes are unique.
- A new type has **no active version**, so it is invisible to residents until a template
  is uploaded, tested and activated. The list marks every such type with a
  "No active version" badge.
- **Archive** (`type_archive.php`, admin only). An archived type is no longer offered to
  residents and can be unarchived. Existing requests and versions are kept.

## 7. Manual template edits

In the review screen staff can, without Word and without AI:

- turn selected words inside one paragraph into a registered placeholder,
- turn a whole placeholder back into text,
- replace selected wording with new text.

Rules: draft versions only, at most 40 edits per save, edits must not overlap or cut
through an existing placeholder, new text cannot contain braces or control characters,
and the save names the hash of the file the official was looking at — if the file
changed meanwhile the save is refused. Saving always creates a new draft version.

## 8. Storage and access

| Location | Holds | Direct HTTP access | Read through |
|---|---|---|---|
| `document_templates/` | template versions | denied | `template_download.php` (official session) |
| `generated_documents/` | generated DOCX + PDF | denied | `api/requests/view_document.php` (official session) |
| `generated_documents/previews/` | test-render PDFs (short-lived) | denied | `template_preview.php` (official session, random token) |
| `complaint_attachments/` | complaint evidence | denied | `view_attachment.php` (active session + ownership) |
| `database/`, `project-support/`, dotfiles, `*.sql` | schema, tests, docs, secrets | denied | command line only |

Every page and API response also carries `X-Content-Type-Options: nosniff`,
`Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN` and a Content Security
Policy (`send_security_headers()` in `db.php`). Pages may load scripts and styles only
from the site itself, the two font/icon CDNs, or blocks carrying a per-request nonce;
API responses use `default-src 'none'`. The folder rules are Apache `.htaccess` files and
are not applied by PHP's built-in server.

Known limits, stated honestly: inline `onclick` and `style` attributes are still allowed
by the policy (removing them is a refactor), there is no CSRF token layer beyond
SameSite cookies, and profile photos are served directly.

## 8a. Document-request report (AI Analytics tab)

One function, `request_report_data()` in `api/stats/_requests_report.php`, feeds both
the report screen (`api/stats/requests_report.php`) and the AI summary
(`api/ai/analyze_document_requests.php`), so the two can never show different numbers.

- **By type** lists every registered resident document type, including archived ones
  and types with 0 requests, with "Archived" and "No active version" badges. Older
  request names that are no longer in the registry still appear, marked "Not in registry".
- **Cancelled requests** stay in the request count and the status counts, but are left
  out of copies and of every amount (gross, collected, pending, waived). Money that was
  collected for a request cancelled later is shown on its own line so a refund can be
  checked. *Gross now excludes cancelled requests (−₱105.00 on current data).*
- **Internal types** (the complaint Summons) are not resident requests and are left out
  of every figure; the count of such rows is reported so totals reconcile with the table.
- **AI summary**: sends only an allowlist of counts, amounts and type names (no resident
  data). Order of checks: no data → AI not configured (503, no budget spent) → hourly AI
  budget (429) → model. One audit row "Generated document-request AI summary" per
  successful summary, with no data in its details. The complaints AI summary now uses the
  same budget (1 unit) and audit pattern ("Generated complaint AI summary").

## 9. Database objects

| Table | Purpose |
|---|---|
| `document_types` | registry of types (name, code, processing days, requirements, status); the fee is a `document_prices` row |
| `document_type_fields` | placeholders per type with source, data type and limits |
| `document_template_versions` | versions with file name, checksums, check and test results, status |
| `document_template_analyses` | stored AI suggestions per version and file hash |
| `document_settings` | signatory (captain name) |

Schema changes ship as additive, re-runnable files in `project-support/migrations/` and
are folded into `database/database.sql`. There is no migration runner; migrations are run
by hand after a backup.

## 10. Tests and safety rules

Suites (all under `project-support/tests/`, run from the project root):

| Suite | Covers |
|---|---|
| `document-baseline` | golden output for the five seeded types |
| `document-registry` | registry, migration equals fresh install, signatory, date formatting |
| `document-templates` (`run.php`, `endpoints.php`) | upload validation, versions, activate/rollback, roles, error codes |
| `document-analysis` | AI suggest-only pipeline with a fake model, validation, throttle, locking |
| `document-apply` | applying suggestions into a new draft, multi-run text, audit content |
| `document-manual-edit` | manual edit operations, limits and conflicts |
| `document-type-create` | add/archive types, uniqueness, visibility |
| `document-forms` (`run.php`, `frontend.js`) | registry-driven request and staff forms, legacy fallback |
| `request-concurrency` (`run.php`, `frontend.js`) | request workflow, idempotent retries, generation lease |
| `review-2026-10-24` (`run.php`, `render-check.js`), `complaint-interface`, `storage-override`, `maintenance` | access, rendering safety, storage configuration, maintenance scripts |

Rules every suite follows:

- **Never the real database.** Each suite creates a `capstone_*` scratch database,
  asserts the name before writing, and drops it at the end.
- **Never the real folders.** Template and output files go to a temp folder
  (`tests/_storage.php`); the real `document_templates/` is only read. The folders are
  hashed before and after a run to prove nothing changed.
- **Never a real AI or SMS call.** Provider keys are blanked before anything loads and
  the model is replaced by a fake.
- **No credentials** in tests, fixtures or source.
- **Tests are not weakened to pass.** A wrong assertion is corrected only with the old
  and new line and the reason recorded.
- Data fixes on a real install are scripts or migrations run by the developer after a
  backup (example: `project-support/scripts/backfill-template-sha.php`, dry run by default).

# Document Management Phase 5A: Add / Archive Document Type

Any official (staff or admin) can add a **new document type** from the
Document Templates tab. The new type then gets its first DOCX upload as the
v1 draft and goes through the usual flow:
Upload -> (Analyze) / Review / edit -> Check -> Test render -> Activate.

A type that has **no active template version** is hidden from residents and
cannot be requested, even when it is not archived. Types are **archived,
never deleted** (archiving stays admin-only, as before).

Files:

| File | Change |
|---|---|
| `project-support/migrations/20261115_document_type_create.sql` | new: `code`, `processing_days`, `requirements`, `created_by` on `document_types`; unique `document_type_code`; FK `fk_document_types_created_by`; codes for the five seeded types. Every change guarded through `information_schema` (MySQL 8 and MariaDB, safe to re-run) |
| `database/database.sql` | same columns/key/FK in `CREATE TABLE`, plus the same guarded block after the seed so an older install is upgraded too |
| `api/documents/_type_create.php` | new: field catalogue, validation, code derivation, friendly pre-check, transactional insert |
| `api/documents/type_create.php` | new: `POST` create a type |
| `api/documents/requestable_types.php` | new: `GET` types a resident can request now |
| `api/documents/_registry.php` | `document_types_phase5a_ready()`, `document_registry_requestable()` |
| `api/documents/types_list.php` | adds `catalogue`, `can_create`, and `code` / `processing_days` / `requirements` per type (existing keys unchanged) |
| `api/requests/create.php` | new check: no active version -> 422 |
| `pages/official.php`, `js/document-templates.js`, `css/official.css` | "Add document type" button and modal; "no active version" row state |
| `pages/resident.php`, `js/resident.js`, `css/resident.css` | document dropdown built from `requestable_types.php`; processing days / requirements hint |
| `project-support/tests/document-type-create/run.php` | new suite |

## Migration

Back up the database and test on a copy first. The suite applies it only to
scratch databases; run it on the real database yourself:

    mysql -u root barangay_system_test_environment < project-support/migrations/20261115_document_type_create.sql

Until it runs, the console still works: **Add document type** is disabled
(`can_create: false`) and `type_create.php` answers 503 "Adding document
types needs the 20261115 database migration."; `requestable_types.php` falls
back to `NULL` processing days / requirements.

Rollback (**only before any new type exists**; it drops the columns and the
codes):

    ALTER TABLE document_types DROP FOREIGN KEY fk_document_types_created_by, DROP INDEX fk_document_types_created_by, DROP INDEX document_type_code, DROP COLUMN code, DROP COLUMN processing_days, DROP COLUMN requirements, DROP COLUMN created_by;

## Screen flow

1. Document Templates -> **Add document type** (above the Template Versions
   table).
2. Modal: name, code (placeholder "auto"; filled from the name as you type
   until you type in the code box yourself), fee, processing days,
   requirements (shown to residents), and a placeholder checklist: label,
   `{{key}}`, source badge. `date_issued`, `reference_no`, `official_name`,
   `official_title` are ticked, disabled and badged "always included".
   `full_name`, `address`, `purpose` are ticked by default and can be
   unticked. Note under the list: "Every required field must appear in the
   first DOCX you upload."
3. **Create** -> toast "Document Type Created", the table reloads, and the
   new row is highlighted with "No active version — upload a template to
   publish" and a primary **Upload template** button.
4. Upload v1 -> it is saved as a draft and checked against the type's fields
   (required keys must be present, unknown keys refused) -> History ->
   Review / edit if needed -> Test render -> Activate.
5. From that moment the type appears in the resident request form.

All server text is written with `textContent`; the modal's listeners are
attached to the modal's own form and inputs; no new libraries.

## API

`POST api/documents/type_create.php` (official session, any role)

    {"name": "...", "code": "" | "snake_code", "fee": "25.00", "processing_days": 2,
     "requirements": "...", "field_keys": ["full_name", "address", "purpose"]}

Response `{"success": true, "type_id": 7, "code": "certificate_of_solo_parent"}`.
No file names or paths.

- **name**: trimmed, inner whitespace collapsed, 3–100 characters, must
  contain a letter; no control, line-separator or bidi characters.
- **code**: if empty, derived from the name: accents dropped (`ñ` -> `n`,
  `é` -> `e`), lowercased, every run of other characters -> `_`, trimmed,
  `doc_` prefixed when it would start with a digit, cut to 50. A typed code is
  used as-is. Either way it must match `^[a-z][a-z0-9_]{2,49}$`.
- **fee**: number or `"1234.56"` string, 0.00–9999.99, at most 2 decimals
  (same cap as `document_prices/update.php`). Written to `document_prices`
  in the same transaction.
- **processing_days**: whole number 0–60.
- **requirements**: optional, up to 1000 characters; newlines allowed, other
  control characters refused. Empty -> `NULL`.
- **field_keys**: a list, each key from the catalogue (below). The four
  system keys are always added. Rows are copied from the catalogue
  (label, source, type, limits, required) in catalogue order.
- The row is inserted with `status='active'`, `template_file=''`,
  `sort_order = MAX+1`, `created_by` = session official.
- Audit: action "Created document type", target `document_type` / id,
  details `Name: …; Code: …` only (no fee, requirements or fields).

`GET api/documents/requestable_types.php` (resident **or** official session)
returns `{"types": [{"name", "fee", "processing_days", "requirements"}]}` for
types that are `active` **and** have an active template version, in
`sort_order`. No ids or status.

### Field catalogue

The renderer can only fill keys it knows, so a new type picks from the keys
already seeded (`DOCUMENT_FIELD_CATALOGUE` in `_type_create.php`; the suite
asserts it equals the seeded rows):

| Key | Source | Required |
|---|---|---|
| full_name, owner_name, address, business_address | resident_record | yes |
| age (int 0–120), years_of_residency (int 0–120) | resident_supplied | no |
| civil_status, business_name, business_type (max 100) | resident_supplied | no |
| purpose, date_issued | staff_entered | yes |
| reference_no, official_name, official_title | system | yes |

## Errors

| Status | Message | When |
|---|---|---|
| 401 | `Unauthorized` | no official session (resident sessions included) |
| 405 | `Method not allowed.` | not POST |
| 422 | `Enter a document name of 3 to 100 characters.` | name missing, not text, too short/long, or no letter (also invalid JSON) |
| 422 | `The document name contains characters that are not allowed.` | control / newline / bidi characters |
| 422 | `Code must be 3 to 50 lowercase letters, numbers or underscores and start with a letter.` | bad typed code, or the name yields no usable code (e.g. `ÑÑ!`) |
| 422 | `Fee must be a number from 0.00 to 9999.99.` | |
| 422 | `Processing days must be a whole number from 0 to 60.` | |
| 422 | `Requirements must be 1000 characters or fewer.` | also when not text |
| 422 | `Requirements contain characters that are not allowed.` | |
| 422 | `Choose fields from the list only.` | not a list, unknown/retired key, more entries than the catalogue |
| 409 | `A document type with this name already exists.` | same name ignoring case/outer spaces, including archived types, **or** an orphan `document_prices` row with that name (never overwritten) |
| 409 | `A document type with this code already exists.` | |
| 503 | `Adding document types needs the 20261115 database migration.` | migration not applied |
| 500 | `Something went wrong. Please try again.` | database failure (detail only in the server log) |

Request API (`api/requests/create.php`), order of checks:

1. type unknown or archived -> 422 `This document type is no longer available.` (unchanged)
2. **new:** no active template version -> 422 `This document is not available yet.`
3. no price row -> 422 `This document type has no posted price. Please contact the barangay hall.` (unchanged)

## Uniqueness and races

- The friendly pre-check uses `LOWER(TRIM(name)) = LOWER(?)` on
  `document_types` and on `document_prices`, and `code = ?`.
- The **unique keys decide**: `document_type_name`, `document_type_code` and
  `document_prices.document_type`. Type, fields and price are inserted in
  one transaction; a duplicate-key error (1062) rolls everything back and is
  mapped to the 409 above (by key name). Two processes that both pass the
  pre-check end with one type and one 409 (tested).
- Comparison follows the column collation. All of them are case-insensitive;
  under MySQL 8's default `utf8mb4_0900_ai_ci` (and the suites'
  `utf8mb4_unicode_ci`) **accents are ignored too**, so "Cédula" and "Cedula"
  clash. The pre-check's `LOWER()` is only a convenience.

## Visibility rule

A resident sees and can request a type only when **status = active AND an
active template version exists** (`document_registry_requestable()`):

- Server: `api/requests/create.php` (the real gate) and
  `requestable_types.php` (the list).
- Resident form: the dropdown is rebuilt from `requestable_types.php`. If
  that call fails, the built-in options stay and the error goes to the
  console only; the server still refuses anything not requestable.
- Generation was already gated: `document_registry_context()` answers 503
  "Document template is unavailable." without an active version.
- Staff console: every type is listed; a type without an active version is
  marked "No active version — upload a template to publish".

## What Phase 5B will wire

5A stores the fields a type carries; two hardcoded lists still decide the
forms:

- `DOC_REQUEST_EXTRA_FIELDS` in `js/resident.js`: a new type shows **no extra
  inputs** today. 5B builds them from the type's `resident_supplied` rows
  (`data_type`, `min/max_value`, `max_length`, `is_required`), which
  `create.php` already whitelists from the registry.
- `DOC_TEMPLATE_FIELDS` in `js/official.js`: the staff generation modal shows
  "Template Missing" for a new type, so **staff cannot generate it yet**. 5B
  builds the modal from the type's `staff_entered` and `resident_record` rows
  (prefilled from the resident record / request as today) and hides
  `system` fields.
- 5B should also make `is_required` resident fields required in the form and
  on the server.

## Tests

    php project-support/tests/document-type-create/run.php

153 checks: code derivation (ñ, accents, spaces, symbols, leading digits,
length), every 422 with its exact message, defaults and always-included
fields, catalogue = seeded rows, fresh-install schema, migration from a
pre-5A table (schema equals a fresh install, `updated_at` untouched, second
and third runs change nothing), create (rows, fields, price, created_by,
audit with name and code only), every 409 (case, whitespace, archived,
code, orphan price), unique keys with the pre-check bypassed (rollback, no
partial rows), a real two-process race, endpoints (401/405/422/409/200,
response shape without paths), `requestable_types` shape and visibility,
request API order of checks, and the full lifecycle on a test server
(upload v1 -> test render -> activate -> visible and requestable -> archive
-> hidden and refused). Scratch DB `capstone_typecreate_*`, TestStorage temp
storage, real database read-only row counts before/after.

## Manual checklist

Use a test server (scratch DB + temp storage), never real storage.

- [ ] Document Templates shows **Add document type** above the table (disabled with a tooltip before the migration).
- [ ] Type a name -> the code fills ("Año 2026 Cert." -> `ano_2026_cert`); type in the code box -> further name edits no longer change it.
- [ ] Checklist: four system fields ticked, disabled, "always included"; full_name/address/purpose ticked; note about required fields visible.
- [ ] Duplicate name in another case -> "A document type with this name already exists." inside the modal.
- [ ] Create -> toast, row highlighted, "No active version — upload a template to publish", primary **Upload template**.
- [ ] Resident portal: the new type is not in the dropdown; a forced API request returns "This document is not available yet."
- [ ] Upload v1 (a DOCX with exactly the ticked keys) -> draft, check passes -> Review / edit -> Test render -> Activate.
- [ ] Resident portal (reload): the type appears with the processing days / requirements hint; submitting works.
- [ ] Admin archives it -> gone from the resident dropdown; staff-only accounts see no Archive button.

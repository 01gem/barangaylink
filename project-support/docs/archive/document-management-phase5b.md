# Document Management Phase 5B: Registry-Driven Request Forms

The resident request form and the staff generation modal are now built from
the document registry, so a type added in the console (Phase 5A) works end to
end: residents see its `resident_supplied` inputs, staff can generate it, and
"Template Missing" no longer blocks a registered type.

The five seeded types look and behave as before, except for the items listed
under **Changes to the seeded forms**.

No migration. No `database.sql` change.

Files:

| File | Change |
|---|---|
| `api/documents/type_fields.php` | new: `GET` field definitions for the two forms |
| `api/documents/_registry.php` | `DOCUMENT_FIELD_MESSAGES`, `document_field_rule_error()` (the one rule implementation), `document_validate_staff_fields()` |
| `api/requests/_generation.php` | `request_generation_validate_fields()` |
| `api/requests/generate_document.php` | validates the staff fields before the operation is claimed; honours the test-only storage override for generated files |
| `api/requests/create.php` | 422 when a resident field the registry marks required has no usable value |
| `js/resident.js` | extra inputs from the registry; `REQUEST_FIELD_HINTS`; legacy list kept as fallback |
| `js/official.js` | modal from the registry; `DOC_FIELD_MESSAGES` + mirror of the rules; client-side block; detail labels; local default date |
| `project-support/tests/document-forms/` | new suite: `run.php`, `frontend.js`, `defs.php`, `golden/seeded_forms.json` |

## Field definitions endpoint

`GET api/documents/type_fields.php` (resident **or** official session; 401
without one, 405 for anything but GET).

Resident session:

    {"success": true, "registry": true, "types": [
      {"name": "Barangay Clearance", "fields": [
        {"field_key": "age", "label": "Age", "data_type": "int",
         "min_value": 0, "max_value": 120, "max_length": null, "is_required": 0},
        {"field_key": "civil_status", "label": "Civil status", "data_type": "text",
         "min_value": null, "max_value": null, "max_length": 100, "is_required": 0}]}]}

- Only types a resident can request now: not archived **and** an active
  template version (same rule as `requestable_types.php`).
- Only `resident_supplied` fields. No `staff_entered`, `resident_record` or
  `system` field, and no `source`, id, status or file name.

Official session: every type, including archived ones (their pending requests
can still be generated) and types without an active version. Each row has
`name`, `status`, `has_active_version` and its **non-system** fields, each
with `source`.

Legacy database (no `document_types` table):
`{"success": true, "registry": false, "types": []}`. Both forms then use
their built-in legacy lists.

`requestable_types.php` and `types_list.php` are unchanged.

## Resident form

- The extra inputs for the selected type come from the type's
  `resident_supplied` rows: `int` -> number input with `min` / `max`; `text`
  -> text input with `maxlength`; `is_required` -> `required`. The label is
  the registry label shown in title case ("Years of residency" ->
  "Years of Residency"). Values are posted under `field_key` as before.
- A type without resident fields shows nothing (the container stays hidden).
- **Presentation hints** (`REQUEST_FIELD_HINTS` in `js/resident.js`) are
  keyed by `field_key`; the registry stays the source of which fields exist.
  A hint only adds what the registry cannot express:

  | field_key | Hint |
  |---|---|
  | `age` | required in the form; prefilled from the resident's birthdate |
  | `civil_status` | required; a select (Single, Married, Widowed, Separated); prefilled from the profile |
  | `years_of_residency` | required |
  | `business_name` | required |
  | `business_type` | required; placeholder "e.g. Sari-sari store, Carinderia, Repair shop" |

  A new type that uses one of these keys gets the same input. A future field
  without a hint is rendered from its definition alone.
- If `type_fields.php` cannot be loaded, or reports `registry: false`, the
  form falls back to `LEGACY_REQUEST_FIELDS` (the five seeded types).

Server (`api/requests/create.php`), order of checks:

1. unknown / archived type -> 422 `This document type is no longer available.` (unchanged)
2. no active template version -> 422 `This document is not available yet.` (unchanged)
3. **new:** a resident field with `is_required = 1` that is missing, out of
   range or not a number -> 422 `{Label} is required.`
4. no price row -> 422 (unchanged)

No shipped field has `is_required = 1` among the resident fields, so check 3
does not fire for the seeded types or for anything the catalogue can create
today. (`is_required` on those rows also means "the placeholder must be in the
DOCX", which is why the seeded flags were left at 0; the form's "required" for
the five keys comes from the hints.) Unknown keys are still dropped silently.

## Staff generation modal

- The modal shows the type's **non-system** fields in registry order, with
  the registry label in title case, prefilled exactly as before (resident
  record, the resident's stored answers, the request purpose, today's date).
  `reference_no`, `official_name`, `official_title` are never shown.
- **Rules apply to `staff_entered` fields only** (`purpose`, `date_issued`
  for every shipped type): `is_required`, `data_type`, `min_value` /
  `max_value`, `max_length`. Other sources keep their server-side fallbacks.
- The modal checks the same rules before sending anything and shows the same
  text the server would answer (toast "Generation Failed" and the preview
  area). The texts live in one map on each side:
  `DOCUMENT_FIELD_MESSAGES` (`api/documents/_registry.php`) and
  `DOC_FIELD_MESSAGES` (`js/official.js`). The suite reads both files and
  fails when they differ, and runs the same cases through both rule
  functions.
- "Template Missing" is shown only on a legacy database (no registry) for a
  type outside the built-in five. For a registry database:
  - type not in the registry -> toast "Document type is unavailable."
  - registered, no active version -> toast "Document template is unavailable."
  - a type created or activated after the page loaded is picked up by one
    reload of the definitions when the modal is opened.
  - definitions could not be loaded at all -> the five seeded types still
    open from the legacy list; any other type shows "Fields Unavailable".
- Default date: the modal used the UTC date (`toISOString`), which is
  yesterday before 08:00 Philippine time. It now uses the local calendar
  date.

`POST api/requests/generate_document.php`: after the existing payload checks
and **before** the operation is claimed, the `staff_entered` values are
validated (a key that was not sent counts as empty). A refusal takes no
lease, writes no operation row and no file.

| Status | Message | When |
|---|---|---|
| 422 | `{Label} is required.` | required staff field empty or not sent |
| 422 | `{Label} must be a whole number.` | `int` field: not an integer of up to 9 digits |
| 422 | `{Label} must be between {min} and {max}.` | `int` with both limits |
| 422 | `{Label} must be at least {min}.` | `int` with a minimum only |
| 422 | `{Label} must be at most {max}.` | `int` with a maximum only |
| 422 | `{Label} must be {max} characters or fewer.` | `text` longer than `max_length` |

`{Label}` is the registry label as stored, e.g. `Purpose is required.`,
`Date issued is required.` The check is skipped on a legacy database, and
when the request or its type is unknown (the run reports those as before).

## Request detail view (staff)

The resident's stored answers (`request_fields_json`) are labelled with the
registry label of that type's field. A key the type does not carry is shown
as the key with underscores replaced by spaces. Both are rendered in
uppercase by the existing style, so seeded requests look the same.

## Changes to the seeded forms

Everything else is identical; the suite compares the new renderers with the
HTML the old hardcoded ones produced (`golden/seeded_forms.json`).

1. **Blank required staff field is refused (accepted behaviour change).**
   Before 5B, clearing **Purpose** silently fell back to the purpose on the
   request and clearing **Date Issued** fell back to today. Now both are
   blocked in the modal and answered with 422 by the server
   (`Purpose is required.` / `Date issued is required.`).
2. **The only attribute changes** on the resident form: `max="120"` on
   Years of Residency and `maxlength="100"` on Business Name and Business
   Type. These are the limits the server already enforced (it dropped or cut
   such values silently). Barangay Clearance is byte-identical.
3. The modal's default date is the local date (fix, see above).
4. Staff detail view: an unknown stored key is labelled with spaces instead
   of capitalised words; invisible in practice because the label style is
   uppercase.

## Storage override (tests only)

`generate_document.php` now passes
`document_storage_override() ?? <project root>` as the artifact root, so a
test server writes generated PDFs / DOCX files into the temp storage instead
of the real `generated_documents/`. The override is the same guarded,
test-only switch as for templates (`BARANGAY_STORAGE_ROOT` must resolve to
`<system temp>/capstone_tpl_<digits>`); production never sets it.

## Tests

    php project-support/tests/document-forms/run.php
    node project-support/tests/document-forms/frontend.js

`run.php` (126 checks; scratch databases `capstone_forms_*`, TestStorage temp
storage, real database read-only, real `document_templates/` and
`generated_documents/` hashed before and after): the rule function (every
message), staff rules on the seeded fields, `type_fields.php` for both roles
(exact shape per type; resident view has no other source, no archived or
unpublished type), `create.php` whitelist and limits with a created type and
the required check, every generation 422 through the endpoint (scratch-only
`staff_entered` rows reach the int / range / length rules), no lease /
operation / file after a refusal, generation success for a created type
(placeholders filled, `Hon. <captain>` / `Barangay Captain`) and a seeded
type, and a registry-less database (endpoint shape, legacy whitelist, legacy
generation with its fallbacks). It also runs `frontend.js` with the
definitions the scratch endpoint really answered.

`frontend.js` (60 checks alone, 63 from `run.php`; node `vm`, stub DOM, no
browser): seeded resident forms and staff modals equal the pre-5B HTML from
registry definitions, from the legacy fallback and from the endpoint
response; created type; generic fields; hints keyed by field only; the
message maps of both files; rule parity JS vs PHP; client-side block; which
types the modal refuses and with what text; detail labels; local date.

`golden/seeded_forms.json` was captured from the renderers at commit
`257808b` (before 5B). Do not regenerate it from the new code.

Known, not part of 5B: `project-support/tests/request-concurrency/frontend.js`
stops at its second check ("displayed buttons embed the reviewed version") on
the committed code as well; it expects `notifyPickupReady('REQ1',7)` while the
button passes a third argument. Likewise
`project-support/tests/review-2026-10-24/render-check.js` stops after 60
passes with `setInterval is not defined` (its VM has no `setInterval`) on the
committed code. Both left as is; with that one expectation skipped in a
scratch copy, the remaining request-concurrency frontend checks pass on the
old and the new `official.js`.

## Manual checklist

Use a test server (scratch DB + temp storage), never real storage.

- [ ] Resident, Barangay Clearance: Age (prefilled) and Civil Status (select, prefilled), both required; same as before.
- [ ] Resident, Certificate of Indigency: no extra inputs.
- [ ] Resident, a created type with age + civil_status: the same two inputs appear; submitting stores both.
- [ ] Staff, seeded request -> Process: same fields, labels and prefills as before; date is today's local date.
- [ ] Staff, created type -> Process: modal opens (no "Template Missing"), Generate & Preview shows the PDF with every placeholder filled and `Hon. <captain>` / `Barangay Captain`.
- [ ] Staff: clear Purpose -> "Purpose is required." and nothing is sent; a direct API call with a blank purpose answers 422 with the same text.
- [ ] Staff, request details: the resident's answers are listed with their labels.
- [ ] A type without an active version -> Process shows "Document template is unavailable."

# Complaint revision — Phase 2: complaint types read from the database

Written 2026-11-16. Not committed.

## Purpose

Phase 1 created `complaint_types` and `complaint_type_fields` and seeded them to match the hardcoded PHP array. Phase 2 makes the application use those tables:

- complaint types and their extra fields are read from the database, not from a PHP array;
- the resident filing form is built from the database, so the "Other" type can show its required "Please specify" text field;
- existing complaints read and display exactly as before.

No complaint type data was changed on the real database. No admin screens yet (Phase 3).

## Files

| File | Change |
|------|--------|
| `database/database.sql` | Added `complaint_types`, `complaint_type_fields` (before `complaints`), their seed rows, and `complaints.complaint_type_id` with its index and foreign key. |
| `api/complaints/_extra_fields.php` | Rewritten. Hardcoded array removed; all functions read the tables. |
| `api/complaints/create.php` | Accepts `complaint_type_id` or legacy `category`; stores both columns. |
| `api/complaints/list.php` | Resolves the type by id (name only as fallback); returns `complaint_type_id`. |
| `api/complaints/_community.php` | Known names include inactive types. |
| `api/complaints/public_list.php` | Same, in two places. |
| `pages/resident.php` | Type dropdown and extra-field controls rendered from the database. |
| `js/resident.js` | Sends `complaint_type_id`; extra-field handling works for inputs as well as selects. |
| `project-support/tests/complaint-types/legacy_schema.php` | New. The old array and old read/display code, as a test fixture only. |
| `project-support/tests/complaint-types/run.php` | Extended: 53 Phase 1 checks kept, 39 Phase 2 checks added. |
| `project-support/tests/complaint-interface/api-checks.php` | Extended: 10 checks added. |
| `project-support/tests/review-2026-10-24/run.php` | One step added: seed complaint types into the scratch database. |
| `project-support/scripts/complaint-baseline.php` | Accepts text, number and date values as valid; reads the schema including inactive entries. |
| `project-support/docs/complaint-revision-phase2.md` | This file. |

Not changed: `js/official.js` (see "Escaping"), `js/community-complaints.js` (Phase 4), stats and AI endpoints (Phase 8), requests and documents code.

### Differences from the Phase 2 brief

1. `review-2026-10-24/run.php` had to change. It builds its scratch database with `mysqldump --no-data`, so the type tables arrived empty and every `create.php` call would have returned 422. It now sources the Phase 1 migration into the scratch database right after the schema import.
2. The JavaScript escaping fix was not needed. `renderComplaintExtraFieldsHtml` in both `js/resident.js` and `js/official.js` already passed label and value through `escapeHtml()`. Neither function was edited, so the "Keep in sync" comments remain true.
3. `pages/resident.php` loads `db.php`, not `api/common.php`, so `json_error()` does not exist there. The loader calls `json_error('Complaint types are unavailable.', 500)` when that function exists (every API endpoint) and otherwise throws `RuntimeException`. The page catches it and shows the form with an empty type list and the text "Complaint filing is temporarily unavailable." There is no fallback to the old array in either case.
4. Phase 1 check group (b) compared the seed with `complaint_extra_fields_schema()`. That function now reads the same tables, so the comparison would have tested the database against itself. Group (b) now compares against `complaint_legacy_schema()`. The count is still 53.
5. `complaint_types_cache_reset()` was added so the tests can see changes made inside a transaction.
6. Extra-field control ids changed from `complaint-extra-{key}` to `complaint-extra-{typeId}-{key}`. Two types may use the same field key, which would have produced duplicate HTML ids.

## Function signatures (`api/complaints/_extra_fields.php`)

```php
function complaint_types_all(bool $includeInactive = false): array
function complaint_type_get(int $id, bool $includeInactive = false): ?array
function complaint_type_by_name(string $name, bool $includeInactive = false): ?array
function complaint_types_cache_reset(): void
function complaint_extra_fields_schema(bool $includeInactive = false): array
function complaint_extra_fields_validate(array $type, mixed $raw): array
function complaint_extra_fields_read(?array $type, ?string $json): array
function complaint_extra_fields_display(?array $type, array $values): array
```

Internal helpers: `complaint_types_store(bool $reset = false)` (two queries, cached per request) and `complaint_extra_field_clean(array $field, mixed $value)`.

Shapes:

- Type: `id`, `type_code`, `type_name`, `description`, `respondent_mode`, `is_active` (bool), `fields` (list, ordered by `sort_order`, `id`).
- Field: `key`, `label`, `type` (`select`, `text`, `number`, `date`), `required` (bool), `max_length`, `min`, `max`, `is_active` (bool), `options` (list).
- Option: `value`, `label`, `is_active` (bool).
- `complaint_extra_fields_schema()`: `[type_name => [field_key => ['label', 'options' => [value => label], 'type', 'required', 'max_length', 'min', 'max']]]`.

Rules:

- Without `$includeInactive`, inactive types, inactive fields and retired options are left out. With it, everything is returned.
- `validate` (write, strict): `null` counts as an empty object; a non-object is refused; unknown keys and keys of inactive fields are refused; select values must be an active option; text is trimmed, control characters are removed, a NUL byte or invalid UTF-8 is refused, and length is limited to `max_length` (255 when not set); numbers must look like `12` or `12.5`, fall inside `min`/`max`, and are stored as strings; dates must be a real `YYYY-MM-DD` date; an empty string means "not provided"; a required field that is missing or empty gives `"<label> is required."`.
- `read` (tolerant): pass a type loaded with `$includeInactive = true`. Inactive fields and retired options stay readable. A select value that matches no option is dropped. Unknown stored keys are never returned.
- `display`: a retired option shows as `"<label> (retired)"`. Values are plain text.

### `create.php` input

- `complaint_type_id`: a JSON integer greater than 0 (a string such as `"3"` is refused). Preferred.
- `category`: the type name, for older clients.
- If both are sent they must name the same type.
- Unknown, inactive, or mismatched type: HTTP 422, `Select a valid type of concern.`
- Extra-field errors: HTTP 422 with the validation message.
- The row stores `category` = the type name and `complaint_type_id` = the type id.

### Escaping

Free text is now possible in extra fields. The API stores and returns it unchanged; the browser escapes it when building HTML (`escapeHtml()` in `renderComplaintExtraFieldsHtml`, both portals). `pages/resident.php` prints every label, key, id and option through `e()`. Manual check 3 below confirms this in the browser.

## Other code that reads `category`

| Location | Status |
|----------|--------|
| `api/stats/complaints_report.php`, `api/ai/analyze_complaints.php` | Group by the stored name. Phase 8. Not changed. |
| `js/resident.js` (`cat: row.category`), `js/official.js` (`cat: row.category`) | Still correct: `category` holds the type name. |
| `js/community-complaints.js` lines 8–14 and 35 | Hardcoded list of five names. Phase 4. Until then a type added or renamed later would show as "Other" on the community page, in the browser only. |

## Test results (2026-11-16, real database `barangay_system_test_environment`)

| Suite | Phase 0 / 1 | After Phase 2 | Exit |
|-------|------------:|--------------:|-----:|
| complaint-types | 53 / 53 | 92 / 92 (53 kept + 39 new) | 0 |
| review-2026-10-24 (whole runner) | 117 / 117 | 127 / 127 (117 kept + 10 new) | 0 |
| — `interface:` checks | 30 / 30 | 40 / 40 (30 kept + 10 new) | — |
| document-baseline | 6 / 6 | 6 / 6 | 0 |
| document-forms | 126 / 126 | 126 / 126 | 0 |
| document-registry | 27 / 27 | 27 / 27 | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |

No check failed in any suite. All earlier counts hold.

- `php project-support/tests/complaint-interface/api-checks.php` run on its own still exits 1 with no output. That is its guard; it only runs inside the review runner.
- `complaint-baseline.php`: output identical to Phase 0 and Phase 1 (7 complaints, same category counts, no INVALID STORED VALUES, no UNKNOWN CATEGORIES).
- The brief asked for 6 interface checks; 10 were added (the extra ones cover the seeded scratch types, mismatched/unknown/string ids, reactivation, and cleanup).

### New complaint-types checks ("Phase 2" section)

- (a) All 7 stored complaints: old read and display (legacy array and old algorithm) equal the new ones. Fields that did not exist before (`Other.specify`) are left out of this comparison so it stays valid after new complaints are filed.
- (b) Active schema equals the legacy schema plus `Other.specify`, in names, keys, labels, options and order.
- (c) Validation: unknown key, inactive field, retired option, date `2026-02-30`, number out of range, text over the limit, NUL byte, missing required field, and non-object input each throw; a valid payload passes for every real type. Number, date and inactive-field cases use a type built in the test, because the seed has no such fields and real type data must not change.
- (d) Retired option, inside a transaction that is rolled back: read keeps the value, display shows "(retired)", validate refuses it.
- (e) Deactivated type, inside a transaction that is rolled back: left out of `complaint_types_all(false)`, present in `complaint_types_all(true)`, and the community projection keeps its name.
- A final check confirms the rollback restored five active types.

### New interface checks (scratch database only)

- Create with `complaint_type_id` returns 200; the row has the id, the type name and the extra field.
- Create with the legacy `category` string returns 200 and stores the matching id.
- Mismatched id and name, unknown id, unknown name, and a string id each return 422.
- "Other" without `specify` returns 422 (`Please specify is required.`); with it, 200.
- A text value of `<b>x</b><script>alert(1)</script>` is stored as is and returned unescaped in `list.php` JSON. Escaping is done in the browser — see manual check 3.
- An inactive type returns 422 by id and by name; after reactivation it can be filed again.
- The three complaints created by these checks are deleted.

### `database/database.sql` against the migrated database

A scratch database built from `database.sql` was compared with the real, migrated database, then dropped:

- `complaint_types`: `SHOW CREATE TABLE` identical.
- `complaint_type_fields`: identical.
- Seed rows (5 types, 9 fields): identical.
- `complaints`: same columns, indexes and foreign keys. One cosmetic difference: `idx_complaint_type` is listed before the two indexes MySQL creates for foreign keys, instead of after them. Index order has no effect.

### Leftovers

- The review runner's test server was stopped after the run.
- `capstone_fix24_2680` (its scratch database) remains; the runner drops it at the start of its next run.

## Manual browser checklist

Not done yet. Do this on the real application.

1. **Resident, filing form.** Open "File a complaint". Choose each of the 5 types in turn. Each shows its own fields: Road (Infrastructure issue, Access impact), Noise (Noise source, Usual time), Flooding (Drainage issue, Affected area), Public Safety (Safety concern, Is the concern still present?). "Other" shows one text box, "Please specify", and the form cannot be submitted while it is empty. The heading reads "Additional details (optional)" for the first four and "Additional details" for Other.
2. **Resident, filing.** File one complaint per type. Each appears in My Complaints with the correct "Additional details".
3. **XSS.** File an "Other" complaint with Please specify = `<b>x</b><script>alert(1)</script>`. In the resident view and in the official view the text appears literally, not bold, and no alert opens.
4. **Official, old complaints.** The official complaint list shows details for complaints filed before Phase 2 exactly as before.
5. **Community concerns page.** Filters work and counts are unchanged.

Note: step 2 adds complaints to the real database. After that, `complaint-types/run.php` checks "e) per-category complaint counts equal Phase 0" and "e) total complaints equal Phase 0 (7)" will fail, because they compare against the Phase 0 numbers. Run the automated suites before the manual pass, or remove the test complaints afterwards. Those two checks should be relaxed or re-baselined in a later phase.

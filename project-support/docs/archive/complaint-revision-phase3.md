# Complaint revision — Phase 3: API to manage complaint types

Written 2026-11-16. Not committed.

## Purpose

Phase 2 made the application read complaint types and their extra fields from the database. Phase 3 adds the backend to change them: any logged-in official (staff or admin) can create and edit types and fields, and switch them active or inactive. Every write is audit-logged. Nothing is ever deleted.

There is no screen for this yet (Phase 4). No complaint type data was changed on the real database.

## Files

| File | Purpose |
|------|---------|
| `api/complaint_types/_admin.php` | New. All rules and database work. |
| `api/complaint_types/list.php` | New. Everything, for the management console. |
| `api/complaint_types/fields.php` | New. One active type for a filing form. |
| `api/complaint_types/save_type.php` | New. Create or update a type. |
| `api/complaint_types/toggle_type.php` | New. Activate or deactivate a type. |
| `api/complaint_types/save_field.php` | New. Create or update a field. |
| `api/complaint_types/toggle_field.php` | New. Activate or deactivate a field. |
| `project-support/tests/complaint-interface/api-checks.php` | 27 HTTP checks added. |
| `project-support/tests/complaint-types/run.php` | Check (e) limited to Phase 0 complaints; 21 unit checks added. |
| `project-support/docs/complaint-revision-phase3.md` | This file. |

Not changed: `js/`, `pages/`, the community board, stats and AI, requests and documents, `api/complaints/_extra_fields.php`. No `.htaccess` was added because no other `api/` subfolder has one.

## Endpoints

All answer JSON. Errors are `{"success": false, "message": "..."}`.

| Endpoint | Method | Who | Request | Success response |
|----------|--------|-----|---------|------------------|
| `list.php` | GET | official | — | `{types, limits}` |
| `fields.php?type_id=N` | GET | resident or official | — | `{type}` |
| `save_type.php` | POST | official | `{id?, version?, type_name, type_code?, description?, respondent_mode?, sort_order?}` | `{changed, type}` |
| `toggle_type.php` | POST | official | `{id, version, is_active}` | `{changed, type}` |
| `save_field.php` | POST | official | `{type_id, id?, version?, field_key?, label, field_type?, is_required?, sort_order?, max_length?, min_value?, max_value?, options?}` | `{changed, field}` |
| `toggle_field.php` | POST | official | `{id, version, is_active}` | `{changed, field}` |

- No `id` means create. An update needs `id` and `version`.
- `type_name` (types) and `label` (fields) are always required. Other keys are optional: on create a missing key takes its default (`respondent_mode` "none", `sort_order` = highest + 10, `is_required` false, text `max_length` 255); on update a missing key is left unchanged.
- `changed` is `false` when the request matched what is already stored. The version is still checked, but it is not increased and no audit row is written.
- POST bodies must be `application/json`.

### Shapes

Type, as returned by `list.php` and the write endpoints:

```json
{"id": 6, "type_code": "stray_animals", "type_name": "Stray Animals", "description": null,
 "respondent_mode": "none", "is_active": true, "sort_order": 60, "version": 1,
 "usage": 0, "fields": []}
```

Field:

```json
{"id": 17, "complaint_type_id": 6, "field_key": "animal", "label": "Animal", "field_type": "select",
 "is_required": false, "sort_order": 10, "version": 1, "is_active": true,
 "max_length": null, "min_value": null, "max_value": null, "usage": 0,
 "options": [{"value": "dog", "label": "Dog", "is_active": true, "usage": 0}]}
```

`list.php` also returns `"limits": {"max_active_fields": 15, "max_options": 50, "max_text_length": 1000}`.

`fields.php` returns a smaller shape with active entries only and no usage, version or `is_active`:

```json
{"success": true, "type": {"id": 6, "type_code": "stray_animals", "type_name": "Stray Animals",
 "description": null, "respondent_mode": "none",
 "fields": [{"key": "animal", "label": "Animal", "type": "select", "required": false,
             "max_length": null, "min": null, "max": null,
             "options": [{"value": "cat", "label": "Cat"}]}]}}
```

### Usage counts

- Type: complaints whose `complaint_type_id` is this type.
- Field: complaints of this type that stored a value under this field key.
- Option: complaints of this type that stored this option value.

### Status codes

| Code | When |
|------|------|
| 401 | Not logged in as an official (a resident session counts as not logged in). For `fields.php`: no active session at all. |
| 404 | Unknown type or field id; a field that does not belong to the given `type_id`; `fields.php` for an inactive, missing or malformed type id. |
| 405 | Wrong HTTP method. |
| 409 | Stale version; duplicate type name, type code or field key. |
| 415 | Body is not `application/json`. |
| 422 | Validation failure, unknown top-level key, id or version that is not a JSON integer, missing version on update, or a refused rule (see below). |
| 500 | Database failure. The detail is logged, not returned. |

## Rules

### Every write

- Runs in one transaction and locks the type row with `SELECT ... FOR UPDATE`. Field writes lock the parent type row first, then the field row.
- Optimistic lock: the `UPDATE` carries `WHERE id = ? AND version = ?` and sets `version = version + 1`. A stale version answers 409, "This item was changed by someone else. Reload and try again."
- After the commit: one `audit_log` row, then `complaint_types_cache_reset()`.
- Strict input: ids, versions, `sort_order` and `max_length` must be JSON integers; `is_active` and `is_required` must be JSON booleans; `min_value` and `max_value` must be JSON numbers or null. Unknown top-level keys are refused.

### Types

- `type_name`: trimmed, 2 to 100 characters, no control characters, unique among all types (active and inactive) without regard to case.
- `type_code`: set at creation only. Optional; when missing it is generated from the name (lowercase, anything outside `a-z0-9` becomes `_`, repeated and outer `_` removed, 50 characters at most, `_2`, `_3` ... added when taken). Must match `^[a-z][a-z0-9_]{1,49}$`. Sending a different code on update is refused; sending the same code is accepted.
- `description`: 255 characters at most; empty becomes null.
- `respondent_mode`: `none`, `optional` or `required`.
- `sort_order`: 0 to 9999.
- Deactivating is refused for the type with code `other`, and when it would leave no active type.
- Renaming is allowed. Complaints keep the category text they were filed with (see "Known gap").

### Fields

- `field_key`: set at creation only, `^[a-z][a-z0-9_]{0,49}$`, unique within the type. Never changes.
- `field_type`: `select`, `text`, `number` or `date`. Set at creation only. Never changes.
- `label`: 1 to 100 characters. `sort_order`: 0 to 9999.
- `text`: `max_length` 1 to 1000.
- `number`: `min_value` and `max_value`, each a number or null; `min_value` may not be greater than `max_value`.
- `date`: no settings.
- A setting that belongs to another field type is refused unless it is null.
- `select`: `options` is an ordered list of `{value, label, is_active}`.
  - `value`: `^[a-z0-9_]{1,50}$`, unique within the field. `label`: 1 to 100 characters. `is_active` defaults to true.
  - At most 50 options.
  - An update that sends `options` must include every existing value. Leaving one out answers 422, "Options cannot be removed; mark them inactive instead."
  - New values may be added. The order sent is the order stored.
  - An active select field needs at least one active option.
- A type can have at most 15 active fields. This is checked when a field is created and when one is reactivated.
- `Other.specify` can be relabeled and resized. It cannot be deactivated or made optional, because it is the only way an "Other" complaint describes itself.

### Audit rows

| Action | target_type | target_reference |
|--------|-------------|------------------|
| Created / Updated / Activated / Deactivated complaint type | `complaint_type` | `type_code` |
| Created / Updated / Activated / Deactivated complaint type field | `complaint_type_field` | `type_code.field_key` |

`details` is JSON of the changed keys as `{key: [old, new]}`. Option changes are summarised, for example `{"label":["Animal","Animal type"],"options":{"added":["goat"],"retired":["dog"]}}`. `target_reference` is cut to 100 characters, the size of the column (the longest possible `type_code.field_key` is 101).

## Module functions (`api/complaint_types/_admin.php`)

```php
final class ComplaintTypeProblem extends RuntimeException { public int $status; }

// pure
function complaint_type_derive_code(string $name): string
function complaint_type_unique_code(string $base, array $takenCodes): string
function complaint_field_json_path(string $fieldKey): string
function complaint_type_admin_keys(array $input, array $allowed): void
function complaint_type_admin_int(array $input, string $key, int $min, int $max): int
function complaint_type_admin_bool(array $input, string $key): bool
function complaint_type_validate_type(array $input, bool $isCreate): array
function complaint_type_validate_field(array $input, string $fieldType, bool $isCreate, array $existingOptions = []): array
function complaint_type_validate_options(mixed $raw, array $existingOptions): array

// usage
function complaint_type_usage(mysqli $db, int $typeId): int
function complaint_field_usage(mysqli $db, int $typeId, string $fieldKey): int
function complaint_option_usage(mysqli $db, int $typeId, string $fieldKey): array

// reads
function complaint_types_admin_list(mysqli $db): array
function complaint_type_admin_get(mysqli $db, int $typeId): ?array
function complaint_field_admin_get(mysqli $db, int $fieldId): ?array
function complaint_type_public_fields(int $typeId): ?array

// writes
function complaint_type_save(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_type_toggle(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_field_save(mysqli $db, array $input, int $officialId, string $officialName): array
function complaint_field_toggle(mysqli $db, array $input, int $officialId, string $officialName): array
```

### Differences from the Phase 3 brief

1. The typed exception is a new class, `ComplaintTypeProblem`. The existing `RequestProblem` lives in the requests code, which this phase must not depend on.
2. `complaint_read_positive_id()` was not reused: it accepts the string `"5"`, and this API requires JSON integers. The module has its own strict readers. `complaint_require_json_object()` from `_community.php` is reused by all four write endpoints.
3. The audit row is written after the commit, as `document_type_create()` does. An audit failure does not undo a saved change.
4. `fields.php` checks for a logged-in resident or official itself. `enforce_active_session()` only re-checks identities that are present and lets an empty session through; the first test run caught this.
5. The three patterns use the `D` modifier. Without it `$` also matches before a trailing newline, so `"a\n"` passed as a field key; a unit check caught this.

## Test results (2026-11-16)

| Suite | Before | After Phase 3 | Exit |
|-------|-------:|--------------:|-----:|
| complaint-types | 92 / 92 | 113 / 113 (92 kept + 21 new) | 0 |
| review-2026-10-24 (whole runner) | 127 / 127 | 154 / 154 (127 kept + 27 new) | 0 |
| — `interface:` checks | 40 / 40 | 67 / 67 (40 kept + 27 new) | — |
| document-baseline | 6 / 6 | 6 / 6 | 0 |
| document-forms | 126 / 126 | 126 / 126 | 0 |
| document-registry | 27 / 27 | 27 / 27 | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |

No check failed in the final run. All earlier counts hold. `complaint-baseline.php` output is identical to Phase 0.

Two checks failed on the first run of the review runner and led to fixes:

- `fields.php` answered 200 to a caller with no session. Fixed in the endpoint (difference 4 above).
- A test compared stored `extra_fields_json` with a fixed key order. MySQL stores JSON object keys in its own order. The test now sorts the keys before comparing; the application is not affected because it reads fields in field order.

### HTTP checks (scratch database, through the review runner)

- (a) Resident session and logged-out caller: 401 on every write endpoint and on `list.php`; logged-out 401 on `fields.php`. No row changed.
- (b) A staff official creates a type, adds text, number, date and select fields, updates, deactivates and reactivates. An inactive type cannot be filed.
- (c) Duplicate name in another case, duplicate code: 409. Bad code, changed code: 422. A generated code that is taken gets `_2`. Unknown keys, string ids, missing version, non-boolean flags: 422. Wrong content type 415, wrong method 405, unknown ids 404.
- (d) A second write with the same version: 409, and the first write's value stays.
- (e) Removing an option: 422. Marking it inactive and adding one: 200. Filing the retired option: 422. The older complaint shows "Dog (retired)". Duplicate field key 409; changed key or kind, settings of another kind, no active option, min above max: 422. The 15-field limit holds on create and on reactivation.
- (f) "Other" cannot be deactivated. `Other.specify` cannot be deactivated or made optional but can be relabeled and resized. The last active type cannot be deactivated.
- (g) The new type and its fields appear in the resident form HTML, a complaint can be filed under it with all four field kinds, and the new limits are enforced.
- (h) Audit rows exist for each successful write with the right action, reference and official; refused writes left none.
- (i) `fields.php` returns active fields and options only, to residents and officials; 404 for an inactive, missing or malformed id.
- (j) The test deletes its own complaints, types, fields, audit rows and official directly in SQL, and confirms the five seeded types and nine fields are intact.

The staff session created by the runner is deleted before these checks run, so the checks create their own staff official and session.

### Unit checks (`complaint-types/run.php`, "Phase 3" section)

Read-only against the real database; nothing is written (a before/after snapshot of type rows, field rows and the audit count confirms it).

- Code generation: from a name, digit-first names, nothing usable, the 50-character cap, `_2` and `_3` on a clash, a suffix that still fits in 50 characters.
- JSON path: a valid key is quoted; 12 unsafe keys (quotes, dots, brackets, spaces, a trailing newline, 51 characters) are refused before reaching SQL, by the path builder and by both usage helpers.
- Usage counts for every type, field and option equal a count done in PHP over the stored complaints.
- Type, field and option validators: lengths, patterns, defaults, settings of another field type, the 50-option limit, strict integers.

### Change to check (e)

Checks "e) per-category complaint counts equal Phase 0" and "e) total complaints equal Phase 0 (7)" now count only complaints with `id <= 7`, the highest complaint id at Phase 0. Complaints filed later no longer break them.

The seed-tied checks were then moved off the real database. See "Test modes" below.

## Test modes (added after Phase 3, before Phase 4)

Once staff add, rename, reorder or deactivate a type on the real database, any check that expects the original seed would fail. `complaint-types/run.php` therefore has two modes.

| Mode | Command | Database | What it checks |
|------|---------|----------|----------------|
| Live (default) | `php project-support/tests/complaint-types/run.php` | Real database, or a copy with `--db=<name>` | Only rules that hold whatever staff have done. SELECT only. |
| Seed | `php project-support/tests/complaint-types/run.php --seed --db=<scratch>` | Scratch database only | Everything in live mode that does not need real complaints, plus seed equality. |

- Seed mode refuses to run against the real database (exit 1 with a message).
- `review-2026-10-24/run.php` runs seed mode on its scratch database, right after seeding it, and reports each line as a `types-seed:` check plus one "run completed" check.
- Check names carry `[seed]` or `[live]` when they run in one mode only.

Live-only (3 checks): the Phase 0 per-category counts and total for complaints with `id <= 7`, and that those 7 complaints still read back the same stored values.

Seed-only (48 checks): the five seeded types with their codes, modes and order; every seeded field, label and option; `Other.specify` at 150 characters labelled "Please specify"; old read and display equal to the new ones for 73 stored-value samples (every option of every seeded field, a full payload per type, and junk input); the retired-option and deactivated-type checks, which change rows inside a transaction and roll back.

Both modes (63 checks): tables, indexes, foreign keys and column types; the `other` type and its `specify` field exist, active and required; at least one active type; every type code and field key matches its pattern; no complaint without a type id; lookups and ordering on whatever types exist; all validation rules on a type built in the test; a valid payload for every active type, whatever its fields are; all Phase 3 unit checks.

Changes this needed in the checks themselves:

- The `other` type is found by its code, not by the name "Other", and error messages that quote the label of `specify` are compared in seed mode only.
- "Every complaint points at the type matching its category" was removed. A type can be renamed while complaints keep their category text, so it is not a rule that always holds, and the scratch database has no complaints at that point. Live mode keeps "no complaint has `complaint_type_id` NULL".
- The per-row comparison of old and new display text was replaced: live mode compares stored values for the Phase 0 complaints (labels can be edited), and seed mode compares values and display text on generated samples.
- Live mode no longer opens a transaction on the real database. It writes nothing.

### Counts after the split (2026-11-16)

| Suite | Result | Exit |
|-------|-------:|-----:|
| complaint-types, live mode, real database | 66 / 66 | 0 |
| complaint-types, seed mode (inside the review runner) | 111 / 111 | 0 |
| review-2026-10-24 (whole runner) | 266 / 266 | 0 |
| — `interface:` checks | 67 / 67 | — |
| — `types-seed:` checks | 112 / 112 (111 + 1 "run completed") | — |
| — all other runner checks | 87 / 87 | — |
| document-baseline | 6 / 6 | 0 |
| document-forms | 126 / 126 | 0 |
| document-registry | 27 / 27 | 0 |
| document-templates | 11 / 11 | 0 |

How these relate to the table above: the runner's 154 became 266 by adding the 112 `types-seed:` checks. The standalone 113 became 66 in live mode; the other checks now run in seed mode, where the count is 111 because one seed check was removed, one was split, and several per-type checks were replaced by ones that work for any set of types.

The real database was not changed by any of these runs: 5 types and 9 fields, every version still 1, and no complaint-type audit rows.

Remaining tie to real data: the three `[live]` checks expect the 7 Phase 0 complaints to exist with their original category text. They fail only if one of those complaints is deleted or its category is edited directly.

### Real database after the run

- `complaint_types`: 5 rows, all active, every version 1.
- `complaint_type_fields`: 9 rows, every version 1.
- `audit_log`: 0 rows for complaint types or fields.
- `complaints`: 7 rows.

### Leftovers

- The review runner's test server was stopped.
- `capstone_fix24_13780` (its scratch database) remains; the runner drops it at the start of its next run.

## Known gap until Phase 4

A type can now be renamed through the API, but complaints keep the `category` text they were filed with, and several places still match on that text:

- **Community board.** `api/complaints/_community.php` and `public_list.php` treat a category name that matches no type name as "Other". After a rename, complaints filed under the old name appear under "Other", and filtering by the new name does not find them. `js/community-complaints.js` also has the five original names hardcoded, so any new or renamed type shows as "Other" in the browser.
- **Resident and official lists** show the stored `category` text, so older complaints show the old name. Their extra details still display correctly, because those are resolved through `complaint_type_id`.
- **Legacy filing by name.** `create.php` called with `category` set to the old name answers 422. The resident form is not affected; it sends `complaint_type_id`.
- **Statistics and AI summaries** group by the stored `category` text, so a renamed type appears as two categories (Phase 8).

Nothing is lost: every complaint keeps its `complaint_type_id`. Until Phase 4 there is no screen that calls these endpoints, so a rename can only happen through a direct API call.

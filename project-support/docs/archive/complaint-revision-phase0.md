# Complaint revision — Phase 0 baseline

Recorded 2026-11-16. Baseline only: no existing file modified, no write SQL run against the application database.

## Purpose

The complaint revision will add dynamic complaint types, parties, a mediation calendar, and summons. Phase 0 records what exists before any change:

- what complaint data is stored now, and whether it all fits the current hardcoded schema;
- the id column types that new foreign keys must match;
- the test pass counts that later phases must not lower;
- every place a complaint category name is hardcoded.

## Commands run

All from the project root `C:/laragon/www/Capstone-System`, PHP 8.3.30 CLI.

| # | Command | Exit |
|---|---------|------|
| 1 | `php project-support/scripts/complaint-baseline.php` | 0 |
| 2 | `php project-support/tests/complaint-interface/api-checks.php` | 1 (no output) |
| 3 | `php project-support/tests/document-baseline/run.php` | 0 |
| 4 | `php project-support/tests/document-forms/run.php` | 0 |
| 5 | `php project-support/tests/document-registry/run.php` | 0 |
| 6 | `php project-support/tests/document-templates/run.php` | 0 |
| 7 | `php project-support/tests/review-2026-10-24/run.php` (added after review, to get the complaint count) | 0 |

`git status --short` was identical before and after commands 2–7.

New files in this phase:

- `project-support/scripts/complaint-baseline.php` — CLI-only, SELECT-only.
- `project-support/docs/complaint-revision-phase0.md` — this file.

## Results

### 1. complaint-baseline.php

```
Complaint baseline (read-only) - 2026-11-15 18:28:04
Database: barangay_system_test_environment

== a) COMPLAINTS PER CATEGORY ==
'Flooding / Drainage'             1
'Noise / Public Disturbance'      4
'Other'                           1
'Road / Infrastructure'           1

== b) EXTRA FIELD TRIPLES (category | key | value  count) ==
Rows with extra_fields_json NOT NULL: 5
Rows whose JSON is not an object: 0
'Flooding / Drainage' | affected_area | 'street'  1
'Flooding / Drainage' | drainage_issue | 'blocked_drain'  1
'Noise / Public Disturbance' | noise_source | 'music'  3
'Noise / Public Disturbance' | usual_time | 'evening'  1
'Noise / Public Disturbance' | usual_time | 'overnight'  2
'Road / Infrastructure' | access_impact | 'partly_blocked'  1
'Road / Infrastructure' | road_issue | 'damaged_road'  1

== INVALID STORED VALUES ==
(none)

== UNKNOWN CATEGORIES ==
(none)

== e) TOTALS ==
Total complaints: 7
resident_id IS NULL: 0
Per status:
  'investigating'     1
  'resolved'          6

== f) ID COLUMN TYPES ==
barangay_officials.id: data_type=int, column_type=int, nullable=NO, key=PRI, extra=auto_increment
complaints.id: data_type=int, column_type=int, nullable=NO, key=PRI, extra=auto_increment
residents.id: data_type=int, column_type=int, nullable=NO, key=PRI, extra=auto_increment

== g) SERVER VERSION ==
8.4.3
```

The timestamp in the first line is the PHP CLI clock (UTC); the run was on 2026-11-16 local time.

### 2. complaint-interface/api-checks.php

No output, exit code 1. This is by design, not a failure. Line 3 of the file:

```php
if (PHP_SAPI !== 'cli' || !isset($dbName) || !str_starts_with($dbName, 'capstone_fix24_')) exit(1);
```

The file is a fragment included by `project-support/tests/review-2026-10-24/run.php` (line 963), which builds the scratch database `capstone_fix24_<pid>` and defines `check()`, `http()`, `q()`, and the session cookies. Run alone it has none of these and exits. It holds 20 `check('interface: …')` call sites (two are inside loops, so the runtime count is higher). The parent runner was not in the original Phase 0 command list; it was run afterwards as command 7.

### 7. review-2026-10-24/run.php (first and last lines of 121)

```
PASS runner uses disposable schema
PASS failed connection is not cached
PASS get_db still terminates without a success marker
SERVER http://127.0.0.1:59004
DB capstone_fix24_14064
...
PASS application database counts unchanged
SERVER_LEFT_RUNNING
TOTAL 117 passed, 0 failed
```

Of the 117 checks, 30 are the `interface:` checks from `complaint-interface/api-checks.php`; all 30 pass.

Side effects, both by design of the runner:

- It leaves its PHP test server running for a browser pass (line 1005–1008). That process (127.0.0.1:59004) was stopped by hand after the run.
- It leaves the scratch database `capstone_fix24_14064` in MySQL. It was not dropped here (no write SQL in Phase 0). The runner drops old `capstone_fix24_*` databases at the start of its next run (lines 305–310).

The runner's own check "application database counts unchanged" passed.

### 3. document-baseline/run.php

```
PASS Barangay Clearance
PASS Certificate of Residency
PASS Certificate of Indigency
PASS Certificate of Good Moral Character
PASS Business Permit Endorsement
PASS Barangay Clearance ISO/address normalization
Baseline comparison passed.
```

### 4. document-forms/run.php (last lines of 129)

```
PASS real row count unchanged document_requests
PASS real row count unchanged request_operations
PASS no scratch database left behind
Results: 126/126 passed.
```

### 5. document-registry/run.php (last lines of 28)

```
PASS template checker detects intact-plus-split duplicate
PASS fail-closed fixture path is absent before test
PASS render fails closed with exact message
Results: 27/27 passed.
```

### 6. document-templates/run.php (last lines of 12)

```
PASS malformed placeholder detected
PASS required placeholder detected
PASS split placeholder detected
Results: 11/11 passed.
```

## Test pass counts

| Suite | Passed | Failed | Exit |
|-------|-------:|-------:|-----:|
| complaint-interface/api-checks.php (standalone) | not run — guard exit | — | 1 |
| document-baseline | 6 / 6 | 0 | 0 |
| document-forms | 126 / 126 | 0 | 0 |
| document-registry | 27 / 27 | 0 | 0 |
| document-templates | 11 / 11 | 0 | 0 |
| review-2026-10-24 (whole runner) | 117 / 117 | 0 | 0 |
| — of which complaint-interface `interface:` checks | 30 / 30 | 0 | — |

Complaint baseline for later phases: 117 of 117 in the review runner, 30 of 30 interface checks.

## Findings

### Stored data

- 7 complaints, 4 of the 5 schema categories in use (`Public Safety` has none).
- No INVALID STORED VALUES. All 7 distinct (category, key, value) triples are valid under `complaint_extra_fields_schema()`.
- No UNKNOWN CATEGORIES. Every stored `category` is a schema key; none is NULL.
- 5 of 7 rows have `extra_fields_json`; all 5 decode to JSON objects.
- `resident_id IS NULL`: 0 rows.
- Status: 6 resolved, 1 investigating, 0 open.
- The database is `barangay_system_test_environment`. These counts describe that database only.

### Id column types

`complaints.id`, `residents.id`, and `barangay_officials.id` are all `int` (signed), NOT NULL, primary key, auto_increment. New foreign key columns (complaint type, parties, mediation, summons) must be signed `INT` to match. Server is MySQL 8.4.3.

### Hardcoded complaint category locations

Confirmed line numbers:

| File | Lines | What is hardcoded |
|------|-------|-------------------|
| `api/complaints/_extra_fields.php` | 3–43 | `complaint_extra_fields_schema()`: the 5 category names (lines 5, 14, 23, 32, 41) with their field keys, labels, and options. Single source for the PHP side. |
| `pages/resident.php` | 319–323 | Five literal `<option>` elements in `#reqCategory`. Lines 319–322 are the four named categories; line 323 is `Other`. Not generated from the schema. |
| `pages/resident.php` | 344 | `foreach (complaint_extra_fields_schema() …)` renders one hidden fieldset per category (reads the schema, no literal names). |
| `js/community-complaints.js` | 8–14 | `COMMUNITY_CATEGORIES` array; the four named categories on lines 9–12, `Other` on line 13. Used at line 35 (fallback to `Other`) and line 60 (filter dropdown). |
| `api/complaints/_community.php` | 21–23 | Line 22 reads the schema; line 23 maps any category that is not a schema key to the literal `'Other'`. |
| `api/stats/complaints_report.php` | 26–27, 40–41, 47–57 | `GROUP BY category` queries. No literal category names; reports whatever is stored. |
| `api/ai/analyze_complaints.php` | 32–33, 46–47, 86, 92 | `GROUP BY category` queries, and prompt text that refers to "category" generically. No literal category names. |

Other locations found by searching the repo:

| File | Lines | Note |
|------|-------|------|
| `api/complaints/public_list.php` | 40–50, 129–136 | Filter validates `category` against the schema keys (line 136) and treats the literal `'Other'` as "Other, NULL, or anything not in the schema" (lines 45–50). Not in the original list. |
| `api/complaints/create.php` | 32, 40, 45 | Category is only checked for non-empty, then passed to `complaint_extra_fields_validate()`. An unknown category is accepted and stored (the validator falls back to an empty schema). No allow-list on write. |
| `api/complaints/list.php` | 72, 74 | Reads and displays extra fields by the stored category through the schema. |
| `js/resident.js` | 1583–1601, 2028–2050 | Shows the fieldset whose `data-complaint-extra-category` equals the selected `#reqCategory` value, by exact string match. No literal names. |
| `project-support/tests/complaint-interface/visual-fixture.php` | 31 | Inserts a `Road / Infrastructure` fixture row. |
| `project-support/tests/complaint-interface/api-checks.php` | 10 | Creates a `Noise / Public Disturbance` complaint. |
| `project-support/tests/review-2026-10-24/run.php` | 365, 717, 930, 967, 970 | Fixture inserts using `Noise / Public Disturbance`. |
| `project-support/docs/complaint-process-and-source.md` | 140, 758, 3749, 3992 | Documentation copies of the source. |

Points for Phase 1:

- The category name is the join key everywhere: stored as text in `complaints.category`, matched by exact string in PHP and JS. There is no category id.
- `'Other'` has special meaning in three places (`_community.php:23`, `public_list.php:40–50`, `community-complaints.js:35`). A dynamic type list must keep or replace this fallback deliberately.
- The category list exists in three independent copies: the PHP schema, the `<option>` list in `pages/resident.php`, and `COMMUNITY_CATEGORIES` in JS.
- `create.php` does not reject unknown categories today. Stored data is clean only because the form limits the choices.

## Go/No-go for Phase 1

**Go.** Stored complaint data is fully valid under the current schema, with no unknown categories and no invalid extra-field values, so a migration to dynamic types has no dirty rows to handle in this database. Id types are uniform signed `int`. The four document suites pass 170 of 170 checks, and the review runner passes 117 of 117, including all 30 complaint interface checks.

Numbers Phase 1 must not lower:

- `review-2026-10-24/run.php`: 117 passed, 0 failed (30 `interface:` checks).
- `document-baseline` 6, `document-forms` 126, `document-registry` 27, `document-templates` 11.

Note for whoever runs the complaint checks: use the parent runner, not `api-checks.php` directly, and stop the test server it leaves running.

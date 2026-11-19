# Complaint revision — Phase 1: complaint types schema

Written 2026-11-16. Schema, seed, and backfill only. No backend, frontend, or `database/database.sql` change in this phase.

## Purpose

Move the complaint category list out of PHP code and into the database, without changing behavior yet.

- Add tables for complaint types and their extra fields.
- Seed them to match `complaint_extra_fields_schema()` in `api/complaints/_extra_fields.php` exactly.
- Link every existing complaint to a type through a new `complaints.complaint_type_id` column.

The application still reads the PHP schema after this phase. Phase 2 switches the read and write paths to the new tables. `api/complaints/_extra_fields.php` is unchanged.

## Files

| File | Purpose |
|------|---------|
| `project-support/migrations/20261116_complaint_types.sql` | Schema, seed, backfill. |
| `project-support/tests/complaint-types/run.php` | Read-only checks of the migrated database. |
| `project-support/docs/complaint-revision-phase1.md` | This file. |

No existing file was changed.

## Schema summary

### `complaint_types`

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT, PK, auto_increment | Signed, same as `complaints.id`. |
| `type_code` | VARCHAR(50), unique | Stable identifier. Immutable after creation (enforced in Phase 3). |
| `type_name` | VARCHAR(100), unique | Display name. Equals the historical `complaints.category` text. |
| `description` | VARCHAR(255), null | |
| `respondent_mode` | ENUM('none','optional','required'), default 'none' | Whether a complaint of this type names a respondent. |
| `is_active` | TINYINT(1), default 1 | |
| `sort_order` | INT, default 0 | |
| `version` | INT, default 1 | For optimistic locking in later phases. |
| `created_by`, `updated_by` | INT, null | FK `barangay_officials(id)` ON DELETE SET NULL. |
| `created_at`, `updated_at` | DATETIME | `updated_at` updates automatically. |

Indexes: `uq_complaint_type_code`, `uq_complaint_type_name`, `idx_type_active (is_active, sort_order)`.

### `complaint_type_fields`

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT, PK, auto_increment | |
| `complaint_type_id` | INT, not null | FK `complaint_types(id)` ON DELETE RESTRICT. No cascade. |
| `field_key` | VARCHAR(50) | Must match `^[a-z][a-z0-9_]{0,49}$`. Not a database constraint; enforced in application code (Phase 3) and checked by the test. |
| `label` | VARCHAR(100) | |
| `field_type` | ENUM('select','text','number','date') | |
| `is_required` | TINYINT(1), default 0 | |
| `options_json` | JSON, null | Select fields only. Array in display order: `[{"value":"pothole","label":"Pothole","is_active":1}, …]`. |
| `max_length` | INT, null | Text fields. |
| `min_value`, `max_value` | DECIMAL(12,2), null | Number fields. |
| `is_active`, `sort_order`, `version` | as in `complaint_types` | |
| `created_at`, `updated_at` | DATETIME | |

Indexes: `uq_type_field (complaint_type_id, field_key)`, `idx_type_fields (complaint_type_id, is_active, sort_order)`.

### `complaints`

- New column `complaint_type_id INT NULL`, placed after `category`.
- New index `idx_complaint_type (complaint_type_id)`.
- New foreign key `fk_complaint_type` to `complaint_types(id)` ON DELETE RESTRICT.
- `category` is not changed. It stays as the historical name.

### Seed

| type_code | type_name | respondent_mode | sort_order | Fields |
|-----------|-----------|-----------------|-----------:|--------|
| `road_infrastructure` | Road / Infrastructure | none | 10 | `road_issue`, `access_impact` |
| `noise_disturbance` | Noise / Public Disturbance | optional | 20 | `noise_source`, `usual_time` |
| `flooding_drainage` | Flooding / Drainage | none | 30 | `drainage_issue`, `affected_area` |
| `public_safety` | Public Safety | optional | 40 | `safety_hazard`, `ongoing` |
| `other` | Other | optional | 50 | `specify` |

- The eight fields from the PHP schema are seeded as `select`, not required, with the same key, label, option values, option labels, and option order.
- `other.specify` is new: text, required, max length 150, label "Please specify". Existing "Other" complaints get no value for it; the Phase 2 read path must tolerate that.

### Backfill

1. Each complaint is linked to the type whose `type_name` equals its `category`. The comparison is byte-exact (`CAST(… AS BINARY)`), the same rule `api/complaints/public_list.php` uses, so case or accent differences do not match.
2. Any complaint still unlinked is linked to `other`. Phase 0 found no unknown categories, so this step is expected to change 0 rows.

### Re-running

The migration is safe to run more than once. Tables use `IF NOT EXISTS`, each `ALTER` is guarded through `information_schema`, seed rows that already exist are left as they are (later edits made in the console are not overwritten), and the backfill only touches rows where `complaint_type_id` is still NULL.

MySQL 8.4 prints a deprecation warning for `VALUES()` in the two `ON DUPLICATE KEY UPDATE` clauses. It is a warning only.

## Run order

Commands are from the project root. They avoid `>` and `<` redirection so they behave the same in PowerShell and Git Bash (PowerShell 5.1 would write a dump through `>` as UTF-16 and corrupt it).

Paths used below:

```
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe
```

### 1. Back up the real database

```
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe -uroot --routines --triggers --single-transaction --result-file=C:/laragon/backups/barangay_before_complaint_types.sql barangay_system_test_environment
```

Create `C:/laragon/backups` first, or use another folder outside the web root. Check that the file is not empty before going on.

### 2. Migrate a test copy and test it

```
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -uroot -e "CREATE DATABASE capstone_ctypes_copy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -uroot --default-character-set=utf8mb4 capstone_ctypes_copy -e "source C:/laragon/backups/barangay_before_complaint_types.sql"
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -uroot --default-character-set=utf8mb4 capstone_ctypes_copy -e "source C:/laragon/www/Capstone-System/project-support/migrations/20261116_complaint_types.sql"
php project-support/tests/complaint-types/run.php --db=capstone_ctypes_copy
```

Run the migration line a second time and rerun the test to confirm the re-run changes nothing. Then drop the copy:

```
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -uroot -e "DROP DATABASE capstone_ctypes_copy"
```

### 3. Migrate the real database and retest

```
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe -uroot --default-character-set=utf8mb4 barangay_system_test_environment -e "source C:/laragon/www/Capstone-System/project-support/migrations/20261116_complaint_types.sql"
php project-support/tests/complaint-types/run.php
php project-support/scripts/complaint-baseline.php
php project-support/tests/review-2026-10-24/run.php
php project-support/tests/document-baseline/run.php
php project-support/tests/document-forms/run.php
php project-support/tests/document-registry/run.php
php project-support/tests/document-templates/run.php
```

Expected, from Phase 0:

| Suite | Phase 0 |
|-------|--------:|
| review-2026-10-24 (whole runner) | 117 / 117 |
| — `interface:` checks | 30 / 30 |
| document-baseline | 6 / 6 |
| document-forms | 126 / 126 |
| document-registry | 27 / 27 |
| document-templates | 11 / 11 |

`complaint-baseline.php` output should be the same as in the Phase 0 document (same category counts, no invalid values, no unknown categories).

Notes:

- Run the complaint checks through `review-2026-10-24/run.php`, not `complaint-interface/api-checks.php` directly. The runner leaves its PHP test server running; stop that process afterwards.
- `review-2026-10-24/run.php`, `document-forms`, and `document-registry` build scratch databases from the real schema or from `database/database.sql`. `database.sql` does not have the new tables until the end of the revision, so those scratch databases will not have them either. That is expected in Phase 1 because no application code uses the tables yet.

## Rollback SQL

Removes everything this migration added, in reverse order. `complaints.category` was never changed, so no data needs restoring.

```sql
ALTER TABLE complaints DROP FOREIGN KEY fk_complaint_type;
ALTER TABLE complaints DROP INDEX idx_complaint_type;
ALTER TABLE complaints DROP COLUMN complaint_type_id;
DROP TABLE IF EXISTS complaint_type_fields;
DROP TABLE IF EXISTS complaint_types;
```

Run it against the database you want to roll back (`USE <database>` first, or pass the database name to `mysql.exe`). Rolling back deletes any complaint types or fields created after the migration.

## Test results

### Before migration (real database, 2026-11-16)

`php project-support/tests/complaint-types/run.php` — read-only dry run to confirm the test fails cleanly when the tables are missing:

```
3/27 passed
```

Exit code 1. The three passing checks are the ones that do not depend on the new tables (seed constants match the PHP schema; the two Phase 0 count checks). The number of checks is higher after migration (53) because per-field checks only run when the type rows exist.

### After migration on the test copy (2026-11-16)

Backup: `C:/laragon/backups/barangay_before_complaint_types.sql` (288,195 bytes, 27 tables, dump completed 2026-11-16 02:54:23).

Copy: `capstone_ctypes_copy`, loaded from that backup (27 tables, 7 complaints).

| Step | Result |
|------|--------|
| Migration, first run | exit 0 |
| `complaint-types/run.php --db=capstone_ctypes_copy` | `53/53 passed`, exit 0 |
| Migration, second run | exit 0; row counts, max ids, and `updated_at` unchanged |
| `complaint-types/run.php --db=capstone_ctypes_copy` again | `53/53 passed`, exit 0 |

Warnings printed by the migration, none of them errors:

- `1681 Integer display width is deprecated` — from `TINYINT(1)`, the same form the existing tables use.
- `1287 'VALUES function' is deprecated` — from the two `ON DUPLICATE KEY UPDATE` clauses.
- `1050 Table already exists` (note) — second run only, from `CREATE TABLE IF NOT EXISTS`.

Backfill result on the copy:

| complaints.id | category | complaint_type_id | type_code |
|--------------:|----------|------------------:|-----------|
| 1 | Noise / Public Disturbance | 2 | noise_disturbance |
| 2 | Other | 5 | other |
| 3 | Flooding / Drainage | 3 | flooding_drainage |
| 4 | Noise / Public Disturbance | 2 | noise_disturbance |
| 5 | Noise / Public Disturbance | 2 | noise_disturbance |
| 6 | Noise / Public Disturbance | 2 | noise_disturbance |
| 7 | Road / Infrastructure | 1 | road_infrastructure |

`complaint_type_fields` has 9 rows with ids 1–8 and 16. The gap is how InnoDB allocates ids for a multi-row `INSERT … SELECT`; nothing depends on those ids.

### After migration on the real database (2026-11-16)

Migration on `barangay_system_test_environment`: exit 0, same deprecation warnings as on the copy, no errors.

| Check | Phase 0 | After Phase 1 | Exit |
|-------|--------:|--------------:|-----:|
| complaint-types | — | 53 / 53 | 0 |
| review-2026-10-24 (whole runner) | 117 / 117 | 117 / 117 | 0 |
| — `interface:` checks | 30 / 30 | 30 / 30 | — |
| document-baseline | 6 / 6 | 6 / 6 | 0 |
| document-forms | 126 / 126 | 126 / 126 | 0 |
| document-registry | 27 / 27 | 27 / 27 | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |

Every count matches Phase 0.

`complaint-baseline.php`: output identical to the run made before the migration (compared with `diff`, ignoring the timestamp line), and the same as recorded in the Phase 0 document: 7 complaints, same per-category counts, no INVALID STORED VALUES, no UNKNOWN CATEGORIES.

Backfill on the real database is the same as on the copy: all 7 complaints linked by exact name (4 `noise_disturbance`, 1 `other`, 1 `flooding_drainage`, 1 `road_infrastructure`); none needed the "Other" fallback.

Cleanup after the run:

- The PHP test server left by `review-2026-10-24/run.php` (127.0.0.1:64689) was stopped.
- `capstone_ctypes_copy` was dropped.
- `capstone_fix24_13852` (the review runner's scratch database) remains; the runner drops it at the start of its next run.
- The backup `C:/laragon/backups/barangay_before_complaint_types.sql` is kept.

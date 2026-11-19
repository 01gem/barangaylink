# Go-live inventory: complaint-revision test data in the real database

Taken 2026-11-17, **read-only** (SELECT queries and file listings only) on `barangay_system_test_environment`. Nothing was changed or removed.

The browser checklists for P4–P7 ran on the real application on 2026-11-16 and 2026-11-17, using the developer's own accounts:
- **resident `gem`** (id 1002, developer resident account);
- **officials `gem`** (id 1, developer admin account, admin) and **`patrick`** (id 2, staff teammate account, staff).

"Likely test" below is a suggestion from dates, accounts and content. **The developer decides.**

## 1. Complaint types

| id | code | name | active | complaints | fields | Created / changed | Verdict |
|---|---|---|---|---|---|---|---|
| 1 | road_infrastructure | Road / Infrastructure | yes | 1 | 2 | seed; v2 = P5 follow-up `none` → `optional` (audit 294) | **real (seed)**, keep. The v2 change is intended. |
| 2 | noise_disturbance | Noise / Public Disturbance | yes | 5 | 2 | seed, unchanged | **real (seed)** |
| 3 | flooding_drainage | Flooding / Drainage | yes | 1 | 2 | seed; v2 = P5 follow-up (audit 295) | **real (seed)**, keep |
| 4 | public_safety | Public Safety | yes | 0 | 2 | seed, unchanged | **real (seed)** |
| 5 | other | Other | yes | 1 | 1 | seed, unchanged | **real (seed)** |
| 6 | stray_animals | **Stray Animal** | **no** | 1 (#8) | 4 (17–20; 18 inactive) | created 2026-11-16 12:06 by developer admin account as "Stray Animals" (audit 285–290), renamed "Stray Animal" by staff teammate account (292), deactivated (293) | **likely test** (P4 checklist: create, add fields, rename, deactivate) |

No seeded type was renamed: types 1–5 have exactly their seed names. Only type 6 was renamed and deactivated.

## 2. Complaints

| id | reference_no | type | status | filed_by | filer | filed | case_no | parties | hearings | summons | Verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1–6 | CSR-20261026-… to CSR-20261028-… | Noise ×4, Other, Flooding | 5 resolved, 1 investigating | resident | nanol, bonono, mic | Oct 26–28 | — | 1 each (backfilled) | — | — | before the revision; outside this inventory, **assumed real** |
| 7 | CSR-20261110-A8E119 | Road / Infrastructure | resolved | resident | `gem` (1002) | Nov 10 | — | 1 | — | — | outside Nov 16–17; filed by the developer account. **Your call.** |
| **8** | CSR-20261116-44202A | Stray Animal (6), stored category "Stray Animals" | open | resident | `gem` (1002) | **Nov 16** 12:14 | — | 1 (complainant 8) | — | — | **likely test** (P4: filing on the new type) |
| **9** | CSR-20261116-66F1B1 | Noise / Public Disturbance | investigating | resident | `gem` (1002) | **Nov 16** 18:25 | **BC-2026-0001** | 2 (16 complainant gem, 17 respondent "John Edmund", not registered) | 1 | 3 | **likely test** (P5–P7: respondent, mediation, summons) |

Rows that belong to complaint 8:
- `complaint_updates`: 27
- `complaint_attachments`: 9, 10, 11. The files are present under the attachment storage `8/` folder: `9c45ae6c….png`, `9a2f2a79….png`, `b82a5b42….png`.
- `complaint_comments`: none
- `sms_log`: 38 (`complaint_filed`, sent)

Rows that belong to complaint 9:
- `complaint_parties`: 16, 17
- `complaint_hearings`: **1**. Session 1, **scheduled for Tue 2026-11-24 09:00–10:00**, Barangay Hall. It is an **upcoming** session: it shows on the Mediation Calendar and in the panel demo.
- `complaint_hearing_attendance`: (1, 16), (1, 17), both `not_recorded`
- `complaint_documents`, all for hearing 1, party 17; all 6 files present in `generated_documents/complaints/`:

  | id | status | template | file_uuid |
  |---|---|---|---|
  | 1 | superseded | v1 | `586d01e4-2b1d-436e-b8bd-fc0e6ac46aa0` (.pdf + .docx) |
  | 2 | superseded | v8 | `55ba5686-4b5b-4335-a2d2-9660f07ec074` (.pdf + .docx) |
  | 3 | current | v11 | `ec4bb4db-29a7-42e5-9555-fedb80cb7a93` (.pdf + .docx) |
- `complaint_updates`: 28 (filed), 29 (open → investigating, mediation scheduled), 30, 31, 32 (summons issued)
- `notifications`: 98 ("Your complaint CSR-20261116-66F1B1 is now investigating.", resident 1002)
- `sms_log`: 39 (`complaint_filed`), 40 (`complaint_hearing_scheduled`). Both were really sent to the `gem` resident's number.
- `audit_log`: 297 (Scheduled mediation), 298, 321, 331 (Generated summons). The target is `BC-2026-0001`.

**Case counter:** `complaint_case_counters` year 2026, `last_no` 1. The only case number ever issued is BC-2026-0001 (complaint 9).

**Orphan attachment files** (no database row; older than this revision, listed for completeness):
- storage folder `8/`: 4 `.jpg` files (`1fb650e9…`, `55966cc0…`, `86b5dd40…`, `bc53f9ab…`)
- storage folder `14/`: 3 `.jpg` files

There is no complaint 14. These probably belong to complaints deleted before complaint ids were reused.

## 3. Settings and templates

| Item | Value | Verdict |
|---|---|---|
| `document_settings.captain_name` | "Erwin Astronomo" (set 2026-11-12 by official 1) | real setting; confirm the name |
| `LIBREOFFICE_PATH` | not set (not in `.env`) | OK: the default `C:/Program Files/LibreOffice/program/soffice.exe` exists |
| Summons active version | **v11** (`tpl_48_v11_ab46e370.docx`, id 35, active since 2026-11-17 01:25:20); passes the Phase 9 wording rule | keep |
| Summons versions v1–v10 | v1, v6, v8 retired; v2, v3, v4, v5, v7, v9, v10 discarded; 11 `tpl_48_v*.docx` files on disk | template history. v1 and v8 are referenced by complaint_documents 1 and 2 (complaint 9). After complaint 9 is gone, nothing references v1–v10. Keeping them is harmless; removing them is optional. |

## 4. Safe removal order (if the developer chooses; nothing was removed)

**Back up first:**
```
mysqldump -u root barangay_system_test_environment > C:/laragon/backups/barangay_before_golive_cleanup.sql
```
Take this backup before any removal. Then, children before parents (the foreign keys are RESTRICT unless noted):

1. **`complaint_documents` 1, 2, 3** (complaint 9). Then the 6 files `generated_documents/complaints/<uuid>.pdf|.docx` listed above. Keep `generated_documents/complaints/.htaccess`.
2. **`complaint_hearing_attendance`** for hearing 1. The cascade from the hearing would also remove them; deleting them explicitly is clearer.
3. **`complaint_hearings`** 1. No reschedules, so there is no `rescheduled_from_id` chain.
4. **`complaint_parties`** 16, 17 (complaint 9) and 8 (complaint 8). `fk_party_complaint` is ON DELETE CASCADE, but delete explicitly after the documents and attendance, which reference parties with RESTRICT.
5. **`complaint_updates`** 27–32; **`complaint_comments`** (none); **`complaint_attachments`** 9, 10, 11, plus their 3 files in storage folder `8/`; **`notifications`** 98; optionally **`sms_log`** 38–40 (a log of SMS that were really sent; keeping them is also fine).
6. **`complaints`** 8 and 9.
7. **Case counter:** `DELETE FROM complaint_case_counters WHERE year = 2026`, so the first real case is BC-2026-0001 again. Only safe once no 2026 `case_no` remains; check with `SELECT COUNT(*) FROM complaints WHERE case_no LIKE 'BC-2026-%'`, which must return 0.
8. **Type 6 "Stray Animal"** (optional; it is inactive, so residents never see it):
   - first `complaint_type_fields` 17–20;
   - then `complaint_types` 6, only after complaint 8 is gone. `fk_complaint_type` and `fk_type_field_type` are RESTRICT.
9. **Audit rows** (285–293 types; 297, 298, 321, 331 hearing and summons): recommended **keep**. The audit log is history, and the rows hold no personal data beyond official names.
10. **Optional:** discarded or retired Summons versions v1–v10 and their files. Keep **v11**. Nothing references the others after step 1.
11. **Optional, outside this revision:** the orphan attachment files in storage folders `8/` (4 `.jpg`) and `14/` (3 `.jpg`). Remove them only after confirming no restore point needs them.

Unchanged by any of this: complaints 1–7, seed types 1–5 (including the intended v2 `respondent_mode` change), `captain_name`, Summons v11.

## Cleanup done (2026-11-17)

Run on the real database `barangay_system_test_environment` at the developer's request. **Complaint 7 was kept**: it was not named in the request.

**Backup:** `C:/laragon/backups/barangay_before_golive_cleanup.sql` (329,827 bytes, 35 tables). It ends with "Dump completed on 2026-11-17 7:34:06" and has no `USE` or `CREATE DATABASE` line. A new file; nothing was overwritten.

**Pre-check:** every count matched section 2 of this document, and every guard was 0:
- no other complaint uses type 6;
- no other 2026 case number exists;
- no other complaint marks 8 or 9 as its duplicate;
- no other complaint's documents, attendance or reschedules point at the removed rows.

**Deleted in one transaction:** each DELETE was checked against its expected count before the next one ran, and the transaction was committed.

| Table | Rows | Which |
|---|---|---|
| complaint_documents | 3 | 1, 2, 3 (complaint 9) |
| complaint_hearing_attendance | 2 | hearing 1 |
| complaint_hearings | 1 | 1 |
| complaint_parties | 3 | 8, 16, 17 |
| complaint_updates | 6 | 27–32 |
| complaint_comments | 0 | — |
| complaint_attachments | 3 | 9, 10, 11 |
| notifications | 1 | 98 |
| complaints | 2 | 8, 9 |
| complaint_type_fields | 4 | 17–20 |
| complaint_types | 1 | 6 (`stray_animals`, "Stray Animal") |
| complaint_case_counters | 1 | year 2026 |

**Kept:**
- the audit rows (285–293, 297, 298, 321, 331);
- `sms_log` 38–40;
- all 11 Summons template versions and their files (v11 active);
- complaints 1–7;
- types 1–5.

**Files moved, never deleted.** All 16 went to `C:/laragon/backups/golive-removed-files/`, keeping their relative paths. Each was sha256-checked after the move. The emptied folders `complaint_attachments/8/` and `14/` were removed. `generated_documents/complaints/.htaccess` stays.

| File | Bytes |
|---|---|
| generated_documents/complaints/586d01e4-2b1d-436e-b8bd-fc0e6ac46aa0.pdf | 194,050 |
| generated_documents/complaints/586d01e4-2b1d-436e-b8bd-fc0e6ac46aa0.docx | 12,380 |
| generated_documents/complaints/55ba5686-4b5b-4335-a2d2-9660f07ec074.pdf | 193,818 |
| generated_documents/complaints/55ba5686-4b5b-4335-a2d2-9660f07ec074.docx | 12,461 |
| generated_documents/complaints/ec4bb4db-29a7-42e5-9555-fedb80cb7a93.pdf | 193,815 |
| generated_documents/complaints/ec4bb4db-29a7-42e5-9555-fedb80cb7a93.docx | 12,457 |
| complaint_attachments/8/9a2f2a79382c8824e566f56b4427b5af.png (complaint 8) | 70,534 |
| complaint_attachments/8/9c45ae6c48ebc74f3a9d9c695dc291ae.png (complaint 8) | 80,364 |
| complaint_attachments/8/b82a5b42cdd3dc9b98ddd838433083e7.png (complaint 8) | 115,084 |
| complaint_attachments/8/1fb650e923f2854789bbe0362114674a.jpg (orphan) | 2,138,759 |
| complaint_attachments/8/55966cc0cf99c082024f77a57272cc0a.jpg (orphan) | 2,142,639 |
| complaint_attachments/8/86b5dd402715c732800c535a0247352d.jpg (orphan) | 1,749,352 |
| complaint_attachments/8/bc53f9abc68e9e949cd617784e6aca02.jpg (orphan) | 148 |
| complaint_attachments/14/4ed27247cdf6737277e7aaf26cbc82e4.jpg (orphan) | 1,451,989 |
| complaint_attachments/14/54012f48b1367263c6ef3ebc10e857e9.jpg (orphan) | 1,137,405 |
| complaint_attachments/14/df4354cb26c45088253dad36b9ee71ad.jpg (orphan) | 2,048,544 |

**Verification after the cleanup:**
- `php project-support/tests/complaint-types/run.php` (live, real database): **107 / 107**, no FAIL.
- Complaints remaining: 1, 2, 3, 4, 5, 6, 7.
- Complaint types: 1 Road / Infrastructure, 2 Noise / Public Disturbance, 3 Flooding / Drainage, 4 Public Safety, 5 Other; all active.
- `complaint_case_counters`: no rows. The next case number is BC-2026-0001.
- Mediation Calendar, November 2026: empty. `complaint_hearings` 0 rows, attendance 0, needs-recording 0.
- `complaint_documents`: 0 rows.

**To undo:** restore the backup and move the 16 files back from `C:/laragon/backups/golive-removed-files/`:
```
cmd /c "mysql -u root barangay_system_test_environment < C:/laragon/backups/barangay_before_golive_cleanup.sql"
```

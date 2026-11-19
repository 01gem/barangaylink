# Complaint revision: summary (P0–P10)

Written 2026-11-17. Details of each phase are in `archive/complaint-revision-phase0.md` … `archive/complaint-revision-phase10.md`.

## Panel request → what was built

The panel asked for **"dynamic complaint rather than static options"**. The complaint categories were a hardcoded PHP array.

| Phase | What | Main files |
|---|---|---|
| P0 | Baseline: every place that used the hardcoded categories, plus counts. No code change. | `archive/complaint-revision-phase0.md` |
| P1 | Tables `complaint_types` and `complaint_type_fields`, seeded to match the old array. `complaints.complaint_type_id` added and backfilled. | `migrations/20261116_complaint_types.sql` |
| P2 | The application reads types and extra fields from the tables. The resident form is built from them. | `api/complaints/_extra_fields.php`, `create.php`, `list.php`, `js/resident.js` |
| P3 | Admin API: any official creates, edits and toggles types and fields. Audit-logged; nothing deleted; `type_code` immutable. | `api/complaint_types/*` |
| P4 | Complaint Types tab. Every display and filter follows `complaint_type_id` (current name). | `js/complaint-types.js`, `pages/official.php`, `_community.php`, `public_list.php` |
| P5 | Parties: complainants and respondents, registered or not. Residents name respondents. Walk-in filing. Party management. `respondent_mode` per type. | `migrations/20261117_complaint_parties.sql`, `api/complaints/_parties.php`, `_filing.php`, `create_official.php`, `party_*.php`, `js/complaint-walkin.js` |
| P6 | Mediation calendar: sessions, attendance, outcomes, case numbers `BC-YYYY-NNNN`, conflicts, SMS to registered parties. | `migrations/20261118_complaint_hearings.sql`, `api/hearings/_hearings.php`, `api/hearings/*`, `js/hearings.js` |
| P7 | Summons per respondent per upcoming session: DOCX + PDF from the managed "Summons" template, with history. | `migrations/20261119_summons.sql`, `api/hearings/_summons.php`, `summons_*.php`, `scripts/build-summons-template.php` |
| P8 | Reports and AI by type id (current name), plus mediation, filing and people figures. The AI gets an allowlisted aggregate payload. | `api/stats/_complaint_report.php`, `complaints_report.php`, `api/ai/analyze_complaints.php`, `js/official.js` |
| P9 | Verification. Summons activation guard and venue article fix. JSON / method guards. End-to-end, install, security and demo tests. Panel demo material. | `api/documents/_template_check.php`, `template_activate.php`, `tests/complaint-e2e`, `tests/complaint-install`, `scripts/demo-complaints.php` |
| P10 | Billing: a default fee per type, one bill per complaint (issue, edit, mark paid, waive), SMS + in-app notice to registered complainants, and a gate on scheduling, rescheduling and summons until the fee is paid or waived. | `migrations/20261120_complaint_billing.sql`, `api/complaints/_billing.php`, `bill_*.php`, `js/complaint-billing.js`, `tests/complaint-billing` |

## Decisions

| # | Decision | Reason |
|---|---|---|
| P1 | `complaints.category` (text) is kept as the historical name; `complaint_type_id` is the link. | No data rewrite; rollback needs no restore. |
| P1 | `type_code` is unique and immutable after creation. | A stable key for seeds, tests and migrations, while the name can change. |
| P1 | The migration is additive and re-runnable (IF NOT EXISTS, guarded ALTERs, seeds never overwrite). | Safe on the real database and on copies. |
| P2 | No fallback to the old PHP array; the request fails (500) when the tables cannot be read. | One source of truth. A silent fallback would hide a broken install. |
| P2 | Residents may still send the legacy `category` name; officials must send the id. | Old cached resident pages keep working. |
| P3 | Any official (staff or admin) manages types. Nothing is deleted; types and fields are deactivated. | Barangay staff run this daily. Old complaints must keep their type. |
| P3 | Optimistic locking (`version`) on every type and field write. | Two officials editing at once never overwrite each other silently. |
| P3 | `Other.specify` cannot be deactivated or made optional. | It is the only way an "Other" complaint describes itself. |
| P4 | Display and filters use `complaint_type_id` and the current name; inactive types show "(no longer used)" in filters. | A rename shows on old complaints; filters keep finding them. |
| P5 | A party is registered (resident_id; address and contact read live) or not registered (typed). `full_name` is always stored. | Documents need a snapshot name; registered data stays current. |
| P5 | Residents type respondents and cannot pick registered residents. | Privacy: residents cannot search the resident registry. |
| P5 | `respondent_mode` none / optional / required per type. Follow-up: every seeded type is `optional` on the real database and in `database.sql`; the 20261116 migration keeps its original seed. | The barangay asked that every type can name a person; the tests check the original migration seed exactly. |
| P5 | Parties are never deleted, only deactivated. | History and attendance keep their references. |
| P6 D1–D13 | `notify_resolved` has the same guard as `update_status` (D1); quarter-hour starts (D2); at most 365 days ahead (D3); completing writes a timeline note (D4); the first scheduling moves the complaint to Investigating (D5); the Manila PHP clock everywhere (D8); deploy the code only after migrating (D9); duplicates can be scheduled (D10); scheduling starts from the complaint detail (D11); every attendance row must be recorded to complete (D12); conflicts are returned in the error body (D13). | See phase6.md, "Decisions agreed before writing". |
| P6 | Case numbers `BC-YYYY-NNNN` from `complaint_case_counters`, assigned on the first scheduling. | Barangay case numbering; one per complaint, never reused. |
| P6 | One lock order everywhere: guard → complaint → hearing. | No deadlocks between party, hearing and status writes. |
| P6 | SMS only to registered parties. | Unregistered contacts are unverified typed numbers. |
| P7 | Summons only for an upcoming scheduled session and an active respondent who is on that session. | A summons must match a real appointment. Someone added later needs a reschedule. |
| P7 | The template is a managed document type with `audience = 'internal'` (versions, test render, activation). | Same tooling as request documents; residents never see it. |
| P7 | Regenerate supersedes; old copies stay viewable. Files are served only through `summons_view.php`, by database id. | Audit trail; no direct file access. |
| P8 | One query module for the report and the AI. The AI payload is an explicit allowlist of keys and row fields. | No duplicated SQL. A future key with personal data cannot leak to the AI. |
| P8 | Report rows are grouped by type id with the current name; `is_active` is shown. | A rename stays one row. |
| P9 | Activation refuses a Summons version with "morning/afternoon", "____", a double space in the summons sentence, or not exactly the 13 keys once. | v6 and v8 on the real database printed broken sentences. |
| P9 | The summons drops one leading "the" from the venue. | The active template says "at the {{venue}}". |
| P9 | `create`, `update_status`, `notify_resolved` and `analyze_complaints` require `application/json` (415) and a JSON object (422). The report and the list are GET only. | A non-JSON body can be sent cross-site without a CORS preflight (CSRF path). |
| P10 | Money is a JSON string read as integer cents (never float); 0.01 .. 100,000.00 per bill, 0 .. 100,000.00 per type. | No rounding errors; one rule in PHP and JS. |
| P10 | One bill per complaint; paid and waived are final; any official may issue, edit, mark paid or waive. Mark paid / waive also after resolution (D2). | Barangay practice: staff and the Captain take payments; late payments happen. |
| P10 | Gate: clear when (type fee 0 and no bill) or the bill is paid / waived. A fee set later on a type blocks that type's existing open complaints until billed and settled. | The fee is due before mediation; a bill on a 0-fee type is still a debt. |
| P10 | Before the migration the gate is clear, list/report hide fees, bill endpoints answer 503, and complaint-types live fails (D1). | No fee can exist without the table; a missing migration is still noticed. |
| P10 | SMS / notices only to registered complainants; respondents are never billed. The resident sees amount, note and status, never the receipt or the waive reason. The AI gets six billing aggregates only (counts and totals). | Privacy; unregistered numbers are unverified. |

## Data model

```
complaint_types            id PK, type_code UNIQUE (immutable), type_name UNIQUE, description, respondent_mode ENUM(none,optional,required),
                           is_active, sort_order, version, created_by/updated_by FK barangay_officials SET NULL, created_at, updated_at
complaint_type_fields      id PK, complaint_type_id FK complaint_types, field_key (unique per type), label, field_type ENUM(select,text,number,date),
                           is_required, options_json, max_length, min_value, max_value, is_active, sort_order, version
complaints                 + complaint_type_id FK complaint_types RESTRICT (category text kept)
                           + filed_by_type ENUM(resident,official), filed_by_official_id FK barangay_officials SET NULL
                           + case_no VARCHAR(20) UNIQUE (BC-YYYY-NNNN, set on the first scheduling)
complaint_parties          id PK, complaint_id FK complaints CASCADE, role ENUM(complainant,respondent), resident_id FK residents RESTRICT NULL,
                           full_name, address, contact_no, sort_order, is_active, version, created_by_type/id,
                           active_resident_id STORED = IF(is_active, resident_id, NULL); UNIQUE (complaint_id, role, active_resident_id)
complaint_case_counters    year PK, last_no
complaint_hearing_guard    id PK (one row: the calendar lock)
complaint_hearings         id PK, complaint_id FK RESTRICT, session_no (UNIQUE per complaint), starts_at, ends_at (Manila; CHECK ends > starts),
                           venue, venue_key, presiding_official_id FK, status ENUM(scheduled,completed,cancelled,rescheduled),
                           outcome ENUM(settled,for_follow_up,referred,no_agreement), public_summary, private_notes,
                           rescheduled_from_id FK self RESTRICT, change_reason, request_key UNIQUE, version, created_by/updated_by FK, completed_at
complaint_hearing_attendance  (hearing_id FK CASCADE, party_id FK RESTRICT) PK, status ENUM(not_recorded,present,absent,excused)
complaint_documents        id PK, complaint_id / hearing_id / party_id FK RESTRICT, kind ENUM(summons), template_version_id FK,
                           file_uuid UNIQUE, pdf_sha256, docx_sha256, status ENUM(current,superseded), generated_by FK, generated_at,
                           active_key STORED = IF(current, hearing:party, NULL) UNIQUE
document_types             + audience ENUM(resident,internal); the "Summons" type (code summons, internal) with 13 fields
complaint_types            + default_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 (P10)
complaint_bills            id PK, complaint_id UNIQUE FK complaints RESTRICT, amount DECIMAL(10,2) (CHECK > 0 and <= 100000), billing_note,
                           status ENUM(unpaid,paid,waived), receipt_no, waive_reason, issued_by / settled_by FK barangay_officials RESTRICT,
                           issued_at, settled_at (Manila), version, created_at, updated_at
```

## Known limits / future work

- No SMS to unregistered parties: their contact numbers are typed and unverified.
- No iCal export of the mediation calendar.
- No online payment: fees are paid at the barangay hall and marked paid by staff (P10).
- `poverty_thresholds` ids 4–12 duplicate ids 1–3. Not changed (outside the complaint revision).
- `database/seed_households.sql` is run-once. It is not idempotent.
- A migrated install (pre-P1 backup + 20261116–20261119) differs from a fresh `database.sql` install in two documented or older places only (see phase9.md, "Fresh install = migrated install"):
  - the P5 `respondent_mode` follow-up (Road and Flooding are `none` after the migration alone);
  - `residents.household_id` NULL / ON DELETE SET NULL (backup) versus NOT NULL / RESTRICT (`database.sql`), which predates P1.
- Inline `onclick` handlers remain in the older resident request and complaint-detail markup (`js/resident.js`, Oct 2026). They predate P2 and were not changed.

## Rollback order (newest first)

Preferred for each: restore the backup taken before that migration:
- `C:/laragon-backups/barangay_before_complaint_billing.sql`
- `C:/laragon-backups/barangay_before_summons.sql`
- `C:/laragon/backups/barangay_before_complaint_hearings.sql`
- `C:/laragon/backups/barangay_before_complaint_parties.sql`
- `C:/laragon/backups/barangay_before_complaint_types.sql`

Restoring loses rows written after the backup. To undo the schema only, run these in this order. **Each deletes data; there is no undo.** Back up first, and roll back the matching code at the same time.

0. **20261120 (P10, billing):**
   ```sql
   DROP TABLE complaint_bills;
   ALTER TABLE complaint_types DROP COLUMN default_fee;
   ```
1. **20261119 (P7, summons):**
   ```sql
   DROP TABLE complaint_documents;
   DELETE v FROM document_template_versions v JOIN document_types t ON t.id = v.document_type_id WHERE t.code = 'summons';
   DELETE FROM document_types WHERE code = 'summons';
   ALTER TABLE document_types DROP COLUMN audience;
   ```
   Then delete `generated_documents/complaints/*` and the `tpl_<summonsTypeId>_v*.docx` files by hand.
2. **20261118 (P6, hearings):**
   ```sql
   DROP TABLE complaint_hearing_attendance;
   DROP TABLE complaint_hearings;
   DROP TABLE complaint_hearing_guard;
   DROP TABLE complaint_case_counters;
   ALTER TABLE complaints DROP KEY uq_complaint_case_no, DROP COLUMN case_no;
   ```
3. **20261117 (P5, parties):**
   ```sql
   DROP TABLE complaint_parties;
   ALTER TABLE complaints DROP FOREIGN KEY fk_complaint_filed_by, DROP KEY idx_complaint_filed_by,
     DROP COLUMN filed_by_official_id, DROP COLUMN filed_by_type;
   ```
4. **20261116 (P1, types):**
   ```sql
   ALTER TABLE complaints DROP FOREIGN KEY fk_complaint_type;
   ALTER TABLE complaints DROP INDEX idx_complaint_type;
   ALTER TABLE complaints DROP COLUMN complaint_type_id;
   DROP TABLE IF EXISTS complaint_type_fields;
   DROP TABLE IF EXISTS complaint_types;
   ```

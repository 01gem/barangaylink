# Complaint revision — Phase 10: complaint billing

Written 2026-11-17. Not committed.

The real database (`barangay_system_test_environment`) was migrated on 2026-11-17, at the developer's request, once every suite was green:
- backup `C:\laragon-backups\barangay_before_complaint_billing.sql` (taken right before the migration);
- the backup was restored to a throwaway copy; 20261120 was run on the copy twice (the second run changed nothing); complaint-types live on the copy **115 / 115**; the copy was dropped;
- migration 20261120 on the real database;
- complaint-types live on the real database **115 / 115** (107 / 108 before the migration: only the new P10 "migration applied" check failed, as expected).

| Real database | Before | After |
|---|---|---|
| complaints | 7 | 7 |
| complaint_types | 5 (all `default_fee` 0.00 after) | 5 |
| complaint_parties | 7 | 7 |
| complaint_hearings | 0 | 0 |
| complaint_documents | 0 | 0 |
| audit_log | 275 | 275 |
| sms_log | 40 | 40 |
| notifications | 97 | 97 |
| residents | 103 | 103 |
| complaint_bills | — (no table) | 0 |

The manual browser checklist below has not been run yet.

## Purpose

1. Each complaint type has a **default fee** (`complaint_types.default_fee`, 0 = no fee).
2. Staff issue **one bill per complaint** to the complainant. The amount is prefilled from the type and can be changed. A billing note is shown to the complainant.
3. Staff or the Captain (any official, staff or admin) **mark it paid** (optional receipt no.) or **waive** it (reason required).
4. Registered complainants get an SMS and an in-app notice when a fee is issued, paid or waived.
5. Scheduling mediation, rescheduling and generating a summons are **blocked** until the bill is paid or waived.

Not in this phase: online payment; bills to respondents. Hearing, summons and party rules are unchanged apart from the fee gate.

## Files

| File | Change |
|---|---|
| `project-support/migrations/20261120_complaint_billing.sql` | **new**: `complaint_types.default_fee` (guarded ALTER), `complaint_bills` |
| `database/database.sql` | `default_fee` in the `complaint_types` CREATE; the migration body after `complaint_documents` (guarded, re-import safe, no `USE`) |
| `api/complaints/_billing.php` | **new**: rules, money, gate, views, writes, notices |
| `api/complaints/bill_get.php`, `bill_issue.php`, `bill_update.php`, `bill_mark_paid.php`, `bill_waive.php` | **new** endpoints |
| `api/hearings/_hearings.php` | gate in `hearing_complaint_problems()` (schedule, reschedule, `check.php`, `for_complaint.php` blocker) |
| `api/hearings/_summons.php` | gate in `summons_context()` (pre-check and under the lock) and the `summons_list()` blocker |
| `api/complaint_types/_admin.php`, `save_type.php` | `default_fee` accepted (string, 0 .. 100,000.00), returned, audited |
| `api/complaints/list.php` | `bill` per complaint (official / filer views); `type_default_fee` for officials |
| `api/stats/_complaint_report.php` | `billing` key; `complaint_ai_payload()` passes its six aggregate keys only (follow-up, see Privacy) |
| `api/ai/analyze_complaints.php` | follow-up: one prompt line (unpaid fee backlog / follow-up); output keys unchanged |
| `js/complaint-billing.js` | **new**: the Billing block of the official complaint detail; list-badge rule |
| `js/official.js` | Billing slot (before Mediation); `bill` / `typeDefaultFee` in the mapper; fee badge on list cards; report "Fees" card and CSV rows |
| `js/complaint-types.js`, `pages/official.php` | "Default fee (₱)" input; Fee column in the types table; script tag; report card container |
| `js/resident.js` | "Fee" section of the resident complaint detail |
| `css/official.css`, `css/resident.css` | one Phase 10 block each |
| `project-support/tests/complaint-billing/run.php`, `frontend.js` | **new** suites |
| `project-support/tests/complaint-types/run.php` | Phase 10 live invariants (incl. "migration applied") |
| `project-support/tests/complaint-e2e/run.php` | scenario 13 (fee variant) + five bill endpoints in the guard sweep |
| `project-support/tests/complaint-install/run.php` | 20261120 in the migrated chain; `default_fee` in the seed comparison |

## Rules

| Rule | Detail |
|---|---|
| Money | A JSON **string** `^\d{1,6}(\.\d{1,2})?$` (D modifier). Read as integer cents, never float; stored `DECIMAL(10,2)`; displayed with `number_format` on the integer part. Bill: 0.01 .. 100,000.00. Type fee: 0 .. 100,000.00. A JSON number is refused (422). |
| One bill | `UNIQUE (complaint_id)`. A second issue → 409 `This complaint already has a fee.` |
| Issue | Complaint open or investigating (else 409). Amount required (prefilled client-side from the type). Note ≤ 255, optional. |
| Update | Unpaid only, version-checked; complaint open or investigating. A missing `billing_note` keeps the note; `""` clears it. Same values → `changed: false`, no version bump, no audit. |
| Mark paid | unpaid → paid. Receipt optional, ≤ 50, `[A-Za-z0-9-/ ]`. Allowed after the complaint is resolved (late payment, D2). |
| Waive | unpaid → waived. Reason 5..500 (same checks as hearing reasons). Staff or admin. Allowed after resolution (D2). |
| Final | Paid and waived bills cannot change (409 `This fee is already settled and cannot be changed.`). |
| Stale | Wrong version → 409 `This item was changed by someone else. Reload and try again.` |
| Gate | `complaint_billing_clear()`: true when (type fee = 0 **and** no bill) **or** the bill is paid / waived. A bill issued on a 0-fee type must still be settled. Refusal: 409 `The complaint fee must be paid or waived first.` |
| Gate places | `hearings/schedule.php`, `reschedule.php`, `summons_generate.php` (inside the existing transaction, after the complaint lock; the bill is read `FOR SHARE`); `check.php` problems; `for_complaint.php` `schedule_blocker`; `summons_list.php` `blocker`. |
| Existing complaints | **Setting a fee on a type blocks scheduling and rescheduling (and new summonses) for that type's existing open complaints until a bill is issued and settled.** Lowering the fee to 0 again unblocks complaints that have no bill. A fee change never changes an issued bill. |
| Locking | Billing writes: complaint `FOR UPDATE` → bill `FOR UPDATE`. Hearing / summons writes: (guard →) complaint `FOR UPDATE` → bill `FOR SHARE`. Same order, no cycle. |
| Time | `hearing_now()` (Asia/Manila) for `issued_at`, `settled_at`, `updated_at`, timeline rows and notices. |
| Before the migration (D1) | `list.php` and the report leave the `bill` / `billing` keys out; the gate is clear (no fee can exist); bill endpoints answer 503 `Billing needs the 20261120 database migration.`; complaint-types live **fails** `P10 complaint_bills and complaint_types.default_fee exist`, so a missing migration is noticed. |

## Endpoints

All need an official session (401 otherwise, also for residents). POST endpoints need `application/json` (415) and a JSON object (422); unknown keys → 422.

| Endpoint | Request | Response |
|---|---|---|
| `GET complaints/bill_get.php?complaint_id=N` | — | `{complaint: {id, reference_no, status, type_name}, default_fee: "50.00", clear, blocker, can_issue, bill: null \| {id, amount, billing_note, status, receipt_no, waive_reason, issued_at, issued_by_name, settled_at, settled_by_name, version}}` |
| `POST complaints/bill_issue.php` | `{complaint_id, amount: "50.00", billing_note?}` | `{bill, clear, sms}` |
| `POST complaints/bill_update.php` | `{complaint_id, version, amount, billing_note?}` | `{changed, bill, clear, sms: []}` |
| `POST complaints/bill_mark_paid.php` | `{complaint_id, version, receipt_no?}` | `{bill, clear, sms}` |
| `POST complaints/bill_waive.php` | `{complaint_id, version, reason}` | `{bill, clear, sms}` |
| `POST complaint_types/save_type.php` | `+ default_fee: "0" .. "100000.00"` | `type.default_fee: "N.NN"` (also in `complaint_types/list.php`) |

## Texts

SMS use "PHP", not the peso sign (the peso sign would switch the SMS to UCS-2 and a 70-character limit). No names. The longest (billed, PHP 100,000.00, `CSR-YYYYMMDD-XXXXXX`) is 120 characters. SMS endpoints: `complaint_bill_issued`, `complaint_bill_paid`, `complaint_bill_waived` (preference `complaints_enabled`, the usual daily cap).

| Event | SMS | In-app notice (title "Complaint Fee") | Timeline |
|---|---|---|---|
| issued | `Brgy. Sampaguita: A fee of PHP 50.00 was issued for complaint CSR-20261117-AB12CD. Please pay at the barangay hall.` | `A fee of PHP 50.00 was issued for your complaint CSR-20261117-AB12CD. Please pay at the barangay hall.` | `Fee issued.` |
| paid | `Brgy. Sampaguita: Payment for complaint CSR-20261117-AB12CD was received. Thank you.` | `Payment for your complaint CSR-20261117-AB12CD was received.` | `Fee paid.` |
| waived | `Brgy. Sampaguita: The fee for complaint CSR-20261117-AB12CD was waived. No payment is needed.` | `The fee for your complaint CSR-20261117-AB12CD was waived.` | `Fee waived.` |

- Recipients: active **complainant** parties with a `resident_id` (registered), de-duplicated. Unregistered complainants and all respondents get nothing.
- Sent after the commit; a failure is logged and never undoes the write. Editing a bill sends nothing and writes no timeline row.
- Audit (`target_type = complaint_bill`, reference = reference no.): `Issued complaint fee` (amount, note), `Updated complaint fee` (changes), `Marked complaint fee paid` (amount, receipt), `Waived complaint fee` (amount, reason).

## Privacy

| Data | Official (detail / `bill_get`) | Official list (`list.php`) | Filer (`list.php`) | Community board | Timeline | Report | AI |
|---|---|---|---|---|---|---|---|
| amount | yes | yes | yes | no | no | totals only | totals only |
| billing note | yes | yes | yes | no | no | no | no |
| status, issued_at, settled_at | yes | yes | yes | no | event only | counts | counts (no dates) |
| receipt no. | yes | yes | **no** | no | no | no | no |
| waive reason | yes | yes | **no** | no | no | no | no |
| issued / settled by (names) | yes | no | **no** | no | no | no | no |
| type default fee | yes | yes | no | no | no | no | no |

**AI (follow-up, 2026-11-17).** The AI commentary receives `billing` = `{bills_issued, unpaid_count, unpaid_total, paid_count, paid_total, waived_count}` and nothing else about fees: no bill rows, amounts per complaint, notes, receipt numbers, waive reasons, names, reference or case numbers. The keys are an explicit allowlist (`COMPLAINT_AI_BILLING_KEYS`); a key added to `billing` later does not reach the model. The prompt may mention an unpaid fee backlog and suggest follow-up on unpaid fees.

## UI

- **Complaint Types**: "Default fee (₱)" input (`0.00` for a new type; 0 = no fee) and a Fee column ("No fee" or ₱N.NN).
- **Official complaint detail → Billing** (above Mediation):
  - not billed, fee > 0: "Fee for this type: ₱50.00 — not issued yet.", **Issue bill** (form prefilled with the fee), the gate message;
  - not billed, fee 0: "No fee for this type.", **Issue bill (optional)**;
  - unpaid: amount, status, note, issued date and official; **Edit** (open / investigating only), **Mark paid** (receipt no. optional), **Waive** (reason);
  - paid / waived: who, when, receipt or reason.
  - After a write, the Mediation block is redrawn, so the Schedule button and its reason follow at once.
- **Complaints list cards**: "Unpaid fee" (unpaid bill) or "Fee not issued" (no bill, type fee > 0, complaint open or investigating).
- **Mediation block / session window**: no JS change. The server's `schedule_blocker` and summons `blocker` carry the gate message, so Schedule is disabled with the reason and the Summons buttons show it.
- **Resident complaint detail → Fee**: amount, note, "Unpaid — pay at the barangay hall" / "Paid" / "Waived". Never the receipt or the reason.
- **Reports → Fees**: Bills issued, Unpaid (count, total), Paid (count, total), Waived; also in the CSV.

## Rollback

Preferred: restore `C:\laragon-backups\barangay_before_complaint_billing.sql`. Restoring loses rows written after the backup.

Schema only. **This deletes every bill and every type fee; there is no undo.** Back up first, and roll back the code at the same time (the code answers 503 on the bill endpoints and hides fees when the table is missing, but complaint-types live will fail its P10 check).

```sql
DROP TABLE complaint_bills;
ALTER TABLE complaint_types DROP COLUMN default_fee;
```

## Results

Full regression, 2026-11-17 (after the last SQL change, the SQL-dependent suites were run again; the numbers below are the final ones).

| Suite | Result |
|---|---|
| complaint-billing (new) | **51 / 51** (helpers; sections a–i; real database counts unchanged) |
| complaint-billing `frontend.js` (new) | **31 / 31** |
| complaint-types live, real database, before the migration | 107 / 108 (only `P10 … migration applied` failed, as expected) |
| complaint-types live, throwaway migrated copy | **115 / 115** |
| complaint-types live, real database, after the migration | **115 / 115** |
| complaint-summons | 77 / 77 |
| complaint-e2e | **74 / 74** (65 + 4 scenario-13 checks + 5 bill endpoints in the guard sweep) |
| complaint-install | **27 / 27** (fresh = migrated with 20261120; second run changes nothing) |
| demo-complaints | 15 / 15 |
| review-2026-10-24 (whole runner) | 438 / 438 |
| review-2026-10-24 `render-check.js` | 131 / 131 |
| complaint-hearings / complaint-types / complaint-parties `frontend.js` | 40 / 40, 26 / 26, 22 / 22 |
| complaint-interface `storage-config-check.php` | 9 / 9 |
| document-baseline | passed |
| document-registry | 28 / 28 |
| document-templates / endpoints | 11 / 11, 76 / 76 |
| document-forms + `frontend.js` | 126 / 126, 60 / 60 |
| document-manual-edit | 162 / 162 |
| document-type-create | 153 / 153 |
| document-apply | 117 / 117 |
| document-analysis | 104 / 104 |
| storage-override | 14 / 14 |
| request-concurrency + `frontend.js` | 81 / 81, 14 / 14 |

### Defects found while testing (fixed)

| # | Defect | Fix |
|---|---|---|
| 1 | complaint-types P3 expected the exact create defaults of `complaint_type_validate_type()`; adding `default_fee` to them changed the shape. | The validator shape is unchanged; the create path defaults the fee to 0.00 itself. |
| 2 | document-registry re-imports `database.sql` with the `complaint_types` section stripped; the guarded `ADD COLUMN default_fee` then ran against a missing table and stopped the import silently (the suite exited 255). | The guard (in `database.sql` and the migration) also requires the `complaint_types` table to exist. |

`php -l` passes on every touched PHP file; `node --check` on every touched JS file.

## Manual checklist (browser, real application)

1. Set a fee on a type (Complaint Types → Edit → Default fee 50.00). File a complaint of that type as a resident.
2. Staff open the complaint → Billing → Issue bill (50.00 prefilled, note "Filing fee"). The resident gets the SMS and the notification and sees the amount and note under "Fee" ("Unpaid — pay at the barangay hall"). The list card shows "Unpaid fee".
3. Try to schedule mediation: the Schedule button is disabled with "The complaint fee must be paid or waived first."
4. Mark paid with a receipt no. → Schedule works → generate the summons; it prints.
5. Another complaint of the same type: Waive with a reason → Schedule is enabled. The resident sees "Waived", not the reason.
6. Reports → Complaints → Fees shows the billing totals.

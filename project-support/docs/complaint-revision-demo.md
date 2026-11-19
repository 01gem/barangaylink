# Complaint revision: 10-minute panel walkthrough

The panel asked for **"dynamic complaint rather than static options"**. This walkthrough shows that complaint types are now data that staff manage. It also shows the people involved in a complaint, the mediation calendar, and summons.

## Before the panel (developer, 15 minutes ahead)

1. **Back up the database.** Seeding writes to the real database: three DEMO complaints, two DEMO residents, one DEMO type, sessions and two summonses.
   ```
   cmd /c "mysqldump -u root barangay_system_test_environment > C:/laragon/backups/barangay_before_demo.sql"
   ```
2. **Seed the demo data.** This runs on the current database; seeding the real database is the developer's decision.
   ```
   php project-support/scripts/demo-complaints.php --seed
   ```
   - The script refuses a second run.
   - It writes `project-support/demo/demo-manifest.json`, the exact list of rows and files it created.
   - If LibreOffice fails, the two summonses are skipped and a `NOTE` line says so (see Fallbacks).
3. Open two browser windows:
   - **A, official:** log in as an admin official, e.g. `gem`.
   - **B, resident:** log in as an ordinary resident test account you already use. The two DEMO residents cannot log in: their password is random.
4. **After the panel:**
   ```
   php project-support/scripts/demo-complaints.php --cleanup
   ```
   This deletes exactly the manifest's rows and files, then the manifest. Nothing else is touched. The test `project-support/tests/demo-complaints/run.php` proves it on a scratch database: every table count and file is identical before and after.

## Walkthrough

| Min | Who / where | Do | What the panel should notice |
|---|---|---|---|
| 0–2 | **A** · Complaint Types | Open the tab. Show the list, then **DEMO Boundary Dispute** (respondent *required*, one select field "Which side of the lot"). Click **New type**: name "Panel Test Concern", respondents *optional*. Add a select field with two options. | **The panel's request.** Types and their extra fields are rows in the database (`complaint_types`, `complaint_type_fields`), edited from the console, audit-logged, never deleted. No code change and no redeploy. |
| 2–3 | **B** · Complaints | Reload the resident page. Pick "Panel Test Concern": its select field appears. Add a person involved (typed name). Submit; note the reference number. | The resident form is built from the database. The new type is available at once, with its own fields. |
| 3–4 | **A** · Grievance Redressal | Open the new complaint. Show **Parties**: complainant (registered, from B) and the typed respondent (not registered). Click **Link** on the respondent to pick a registered resident. | **Persons involved.** Each party is registered (address and contact read live) or not registered (typed). Officials can link, add or remove parties. |
| 4–5 | **A** · Walk-in | **New walk-in complaint**: an unregistered complainant against a registered resident. | Walk-in filing at the barangay hall. It appears in the official list, never in a resident's list. |
| 5–7 | **A** · Mediation Calendar | Show the month view. The DEMO session (BC-DEMO-0001) sits 3 days ahead. Open it: parties, attendance, the **Summons** box with two issued summonses. Click **Open PDF**. Back on the new complaint: **Schedule mediation** (a free weekday slot). It gets case number `BC-YYYY-NNNN` and moves to Investigating. | **Mediation calendar.** Conflicts (same room, same official, same person) are refused. Registered parties get an SMS; unregistered ones get none. |
| 7–8 | **A** · Summons | In the new session: **Generate** for the respondent → PDF. Point at the sentence "… at 9:00 in the morning at the …". | **Summons** is a DOCX + PDF from the managed "Summons" template (v11). Regenerating keeps the old copy in the history. Residents never see summons. |
| 8–9 | **A** · DEMO resolved complaint (BC-DEMO-0002) | Show the completed session: outcome Settled, public summary. On **B** (any resident's own complaint with a completed session), only the public summary shows. | Private notes stay with the officials. Residents see the outcome and the public summary only. |
| 9–10 | **A** · AI Analytics → Complaints Report | Show type rows (current names), the Mediation cards (sessions, settlement rate, needs recording → click opens the calendar), Filing and People involved. **Generate AI Commentary**. | Reports group by type id with the current name, so a rename stays one row. The AI receives counts only, never names (archive/complaint-revision-phase8.md). |

Optional, if time allows: rename "Panel Test Concern" in Complaint Types and refresh the report and lists. The old complaint shows the new name everywhere, and the report still has one row.

## Fallbacks

- **No internet.**
  - **What breaks:** only **Generate AI Commentary**. It answers with an error and the rest of the report still shows. Skip that step and point at the cards.
  - **What still works:** Gemini is not used anywhere else in this walkthrough (types, filing, parties, calendar, summons, report).
  - **SMS:** sending fails quietly and the write is kept. The calendar still works.
- **LibreOffice fails** (summons generation or the template test render answers "Summons could not be generated").
  - Open `project-support/demo/sample-summons-DEMO.pdf`. It was pre-generated on 2026-11-17 on a scratch database by `demo-complaints.php --seed`, through the same code path as the live demo, with the DEMO data.
  - The template was a temp copy of the active real Summons template v11 (`tpl_48_v11_ab46e370.docx`), so the sentence reads exactly like a live summons: "… at 9:00 in the morning at the DEMO Barangay Hall, then and there …".
  - `document_templates/` and the real database were not touched.
  - Rerunning `tests/demo-complaints/run.php --save-sample` would overwrite this file with a sample from the bootstrap template, whose sentence reads "in DEMO Barangay Hall". Don't use that flag before the panel.
- **Seeding refused** ("already seeded"): run `--cleanup` first, or use the existing DEMO data as it is.
- **Something goes wrong mid-demo:** `--cleanup`, then restore `C:/laragon/backups/barangay_before_demo.sql` if needed.

## Map to the panel's request

| Panel wording | Shown at | Built in |
|---|---|---|
| dynamic complaint rather than static options | 0–3 min | P1 tables, P2 read path, P3 admin API, P4 screen, P8 reports by type id |
| who is involved (complainant / respondent) | 3–5 min | P5 parties and walk-in filing |
| mediation scheduling | 5–7 min | P6 calendar and case numbers |
| summons | 7–8 min | P7 summons; template v11 wording |
| reports / analysis | 9–10 min | P8 report and AI aggregates |

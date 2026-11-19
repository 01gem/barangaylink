# BarangayLink

A web-based integrated management information system for Barangay
Sampaguita, Tagana-an, Surigao del Norte. Resident self-service plus an
official operations console, with deterministic MPI-based household
assessment and on-demand AI commentary.

Copyright (C) 2026 Jun Gem Riege M. Dulduco.
Licensed under the GNU General Public License v3.0 (or later).
See the LICENSE file in the project root for the full text.

## What it does

- Resident portal: document requests, complaint filing, announcements,
  local services directory, notifications.
- Official console: population management, request processing, complaint
  triage, announcements, services management, audit log.
- Household profiling and Multidimensional Poverty Index (MPI) assessment
  per Philippine Statistics Authority methodology. Deterministic; the
  engine is pure and lives in `api/households/mpi_engine.php`.
- Optional Gemini-generated commentary on each household's MPI result.
  Commentary explains the deterministic score; it does not re-score.
- Optional SMS via httpSMS for pickup notices, complaint resolutions,
  password resets, and announcement broadcasts.
- Document templates are versioned in `document_template_versions`; uploads,
  test renders, activation, rollback, and archival are documented in
  `project-support/docs/archive/document-management-phase2a.md` and
  `project-support/docs/archive/document-management-phase2b.md`. The official
  console's Document Templates tab manages these operations.
- Optional AI placeholder analysis for draft templates (backend only,
  suggest-only): Gemini proposes which text spans should become placeholders,
  the server validates every suggestion, and no template file is changed.
  Document text is sent to Google only after explicit acknowledgement. Existing
  installs need `project-support/migrations/20261113_document_template_analyses.sql`.
  See `project-support/docs/archive/document-management-phase3.md`.
- Confirmed suggestions can be applied into a new draft version (backend only):
  the source version and its file are never modified, spans that cross tabs,
  breaks, fields, hyperlinks, text boxes or other non-text content are skipped,
  and the result is verified before it is stored. No migration needed. See
  `project-support/docs/archive/document-management-phase4a.md`.
- History -> "Review AI suggestions" on a draft opens the review screen (UI only): analyze with explicit consent, pick suggestions and keys, apply into a new draft. See `project-support/docs/archive/document-management-phase4b.md`.
- History -> "Review / edit" (renamed from "Review AI suggestions") also lets officials fix a draft's text without Word, with or without AI: make a placeholder from selected text, change wording, or turn a `{{key}}` back into text; each save creates a new draft. See `project-support/docs/archive/document-management-phase4c.md`.
- Document Templates -> **Add document type** lets any official add a new type (name, code, fee, processing days, requirements, placeholders). It stays hidden from residents until its first template version is activated; types are archived, never deleted. Needs migration `20261115_document_type_create.sql`. See `project-support/docs/archive/document-management-phase5a.md`.
- The resident request form and the staff generation modal are built from the document registry (`api/documents/type_fields.php`), so a newly added type shows its resident fields and can be generated (no migration). Behaviour change: a blank required staff field (Purpose, Date Issued) is now refused in the modal and with 422 by the server instead of silently falling back. See `project-support/docs/archive/document-management-phase5b.md`.
- Generated request documents record the template version that produced them (`document_requests.template_version_id`; documents generated earlier stay NULL). Needs migration `20261122_request_template_version.sql`. Request Details shows the generated PDF for any status, or "File no longer on server" when the file is gone.

## Directory layout

    api/                 JSON endpoints, grouped by resource
      ai/                AI commentary, connectivity test, workforce classifier
      auth/              login, OTP, password reset
      households/        profiling wizard, registry, MPI engine
      ...
    css/                 stylesheets (one per portal)
    database/            schema (database.sql), household seed, engine test
    js/                  front-end controllers (one per portal)
    pages/               PHP-rendered pages (login, resident, official)

## Setup

1. Requires PHP 8.1+, MySQL 5.7+, and the PHP `mysqli`, `curl`, and
   `zip` extensions. Document generation additionally requires LibreOffice
   installed at `C:\Program Files\LibreOffice\program\soffice.exe` on
   Windows. Set the optional `LIBREOFFICE_PATH` environment variable in
   `.env` to override that path.

2. Create an EMPTY database yourself, then import into it, in this order
   (run from the project folder):

       mysql -uroot -e "CREATE DATABASE barangay_system_test_environment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
       mysql -uroot --default-character-set=utf8mb4 barangay_system_test_environment -e "source database/database.sql"
       mysql -uroot --default-character-set=utf8mb4 barangay_system_test_environment -e "source database/seed_households.sql"

   Neither file selects or creates a database: they always write to the
   database named on the command line, so check that name before running.
   `database.sql` builds the complete schema and seeds the default admin
   account, the poverty thresholds, and the document prices; importing it a
   second time into the same database changes nothing. `seed_households.sql`
   then loads the 35-household fixture. It inserts fixed ids, so run it once
   only. Never import `database.sql` into an existing installation to upgrade
   it; apply the scripts in `project-support/migrations/` instead.

3. Copy `.env.example` to `.env` at the project root and fill it in:

       DB_HOST=localhost               # optional; defaults shown
       DB_USER=root
       DB_PASS=
       DB_NAME=barangay_system_test_environment
       GEMINI_API_KEY=...              # required for AI features
       GEMINI_MODEL=gemini-3.5-flash-lite   # optional; this is the default
       HTTPSMS_API_KEY=...             # required for SMS features
       HTTPSMS_FROM_NUMBER=+63...
       HTTPSMS_SIM_SLOT=SIM1
       PASSWORD_RESET_HINT_KEY=...     # random, at least 32 characters; keys the masked
                                       # number hint and the IP hash used by password-reset
                                       # throttling. Missing/short: no hint is shown, the
                                       # throttles still apply.
       COMPLAINT_ATTACHMENT_STORAGE_DIR=C:/laragon/private/Capstone-System-COPY/complaint_attachments
       LIBREOFFICE_PATH=C:/Program Files/LibreOffice/program/soffice.exe  # optional override

   `COMPLAINT_ATTACHMENT_STORAGE_DIR` is required for new complaint photos.
   It must be an absolute directory outside this project and outside the
   web server's document root. The example above sits outside
   `C:/laragon/www`. Create the directory and make it writable by PHP.
   Do not point it at `complaint_attachments/` inside this project. If the
   setting is missing, unreadable, or inside a served tree, new uploads
   fail and are not stored in the public project tree. Older rows shaped
   `complaint_attachments/{complaint_id}/{uuid}.jpg|png` stay where they
   are and are readable only through `api/complaints/view_attachment.php`.

4. Block direct web access to legacy evidence. `complaint_attachments/.htaccess`
   denies all access on Apache 2.4 (`Require all denied`). Confirm a copied
   legacy URL returns 403 or 404, including a soft-deleted file. PHP's
   built-in server ignores `.htaccess`, so that check has to run on the
   deployed server. Nginx needs an equivalent denial, for example
   `location ^~ /complaint_attachments/ { deny all; return 403; }`. IIS needs
   a location rule that denies all users for `complaint_attachments`. The
   private storage directory must also stay outside every static alias.
   A denial rule that the server does not actually enforce leaves the old
   files readable by URL.

5. Serve the project root with any PHP-capable web server. The default
   admin username from the seed is `choogi`. Its password is not in this
   repository; set your own hash in `database/database.sql` before importing
   (see the comment above the admin INSERT), and change it before exposing the app.

## Verifying the MPI engine

The engine has a hand-computed fixture of 35 seeded households. Run:

    php project-support/tests/mpi-engine/test_mpi_engine.php

The test loads households whose ids are 1-35 (the range
database/seed_households.sql owns), reads each linked profile and
threshold, and diffs the computed assessment against the seed's stored
values. Expected output ends with `Results: 35/35 match`. Any mismatch is
an engine bug, not a data bug.

## Known caveats

- AI commentary persists in `household_ai_reports` and loads on page
  open. Regenerating over an existing row overwrites it. Per-row × and
  the header "Clear Commentaries" button remove reports. The batch and
  single endpoints are throttled per official via `ai_throttle_or_die()`
  in `api/ai/_shared.php` (200 households/hour default; workforce
  classifier 400). Raw call log lives in `ai_request_log`; one audit_log
  entry is written per Generate Reports click.
- Generated documents use the admin-editable `captain_name` document setting
  (name only, without a title). Printing adds `Hon. ` before the name and
  `Barangay Captain` on the next line. The registry has 40 fields per fresh
  install after the official-title migration. The Phase 1 registry and rollback notes are in
  [document-management-phase1](project-support/docs/archive/document-management-phase1.md).
- Background list polling uses a 5-second interval, section-scoped, with
  exponential backoff to 2 minutes on failure and paused while a modal is
  open or the tab is hidden. Open complaint dialogs poll their comments every
  5 seconds and pause while the tab is hidden. Background list polling costs
  ~0.2 req/s per active client; an open complaint adds one comments request
  every 5 seconds.
  Past 100 concurrent users, replace polling with Server-Sent Events
  against a `/api/events/stream.php` endpoint.
- A pickup SMS that ends `failed` is retried by the scheduled dispatcher with
  a fixed 5 / 15 / 60 / 180-minute backoff. After four requeues the event is
  left in `failed` for operator reconciliation via
  `php project-support/scripts/dispatch-request-notices.php reconcile EVENT_ID sent|failed "evidence"`
  or by re-marking the request Ready (which creates a fresh readiness cycle).
  See [request-concurrency](project-support/docs/request-concurrency.md).
- Registries read from `enrichedRegistryRows()`, which reconciles
  residents against `HOUSEHOLD_REGISTRY` on every render. A resident
  whose `household_id` changes mid-session may show stale MPI until
  both `loadResidents()` and `loadHouseholds()` re-run.
- No CSRF protection on POST endpoints beyond `SameSite=Lax` cookies.
  See `db.php` for the current session cookie settings. Adding CSRF is
  a cross-cutting change (every POST endpoint plus a middleware check)
  and is not in scope for this deployment pass.
- No migration runner. `database.sql` is the fresh-install schema; additive
  upgrade scripts live in `project-support/migrations/`. If upgrading a database built with the old `migration_*.sql`
  chain, apply those migrations once before deleting them; then
  `database.sql` becomes the fresh-install entry point.
- Existing databases must also apply
  `project-support/migrations/20261025_resident_households.sql`. It assigns
  each orphan resident to a new household before making `residents.household_id`
  mandatory. Back up the database first and stop resident writes while applying
  the migration.
- Document-specific extra fields (e.g. civil_status for Barangay
  Clearance) are captured at request time in
  `document_requests.request_fields_json` and pre-fill the official
  generate-document modal. Fields are whitelisted per document type in
  `api/requests/create.php`; unknown keys in the resident's payload are
  silently dropped. The resident's birthdate remains the source of truth
  for age — the value the resident typed is stored for audit but the
  generator computes it fresh from birthdate when the modal left age
  blank.

## Release notes

### 2026-10-26

- **Announcement in-app notifications are now independent of SMS opt-out.**
  Residents who disabled announcement SMS now still receive in-app
  announcement notifications. The "Announcements" checkbox on the resident
  profile page suppresses only the SMS channel.
- **Pickup SMS retries.** A failed pickup SMS is retried by the scheduler up
  to four times (5 / 15 / 60 / 180 minutes, ~4h20m total from the original
  attempt). After the fourth retry the event stays `failed` for operator
  reconciliation. The panel message now says "will retry up to four times
  with backoff" instead of the earlier incorrect "the scheduler will retry."
- **`database.sql` now includes `request_notification_events.retry_count`.**
  Fresh installs no longer need to apply
  `project-support/migrations/20261026_request_notification_retry.sql` after
  `database.sql`; existing installs still do. See
  `project-support/docs/request-concurrency.md`.

## Document request concurrency rollout

Existing installations must apply the additive request migration before enabling
the updated handlers. See [rollout, dispatch/recovery and test evidence](project-support/docs/request-concurrency.md).
The consolidated schema remains the entry point for fresh installations.
The [local controlled rollout and final acceptance](project-support/docs/request-rollout-acceptance.md)
have passed; the local issue is closed.
Run `python project-support/scripts/update-source-code.py` to refresh the local source text snapshot.
See [packaging and maintenance](project-support/docs/maintenance.md) for runtime-only deployment
ZIPs, scheduled log rotation, protected backup retention and cleanup verification.

## License

GNU General Public License v3.0 or later. See `LICENSE`.

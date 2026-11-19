# Document Management Phase 4C

Officials fix a **draft** template inside the system, without Word:

1. select text in one paragraph and turn it into a registered `{{key}}`;
2. change the wording of a span (plain text; the formatting of the run where
   the span starts is kept, the other runs of the span are dropped);
3. turn an existing `{{key}}` back into plain text.

Every save creates a **new draft version**. The source version, its row and
its file are never modified. The new draft then goes through the usual
Check -> Test render -> Activate. Manual editing needs **no AI and no
analysis**: it works the same when `GEMINI_API_KEY` is not set (where
`template_analyze.php` answers 503).

Files:

| File | Change |
|---|---|
| `api/documents/_manual_edit.php` | new: paragraph listing, plan/validation, key verify, run |
| `api/documents/template_paragraphs.php` | new: `GET` paragraph text for one version |
| `api/documents/template_manual_edit.php` | new: `POST` save manual edits |
| `api/documents/_apply.php` | `document_apply_store_version()` gained an optional `$whileLocked` hook (Phase 4A passes `null`) |
| `api/documents/_registry.php` | test-only storage override `BARANGAY_STORAGE_ROOT` (see [Storage rule for tests](#storage-rule-for-tests)) |
| `js/document-templates.js`, `pages/official.php`, `css/official.css` | "Edit document text" section of the review modal (`doctpl-manual-*`) |
| `project-support/tests/_storage.php`, `tests/storage-override/`, `tests/document-manual-edit/` | test storage helper and suites |

No migration is needed.

## Screen flow

1. Document Templates -> **History** -> **Review / edit** on a draft row
   (the button was called "Review AI suggestions" in 4B). The modal title is
   "Review Template: *Type* v*N*".
2. The AI part (Analyze, Suggestions, Apply selected) is unchanged from 4B.
   The new **Edit document text** section loads
   `GET template_paragraphs.php?version_id=N` on its own, so it works with no
   analysis and with AI off.
3. The paragraph list is a **read-only view**: plain DOM text written with
   `textContent`, no `contenteditable`, so typing, paste and drop cannot
   change it. Only the browser selection is read. Each non-blank paragraph
   shows "¶ N" and its text. Existing placeholders are shown as chips
   (buttons, `user-select: none`). A chip whose placeholder is written with
   spaces (`{{ key }}`), or that sits in a non-editable paragraph, is
   disabled. Paragraphs inside a text box or with a Word field are greyed
   and say "Edit it in Word."
4. **Selecting text.** `window.getSelection()` is read on mouse-up and on
   Shift+key-up inside the review modal. The selection is refused, with a
   message, when it spans two paragraphs ("Select text inside one
   paragraph."), touches a chip ("A selection cannot include a placeholder.
   Click the placeholder to turn it into text."), or is in a non-editable
   paragraph.
5. **Offsets.** The browser gives UTF-16 offsets. The start sent to the
   server is `Array.from(prefix.toString()).length`, where `prefix` is a DOM
   Range from the paragraph start to the selection start. Chips show the
   placeholder exactly as written, so the paragraph's DOM text equals the
   server text. The selected text is re-derived from the server text at that
   code-point position and must equal the selection, otherwise the selection
   is refused ("This selection cannot be used. Select the text again.").
6. **Toolbar for a selection:** "Make placeholder" (dropdown of the type's
   registry fields as `label ({{key}})`) and "Edit text" (input,
   `maxlength=500`). **Clicking a chip** opens "Turn into text" with an input
   for the replacement (may be empty).
7. Each action adds a pending edit (`source: 'manual'`) to
   `state.review.edits`, shown in a list with **Remove** buttons. Pending
   edits that overlap are refused client-side ("This overlaps an edit that
   is already pending. Remove that edit first."). The client repeats the
   server's limit, character and brace checks with the same messages; the
   server stays authoritative.
8. **Save manual edits** posts to `template_manual_edit.php`. AI apply and
   manual save are mutually exclusive: when AI suggestions are selected
   **and** manual edits are pending, both buttons are disabled and both
   sections show "Save or clear one kind of change first."
9. After a save: the same result panel as 4B ("Created draft vN", applied
   count, skipped edits with reasons, template check result, **Open vN in
   History**). The review is locked (a second save would create a duplicate
   draft).
10. Closing the modal (Close, ×, Escape, overlay) with pending manual edits
    asks "Discard N unsaved edit(s)?". Cancel keeps the modal open.
11. On 409/404 the pending edits are cleared ("… Your unsaved edits were
    cleared."), the list is reloaded and the paragraphs are fetched again.
    On 422 the pending edits stay, so the official can remove the bad one;
    a "None of the edits could be applied." answer lists every skip reason.

## Operations

Each edit carries exactly these fields (no more, no fewer):

| `op` | Fields | Meaning |
|---|---|---|
| `placeholder` | `op, paragraph, start, text, key` | replace `text` at `start` with `{{key}}`; `key` must be a registry field of the type |
| `replace_text` | `op, paragraph, start, text, new_text` | replace `text` at `start` with plain `new_text` (may be `""`) |
| `remove_placeholder` | `op, paragraph, start, key, new_text` | replace the exact `{{key}}` at `start` with plain `new_text`; any key format `^[a-z_][a-z0-9_]*$`, registered or not |

`paragraph` is the index in `//w:p` (same indexing as the AI analysis; blank
paragraphs keep their index). `start` and all lengths are **Unicode code
points**. The server re-reads the document; client text is only compared,
never trusted.

## Endpoints

### `GET api/documents/template_paragraphs.php?version_id=N`

Official session only. Read-only. Response:

    {"success": true, "version_id": N, "status": "draft", "sha256": "<64 hex>",
     "paragraphs": [{"index", "text", "editable", "reason"?}],
     "placeholders": [{"paragraph", "start", "key", "text", "removable"}]}

`reason` is `text box` or `field code`. `removable` is true only when the
placeholder text is exactly `{{key}}`.

**sha256 exception.** Elsewhere hashes and server file names are never
returned. This endpoint returns the file's sha256 (official session only) so
a save can prove it edits the file the official saw (`expected_sha256`).

| Status | Message |
|---|---|
| 401 | `Unauthorized` |
| 405 | `Method not allowed.` |
| 422 | `Version is required.` |
| 404 | `Template version is unavailable.` |
| 500 | generic database error message (logged server-side) |

### `POST api/documents/template_manual_edit.php`

Official session, JSON body `{"version_id", "expected_sha256", "edits": [...]}`.
Success: `{"success": true, "new_version_id", "version_no", "applied",
"skipped": [{"index", "reason"}], "check_ok", "check_errors"}`. `index`
is the edit's position in the request. No file name or hash is returned.

## Check order

The endpoint and `document_manual_edit_run()` check in this order; the
first failure answers:

1. Session (401), method (405).
2. **Request shape (422)**: `version_id` a positive int, `expected_sha256`
   64 lowercase hex, `edits` an array. This runs **before** any database
   lookup, so a malformed request never reveals whether a version exists.
3. Version exists (404), version is a draft (409).
4. Hash: the stored file's sha256 must equal `expected_sha256` (409).
5. Read the XML, then plan (all 422, in this order): `edits` is a list;
   not empty; at most 40; then per edit: object with a known `op` and
   exactly its fields, `paragraph`/`start` non-negative ints,
   `text`/`key`/`new_text` valid UTF-8 strings, remove key format; paragraph
   exists and is editable; per op: remove at an exact `{{key}}` (a spaced
   one -> "Only a whole placeholder…", otherwise mismatch), placeholder span
   1–200 then registry key, replace span 1–500 then `new_text` checks then
   "same text"; span text matches; does not touch a placeholder; finally
   no two edits overlap.
6. Apply. If no edit could be applied: 422 with `skipped`.
7. Verify (paragraph texts and placeholder-key multiset), then store:
   lock `document_types` row -> lock source version row `FOR UPDATE` ->
   draft re-check (409) -> **in-lock hash re-check (409)** -> allocate
   `MAX(version_no)+1` -> exclusive create of the new file -> insert ->
   commit. Then audit.

**Hash-then-read relies on immutable version files.** Step 4 hashes the
file and step 5 reads it in a separate call. That is safe because a stored
version file is never rewritten: every save writes a **new** file with an
exclusive create, and the source file is only read. The in-lock re-check in
step 7 covers the save itself: if the file changed after the official
loaded it (or between step 4 and the lock), the save is refused with 409 and
nothing is written. A real two-process race test proves this check is
needed: with the check removed, the waiting process saved a draft.

## Errors (exact messages)

| Status | Message | When |
|---|---|---|
| 401 | `Unauthorized` | no official session |
| 405 | `Method not allowed.` | not POST |
| 422 | `A version, the file hash and a list of edits are required.` | bad shape (step 2), or `expected_sha256` not 64 lowercase hex |
| 404 | `Template version is unavailable.` | unknown version |
| 409 | `Manual edits can only be saved to a draft version.` | active, retired or discarded (before and under the lock) |
| 409 | `The template changed. Reload and try again.` | hash differs (early and in-lock) |
| 422 | `Add at least one edit.` | empty list |
| 422 | `Save at most 40 edits at a time.` | more than 40 |
| 422 | `An edit is not valid.` | unknown/missing `op`, missing or extra fields, non-int or negative `paragraph`/`start`, `text`/`key`/`new_text` not a string (`null`, number, array) or invalid UTF-8, bad remove key |
| 422 | `An edit refers to a paragraph that cannot be edited.` | unknown, blank, text box, field code |
| 422 | `Only a whole placeholder such as {{key}} can be turned into text.` | remove on a spaced `{{ key }}` |
| 422 | `An edit no longer matches the document text. Reload and try again.` | span text differs, or no placeholder with that key at `start` |
| 422 | `Selected text must be 1 to 200 characters.` | `placeholder` span |
| 422 | `A selected placeholder is not registered for this document type.` | `placeholder` key not in registry |
| 422 | `Selected text must be 1 to 500 characters.` | `replace_text` span |
| 422 | `New text must be at most 500 characters.` | |
| 422 | `New text cannot contain tabs, line breaks or control characters.` | C0/C1 controls, U+2028/2029, bidi controls U+202A–202E and U+2066–2069 |
| 422 | `New text cannot contain { or }.` | any single brace |
| 422 | `The new text is the same as the old text.` | `replace_text` only |
| 422 | `An edit cannot change part of an existing placeholder.` | `placeholder`/`replace_text` span shares at least one character with a `{{…}}` or a stray `{{`/`}}` |
| 422 | `Edits must not overlap.` | same paragraph, intersecting spans |
| 422 | `None of the edits could be applied.` + `skipped` | every edit hit a structural skip |
| 500 | `Saving the manual edits failed.` | unexpected error (logged server-side) |

**"Touching" a placeholder** means sharing at least one character with it
(starting or ending inside it, containing it, or equal to it). An edit
directly next to a placeholder is allowed: `new_text` cannot contain braces,
so nothing can merge into a placeholder.

Structural skips (partial save) use the 4A reasons: `text box`,
`crosses a tab or break`, `crosses a field code`,
`crosses a hyperlink boundary`, `crosses a content boundary`,
`crosses non-text content`, `text mismatch`.

## Limits

| Limit | Value |
|---|---|
| Edits per save | 1–40 |
| `placeholder` span | 1–200 code points |
| `replace_text` span | 1–500 code points |
| `new_text` | 0–500 code points, no controls, no `{` or `}` |
| Placeholder keys (`placeholder` op) | the type's registry fields |

## Audit

One entry per save: action `Edited template text manually`, target
`document_template_version` = new id, details
`placeholder=a; replace_text=b; remove_placeholder=c; skipped=n; source_version=X; new_version=Y`.
No document text, file name or hash. The new row's notes are
`Manual edits from vN: X edit(s) applied` and its original name is
`manual-edit-from-vN.docx`.

## Storage rule for tests

Tests and UI checks never create, change or delete files in the real
`document_templates/` or `generated_documents/`.

- `BARANGAY_STORAGE_ROOT` is a **test-only** override read by
  `document_storage_dir()` in `api/documents/_registry.php`. It is honored
  only when its real path is a **direct child of `sys_get_temp_dir()`**
  named `capstone_tpl_<digits>`. Anything else (outside temp, nested, `..`,
  other names, a symlink or junction pointing elsewhere) is ignored and the
  normal project folders are used. Production never sets it.
- `project-support/tests/_storage.php` (`TestStorage`) creates
  `%TEMP%/capstone_tpl_<pid>/{document_templates, generated_documents/previews}`,
  copies the five seeded templates in (the real files are only read), sets
  the override, and `assertActive()` exits with code 2 unless the app really
  resolves to the temp folder. Every suite that touches template storage
  constructs `new TestStorage` before doing anything else.
- Cleanup deletes only **tracked exact paths**, then the empty folders; an
  untracked leftover is reported (`NOTE: untracked test file left…`), not
  deleted.
- Scratch databases are built from `database/database.sql` (`capstone_*`
  prefix), never copied from the real database with real storage paths.
- Test servers (`php -S`) get only `BARANGAY_DB_NAME` (scratch) and
  `BARANGAY_STORAGE_ROOT` (temp) plus the basics PHP needs on Windows
  (`SystemRoot`, `PATH`, `TEMP`, `TMP`); AI and SMS keys are blanked.
- Known gap: `api/requests/generate_document.php` writes generated request
  documents itself and does not use the override.

## Tests

    php project-support/tests/document-manual-edit/run.php
    php project-support/tests/storage-override/run.php

`document-manual-edit` (162 checks) covers every message above; the three
op kinds on multi-run bold/italic spans; ñ, `& < >` and code-point offsets;
`xml:space`; emptied paragraphs; a remove -> placeholder round trip on the
seeded clearance; verify tampering; a full save on a scratch database
(new row, source unchanged, one new file, audit without text); partial and
none-applied saves; refusals that write nothing; an `afterWrite` failure and
a fatal child (orphan cleanup); the real two-process lock race for the
in-lock hash check; and the endpoints on a test server.
`storage-override` (14 checks) covers the override guard.

## Manual checklist

Use a test server (scratch DB + temp storage, as above), never real storage.

- [ ] History -> **Review / edit** on a draft opens "Review Template: Type vN"; active rows have no button.
- [ ] With AI off: the AI note says manual editing is still possible; Analyze is disabled; the paragraph list loads.
- [ ] Double-click a word after an emoji (e.g. `Republic 😀 of the 🇵🇭 Philippines`) -> "Selected in paragraph 1: …"; Edit text -> save -> the new draft has the new word in the right place.
- [ ] Drag across two paragraphs -> "Select text inside one paragraph."
- [ ] Drag across a placeholder chip -> "A selection cannot include a placeholder…"
- [ ] Click a chip -> "Turn into text" -> pending edit; clicking it again and adding -> overlap refused.
- [ ] New text with `{` -> "New text cannot contain { or }."
- [ ] Make placeholder with a registry key -> pending; Remove deletes it from the list.
- [ ] Tick an AI suggestion while manual edits are pending -> both buttons disabled, "Save or clear one kind of change first."
- [ ] Close/Escape with pending edits -> "Discard N unsaved edit(s)?"; Cancel keeps the modal.
- [ ] Save -> "Created draft vN", check result, review locked, **Open vN in History** opens History on the new row without a discard prompt.
- [ ] New draft: Test render, then Activate as usual. The source version is unchanged.

# Document Management Phase 4A

Apply confirmed AI placeholder suggestions into a **new draft version**.
**Backend only**; Phase 4B adds the review screen.

The source version and its file are never modified. Applying always creates
a new draft version with its own server-generated file. Rendering, upload,
activation, analysis and the registry are unchanged. No migration is needed.

## Flow

1. Staff or the captain analyzes a draft version (Phase 3) and reviews the
   suggestions.
2. `POST api/documents/template_apply_suggestions.php` with JSON

   ```json
   {"version_id": 12, "analysis_id": 34, "selections": [{"index": 0}, {"index": 3, "key": "full_name"}]}
   ```

   `index` points into the stored `suggestions` array of that analysis. `key`
   is optional and replaces the suggested key; it must be a registry field
   key of the document type. The client never sends span text or offsets:
   paragraph, start and text come from the stored analysis.
3. The server checks, in this order:

   | Check | Response |
   | --- | --- |
   | No official session | 401 |
   | Not POST | 405 |
   | `version_id` / `analysis_id` not positive integers, or `selections` not an array | 422 |
   | Unknown version, or analysis missing / of another version | 404 |
   | Version is not a draft | 409 |
   | Analysis is not `completed`, or stale (file hash changed) | 409 `The analysis is out of date. Analyze the document again.` |
   | Empty selection, more than 40, duplicate or out-of-range index, extra properties, key not in the registry | 422 |
   | No selected suggestion could be applied | 422 `None of the selected suggestions could be applied.` (no version created) |

4. On success the response is
   `{new_version_id, version_no, applied, skipped: [{index, reason}], check_ok, check_errors}`.
   File names and hashes are never returned. Staff and admin are both allowed.

The new row: `status='draft'`, `original_name='ai-assisted-from-v<n>.docx'`,
`notes='AI-assisted from v<n>: <applied> placeholder(s) applied'` (capped at
255), `sha256` of the new file, `check_ok`/`check_report` from
`document_template_check()` with the type's allowed and required keys,
`test_ok=0`. Activation still requires a test render of that exact file, as
in Phase 2.

## Code (`api/documents/_apply.php`)

| Function | Kind | Role |
| --- | --- | --- |
| `document_apply_plan()` | pure | selections + stored suggestions + registry keys -> edits |
| `document_apply_xml()` | pure | edits applied to `word/document.xml`, returns `{xml, applied, skipped}` |
| `document_apply_verify()` | pure | re-extracts paragraph texts and compares with the original |
| `document_apply_verify_archive()` | pure | compares the source and new archives entry by entry |
| `document_apply_build_docx()` | file | copies the source archive entry by entry, replacing only `word/document.xml` |
| `document_apply_run()` | DB + file | the whole operation; the endpoint calls it with five arguments |

`document_apply_run()` has a sixth parameter, `$afterWrite`, which is a
**test-only seam** (called after the file is written and the row inserted,
before commit). The endpoint never passes it; a test asserts that by reading
the endpoint source.

## How a span is applied

Paragraphs use the same indexing as `document_template_paragraph_text()`:
the nth `//w:p` in document order, text = all `.//w:t` joined. Offsets are
Unicode characters.

1. The span text at `[start, start+len)` must equal the stored suggestion
   text exactly, otherwise the edit is skipped (`text mismatch`).
2. The `w:t` nodes that share at least one character with the span are the
   touched nodes.
3. `{{key}}` goes into the first touched `w:t`, so it keeps that run's
   `w:rPr` (a span that starts in a bold run gives a bold placeholder). The
   span's other characters are removed from the following `w:t` nodes. A
   `w:t` left empty is removed; its `w:r` is removed only when it held
   nothing but run properties and that `w:t`.
4. Any changed `w:t` whose text now starts or ends with whitespace gets
   `xml:space="preserve"`. Text is written through the DOM, so `& < >`
   stay escaped.
5. Edits in one paragraph run from the last position to the first, so
   earlier offsets stay valid.

### What is skipped and why

An edit is skipped (never partly applied) and reported with its reason:

| Reason | When |
| --- | --- |
| `text mismatch` | stored text is not at that offset, or the paragraph does not exist |
| `overlaps another selection` | two selected spans overlap (defensive; analysis already removes overlaps) |
| `text box` | the paragraph contains or sits inside `w:txbxContent` (such paragraphs are also counted twice through `mc:AlternateContent`) |
| `crosses a tab or break` | a `w:tab`, `w:br` or `w:cr` lies inside the span; they are not part of the paragraph text, so the span looks contiguous but is not |
| `crosses a field code` | a touched `w:t` is inside `w:fldSimple` or a complex field (`w:fldChar` begin..end), or a `w:fldChar` / `w:instrText` lies inside the span |
| `crosses a hyperlink boundary` | the touched runs are not all inside the same `w:hyperlink` (a span fully inside one hyperlink is applied) |
| `crosses a content boundary` | the touched runs have different parents for another reason (`w:ins`, `w:smartTag`, `w:sdtContent`, ...) |
| `crosses non-text content` | `w:sym`, `w:drawing`, `w:noBreakHyphen` or any other non-text run content lies inside the span |

## Verify step

Before anything is written:

- The new XML must parse (no DOCTYPE, no network) and have the same `w:p`
  count.
- Every edited paragraph must equal the original text with exactly its
  applied spans replaced by `{{key}}`; every other paragraph must be
  identical.
- The new archive must have the same entry names in the same order, every
  entry other than `word/document.xml` must have identical uncompressed
  bytes, and `word/document.xml` must equal the verified XML.
- The new file must pass `document_template_validate_upload()`, the same
  checks a user upload gets (size, unsafe paths, macros, external
  relationships, zip-bomb ratio, required parts).

Any difference throws and nothing is stored.

## Storage and concurrency

The new DOCX is built and verified in a temp workspace. Then, in one
transaction: `SELECT ... FOR UPDATE` on the `document_types` row, re-lock the
source version and confirm it is still a draft, allocate
`MAX(version_no)+1`, create `tpl_<typeid>_v<n>_<hex>.docx` with exclusive
create (`fopen 'xb'`), insert the row, commit. Two concurrent applies on the
same type are serialized by the type-row lock and get distinct version
numbers and files; the UNIQUE indexes are a backstop.

If anything fails after the file is written and before commit, the file is
deleted (finally block). A fatal error skips finally, so a shutdown function
registered by `document_apply_run()` also deletes it; it is disarmed after a
successful commit. The uncommitted row is rolled back when the connection
closes.

Two applies of the same analysis create two drafts (no idempotency key); the
extra draft can be discarded.

The audit entry `Applied AI placeholder suggestions` holds counts only:
`applied=..; skipped=..; source_version=..; new_version=..`. Never document
text, file names or hashes.

## Round-trip evidence

`project-support/tests/document-apply/run.php` takes each of the five seeded
templates, fills every placeholder with a sample value exactly like
`analysis-accuracy.php`, derives the expected suggestions from the known
placeholders, and applies them. For all five, the result:

- reproduces the seeded template's paragraph texts exactly,
- opens with ZipArchive, `word/document.xml` parses, `w:p` count unchanged,
- passes `document_template_validate_upload()`,
- has no `document_template_check()` errors,

and the seeded files stay byte-for-byte unchanged. One round-trip result
(Barangay Clearance) is also rendered with LibreOffice to a valid PDF.

The seeded templates keep every placeholder in its own run and contain no
tabs, breaks, fields, hyperlinks or text boxes, so the round trip only
exercises in-run replacement. The crossing rules are covered by synthetic
fixtures in the same suite.

Manual check against the real model (makes real AI calls; not part of any
suite):

    php project-support/scripts/analysis-accuracy.php --confirm-external --apply

**Note on accuracy:** the Phase 3 result of 40/40 recall and precision was
measured on the same five templates the analysis prompt was tuned on. It
shows the pipeline works end to end; it is not evidence of accuracy on unseen
templates.

## Tests

    php project-support/tests/document-apply/run.php
    php project-support/tests/document-templates/endpoints.php

Neither makes an AI call. Database work uses scratch databases
(`capstone_apply_*`, `capstone_templates_*`); real row counts are checked
unchanged.

Storage rule: template files are written only to temp storage
(`TestStorage`, `BARANGAY_STORAGE_ROOT` -> `%TEMP%/capstone_tpl_<pid>`), never
to the real `document_templates/` or `generated_documents/`, and cleanup
deletes only tracked exact paths. See `document-management-phase4c.md` ->
"Storage rule for tests".

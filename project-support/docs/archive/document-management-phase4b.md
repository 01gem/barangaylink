# Document Management Phase 4B

Review screen for AI placeholder suggestions. **UI only.** The backend from
Phases 3 and 4A is unchanged: no PHP endpoint, database, migration or template
file was modified.

Files: `js/document-templates.js` (review module), `pages/official.php`
(`#doctplReviewModal` skeleton), `css/official.css` (`doctpl-review-*`).

## Screen flow

1. Document Templates tab -> **History** for a type.
2. A **draft** row shows **Review AI suggestions**. Active, retired and
   discarded rows do not.
3. The review modal opens on top of History and immediately calls
   `GET template_analysis_get.php?version_id=N`.
4. **Analyze section.** The acknowledgement checkbox ("I understand the
   document text will be sent to Google Gemini for analysis.") is unchecked
   on every open, never defaulted or remembered, and cleared again after
   every analyze request. **Analyze with AI** is enabled only while it is
   checked. When `GEMINI_API_KEY` is not configured (`featureEnabled('ai')`
   is false) the checkbox and button are disabled and a note says an
   existing analysis can still be reviewed and applied.
5. **Suggestion list.** One row per stored suggestion, in document order
   (paragraph, then start; never by confidence). Each row: a checkbox
   labelled with the span text (unchecked by default), "Paragraph N · AI
   confidence X%", the AI reason, and a placeholder dropdown. Dropdown
   options are only the type's registry fields from `types_list.php`, shown
   as `label ({{field_key}})`, with the AI's key preselected. If the AI's key
   is no longer in the registry the row shows "— choose a placeholder —" and
   its checkbox is disabled until a key is picked. Changing the key never
   changes the checkbox. **Select all**, **Clear all** and "N of M selected".
6. **Apply selected** sends
   `{"version_id", "analysis_id", "selections": [{"index", "key"}]}` with
   `key` always present (the AI key or the changed key). `index` is the
   suggestion's position in the stored `suggestions` array. No span text or
   offsets are sent.
7. **Result panel.** "Created draft vN" (focus moves to this heading), the
   applied count, every skipped suggestion with a plain-words reason,
   the template check result with `check_errors`, and **Open vN in History**.
   That button closes the review, refreshes the list, reopens History with
   the new row highlighted and scrolled into view. Nothing is activated or
   test-rendered automatically.
8. After a successful Apply the review is **locked**: Apply, the checkboxes,
   the key dropdowns, Select all / Clear all, the acknowledgement and Analyze
   are disabled, and the result panel stays visible. Re-applying the same
   analysis would create duplicate drafts. Reopening the review on the new
   draft starts clean.

Double submits are impossible: `state.review.busy` is set before the request
starts and the buttons are disabled while it runs, so a double-click sends
one request. A response that arrives after the modal was closed or reopened
is ignored (`state.reviewSeq`).

## States

| State | What the screen shows |
| --- | --- |
| Loading | Analyze and Apply disabled while `template_analysis_get` runs. |
| No analysis (404 `No analysis exists for this version.`) | Analyze section only. |
| Completed analysis | Suggestion list, all unchecked. |
| Completed, no suggestions | "The analysis found no placeholder suggestions for this document." |
| Stale (`stale: true`) | Yellow banner "Template changed since this analysis. Re-run analysis." Apply disabled; Analyze stays usable (re-running is the fix). |
| Last attempt failed, previous result kept (`last_attempt_failed: true`) | Banner "Last analysis attempt failed; showing previous result." |
| Last attempt failed, no result (latest row is `failed`) | Banner "Last analysis attempt failed; no result." Suggestion list hidden. |
| Analyze running | Button "Analyzing…", acknowledgement and Analyze disabled, status text in an `aria-live` region. |
| Analysis busy (409) | Server message shown, Analyze disabled for 5 seconds, then `template_analysis_get` is fetched **once**. No polling loop. |
| Apply running | Button "Applying…", every checkbox, dropdown and select button disabled. |
| Result (locked) | Result panel; the review is read-only. |
| Load error | Red banner. |

`aria-live="polite"` is set on the analyze status, the apply status and the
result panel.

## Error -> message tables

These are every status and message the three endpoints can return (from the
PHP source), and what the screen shows.

### `POST api/documents/template_analyze.php`

| Status | Server message | Screen shows |
| --- | --- | --- |
| 500 | `Something went wrong. Please try again.` (session check failed) | The analysis could not be completed. Try again. |
| 401 | `Unauthorized` | Your session has expired. Sign in again. |
| 405 | `Method not allowed.` | The analysis could not be completed. Try again. (the screen always POSTs) |
| 422 | `Confirm that the document text may be sent to the AI provider.` | Server message |
| 404 | `Template version is unavailable.` | This template version no longer exists. Close this window and reload the list. |
| 409 | `Only draft versions can be analyzed.` | Server message |
| 503 | `AI analysis is not configured. Mark placeholders manually in Word.` | AI analysis is not configured. Mark placeholders manually in Word. |
| 422 | `This document is too large to analyze.` | Server message |
| 422 | `This document has no text to analyze.` | Server message |
| 429 | `AI analysis limit reached for this hour. Try again later.` | Server message, as-is |
| 409 | `Analysis is already running for this version.` | Server message; Analyze disabled 5 s, then one re-fetch |
| 502 | `The AI analysis failed. Try again or mark placeholders manually.` | Server message; analysis re-fetched so the "last attempt failed" banner appears |
| 500 | `Template analysis failed.` | The analysis could not be completed. Try again. |

### `GET api/documents/template_analysis_get.php`

| Status | Server message | Screen shows |
| --- | --- | --- |
| 500 | `Something went wrong. Please try again.` (session check failed) | Banner: The analysis could not be loaded. Close this window and try again. |
| 401 | `Unauthorized` | Banner: The analysis could not be loaded. Close this window and try again. |
| 405 | `Method not allowed.` | Same banner (the screen always GETs) |
| 422 | `Version is required.` | Same banner (the screen always sends the id) |
| 404 | `Template version is unavailable.` | Banner: This template version no longer exists. Close this window and reload the list. |
| 404 | `No analysis exists for this version.` | No banner; Analyze section only |
| 500 | `Something went wrong. Please try again.` | Banner: The analysis could not be loaded. Close this window and try again. |

### `POST api/documents/template_apply_suggestions.php`

| Status | Server message | Screen shows |
| --- | --- | --- |
| 500 | `Something went wrong. Please try again.` (session check failed) | The suggestions could not be applied. Try again. |
| 401 | `Unauthorized` | Your session has expired. Sign in again. |
| 405 | `Method not allowed.` | The suggestions could not be applied. Try again. (the screen always POSTs) |
| 422 | `A version, an analysis and a list of selections are required.` | Server message |
| 404 | `Template version is unavailable.` | Server message + "The analysis was reloaded." (list and analysis reloaded) |
| 404 | `The analysis is unavailable.` | Server message + "The analysis was reloaded." |
| 409 | `Suggestions can only be applied to a draft version.` | Server message + "The analysis was reloaded." |
| 409 | `The analysis is out of date. Analyze the document again.` | Server message + "The analysis was reloaded." (stale banner appears) |
| 422 | `Select at least one suggestion.` | Server message |
| 422 | `Select at most 40 suggestions.` | Server message |
| 422 | `Invalid suggestion selection.` | Server message |
| 422 | `A selected suggestion does not exist.` | Server message |
| 422 | `A suggestion was selected more than once.` | Server message |
| 422 | `A selected placeholder is not registered for this document type.` | Server message |
| 422 | `None of the selected suggestions could be applied.` | Server message (no version was created) |
| 500 | `Applying the suggestions failed.` | The suggestions could not be applied. Try again. |

All three endpoints call `get_db()`, which ends with the plain text
`Database connection failed.` (HTTP 200, not JSON) when MySQL is down;
`fetchJson` turns that into `Server returned an invalid response format.`
(no status), and the screen shows its generic message for that endpoint.

Every server string is written with `textContent`; none goes through
`innerHTML`.

## Skip reason -> label

Every reason `api/documents/_apply.php` can return:

| Reason code | Label |
| --- | --- |
| `text mismatch` | The text no longer matches the document at that position. |
| `overlaps another selection` | Overlaps another selected suggestion. |
| `text box` | Inside a text box, which is not supported. Edit it in Word. |
| `crosses a tab or break` | Spans a tab or line break. |
| `crosses a field code` | Spans a Word field (for example a date or page number). |
| `crosses a hyperlink boundary` | Spans the edge of a hyperlink. |
| `crosses a content boundary` | Spans tracked changes or a content control. |
| `crosses non-text content` | Spans a symbol, picture or special hyphen. |

An unknown code is shown as-is.

## Not in 4B: paragraph context

Rows show the span text and its paragraph number but no surrounding text:
neither `template_analysis_get.php` nor `template_analyze.php` returns
paragraph text, and 4B does not change the backend. **Phase 4C adds a
read-only paragraph-text endpoint**, which manual select-to-placeholder needs
anyway; the context column can use it then.

## How Phase 4C plugs in

`state.review.edits` is the single list of pending edits. Each AI item is

    {source: 'ai', index, key, suggestedKey, text, paragraph, start, reason, confidence, selected}

Phase 4C adds items with `source: 'manual'` (select text -> pick key, edit
paragraph text, remove placeholder) to the same list. Counting, selection,
locking and the result panel already work over the whole list.
`aiSelections()` sends only `source: 'ai'` items to
`template_apply_suggestions.php`; 4C adds the manual items' request next to
it. The row renderer is the only place that needs a `source` branch.

## Verification

UI only; no automated browser test exists for this tab. `node --check
js/document-templates.js` passes and every existing suite stays green (see
the 4B report). The manual checklist:

1. The acknowledgement is unchecked every time the review opens.
2. All suggestions are unchecked by default.
3. Apply is disabled at 0 selected, while running, and when stale.
4. The key dropdown lists only the type's registry keys.
5. Skipped reasons are readable sentences.
6. The new draft appears highlighted in History and is not activated.
7. Double-clicking Analyze or Apply sends one request (Network tab).

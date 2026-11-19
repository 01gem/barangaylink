# Complaint revision — Phase 4: Complaint Types screen, display by type id

Written 2026-11-16. Not committed.

## Purpose

1. A "Complaint Types" tab in the official console, for every official (staff and admin), on top of the Phase 3 API.
2. Every complaint display and filter now follows `complaint_type_id` instead of the stored `category` text. A renamed type shows its new name on its old complaints, and filters keep finding them. This closes the "Known gap" in the Phase 3 document for the resident list, the official list and the community board. Statistics and AI still group by `category` (Phase 8).
3. Tests: the real-database mode of `complaint-types/run.php` holds whatever staff do in the new screen.

No complaint type data was changed on the real database.

## Files

| File | Change |
|------|--------|
| `api/complaints/list.php` | Adds `type_name`, the current name of the complaint's type (fallback: `category`). `category` and `complaint_type_id` are unchanged. |
| `api/complaints/_community.php` | `complaint_community_projection()` returns `type_id` and `type_name` (current name, by id, inactive types included) instead of `category`. Missing or unknown type: `type_id` null, `type_name` "Other". The name-to-"Other" mapping is gone. |
| `api/complaints/public_list.php` | `type_id` filter replaces `category`. `category` in the query is refused. The "Other" `NOT IN` branch is gone. |
| `api/complaints/_extra_fields.php` | New `complaint_type_filter_options()`: filter choices for both pages. |
| `pages/community_complaints.php` | Type `<select id="communityCategory">` rendered on the server through `e()`. |
| `js/community-complaints.js` | `COMMUNITY_CATEGORIES` and `communityCategoryLabel` removed; sends `type_id`; card shows `type_name` (through `textContent`, as before). |
| `pages/official.php` | Sidebar button, `tab-complainttypes`, type modal, field modal, `compTypeFilter`, script tag. |
| `js/official.js` | Complaint mapping shows `type_name`; client-side type filter; tab plaque; `switchTab` calls `window.ComplaintTypes.load()`. |
| `js/resident.js` | `cat: row.type_name \|\| row.category`. |
| `js/complaint-types.js` | New. The tab and both modals. |
| `css/official.css` | One commented block at the end (options editor, field list, inline errors). |
| `project-support/tests/complaint-types/run.php` | Phase 4 invariants (both modes); seed check (e) rewritten for the new projection. |
| `project-support/tests/complaint-types/frontend.js` | New. Node checks for `js/complaint-types.js`. |
| `project-support/tests/complaint-interface/api-checks.php` | 9 HTTP checks added. |
| `project-support/tests/review-2026-10-24/run.php` | Per-group summary lines; the community-card key check now expects `type_id` and `type_name`. |
| `project-support/docs/complaint-revision-phase4.md` | This file. |

Not changed: `api/complaints/create.php`, the stored `category` column, `api/complaint_types/*`, stats and AI, requests and documents code, parties and hearings.

### Differences from the Phase 4 brief

1. **`category` is no longer in the community card.** The projection had six keys; it now has seven: `id, type_id, type_name, severity, status, filed_on, published_report_count`. The stored text is not sent to residents. Approved before writing. The older review-runner check "community card has the six public keys" was changed to expect the seven keys.
2. **One shared helper.** `complaint_type_filter_options()` was added to `api/complaints/_extra_fields.php` so the community page and the official page build the same list. The brief did not list this file.
3. **Escape helper.** `escapeHtml`, `fetchJson`, `showToastAdmin` and `API_BASE` are top-level declarations in `js/official.js`, a classic script, so they are already global. `js/complaint-types.js` uses them directly. Nothing had to be exposed and no second helper was added.
4. **Modal class.** The new modals use `modal-overlay ctype-modal` and `data-ct-action`, not `doctpl-modal` and `data-action`: the document templates click handler takes every `data-action` button inside a `.doctpl-modal`.
5. **Which 409 shows the "changed by someone else" toast.** Only the stale-version 409 (`This item was changed by someone else. Reload and try again.`). A 409 for a duplicate name, code or key is a mistake in the input, so it is shown inline like a 422 and the input is kept.
6. **Test modes already existed.** The live/seed split of `complaint-types/run.php` and the `--seed` call in the review runner were done after Phase 3. This phase adds the live invariants the brief listed that were missing, and replaces the one seed check that tested the old "unknown name becomes Other" rule.
7. **Pages without `e()`.** `pages/community_complaints.php` did not define `e()`; it now defines the same function as `pages/official.php`. Both pages catch a failure to read the types and show "All types" only.
8. **MySQL was not running** when the tests started. Laragon's `mysqld` was started from `C:/laragon/bin/mysql/mysql-8.4.3-winx64` with its own `my.ini` and left running.

## `public_list.php`

| Parameter | Rule |
|-----------|------|
| `limit`, `before_id`, `status` | Unchanged. |
| `type_id` | Missing or `all`: no filter. Otherwise `/^[1-9][0-9]{0,9}$/D` and an id in `complaint_types_all(true)` (inactive types included). Anything else, including empty, `01`, `-1` and `1\n`: 422 `Invalid type.` |
| `category` | Present at all: 422 `Invalid filter.` |

Filter: `c.complaint_type_id = ?`.

```json
{"success": true,
 "complaints": [{"id": 12, "type_id": 6, "type_name": "Stray Animals", "severity": "low",
                 "status": "open", "filed_on": "2026-11-16", "published_report_count": 1}],
 "has_more": false, "next_before_id": null}
```

## Complaint Types tab

Visible to all officials. The server enforces every rule; the screen disables what it would refuse and explains why in a tooltip.

```
Complaint Types tab
┌───────────────────────────────────────────────────────────── [+ New type] ┐
│ Name           Code                Respondent  Active fields  Complaints  Status    Actions             │
│ Road / Infra…  road_infrastructure None        2              1           Active    [Edit][Deactivate]  │
│ Other          other               Optional    1              1           Active    [Edit][Deactivate⊘] │  ⊘ disabled + tooltip
│ Stray Animals  stray_animals       Optional    4              0           Inactive  [Edit][Activate]    │
└───────────────────────────────────────────────────────────────────────────┘

Type modal ("New complaint type" / "Edit: <name>")
  Name          [______________]
  Code          [______________]  create only, optional ("made from the name")
                edit: read-only text
  Description   [______________]
  Respondent    (•) None      The form does not ask who the complaint is against.
                ( ) Optional  The resident may name a respondent.
                ( ) Required  The resident must name a respondent.
  Sort order    [ 60 ]
  <inline error>                                   [Cancel] [Save]
  ── Extra fields (edit mode only) ─ "N of at most 15 fields active." ─ [+ Add field]
  Label         Key      Type         Required  Used in        Status    Actions
  Animal        animal   Choice list  No        2 complaints   Active    [Edit][Deactivate]
  Please spec…  specify  Text         Yes       1 complaint    Active    [Edit][Deactivate⊘]

Field modal ("Add field to <type>" / "Edit field: <label>")
  Label       [______________]
  Key         [________]  create only (suggested from the label) | read-only text
  Field type  [Choice list ▾]  create only | read-only text
  [x] Required            (Other.specify: disabled + tooltip)
  text:    Max length [255]
  number:  Minimum [__]  Maximum [__]
  date:    "A date field has no settings."
  select:  Options
     Value       Label        Active  Order    
     dog         [Dog____]    [x]     [↑][↓]   used in 1     saved: value read-only, no remove
     [cat____]   [Cat____]    [x]     [↑][↓]   [Remove]      new: value suggested from the label
     [+ Add option]
  <inline error>                                   [Cancel] [Save]
```

Behaviour (`js/complaint-types.js`):

- The list (`api/complaint_types/list.php`) loads the first time the tab is opened, and again after every successful write.
- Every update sends the `version` from the last load. On a stale-version 409 the toast "Changed by someone else — reloaded." appears, the list reloads, and an open modal is refilled with the fresh data.
- On 422 (or a duplicate 409) the server message appears inline in the modal; the input is kept.
- Activate and Deactivate ask for confirmation. For a type: "Existing complaints keep this type. New complaints cannot choose it."
- Disabled with a tooltip (server still enforces): Deactivate on type `other`; Deactivate and Required on `Other.specify`.
- After creating a type the modal stays open in edit mode, so fields can be added straight away.
- Every server value written as HTML goes through `escapeHtml` from `js/official.js`. Toast title and body are escaped too (`showToastAdmin` writes HTML).
- No inline handlers. One delegated click listener (only for `[data-ct-action]` inside the tab or a `.ctype-modal`), one keydown listener (Escape, focus trap), and form listeners.
- `initComplaintTypesSafely` is a top-level function registered on `DOMContentLoaded`; it wraps the init in try/catch. `official.js` calls the tab only through `window.ComplaintTypes` when it exists.
- The official console's 5-second poll already pauses while any `.modal-overlay.show` is open, so it does not redraw under an open modal.

## Tests

### Results (2026-11-16)

| Suite | Before Phase 4 | After Phase 4 | Exit |
|-------|---------------:|--------------:|-----:|
| complaint-types, live mode, real database | 66 / 66 | 73 / 73 (66 + 7) | 0 |
| complaint-types, seed mode (inside the review runner) | 111 / 111 | 118 / 118 (111 + 7) | 0 |
| complaint-types `frontend.js` (Node, new) | — | 26 / 26 | 0 |
| review-2026-10-24 (whole runner) | 266 / 266 | 282 / 282 | 0 |
| — `interface:` checks | 67 / 67 | 76 / 76 (67 + 9) | — |
| — `types-seed:` checks | 112 / 112 | 119 / 119 (118 + 1 "run completed") | — |
| — all other runner checks | 87 / 87 | 87 / 87 | — |
| document-baseline | 6 / 6 | 6 / 6 | 0 |
| document-forms | 126 / 126 | 126 / 126 | 0 |
| document-registry | 27 / 27 | 27 / 27 | 0 |
| document-templates | 11 / 11 | 11 / 11 | 0 |

The first review run had one failure: "community card has the six public keys", which expected `category`. That is the intended change (difference 1); the check now expects the seven keys. No other check failed.

Real database after all runs: `complaint_types` 5 rows, all active, every version 1; `complaint_type_fields` 9 rows, every version 1; no complaint-type audit rows; 7 complaints. `complaint-baseline.php` output unchanged (7 complaints).

The runner leaves its test server up for a browser pass; it was stopped. Its scratch database `capstone_fix24_3808` remains; the runner drops it at the start of its next run.

### complaint-types/run.php, "Phase 4" section (both modes, read-only)

- Every complaint has a `complaint_type_id` that exists.
- Type names are unique without regard to case.
- Every `type_code`, `field_key` and option value matches its pattern (the `D`-modifier constants from `_admin.php`).
- No stored select value is missing from its field's options (active or retired).
- `complaint_extra_fields_display` works for every complaint.
- Filter choices: every type once, active ones first in sort order, inactive ones labelled "(no longer used)".
- Nothing written (type, field and audit snapshot unchanged).

Already in both modes from before: type `other` exists and is active with an active, required `specify` text field; at least one type is active.

Seed check (e) now builds community cards from a type id: a deactivated type keeps its current name, the stored `category` text is ignored, an unknown or missing id shows "Other", and the card has no `category` key.

Live mode still has the three `[live]` checks tied to the 7 Phase 0 complaints. They fail only if one of those complaints is deleted or its category is edited directly; filing, renaming or deactivating through the screen does not affect them.

### complaint-interface/api-checks.php, "Phase 4" (scratch database)

Uses the Phase 3 test type and its two complaints, made public for the board.

- (a) `?type_id=<id>` returns only that type's complaints with `type_id`, `type_name` and no `category`; `?type_id=all` means no filter. `?type_id=999999`, `?category=Other`, `?type_id=1%0A`, empty, `-1` and `01` all answer 422 (`Invalid type.` / `Invalid filter.`).
- (b) Renaming through `save_type` to "Loose Animals": `public_list.php` and `list.php` (staff and resident sessions) show the new name for the old complaints; the stored `category` stays "Stray Animals".
- (c) Deactivating the type: its public complaints are still listed with its name, and filtering by its id still works.
- (d) `community_complaints.php` renders the type select from the database: "All types", active types, then "Loose Animals (no longer used)" after them; no old name. The community script no longer contains the hardcoded names and sends `type_id`.
- (e) `official.php` for a staff official (not admin): `data-tab="complainttypes"`, `tab-complainttypes`, both modals, `complaint-types.js` loaded after `document-templates.js`; the complaints tab has `compTypeFilter` rendered from the database including the inactive type.
- (f) Cleanup: the Phase 3 cleanup (j) removes the type, its complaints, audit rows and the test official, and confirms the seed is intact.

### complaint-types/frontend.js (Node)

Loads `js/official.js` and `js/complaint-types.js` in a VM with a stub DOM, as `document-forms/frontend.js` does.

- `escapeHtml` comes from `official.js`; the new file defines no escape helper and no inline handlers.
- Init is registered as the top-level `initComplaintTypesSafely`; a failure inside it does not propagate.
- Options editor: saved options cannot be removed; "Add option" adds one unsaved active row; only that row can be removed; move up/down swaps neighbours, does nothing past either end, and does not change the original list.
- Request bodies: options payload keeps every saved value in display order with only `value`, `label`, `is_active`; field update sends `id` and `version` and never key or type; each field kind sends only its own settings; type create omits an empty code and sort order; type update sends `id` and `version` and never a code.
- Only the stale-version 409 counts as "changed by someone else".

## Manual browser checklist

Not done yet. For the real application.

1. Staff login → Complaint Types tab visible; admin login → same.
2. Create type "Stray Animals" (respondent optional) with a select field (3 options), a text field, a number field, a date field.
3. Resident form: new type appears with all 4 inputs; file one complaint.
4. Deactivate one option → resident form no longer offers it; the filed complaint still shows it with "(retired)".
5. Rename the type → My Complaints, official list, community board show the new name.
6. Open the same type in two browser tabs, save in both → second save shows the "changed by someone else" toast.
7. Deactivate the type → hidden in resident form, still shown on old complaints and in filters as "(no longer used)".
8. Type "Other" and its "specify" field: Deactivate / Required controls disabled with tooltip.
9. Audit Log tab shows each change.
10. Delete the test type's complaint afterwards, or keep it — default-mode `run.php` must still pass either way.

Notes for the checklist:

- Step 5, community board: only complaints an official made public appear there. Make the test complaint public first.
- Step 10: keeping the test type and its complaint is fine for live mode. Only the three `[live]` Phase 0 checks look at specific complaints (ids 1–7).

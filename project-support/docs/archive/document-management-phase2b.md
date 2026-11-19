# Document Management Phase 2B

## What the tab does

The official console's **Document Templates** tab:

- Shows every registered document type and its active or archived state.
- Uploads DOCX template versions with client and server validation.
- Displays template-check errors without exposing stored file names or hashes.
- Test-renders a version with fictional sample data and shows a PDF preview.
- Activates draft or retired versions after a passing check and test render.
- Supports rollback by activating a retired version.
- Discards drafts only.
- Downloads individual versions.
- Archives and unarchives document types without deleting them.
- Updates the printed document signatory name.

The signatory stores the captain's name only. Documents print `Hon. <name>` and
`Barangay Captain`.

## Permissions

| Action | Staff | Barangay Captain |
| --- | --- | --- |
| View types and history | Yes | Yes |
| Upload, test-render, activate, rollback, discard, download | Yes | Yes |
| Change signatory | No | Yes |
| Archive or unarchive a type | No | Yes |

Archived types block new requests. Existing requests remain processable.

## Manual test checklist

1. Sign in as staff. Open **Configuration > Document Templates**.
2. Confirm the signatory input and Save button are disabled.
3. Confirm the staff permission note is visible.
4. Confirm the template list shows name, status, active version, uploader, and date.
5. Open History. Confirm version actions are visible and server-generated file names (tpl_*) and hashes are absent.
6. Upload a non-DOCX file. Confirm a clear client-side error.
7. Upload a DOCX larger than 2 MB. Confirm a clear client-side error.
8. Upload a valid DOCX. Confirm the new draft opens highlighted in History.
9. Upload a template with check errors. Confirm the errors appear in the upload result.
10. Test-render a valid version. Confirm the PDF preview opens and says it uses fictional sample data.
11. Test-render while another render is running. Confirm all test buttons are disabled and the busy error is shown.
12. Activate a tested version. Confirm the activation message and refreshed active version.
13. Test rollback by test-rendering and activating a retired version.
14. Discard a draft. Confirm it disappears from the activatable state.
15. Download an active or historical version.
16. Sign in as the Barangay Captain. Change the name using a name only.
17. Try a title such as `Hon. Captain Name`. Confirm the server's validation message appears inline.
18. Archive a type. Confirm the warning explains that new requests are blocked while existing requests continue.
19. Confirm a resident cannot create a new request for the archived type.
20. Confirm an existing request for the archived type can still be processed.
21. Unarchive the type and confirm new requests are allowed again.
22. Open and close each modal with its close button, outside click, and Escape.
23. Confirm focus returns to the control that opened each modal.
24. Repeat relevant checks at a narrow viewport.

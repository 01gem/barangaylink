# Document management: Phase 2A

Back up the database and test this migration on a disposable copy before
applying it to a real installation.

## Version lifecycle

Each document type has immutable DOCX files and metadata in
`document_template_versions`. Uploads create `draft` versions. A successful
check and test render are required before activation. Activation retires the
previous active version; activating a retired version is the rollback path.
Drafts may be discarded, but files are never deleted or overwritten.

The database enforces one active version per document type. Every stored file
has a server-generated basename and a SHA-256 checksum.

Never edit or replace a seeded template file in `document_templates/` in
place. Make the change in Word, upload it as a new version, test-render it,
and activate it. Activation refuses a version whose file no longer matches the
checksum on its record, and running another test render does not clear that.

## Endpoints

Officials use `types_list.php`, `template_upload.php`,
`template_test_render.php`, `template_preview.php`, `template_download.php`,
`template_activate.php`, and `template_discard.php`. Only an administrator
may use `type_archive.php`. All state changes are audited.

Uploads accept DOCX files no larger than 2 MB. Archives must contain the
required DOCX parts, have no unsafe paths, macros, embedded objects, external
relationships other than hyperlinks, or excessive compression.

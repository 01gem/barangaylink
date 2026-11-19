DOCUMENT TEMPLATES

Place the local .docx templates used by api/requests/generate_document.php in this folder.

Required filenames are defined in the $templateMap in that PHP endpoint.
The DOCX templates are tracked by Git. Temporary Word lock files (`~$*`) and
temporary `.tmp` files in this folder are ignored; do not commit private
deployment-specific template content.

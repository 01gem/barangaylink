# Document Management Phase 1

Phase 1 adds a database-backed document type registry and the editable
captain-name setting. It does not add a management UI or change the DOCX
templates.

## Schema

- `document_types` stores the exact, case-sensitive display name, safe DOCX
  basename, active/archived status, and ordering.
- `document_type_fields` stores placeholder order, source, type, and resident
  validation bounds.
- `document_settings` stores `captain_name` only; the value is the name without
  a title or position. The input rejects names starting with `Hon.` or
  `Honorable`. Rendered documents add `Hon.` before the name and
  `Barangay Captain` on the next line. Each fresh install has 40 registry
  fields after the official-title migration.

Apply [`20261108_document_registry.sql`](../migrations/20261108_document_registry.sql)
only after backing up the database and testing on a copy. The migration is
additive and safe to run twice.

## Runtime rules

The generation coordinator reads the registry and signatory once, then passes
that context to the renderer. If `document_types` does not exist, rendering
uses the legacy maps and `Erwin Astronomo`. If the table exists but a type is
missing/archived, or `captain_name` is missing/empty, generation fails closed.
Type lookup is an exact PHP string match.

Template paths must be lowercase-safe DOCX basenames resolved inside
`document_templates`. Rendering formats valid ISO dates, removes one trailing
`Barangay Sampaguita` address mention, and rejects unresolved placeholders.

## Rollback

Stop request generation, back up the database, and test on a copy first. To
roll back Phase 1, drop `document_type_fields`, `document_settings`, and
`document_types` in that order, then revert the Phase 1 source files. With the
tables absent, the legacy fallback preserves the previous registry behavior.

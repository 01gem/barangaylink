# Document Management Phase 0

This document records the baseline and safety-net work for the document
management revision. The current source still uses hardcoded document types.

## Step A key matrix

`DOC_TEMPLATE_FIELDS` contains fields staff can review or enter in the
generation modal. The renderer additionally fills resident-record and
system-generated values. `REQUEST_FIELD_WHITELIST` and
`DOC_REQUEST_EXTRA_FIELDS` are the resident request-time fields.

| Document type | `$templateMap` | `DOC_TEMPLATE_FIELDS` | `REQUEST_FIELD_WHITELIST` | `DOC_REQUEST_EXTRA_FIELDS` | Dropdown | `document_prices` | Source classification |
|---|---|---|---|---|---|---|---|
| Barangay Clearance | `full_name`, `age`, `civil_status`, `address`, `purpose`, `date_issued`, `reference_no`, `official_name`, `official_position` | `full_name`, `age`, `civil_status`, `address`, `purpose`, `date_issued` | `age`, `civil_status` | `age`, `civil_status` | present | ₱20.00 | resident record: `full_name`, `address`; resident-supplied at request time (whitelist): `age`, `civil_status`; staff-entered at generation: `purpose`, `date_issued`; system-generated: `reference_no`, `official_name`, `official_position` |
| Certificate of Residency | `full_name`, `address`, `years_of_residency`, `purpose`, `date_issued`, `reference_no`, `official_name`, `official_position` | `full_name`, `address`, `years_of_residency`, `purpose`, `date_issued` | `years_of_residency` | `years_of_residency` | present | ₱20.00 | resident record: `full_name`, `address`; resident-supplied at request time (whitelist): `years_of_residency`; staff-entered at generation: `purpose`, `date_issued`; system-generated: `reference_no`, `official_name`, `official_position` |
| Certificate of Indigency | `full_name`, `address`, `purpose`, `date_issued`, `reference_no`, `official_name`, `official_position` | `full_name`, `address`, `purpose`, `date_issued` | none | none | present | ₱20.00 | resident record: `full_name`, `address`; staff-entered at generation: `purpose`, `date_issued`; system-generated: `reference_no`, `official_name`, `official_position` |
| Certificate of Good Moral Character | `full_name`, `address`, `purpose`, `date_issued`, `reference_no`, `official_name`, `official_position` | `full_name`, `address`, `purpose`, `date_issued` | none | none | present | ₱20.00 | resident record: `full_name`, `address`; staff-entered at generation: `purpose`, `date_issued`; system-generated: `reference_no`, `official_name`, `official_position` |
| Business Permit Endorsement | `owner_name`, `business_name`, `business_type`, `business_address`, `purpose`, `date_issued`, `reference_no`, `official_name`, `official_position` | `owner_name`, `business_name`, `business_type`, `business_address`, `purpose`, `date_issued` | `business_name`, `business_type` | `business_name`, `business_type` | present | ₱20.00 | resident record: `owner_name`, `business_address`; resident-supplied at request time (whitelist): `business_name`, `business_type`; staff-entered at generation: `purpose`, `date_issued`; system-generated: `reference_no`, `official_name`, `official_position` |

The dropdown and `document_prices` seed contain all five document types at
the current price of 20.00. The whitelist and resident extra-field list both
include `years_of_residency` for Certificate of Residency. The renderer alone
also declares `reference_no`, `official_name`, and the retired
`official_position`.

## Audit output

Run:

```text
php project-support/scripts/audit-templates.php
```

The report produced for the current template files is recorded below after
the Phase 0 verification run. The golden files were recorded after the
repaired templates were installed.

```text
Document template audit
=======================
Template directory: C:\laragon\www\Capstone-System\document_templates
Mode: report

TEMPLATE: barangay_clearance.docx
  Parts:
    word/document.xml: reference_no, full_name, age, civil_status, address, purpose, date_issued, official_name
    word/fontTable.xml: (none)
    word/numbering.xml: (none)
    word/settings.xml: (none)
    word/styles.xml: (none)
    word/webSettings.xml: (none)
TEMPLATE: business_permit_endorsement.docx
  Parts:
    word/document.xml: reference_no, owner_name, business_name, business_type, business_address, purpose, date_issued, official_name
    word/fontTable.xml: (none)
    word/numbering.xml: (none)
    word/settings.xml: (none)
    word/styles.xml: (none)
    word/webSettings.xml: (none)
TEMPLATE: certificate_of_good_moral_character.docx
  Parts:
    word/document.xml: reference_no, full_name, address, purpose, date_issued, official_name
    word/fontTable.xml: (none)
    word/numbering.xml: (none)
    word/settings.xml: (none)
    word/styles.xml: (none)
    word/webSettings.xml: (none)
TEMPLATE: certificate_of_indigency.docx
  Parts:
    word/document.xml: reference_no, full_name, address, purpose, date_issued, official_name
    word/fontTable.xml: (none)
    word/numbering.xml: (none)
    word/settings.xml: (none)
    word/styles.xml: (none)
    word/webSettings.xml: (none)
TEMPLATE: certificate_of_residency.docx
  Parts:
    word/document.xml: reference_no, full_name, address, years_of_residency, purpose, date_issued, official_name
    word/fontTable.xml: (none)
    word/numbering.xml: (none)
    word/settings.xml: (none)
    word/styles.xml: (none)
    word/webSettings.xml: (none)
Summary: 5 template(s), 0 template(s) with problems.
```

## Deployment and direct-access protection

The Apache rule in `document_templates/.htaccess` denies direct access to
templates. Verify from the browser that a request such as
`/document_templates/barangay_clearance.docx` returns 403 or 404.

For nginx, deny the location before the general static-file handler:

```nginx
location ^~ /document_templates/ {
    deny all;
    return 403;
}
```

For IIS, add a web.config under `document_templates/`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <fileExtensions>
          <add fileExtension=".docx" allowed="false" />
        </fileExtensions>
      </requestFiltering>
    </security>
    <authorization>
      <remove users="*" />
      <add accessType="Deny" users="*" />
    </authorization>
  </system.webServer>
</configuration>
```

The deployment builder already allows `.htaccess` in
`document_templates`; its output must include this file.

## Baseline commands

```text
php project-support/tests/document-baseline/run.php --update
php project-support/tests/document-baseline/run.php
```

The PDF page count is approximate because it counts `/Type /Page` markers.
Baseline update records current output as-is and warns, without correcting,
any unresolved `{{...}}` placeholders.

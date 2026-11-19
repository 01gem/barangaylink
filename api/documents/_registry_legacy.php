<?php
/**
 * BarangayLink - Legacy document registry fallback.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 */

const LEGACY_DOCUMENT_MAP = [
    'Barangay Clearance' => [
        'file' => 'barangay_clearance.docx',
        'placeholders' => ['full_name', 'age', 'civil_status', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'],
    ],
    'Certificate of Residency' => [
        'file' => 'certificate_of_residency.docx',
        'placeholders' => ['full_name', 'address', 'years_of_residency', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'],
    ],
    'Certificate of Indigency' => [
        'file' => 'certificate_of_indigency.docx',
        'placeholders' => ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'],
    ],
    'Certificate of Good Moral Character' => [
        'file' => 'certificate_of_good_moral_character.docx',
        'placeholders' => ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'],
    ],
    'Business Permit Endorsement' => [
        'file' => 'business_permit_endorsement.docx',
        'placeholders' => ['owner_name', 'business_name', 'business_type', 'business_address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'],
    ],
];

const LEGACY_RESIDENT_FIELDS = [
    'Barangay Clearance' => [
        'age' => ['data_type' => 'int', 'min_value' => 0, 'max_value' => 120],
        'civil_status' => ['data_type' => 'text', 'max_length' => 100],
    ],
    'Certificate of Residency' => [
        'years_of_residency' => ['data_type' => 'int', 'min_value' => 0, 'max_value' => 120],
    ],
    'Business Permit Endorsement' => [
        'business_name' => ['data_type' => 'text', 'max_length' => 100],
        'business_type' => ['data_type' => 'text', 'max_length' => 100],
    ],
];

# Complaint Process Improvements - Mediation Calendar, Dynamic Types, Person Involvement, and Billing

## Overview
This document outlines the additions and modifications needed to adapt the existing complaint process documentation to include:
1. Mediation calendar (Husay) for in-person dispute resolution
2. Dynamic complaint types with configurable fields
3. Person involvement tracking
4. Billing and financial notes per complaint

---

## 1. Data Model Changes (Section 4)

### Add to `complaints` table:
```sql
ALTER TABLE complaints ADD COLUMN complaint_type_id INT NULL;
ALTER TABLE complaints ADD COLUMN involves_person TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE complaints ADD COLUMN person_name VARCHAR(150) NULL;
ALTER TABLE complaints ADD COLUMN person_address VARCHAR(255) NULL;
ALTER TABLE complaints ADD COLUMN billing_note TEXT NULL;
ALTER TABLE complaints ADD COLUMN billing_amount DECIMAL(10,2) NULL;
ALTER TABLE complaints ADD COLUMN billing_status ENUM('unbilled','pending','paid','waived') NULL;
ALTER TABLE complaints ADD COLUMN mediation_required TINYINT(1) NOT NULL DEFAULT 0;
```

### Add new tables:

**complaint_types** - Dynamic complaint type configuration
```sql
CREATE TABLE IF NOT EXISTS `complaint_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `type_code` VARCHAR(50) NOT NULL UNIQUE,
  `type_name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `requires_person` TINYINT(1) NOT NULL DEFAULT 0,
  `requires_billing` TINYINT(1) NOT NULL DEFAULT 0,
  `default_mediates` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**complaint_type_fields** - Dynamic field configuration per type
```sql
CREATE TABLE IF NOT EXISTS `complaint_type_fields` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `complaint_type_id` INT NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `field_label` VARCHAR(100) NOT NULL,
  `field_type` ENUM('text','textarea','select','checkbox','date','number') NOT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `field_options` JSON NULL,
  `validation_regex` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_fields` (`complaint_type_id`, `sort_order`),
  CONSTRAINT `fk_field_type` FOREIGN KEY (`complaint_type_id`)
    REFERENCES `complaint_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**complaint_field_values** - Store dynamic field values per complaint
```sql
CREATE TABLE IF NOT EXISTS `complaint_field_values` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `field_value` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_complaint_field` (`complaint_id`, `field_key`),
  CONSTRAINT `fk_value_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**husay_schedules** - Mediation sessions
```sql
CREATE TABLE IF NOT EXISTS `husay_schedules` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `complaint_id` INT UNSIGNED NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `venue` VARCHAR(180) NOT NULL,
  `assigned_official_id` INT UNSIGNED NOT NULL,
  `complainant_name` VARCHAR(150) NOT NULL,
  `complainant_mobile` VARCHAR(30) DEFAULT NULL,
  `respondent_name` VARCHAR(150) NOT NULL,
  `respondent_mobile` VARCHAR(30) DEFAULT NULL,
  `respondent_address` VARCHAR(255) DEFAULT NULL,
  `instructions` TEXT DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
  `outcome` VARCHAR(30) DEFAULT NULL,
  `complainant_attendance` VARCHAR(20) NOT NULL DEFAULT 'Not recorded',
  `respondent_attendance` VARCHAR(20) NOT NULL DEFAULT 'Not recorded',
  `public_summary` TEXT DEFAULT NULL,
  `private_notes` TEXT DEFAULT NULL,
  `rescheduled_from` INT UNSIGNED DEFAULT NULL,
  `change_reason` VARCHAR(1000) DEFAULT NULL,
  `reviewed_by` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `request_key` CHAR(32) DEFAULT NULL UNIQUE,
  `decision_reason` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `husay_calendar` (`status`, `starts_at`, `ends_at`),
  INDEX `husay_complaint` (`complaint_id`),
  CONSTRAINT `fk_husay_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`),
  CONSTRAINT `fk_husay_official` FOREIGN KEY (`assigned_official_id`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**husay_history** - Mediation audit trail
```sql
CREATE TABLE IF NOT EXISTS `husay_history` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `hearing_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(40) NOT NULL,
  `description` TEXT NOT NULL,
  `actor_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `husay_history_hearing` (`hearing_id`),
  CONSTRAINT `fk_husay_history` FOREIGN KEY (`hearing_id`)
    REFERENCES `husay_schedules`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**husay_calendar_guard** - Row-level locking
```sql
CREATE TABLE IF NOT EXISTS `husay_calendar_guard` (
  `id` TINYINT PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO husay_calendar_guard(id) VALUES (1);
```

---

## 2. Process Documentation (Section 6 - Add New Subsections)

### Insert after 6.9 (Community concerns board):

#### 6.10 Dynamic Complaint Types and Conditional Fields

**Purpose:** Allow administrators to configure complaint types with custom fields that display conditionally based on user selections.

**Configuration Flow:**
1. Admin accesses **Settings → Complaint Types** (new admin-only page)
2. Creates/edits complaint types with: type code, name, description, requires person, requires billing, default mediation, active status
3. For each type, admin adds dynamic fields: field key, label, type (text/textarea/select/checkbox/date/number), required, options, validation
4. Types can be activated/deactivated without losing data

**Resident Filing Flow:**
1. Resident selects complaint type from dropdown
2. AJAX call to `api/complaints/get_type_fields.php?type_id=X`
3. Form dynamically renders: standard fields + type-specific dynamic fields + person section (if required) + billing section (if required)
4. Validation runs against type-specific rules
5. Submission stores both standard and dynamic field values

#### 6.11 Person Involvement Tracking

**Purpose:** Track whether a complaint involves a specific person for mediation purposes.

**Conditional Logic:**
- If type has `requires_person = 1`: Person section auto-displays and is required
- If type has `requires_person = 0`: Resident can toggle "Involves a person?" checkbox
- When enabled, requires: person name (required), person address (optional)
- Person data stored in `complaints.person_name`, `person_address`

**Mediation Integration:**
- Scheduling mediation auto-populates respondent fields from person data
- If no person involved, mediation marked as "concern-based" (no respondent)
- Calendar shows mediation type: person-based vs. concern-based

#### 6.12 Billing and Financial Notes

**Purpose:** Track billing information for complaints that incur barangay fees.

**Billing Workflow:**
1. Initial state: `billing_status = 'unbilled'`
2. Official sets billing: adds `billing_note`, sets `billing_amount`, status → `pending`
3. Payment processing: treasurer records payment → status → `paid`; or admin waives → status → `waived`
4. Billing visibility: residents see billing note when status is `pending` or `paid`

**Billing Permissions:**
- Staff: Can add billing notes, set amounts
- Admin: Can waive fees, change billing status
- Resident: Can view own billing status (read-only)

#### 6.13 Mediation Calendar (Husay)

**Purpose:** Schedule in-person mediation sessions with conflict detection, approval workflow, and outcome tracking.

**A. Scheduling a Session**
1. Official opens complaint → clicks "Schedule Mediation"
2. Modal displays: hearing date/time, venue, assigned official (must be Captain), complainant details, respondent details, instructions
3. Validation: date/time must be current/future, no schedule conflicts, complaint must be open/investigating
4. Approval: Captain schedules → status = `Scheduled`; staff schedules → status = `Pending Approval`
5. Creates `husay_schedules` row with version = 1
6. Audit entry in `husay_history`

**B. Walk-in Mediation**
1. Official clicks "Add Walk-in Mediation"
2. Creates complaint on-the-spot: code format `CMP-W-YYYYMMDD-XXXX`, can link to resident or anonymous
3. Proceeds with standard scheduling

**C. Captain Approval**
1. Captain reviews pending schedules
2. Approve: validates no conflicts, marks old session `Rescheduled` if rescheduling, new session → `Scheduled`, complaint → `investigating`
3. Decline: requires reason, session → `Declined`, complaint unchanged

**D. Session Recording**
1. Official opens scheduled session
2. Records: attendance (Present/Absent/Not applicable), outcome (Settled/For follow-up/Referred/No agreement), public summary, private notes
3. Actions: cancel session (requires reason), record attendance only, complete session (status → `Completed`)
4. After completion, complaint can be resolved

**E. Calendar View**
- Full calendar showing all scheduled sessions
- Filter by status (Pending Approval, Scheduled, In Progress)
- Color-coded by outcome
- Conflict warnings for overlapping times
- Export to iCal for external calendar integration

**Concurrency Controls:**
- Row-level locking via `husay_calendar_guard`
- Optimistic locking via `version` field
- Duplicate submission prevention via `request_key`

---

## 3. API Reference (Section 7 - Add New Endpoints)

Add to the API endpoint table:

| Method | Endpoint | Who | Purpose |
|--------|----------|-----|---------|
| GET | `api/complaints/list_types.php` | Admin | List all complaint types with field configs |
| POST | `api/complaints/create_type.php` | Admin | Create new complaint type |
| POST | `api/complaints/update_type.php` | Admin | Update complaint type |
| POST | `api/complaints/delete_type.php` | Admin | Soft-delete complaint type |
| GET | `api/complaints/get_type_fields.php` | Resident/Official | Get dynamic fields for a type |
| POST | `api/complaints/create_field.php` | Admin | Add field to complaint type |
| POST | `api/complaints/update_field.php` | Admin | Update field configuration |
| POST | `api/complaints/delete_field.php` | Admin | Delete field from type |
| POST | `api/complaints/set_billing.php` | Staff/Admin | Set billing note/amount/status |
| GET | `api/husay/list_schedules.php` | Official | List mediation sessions |
| POST | `api/husay/schedule.php` | Staff/Admin | Create/reschedule mediation |
| POST | `api/husay/decide_schedule.php` | Admin | Approve/decline pending schedule |
| POST | `api/husay/record_session.php` | Staff/Admin | Record attendance/outcome |
| POST | `api/husay/cancel_session.php` | Admin | Cancel mediation session |
| GET | `api/husay/calendar.php` | Official | Get calendar feed (JSON/iCal) |
| GET | `api/husay/available_officials.php` | Staff | Get available officials for scheduling |

---

## 4. Actors and Permissions (Section 2 - Modify)

Update the permissions table to add:

| Actor | Additional Can Do | Additional Cannot Do |
|-------|------------------|---------------------|
| **Resident** | File complaint with dynamic fields; toggle person involvement; view own billing status | Manage complaint types; schedule mediation; set billing; approve schedules |
| **Official — staff** | Schedule mediation (pending approval); set billing notes; view calendar | Manage complaint types; approve schedules; waive billing |
| **Official — admin** | Manage complaint types and fields; approve/decline mediation schedules; waive billing fees; cancel mediation sessions | — |

---

## 5. Security and Privacy Controls (Section 9 - Add)

Add to the security table:

| Risk | Control |
|------|---------|
| Unauthorized complaint type changes | Admin-only endpoints; active session validation; audit log on type CRUD |
| Dynamic field injection | Field keys allowlisted per type; values sanitized; JSON schema validation |
| Billing data exposure | Billing status/amount hidden from residents until pending/paid; admin-only waiver |
| Mediation schedule conflicts | Row-level locking; overlap detection; `request_key` duplicate prevention |
| Unauthorized mediation access | Role gates (staff can propose, admin must approve); ownership checks |
| Calendar manipulation | Optimistic locking with version field; session validation on every update |
| Person data privacy | Person data not exposed on community board; mediation summary anonymized |

---

## 6. Concurrency and Consistency Rules (Section 8 - Add)

Add to the concurrency table:

| Operation | Lock / guard | Behaviour on conflict |
|-----------|--------------|----------------------|
| Mediation scheduling | `husay_calendar_guard` row lock + `request_key` duplicate check | 409 on duplicate submission; retry on deadlock |
| Schedule approval | Session row lock + version check | 409 on stale version |
| Session recording | Session row lock + version check | 409 on stale version |
| Complaint type update | Type row lock | Last writer wins; audit entry required |
| Dynamic field value write | Complaint row lock + unique constraint on (complaint_id, field_key) | Overwrites existing value; idempotent |
| Billing status change | Complaint row lock + expected_billing_status | 409 on stale status |

---

## 7. Verification (Section 12 - Add Test Cases)

Add to the verification checklist:

**Dynamic Types:**
1. Create complaint type with required fields, verify form renders correctly
2. Create type with conditional person requirement, verify toggle behavior
3. Test field validation regex patterns
4. Test type deactivation (existing complaints retain type, new ones can't select)

**Person Involvement:**
1. File complaint with person, verify data auto-populates mediation
2. File complaint without person, verify mediation marks as concern-based
3. Test person data visibility (resident sees own, officials see all)

**Billing:**
1. Set billing on complaint, verify resident sees pending status
2. Record payment, verify status changes to paid
3. Waive fee as admin, verify audit trail
4. Test billing visibility rules

**Mediation:**
1. Schedule as staff, verify pending approval status
2. Approve as admin, verify conflict detection
3. Test walk-in mediation flow
4. Record session attendance and outcome
5. Test rescheduling with conflict prevention
6. Verify calendar export integrity

**Integration:**
1. File complaint → type selection → dynamic fields → mediation → billing → resolution
2. Test full workflow with person involvement
3. Test full workflow without person involvement
4. Verify audit trail captures all mediation and billing actions

---

## 8. Migration Guide (New Section 15)

### Database Migration

**Phase 1: Add columns and tables**
```sql
-- Add new columns to complaints (see Section 1)
-- Create new tables (see Section 1)

-- Migrate existing categories to complaint_types
INSERT INTO complaint_types (type_code, type_name, description, requires_person, requires_billing, default_mediates, is_active, sort_order)
SELECT
  LOWER(REPLACE(category, ' ', '_')) as type_code,
  category as type_name,
  category as description,
  0 as requires_person,
  0 as requires_billing,
  0 as default_mediates,
  1 as is_active,
  0 as sort_order
FROM (SELECT DISTINCT category FROM complaints) c;

-- Link existing complaints to new types
UPDATE complaints c
JOIN complaint_types ct ON ct.type_name = c.category
SET c.complaint_type_id = ct.id;
```

**Phase 2: Backward Compatibility**
- Keep `category` column for read operations during transition
- API endpoints accept both `category` (legacy) and `complaint_type_id` (new)
- Frontend falls back to category if type_id not set
- After migration period, deprecate `category` column

**Phase 3: Data Population**
- Admin creates proper complaint types with field configurations
- Admin maps existing categories to new types
- Bulk-update complaints to set `involves_person` based on content analysis
- Set default `mediation_required` based on type patterns

**Phase 4: UI Rollout**
- Deploy new admin complaint types management
- Update resident form with dynamic field rendering
- Deploy mediation calendar to official portal
- Add billing panel to official portal
- Train staff on new workflows

**Phase 5: Cleanup**
- Remove `category` column after deprecation period
- Remove legacy category validation
- Update all documentation

---

## 9. File Structure Updates (Section 3 - Modify)

Add to the file structure table:

| Layer | New Files |
|-------|-----------|
| Database | `database/migration_complaint_types.sql`, `database/migration_husay.sql` |
| Backend API | `api/complaints/*_type.php`, `api/complaints/*_field.php`, `api/complaints/set_billing.php`, `api/husay/*.php` (13 new files) |
| Admin UI | `pages/admin_types.php`, `js/admin-types.js` |
| Official UI | `pages/official_calendar.php`, `js/official-calendar.js`, `js/official-mediation.js` |
| Resident UI | `js/resident-dynamic-form.js` |
| Styling | `css/mediation.css`, `css/dynamic-form.css` |

---

## 10. Content Order (Section 1 - Update Contents)

Update the contents list to add:

1. 6.10 Dynamic Complaint Types and Conditional Fields
2. 6.11 Person Involvement Tracking
3. 6.12 Billing and Financial Notes
4. 6.13 Mediation Calendar (Husay)
5. 15. Migration Guide

And append:
- Appendix D — Mediation API source code
- Appendix E — Dynamic type configuration examples

<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../api/_session.php';
session_start();
$officialAccess = session_revalidate_active();
if (($officialAccess['error'] ?? null) === 'server') {
  http_response_code(500);
  header('Content-Type: text/html; charset=utf-8');
  echo session_access_failure_html();
  exit;
}
if (empty($officialAccess['ok']) || empty($_SESSION['official_id'])) {
  header('Location: login.php');
  exit;
}
function e($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$isOfficialAdmin = ($_SESSION['official_role'] ?? '') === 'admin';
require_once __DIR__ . '/../api/complaints/_extra_fields.php';
// Complaint type filter choices. If they cannot be read, the filter offers "All types" only.
try {
  $complaintTypeFilterOptions = complaint_type_filter_options();
} catch (Throwable $error) {
  $complaintTypeFilterOptions = [];
}
$officialName = trim((string)($_SESSION['official_name'] ?? ''));
$officialRole = trim((string)($_SESSION['official_role'] ?? ''));
$officialPosition = trim((string)($_SESSION['official_position'] ?? ''));
$officialUsername = '';
$officialAddress = '';
$officialContact = '';
$officialStatus = '';
$officialCreatedAt = '';
$officialProfilePhoto = '';
$officialDb = get_db();
$officialPhotoStmt = $officialDb->prepare('SELECT username, address, contact, status, created_at, profile_photo FROM barangay_officials WHERE id = ? LIMIT 1');
if ($officialPhotoStmt) {
  $officialId = (int)$_SESSION['official_id'];
  $officialPhotoStmt->bind_param('i', $officialId);
  $officialPhotoStmt->execute();
  $officialPhotoResult = $officialPhotoStmt->get_result();
  if ($officialPhotoResult && ($officialPhotoRow = $officialPhotoResult->fetch_assoc())) {
    $officialUsername = (string)($officialPhotoRow['username'] ?? '');
    $officialAddress = (string)($officialPhotoRow['address'] ?? '');
    $officialContact = (string)($officialPhotoRow['contact'] ?? '');
    $officialStatus = (string)($officialPhotoRow['status'] ?? '');
    $officialCreatedAt = (string)($officialPhotoRow['created_at'] ?? '');
    $officialProfilePhoto = (string)($officialPhotoRow['profile_photo'] ?? '');
  }
  $officialPhotoStmt->close();
}
$officialPhotoUrl = $officialProfilePhoto !== '' ? '../' . ltrim($officialProfilePhoto, '/') : '';
$officialRoleLabel = $officialRole === 'admin' ? 'Administrator' : ($officialRole === 'staff' ? 'Staff' : ucwords(str_replace(['_', '-'], ' ', $officialRole)));
$officialSubtitle = $officialPosition !== '' ? $officialPosition : ($officialRoleLabel !== '' ? $officialRoleLabel : 'Official');
$officialInitials = 'O';
$officialNameParts = preg_split('/\s+/', $officialName, -1, PREG_SPLIT_NO_EMPTY);
if (count($officialNameParts) >= 2) {
  $officialInitials = strtoupper(substr($officialNameParts[0], 0, 1) . substr($officialNameParts[count($officialNameParts) - 1], 0, 1));
} elseif (count($officialNameParts) === 1) {
  $officialInitials = strtoupper(substr($officialNameParts[0], 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BarangayLink — Official Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/official.css?v=<?= filemtime(__DIR__ . '/../css/official.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
</head>
<body>

  <!-- ═══ SIDEBAR ═══ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon"><i class="fa-solid fa-seedling"></i></div>
      <div class="sidebar-brand-text">
        <span class="brand-name">BarangayLink</span>
        <span class="brand-loc">Admin / Official Panel</span>
      </div>
      <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Hide sidebar" aria-expanded="true">
        <i class="fa-solid fa-angles-left"></i>
      </button>
    </div>
    <div class="sidebar-user">
      <?php if ($officialPhotoUrl !== ''): ?>
        <img class="user-avatar official-header-photo" src="<?= e($officialPhotoUrl) ?>" alt="Profile photo" />
      <?php else: ?>
        <div class="user-avatar"><?= e($officialInitials) ?></div>
      <?php endif; ?>
      <div class="user-info">
        <span class="user-name"><?= e($officialName !== '' ? $officialName : 'Official') ?></span>
        <span class="user-role official"><i class="fa-solid fa-shield-halved"></i> <?= e($officialSubtitle) ?></span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group-label">Main</div>
      <button class="snav-item active" data-tab="dashboard"><i class="fa-solid fa-gauge-high"></i> <span class="nav-text">Dashboard</span></button>
      <div class="nav-group-label">Management</div>
      <button class="snav-item" data-tab="residents"><i class="fa-solid fa-users"></i> <span class="nav-text">Residents</span></button>
      <button class="snav-item" data-tab="requests"><i class="fa-solid fa-file-lines"></i> <span class="nav-text">Document Requests</span></button>
      <button class="snav-item" data-tab="complaints"><i class="fa-solid fa-triangle-exclamation"></i> <span class="nav-text">Complaints</span></button>
      <button class="snav-item" data-tab="complainttypes"><i class="fa-solid fa-list-check"></i> <span class="nav-text">Complaint Types</span></button>
      <button class="snav-item" data-tab="hearings"><i class="fa-solid fa-calendar-days"></i> <span class="nav-text">Mediation Calendar</span></button>
      <div class="nav-group-label">Configuration</div>
      <button class="snav-item" data-tab="documentprices"><i class="fa-solid fa-tags"></i> <span class="nav-text">Document Prices</span></button>
      <button class="snav-item" data-tab="documents"><i class="fa-solid fa-file-signature"></i> <span class="nav-text">Document Templates</span></button>
      <div class="nav-group-label">Community</div>
      <button class="snav-item" data-tab="announcements"><i class="fa-solid fa-bullhorn"></i> <span class="nav-text">Announcements</span></button>
      <button class="snav-item" data-tab="services"><i class="fa-solid fa-store"></i> <span class="nav-text">Local Services</span></button>
      <div class="nav-group-label">Intelligence</div>
      <button class="snav-item" data-tab="aianalyst"><i class="fa-solid fa-brain"></i> <span class="nav-text">AI Analytics</span></button>
      <div class="nav-group-label">System</div>
      <?php if ($isOfficialAdmin): ?>
      <button class="snav-item" data-tab="officials"><i class="fa-solid fa-user-shield"></i> <span class="nav-text">Manage Officials</span></button>
      <?php endif; ?>
      <button class="snav-item" data-tab="auditlog"><i class="fa-solid fa-scroll"></i> <span class="nav-text">Audit Log</span></button>
      <div class="nav-group-label">Account</div>
      <button class="snav-item" data-tab="profile"><i class="fa-solid fa-user"></i> <span class="nav-text">My Profile</span></button>
    </nav>
    <div class="sidebar-footer">
      <a href="../logout.php" class="snav-item logout-item"><i class="fa-solid fa-arrow-left-from-bracket"></i> <span class="nav-text">Back to Home</span></a>
    </div>
  </aside>

  <!-- ═══ MAIN ═══ -->
  <main class="main-area">
    <header class="topbar">
      <div class="topbar-left">
        <div class="door-plaque door-plaque-dashboard" aria-live="polite">
          <i class="fa-solid fa-house" id="sectionPlaqueIcon" aria-hidden="true"></i>
          <span id="sectionPlaque">Office of the Barangay Captain</span>
        </div>
      </div>
      <div class="topbar-right">
        <div class="official-chip"><i class="fa-solid fa-shield-halved"></i> Official Access</div>
        <div class="topbar-user">
          <?php if ($officialPhotoUrl !== ''): ?>
            <img class="tu-avatar official-header-photo" src="<?= e($officialPhotoUrl) ?>" alt="Profile photo" />
          <?php else: ?>
            <div class="tu-avatar"><?= e($officialInitials) ?></div>
          <?php endif; ?>
          <span><?= e($officialName !== '' ? $officialName : 'Official') ?></span>
          <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i></a>
        </div>
      </div>
    </header>

    <div class="content-area">

      <!-- ─── DASHBOARD ─── -->
      <div class="tab-panel active" id="tab-dashboard">
        <div class="dash-head">
          <h2>Dashboard</h2>
          <label class="dash-range">
            <span>Range</span>
            <select id="dashRange" class="form-input" aria-label="Dashboard range">
              <option value="6m" selected>Last 6 months</option>
              <option value="12m">Last 12 months</option>
              <option value="ytd">This year</option>
            </select>
          </label>
        </div>
        <div class="stat-cards" id="dashStats"></div>
        <div class="dash-charts">
          <div class="card dash-card dash-chart-card is-wide">
            <div class="dc-head"><h3><i class="fa-solid fa-chart-line"></i> Requests &amp; Complaints per Month</h3></div>
            <div class="dash-chart-box"><canvas id="chartTrend" role="img" aria-label="Line chart of document requests and complaints filed per month"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-2">
            <div class="dc-head"><h3><i class="fa-solid fa-file-lines"></i> Document Requests — Current Status</h3></div>
            <div class="dash-chart-box"><canvas id="chartRequestStatus" role="img" aria-label="Doughnut chart of document requests by current status"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-2">
            <div class="dc-head"><h3><i class="fa-solid fa-flag"></i> Complaints — Current Status</h3></div>
            <div class="dash-chart-box"><canvas id="chartComplaintStatus" role="img" aria-label="Doughnut chart of complaints by current status"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-2">
            <div class="dc-head"><h3><i class="fa-solid fa-house"></i> Households by Poverty Status (MPI)</h3></div>
            <div class="dash-chart-box"><canvas id="chartHouseholdMpi" role="img" aria-label="Pie chart of households by MPI poverty status"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-3">
            <div class="dc-head"><h3><i class="fa-solid fa-file-signature"></i> Requests by Document Type</h3></div>
            <p class="dash-chart-sub">Filed in selected range</p>
            <div class="dash-chart-box"><canvas id="chartRequestsByType" role="img" aria-label="Bar chart of document requests by type"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-3">
            <div class="dc-head"><h3><i class="fa-solid fa-triangle-exclamation"></i> Complaints by Type</h3></div>
            <p class="dash-chart-sub">Filed in selected range</p>
            <div class="dash-chart-box"><canvas id="chartComplaintsByType" role="img" aria-label="Bar chart of complaints by type"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-2">
            <div class="dc-head"><h3><i class="fa-solid fa-people-group"></i> Residents by Age Group</h3></div>
            <div class="dash-chart-box"><canvas id="chartAgeGroups" role="img" aria-label="Bar chart of active residents by age group"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-2">
            <div class="dc-head"><h3><i class="fa-solid fa-map-location-dot"></i> Residents by Purok</h3></div>
            <div class="dash-chart-box"><canvas id="chartPurok" role="img" aria-label="Bar chart of active residents by purok"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-2">
            <div class="dc-head"><h3><i class="fa-solid fa-hand-holding-heart"></i> Vulnerable Sectors</h3></div>
            <p class="dash-chart-sub">A resident may belong to more than one</p>
            <div class="dash-chart-box"><canvas id="chartSectors" role="img" aria-label="Bar chart of active residents per vulnerable sector"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card is-wide">
            <div class="dc-head"><h3><i class="fa-solid fa-peso-sign"></i> Collections per Month</h3></div>
            <p class="dash-chart-sub">Document fees by completion month · complaint fees by settlement date</p>
            <div class="dash-chart-box"><canvas id="chartCollections" role="img" aria-label="Stacked bar chart of fees collected per month"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card dash-chart-card span-3">
            <div class="dc-head"><h3><i class="fa-solid fa-handshake"></i> Mediation Outcomes</h3></div>
            <p class="dash-chart-sub">Sessions in selected range</p>
            <div class="dash-chart-box"><canvas id="chartMediationOutcomes" role="img" aria-label="Doughnut chart of mediation session outcomes"></canvas></div>
            <p class="dash-chart-empty" hidden>No data yet.</p>
          </div>
          <div class="card dash-card span-3">
            <div class="dc-head"><h3><i class="fa-solid fa-calendar-days"></i> Upcoming Mediations (next 7 days)</h3></div>
            <div id="dashUpcomingMediations"></div>
          </div>
        </div>
        <div class="dash-row">
          <div class="card dash-card">
            <div class="dc-head">
              <h3><i class="fa-solid fa-file-clock"></i> Pending Document Requests</h3>
              <button class="dc-more-btn" onclick="switchTab('requests')">View all →</button>
            </div>
            <div id="dashPendingReqs"></div>
          </div>
          <div class="card dash-card">
            <div class="dc-head">
              <h3><i class="fa-solid fa-triangle-exclamation"></i> Open Complaints</h3>
              <button class="dc-more-btn" onclick="switchTab('complaints')">View all →</button>
            </div>
            <div id="dashOpenComplaints"></div>
          </div>
        </div>
        <div class="card recent-activity-card">
          <div class="dc-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Recent System Activity</h3></div>
          <div id="dashActivity"></div>
        </div>
      </div>

      <!-- ─── DOCUMENT PRICES ─── -->
      <div class="tab-panel" id="tab-documentprices">
        <div class="tab-header"><h2>Document Prices</h2></div>
        <div class="card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-tags"></i> Document Prices</h3>
              <p>Set the unit price charged per print. Default is ₱20.00.</p>
            </div>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Document</th><th>Price</th><th>Last Updated</th><th class="action-column">Action</th></tr></thead>
              <tbody id="docPricesBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── DOCUMENT TEMPLATES ─── -->
      <div class="tab-panel" id="tab-documents">
        <div class="tab-header"><h2>Document Templates</h2></div>
        <div class="card doctpl-signatory-card">
          <div class="doctpl-card-head">
            <div>
              <h3><i class="fa-solid fa-file-signature"></i> Document Signatory</h3>
              <p>Printed on newly generated documents.</p>
            </div>
          </div>
          <form id="doctplSignatoryForm" class="doctpl-signatory-form" novalidate>
            <label class="doctpl-field">
              <span>Captain name</span>
              <input id="doctplSignatoryName" name="captain_name" type="text" maxlength="80" autocomplete="off" required />
            </label>
            <div class="doctpl-signatory-preview" aria-live="polite">
              <strong id="doctplSignatoryPreviewName">Hon. —</strong>
              <span id="doctplSignatoryPreviewTitle">Barangay Captain</span>
            </div>
            <button type="submit" class="btn-primary-action" id="doctplSignatorySave">Save</button>
          </form>
          <p class="doctpl-note" id="doctplSignatoryNote" hidden>Only the Barangay Captain can change this.</p>
          <p class="doctpl-feedback" id="doctplSignatoryFeedback" role="status" aria-live="polite"></p>
        </div>
        <div class="card">
          <div class="doctpl-card-head">
            <div>
              <h3><i class="fa-solid fa-layer-group"></i> Template Versions</h3>
              <p>Upload, test, activate, download, or archive document templates.</p>
            </div>
            <button type="button" class="btn-primary-action" id="doctplAddTypeBtn" data-action="add-type"><i class="fa-solid fa-plus"></i> Add document type</button>
          </div>
          <div class="table-wrap">
            <table class="data-table doctpl-table">
              <thead><tr><th>Document</th><th>Status</th><th>Active version</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="doctplTypesBody">
                <tr><td colspan="4" class="registry-empty">Loading document templates...</td></tr>
              </tbody>
            </table>
          </div>
          <p class="doctpl-feedback" id="doctplListFeedback" role="status" aria-live="polite"></p>
        </div>
      </div>

      <!-- ─── RESIDENTS ─── -->
      <div class="tab-panel" id="tab-residents">
        <div class="tab-header">
          <h2>Resident</h2>
          <button class="btn-primary-action" id="addResidentBtn"><i class="fa-solid fa-plus"></i> Add Resident</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="residentFormCard">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3 id="residentFormTitle"><i class="fa-solid fa-user-plus"></i> Add New Resident</h3>
              <button class="close-card-btn" id="closeResidentForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <!-- Wizard progress -->
            <div class="wizard-progress" id="residentWizardProgress">
              <div class="wz-step" data-step="1"><span class="wz-dot">1</span><span class="wz-label">Account</span></div>
              <div class="wz-step" data-step="2"><span class="wz-dot">2</span><span class="wz-label">Household</span></div>
              <div class="wz-step" data-step="3"><span class="wz-dot">3</span><span class="wz-label">Profiling</span></div>
            </div>

            <form id="residentFormEl" data-mode="create">
            <input type="hidden" name="resident_id" />

            <!-- ── Step 1: Account ── -->
            <div class="wizard-step" data-step="1">
            <div class="form-2col">
              <div class="field"><label>First Name</label><input type="text" name="fname" class="form-input" required /></div>
              <div class="field"><label>Last Name</label><input type="text" name="lname" class="form-input" required /></div>
            </div>
            <div class="form-2col">
              <div class="field"><label>Contact</label><input type="text" name="contact" class="form-input" required placeholder="+639171234567" /><small style="color:var(--text-3);font-size:11px">Philippine mobile number, format +639XXXXXXXXX</small></div>
              <div class="field"><label>Username</label><input type="text" name="username" class="form-input" required /></div>
            </div>
            <div class="field"><label>Address</label><input type="text" name="address" class="form-input" required /></div>
            <div class="field" style="margin-top:12px;"><label id="residentPasswordLabel">Temporary Password</label><input type="password" name="password" class="form-input" required /></div>
            </div>

            <!-- ── Step 2: Household ── -->
            <div class="wizard-step" data-step="2" hidden>
              <p class="wz-help">How should this resident be linked to a household?</p>
              <div class="wz-household-choice">
                <label class="wz-choice">
                  <input type="radio" name="household_mode" value="new" checked />
                  <span><strong>Create a new household</strong><small>This resident becomes the household head.</small></span>
                </label>
                <label class="wz-choice">
                  <input type="radio" name="household_mode" value="link" />
                  <span><strong>Link to an existing household</strong><small>Choose a household from the list below.</small></span>
                </label>
              </div>

              <div class="wz-household-pane" data-pane="new">
                <div class="form-2col">
                  <div class="field"><label>Household Purok / Zone</label>
                    <select name="household_purok_zone" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Purok 1">Purok 1</option>
                      <option value="Purok 2">Purok 2</option>
                      <option value="Purok 3">Purok 3</option>
                      <option value="Purok 4">Purok 4</option>
                      <option value="Purok 5">Purok 5</option>
                    </select>
                  </div>
                  <div class="field" id="residentHouseholdSizeField"><label>Household Size</label><input type="number" name="household_size" class="form-input" min="1" value="1" /></div>
                </div>
                <div class="field" style="margin-top:8px;"><label>Household Address <span class="opt-label">(defaults to resident address)</span></label><input type="text" name="household_address" class="form-input" /></div>
              </div>

              <div class="wz-household-pane" data-pane="link" hidden>
                <div class="field"><label>Existing Household</label>
                  <select name="household_id" class="form-input" id="residentHouseholdSelect">
                    <option value="">— Select household —</option>
                  </select>
                </div>
                <div class="field" style="margin-top:8px;"><label>Relationship to Head</label><input type="text" name="relationship_to_household_head" class="form-input" placeholder="e.g. Spouse, Son, Daughter" /></div>
              </div>
            </div>

            <!-- ── Step 3: Profiling ── -->
            <div class="wizard-step" data-step="3" hidden>
              <div class="wz-profiling-choice" id="residentProfilingChoice">
                <p class="wz-help">Complete the resident's profiling information now, or defer it.</p>
                <div class="wz-choice-row">
                  <label class="wz-choice">
                    <input type="radio" name="profile_now" value="1" checked />
                    <span><strong>Complete Now</strong><small>Fill in demographics and livelihood details.</small></span>
                  </label>
                  <label class="wz-choice">
                    <input type="radio" name="profile_now" value="0" />
                    <span><strong>Later</strong><small>Keep the resident unprofiled for now.</small></span>
                  </label>
                </div>
              </div>

              <fieldset class="profiling-group" data-profile-fields>
                <legend>Demographics</legend>
                <div class="form-2col">
                  <div class="field"><label>Birthdate</label><input type="date" name="birthdate" class="form-input" /></div>
                  <div class="field"><label>Civil Status</label>
                    <select name="civil_status" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Single">Single</option>
                      <option value="Married">Married</option>
                      <option value="Widowed">Widowed</option>
                      <option value="Separated">Separated</option>
                    </select>
                  </div>
                </div>
                <div class="field" style="margin-top:8px;"><label>Purok / Zone</label>
                  <select name="purok_zone" class="form-input">
                    <option value="">— Select —</option>
                    <option value="Purok 1">Purok 1</option>
                    <option value="Purok 2">Purok 2</option>
                    <option value="Purok 3">Purok 3</option>
                    <option value="Purok 4">Purok 4</option>
                    <option value="Purok 5">Purok 5</option>
                  </select>
                </div>
                <div id="residentHouseholdSizeSlot"></div>
                <div class="form-2col profiling-checkboxes">
                  <label class="checkbox-label"><input type="checkbox" name="is_solo_parent" value="1" /> Solo Parent</label>
                  <label class="checkbox-label"><input type="checkbox" name="is_pwd" value="1" /> Person with Disability (PWD)</label>
                  <label class="checkbox-label"><input type="checkbox" name="is_4ps_member" value="1" /> 4Ps Member</label>
                </div>
              </fieldset>

              <fieldset class="profiling-group" data-profile-fields>
                <legend>Livelihood &amp; Skills</legend>
                <div class="form-2col">
                  <div class="field"><label>Educational Attainment</label>
                    <select name="educational_attainment" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Elementary">Elementary</option>
                      <option value="High School">High School</option>
                      <option value="Vocational">Vocational</option>
                      <option value="College">College</option>
                      <option value="Postgraduate">Postgraduate</option>
                    </select>
                  </div>
                  <div class="field"><label>Employment Status</label>
                    <select name="employment_status" class="form-input">
                      <option value="">— Select —</option>
                      <option value="Employed">Employed</option>
                      <option value="Unemployed">Unemployed</option>
                      <option value="Self-Employed">Self-Employed</option>
                      <option value="Student">Student</option>
                      <option value="Retired">Retired</option>
                    </select>
                  </div>
                </div>
                <div class="field" style="margin-top:8px;"><label>Occupation</label><input type="text" name="occupation" class="form-input" placeholder="e.g. Farmer, Teacher" /></div>
                <div class="field" style="margin-top:8px;"><label>Work Availability</label>
                  <select name="work_availability" class="form-input">
                    <option value="">— Select —</option>
                    <option value="Full-time">Full-time</option>
                    <option value="Part-time">Part-time</option>
                    <option value="Seasonal">Seasonal</option>
                    <option value="Not looking">Not looking</option>
                  </select>
                </div>
                <div class="field" style="margin-top:8px;"><label>Training / Certifications</label><textarea name="training_certifications" class="form-input" rows="2" placeholder="e.g. TESDA NC II - Welding"></textarea></div>
              </fieldset>
            </div>

            <div class="form-actions wizard-actions">
              <button type="button" class="btn-cancel-form" id="cancelResidentForm">Cancel</button>
              <span class="wizard-spacer"></span>
              <button type="button" class="btn-cancel-form" id="residentWizardBack" hidden><i class="fa-solid fa-arrow-left"></i> Back</button>
              <button type="button" class="btn-submit-form" id="residentWizardNext">Next <i class="fa-solid fa-arrow-right"></i></button>
              <button type="submit" class="btn-submit-form" id="residentFormSubmitBtn" hidden>Save Resident</button>
            </div>
            </form>
          </div>
        </div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="residentSearch" placeholder="Search residents…" />
            <select class="table-filter" id="residentStatusFilter">
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table" id="residentsTable">
              <thead><tr><th>Resident ID</th><th>Full Name</th><th>Address</th><th>Contact</th><th>Status</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="residentsBody"></tbody>
            </table>
          </div>
          <div class="registry-pagination" id="residentPager">
            <button type="button" class="btn-action view" id="residentPrevBtn"><i class="fa-solid fa-chevron-left"></i> Previous</button>
            <span id="residentPageLabel">Page 1 of 1</span>
            <button type="button" class="btn-action view" id="residentNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
      </div>

      <!-- ─── DOCUMENT REQUESTS ─── -->
      <div class="tab-panel" id="tab-requests">
        <div class="tab-header"><h2>Document Requests</h2></div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="reqSearch" placeholder="Search by name, ref #…" />
            <select class="table-filter" id="reqStatusFilter">
              <option value="all">All Status</option>
              <option value="pending">Pending</option>
              <option value="processing">Processing</option>
              <option value="ready">Ready</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Ref #</th><th>Resident</th><th>Document Type</th><th>Purpose</th><th>Date</th><th>Status</th><th class="action-column">Action</th></tr></thead>
              <tbody id="reqsBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── COMPLAINTS ─── -->
      <div class="tab-panel" id="tab-complaints">
        <div class="tab-header"><h2>Complaints &amp; Concerns</h2></div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="compSearch" placeholder="Search complaints…" />
            <select class="table-filter" id="compStatusFilter">
              <option value="all">All</option>
              <option value="open">Open</option>
              <option value="investigating">Investigating</option>
              <option value="resolved">Resolved</option>
            </select>
            <select class="table-filter" id="compTypeFilter" aria-label="Type">
              <option value="all">All types</option>
<?php foreach ($complaintTypeFilterOptions as $typeOption): ?>
              <option value="<?= e($typeOption['value']) ?>"><?= e($typeOption['label']) ?></option>
<?php endforeach; ?>
            </select>
            <button type="button" class="btn-primary-action walkin-new-btn" id="walkinNewBtn" data-wk-action="new-walkin"><i class="fa-solid fa-plus"></i> New walk-in complaint</button>
          </div>
          <div id="complaintsAdminList"></div>
        </div>
      </div>

      <!-- ─── COMPLAINT TYPES (complaint revision Phase 4) ─── -->
      <div class="tab-panel" id="tab-complainttypes">
        <div class="tab-header"><h2>Complaint Types</h2></div>
        <div class="card">
          <div class="doctpl-card-head">
            <div>
              <h3><i class="fa-solid fa-list-check"></i> Types and their extra fields</h3>
              <p>What residents can choose when they file a complaint. Nothing is deleted: deactivate instead.</p>
            </div>
            <button type="button" class="btn-primary-action" id="ctypeNewBtn" data-ct-action="new-type"><i class="fa-solid fa-plus"></i> New type</button>
          </div>
          <div class="table-wrap">
            <table class="data-table doctpl-table">
              <thead><tr><th>Name</th><th>Code</th><th>Respondent</th><th>Fee</th><th>Active fields</th><th>Complaints</th><th>Status</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="ctypeTypesBody">
                <tr><td colspan="7" class="registry-empty">Open this tab to load complaint types.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── MEDIATION CALENDAR (complaint revision Phase 6) ─── -->
      <div class="tab-panel" id="tab-hearings">
        <div class="tab-header"><h2>Mediation Calendar</h2></div>
        <section class="card hearing-needs" id="hearingNeedsWrap" hidden>
          <h3><i class="fa-solid fa-clipboard-check"></i> Needs recording <span id="hearingNeedsCount"></span></h3>
          <ul id="hearingNeedsList" class="hearing-needs-list"></ul>
        </section>
        <div class="card">
          <div class="table-toolbar hearing-toolbar">
            <div class="hearing-nav">
              <button type="button" class="btn-action" data-hr-action="prev" aria-label="Previous month">&lsaquo;</button>
              <button type="button" class="btn-action" data-hr-action="today">Today</button>
              <button type="button" class="btn-action" data-hr-action="next" aria-label="Next month">&rsaquo;</button>
            </div>
            <h3 id="hearingMonthLabel" class="hearing-month-label" aria-live="polite"></h3>
            <select class="table-filter" id="hearingStatusFilter" aria-label="Status">
              <option value="all">All statuses</option>
              <option value="scheduled">Scheduled</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
              <option value="rescheduled">Rescheduled</option>
            </select>
            <select class="table-filter" id="hearingOfficialFilter" aria-label="Presiding official">
              <option value="all">Everyone presiding</option>
            </select>
          </div>
          <p id="hearingCalendarError" class="ctype-error" role="alert" hidden></p>
          <div class="hearing-grid-wrap">
            <div class="hearing-weekdays" aria-hidden="true"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
            <div id="hearingMonthGrid" class="hearing-grid" role="grid" aria-labelledby="hearingMonthLabel"></div>
          </div>
          <p class="hearing-legend"><span class="hearing-chip is-scheduled">Scheduled</span> <span class="hearing-chip is-scheduled needs-recording">Needs recording</span> <span class="hearing-chip is-completed">Completed</span> <span class="hearing-chip is-cancelled">Cancelled</span> <span class="hearing-chip is-rescheduled">Rescheduled</span></p>
        </div>
      </div>

      <!-- ─── ANNOUNCEMENTS ─── -->
      <div class="tab-panel" id="tab-announcements">
        <div class="tab-header">
          <h2>Announcements</h2>
          <button class="btn-primary-action" id="newAnnBtn"><i class="fa-solid fa-plus"></i> Post Announcement</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="annForm">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3><i class="fa-solid fa-bullhorn"></i> New Announcement</h3>
              <button class="close-card-btn" id="closeAnnForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="annFormEl">
            <div class="form-2col">
              <div class="field"><label>Title</label><input type="text" class="form-input" placeholder="Announcement title" required /></div>
              <div class="field"><label>Category</label>
                <select class="form-input">
                  <option>General</option><option>Health</option><option>Environment</option><option>Safety</option><option>Event</option>
                </select>
              </div>
            </div>
            <div class="field" style="margin-top:12px;"><label>Content</label><textarea class="form-input" rows="5" placeholder="Write the announcement content here…" required></textarea></div>
            <div class="field" style="margin-top:12px;"><label>Expires On <span class="opt-label">(optional — leave blank for a permanent announcement)</span></label><input id="announcementExpiresAt" type="datetime-local" class="form-input" /></div>
            <?php if (getenv('HTTPSMS_API_KEY') && getenv('HTTPSMS_FROM_NUMBER')): ?>
            <div class="field" style="margin-top:8px;">
              <label>Send SMS Notification to Residents? <span class="opt-label">(via httpSMS)</span></label>
              <label class="toggle-label"><input type="checkbox" id="smsToggle" /> <span class="toggle-switch"></span> <span>Yes, send SMS to all registered residents</span></label>
            </div>
            <?php else: ?>
            <div class="field" style="margin-top:8px;">
              <small style="color:var(--text-3);font-size:12px">SMS notifications are disabled in this build.</small>
            </div>
            <?php endif; ?>
            <div class="form-actions"><button type="submit" class="btn-submit-form">Post Announcement</button><button type="button" class="btn-cancel-form" id="cancelAnnForm">Cancel</button></div>
            </form>
          </div>
        </div>
        <div class="card">
          <div id="announcementsAdminList"></div>
        </div>
      </div>

      

      <!-- ─── LOCAL SERVICES ─── -->
      <div class="tab-panel" id="tab-services">
        <div class="tab-header">
          <h2>Local Services Directory</h2>
          <button class="btn-primary-action" id="newServiceBtn"><i class="fa-solid fa-plus"></i> Add Service</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="serviceForm">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3><i class="fa-solid fa-store"></i> Local Service</h3>
              <button class="close-card-btn" id="closeServiceForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="serviceFormEl">
            <input type="hidden" name="id" />
            <div class="form-2col">
              <div class="field"><label>Service Name</label><input name="service_name" type="text" class="form-input" required /></div>
              <div class="field"><label>Category</label><input name="category" type="text" class="form-input" placeholder="e.g. Food, Retail, Services" required /></div>
            </div>
            <div class="form-2col" style="margin-top:12px;">
              <div class="field"><label>Contact Number</label><input name="contact_number" type="text" class="form-input" /></div>
              <div class="field"><label>Address</label><input name="address" type="text" class="form-input" /></div>
            </div>
            <div class="field" style="margin-top:12px;"><label>Operating Hours</label><input name="operating_hours" type="text" class="form-input" /></div>
            <div class="field" style="margin-top:12px;"><label>Description</label><textarea name="description" class="form-input" rows="4"></textarea></div>
            <div class="form-actions"><button type="submit" class="btn-submit-form">Save Service</button><button type="button" class="btn-cancel-form" id="cancelServiceForm">Cancel</button></div>
            </form>
          </div>
        </div>
        <div class="services-admin-grid" id="servicesAdminGrid"></div>
      </div>

      <?php if ($isOfficialAdmin): ?>
      <!-- ─── MANAGE OFFICIALS ─── -->
      <div class="tab-panel" id="tab-officials">
        <div class="tab-header">
          <h2>Manage Officials</h2>
          <button class="btn-primary-action" id="addOfficialBtn"><i class="fa-solid fa-plus"></i> Add Official</button>
        </div>
        <div class="modal-overlay form-modal-overlay" id="officialFormCard">
          <div class="modal-box form-card">
            <div class="form-card-header">
              <h3 id="officialFormTitle"><i class="fa-solid fa-user-plus"></i> Add New Official</h3>
              <button class="close-card-btn" id="closeOfficialForm"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="officialFormEl">
            <input type="hidden" name="official_id" />
            <div class="form-2col">
              <div class="field"><label>First Name</label><input type="text" name="fname" class="form-input" required /></div>
              <div class="field"><label>Last Name</label><input type="text" name="lname" class="form-input" required /></div>
            </div>
            <div class="form-2col">
              <div class="field"><label>Contact</label><input type="text" name="contact" class="form-input" required placeholder="+639171234567" /><small style="color:var(--text-3);font-size:11px">Philippine mobile number, format +639XXXXXXXXX</small></div>
              <div class="field"><label>Username</label><input type="text" name="username" class="form-input" required /></div>
            </div>
            <div class="field"><label>Address</label><input type="text" name="address" class="form-input" required /></div>
            <div class="form-2col" style="margin-top:12px;">
              <div class="field"><label>Position</label><input type="text" name="position" class="form-input" required /></div>
              <div class="field"><label>Role</label>
                <select name="role" class="form-input" required>
                  <option value="staff">Staff</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
            </div>
            <div class="field" style="margin-top:12px;"><label id="officialPasswordLabel">Temporary Password</label><input type="password" name="password" class="form-input" required /></div>
            <div class="form-actions">
              <button type="submit" class="btn-submit-form" id="officialFormSubmitBtn">Save Official</button>
              <button type="button" class="btn-cancel-form" id="cancelOfficialForm">Cancel</button>
            </div>
            </form>
          </div>
        </div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="officialSearch" placeholder="Search by name, username, position…" />
            <select class="table-filter" id="officialStatusFilter">
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table" id="officialsTable">
              <thead><tr><th>ID</th><th>Full Name</th><th>Username</th><th>Contact</th><th>Address</th><th>Position</th><th>Role</th><th>Status</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="officialsBody"></tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ─── AUDIT LOG ─── -->
      <div class="tab-panel" id="tab-auditlog">
        <div class="tab-header"><h2>Audit Log</h2></div>
        <div class="card">
          <div class="table-toolbar">
            <input type="text" class="table-search" id="auditSearch" placeholder="Search by user, action, or module…" />
            <select class="table-filter" id="auditActionFilter">
              <option value="all">All Actions</option>
            </select>
            <?php if ($isOfficialAdmin): ?>
            <select class="table-filter" id="auditOfficialFilter">
              <option value="all">All Staff/Admins</option>
            </select>
            <?php endif; ?>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Timestamp</th><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>IP Address</th></tr></thead>
              <tbody id="auditBody"></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ─── AI ANALYTICS ─── -->
      <div class="tab-panel" id="tab-aianalyst">
        <div class="tab-header"><h2><i class="fa-solid fa-brain"></i> AI Analytics</h2></div>
        <div class="ai-hub" id="aiHub">
          <button type="button" class="ai-tool-tile" data-ai-view="registry">
            <span class="ai-tile-ico" style="background:#DBEAFE;color:#1976D2"><i class="fa-solid fa-shield-halved"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Vulnerability Registry</span><span class="ai-tile-desc">Deterministic MPI ranking with AI commentary</span></span>
          </button>
          <button type="button" class="ai-tool-tile" data-ai-view="workforce">
            <span class="ai-tile-ico" style="background:#EDE9FE;color:#7C3AED"><i class="fa-solid fa-chart-column"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Workforce Insights</span><span class="ai-tile-desc">Employment and skill breakdowns</span></span>
          </button>
          <button type="button" class="ai-tool-tile" data-ai-view="docreports">
            <span class="ai-tile-ico" style="background:#DBEAFE;color:#1976D2"><i class="fa-solid fa-file-invoice-dollar"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Document Requests Report</span><span class="ai-tile-desc">Monthly volume, revenue &amp; AI commentary</span></span>
          </button>
          <button type="button" class="ai-tool-tile" data-ai-view="complaintreports">
            <span class="ai-tile-ico" style="background:#FEE2E2;color:#DC2626"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Complaints Report</span><span class="ai-tile-desc">Category, severity &amp; AI commentary</span></span>
          </button>
          <button type="button" class="ai-tool-tile is-secondary" data-ai-view="echo">
            <span class="ai-tile-ico"><i class="fa-solid fa-plug"></i></span>
            <span class="ai-tile-text"><span class="ai-tile-title">Connectivity Test</span><span class="ai-tile-desc">Verify AI service is responding</span></span>
          </button>
        </div>
        <div class="ai-subview" data-ai-subview="registry" hidden>
        <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>

        <div class="registry-tabs" role="tablist" aria-label="Registry view">
          <button type="button" class="registry-tab active" data-registry-tab="vulnerability" role="tab" aria-selected="true">
            <i class="fa-solid fa-shield-halved"></i> Vulnerability Registry
          </button>
          <button type="button" class="registry-tab" data-registry-tab="household" role="tab" aria-selected="false">
            <i class="fa-solid fa-house-user"></i> Household Registry
          </button>
        </div>

        <div class="registry-pane" data-registry-pane="vulnerability">
        <div class="card vulnerability-registry-card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-shield-halved"></i> Vulnerability Registry</h3>
              <p>Household MPI status and deprivations from the household questionnaire. Gemini generates a plain-English commentary per household on demand; the deterministic MPI result is unchanged.</p>
            </div>
            <div class="registry-actions">
              <button id="aiAssessBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reports</button>
              <button id="clearAllReportsBtn" type="button" class="btn-primary-action">
                <i class="fa-solid fa-eraser"></i> Clear Commentaries
              </button>
              <button id="registryExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
            </div>
          </div>
          <p class="registry-note">Rate limit: 200 households per hour per official.</p>
          <div class="table-toolbar">
            <select id="vulnerabilityPurokFilter" class="table-filter" aria-label="Filter vulnerability registry by Purok">
              <option value="all">All Puroks</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table vulnerability-registry-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Purok</th>
                  <th>Household</th>
                  <th>Priority Status</th>
                  <th>Priority Indicators</th>
                  <th>AI Commentary</th>
                </tr>
              </thead>
              <tbody id="vulnerabilityRegistryBody"></tbody>
            </table>
          </div>
          <div class="registry-pagination" id="vulnerabilityRegistryPager">
            <button type="button" class="btn-action view" id="vulnerabilityPrevBtn"><i class="fa-solid fa-chevron-left"></i> Prev</button>
            <span id="vulnerabilityPageLabel">Page 1 of 1</span>
            <button type="button" class="btn-action view" id="vulnerabilityNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
        </div>

        <div class="registry-pane" data-registry-pane="household" hidden>
        <div class="card vulnerability-registry-card household-registry-card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-house-user"></i> Household Registry</h3>
              <p>One row per household. Households marked <strong>Unprofiled</strong> have no questionnaire data yet and cannot be assessed.</p>
            </div>
            <div class="registry-actions">
              <button id="householdReportsBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reports</button>
              <button id="clearAllHouseholdReportsBtn" type="button" class="btn-primary-action">
                <i class="fa-solid fa-eraser"></i> Clear Commentaries
              </button>
              <button id="householdSortBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-arrow-down-wide-short"></i> Organize by Poverty</button>
              <button id="householdExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
            </div>
          </div>
          <p class="registry-note">Rate limit: 200 households per hour per official.</p>
          <div class="table-toolbar">
            <select id="householdPurokFilter" class="table-filter" aria-label="Filter household registry by Purok">
              <option value="all">All Puroks</option>
            </select>
          </div>
          <div class="table-wrap">
            <table class="data-table vulnerability-registry-table">
              <thead><tr><th>Household</th><th>Head</th><th>Purok</th><th>Size</th><th>Profile Status</th><th>MPI</th><th>Income Status</th><th>AI Commentary</th><th class="action-column">Actions</th></tr></thead>
              <tbody id="householdRegistryBody"></tbody>
            </table>
          </div>
          <div class="registry-pagination" id="householdRegistryPager">
            <button type="button" class="btn-action view" id="householdPrevBtn"><i class="fa-solid fa-chevron-left"></i> Prev</button>
            <span id="householdPageLabel">Page 1 of 1</span>
            <button type="button" class="btn-action view" id="householdNextBtn">Next <i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
        </div>
        </div>
        <!-- ── Document Requests Report ── -->
        <div class="ai-subview" data-ai-subview="docreports" hidden>
          <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>

          <div class="card" id="docReportsAiCard" style="margin-bottom:1.5rem;border-left:4px solid #1976D2">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Commentary</h3>
                <p>Interpretation of this report. This section is visible on screen only — it is excluded from CSV exports and printed reports.</p>
              </div>
              <div class="registry-actions">
                <button id="docReportsAiBtn" type="button" class="btn-primary-action">
                  <i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Commentary
                </button>
              </div>
            </div>
            <div id="docReportsAiOutput" class="ai-echo-response" role="status" aria-live="polite">No commentary generated yet.</div>
          </div>

          <div class="card" style="margin-bottom:1.5rem">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-file-invoice-dollar"></i> Document Requests — Monthly Summary</h3>
                <p>Requests issued per month with gross billed and total collected.</p>
              </div>
              <div class="registry-actions">
                <button id="docReportsRefreshBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-rotate"></i> Refresh</button>
                <button id="docReportsExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
              </div>
            </div>
            <div class="stat-cards is-compact" id="docRequestStats"></div>
            <div class="report-status-strip" id="docRequestStatusCounts" aria-label="Requests by status"></div>
            <div id="docRequestReportNotes"></div>
            <div class="table-wrap" style="margin-top:12px">
              <table class="data-table">
                <thead><tr>
                  <th>Month</th><th>Requests</th><th>Cancelled</th><th>Copies</th>
                  <th>Gross</th><th>Collected</th><th>Pending</th><th>Waived</th>
                </tr></thead>
                <tbody id="docRequestMonthlyBody"></tbody>
              </table>
            </div>
            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">By Document Type</h4>
            <div class="table-wrap">
              <table class="data-table">
                <thead><tr>
                  <th>Document</th><th>Requests</th><th>Cancelled</th><th>Copies</th><th>Gross</th><th>Collected</th>
                </tr></thead>
                <tbody id="docRequestTypeBody"></tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ── Complaints Report ── -->
        <div class="ai-subview" data-ai-subview="complaintreports" hidden>
          <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>

          <div class="card" id="complaintReportsAiCard" style="margin-bottom:1.5rem;border-left:4px solid #DC2626">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Commentary</h3>
                <p>Interpretation of this report. This section is visible on screen only — it is excluded from CSV exports and printed reports.</p>
              </div>
              <div class="registry-actions">
                <button id="complaintReportsAiBtn" type="button" class="btn-primary-action">
                  <i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Commentary
                </button>
              </div>
            </div>
            <div id="complaintReportsAiOutput" class="ai-echo-response" role="status" aria-live="polite">No commentary generated yet.</div>
          </div>

          <div class="card">
            <div class="registry-header">
              <div>
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Complaints — Summary &amp; Ranking</h3>
                <p>Counts by category, severity, and monthly ranking of the most common type.</p>
              </div>
              <div class="registry-actions">
                <button id="complaintReportsRefreshBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-rotate"></i> Refresh</button>
                <button id="complaintReportsExportBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-file-csv"></i> Export as CSV</button>
              </div>
            </div>
            <div class="stat-cards is-compact" id="complaintStats"></div>

            <div class="dash-row" style="margin-top:1rem">
              <div class="card dash-card">
                <div class="dc-head"><h3>By Category</h3></div>
                <div id="complaintByCategory"></div>
              </div>
              <div class="card dash-card">
                <div class="dc-head"><h3>By Severity</h3></div>
                <div id="complaintBySeverity"></div>
              </div>
            </div>

            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">Category × Severity</h4>
            <div class="table-wrap">
              <table class="data-table">
                <thead><tr>
                  <th>Category</th><th>Low</th><th>Medium</th><th>High</th><th>Critical</th><th>Total</th>
                </tr></thead>
                <tbody id="complaintCrossBody"></tbody>
              </table>
            </div>

            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">Mediation</h4>
            <div id="complaintMediation"></div>

            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">Fees</h4>
            <div id="complaintBilling"></div>

            <div class="dash-row" style="margin-top:1rem">
              <div class="card dash-card">
                <div class="dc-head"><h3>Filing</h3></div>
                <div id="complaintFiling"></div>
              </div>
              <div class="card dash-card">
                <div class="dc-head"><h3>People involved</h3></div>
                <div id="complaintParties"></div>
              </div>
            </div>

            <h4 style="margin:18px 0 8px;color:var(--text-2);font-size:.95rem">Top Complaint Types — Monthly Ranking</h4>
            <div class="table-wrap">
              <table class="data-table">
                <thead><tr><th>Month</th><th>Rank</th><th>Category</th><th>Count</th></tr></thead>
                <tbody id="complaintRankingBody"></tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="ai-subview" data-ai-subview="workforce" hidden>
        <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>
        <div class="card vulnerability-registry-card workforce-card">
          <div class="registry-header">
            <div>
              <h3><i class="fa-solid fa-chart-column"></i> Workforce Insights</h3>
              <p>Employment, education, availability and skill-category breakdowns of active residents. Gemini classifies each resident's occupation and skills into a fixed category once; edits to those fields reset the category.</p>
            </div>
            <div class="registry-actions">
              <button id="workforceClassifyBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-tags"></i> Classify Workforce</button>
            </div>
          </div>
          <p class="registry-note">Rate limit: 200 households per hour per official.</p>
          <div class="table-toolbar">
            <select id="workforcePurokFilter" class="table-filter" aria-label="Filter workforce insights by Purok">
              <option value="all">All Puroks</option>
            </select>
          </div>
          <div id="workforceStatus" class="ai-echo-response" role="status" aria-live="polite" hidden></div>
          <div id="workforceStats" class="stat-cards workforce-stats"></div>
          <div id="workforceCharts" class="workforce-charts"></div>
        </div>
        </div>
        <div class="ai-subview" data-ai-subview="echo" hidden>
        <button type="button" class="ai-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to AI Tools</button>
        <div class="card ai-echo-test-card">
          <p class="ai-echo-test-note">Temporary connectivity test. Full AI features are still in development.</p>
          <label for="aiEchoPrompt">Test prompt</label>
          <div class="ai-echo-test-controls">
            <input id="aiEchoPrompt" type="text" value="Say hello and confirm you are working correctly." maxlength="2000">
            <button id="aiEchoSendBtn" type="button" class="btn-primary-action"><i class="fa-solid fa-paper-plane"></i> Send Test Prompt</button>
          </div>
          <div id="aiEchoResponse" class="ai-echo-response" role="status" aria-live="polite">No response yet.</div>
        </div>
        </div>
      </div>

      <!-- ─── MY PROFILE ─── -->
      <div class="tab-panel" id="tab-profile">
        <div class="tab-header"><h2>My Profile</h2></div>
        <div class="profile-grid official-profile-grid">
          <div class="card profile-card">
            <?php if ($officialPhotoUrl !== ''): ?>
              <img class="profile-avatar-big profile-photo" src="<?= e($officialPhotoUrl) ?>" alt="Profile photo" />
            <?php else: ?>
              <div class="profile-avatar-big"><?= e($officialInitials) ?></div>
            <?php endif; ?>
            <div class="profile-name-big"><?= e($officialName !== '' ? $officialName : 'Official') ?></div>
            <div class="profile-role-badge official-profile-role"><i class="fa-solid fa-shield-halved"></i> <?= e($officialSubtitle) ?></div>
            <form id="officialProfilePhotoForm" class="profile-photo-form" enctype="multipart/form-data">
              <label for="officialProfilePhotoInput" class="btn-submit-form">Change Photo</label>
              <input id="officialProfilePhotoInput" type="file" name="photo" accept="image/jpeg,image/png" hidden />
              <small>JPEG or PNG, maximum 50MB</small>
            </form>
          </div>
          <div class="card">
            <h3 class="card-section-title">Official Information</h3>
            <div class="profile-form">
              <div class="field"><label>Name</label><div class="profile-readonly"><?= e($officialName) ?></div></div>
              <div class="field"><label>Position</label><div class="profile-readonly"><?= e($officialPosition !== '' ? $officialPosition : '—') ?></div></div>
              <div class="field"><label>Role</label><div class="profile-readonly"><?= e($officialRoleLabel) ?></div></div>
              <div class="field"><label>Username</label><div class="profile-readonly"><?= e($officialUsername !== '' ? $officialUsername : '—') ?></div></div>
              <div class="field"><label>Contact</label><div class="profile-readonly"><?= e($officialContact !== '' ? $officialContact : '—') ?></div></div>
              <div class="field"><label>Address</label><div class="profile-readonly"><?= e($officialAddress !== '' ? $officialAddress : '—') ?></div></div>
              <div class="field"><label>Account Status</label><div class="profile-readonly"><?= e($officialStatus !== '' ? ucfirst($officialStatus) : '—') ?></div></div>
              <div class="field"><label>Account ID</label><div class="profile-readonly"><?= e((string)$_SESSION['official_id']) ?></div></div>
              <div class="field"><label>Account Created</label><div class="profile-readonly"><?= e($officialCreatedAt !== '' ? date('F j, Y', strtotime($officialCreatedAt)) : '—') ?></div></div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </main>

  <!-- Action Modal -->
  <div class="modal-overlay" id="actionModal">
    <div class="modal-box" id="actionModalBox"></div>
  </div>

  <!-- Document Generation Modal -->
  <div class="modal-overlay" id="docGenModal">
    <div class="modal-box docgen-modal-box">
      <div class="docgen-modal-head">
        <h3 id="docGenTitle" class="docgen-modal-title">Generate Document</h3>
        <button type="button" class="close-card-btn" id="closeDocGenModal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="docGenMeta" class="docgen-modal-meta"></div>
      <div class="docgen-modal-grid">
        <div class="docgen-preview-col">
          <h4 class="docgen-preview-title">PDF Preview</h4>
          <div id="docGenPreviewEmpty" class="docgen-preview-empty">Generate a document to see its PDF preview here.</div>
          <div id="docGenPreviewWrap" class="docgen-preview-wrap" style="display:none;">
            <iframe id="docGenPreviewFrame" title="Generated document preview" class="docgen-preview-frame"></iframe>
          </div>
        </div>
        <div class="docgen-form-col">
          <div class="form-2col" id="docGenFields"></div>
          <div class="docgen-actions">
            <button type="button" class="btn-cancel-form" id="cancelDocGenModal">Close</button>
            <button type="button" class="btn-submit-form" id="generateDocBtn">Generate &amp; Preview</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Document Template Modals -->
  <div class="modal-overlay doctpl-modal" id="doctplUploadModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box" role="dialog" aria-modal="true" aria-labelledby="doctplUploadTitle">
      <div class="doctpl-modal-head">
        <h3 id="doctplUploadTitle">Upload New Version</h3>
        <button type="button" class="close-card-btn" data-action="doctpl-close" aria-label="Close upload modal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <p class="doctpl-modal-type" id="doctplUploadType"></p>
      <form id="doctplUploadForm" novalidate>
        <input type="hidden" id="doctplUploadTypeId" name="document_type_id" />
        <label class="doctpl-field"><span>DOCX file</span><input id="doctplUploadFile" name="file" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required /></label>
        <label class="doctpl-field"><span>Notes</span><textarea id="doctplUploadNotes" name="notes" maxlength="255" rows="3"></textarea></label>
        <div id="doctplUploadResult" class="doctpl-result" role="status" aria-live="polite"></div>
        <div class="doctpl-modal-actions">
          <button type="button" class="btn-cancel-form" data-action="doctpl-close">Cancel</button>
          <button type="submit" class="btn-submit-form" id="doctplUploadSubmit">Upload and Check</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay doctpl-modal" id="doctplHistoryModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box doctpl-history-box" role="dialog" aria-modal="true" aria-labelledby="doctplHistoryTitle">
      <div class="doctpl-modal-head">
        <h3 id="doctplHistoryTitle">Version History</h3>
        <button type="button" class="close-card-btn" data-action="doctpl-close" aria-label="Close history modal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="table-wrap"><table class="data-table doctpl-table"><thead><tr><th>Version</th><th>Status</th><th>Uploaded by</th><th>Date</th><th>Actions</th></tr></thead><tbody id="doctplHistoryBody"></tbody></table></div>
      <div class="doctpl-modal-actions"><button type="button" class="btn-cancel-form" data-action="doctpl-close">Close</button></div>
    </div>
  </div>

  <!-- Phase 4B/4C: review AI placeholder suggestions and edit the text of one draft version. Filled by js/document-templates.js. -->
  <div class="modal-overlay doctpl-modal" id="doctplReviewModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box doctpl-review-box" role="dialog" aria-modal="true" aria-labelledby="doctplReviewTitle">
      <div class="doctpl-modal-head">
        <h3 id="doctplReviewTitle">Review Template</h3>
        <button type="button" class="close-card-btn" data-action="doctpl-close" aria-label="Close review"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="doctplReviewBanners" class="doctpl-review-banners"></div>
      <section class="doctpl-review-section" aria-labelledby="doctplReviewAnalyzeHeading">
        <h4 id="doctplReviewAnalyzeHeading">Analyze</h4>
        <p class="doctpl-note">The AI only suggests placeholders. Nothing changes until you apply, and applying always creates a new draft version.</p>
        <p class="doctpl-note" id="doctplReviewAiOff" hidden>AI analysis is not configured. An existing analysis can still be reviewed and applied, and you can edit the document text below.</p>
        <label class="doctpl-review-ack">
          <input type="checkbox" id="doctplReviewAck" autocomplete="off" />
          <span>I understand the document text will be sent to Google Gemini for analysis.</span>
        </label>
        <div class="doctpl-review-row">
          <button type="button" class="btn-submit-form" id="doctplReviewAnalyze" data-review-action="analyze" disabled>Analyze with AI</button>
        </div>
        <p class="doctpl-feedback" id="doctplReviewAnalyzeStatus" role="status" aria-live="polite"></p>
      </section>
      <section class="doctpl-review-section" id="doctplReviewSuggestions" aria-labelledby="doctplReviewListHeading" hidden>
        <h4 id="doctplReviewListHeading">Suggestions</h4>
        <p class="doctpl-note" id="doctplReviewMeta"></p>
        <div class="doctpl-review-toolbar">
          <button type="button" class="btn-action view" id="doctplReviewSelectAll" data-review-action="select-all">Select all</button>
          <button type="button" class="btn-action view" id="doctplReviewClearAll" data-review-action="clear-all">Clear all</button>
          <span class="doctpl-note" id="doctplReviewCounter">0 of 0 selected</span>
        </div>
        <ul class="doctpl-review-list" id="doctplReviewList"></ul>
        <div class="doctpl-review-row">
          <button type="button" class="btn-submit-form" id="doctplReviewApply" data-review-action="apply" disabled>Apply selected</button>
        </div>
        <p class="doctpl-note doctpl-manual-conflict" id="doctplReviewApplyConflict" hidden></p>
        <p class="doctpl-feedback" id="doctplReviewApplyStatus" role="status" aria-live="polite"></p>
      </section>
      <section class="doctpl-review-section" id="doctplManualSection" aria-labelledby="doctplManualHeading">
        <h4 id="doctplManualHeading">Edit document text</h4>
        <p class="doctpl-note">Select words inside one paragraph to turn them into a placeholder or change the wording. Click a placeholder to turn it back into text. Saving always creates a new draft version; this version is not changed.</p>
        <div class="doctpl-manual-paragraphs" id="doctplManualParagraphs" aria-label="Document text (read-only)"></div>
        <p class="doctpl-feedback" id="doctplManualSelectionStatus" role="status" aria-live="polite"></p>
        <div class="doctpl-manual-tools" id="doctplManualSelectionTools" hidden>
          <p class="doctpl-manual-selected" id="doctplManualSelectionText"></p>
          <div class="doctpl-manual-tool-row">
            <label class="doctpl-manual-field"><span>Placeholder</span><select id="doctplManualKey"></select></label>
            <button type="button" class="btn-action view" id="doctplManualMakePlaceholder" data-review-action="manual-placeholder" disabled>Make placeholder</button>
          </div>
          <div class="doctpl-manual-tool-row">
            <label class="doctpl-manual-field"><span>New text</span><input type="text" id="doctplManualNewText" maxlength="500" autocomplete="off" /></label>
            <button type="button" class="btn-action view" id="doctplManualReplace" data-review-action="manual-replace" disabled>Edit text</button>
          </div>
        </div>
        <div class="doctpl-manual-tools" id="doctplManualChipTools" hidden>
          <p class="doctpl-manual-selected" id="doctplManualChipLabel"></p>
          <div class="doctpl-manual-tool-row">
            <label class="doctpl-manual-field"><span>Text to put in its place</span><input type="text" id="doctplManualChipText" maxlength="500" autocomplete="off" /></label>
            <button type="button" class="btn-action view" id="doctplManualRemove" data-review-action="manual-remove" disabled>Turn into text</button>
            <button type="button" class="btn-action view" data-review-action="manual-chip-cancel">Cancel</button>
          </div>
        </div>
        <div class="doctpl-review-toolbar">
          <span class="doctpl-note" id="doctplManualCounter">0 unsaved edit(s)</span>
        </div>
        <ul class="doctpl-review-list" id="doctplManualPending"></ul>
        <div class="doctpl-review-row">
          <button type="button" class="btn-submit-form" id="doctplManualSave" data-review-action="manual-save" disabled>Save manual edits</button>
        </div>
        <p class="doctpl-note doctpl-manual-conflict" id="doctplManualConflict" hidden></p>
        <p class="doctpl-feedback" id="doctplManualStatus" role="status" aria-live="polite"></p>
      </section>
      <section class="doctpl-review-result" id="doctplReviewResult" aria-live="polite" hidden></section>
      <div class="doctpl-modal-actions"><button type="button" class="btn-cancel-form" data-action="doctpl-close">Close</button></div>
    </div>
  </div>

  <div class="modal-overlay doctpl-modal" id="doctplPreviewModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box doctpl-preview-box" role="dialog" aria-modal="true" aria-labelledby="doctplPreviewTitle">
      <div class="doctpl-modal-head">
        <h3 id="doctplPreviewTitle">Template Preview</h3>
        <button type="button" class="close-card-btn" data-action="doctpl-close" aria-label="Close preview modal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <p class="doctpl-preview-note">Preview uses fictional sample data.</p>
      <div id="doctplPreviewResult" class="doctpl-feedback" role="status" aria-live="polite"></div>
      <iframe id="doctplPreviewFrame" class="doctpl-preview-frame" title="Template PDF preview"></iframe>
      <div class="doctpl-modal-actions"><button type="button" class="btn-cancel-form" data-action="doctpl-close">Close</button></div>
    </div>
  </div>

  <div class="modal-overlay doctpl-modal" id="doctplTypeModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box doctpl-type-box" role="dialog" aria-modal="true" aria-labelledby="doctplTypeTitle">
      <div class="doctpl-modal-head">
        <h3 id="doctplTypeTitle">Add Document Type</h3>
        <button type="button" class="close-card-btn" data-action="doctpl-close" aria-label="Close add document type modal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <p class="doctpl-note">The new type stays hidden from residents until you upload a template and activate it.</p>
      <form id="doctplTypeForm" novalidate>
        <div class="doctpl-type-grid">
          <label class="doctpl-field doctpl-type-wide"><span>Document name</span><input id="doctplTypeName" name="name" type="text" maxlength="100" autocomplete="off" required /></label>
          <label class="doctpl-field doctpl-type-wide"><span>Code</span><input id="doctplTypeCode" name="code" type="text" maxlength="50" autocomplete="off" placeholder="auto" spellcheck="false" /></label>
          <label class="doctpl-field"><span>Fee (PHP)</span><input id="doctplTypeFee" name="fee" type="number" min="0" max="9999.99" step="0.01" value="0.00" required /></label>
          <label class="doctpl-field"><span>Processing days</span><input id="doctplTypeDays" name="processing_days" type="number" min="0" max="60" step="1" value="1" required /></label>
          <label class="doctpl-field doctpl-type-wide"><span>Requirements (shown to residents)</span><textarea id="doctplTypeRequirements" name="requirements" maxlength="1000" rows="3"></textarea></label>
        </div>
        <fieldset class="doctpl-type-fields">
          <legend>Placeholders</legend>
          <ul class="doctpl-type-checklist" id="doctplTypeFields"></ul>
          <p class="doctpl-note">Every required field must appear in the first DOCX you upload.</p>
        </fieldset>
        <div id="doctplTypeResult" class="doctpl-result" role="status" aria-live="polite"></div>
        <div class="doctpl-modal-actions">
          <button type="button" class="btn-cancel-form" data-action="doctpl-close">Cancel</button>
          <button type="submit" class="btn-submit-form" id="doctplTypeSubmit">Create</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay doctpl-modal" id="doctplConfirmModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box" role="dialog" aria-modal="true" aria-labelledby="doctplConfirmTitle">
      <div class="doctpl-modal-head">
        <h3 id="doctplConfirmTitle">Confirm action</h3>
        <button type="button" class="close-card-btn" data-action="doctpl-close" aria-label="Close confirmation modal"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <p id="doctplConfirmMessage" class="doctpl-confirm-message"></p>
      <div class="doctpl-modal-actions">
        <button type="button" class="btn-cancel-form" data-action="doctpl-close">Cancel</button>
        <button type="button" class="btn-submit-form" id="doctplConfirmSubmit">Confirm</button>
      </div>
    </div>
  </div>

  <!-- Complaint type modal (Phase 4). Listeners are attached by js/complaint-types.js. -->
  <div class="modal-overlay ctype-modal" id="ctypeTypeModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box ctype-modal-box" role="dialog" aria-modal="true" aria-labelledby="ctypeTypeTitle">
      <div class="doctpl-modal-head">
        <h3 id="ctypeTypeTitle">New complaint type</h3>
        <button type="button" class="close-card-btn" data-ct-action="close" aria-label="Close complaint type window"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="ctypeTypeForm" novalidate>
        <div class="doctpl-type-grid">
          <label class="doctpl-field doctpl-type-wide"><span>Name</span><input id="ctypeName" type="text" maxlength="100" autocomplete="off" required /></label>
          <label class="doctpl-field doctpl-type-wide" id="ctypeCodeWrap"><span>Code (optional)</span><input id="ctypeCode" type="text" maxlength="50" autocomplete="off" spellcheck="false" placeholder="made from the name" /></label>
          <div class="doctpl-field doctpl-type-wide" id="ctypeCodeReadWrap" hidden><span>Code</span><code id="ctypeCodeRead" class="ctype-readonly"></code></div>
          <label class="doctpl-field doctpl-type-wide"><span>Description (optional)</span><input id="ctypeDescription" type="text" maxlength="255" autocomplete="off" /></label>
          <fieldset class="doctpl-type-wide ctype-radios">
            <legend>Respondent</legend>
            <label><input type="radio" name="ctypeRespondent" value="none" checked /> <strong>None</strong> <span class="doctpl-note">The form does not ask who the complaint is against.</span></label>
            <label><input type="radio" name="ctypeRespondent" value="optional" /> <strong>Optional</strong> <span class="doctpl-note">The resident may name a respondent.</span></label>
            <label><input type="radio" name="ctypeRespondent" value="required" /> <strong>Required</strong> <span class="doctpl-note">The resident must name a respondent.</span></label>
          </fieldset>
          <label class="doctpl-field"><span>Default fee (₱)</span><input id="ctypeFee" type="text" inputmode="decimal" maxlength="9" autocomplete="off" value="0.00" aria-describedby="ctypeFeeHint" /><span id="ctypeFeeHint" class="doctpl-note">0 = no fee. Up to 100,000.00. Prefills the bill; a fee blocks mediation until paid or waived.</span></label>
          <label class="doctpl-field"><span>Sort order</span><input id="ctypeSort" type="number" min="0" max="9999" step="1" placeholder="last" /></label>
        </div>
        <p id="ctypeTypeError" class="ctype-error" role="alert" hidden></p>
        <div class="doctpl-modal-actions">
          <button type="button" class="btn-cancel-form" data-ct-action="close">Cancel</button>
          <button type="submit" class="btn-submit-form" id="ctypeTypeSubmit">Save</button>
        </div>
      </form>
      <section id="ctypeFieldsSection" class="ctype-fields" hidden>
        <div class="doctpl-card-head">
          <div><h4>Extra fields</h4><p class="doctpl-note" id="ctypeFieldsNote"></p></div>
          <button type="button" class="btn-primary-action" id="ctypeAddFieldBtn" data-ct-action="new-field"><i class="fa-solid fa-plus"></i> Add field</button>
        </div>
        <div class="table-wrap">
          <table class="data-table doctpl-table ctype-field-table">
            <thead><tr><th>Label</th><th>Key</th><th>Type</th><th>Required</th><th>Used in</th><th>Status</th><th class="action-column">Actions</th></tr></thead>
            <tbody id="ctypeFieldsBody"></tbody>
          </table>
        </div>
      </section>
    </div>
  </div>

  <!-- Complaint type field modal (Phase 4). -->
  <div class="modal-overlay ctype-modal" id="ctypeFieldModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box ctype-modal-box" role="dialog" aria-modal="true" aria-labelledby="ctypeFieldTitle">
      <div class="doctpl-modal-head">
        <h3 id="ctypeFieldTitle">Add field</h3>
        <button type="button" class="close-card-btn" data-ct-action="close" aria-label="Close field window"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="ctypeFieldForm" novalidate>
        <div class="doctpl-type-grid">
          <label class="doctpl-field doctpl-type-wide"><span>Label</span><input id="ctfLabel" type="text" maxlength="100" autocomplete="off" required /></label>
          <label class="doctpl-field" id="ctfKeyWrap"><span>Key</span><input id="ctfKey" type="text" maxlength="50" autocomplete="off" spellcheck="false" placeholder="e.g. animal_kind" /></label>
          <div class="doctpl-field" id="ctfKeyReadWrap" hidden><span>Key</span><code id="ctfKeyRead" class="ctype-readonly"></code></div>
          <label class="doctpl-field" id="ctfTypeWrap"><span>Field type</span>
            <select id="ctfType" class="form-input">
              <option value="select">Choice list</option>
              <option value="text">Text</option>
              <option value="number">Number</option>
              <option value="date">Date</option>
            </select>
          </label>
          <div class="doctpl-field" id="ctfTypeReadWrap" hidden><span>Field type</span><span id="ctfTypeRead" class="ctype-readonly"></span></div>
          <label class="doctpl-type-wide ctype-check"><input id="ctfRequired" type="checkbox" /> Required</label>
          <label class="doctpl-field" data-ctf-setting="text"><span>Max length</span><input id="ctfMaxLength" type="number" min="1" max="1000" step="1" placeholder="255" /></label>
          <label class="doctpl-field" data-ctf-setting="number"><span>Minimum</span><input id="ctfMin" type="number" step="any" placeholder="none" /></label>
          <label class="doctpl-field" data-ctf-setting="number"><span>Maximum</span><input id="ctfMax" type="number" step="any" placeholder="none" /></label>
          <p class="doctpl-note doctpl-type-wide" data-ctf-setting="date">A date field has no settings.</p>
        </div>
        <fieldset class="ctype-options" data-ctf-setting="select">
          <legend>Options</legend>
          <p class="doctpl-note">Saved options can be made inactive but never removed, so older complaints keep their answer.</p>
          <div class="ctype-option-head" aria-hidden="true"><span>Value</span><span>Label</span><span>Active</span><span>Order</span><span></span></div>
          <ol id="ctfOptions" class="ctype-option-list"></ol>
          <button type="button" class="btn-action view" data-ct-action="add-option"><i class="fa-solid fa-plus"></i> Add option</button>
        </fieldset>
        <p id="ctypeFieldError" class="ctype-error" role="alert" hidden></p>
        <div class="doctpl-modal-actions">
          <button type="button" class="btn-cancel-form" data-ct-action="close">Cancel</button>
          <button type="submit" class="btn-submit-form" id="ctypeFieldSubmit">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Walk-in complaint and parties windows (complaint revision Phase 5). js/complaint-walkin.js. -->
  <div class="modal-overlay walkin-modal" id="walkinModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box walkin-modal-box" role="dialog" aria-modal="true" aria-labelledby="walkinTitle">
      <div class="doctpl-modal-head">
        <h3 id="walkinTitle">New walk-in complaint</h3>
        <button type="button" class="close-card-btn" data-wk-action="close" aria-label="Close walk-in complaint window"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="walkinForm" novalidate>
        <div class="doctpl-type-grid">
          <label class="doctpl-field doctpl-type-wide"><span>Type of concern</span>
            <select id="walkinType" class="form-input"><option value="">Loading types…</option></select>
          </label>
          <div id="walkinExtra" class="doctpl-type-wide walkin-extra" hidden></div>
          <label class="doctpl-field"><span>Location</span><input id="walkinLocation" type="text" maxlength="150" autocomplete="off" /></label>
          <label class="doctpl-field"><span>Severity</span>
            <select id="walkinSeverity" class="form-input">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical (immediate danger to life or property)</option>
            </select>
          </label>
          <label class="doctpl-field doctpl-type-wide"><span>Description</span><textarea id="walkinDescription" rows="4"></textarea></label>
        </div>
        <fieldset class="walkin-parties">
          <legend>Complainants</legend>
          <div id="walkinComplainants"></div>
          <button type="button" class="btn-cancel-form" data-wk-action="add-party" data-role="complainant"><i class="fa-solid fa-plus"></i> Add complainant</button>
        </fieldset>
        <fieldset class="walkin-parties" id="walkinRespondentsSection" hidden>
          <legend>Respondents</legend>
          <p class="doctpl-note" id="walkinRespondentNote"></p>
          <div id="walkinRespondents"></div>
          <button type="button" class="btn-cancel-form" data-wk-action="add-party" data-role="respondent"><i class="fa-solid fa-plus"></i> Add respondent</button>
        </fieldset>
        <p id="walkinError" class="ctype-error" role="alert" hidden></p>
        <div class="doctpl-modal-actions">
          <button type="button" class="btn-cancel-form" data-wk-action="close">Cancel</button>
          <button type="submit" class="btn-submit-form" id="walkinSubmit">File complaint</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay walkin-modal" id="partiesModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box walkin-modal-box" role="dialog" aria-modal="true" aria-labelledby="partiesTitle">
      <div class="doctpl-modal-head">
        <h3 id="partiesTitle">Parties</h3>
        <button type="button" class="close-card-btn" data-wk-action="close" aria-label="Close parties window"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <p id="partiesResolved" class="doctpl-note" hidden>Resolved complaints cannot be changed.</p>
      <div class="table-wrap">
        <table class="data-table doctpl-table walkin-party-table">
          <thead><tr><th>Role</th><th>Name</th><th>Status</th><th>Address</th><th>Contact</th><th class="action-column">Actions</th></tr></thead>
          <tbody id="partiesBody"><tr><td colspan="6" class="registry-empty">Loading…</td></tr></tbody>
        </table>
      </div>
      <details id="partiesRemovedWrap" class="walkin-removed" hidden>
        <summary id="partiesRemovedSummary">Removed</summary>
        <ul id="partiesRemoved"></ul>
      </details>
      <section id="partiesAddSection" class="walkin-add">
        <h4>Add a party</h4>
        <label class="doctpl-field"><span>Role</span>
          <select id="partiesAddRole" class="form-input">
            <option value="complainant">Complainant</option>
            <option value="respondent">Respondent</option>
          </select>
        </label>
        <div id="partiesAddRow"></div>
        <div class="doctpl-modal-actions"><button type="button" class="btn-submit-form" data-wk-action="party-add">Add</button></div>
      </section>
      <p id="partiesError" class="ctype-error" role="alert" hidden></p>
      <div class="doctpl-modal-actions">
        <button type="button" class="btn-cancel-form" data-wk-action="close">Close</button>
      </div>
    </div>
  </div>

  <!-- Mediation session and schedule windows (complaint revision Phase 6). js/hearings.js. -->
  <div class="modal-overlay hearing-modal" id="hearingSessionModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box hearing-modal-box" role="dialog" aria-modal="true" aria-labelledby="hearingSessionTitle">
      <div class="doctpl-modal-head">
        <h3 id="hearingSessionTitle">Mediation session</h3>
        <button type="button" class="close-card-btn" data-hr-action="close" aria-label="Close session window"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="hearingSessionBody"></div>
      <p id="hearingSessionError" class="ctype-error" role="alert" hidden></p>
      <div class="doctpl-modal-actions" id="hearingSessionActions"></div>
    </div>
  </div>

  <div class="modal-overlay hearing-modal hearing-modal-top" id="hearingScheduleModal" aria-hidden="true">
    <div class="modal-box doctpl-modal-box hearing-modal-box" role="dialog" aria-modal="true" aria-labelledby="hearingScheduleTitle">
      <div class="doctpl-modal-head">
        <h3 id="hearingScheduleTitle">Schedule mediation</h3>
        <button type="button" class="close-card-btn" data-hr-action="close" aria-label="Close schedule window"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <p id="hearingScheduleSub" class="doctpl-note"></p>
      <form id="hearingScheduleForm" novalidate>
        <div class="doctpl-type-grid">
          <label class="doctpl-field"><span>Date</span><input id="hearingDate" type="date" class="form-input" /></label>
          <label class="doctpl-field"><span>Start time</span><select id="hearingStart" class="form-input"></select></label>
          <label class="doctpl-field"><span>Duration</span><select id="hearingDuration" class="form-input"></select></label>
          <label class="doctpl-field"><span>Presiding official</span><select id="hearingPresiding" class="form-input"></select></label>
          <label class="doctpl-field doctpl-type-wide"><span>Venue</span><input id="hearingVenue" type="text" class="form-input" maxlength="180" list="hearingVenueList" autocomplete="off" /></label>
          <datalist id="hearingVenueList"></datalist>
          <label class="doctpl-field doctpl-type-wide" id="hearingReasonWrap" hidden><span>Reason for rescheduling</span>
            <textarea id="hearingReason" rows="3" maxlength="500"></textarea>
            <small class="complaint-meta" id="hearingReasonCount">0 / 500</small>
          </label>
        </div>
        <div id="hearingCheck" class="hearing-check" aria-live="polite"></div>
        <p id="hearingScheduleError" class="ctype-error" role="alert" hidden></p>
        <div class="doctpl-modal-actions">
          <button type="button" class="btn-cancel-form" data-hr-action="close">Cancel</button>
          <button type="submit" class="btn-submit-form" id="hearingScheduleSubmit">Schedule session</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Household Profiling Wizard Modal -->
  <div class="modal-overlay form-modal-overlay" id="householdWizardModal" aria-hidden="true">
    <div class="modal-box form-card household-wizard-box" role="dialog" aria-modal="true" aria-labelledby="householdWizardTitle">
      <div class="form-card-header">
        <h3 id="householdWizardTitle"><i class="fa-solid fa-house-user"></i> Profile Household</h3>
        <button class="close-card-btn" id="closeHouseholdWizard" type="button" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>

      <div class="household-wizard-meta" id="householdWizardMeta"></div>

      <div class="wizard-progress" id="householdWizardProgress">
        <div class="wz-step" data-step="1"><span class="wz-dot">1</span><span class="wz-label">Composition</span></div>
        <div class="wz-step" data-step="2"><span class="wz-dot">2</span><span class="wz-label">Education</span></div>
        <div class="wz-step" data-step="3"><span class="wz-dot">3</span><span class="wz-label">Health</span></div>
        <div class="wz-step" data-step="4"><span class="wz-dot">4</span><span class="wz-label">Housing</span></div>
        <div class="wz-step" data-step="5"><span class="wz-dot">5</span><span class="wz-label">Employment</span></div>
        <div class="wz-step" data-step="6"><span class="wz-dot">6</span><span class="wz-label">Income</span></div>
      </div>

      <form id="householdWizardForm" novalidate>
        <div id="householdWizardStepBody" class="household-wizard-step-body"></div>

        <div class="form-actions wizard-actions">
          <button type="button" class="btn-cancel-form" id="householdWizardCancel">Cancel</button>
          <span class="wizard-spacer"></span>
          <button type="button" class="btn-cancel-form" id="householdWizardBack" hidden><i class="fa-solid fa-arrow-left"></i> Back</button>
          <button type="button" class="btn-submit-form" id="householdWizardNext">Next <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-submit-form" id="householdWizardSave" hidden><i class="fa-solid fa-floppy-disk"></i> Save Step</button>
        </div>
      </form>
    </div>
  </div>

  <script nonce="<?= e(csp_nonce()) ?>">
    window.IS_OFFICIAL_ADMIN = <?php echo $isOfficialAdmin ? 'true' : 'false'; ?>;
    window.OFFICIAL_ID = <?php echo (int)$_SESSION['official_id']; ?>;
    window.SMS_CONFIGURED = <?= (getenv('HTTPSMS_API_KEY') && getenv('HTTPSMS_FROM_NUMBER')) ? 'true' : 'false'; ?>;
    window.AI_CONFIGURED  = <?= getenv('GEMINI_API_KEY') ? 'true' : 'false'; ?>;
  </script>
  <script src="../js/official.js?v=<?= filemtime(__DIR__ . '/../js/official.js') ?>"></script>
  <script src="../js/document-templates.js?v=<?= filemtime(__DIR__ . '/../js/document-templates.js') ?>"></script>
  <script src="../js/complaint-types.js?v=<?= filemtime(__DIR__ . '/../js/complaint-types.js') ?>"></script>
  <script src="../js/complaint-walkin.js?v=<?= filemtime(__DIR__ . '/../js/complaint-walkin.js') ?>"></script>
  <script src="../js/hearings.js?v=<?= filemtime(__DIR__ . '/../js/hearings.js') ?>"></script>
  <script src="../js/complaint-billing.js?v=<?= filemtime(__DIR__ . '/../js/complaint-billing.js') ?>"></script>
  <script src="../js/vendor/chart.umd.min.js?v=<?= filemtime(__DIR__ . '/../js/vendor/chart.umd.min.js') ?>"></script>
  <script src="../js/dashboard-charts.js?v=<?= filemtime(__DIR__ . '/../js/dashboard-charts.js') ?>"></script>
</body>
</html>

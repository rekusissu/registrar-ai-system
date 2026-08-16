<?php
// ============================================================
//  STUDENT/HEALTH-RECORDS.PHP
//  Student's own health profile: blood type, height/weight/BMI,
//  medical history, surgical history, current conditions.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';

$page_title = 'Health Records';
$APP_ROOT = '../';
$ACTIVE_NAV = 'student_health';
$extra_css = ['student.css'];

require_once __DIR__ . '/_guard.php';

$db = Database::getInstance();

// The student's own health record (read-only view).
$health = $db->fetchOne(
    "SELECT * FROM health_records WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1",
    [$student['id']]
);

// BMI computed, never stored: weight(kg) / height(m)^2.
$bmi = null;
$bmiLabel = '';
if ($health) {
    $h = (float) ($health['height'] ?? 0);
    $w = (float) ($health['weight'] ?? 0);
    if ($h > 0 && $w > 0) {
        $hm = $h / 100; // height may be stored in cm
        $bmi = $w / ($hm * $hm);
        $bmiVal = round($bmi, 1);
        if ($bmiVal < 18.5) {
            $bmiLabel = 'Underweight';
        } elseif ($bmiVal < 25) {
            $bmiLabel = 'Normal';
        } elseif ($bmiVal < 30) {
            $bmiLabel = 'Overweight';
        } else {
            $bmiLabel = 'Obese';
        }
    }
}

$emergency = $db->fetchAll(
    "SELECT * FROM emergency_contacts WHERE student_id = ? ORDER BY is_primary DESC, id ASC LIMIT 2",
    [$student['id']]
);
?>

<main class="dashboard-main">
    <div class="dashboard-container">

        <header class="header">
            <div class="title"><h1>Health Records</h1><p>Your health profile and conditions on file with the campus clinic, as maintained by the Registrar.</p></div>
        </header>

        <?php if (!$health): ?>
            <div class="panel">
                <div class="empty-state">
                    <i class="fa-solid fa-heart-pulse"></i>
                    <p>No health record on file</p>
                    <span>Your health profile will appear here once the Registrar's office records it. Please coordinate with the campus clinic if you have concerns.</span>
                </div>
            </div>
        <?php else: ?>

        <div class="status-strip" style="margin-bottom:24px;">
            <div class="s-item">
                <div class="s-icon blue"><i class="fa-solid fa-droplet"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars($health['blood_type'] ?? '—') ?></div>
                    <div class="s-label">Blood Type</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon green"><i class="fa-solid fa-ruler-vertical"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars((string)($health['height'] ?? '—')) ?></div>
                    <div class="s-label">Height (cm)</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon purple"><i class="fa-solid fa-weight-scale"></i></div>
                <div>
                    <div class="s-value"><?= htmlspecialchars((string)($health['weight'] ?? '—')) ?></div>
                    <div class="s-label">Weight (kg)</div>
                </div>
            </div>
            <div class="s-item">
                <div class="s-icon yellow"><i class="fa-solid fa-calculator"></i></div>
                <div>
                    <div class="s-value"><?= $bmi ? htmlspecialchars((string)round($bmi, 1)) : '—' ?></div>
                    <div class="s-label">BMI · <?= $bmiLabel ?: 'Not computed' ?></div>
                </div>
            </div>
        </div>

        <div class="student-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;">

            <!-- Medical history -->
            <div class="panel">
                <div class="panel-toolbar">
                    <div class="panel-title"><i class="fa-solid fa-notes-medical" style="color:#dc2626;"></i> Medical History</div>
                </div>
                <div style="padding:18px 20px;font-size:13.5px;color:#1e293b;line-height:1.6;white-space:pre-wrap;">
                    <?= !empty($health['medical_history']) ? nl2br(htmlspecialchars($health['medical_history'])) : '<span style="color:#94a3b8;">No medical history recorded.</span>' ?>
                </div>
            </div>

            <!-- Surgical history -->
            <div class="panel">
                <div class="panel-toolbar">
                    <div class="panel-title"><i class="fa-solid fa-scalpel" style="color:#b45309;"></i> Surgical History</div>
                </div>
                <div style="padding:18px 20px;font-size:13.5px;color:#1e293b;line-height:1.6;white-space:pre-wrap;">
                    <?= !empty($health['surgical_history']) ? nl2br(htmlspecialchars($health['surgical_history'])) : '<span style="color:#94a3b8;">No surgical history recorded.</span>' ?>
                </div>
            </div>

        </div>

        <div class="panel" style="margin-top:20px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-heart-crack" style="color:#7c3aed;"></i> Current Medical Conditions</div>
            </div>
            <div style="padding:18px 20px;font-size:13.5px;color:#1e293b;line-height:1.6;white-space:pre-wrap;">
                <?= !empty($health['pre_existing_conditions']) ? nl2br(htmlspecialchars($health['pre_existing_conditions'])) : '<span style="color:#94a3b8;">No pre-existing conditions recorded.</span>' ?>
            </div>
        </div>

        <?php if (!empty($health['allergies']) || !empty($health['blood_pressure']) || !empty($health['dietary_restrictions']) || !empty($health['immunization_records'])): ?>
        <div class="panel" style="margin-top:20px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-clipboard-list" style="color:#2563eb;"></i> Additional Health Information</div>
            </div>
            <div style="padding:16px 20px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
                    <?php if (!empty($health['allergies'])): ?>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-allergies"></i></div>
                        <div class="profile-field-content"><span class="label">Allergies</span><span class="value"><?= htmlspecialchars($health['allergies']) ?></span></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($health['blood_pressure'])): ?>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-heart-pulse"></i></div>
                        <div class="profile-field-content"><span class="label">Blood Pressure</span><span class="value"><?= htmlspecialchars($health['blood_pressure']) ?></span></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($health['dietary_restrictions'])): ?>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-utensils"></i></div>
                        <div class="profile-field-content"><span class="label">Dietary Restrictions</span><span class="value"><?= htmlspecialchars($health['dietary_restrictions']) ?></span></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($health['immunization_records'])): ?>
                    <div class="profile-field">
                        <div class="profile-field-icon"><i class="fa-solid fa-syringe"></i></div>
                        <div class="profile-field-content"><span class="label">Immunization Records</span><span class="value"><?= htmlspecialchars($health['immunization_records']) ?></span></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($emergency): ?>
        <div class="panel" style="margin-top:20px;">
            <div class="panel-toolbar">
                <div class="panel-title"><i class="fa-solid fa-truck-medical" style="color:#dc2626;"></i> Emergency Contacts</div>
            </div>
            <div style="padding:16px 20px;">
                <?php foreach ($emergency as $e): ?>
                <div class="guardian-row">
                    <div class="guardian-avatar" style="background:linear-gradient(135deg,#b45309,#92400e);"><?= strtoupper(substr(htmlspecialchars(trim($e['full_name'])), 0, 1)) ?></div>
                    <div class="guardian-info">
                        <div class="guardian-name"><?= htmlspecialchars($e['full_name']) ?></div>
                        <div class="guardian-contact"><?= htmlspecialchars(ucfirst($e['relationship'] ?? '')) ?><?= $e['contact_number'] ? ' · ' . htmlspecialchars($e['contact_number']) : '' ?></div>
                    </div>
                    <?php if (!empty($e['is_primary'])): ?><div class="guardian-tags"><span class="chip blue"><i class="fa-solid fa-star"></i> Primary</span></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

    </div>
</main>

<?php include '../includes/footer.php'; ?>
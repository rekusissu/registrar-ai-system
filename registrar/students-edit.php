<?php
// ============================================================
//  REGISTRAR/STUDENTS-EDIT.PHP
//  Edit student form - polished UI with sections
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: students.php');
    exit;
}

$db = Database::getInstance();
$student = $db->fetchOne("SELECT * FROM students WHERE id = ?", [$id]);

if (!$student) {
    header('Location: students.php');
    exit;
}

// Helpers for display
function safeHtml($value, $fallback = '') {
    return htmlspecialchars($value ?? $fallback);
}

$fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$page_title = 'Edit Student';
$APP_ROOT = '../';
$ACTIVE_NAV = 'students';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Edit Student</h1>
            <p><?= safeHtml($student['first_name']) ?> <?= safeHtml($student['last_name']) ?> · <?= safeHtml($student['student_number']) ?></p>
        </div>
        <div class="header-actions">
            <a href="students-view.php?id=<?= (int)$student['id'] ?>" class="btn btn-light">
                <i class="fas fa-eye"></i> View
            </a>
            <a href="students.php" class="btn btn-light">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </header>

    <div class="form-container" id="formContainer">
        <div class="form-alert info">
            <i class="fas fa-circle-info"></i>
            <div>
                Editing <strong><?= safeHtml($fullName ?: 'student record') ?></strong>. Student number cannot be changed.
            </div>
        </div>

        <form method="POST" action="../api/students.php" id="editStudentForm" novalidate>
            <input type="hidden" name="id" value="<?= (int)$student['id'] ?>" />

            <!-- ── Student Number (read-only) ── -->
            <section class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-id-card"></i></div>
                    <div>
                        <div class="form-section-title">Student Number</div>
                        <div class="form-section-subtitle">Auto-generated identifier</div>
                    </div>
                </div>
                <div class="form-row form-row-full">
                    <div class="form-group">
                        <input type="text" value="<?= safeHtml($student['student_number']) ?>" class="form-control" readonly />
                    </div>
                </div>
            </section>

            <!-- ── Personal Info ── -->
            <section class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-user"></i></div>
                    <div>
                        <div class="form-section-title">Personal Information</div>
                        <div class="form-section-subtitle">Basic identity details of the student</div>
                    </div>
                </div>

                <div class="form-row form-row-3">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="form-control" value="<?= safeHtml($student['first_name']) ?>" required autocomplete="given-name" />
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?= safeHtml($student['middle_name']) ?>" autocomplete="additional-name" />
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="form-control" value="<?= safeHtml($student['last_name']) ?>" required autocomplete="family-name" />
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Birth Date</label>
                        <input type="date" name="birth_date" class="form-control" value="<?= safeHtml($student['birth_date']) ?>" />
                    </div>
                    <div class="form-group">
                        <label>Place of Birth</label>
                        <input type="text" name="place_of_birth" class="form-control" value="<?= safeHtml($student['place_of_birth']) ?>" />
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nationality</label>
                        <input type="text" name="nationality" class="form-control" value="<?= safeHtml($student['nationality']) ?>" />
                    </div>
                    <div class="form-group">
                        <label>Religion</label>
                        <input type="text" name="religion" class="form-control" value="<?= safeHtml($student['religion']) ?>" />
                    </div>
                </div>
            </section>

            <!-- ── Contact ── -->
            <section class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-address-book"></i></div>
                    <div>
                        <div class="form-section-title">Contact Details</div>
                        <div class="form-section-subtitle">How to reach the student</div>
                    </div>
                </div>

                <div class="form-row form-row-full">
                    <div class="form-group">
                        <label>Address <span class="required">*</span></label>
                        <textarea name="address" class="form-control" rows="2" required><?= safeHtml($student['address']) ?></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?= safeHtml($student['contact_number']) ?>" />
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= safeHtml($student['email']) ?>" />
                    </div>
                </div>
            </section>

            <!-- ── Academic ── -->
            <section class="form-section">
                <div class="form-section-header">
                    <div class="form-section-icon"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <div class="form-section-title">Academic Information</div>
                        <div class="form-section-subtitle">Course, year level, and standing</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Course / Program</label>
                        <input type="text" name="course" class="form-control" value="<?= safeHtml($student['course']) ?>" />
                    </div>
                    <div class="form-group">
                        <label>Year Level</label>
                        <select name="year_level" class="form-control">
                            <option value="">Select year level</option>
                            <option value="1" <?= (int)$student['year_level'] === 1 ? 'selected' : '' ?>>1st Year</option>
                            <option value="2" <?= (int)$student['year_level'] === 2 ? 'selected' : '' ?>>2nd Year</option>
                            <option value="3" <?= (int)$student['year_level'] === 3 ? 'selected' : '' ?>>3rd Year</option>
                            <option value="4" <?= (int)$student['year_level'] === 4 ? 'selected' : '' ?>>4th Year</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Section</label>
                        <input type="text" name="section" class="form-control" value="<?= safeHtml($student['section']) ?>" />
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?= $student['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="probation" <?= $student['status'] === 'probation' ? 'selected' : '' ?>>Probation</option>
                            <option value="at-risk" <?= $student['status'] === 'at-risk' ? 'selected' : '' ?>>At Risk</option>
                            <option value="graduated" <?= $student['status'] === 'graduated' ? 'selected' : '' ?>>Graduated</option>
                            <option value="loa" <?= $student['status'] === 'loa' ? 'selected' : '' ?>>Leave of Absence</option>
                            <option value="transferred" <?= $student['status'] === 'transferred' ? 'selected' : '' ?>>Transferred</option>
                            <option value="dropped" <?= $student['status'] === 'dropped' ? 'selected' : '' ?>>Dropped</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- ── Actions ── -->
            <div class="form-actions">
                <a href="students.php" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save"></i> Update Student
                </button>
            </div>
        </form>
    </div>
</main>

<script>
document.getElementById('editStudentForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    const btn = document.getElementById('submitBtn');
    const container = document.getElementById('formContainer');

    const prior = container.querySelector('.form-alert.error');
    if (prior) prior.remove();

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

    try {
        const response = await fetch('../api/students.php?id=' + data.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();

        if (result.success) {
            window.location.href = 'students.php?success=updated';
        } else {
            const alert = document.createElement('div');
            alert.className = 'form-alert error';
            alert.innerHTML = '<i class="fas fa-circle-exclamation"></i><div></div>';
            alert.querySelector('div').textContent = result.message || 'Error updating student.';
            container.insertBefore(alert, container.firstChild);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Update Student';
        }
    } catch (error) {
        const alert = document.createElement('div');
        alert.className = 'form-alert error';
        alert.innerHTML = '<i class="fas fa-circle-exclamation"></i><div></div>';
        alert.querySelector('div').textContent = 'Network error. Please try again.';
        container.insertBefore(alert, container.firstChild);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Student';
    }
});
</script>

<?php include '../includes/footer.php'; ?>
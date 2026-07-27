<?php
// ============================================================
//  REGISTRAR/STUDENTS-ADD.PHP
//  Add new student form - polished UI with sections
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = 'Add Student';
$APP_ROOT = '../';
$ACTIVE_NAV = 'students';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="dashboard-main">
    <header class="header">
        <div class="title">
            <h1>Add Student</h1>
            <p>Create a new student record</p>
        </div>
        <div class="header-actions">
            <a href="students.php" class="btn btn-light">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </header>

    <div class="form-container" id="formContainer">
        <div class="form-alert info">
            <i class="fas fa-circle-info"></i>
            <div>
                Fields marked with <span style="color:#dc2626;">*</span> are required. The student number will be auto-generated upon saving.
            </div>
        </div>

        <form method="POST" action="../api/students.php" id="addStudentForm" novalidate>
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
                        <input type="text" name="first_name" class="form-control" required autocomplete="given-name" placeholder="Juan" />
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" autocomplete="additional-name" placeholder="Dela" />
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="form-control" required autocomplete="family-name" placeholder="Cruz" />
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Birth Date</label>
                        <input type="date" name="birth_date" class="form-control" />
                    </div>
                    <div class="form-group">
                        <label>Place of Birth</label>
                        <input type="text" name="place_of_birth" class="form-control" placeholder="Manila, Philippines" />
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nationality</label>
                        <input type="text" name="nationality" class="form-control" placeholder="Filipino" />
                    </div>
                    <div class="form-group">
                        <label>Religion</label>
                        <input type="text" name="religion" class="form-control" placeholder="Roman Catholic" />
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
                        <textarea name="address" class="form-control" rows="2" required placeholder="Street, Barangay, City, Province"></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" placeholder="0917-123-4567" />
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="juan.cruz@example.com" />
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
                        <input type="text" name="course" class="form-control" placeholder="BSIT, BSCS, BSED..." />
                    </div>
                    <div class="form-group">
                        <label>Year Level</label>
                        <select name="year_level" class="form-control">
                            <option value="">Select year level</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Section</label>
                        <input type="text" name="section" class="form-control" placeholder="A, B, C..." />
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" selected>Active</option>
                            <option value="probation">Probation</option>
                            <option value="at-risk">At Risk</option>
                            <option value="graduated">Graduated</option>
                            <option value="loa">Leave of Absence</option>
                            <option value="transferred">Transferred</option>
                            <option value="dropped">Dropped</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- ── Actions ── -->
            <div class="form-actions">
                <a href="students.php" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save"></i> Save Student
                </button>
            </div>
        </form>
    </div>
</main>

<script>
document.getElementById('addStudentForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    const btn = document.getElementById('submitBtn');
    const container = document.getElementById('formContainer');

    // Remove any prior error banner
    const prior = container.querySelector('.form-alert.error');
    if (prior) prior.remove();

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    try {
        const response = await fetch('../api/students.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();

        if (result.success) {
            window.location.href = 'students.php?success=added';
        } else {
            const alert = document.createElement('div');
            alert.className = 'form-alert error';
            alert.innerHTML = '<i class="fas fa-circle-exclamation"></i><div></div>';
            alert.querySelector('div').textContent = result.message || 'Error adding student.';
            container.insertBefore(alert, container.firstChild);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Student';
        }
    } catch (error) {
        const alert = document.createElement('div');
        alert.className = 'form-alert error';
        alert.innerHTML = '<i class="fas fa-circle-exclamation"></i><div></div>';
        alert.querySelector('div').textContent = 'Network error. Please try again.';
        container.insertBefore(alert, container.firstChild);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Student';
    }
});
</script>

<?php include '../includes/footer.php'; ?>
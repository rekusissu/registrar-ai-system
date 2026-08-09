<?php
// ============================================================
//  REGISTRAR/DOCUMENTS-ADD.PHP
//  New document request form
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();
$students = $db->fetchAll("SELECT id, student_number, CONCAT(first_name, ' ', last_name) AS name FROM students WHERE status = 'active' ORDER BY name");

$page_title = 'New Document Request';
$APP_ROOT = '../';
$ACTIVE_NAV = 'documents';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1>New Document Request</h1>
            <p>Submit a new document request</p>
        </div>
        <div class="header-actions">
            <a href="documents.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </header>

    <div class="card" style="max-width: 600px; margin: 0 auto;">
        <form method="POST" action="../api/documents.php" id="addDocumentForm">
            <div class="form-group">
                <label>Student <span class="required">*</span></label>
                <select name="student_id" class="form-control" required>
                    <option value="">Select a student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= $student['id'] ?>">
                            <?= htmlspecialchars($student['student_number']) ?> - <?= htmlspecialchars($student['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Document Type <span class="required">*</span></label>
                <select name="document_type" class="form-control" required>
                    <option value="">Select document type</option>
                    <option value="form137">Form 137</option>
                    <option value="good_moral">Good Moral</option>
                    <option value="transcript">Transcript</option>
                    <option value="certificate">Certificate</option>
                    <option value="clearance">Clearance</option>
                </select>
            </div>

            <div class="form-group">
                <label>Purpose</label>
                <input type="text" name="purpose" class="form-control" placeholder="e.g., Transfer to UP Manila" />
            </div>

            <div class="form-group">
                <label>Recipient</label>
                <input type="text" name="recipient" class="form-control" placeholder="e.g., UP Manila Registrar" />
            </div>

            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Fee (₱, optional)</label>
                    <input type="number" name="fee_amount" step="0.01" min="0" class="form-control" placeholder="0.00" />
                </div>
                <div class="form-group">
                    <label>Official Receipt No. (optional)</label>
                    <input type="text" name="official_receipt" class="form-control" placeholder="e.g. OR-0001" />
                </div>
            </div>

            <div class="form-actions" style="display: flex; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                <a href="documents.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Submit Request</button>
            </div>
        </form>
    </div>
</main>

<script>
document.getElementById('addDocumentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    const btn = this.querySelector('button[type="submit"]');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    
    try {
        const response = await fetch('../api/documents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        
        if (result.success) {
            window.location.href = 'documents.php?success=added';
        } else {
            alert(result.message || 'Error submitting request.');
        }
    } catch (error) {
        alert('Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Submit Request';
    }
});
</script>

<?php include '../includes/footer.php'; ?>
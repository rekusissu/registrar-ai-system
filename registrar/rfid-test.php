<?php
// ============================================================
//  REGISTRAR/RFID-TEST.PHP
//  RFID scanner test page
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$page_title = 'RFID Scanner Test';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';
$extra_css = ['rfid-cards.css'];
$page_scripts = ['rfid.js'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main">
    <header class="header">
        <div class="title">
            <h1>RFID Scanner Test</h1>
            <p>Simulate a 125kHz RFID card tap</p>
        </div>
        <div class="header-actions">
            <a href="rfid-cards.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Cards
            </a>
            <a href="rfid-scan-logs.php" class="btn btn-secondary">
                <i class="fas fa-clock-rotate-left"></i> Scan Logs
            </a>
        </div>
    </header>

    <div class="scanner-container">
        <div class="scanner-icon"><i class="fas fa-credit-card"></i></div>
        <h1>RFID Scanner</h1>
        <p class="subtitle">Tap a card on the reader or enter UID manually</p>

        <form id="scanForm">
            <div class="scan-area">
                <input type="text" id="cardUid" placeholder="Enter or simulate card UID" autocomplete="off" />
            </div>
            <button type="submit" class="btn-scan"><i class="fas fa-wave-square"></i> Tap Card</button>
        </form>

        <button class="random-btn" id="simulateBtn"><i class="fas fa-random"></i> Simulate random card</button>

        <div class="result" id="scanResult"></div>
    </div>
</main>

<script>
(function () {
    'use strict';

    var uidInput = document.getElementById('cardUid');
    var form = document.getElementById('scanForm');
    var resultDiv = document.getElementById('scanResult');
    var simulateBtn = document.getElementById('simulateBtn');

    function simulateCard() {
        var uid = Math.floor(Math.random() * 9000000000 + 1000000000).toString();
        uidInput.value = uid;
        uidInput.style.borderColor = '#22c55e';
        setTimeout(function () { uidInput.style.borderColor = '#e2e8f0'; }, 2000);
    }

    if (simulateBtn) simulateBtn.addEventListener('click', simulateCard);

    function clearResult() {
        resultDiv.className = 'result';
        resultDiv.innerHTML = '';
    }

    function renderStudent(student) {
        var name = ((student.first_name || '') + ' ' + (student.last_name || '')).trim() || 'Unknown';
        return '<div class="result-header">' +
                    '<i class="fas fa-check-circle"></i> Access Granted' +
                '</div>' +
                '<div class="result-name">' + name + '</div>' +
                '<div class="result-meta">' +
                    '<span><i class="fas fa-id-badge"></i> ' + (student.student_number || '—') + '</span>' +
                    (student.course ? '<span><i class="fas fa-graduation-cap"></i> ' + student.course + '</span>' : '') +
                '</div>';
    }

    function renderDenied(message, uid) {
        return '<div class="result-header denied">' +
                    '<i class="fas fa-times-circle"></i> Access Denied' +
                '</div>' +
                '<div class="result-name">' + message + '</div>' +
                '<div class="result-meta"><span><i class="fas fa-microchip"></i> UID: ' + uid + '</span></div>';
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var uid = uidInput.value.trim();
        clearResult();

        if (!uid) {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a card UID.';
            return;
        }

        var submitBtn = form.querySelector('.btn-scan');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';
        }

        try {
            var response = await fetch('../api/rfid-scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_uid: uid, location: 'Test Scanner', event_type: 'entry' })
            });
            var data = await response.json();

            if (data.success && data.student) {
                resultDiv.className = 'result success';
                resultDiv.innerHTML = renderStudent(data.student);
            } else {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = renderDenied(data.message || 'Access denied.', uid);
            }
        } catch (err) {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = '<i class="fas fa-times-circle"></i> Network error. Please try again.';
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-wave-square"></i> Tap Card';
            }
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
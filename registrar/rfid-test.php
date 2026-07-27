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
        </div>
    </header>

    <div class="scanner-container">
        <div class="scanner-icon"><i class="fas fa-credit-card"></i></div>
        <h1>RFID Scanner</h1>
        <p class="subtitle">Tap a card on the reader or enter UID manually</p>

        <form id="scanForm">
            <div class="scan-area">
                <input type="text" id="cardUid" placeholder="Enter or simulate card UID" />
            </div>
            <button type="submit" class="btn-scan"><i class="fas fa-wave-square"></i> Tap Card</button>
        </form>

        <button class="random-btn" onclick="simulateCard()"><i class="fas fa-random"></i> Simulate random card</button>

        <div class="result" id="scanResult"></div>
    </div>
</main>

<script>
function simulateCard() {
    const uid = Math.floor(Math.random() * 9000000000 + 1000000000).toString();
    document.getElementById('cardUid').value = uid;
    document.getElementById('cardUid').style.borderColor = '#22c55e';
    setTimeout(() => document.getElementById('cardUid').style.borderColor = '#e2e8f0', 2000);
}

document.getElementById('scanForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const uid = document.getElementById('cardUid').value.trim();
    const resultDiv = document.getElementById('scanResult');

    if (!uid) {
        resultDiv.className = 'result error';
        resultDiv.textContent = 'Please enter a card UID.';
        return;
    }

    try {
        const response = await fetch('../api/rfid-scan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ card_uid: uid })
        });
        const data = await response.json();

        if (data.success) {
            resultDiv.className = 'result success';
            resultDiv.innerHTML = '<i class="fas fa-check-circle"></i> Access GRANTED for ' + (data.student?.first_name || 'Unknown');
        } else {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = '<i class="fas fa-times-circle"></i> ' + data.message;
        }
    } catch (error) {
        resultDiv.className = 'result error';
        resultDiv.textContent = 'Network error. Please try again.';
    }
});
</script>

<?php include '../includes/footer.php'; ?>
<?php
// ============================================================
//  REGISTRAR/RFID-TEST.PHP
//  RFID scanner test page — fully inline (CSS + JS)
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
requireRole('registrar');

$page_title = 'RFID Scanner Test';
$APP_ROOT = '../';
$ACTIVE_NAV = 'rfid';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
/* ============================================================
   RFID TEST PAGE — INLINE STYLES
   ============================================================ */
:root { --sidebar-width: 260px; }
.dashboard-main {
    margin-left: var(--sidebar-width);
    padding: 24px 32px;
    min-height: 100vh;
    width: calc(100% - var(--sidebar-width));
    max-width: calc(100% - var(--sidebar-width));
    overflow-x: hidden;
    box-sizing: border-box;
    transition: margin-left 0.3s ease, width 0.3s ease, max-width 0.3s ease;
}

/* ── Page header ── */
.header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 22px; padding-bottom: 16px;
    border-bottom: 1px solid #e8eaef;
    gap: 16px; flex-wrap: wrap;
}
.header .title h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin: 0 0 2px; }
.header .title p  { font-size: 13px; color: #64748b; margin: 0; }
.header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── Buttons ── */
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    border-radius: 10px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1.5px solid transparent;
    text-decoration: none;
    transition: all 0.2s ease;
    font-family: inherit;
    line-height: 1;
}
.btn-secondary { background: white; color: #475569; border-color: #e2e8f0; }
.btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }

/* ── Scanner card ── */
.scanner-container {
    max-width: 560px;
    margin: 24px auto;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 36px 32px 28px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
}
.scanner-icon {
    width: 76px; height: 76px;
    margin: 0 auto 18px;
    background: linear-gradient(135deg, #eef4ff, #dbeafe);
    border-radius: 22px;
    display: flex; align-items: center; justify-content: center;
    color: #2563eb;
    font-size: 32px;
}
.scanner-container h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
.scanner-container .subtitle { color: #64748b; margin: 0 0 22px; font-size: 14px; }

.scan-area { margin-bottom: 14px; }
.scan-area input {
    width: 100%;
    padding: 14px 16px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-size: 16px;
    font-family: 'Courier New', monospace;
    letter-spacing: 1px;
    text-align: center;
    outline: none;
    background: white;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.scan-area input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
}

.btn-scan {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 15px; font-weight: 600;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    font-family: inherit;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(37,99,235,0.25);
}
.btn-scan:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(37,99,235,0.30); }
.btn-scan:disabled { opacity: 0.7; cursor: not-allowed; }

.random-btn {
    margin-top: 12px;
    background: #f1f5f9;
    color: #475569;
    border: 1.5px solid #e2e8f0;
    padding: 9px 18px;
    border-radius: 10px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; font-weight: 600;
    font-family: inherit;
    transition: all 0.2s ease;
}
.random-btn:hover { background: #e2e8f0; color: #0f172a; }

/* ── Result panel ── */
.result {
    margin-top: 22px;
    padding: 22px;
    border-radius: 14px;
    text-align: left;
    display: none;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    animation: resultIn 0.3s ease-out;
}
.result.show { display: block; }
.result.success { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-color: #86efac; }
.result.error   { background: linear-gradient(135deg, #fef2f2, #fee2e2); border-color: #fca5a5; }
.result.info    { background: linear-gradient(135deg, #eff6ff, #dbeafe); border-color: #93c5fd; }

@keyframes resultIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}

.result-header {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 16px;
    color: #16a34a;
    margin-bottom: 12px;
}
.result-header.denied { color: #dc2626; }
.result-header i { font-size: 22px; }

.result-name {
    font-size: 18px; font-weight: 700; color: #0f172a;
    margin-bottom: 8px;
}
.result-meta {
    display: flex; gap: 16px;
    color: #475569; font-size: 13px;
    flex-wrap: wrap;
}
.result-meta span { display: inline-flex; align-items: center; gap: 6px; }
.result-meta i { color: #94a3b8; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .dashboard-main {
        margin-left: 0;
        width: 100%; max-width: 100%;
        padding: 18px 14px;
    }
}
@media (max-width: 480px) {
    .scanner-container { padding: 24px 18px 20px; }
    .header { flex-direction: column; align-items: flex-start; gap: 12px; }
    .header-actions { width: 100%; }
}
</style>

<main class="dashboard-main">
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
// ============================================================
// RFID TEST — INLINE JS
// ============================================================
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
        setTimeout(function () { uidInput.style.borderColor = ''; }, 2000);
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
            resultDiv.className = 'result show error';
            resultDiv.innerHTML = '<div class="result-header denied"><i class="fas fa-exclamation-circle"></i> Please enter a card UID.</div>';
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
                resultDiv.className = 'result show success';
                resultDiv.innerHTML = renderStudent(data.student);
            } else {
                resultDiv.className = 'result show error';
                resultDiv.innerHTML = renderDenied(data.message || 'Access denied.', uid);
            }
        } catch (err) {
            resultDiv.className = 'result show error';
            resultDiv.innerHTML = '<div class="result-header denied"><i class="fas fa-times-circle"></i> Network error. Please try again.</div>';
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

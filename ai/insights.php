<?php
// ============================================================
//  AI/INSIGHTS.PHP
//  Intelligent Analytics and Reports Dashboard
//  With OpenAI-powered AI analysis
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../shared/database.php';

$db = Database::getInstance();

$currentMonth = date('n');
$currentYear = date('Y');

$totalStudents = $db->fetchColumn("SELECT COUNT(*) FROM students");
$totalCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
$totalDocuments = $db->fetchColumn("SELECT COUNT(*) FROM document_requests");
$queueTotal = $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets");

$statusData = $db->fetchAll("SELECT status, COUNT(*) as count FROM students GROUP BY status ORDER BY count DESC");
$programData = $db->fetchAll("SELECT course, COUNT(*) as count FROM students WHERE course IS NOT NULL AND course != '' GROUP BY course ORDER BY count DESC LIMIT 8");
$docData = $db->fetchAll("SELECT document_type, COUNT(*) as count FROM document_requests GROUP BY document_type ORDER BY count DESC");
$rfidStatusData = $db->fetchAll("SELECT status, COUNT(*) as count FROM rfid_cards GROUP BY status ORDER BY count DESC");

$page_title = 'Intelligent Analytics and Reports';
$APP_ROOT = '../';
$ACTIVE_NAV = 'insights';
$use_chart = true;

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<main class="main">
    <header class="header">
        <div class="title">
            <h1><i class="fas fa-chart-line" style="color: var(--primary-500);"></i> Intelligent Analytics and Reports</h1>
            <p>AI-powered registrar data analysis and visualization</p>
        </div>
    </header>

    <!-- Reporting Period Selector -->
    <div style="display: flex; justify-content: flex-end; margin-bottom: 20px; gap: 12px; align-items: center;">
        <label style="font-weight: 500; color: #374151;">
            <i class="fas fa-calendar-alt" style="color: var(--primary-500); margin-right: 8px;"></i> Reporting Period:
        </label>
        <div style="display: flex; gap: 8px;">
            <select id="reportingMonth" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: white; min-width: 140px;">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $currentMonth ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                <?php endfor; ?>
            </select>
            <select id="reportingYear" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: white; min-width: 100px;">
                <?php for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 28px;">
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-user-graduate" style="color: var(--primary-500);"></i>
                <span class="card-label">Total Students</span>
            </div>
            <div class="card-value" style="font-size: 32px;"><?= number_format($totalStudents) ?></div>
            <div class="card-sub" style="font-size: 13px;">
                <span class="badge badge-primary">Active</span>
                <span class="badge badge-info">Enrolled</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-credit-card" style="color: var(--primary-500);"></i>
                <span class="card-label">RFID Cards</span>
            </div>
            <div class="card-value" style="font-size: 32px;"><?= number_format($totalCards) ?></div>
            <div class="card-sub" style="font-size: 13px;">
                <span class="badge badge-success">Active</span>
                <span class="badge badge-warning">Expired</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-file-lines" style="color: var(--primary-500);"></i>
                <span class="card-label">Document Transactions</span>
            </div>
            <div class="card-value" style="font-size: 32px;"><?= number_format($totalDocuments) ?></div>
            <div class="card-sub" style="font-size: 13px;">
                <span class="badge badge-success">Processed</span>
                <span class="badge badge-info">Types</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="card-header">
                <i class="fas fa-ticket" style="color: var(--primary-500);"></i>
                <span class="card-label">Queue Total Student</span>
            </div>
            <div class="card-value" style="font-size: 32px;"><?= number_format($queueTotal) ?></div>
            <div class="card-sub" style="font-size: 13px;">
                <span class="badge badge-primary">Today: <?= $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = CURDATE()") ?></span>
                <span class="badge badge-secondary">Total</span>
            </div>
        </div>
    </div>

    <!-- Student Population Overview Section -->
    <div style="margin-bottom: 28px;">
        <h2 style="font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid var(--primary-500);">
            <i class="fas fa-users" style="color: var(--primary-500); margin-right: 10px;"></i> STUDENT POPULATION OVERVIEW
        </h2>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 16px;">
                    <i class="fas fa-chart-pie" style="color: #8b5cf6; margin-right: 8px;"></i> Student Status Distribution
                </h3>
                <div style="position: relative; height: 280px;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 16px;">
                    <i class="fas fa-chart-bar" style="color: #06b6d4; margin-right: 8px;"></i> Student Program Distribution
                </h3>
                <div style="position: relative; height: 280px;">
                    <canvas id="programChart"></canvas>
                </div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 16px;">
                    <i class="fas fa-file" style="color: #f59e0b; margin-right: 8px;"></i> Document Transaction Overview
                </h3>
                <div style="position: relative; height: 280px;">
                    <canvas id="documentChart"></canvas>
                </div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 16px;">
                    <i class="fas fa-id-card" style="color: #10b981; margin-right: 8px;"></i> RFID Overview
                </h3>
                <div style="position: relative; height: 280px;">
                    <canvas id="rfidChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Report Generation Section -->
    <div style="background: white; padding: 24px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 700; color: #1e293b;">
                    <i class="fas fa-wand-magic-sparkles" style="color: var(--primary-500); margin-right: 10px;"></i> AI Analysis Report
                </h2>
                <p style="font-size: 14px; color: #64748b; margin-top: 4px;">
                    Generate an AI-assisted analysis of the registrar data.
                </p>
            </div>
            <button id="generateReportBtn" style="padding: 10px 24px; background: linear-gradient(135deg, var(--primary-500), var(--primary-700)); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-wand-magic-sparkles"></i> Generate Report
            </button>
        </div>

        <div id="reportOutput" style="display: none; margin-top: 20px;">
            <div style="border: 2px solid var(--primary-500); border-radius: 12px; padding: 24px; background: #f8fafc;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                    <h3 style="font-size: 20px; font-weight: 700; color: var(--primary-700); margin: 0;">
                        <i class="fas fa-brain" style="color: var(--primary-500); margin-right: 10px;"></i> AI ANALYSIS REPORT
                    </h3>
                    <span style="font-size: 12px; color: #64748b; background: white; padding: 4px 12px; border-radius: 20px; border: 1px solid #e2e8f0;">
                        <i class="far fa-clock"></i> Generated: <span id="reportGeneratedAt"></span>
                    </span>
                </div>
                <div id="reportContent" style="font-size: 14px; line-height: 1.8; color: #334155; white-space: pre-wrap;"></div>
                <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px;">
                    <button id="printReportBtn" style="padding: 8px 16px; background: white; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button id="exportReportBtn" style="padding: 8px 16px; background: white; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            <div style="margin-top: 16px; padding: 12px; background: #fef3c7; border-radius: 8px; border: 1px solid #fcd34d; font-size: 12px; color: #92400e; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-info-circle"></i>
                <span>AI-generated information is provided for administrative reference only.</span>
            </div>
        </div>

        <div id="reportLoading" style="display: none; text-align: center; padding: 40px;">
            <i class="fas fa-spinner fa-spin" style="font-size: 40px; color: var(--primary-500);"></i>
            <p style="margin-top: 16px; color: #64748b;">Analyzing registrar data with AI...</p>
        </div>
    </div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#64748b';

const statusColors = {
    'active': '#10b981', 'enrolled': '#3b82f6', 'alumni': '#8b5cf6',
    'graduated': '#f59e0b', 'dropped': '#ef4444', 'transferred': '#06b6d4',
    'probation': '#f97316', 'at-risk': '#dc2626'
};

const programColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#14b8a6'];

document.addEventListener('DOMContentLoaded', function() {
    // Student Status Distribution - Pie Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_column($statusData, 'status')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($statusData, 'count')) ?>,
                backgroundColor: <?= json_encode(array_map(fn($s) => statusColors[strtolower($s)] || '#94a3b8', array_column($statusData, 'status'))) ?>,
                borderColor: '#ffffff', borderWidth: 2, hoverOffset: 8
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, font: { size: 12 } } },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 12, cornerRadius: 8,
                    callbacks: { label: function(ctx) { const t = ctx.dataset.data.reduce((a,b) => a+b, 0); return ' ' + ctx.label + ': ' + ctx.raw + ' (' + ((ctx.raw/t)*100).toFixed(1) + '%)'; } }
                }
            }
        }
    });

    // Student Program Distribution - Horizontal Bar
    const programCtx = document.getElementById('programChart').getContext('2d');
    new Chart(programCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($programData, 'course')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($programData, 'count')) ?>,
                backgroundColor: programColors.slice(0, <?= count($programData) ?>),
                borderRadius: 4, borderSkipped: false
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 12, cornerRadius: 8, callbacks: { label: function(ctx) { return ' ' + ctx.raw + ' students'; } } } },
            scales: { x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } }
        }
    });

    // Document Transaction Overview - Bar Chart
    <?php
    $docLabels = array_map(fn($d) => strtoupper(str_replace('_', ' ', $d['document_type'])), $docData);
    $docValues = array_column($docData, 'count');
    ?>
    new Chart(document.getElementById('documentChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($docLabels) ?>,
            datasets: [{ data: <?= json_encode($docValues) ?>, backgroundColor: '#f59e0b', borderRadius: 4, borderSkipped: false }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 12, cornerRadius: 8, callbacks: { label: function(ctx) { return ' ' + ctx.raw + ' transactions'; } } } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 }, stepSize: 1 } }, x: { grid: { display: false }, ticks: { font: { size: 10 } } } }
        }
    });

    // RFID Overview - Doughnut Chart
    <?php
    $rfidLabels = array_map(fn($s) => ucfirst($s['status']), $rfidStatusData);
    $rfidValues = array_column($rfidStatusData, 'count');
    $rfidColors = [];
    foreach (array_column($rfidStatusData, 'status') as $s) {
        $l = strtolower($s);
        if ($l === 'active') $rfidColors[] = '#10b981';
        elseif ($l === 'expired') $rfidColors[] = '#ef4444';
        elseif ($l === 'pending') $rfidColors[] = '#f59e0b';
        else $rfidColors[] = '#94a3b8';
    }
    ?>
    new Chart(document.getElementById('rfidChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($rfidLabels) ?>,
            datasets: [{ data: <?= json_encode($rfidValues) ?>, backgroundColor: <?= json_encode($rfidColors) ?>, borderColor: '#ffffff', borderWidth: 2, hoverOffset: 8 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '60%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, font: { size: 12 } } },
                tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 12, cornerRadius: 8, callbacks: { label: function(ctx) { const t = ctx.dataset.data.reduce((a,b) => a+b, 0); return ' ' + ctx.label + ': ' + ctx.raw + ' (' + ((ctx.raw/t)*100).toFixed(1) + '%)'; } } }
            }
        }
    });
});

// Generate AI Report
document.getElementById('generateReportBtn').addEventListener('click', async function() {
    const btn = this;
    const reportOutput = document.getElementById('reportOutput');
    const reportLoading = document.getElementById('reportLoading');
    const reportContent = document.getElementById('reportContent');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    reportOutput.style.display = 'none';
    reportLoading.style.display = 'block';
    
    try {
        const month = document.getElementById('reportingMonth').value;
        const year = document.getElementById('reportingYear').value;
        
        const response = await fetch('../api/ai-insights-report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filters: { month: parseInt(month), year: parseInt(year) } })
        });
        
        const data = await response.json();
        
        if (!data.success || !data.data) throw new Error(data.message || 'Failed to generate report');
        
        reportContent.textContent = data.data.report;
        document.getElementById('reportGeneratedAt').textContent = data.data.generated_at + ' (' + data.data.period.label + ')';
        
        reportLoading.style.display = 'none';
        reportOutput.style.display = 'block';
        reportOutput.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
        console.error('Report error:', error);
        reportContent.textContent = 'ERROR: Could not generate the AI analysis report.\n\nPlease check that:\n• OpenAI API key is configured\n• Internet connection is active\n\nError: ' + error.message;
        reportLoading.style.display = 'none';
        reportOutput.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate Report';
    }
});

// Print Report
document.getElementById('printReportBtn').addEventListener('click', function() {
    const printWindow = window.open('', '_blank');
    const reportContent = document.getElementById('reportContent').textContent;
    const generatedAt = document.getElementById('reportGeneratedAt').textContent;
    
    printWindow.document.write('<!DOCTYPE html><html><head><title>AI Analysis Report</title><style>body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:20px;line-height:1.6} h1{color:#1e40af;border-bottom:3px solid #1e40af;padding-bottom:15px} .footer{margin-top:40px;border-top:1px solid #ddd;font-size:11px;color:#888;text-align:center}</style></head><body>');
    printWindow.document.write('<h1>AI ANALYSIS REPORT</h1>');
    printWindow.document.write('<p style="color:#666;font-size:12px">Generated: ' + generatedAt + '</p>');
    printWindow.document.write('<div style="margin-top:20px">' + reportContent.replace(/\n/g, '<br>') + '</div>');
    printWindow.document.write('<div class="footer">Generated by: Registrar Information System<br>AI-generated information is provided for administrative reference.</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 500);
});

// Export PDF (opens print dialog)
document.getElementById('exportReportBtn').addEventListener('click', function() {
    const printWindow = window.open('', '_blank');
    const reportContent = document.getElementById('reportContent').textContent;
    const generatedAt = document.getElementById('reportGeneratedAt').textContent;
    const month = document.getElementById('reportingMonth').value;
    const year = document.getElementById('reportingYear').value;
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    
    printWindow.document.write('<!DOCTYPE html><html><head><title>AI Report - ' + monthNames[parseInt(month)-1] + ' ' + year + '</title><style>@page{size:A4;margin:2cm}body{font-family:sans-serif;line-height:1.6;color:#333}.header{text-align:center;border-bottom:3px solid #1e40af;padding-bottom:20px;margin-bottom:30px}h1{color:#1e40af;margin:0 0 10px;font-size:28px}.period{background:#f3f4f6;padding:8px 20px;border-radius:20px;display:inline-block;margin-top:10px;font-size:13px}.meta{text-align:right;font-size:11px;color:#888;margin-bottom:20px}.footer{margin-top:50px;padding-top:20px;border-top:2px solid #1e40af;text-align:center;font-size:11px}.system{font-weight:600}</style></head><body>');
    printWindow.document.write('<div class="header"><h1>AI ANALYSIS REPORT</h1><div class="period"><i class="far fa-calendar" style="margin-right:5px"></i> ' + monthNames[parseInt(month)-1] + ' ' + year + '</div></div>');
    printWindow.document.write('<div class="meta">Generated: ' + generatedAt + '</div>');
    printWindow.document.write('<div style="margin-top:20px">' + reportContent + '</div>');
    printWindow.document.write('<div class="footer"><div class="system">Generated by: Registrar Information System</div><div>AI-generated information is provided for administrative reference.</div></div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 500);
});
</script>

<?php include '../includes/footer.php'; ?>

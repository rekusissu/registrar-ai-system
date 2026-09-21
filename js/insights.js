// ============================================================
//  JS/INSIGHTS.JS
//  Intelligent Analytics — Chart.js init + AI report flow
//  Reads chart data from <script type="application/json" id="insightsData">
// ============================================================
(function () {
    'use strict';

    var dataEl = document.getElementById('insightsData');
    var data = null;
    try { data = dataEl ? JSON.parse(dataEl.textContent) : null; } catch (e) { data = null; }

    var baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 11 } } },
            tooltip: { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, titleFont: { size: 12 }, bodyFont: { size: 12 } }
        }
    };

    function initCharts() {
        if (!window.Chart || !data) return;

        if (document.getElementById('statusChart')) {
            new Chart(document.getElementById('statusChart'), {
                type: 'doughnut',
                data: { labels: data.status.labels, datasets: [{ data: data.status.values, backgroundColor: data.status.colors, borderWidth: 2, borderColor: '#ffffff' }] },
                options: Object.assign({}, baseOptions, { cutout: '62%' })
            });
        }

        if (document.getElementById('programChart')) {
            new Chart(document.getElementById('programChart'), {
                type: 'bar',
                data: { labels: data.program.labels, datasets: [{ label: 'Students', data: data.program.values, backgroundColor: data.program.colors, borderRadius: 6, maxBarThickness: 34 }] },
                options: Object.assign({}, baseOptions, {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 } } },
                        y: { grid: { display: false }, ticks: { font: { size: 11 } } }
                    }
                })
            });
        }

        if (document.getElementById('docChart')) {
            new Chart(document.getElementById('docChart'), {
                type: 'bar',
                data: { labels: data.doc.labels, datasets: [{ label: 'Requests', data: data.doc.values, backgroundColor: data.doc.colors, borderRadius: 6, maxBarThickness: 40 }] },
                options: Object.assign({}, baseOptions, {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 } } }
                    }
                })
            });
        }

        if (document.getElementById('rfidChart')) {
            new Chart(document.getElementById('rfidChart'), {
                type: 'doughnut',
                data: { labels: data.rfid.labels, datasets: [{ data: data.rfid.values, backgroundColor: data.rfid.colors, borderWidth: 2, borderColor: '#ffffff' }] },
                options: Object.assign({}, baseOptions, { cutout: '62%' })
            });
        }
    }

    // ─── AI REPORT ───────────────────────────────────────────
    var generateBtn   = document.getElementById('generateBtn');
    var reportMonth   = document.getElementById('reportMonth');
    var reportYear    = document.getElementById('reportYear');
    var reportOutput  = document.getElementById('reportOutput');
    var reportEmpty   = document.getElementById('reportEmpty');
    var reportLoading = document.getElementById('reportLoading');
    var reportError   = document.getElementById('reportError');
    var printBtn      = document.getElementById('printBtn');
    var exportBtn     = document.getElementById('exportBtn');
    var reportDisclaimer = document.getElementById('reportDisclaimer');

    function showLoading() {
        reportEmpty.style.display = 'none';
        reportOutput.style.display = 'none';
        reportError.style.display = 'none';
        reportDisclaimer.style.display = 'none';
        reportLoading.style.display = 'block';
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    }

    function hideLoading() {
        reportLoading.style.display = 'none';
        generateBtn.disabled = false;
        generateBtn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate Report';
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function inlineFormat(s) {
        return s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    }

    function renderReport(report) {
        var lines = String(report).split('\n');
        var html = '';
        var cur = null;   // { num, title }
        var body = '';
        var inList = false;

        function flush() {
            if (!cur) return;
            if (inList) { body += '</ul>'; inList = false; }
            var badge = cur.num ? '<span class="report-section-num">' + cur.num + '</span>' : '';
            html += '<div class="report-section report-section-' + (cur.num || 'plain') + '">'
                 + '<div class="report-section-head">' + badge + '<h2>' + inlineFormat(escapeHtml(cur.title)) + '</h2></div>'
                 + body
                 + '</div>';
            body = '';
        }

        lines.forEach(function (line) {
            var numbered = /^##\s+(\d+)\.\s*(.*)$/.exec(line);
            var plain = /^##\s+(.+)$/.exec(line);
            if (numbered) {
                flush();
                cur = { num: parseInt(numbered[1], 10), title: numbered[2].trim() };
            } else if (plain) {
                flush();
                cur = { num: 0, title: plain[1].trim() };
            } else if (/^\s*[-*]\s+/.test(line)) {
                if (!inList) { body += '<ul>'; inList = true; }
                body += '<li>' + inlineFormat(escapeHtml(line.replace(/^\s*[-*]\s+/, ''))) + '</li>';
            } else if (line.trim() === '') {
                if (inList) { body += '</ul>'; inList = false; }
            } else {
                if (inList) { body += '</ul>'; inList = false; }
                body += '<p>' + inlineFormat(escapeHtml(line)) + '</p>';
            }
        });
        flush();
        reportOutput.innerHTML = html;
        printBtn.style.display = 'inline-flex';
        exportBtn.style.display = 'inline-flex';
    }

    function getReportText() {
        return reportOutput.textContent || reportOutput.innerText || '';
    }

    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            showLoading();
            fetch('../api/ai-insights-report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    filters: {
                        month: parseInt(reportMonth.value, 10),
                        year: parseInt(reportYear.value, 10)
                    }
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                hideLoading();
                if (d.success && d.data && d.data.report) {
                    renderReport(d.data.report);
                    reportDisclaimer.style.display = 'block';
                } else {
                    reportError.textContent = d.message || 'Failed to generate report.';
                    reportError.style.display = 'block';
                    reportEmpty.style.display = 'block';
                }
            })
            .catch(function (err) {
                hideLoading();
                reportError.textContent = 'Network error: ' + err.message;
                reportError.style.display = 'block';
                reportEmpty.style.display = 'block';
            });
        });
    }

    if (printBtn) {
        printBtn.addEventListener('click', function () {
            var text = getReportText();
            if (!text) return;
            var w = window.open('', '_blank', 'width=820,height=600');
            if (!w) return;
            w.document.write('<!DOCTYPE html><html><head><title>AI Insight Report</title>');
            w.document.write('<style>body{font-family:Inter,Arial,sans-serif;padding:40px;color:#1e293b;line-height:1.7;max-width:760px;margin:0 auto}');
            w.document.write('h1{font-size:20px;margin:0 0 4px}.meta{color:#64748b;font-size:12px;margin-bottom:24px;border-bottom:1px solid #e2e8f0;padding-bottom:12px}');
            w.document.write('h2{font-size:15px;margin:18px 0 8px}.report-section{border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:12px;page-break-inside:avoid}.report-section-head{display:flex;align-items:center;gap:8px;margin-bottom:4px}.report-section-num{width:22px;height:22px;border-radius:6px;background:#2563eb;color:#fff;font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}ul{margin:4px 0 10px;padding-left:18px}li{margin-bottom:4px}</style></head><body>');
            w.document.write('<h1>Intelligent Analytics — AI Insight Report</h1>');
            w.document.write('<div class="meta">Period: ' + escapeHtml(reportMonth.options[reportMonth.selectedIndex].text) + ' ' + escapeHtml(reportYear.value) + ' · Generated ' + new Date().toLocaleString() + '</div>');
            w.document.write(reportOutput.innerHTML);
            w.document.write('</body></html>');
            w.document.close();
            w.focus();
            w.print();
        });
    }

    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            var text = getReportText();
            if (!text) return;
            var monthName = reportMonth.options[reportMonth.selectedIndex].text;
            var header = 'INTELLIGENT ANALYTICS — AI INSIGHT REPORT\n';
            header += 'Period: ' + monthName + ' ' + reportYear.value + '\n';
            header += 'Generated: ' + new Date().toLocaleString() + '\n';
            header += '============================================================\n\n';
            var blob = new Blob([header + text], { type: 'text/plain;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'ai-insights-report-' + reportYear.value + '-' + ('0' + reportMonth.value).slice(-2) + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }

    initCharts();
})();
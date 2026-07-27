// ============================================================
//  DASHBOARD.JS - Advanced Premium Charts
// ============================================================

document.addEventListener('DOMContentLoaded', function() {

    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded.');
        return;
    }

    // ─── HELPERS ────────────────────────────────────────────────
    function getData(el, key) {
        try {
            const data = el.dataset[key];
            return data ? JSON.parse(data) : [];
        } catch { return []; }
    }

    function hasData(arr) {
        return Array.isArray(arr) && arr.some(v => v > 0);
    }

    // ─── COLORS ────────────────────────────────────────────────────
    const c = {
        blue: '#2563eb',
        blueLight: 'rgba(37, 99, 235, 0.15)',
        blueLighter: 'rgba(37, 99, 235, 0.05)',
        green: '#16a34a',
        greenLight: 'rgba(22, 163, 74, 0.15)',
        purple: '#7c3aed',
        purpleLight: 'rgba(124, 58, 237, 0.15)',
        red: '#dc2626',
        redLight: 'rgba(220, 38, 38, 0.15)',
        yellow: '#b45309',
        yellowLight: 'rgba(180, 83, 9, 0.15)',
        pink: '#db2777',
        pinkLight: 'rgba(219, 39, 119, 0.15)',
        orange: '#b45309',
        orangeLight: 'rgba(180, 83, 9, 0.15)',
        gray: '#94a3b8',
        grayLight: 'rgba(148, 163, 184, 0.15)',
    };

    // ─── 1. ENROLLMENT CHART ──────────────────────────────────────
    const enrollEl = document.getElementById('enrollmentChart');
    if (enrollEl) {
        const labels = getData(enrollEl, 'labels');
        const data = getData(enrollEl, 'data');
        const has = hasData(data);
        const ctx = enrollEl.getContext('2d');

        const gradient = ctx.createLinearGradient(0, 0, 0, 280);
        gradient.addColorStop(0, has ? 'rgba(37, 99, 235, 0.25)' : 'rgba(200, 200, 200, 0.1)');
        gradient.addColorStop(1, has ? 'rgba(37, 99, 235, 0.02)' : 'rgba(200, 200, 200, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels.length ? labels : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Students',
                    data: has ? data : [0, 0, 0, 0, 0, 0],
                    borderColor: has ? c.blue : c.gray,
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: has ? c.blue : c.gray,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: has ? 5 : 3,
                    pointHoverRadius: 7,
                    borderWidth: 2.5,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(255,255,255,0.95)',
                        titleColor: '#0f172a',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' students';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { 
                            stepSize: Math.max(1, Math.ceil(Math.max(...(has ? data : [0])) / 5)),
                            font: { size: 11 }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 } }
                    }
                },
                elements: {
                    line: { borderJoinStyle: 'round' }
                }
            }
        });
    }

    // ─── 2. COURSE DISTRIBUTION ───────────────────────────────────
    const courseEl = document.getElementById('courseChart');
    if (courseEl) {
        const labels = getData(courseEl, 'labels');
        const data = getData(courseEl, 'data');
        const has = hasData(data);
        const colors = ['#2563eb', '#7c3aed', '#16a34a', '#b45309', '#db2777'];

        new Chart(courseEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: has ? labels : ['No Data'],
                datasets: [{
                    data: has ? data : [1],
                    backgroundColor: has ? colors : ['#e2e8f0'],
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 10,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 14,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11, weight: '500' },
                            color: '#1e293b'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255,255,255,0.95)',
                        titleColor: '#0f172a',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                if (!has) return 'No data available';
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ─── 3. RING CHARTS ───────────────────────────────────────────
    function createRing(canvasId, value, max, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const w = canvas.width, h = canvas.height;
        const cx = w / 2, cy = h / 2;
        const radius = 30, lineWidth = 7;
        const pct = max > 0 ? Math.min(value / max, 1) : 0;

        ctx.clearRect(0, 0, w, h);

        // Background
        ctx.beginPath();
        ctx.arc(cx, cy, radius, 0, 2 * Math.PI);
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = lineWidth;
        ctx.stroke();

        // Foreground
        const start = -Math.PI / 2;
        const end = start + (2 * Math.PI * pct);
        ctx.beginPath();
        ctx.arc(cx, cy, radius, start, end);
        ctx.strokeStyle = max > 0 ? color : '#d1d5db';
        ctx.lineWidth = lineWidth;
        ctx.lineCap = 'round';
        ctx.stroke();
    }

    const rc = document.querySelector('.rings-row');
    if (rc) {
        const total = parseInt(rc.dataset.ringTotal) || 0;
        const active = parseInt(rc.dataset.ringActive) || 0;
        const risk = parseInt(rc.dataset.ringRisk) || 0;
        const grad = parseInt(rc.dataset.ringGrad) || 0;
        const maxVal = Math.max(total, 1);

        createRing('ringTotal', total, total, '#2563eb');
        createRing('ringActive', active, maxVal, '#16a34a');
        createRing('ringRisk', risk, maxVal, '#dc2626');
        createRing('ringGrad', grad, maxVal, '#7c3aed');
    }

});
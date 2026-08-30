// ============================================================
//  DASHBOARD.JS - Advanced Premium Charts
// ============================================================

document.addEventListener('DOMContentLoaded', function() {

    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded.');
        return;
    }

    // ─── GLOBAL TYPOGRAPHY ─────────────────────────────────────────
    Chart.defaults.font.family = "'Inter', 'Segoe UI', -apple-system, sans-serif";
    Chart.defaults.font.weight = '500';
    Chart.defaults.color = '#94a3b8';

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

    // Shared dark tooltip — high contrast, no chartjunk.
    const tooltipStyle = {
        backgroundColor: 'rgba(13, 27, 46, 0.94)',
        titleColor: '#fff',
        titleFont: { size: 12, weight: '700' },
        bodyColor: 'rgba(255,255,255,0.82)',
        bodyFont: { size: 12, weight: '500' },
        borderWidth: 0,
        cornerRadius: 9,
        padding: { top: 9, bottom: 9, left: 12, right: 12 },
        displayColors: false,
        caretSize: 5,
    };

    // ─── COLORS ────────────────────────────────────────────────────
    const c = {
        blue: '#2563eb',
        green: '#16a34a',
        purple: '#7c3aed',
        red: '#dc2626',
        yellow: '#b45309',
        pink: '#db2777',
        gray: '#94a3b8',
    };

    // ─── PLUGIN · hover crosshair for the line chart ───────────────
    const crosshair = {
        id: 'crosshair',
        afterDatasetsDraw(chart) {
            const active = chart.getActiveElements();
            if (!active.length) return;
            const x = active[0].element.x;
            const { top, bottom } = chart.chartArea;
            const ctx = chart.ctx;
            ctx.save();
            ctx.beginPath();
            ctx.setLineDash([4, 4]);
            ctx.lineWidth = 1;
            ctx.strokeStyle = 'rgba(37, 99, 235, 0.35)';
            ctx.moveTo(x, top);
            ctx.lineTo(x, bottom);
            ctx.stroke();
            ctx.restore();
        }
    };

    // ─── PLUGIN · centre readout for the doughnut ──────────────────
    const doughnutCentre = {
        id: 'doughnutCentre',
        afterDraw(chart) {
            const meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data.length) return;

            const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
            const label = chart.$centreLabel || 'Students';
            const arc = meta.data[0];
            const { x, y } = arc;
            const ctx = chart.ctx;

            // Scale the readout to the hole so it never overflows the ring
            // on the shorter mobile card heights.
            const inner = arc.innerRadius || 40;
            const big = Math.max(15, Math.min(32, inner * 0.5));
            const small = Math.max(8, big * 0.34);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            ctx.font = '700 ' + big + "px 'Inter', sans-serif";
            ctx.fillStyle = '#0f172a';
            ctx.fillText(chart.$centreValue !== undefined ? chart.$centreValue : total, x, y - small * 0.9);

            ctx.font = '600 ' + small + "px 'Inter', sans-serif";
            ctx.fillStyle = '#94a3b8';
            ctx.fillText(String(label).toUpperCase(), x, y + big * 0.48);
            ctx.restore();
        }
    };

    // ─── 1. ENROLLMENT CHART ──────────────────────────────────────
    const enrollEl = document.getElementById('enrollmentChart');
    if (enrollEl) {
        const labels = getData(enrollEl, 'labels');
        const data = getData(enrollEl, 'data');
        const has = hasData(data);
        const ctx = enrollEl.getContext('2d');

        // Deeper three-stop wash so the area reads as volume, not a flat tint.
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        if (has) {
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.28)');
            gradient.addColorStop(0.55, 'rgba(37, 99, 235, 0.08)');
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0)');
        } else {
            gradient.addColorStop(0, 'rgba(148, 163, 184, 0.12)');
            gradient.addColorStop(1, 'rgba(148, 163, 184, 0)');
        }

        new Chart(ctx, {
            type: 'line',
            plugins: [crosshair],
            data: {
                labels: labels.length ? labels : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Students',
                    data: has ? data : [0, 0, 0, 0, 0, 0],
                    borderColor: has ? c.blue : c.gray,
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.42,
                    borderWidth: 2.5,
                    // Points stay hidden until hovered — the single biggest
                    // difference between a stock chart and a considered one.
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: has ? c.blue : c.gray,
                    pointHoverBorderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 8, right: 4 } },
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            title: items => items[0].label,
                            label: context => context.parsed.y + ' new students'
                        }
                    })
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: {
                            color: '#eef2f7',
                            drawTicks: false,
                        },
                        ticks: {
                            stepSize: Math.max(1, Math.ceil(Math.max(...(has ? data : [0])) / 4)),
                            font: { size: 11, weight: '500' },
                            color: '#a8b3c4',
                            padding: 10,
                        }
                    },
                    x: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: '600' },
                            color: '#a8b3c4',
                            padding: 6,
                        }
                    }
                },
                elements: {
                    line: { borderJoinStyle: 'round', borderCapStyle: 'round' }
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
        const colors = ['#2563eb', '#7c3aed', '#0ea5e9', '#b45309', '#db2777'];

        const chart = new Chart(courseEl.getContext('2d'), {
            type: 'doughnut',
            plugins: [doughnutCentre],
            data: {
                labels: has ? labels : ['No Data'],
                datasets: [{
                    data: has ? data : [1],
                    backgroundColor: has ? colors : ['#eef2f7'],
                    borderWidth: 0,
                    // Rounded caps + gaps make the ring read as segments,
                    // not a sliced pie.
                    borderRadius: has ? 6 : 0,
                    spacing: has ? 3 : 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '76%',
                layout: { padding: { top: 4, bottom: 4 } },
                animation: { duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 16,
                            boxWidth: 7,
                            boxHeight: 7,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11, weight: '600' },
                            color: '#64748b'
                        }
                    },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            label: function(context) {
                                if (!has) return 'No data available';
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.parsed + ' students · ' + pct + '%';
                            }
                        }
                    })
                }
            }
        });

        // Centre readout: total enrolled across the listed courses.
        chart.$centreValue = has ? data.reduce((a, b) => a + b, 0) : '—';
        chart.$centreLabel = has ? 'Enrolled' : 'No data';
        chart.update('none');
    }

});

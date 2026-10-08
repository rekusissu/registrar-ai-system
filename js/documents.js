// ============================================================
//  DOCUMENTS.JS - Registrar documents queue metrics charts.
//  Data-attribute driven, mirroring js/dashboard.js patterns:
//  the page renders <canvas data-labels data-data ...> and this
//  script builds the Chart.js configs.
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded.');
        return;
    }

    Chart.defaults.font.family = "'Inter', 'Segoe UI', -apple-system, sans-serif";
    Chart.defaults.font.weight = '500';
    Chart.defaults.color = '#94a3b8';

    // ─── HELPERS ──────────────────────────────────────────────
    function getData(el, key) {
        try {
            const data = el.dataset[key];
            return data ? JSON.parse(data) : [];
        } catch { return []; }
    }

    function hasData(arr) {
        return Array.isArray(arr) && arr.some(v => v > 0);
    }

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

    const c = {
        blue: '#2563eb',
        lightBlue: '#bfdbfe',
        green: '#16a34a',
        purple: '#7c3aed',
        slate: '#94a3b8',
        amber: '#b45309',
        pink: '#db2777',
    };


    // ─── 1. REVENUE BY DOCUMENT TYPE (bar) ────────────────────
    const revenueEl = document.getElementById('revenueChart');
    if (revenueEl) {
        const labels = getData(revenueEl, 'labels');
        const data = getData(revenueEl, 'data').map(v => Math.round(v * 100) / 100);
        const total = parseFloat(revenueEl.dataset.total || '0') || 0;
        const has = hasData(data);

        new Chart(revenueEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels.length ? labels : ['No Data'],
                datasets: [{
                    label: 'Revenue',
                    data: has ? data : [0],
                    backgroundColor: has ? c.blue : 'rgba(148,163,184,0.18)',
                    hoverBackgroundColor: has ? '#1d4ed8' : 'rgba(148,163,184,0.18)',
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 38,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 8, right: 4 } },
                animation: { duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            title: items => items[0].label,
                            label: ctx => {
                                if (!has) return 'No data available';
                                const val = ctx.parsed.y;
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return '₱' + val.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' · ' + pct + '%';
                            }
                        }
                    })
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#eef2f7', drawTicks: false },
                        ticks: {
                            font: { size: 11, weight: '500' },
                            color: '#a8b3c4',
                            padding: 10,
                            callback: v => '₱' + v,
                        }
                    },
                    x: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: {
                            font: { size: 10.5, weight: '600' },
                            color: '#a8b3c4',
                            padding: 6,
                            maxRotation: 32,
                            minRotation: 32,
                        }
                    }
                }
            }
        });
    }

    // ─── 2. DAILY INTAKE — settled vs still open (stacked) ────
    // Replaces an Express/Regular split that could no longer vary: Express
    // is out of the product, so that chart carried a permanent empty series
    // and answered nothing. This stacks the day's settled work under the
    // work still open, so the height is intake and the amber cap is the
    // backlog — the number a counter desk acts on.
    const volumeEl = document.getElementById('volumeChart');
    if (volumeEl) {
        const days = getData(volumeEl, 'labels');
        const settled = getData(volumeEl, 'settled');
        const outstanding = getData(volumeEl, 'outstanding');
        const has = hasData(settled) || hasData(outstanding);
        const today = days.length ? days[days.length - 1] : null;
        // Today is still accumulating, so it is drawn lighter — a column
        // that looks final when the day is not yet over is a small lie.
        const isToday = i => has && days[i] === today;
        const settledFill = ctx => isToday(ctx.dataIndex) ? '#93b4f7' : c.blue;
        const openFill = ctx => isToday(ctx.dataIndex) ? '#f0c98a' : c.amber;

        new Chart(volumeEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: days.length ? days : ['—'],
                datasets: [
                    {
                        label: 'Settled',
                        data: has ? settled : [0],
                        backgroundColor: settledFill,
                        hoverBackgroundColor: '#1d4ed8',
                        borderRadius: 0,
                        borderSkipped: false,
                        maxBarThickness: 34,
                        stack: 'intake',
                    },
                    {
                        label: 'Still open',
                        data: has ? outstanding : [0],
                        backgroundColor: openFill,
                        hoverBackgroundColor: '#92400e',
                        // Only the top of the stack gets the round cap; the
                        // segment below it must stay square or the join
                        // shows a notch wherever both series are non-zero.
                        borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
                        borderSkipped: false,
                        maxBarThickness: 34,
                        stack: 'intake',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 8, right: 4 } },
                animation: { duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 7,
                            boxHeight: 7,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11, weight: '600' },
                            color: '#64748b',
                        }
                    },
                    tooltip: Object.assign({}, tooltipStyle, {
                        callbacks: {
                            title: items => items[0].label + (items[0].label === today ? ' (today)' : ''),
                            label: ctx => {
                                if (!has) return 'No data available';
                                const n = ctx.parsed.y;
                                if (!n) return null;
                                return ctx.dataset.label + ': ' + n + ' request' + (n === 1 ? '' : 's');
                            },
                            // The sum is the point of a stacked column, and
                            // Chart.js will not print it for us.
                            footer: items => {
                                if (!has) return '';
                                const n = items.reduce((a, b) => a + b.parsed.y, 0);
                                return 'Total: ' + n;
                            }
                        },
                        footerColor: 'rgba(255,255,255,0.55)',
                        footerFont: { size: 11, weight: '600' },
                        footerMarginTop: 6,
                    })
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#eef2f7', drawTicks: false },
                        ticks: {
                            font: { size: 11, weight: '500' },
                            color: '#a8b3c4',
                            padding: 10,
                            // Request counts are whole numbers; a "2.5
                            // requests" tick is noise on a seven-day strip.
                            precision: 0,
                        }
                    },
                    x: {
                        border: { display: false },
                        grid: { display: false },
                        stacked: true,
                        ticks: {
                            font: { size: 10.5, weight: '600' },
                            padding: 6,
                            // Today is inked darker so the eye lands on the
                            // live day without needing a marker under it.
                            color: ctx => (has && ctx.tick.label === today) ? '#334155' : '#a8b3c4',
                        }
                    }
                }
            }
        });
    }


});

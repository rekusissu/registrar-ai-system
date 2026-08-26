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

    // ─── PLUGIN · centre readout for the doughnut ─────────────
    const doughnutCentre = {
        id: 'doughnutCentre',
        afterDraw(chart) {
            const meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data.length) return;
            const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
            const label = chart.$centreLabel || 'Requests';
            const arc = meta.data[0];
            const { x, y } = arc;
            const ctx = chart.ctx;
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

    // ─── 2. DAILY QUEUE VOLUME — Express vs Regular (grouped bar) ─
    const volumeEl = document.getElementById('volumeChart');
    if (volumeEl) {
        const days = getData(volumeEl, 'days');
        const express = getData(volumeEl, 'express');
        const regular = getData(volumeEl, 'regular');
        const has = hasData(express) || hasData(regular);

        new Chart(volumeEl.getContext('2d'), {
            type: 'bar',
            data: {
                labels: days.length ? days : ['—'],
                datasets: [
                    {
                        label: 'Express',
                        data: has ? express : [0],
                        backgroundColor: c.blue,
                        hoverBackgroundColor: '#1d4ed8',
                        borderRadius: 5,
                        borderSkipped: false,
                        maxBarThickness: 16,
                    },
                    {
                        label: 'Regular',
                        data: has ? regular : [0],
                        backgroundColor: c.slate,
                        hoverBackgroundColor: '#64748b',
                        borderRadius: 5,
                        borderSkipped: false,
                        maxBarThickness: 16,
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
                            title: items => items[0].label,
                            label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y + ' request' + (ctx.parsed.y === 1 ? '' : 's')
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
                        }
                    },
                    x: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: {
                            font: { size: 10.5, weight: '600' },
                            color: '#a8b3c4',
                            padding: 6,
                        }
                    }
                }
            }
        });
    }

    // ─── 3. FULFILLMENT SPLIT (doughnut) ──────────────────────
    const fulfillEl = document.getElementById('fulfillmentChart');
    if (fulfillEl) {
        const labels = getData(fulfillEl, 'labels');
        const data = getData(fulfillEl, 'data');
        const total = parseInt(fulfillEl.dataset.total || '0', 10) || 0;
        const has = hasData(data);
        const colors = [c.green, c.lightBlue, c.purple];

        const chart = new Chart(fulfillEl.getContext('2d'), {
            type: 'doughnut',
            plugins: [doughnutCentre],
            data: {
                labels: has ? labels : ['No Data'],
                datasets: [{
                    data: has ? data : [1],
                    backgroundColor: has ? colors : ['#eef2f7'],
                    borderWidth: 0,
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
                            label: function (context) {
                                if (!has) return 'No data available';
                                const t = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = t > 0 ? ((context.parsed / t) * 100).toFixed(1) : 0;
                                return context.label + ' · ' + context.parsed + ' · ' + pct + '%';
                            }
                        }
                    })
                }
            }
        });

        chart.$centreValue = has ? total : '—';
        chart.$centreLabel = has ? 'Total' : 'No data';
        chart.update('none');
    }

});

// ============================================================
//  CHART.JS
//  Chart.js initialization for dashboard reports
// ============================================================

(function() {
    'use strict';

    // ── Check if Chart.js is loaded ──
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded. Charts will not render.');
        return;
    }

    // ── Find all chart canvases ──
    const chartCanvases = document.querySelectorAll('.chart-canvas');

    chartCanvases.forEach(function(canvas) {
        const chartId = canvas.id;
        const dataAttr = canvas.dataset.chartData;
        const configAttr = canvas.dataset.chartConfig;

        if (!chartId) return;

        // ── Default chart data ──
        const defaultData = {
            labels: ['English', 'Science', 'ICT', 'PE', 'Constitution', 'Humanity', 'Center', 'Core1', 'Core2', 'Elective', 'Final'],
            datasets: [
                {
                    label: '2020',
                    data: [75, 82, 78, 85, 80, 88, 72, 79, 83, 86, 81],
                    backgroundColor: '#2563eb',
                    borderRadius: 4,
                    borderSkipped: false,
                },
                {
                    label: '2021',
                    data: [80, 85, 82, 88, 84, 90, 78, 83, 87, 89, 85],
                    backgroundColor: '#ec4899',
                    borderRadius: 4,
                    borderSkipped: false,
                },
                {
                    label: '2022',
                    data: [85, 88, 86, 90, 87, 92, 82, 86, 89, 91, 88],
                    backgroundColor: '#8b5cf6',
                    borderRadius: 4,
                    borderSkipped: false,
                }
            ]
        };

        // ── Parse custom data if provided ──
        let chartData = defaultData;
        if (dataAttr) {
            try {
                const parsed = JSON.parse(dataAttr);
                if (parsed && parsed.datasets) {
                    chartData = parsed;
                }
            } catch (e) {
                console.warn('Invalid chart data for:', chartId);
            }
        }

        // ── Chart configuration ──
        const config = {
            type: 'bar',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255,255,255,0.95)',
                        titleColor: '#0f172a',
                        bodyColor: '#475569',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        cornerRadius: 8,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        };

        // ── Merge custom config if provided ──
        if (configAttr) {
            try {
                const customConfig = JSON.parse(configAttr);
                if (customConfig.options) {
                    config.options = { ...config.options, ...customConfig.options };
                }
                if (customConfig.type) {
                    config.type = customConfig.type;
                }
            } catch (e) {
                console.warn('Invalid chart config for:', chartId);
            }
        }

        // ── Create chart ──
        try {
            new Chart(canvas.getContext('2d'), config);
        } catch (e) {
            console.warn('Failed to create chart:', chartId, e);
        }
    });

    // ── If no chart canvases found, check for gradeChart ──
    if (chartCanvases.length === 0) {
        const gradeChart = document.getElementById('gradeChart');
        if (gradeChart) {
            // Chart will be initialized inline in the page
            // This is just a fallback
            console.log('Grade chart found, waiting for inline initialization...');
        }
    }

})();
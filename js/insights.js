// ============================================================
//  JS/INSIGHTS.JS
//  Intelligent Analytics and Reports
//    · Chart.js rendering for the 4 registrar charts
//    · Reporting Period switching (cards + charts refetch)
//    · Generate AI Insight → 7-section AI ANALYSIS REPORT
//    · Print + Export (PDF / TXT); the print document is ONE file:
//      title (letterhead) → body (sections 1-6) → Conclusion (section 7)
//
//  Reads the first paint from
//  <script type="application/json" id="insightsData">.
//  Chart styling mirrors js/dashboard.js (same typography, dark
//  tooltip, doughnut centre readout) so both pages read as one
//  product.
// ============================================================
(function () {
    'use strict';

    var payloadEl = document.getElementById('insightsData');
    var payload = null;
    try { payload = payloadEl ? JSON.parse(payloadEl.textContent) : null; } catch (e) { payload = null; }
    if (!payload) return;

    var ENDPOINTS = payload.endpoints || {
        data:   '../api/ai-insights-data.php',
        report: '../api/ai-insights-report.php'
    };

    var state = {
        period: payload.period,
        cards:  payload.cards,
        charts: payload.charts,
        report: null,
        source: null,
        stale:  false
    };

    var chartInstances = {};   // canvas id → Chart

    var el = {
        month:      document.getElementById('reportMonth'),
        year:       document.getElementById('reportYear'),
        periodBox:  document.getElementById('periodControl'),
        generate:   document.getElementById('generateBtn'),
        cardGrid:   document.getElementById('statCards'),
        statusBadge: document.getElementById('statusBadge'),
        programBadge: document.getElementById('programBadge'),
        programCanvas: document.getElementById('programChart'),
        programEmptyNote: document.getElementById('programEmptyNote'),
        docBadge:   document.getElementById('docBadge'),
        rfidBadge:  document.getElementById('rfidBadge'),
        rfidSubtitle: document.getElementById('rfidSubtitle'),
        rfidTrendLabel: document.getElementById('rfidTrendLabel'),
        rfidEmptyNote: document.getElementById('rfidEmptyNote'),
        reportOutput: document.getElementById('reportOutput'),
        reportEmpty:  document.getElementById('reportEmpty'),
        reportLoading: document.getElementById('reportLoading'),
        reportError:  document.getElementById('reportError'),
        reportMeta:   document.getElementById('reportMeta'),
        reportSourceBadge: document.getElementById('reportSourceBadge'),
        reportMetaText: document.getElementById('reportMetaText'),
        // The loud degradation banner and the measured-figures block. Both are
        // new, and both are about the same thing: never letting a reader
        // mistake counts for analysis. See renderReport().
        reportUnavailable:       document.getElementById('reportUnavailable'),
        reportUnavailableReason: document.getElementById('reportUnavailableReason'),
        reportFigures:           document.getElementById('reportFigures'),
        reportFiguresBody:       document.getElementById('reportFiguresBody'),
        reportFooter: document.getElementById('reportFooter'),
        printBtn:   document.getElementById('printBtn'),
        exportBtn:  document.getElementById('exportBtn'),
        exportMenu: document.getElementById('exportMenu'),
        exportPdf:  document.getElementById('exportPdf'),
        exportTxt:  document.getElementById('exportTxt')
    };

    // ─── Chart.js global look (identical to the dashboard) ──────
    // Neutral chrome is aligned to the registrar-blue tokens used by
    // css/registrar*.css. Series colours are NOT touched: they come
    // from shared/analytics.php and are shared with dashboard.php.
    var T = {
        ink:    '#0f172a',
        muted:  '#64748b',
        faint:  '#94a3b8',
        grid:   '#f1f5f9',
        axis:   '#e2e8f0',
        brand:  '#2563eb',
        deep:   '#1d4ed8',
        pale:   '#93c5fd'
    };

    if (window.Chart) {
        Chart.defaults.font.family = "'Inter', 'Segoe UI', -apple-system, sans-serif";
        Chart.defaults.font.weight = '500';
        Chart.defaults.color = T.muted;
    }

    var tooltipStyle = {
        backgroundColor: 'rgba(13, 27, 46, 0.94)',
        titleColor: '#fff',
        titleFont: { size: 12, weight: '700' },
        bodyColor: 'rgba(255,255,255,0.82)',
        bodyFont: { size: 12, weight: '500' },
        borderWidth: 0,
        cornerRadius: 9,
        padding: { top: 9, bottom: 9, left: 12, right: 12 },
        displayColors: false,
        caretSize: 5
    };

    var legendStyle = {
        position: 'bottom',
        labels: {
            padding: 14,
            boxWidth: 7,
            boxHeight: 7,
            usePointStyle: true,
            pointStyle: 'circle',
            font: { size: 11, weight: '600' },
            color: '#64748b'
        }
    };

    // Centre readout inside a doughnut (total + label), as on the dashboard.
    var doughnutCentre = {
        id: 'doughnutCentre',
        afterDraw: function (chart) {
            var meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data.length) return;

            var total = chart.data.datasets[0].data.reduce(function (a, b) { return a + b; }, 0);
            var arc = meta.data[0];
            var ctx = chart.ctx;
            var inner = arc.innerRadius || 40;
            var big = Math.max(14, Math.min(26, inner * 0.5));
            var small = Math.max(8, big * 0.36);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '700 ' + big + "px 'Inter', sans-serif";
            ctx.fillStyle = T.ink;
            ctx.fillText(String(chart.$centreValue == null ? total : chart.$centreValue), arc.x, arc.y - small * 0.4);
            ctx.font = '600 ' + small + "px 'Inter', sans-serif";
            ctx.fillStyle = T.faint;
            ctx.fillText(String(chart.$centreLabel || 'Total'), arc.x, arc.y + small * 1.1);
            ctx.restore();
        }
    };

    // Lollipop terminals. The datasets below draw only a thin stem; this
    // draws the dot that the eye actually lands on, plus the value beside
    // it, so a 4px stem never has to do the work of a full bar.
    var lollipopEnds = {
        id: 'lollipopEnds',
        afterDatasetsDraw: function (chart) {
            var totals = chart.$barTotals;
            if (!totals || !totals.length) return;

            // Stacked stems each report their own right edge; the furthest
            // one per row is the true bar end.
            var metas = chart.data.datasets.map(function (_, di) {
                return chart.getDatasetMeta(di);
            });
            var radius = chart.$lollipopRadius || 5.5;

            var ctx = chart.ctx;
            ctx.save();

            totals.forEach(function (total, i) {
                var right = null, y = null;
                for (var m = 0; m < metas.length; m++) {
                    var bar = metas[m] && metas[m].data && metas[m].data[i];
                    if (!bar) continue;
                    if (right === null || bar.x > right) right = bar.x;
                    if (y === null) y = bar.y;
                }
                if (right === null || y === null) return;

                // No dot for an empty row — it would read as a real value.
                if (total > 0) {
                    ctx.beginPath();
                    ctx.arc(right, y, radius, 0, Math.PI * 2);
                    ctx.fillStyle = T.deep;
                    ctx.fill();
                    // White halo separates the dot from the stem it caps.
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#fff';
                    ctx.stroke();
                }

                ctx.font = "700 11px 'Inter', 'Segoe UI', -apple-system, sans-serif";
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = T.ink;
                ctx.fillText(String(total), right + radius + 8, y);
            });
            ctx.restore();
        }
    };

    // ─── Helpers ────────────────────────────────────────────────
    function hasData(arr) {
        return Array.isArray(arr) && arr.some(function (v) { return v > 0; });
    }

    function truncateLabel(text, max) {
        var s = String(text == null ? '' : text);
        return s.length > max ? s.slice(0, max - 1) + '…' : s;
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function mount(id) {
        var canvas = document.getElementById(id);
        return canvas ? canvas.getContext('2d') : null;
    }

    function destroyChart(id) {
        if (chartInstances[id]) {
            chartInstances[id].destroy();
            delete chartInstances[id];
        }
    }

    function num(value) {
        return Number(value || 0).toLocaleString();
    }


    // ─── Charts ─────────────────────────────────────────────────
    function renderCharts(data) {
        var charts = data.charts || {};
        var periodLabel = (data.period && data.period.label) || state.period.label;

        // ── 1 · Student status distribution (doughnut) ──
        var statusCtx = mount('statusChart');
        if (statusCtx) {
            destroyChart('statusChart');
            var s = charts.status || { labels: [], values: [], colors: [] };
            var sHas = hasData(s.values);
            chartInstances.statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                plugins: [doughnutCentre],
                data: {
                    labels: sHas ? s.labels : ['No data'],
                    datasets: [{
                        data: sHas ? s.values : [1],
                        backgroundColor: sHas ? s.colors : ['#eef2f7'],
                        borderWidth: 0,
                        borderRadius: sHas ? 6 : 0,
                        spacing: sHas ? 3 : 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '76%',
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: legendStyle,
                        tooltip: Object.assign({}, tooltipStyle, {
                            callbacks: {
                                label: function (ctx) {
                                    if (!sHas) return 'No students on record';
                                    var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                    var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0.0';
                                    return ctx.parsed + ' students · ' + pct + '%';
                                }
                            }
                        })
                    }
                }
            });
            chartInstances.statusChart.$centreValue = sHas ? num(s.total != null ? s.total : s.values.reduce(function (a, b) { return a + b; }, 0)) : '—';
            chartInstances.statusChart.$centreLabel = sHas ? 'Students' : 'No data';
            chartInstances.statusChart.update('none');
        }

        // ── 2 · Student program distribution (ranked horizontal bars) ──
        //
        // Orientation: this chart was always authored for a horizontal
        // layout — scales.x carries beginAtZero/grid/precision and scales.y
        // turns its grid off, which is the value/category split of a
        // horizontal bar. Without indexAxis:'y' Chart.js put the categories
        // on x, so program names rendered as 40°-rotated ticks, the gridlines
        // landed on the wrong axis, and the tooltip read ctx.parsed.x (the
        // category index) instead of the enrolment count.
        //
        // Encoding: bars used to be coloured per program while the axis
        // label already named the program — eight competing hues carrying no
        // information, under a legend whose swatches matched none of them.
        // Colour now means one thing only: the existing cohort vs. what
        // arrived this period, with the count printed at each bar's end.
        var programCtx = mount('programChart');
        if (programCtx) {
            destroyChart('programChart');
            var p = charts.program || { labels: [], values: [], new: [] };
            var pHas = hasData(p.values);

            if (el.programEmptyNote) el.programEmptyNote.classList.toggle('is-empty', !pHas);
            if (el.programCanvas) el.programCanvas.style.display = pHas ? '' : 'none';
            if (el.programBadge) el.programBadge.textContent = pHas ? p.labels.length + ' programs' : 'No data';

            if (pHas) {
                // Rank by enrolment so position carries the ranking.
                var order = p.values
                    .map(function (_, i) { return i; })
                    .sort(function (a, b) { return p.values[b] - p.values[a]; });

                var names = order.map(function (i) { return String(p.labels[i] == null ? '' : p.labels[i]); });
                var totals = order.map(function (i) { return Number(p.values[i]) || 0; });
                // Guard against a new count exceeding the total it belongs to.
                var fresh = order.map(function (i) {
                    return Math.max(0, Math.min(Number((p.new || [])[i]) || 0, Number(p.values[i]) || 0));
                });
                var earlier = totals.map(function (t, i) { return Math.max(0, t - fresh[i]); });

                var programChart = new Chart(programCtx, {
                    type: 'bar',
                    plugins: [lollipopEnds],
                    data: {
                        labels: names.map(function (l) { return truncateLabel(l, 30); }),
                        datasets: [
                            {
                                label: 'Earlier students',
                                data: earlier,
                                backgroundColor: '#c7dcfd',
                                borderRadius: 2,
                                borderSkipped: 'start',
                                maxBarThickness: 4,
                                stack: 'enrolment'
                            },
                            {
                                label: 'New this period',
                                data: fresh,
                                backgroundColor: T.deep,
                                borderRadius: 2,
                                borderSkipped: 'start',
                                maxBarThickness: 4,
                                stack: 'enrolment'
                            }
                        ]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        // A 4px stem is a poor hover target, so the whole
                        // row band is made interactive instead.
                        interaction: { mode: 'index', intersect: false },
                        layout: { padding: { right: 48, top: 2 } },
                        animation: { duration: 800, easing: 'easeOutQuart' },
                        plugins: {
                            legend: legendStyle,
                            tooltip: Object.assign({}, tooltipStyle, {
                                displayColors: true,
                                callbacks: {
                                    title: function (items) {
                                        return names[items[0].dataIndex] || items[0].label;
                                    },
                                    label: function (ctx) {
                                        return ctx.dataset.label + ': ' + num(ctx.parsed.x);
                                    },
                                    footer: function (items) {
                                        if (!items.length) return '';
                                        var total = totals[items[0].dataIndex];
                                        return 'Total: ' + num(total) + ' student' + (total === 1 ? '' : 's');
                                    }
                                }
                            })
                        },
                        scales: {
                            x: {
                                stacked: true,
                                beginAtZero: true,
                                grid: { color: T.grid },
                                border: { display: false },
                                ticks: {
                                    font: { size: 11, weight: '500' },
                                    color: T.faint,
                                    padding: 6,
                                    precision: 0,
                                    maxTicksLimit: 6
                                }
                            },
                            y: {
                                stacked: true,
                                grid: { display: false },
                                border: { display: false },
                                ticks: {
                                    font: { size: 11.5, weight: '600' },
                                    color: T.ink,
                                    padding: 10,
                                    autoSkip: false,
                                    crossAlign: 'far'
                                }
                            }
                        }
                    }
                });
                programChart.$barTotals = totals;
                chartInstances.programChart = programChart;
            }
        }

        // ── 3 · Document transaction overview (stacked bar) ──
        var docCtx = mount('docChart');
        if (docCtx) {
            destroyChart('docChart');
            var d = charts.doc || { labels: [], series: [], totals: [], total: 0 };
            var dHas = hasData(d.totals);
            var docDatasets = (d.series || []).map(function (series) {
                return {
                    label: series.label,
                    data: series.values,
                    backgroundColor: series.color,
                    borderRadius: 4,
                    maxBarThickness: 46,
                    stack: 'requests'
                };
            });
            if (!docDatasets.length) {
                docDatasets = [{
                    label: 'Requests',
                    data: (d.labels || []).map(function () { return 0; }),
                    backgroundColor: '#eef2f7',
                    borderRadius: 4
                }];
            }
            chartInstances.docChart = new Chart(docCtx, {
                type: 'bar',
                data: { labels: d.labels || [], datasets: docDatasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: legendStyle,
                        tooltip: Object.assign({}, tooltipStyle, {
                            displayColors: true,
                            callbacks: {
                                label: function (ctx) {
                                    if (!dHas) return 'No requests in this period';
                                    return ctx.dataset.label + ': ' + ctx.parsed.y;
                                },
                                footer: function (items) {
                                    if (!items.length) return '';
                                    var bucket = items[0].dataIndex;
                                    var total = (d.totals && d.totals[bucket] != null) ? d.totals[bucket] : 0;
                                    return 'Total: ' + total + ' request' + (total === 1 ? '' : 's');
                                }
                            }
                        })
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: { display: false },
                            border: { display: false },
                            ticks: { font: { size: 11, weight: '600' }, color: '#64748b', padding: 6, autoSkip: false, maxRotation: 40, minRotation: 0 }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            grid: { color: T.grid },
                            border: { display: false },
                            ticks: { font: { size: 11, weight: '500' }, color: T.muted, padding: 8, precision: 0 }
                        }
                    }
                }
            });
        }

        // ── 4 · RFID overview (status doughnut + activity trend) ──
        var r = charts.rfid || { labels: [], values: [], colors: [], trend_labels: [], trend_values: [], trend_source: 'none', highlight: 0 };

        var rfidCtx = mount('rfidChart');
        if (rfidCtx) {
            destroyChart('rfidChart');
            var rHas = hasData(r.values);
            if (el.rfidEmptyNote) el.rfidEmptyNote.style.display = rHas ? 'none' : 'block';
            chartInstances.rfidChart = new Chart(rfidCtx, {
                type: 'doughnut',
                plugins: [doughnutCentre],
                data: {
                    labels: rHas ? r.labels : ['No cards'],
                    datasets: [{
                        data: rHas ? r.values : [1],
                        backgroundColor: rHas ? r.colors : ['#eef2f7'],
                        borderWidth: 0,
                        borderRadius: rHas ? 6 : 0,
                        spacing: rHas ? 3 : 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '74%',
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: rHas ? legendStyle : { display: false },
                        tooltip: Object.assign({}, tooltipStyle, {
                            callbacks: {
                                label: function (ctx) {
                                    if (!rHas) return 'No RFID cards issued yet';
                                    return ctx.parsed + ' cards';
                                }
                            }
                        })
                    }
                }
            });
            chartInstances.rfidChart.$centreValue = rHas ? num(r.total) : '0';
            chartInstances.rfidChart.$centreLabel = rHas ? 'Cards' : 'None yet';
            chartInstances.rfidChart.update('none');
        }

        var trendCtx = mount('rfidTrendChart');
        if (trendCtx) {
            destroyChart('rfidTrendChart');
            var trendValues = r.trend_values || [];
            var highlight = Number(r.highlight != null ? r.highlight : trendValues.length - 1);
            chartInstances.rfidTrendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: r.trend_labels || [],
                    datasets: [{
                        label: r.trend_source === 'cards' ? 'Cards issued' : 'Kiosk taps',
                        data: trendValues,
                        borderColor: T.brand,
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: true,
                        pointRadius: trendValues.map(function (v, i) { return i === highlight ? 6 : 3; }),
                        pointBackgroundColor: trendValues.map(function (v, i) { return i === highlight ? T.deep : T.pale; }),
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 800, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltipStyle, {
                            callbacks: {
                                title: function (items) {
                                    return items[0].label + (items[0].dataIndex === highlight ? ' · reporting period' : '');
                                },
                                label: function (ctx) {
                                    return ctx.parsed.y + (r.trend_source === 'cards' ? ' cards issued' : ' kiosk taps');
                                }
                            }
                        })
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { font: { size: 10, weight: '600' }, color: T.muted, maxRotation: 0, autoSkipPadding: 8 }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: T.grid },
                            border: { display: false },
                            ticks: { font: { size: 10, weight: '500' }, color: T.muted, padding: 6, precision: 0, maxTicksLimit: 4 }
                        }
                    }
                }
            });
        }

        // ── Badges and labels tied to the reporting period ──
        if (el.statusBadge) el.statusBadge.textContent = periodLabel;
        if (el.docBadge)    el.docBadge.textContent    = periodLabel;
        if (el.rfidBadge)   el.rfidBadge.textContent   = periodLabel;
        if (el.rfidTrendLabel) {
            el.rfidTrendLabel.textContent = r.trend_source === 'cards'
                ? 'Cards issued — last 12 months'
                : (r.trend_source === 'scans' ? 'Kiosk taps — last 12 months' : 'Activity — last 12 months');
        }
        if (el.rfidSubtitle) {
            el.rfidSubtitle.textContent = r.trend_source === 'scans'
                ? 'No cards registered yet — showing kiosk tap activity'
                : 'Card lifecycle and issuance activity';
        }
    }

    // ─── KPI cards ──────────────────────────────────────────────
    var trendGlyph = { up: 'fa-arrow-up', down: 'fa-arrow-down', flat: 'fa-minus' };

    function renderCards(cards) {
        if (!el.cardGrid || !Array.isArray(cards)) return;
        cards.forEach(function (card) {
            var box = el.cardGrid.querySelector('[data-card="' + card.key + '"]');
            if (!box) return;

            var valueEl = box.querySelector('.stat-value');
            if (valueEl) valueEl.textContent = num(card.value);

            var labelEl = box.querySelector('.stat-label');
            if (labelEl) labelEl.textContent = card.label;

            var trendEl = box.querySelector('.stat-trend');
            if (trendEl) {
                trendEl.className = 'stat-trend ' + card.badge.dir;
                trendEl.title = 'Compared with ' + (state.period.prev_label || 'the previous period');
                var icon = trendEl.querySelector('i');
                if (icon) icon.className = 'fas ' + (trendGlyph[card.badge.dir] || 'fa-minus');
                var textEl = trendEl.querySelector('.stat-trend-text');
                if (textEl) textEl.textContent = card.badge.text;
            }

            var footerEl = box.querySelector('.stat-footer');
            if (footerEl) {
                var dot = footerEl.querySelector('.dot');
                if (dot) dot.className = 'dot ' + card.footer.dot;
                var footerText = footerEl.querySelector('.stat-footer-text');
                if (footerText) footerText.textContent = card.footer.text;
            }
        });
    }

    // ─── Reporting period ───────────────────────────────────────
    function markReportStale(isStale) {
        state.stale = !!isStale;
        if (!state.report) return;
        if (isStale) {
            if (el.reportMetaText) {
                el.reportMetaText.textContent = 'Data refreshed for ' + (state.period.label || 'a new period')
                    + ' — select Generate AI Insight to update the analysis.';
            }
            if (el.reportSourceBadge) {
                el.reportSourceBadge.textContent = 'Out of date';
                el.reportSourceBadge.classList.add('is-fallback');
            }
        }
    }

    function loadPeriod() {
        var month = parseInt(el.month.value, 10);
        var year  = parseInt(el.year.value, 10);

        if (el.periodBox) el.periodBox.classList.add('is-busy');
        showReportError('');

        fetch(ENDPOINTS.data + '?month=' + encodeURIComponent(month) + '&year=' + encodeURIComponent(year), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success || !json.data) {
                    throw new Error((json && json.message) || 'Unable to load analytics for that period.');
                }
                state.period = Object.assign({}, state.period, json.data.period);
                state.cards  = json.data.cards;
                state.charts = json.data.charts;

                renderCards(state.cards);
                renderCharts({ period: state.period, charts: state.charts });
                markReportStale(true);
            })
            .catch(function (err) {
                showReportError('Could not refresh the dashboard: ' + err.message);
            })
            .then(function () {
                if (el.periodBox) el.periodBox.classList.remove('is-busy');
            });
    }

    // ─── Report states ──────────────────────────────────────────
    function showReportError(message) {
        if (!el.reportError) return;
        if (!message) {
            el.reportError.style.display = 'none';
            el.reportError.textContent = '';
            return;
        }
        el.reportError.textContent = message;
        el.reportError.style.display = 'block';
    }



    // ─── AI ANALYSIS REPORT rendering ───────────────────────────
    function inlineFormat(str) {
        return str.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    }

    /** Markdown → HTML for the three-section report shape. */
    function markdownToHtml(report) {
        var lines = String(report).split('\n');
        var html = '';
        var current = null;   // { num, title }
        var body = '';
        var inList = false;

        function flush() {
            if (!current) return;
            if (inList) { body += '</ul>'; inList = false; }
            var badge = current.num ? '<span class="report-section-num">' + current.num + '</span>' : '';
            html += '<div class="report-section report-section-' + (current.num || 'plain') + '">'
                + '<div class="report-section-head">' + badge + '<h2>' + inlineFormat(escapeHtml(current.title)) + '</h2></div>'
                + body
                + '</div>';
            body = '';
        }

        lines.forEach(function (line) {
            var numbered = /^##\s+(\d+)\.\s*(.*)$/.exec(line);
            var plain    = /^##\s+(.+)$/.exec(line);
            if (numbered) {
                flush();
                current = { num: parseInt(numbered[1], 10), title: numbered[2].trim() };
            } else if (plain) {
                flush();
                current = { num: 0, title: plain[1].trim() };
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
        return html;
    }

    /**
     * Split the report Markdown into its printable parts.
     *
     * The endpoint CONTRACTS seven numbered sections, and the seventh is the
     * conclusion by design — cross-module findings plus recommended actions
     * (api/ai-insights-report.php requires '## 7.' before it will serve a
     * report at all). The print document honours that structure: sections
     * 1-6 print as the body, section 7 prints under its own "Conclusion"
     * heading. So the Markdown is parsed into section records here rather
     * than flattened straight to HTML, and openPrintView() decides where
     * each part goes.
     *
     * A section is { num, title, lines }. Lines before the first heading
     * (the endpoint forbids any) are dropped rather than printed naked.
     */
    function splitReportSections(report) {
        var body = [];
        var conclusion = null;
        var current = null;

        String(report).split('\n').forEach(function (line) {
            // "## 1. Title" keeps its number: the model contract guarantees
            // seven numbered sections, and a formal report cites them by
            // number. "## Title" (no number) is used as-is, with num 0.
            var numbered = /^##\s+(\d+)\.\s*(.+)$/.exec(line);
            var heading  = /^##\s+(.+)$/.exec(line);

            if (numbered || heading) {
                current = numbered
                    ? { num: parseInt(numbered[1], 10), title: numbered[2].trim(), lines: [] }
                    : { num: 0, title: heading[1].trim(), lines: [] };
                if (current.num === 7) {
                    conclusion = current;
                } else {
                    body.push(current);
                }
            } else if (current) {
                current.lines.push(line);
            }
        });

        return { body: body, conclusion: conclusion };
    }

    /**
     * The printable rendering of one section's lines: plain paragraphs and
     * bullets, with no card, badge or border wrappers.
     *
     * markdownToHtml() cannot be reused here. It is shared with the on-screen
     * view, where the boxed .report-section cards are wanted, and its output
     * is <div>-wrapped. Stripping the wrappers by regex here would be brittle
     * — it would depend on the exact class strings the other function emits,
     * so an unrelated restyle of the screen view could quietly corrupt the
     * printout. Parsing the same small Markdown subset directly keeps the two
     * renderings independent.
     */
    function sectionLinesHtml(lines) {
        var html = '';
        var inList = false;

        function closeList() {
            if (inList) { html += '</ul>'; inList = false; }
        }

        lines.forEach(function (line) {
            var bullet = /^\s*[-*]\s+(.+)$/.exec(line);
            if (bullet) {
                if (!inList) { html += '<ul>'; inList = true; }
                html += '<li>' + inlineFormat(escapeHtml(bullet[1])) + '</li>';
            } else if (line.trim() === '') {
                closeList();
            } else {
                closeList();
                html += '<p>' + inlineFormat(escapeHtml(line.trim())) + '</p>';
            }
        });
        closeList();
        return html;
    }

    /** The body of the printout: each section's numbered heading, then its
     *  lines. The conclusion is NOT included — openPrintView() prints it as
     *  its own block after this. */
    function reportBodyHtml(sections) {
        return sections.map(function (s) {
            var title = s.num ? s.num + '. ' + s.title : s.title;
            return '<h2 class="doc-h">' + inlineFormat(escapeHtml(title)) + '</h2>'
                + sectionLinesHtml(s.lines);
        }).join('');
    }

    function showLoading() {
        el.reportEmpty.style.display = 'none';
        el.reportOutput.style.display = 'none';
        el.reportMeta.style.display = 'none';
        el.reportFooter.style.display = 'none';
        showReportError('');
        el.reportLoading.style.display = 'block';
        el.generate.disabled = true;
        el.generate.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    }

    function hideLoading() {
        el.reportLoading.style.display = 'none';
        el.generate.disabled = false;
        // A report already exists for this period → the next click
        // regenerates it with a forced fresh model call.
        el.generate.innerHTML = state.report
            ? '<i class="fas fa-rotate"></i> Regenerate AI Insight'
            : '<i class="fas fa-wand-magic-sparkles"></i> Generate AI Insight';
    }

    // ── The report, the banner, and the figures are three different things ──
    //
    // They used to be one. The endpoint returned a "rule-based summary" in the
    // same slot the model's text went in, under the same headings and the same
    // title, and the only signal that no model had run was a small amber badge
    // and a sentence of meta text. That is the shape of output that gets
    // believed: it is well-formed, it cites figures, and it concludes.
    //
    // So the three are now kept apart.
    //
    //   report  - the model's narrative, or NOTHING.
    //   banner  - shown when there is no model output, saying so in a sentence
    //             a skimming reader cannot miss.
    //   figures - the database counts, always, in a ledger treatment that is
    //             deliberately unlike the report.
    //
    // A reader can now answer "who wrote this?" without reading a word of it.
    function renderReport(report, meta) {
        state.report = report;
        state.source = meta.source || 'ai';
        if (meta.cards)  state.cards  = meta.cards;
        state.stale = false;

        var hasAi = state.source === 'ai' && !!report;

        // The banner. Its presence is the honest signal; the badge is a
        // secondary confirmation, not the primary one.
        if (el.reportUnavailable) {
            if (hasAi) {
                el.reportUnavailable.style.display = 'none';
            } else {
                el.reportUnavailable.style.display = 'block';
                if (el.reportUnavailableReason) {
                    el.reportUnavailableReason.textContent = meta.ai_error
                        ? 'Reported by the gateway: ' + meta.ai_error
                        : 'No reason was reported.';
                }
            }
        }

        // The narrative slot. Left genuinely empty on fallback - not filled with
        // the figures, and not filled with a template.
        if (hasAi) {
            el.reportOutput.innerHTML = '<div class="ai-report-title"><i class="fas fa-chart-line"></i> AI Analysis Report</div>'
                + markdownToHtml(report);
            el.reportOutput.style.display = 'block';
            el.reportFooter.style.display = 'block';
        } else {
            el.reportOutput.innerHTML = '';
            el.reportOutput.style.display = 'none';
            // No model output means there is nothing to attribute to the model,
            // so the AI disclaimer does not belong on this card.
            el.reportFooter.style.display = 'none';
        }

        // The measured figures, always, in their own language.
        if (el.reportFigures && el.reportFiguresBody) {
            if (meta.figures) {
                el.reportFiguresBody.textContent = meta.figures;
                el.reportFigures.style.display = 'block';
            } else {
                el.reportFigures.style.display = 'none';
            }
        }

        if (el.reportSourceBadge) {
            if (hasAi) {
                el.reportSourceBadge.textContent = 'AI-generated' + (meta.model ? ' · ' + meta.model : '');
                el.reportSourceBadge.classList.remove('is-fallback');
            } else {
                el.reportSourceBadge.textContent = 'No AI analysis · counts only';
                el.reportSourceBadge.classList.add('is-fallback');
            }
        }
        if (el.reportMetaText) {
            var text = (meta.period && meta.period.label) ? meta.period.label : state.period.label;
            // The time the analysis was WRITTEN, not the time this page was
            // opened. The server sends that deliberately: a "generated" clock
            // that resets every reload makes a month-old analysis look
            // like it was just produced.
            text += ' · generated ' + new Date(meta.generated_at || Date.now()).toLocaleString();
            if (meta.cached) {
                // Say why it appeared instantly. A report that renders in a
                // second after the last one took a minute reads as a broken
                // button unless it is explained.
                text += ' · reused — the figures had not changed';
            }
            el.reportMetaText.textContent = text;
        }
        el.reportMeta.style.display = 'flex';

        // Print and export only make sense when there is a report to carry. With
        // counts alone they would export a page of figures under a title that
        // promises analysis.
        el.printBtn.style.display  = hasAi ? 'inline-flex' : 'none';
        el.exportBtn.style.display = hasAi ? 'inline-flex' : 'none';
    }

/**
     * Explain a non-JSON response from the server.
     *
     * This endpoint is the only one that takes a minute, so it is the only one
     * where the web server is likely to give up on it. The statuses that reach
     * here have causes that are indistinguishable from the browser but fixed in
     * completely different places - PHP settings, the server, or something in
     * front of the server - so the status alone sends the operator to the wrong
     * one every time.
     *
     * The snippet of what actually arrived is included because it settles the
     * question outright: Apache's mod_evasive, PHP-FPM's "service temporarily
     * unavailable", and Cloudflare's own error page all carry identifying text,
     * and the operator can match it in one look instead of guessing.
     *
     * Returns plain text. The caller renders it, and nothing here is ever put
     * into innerHTML, so the server's body cannot inject markup.
     */
    function describeFailedResponse(status, snippet) {
        var s = String(snippet || '');
        var looksLikeHtml = s.indexOf('<') === 0;

        var base;
        if (status === 503 || status === 502 || status === 504) {
            base = 'The web server could not complete the request (HTTP ' + status + '), '
                 + 'so no analysis was produced. The page itself is unaffected and the '
                 + 'measured figures are unaffected - this is the server refusing a long '
                 + 'request, not the AI service.\n\n'
                 + 'Most likely, in order:\n'
                 + '• A proxy in front of the site (Cloudflare, or the host\'s own) gives up '
                 + 'before the ~60 seconds a full report takes. Raise its read timeout.\n'
                 + '• PHP is being killed mid-request. .user.ini sets max_execution_time to '
                 + '180; confirm the host is honouring it rather than overriding it.\n'
                 + '• The host is rate limiting this IP, which is common on shared hosting '
                 + 'and returns 503 with no further detail. Waiting a minute and retrying '
                 + 'will show whether it is that.';
        } else if ((status === 200 || status === 500) && looksLikeHtml) {
            base = 'The server stopped before it could reply (HTTP ' + status + '). A full '
                 + 'report takes 40-60 seconds and this host is cutting PHP off before that '
                 + 'finishes. Raise max_execution_time to 180 — .user.ini already requests '
                 + 'it, so the host is overriding it.';
        } else {
            base = 'The server returned a response that was not JSON (HTTP ' + status + ').';
        }

        // The single most useful clue, so it is always shown. Trimmed and
        // flattened because a wall of HTML helps nobody.
        var evidence = s.replace(/\s+/g, ' ').trim().slice(0, 220);
        return evidence ? base + '\n\nThe server sent: "' + evidence + '"' : base;
    }

    function generateReport(force) {
        showLoading();
        var started = Date.now();

        // A bare spinner over a 40-70 second call is indistinguishable from
        // a hung request, so the user clicks again and pays for a second
        // identical call. Show the clock instead: it is the one thing that
        // tells them this is working and roughly how much is left.
        var loadingText = document.getElementById('reportLoadingText');
        var tick = window.setInterval(function () {
            if (!loadingText) return;
            var secs = Math.round((Date.now() - started) / 1000);
            if (secs < 15) {
                loadingText.textContent = 'Analysing registrar data…';
            } else if (secs < 45) {
                loadingText.textContent = 'Writing the full seven-section analysis… ' + secs + 's';
            } else {
                // Past the measured 40-65s window. Say the honest thing:
                // still writing, still expected. A message that admits the
                // wait is long is far less alarming than silence at 50s.
                loadingText.textContent = 'Still writing — a full report takes up to a minute. ' + secs + 's';
            }
        }, 1000);

        // The POST returns in about a second now, not a minute. What follows is the
    // wait: a cheap GET every few seconds until the finished report lands in
    // the cache.
    //
    // WHY THIS, RATHER THAN ONE LONG REQUEST
    //
    // The generation itself takes 40-70 seconds and always did. What changed is
    // that no single HTTP request does any of it: the server answers "pending"
    // immediately and finishes the work after the response is gone. The proxy
    // in front of PHP never gets a long request to give up on, which is the
    // only fix that survives a shared host - raising max_execution_time did
    // nothing, because the timeout was never PHP's.
    //
    // Polling is safe here because the poll is a cache read, not a second
    // generation. That distinction is the whole design: the expensive thing
    // happens exactly once.
    var pollUrl = ENDPOINTS.report
        + '?month=' + encodeURIComponent(el.month.value)
        + '&year=' + encodeURIComponent(el.year.value);
    var waited = 0;
    var POLL_EVERY_MS = 4000;
    var POLL_GIVE_UP_MS = 180000;

    function pollOnce() {
        return fetch(pollUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (res) { return res.text(); })
            .then(function (text) {
                try { return JSON.parse(text); } catch (e) { return null; }
            });
    }

    function startPolling() {
        waited = 0;
        function again() {
            waited += POLL_EVERY_MS;
            if (waited > POLL_GIVE_UP_MS) {
                // The job is genuinely stuck or the server is gone. Say so
                // plainly rather than spinning forever: the honest failure is
                // that we stopped waiting, not that the analysis failed.
                window.clearInterval(tick);
                hideLoading();
                showReportError('The analysis is taking longer than expected and has not finished yet. '
                    + 'Close this page and open it again shortly - the report is saved as soon as it is written, '
                    + 'so you will not pay for it twice.');
                el.reportEmpty.style.display = 'block';
                return;
            }
            pollOnce().then(function (j) {
                if (j && j.success && j.data && j.data.status === 'ready' && j.data.report) {
                    window.clearInterval(tick);
                    hideLoading();
                    // Re-fetched through the normal path so figures, source
                    // badge and period all come from one authoritative
                    // response rather than being assembled here.
                    generateReport(false);
                    return;
                }
                if (j && j.success && j.data && j.data.status === 'ready') {
                    // Ready, but the job that was running did not write
                    // anything this build understands. Fall back to one
                    // synchronous attempt rather than reporting success on
                    // an empty report.
                    window.clearInterval(tick);
                    hideLoading();
                    showReportError('The analysis finished but could not be read back. Please generate it again.');
                    el.reportEmpty.style.display = 'block';
                    return;
                }
                // Still pending. Keep the clock honest.
                if (loadingText) {
                    loadingText.textContent = 'Still writing — a full report takes up to a minute. '
                        + Math.round(waited / 1000) + 's';
                }
            });
        }
        window.setInterval(again, POLL_EVERY_MS);
    }

    fetch(ENDPOINTS.report, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                filters: {
                    month: parseInt(el.month.value, 10),
                    year:  parseInt(el.year.value, 10)
                },
                force: !!force
            })
        })
            .then(function (res) {
                // Read the body as TEXT first.
                //
                // res.json() rejects on a body that is not JSON, and the most
                // likely cause here is not a network problem at all: this
                // request can take a minute, a host with a 30s PHP limit kills
                // the script mid-response, and what arrives is a truncated HTML
                // error page. Calling res.json() on that throws, and the catch
                // below then reports "Network error" - which is not what
                // happened and sends the reader to the wrong place.
                return res.text().then(function (text) {
                    try {
                        return { ok: true, json: JSON.parse(text) };
                    } catch (e) {
                        return {
                            ok: false,
                            status: res.status,
                            snippet: text.slice(0, 200)
                        };
                    }
                });
            })
            .then(function (r) {
                window.clearInterval(tick);
                hideLoading();
                var t = document.getElementById('reportLoadingText');
                if (t) t.textContent = 'Analysing registrar data…';

                if (!r.ok) {
                    // The server did not answer with JSON.
                    //
                    // Anything that answers in the 5xx range with an HTML body
                    // is the SERVER, not this application: ai-insights-report.php
                    // only ever emits 401, 403, 405 and 500, so a 503 cannot have
                    // come from it. On shared hosting that leaves a short list of
                    // real causes, and each is fixed in a different place, so the
                    // message names them and shows what actually arrived rather
                    // than just the status.
                    showReportError(describeFailedResponse(r.status, r.snippet || ''));
                    el.reportEmpty.style.display = 'block';
                    return;
                }

                var json = r.json;

                // THE FALLBACK IS NOT A FAILURE.
                //
                // This test used to require json.data.report to be non-empty,
                // which meant a fallback response - success, empty report, real
                // figures - was routed to the error branch and the figures never
                // rendered at all. renderReport() already handles the empty case:
                // it shows the banner and the figures and leaves the narrative
                // slot empty. So the only question here is whether the server
                // succeeded at all.
                if (json && json.success && json.data && json.data.status === 'pending') {
                    // Accepted and running. The spinner stays up and the clock
                    // keeps counting while the poll waits for it.
                    startPolling();
                    return;
                }

                if (json && json.success && json.data) {
                    renderReport(json.data.report || '', json.data);
                } else {
                    showReportError((json && json.message) || 'The server could not generate the analysis.');
                    el.reportEmpty.style.display = 'block';
                }
            })
            .catch(function (err) {
                window.clearInterval(tick);
                hideLoading();
                showReportError('Could not reach the server: ' + err.message);
                el.reportEmpty.style.display = 'block';
            });
    }




    // ─── Printable executive report ─────────────────────────────
    /**
     * Prints the report as ONE file with three parts, in this order:
     *
     *   1. Title    — the BCP letterhead and the reporting-period line.
     *   2. Body     — sections 1-6 of the analysis, under their numbered
     *                 headings.
     *   3. Conclusion — section 7 (cross-module findings and recommended
     *                 actions), lifted out of the body and printed under
     *                 its own "Conclusion" heading, so the end of the
     *                 document reads as conclusions rather than as one
     *                 more section that happened to come last.
     *
     * Section 7 is the conclusion BY CONTRACT, not by keyword search: the
     * endpoint requires exactly seven numbered headings and names the
     * seventh "Cross-Module Findings and Recommended Actions". If a stored
     * report somehow has no section 7, the conclusion block is omitted
     * rather than invented.
     *
     * "Export PDF" reuses this (print → save as PDF). There is deliberately
     * no CSV export of the analysis: the figures behind it live in the data
     * endpoint and in the on-screen figures block, and a flat CSV invites
     * reading counts as analysis — the exact mistake those blocks exist to
     * prevent.
     *
     * The report is written into a hidden <iframe> rather than a pop-up
     * window. window.open() is the convention elsewhere in this project
     * (registrar/masterlist.php), but it spawns a new tab the user has to
     * dismiss, which is jarring when the only thing wanted is a sheet of
     * paper. An iframe prints from the current tab and the page the user is
     * already on is never disturbed.
     *
     * The charts and KPI tiles were removed on purpose: this is a formal
     * document, and the analysis is the content. The figures behind the
     * analysis stay available in the data endpoint and on screen.
     */
    function openPrintView() {
        if (!state.report) return;

        var logo = new URL('../assets/images/BCP_LOGO.png', window.location.href).href;
        var periodLabel = state.period.label || '';
        var generated = new Date().toLocaleString();

        // The gateway note is deliberately NOT printed. "rule-based summary
        // (AI gateway unavailable)" is an operational detail for whoever is
        // operating the screen, and it made the printout look like an error
        // on an official document. The source badge on screen still reports
        // it, so nothing is hidden from the user who needs to know.
        var parts = splitReportSections(state.report);

        var body = ''
            + BCPPrint.headerHtml({
                logoUrl: logo,
                title: 'AI INSIGHT REPORT — ' + String(periodLabel).toUpperCase()
            })
            // Part 1 closes here; the period line is the bridge to the body.
            + '<div class="meta">Reporting period: ' + escapeHtml(periodLabel)
            + ' (compared with ' + escapeHtml(state.period.prev_label || '—')
            + ') · Generated: ' + escapeHtml(generated)
            + '</div>'
            // Part 2 — the body. No KPI tiles and no chart snapshots —
            // those are a screen view; the printout is the written analysis.
            + '<div class="doc-body">' + reportBodyHtml(parts.body) + '</div>'
            // Part 3 — the conclusion: its own block, its own heading,
            // rendered from the same lines section 7 would have printed as
            // inside the body, so nothing is lost by lifting it out.
            + (parts.conclusion
                ? '<div class="doc-body doc-conclusion"><h2 class="doc-h">Conclusion</h2>'
                    + sectionLinesHtml(parts.conclusion.lines) + '</div>'
                : '')
            + '<div class="sig"><div class="box"><div class="line">Prepared by:<br>Registrar</div></div>'
            + '<div class="box"><div class="line">Noted by:<br>School Head / President</div></div></div>'
            + '<div class="foot-note">Generated by: Registrar Information System<br>'
            + 'AI-generated information is provided for administrative reference.</div>';

        if (!BCPPrint.printDocument({ title: periodLabel, body: body })) {
            showReportError('Unable to prepare the report for printing.');
        }
    }

    // ─── Export ───────────────────────────────────────────────────────────────────────────────────────────────
    function periodSlug() {
        return el.year.value + '-' + ('0' + el.month.value).slice(-2);
    }

    function downloadBlob(content, filename, mime) {
        var blob = new Blob([content], { type: mime });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function exportTxt() {
        if (!state.report) return;
        var text = 'AI ANALYSIS REPORT\n';
        text += 'Bestlink College of the Philippines — Office of the Registrar\n';
        text += 'Reporting period: ' + (state.period.label || '') + ' (compared with ' + (state.period.prev_label || '—') + ')\n';
        text += 'Generated: ' + new Date().toLocaleString() + '\n';
        text += 'Source: ' + (state.source === 'ai' ? 'AI-generated' : 'AI analysis unavailable (gateway not reached)') + '\n';
        text += Array(64).join('=') + '\n\n';
        text += state.report + '\n\n';
        text += Array(64).join('-') + '\n';
        text += 'Generated by: Registrar Information System\n';
        text += 'AI-generated information is provided for administrative reference.\n';

        downloadBlob(text, 'ai-insights-' + periodSlug() + '.txt', 'text/plain;charset=utf-8');
    }

    // ─── Wiring ─────────────────────────────────────────────────
    function closeExportMenu() {
        if (el.exportMenu) el.exportMenu.classList.remove('is-open');
    }

    if (el.generate) {
        el.generate.addEventListener('click', function () {
            // Second run for the same period forces a fresh model call.
            generateReport(!!state.report);
        });
    }

    [el.month, el.year].forEach(function (select) {
        if (!select) return;
        select.addEventListener('change', loadPeriod);
    });

    if (el.printBtn)  el.printBtn.addEventListener('click', openPrintView);
    if (el.exportPdf) el.exportPdf.addEventListener('click', function (e) { e.preventDefault(); closeExportMenu(); openPrintView(); });
    if (el.exportTxt) el.exportTxt.addEventListener('click', function (e) { e.preventDefault(); closeExportMenu(); exportTxt(); });

    if (el.exportBtn) {
        el.exportBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (el.exportMenu) el.exportMenu.classList.toggle('is-open');
        });
    }
    document.addEventListener('click', closeExportMenu);

    // ─── First paint (server-rendered payload) ──────────────────
    renderCards(state.cards);
    renderCharts({ period: state.period, charts: state.charts });
})();

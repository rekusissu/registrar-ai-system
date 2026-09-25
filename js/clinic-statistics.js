// Visit statistics renderer for the clinic dashboard.
// Uses only data supplied by the authenticated PHP page.
(function () {
    'use strict';
    var data = window.CLINIC_VISIT_DATA || {};
    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function setText(id, value) {
        var element = document.getElementById(id);
        if (element) element.textContent = value;
    }

    var daily = data.dailyVisits || [];
    var dailyChart = document.getElementById('clinicDailyChart');
    if (dailyChart) {
        var dailyMax = Math.max.apply(null, daily.map(function (row) { return Number(row.cnt) || 0; })) || 1;
        dailyChart.innerHTML = daily.map(function (row) {
            var count = Number(row.cnt) || 0;
            var height = count ? Math.max(8, Math.round(count / dailyMax * 100)) : 2;
            var label = String(row.dt || '').substring(5).replace('-', '/');
            return '<div class="visit-bar-col" title="' + esc(row.dt) + ': ' + count + ' visit' + (count === 1 ? '' : 's') + '">' +
                '<span class="visit-bar-value">' + count + '</span><span class="visit-bar" style="height:' + height + '%"></span>' +
                '<span class="visit-bar-label">' + esc(label) + '</span></div>';
        }).join('');
        var dailyData = document.getElementById('clinicDailyData');
        if (dailyData) dailyData.innerHTML = daily.map(function (row) {
            return '<li>' + esc(row.dt) + ': ' + Number(row.cnt || 0) + ' visits</li>';
        }).join('');
    }
    var dailyTotal = daily.reduce(function (sum, row) { return sum + (Number(row.cnt) || 0); }, 0);
    var peak = daily.reduce(function (best, row) {
        return !best || Number(row.cnt) > Number(best.cnt) ? row : best;
    }, null);
    setText('clinicAvgDaily', daily.length ? (dailyTotal / daily.length).toFixed(1) : 'Not available');
    setText('clinicPeakDay', dailyTotal && peak ? peak.dt + ' (' + peak.cnt + ')' : 'No visits recorded');

    var reasons = data.topReasons || [];
    var reasonsChart = document.getElementById('clinicReasonsChart');
    if (reasonsChart) {
        if (!reasons.length) {
            reasonsChart.innerHTML = '<div class="visit-empty">No reasons recorded in the last 30 days.</div>';
        } else {
            var reasonMax = Math.max.apply(null, reasons.map(function (row) { return Number(row.cnt) || 0; })) || 1;
            reasonsChart.innerHTML = reasons.map(function (row) {
                var count = Number(row.cnt) || 0;
                var width = Math.max(2, Math.round(count / reasonMax * 100));
                return '<div class="visit-reason-row" title="' + esc(row.reason_for_visit) + '">' +
                    '<span class="visit-reason-label">' + esc(row.reason_for_visit) + '</span>' +
                    '<span class="visit-reason-track"><span class="visit-reason-fill" style="width:' + width + '%"></span></span>' +
                    '<span class="visit-reason-count">' + count + '</span></div>';
            }).join('');
            var reasonsData = document.getElementById('clinicReasonsData');
            if (reasonsData) reasonsData.innerHTML = reasons.map(function (row) {
                return '<li>' + esc(row.reason_for_visit) + ': ' + Number(row.cnt || 0) + ' visits</li>';
            }).join('');
            setText('clinicTopReason', reasons[0].reason_for_visit + ' (' + Number(reasons[0].cnt) + ')');
        }
    }

    var hourly = data.hourly || [];
    var hourlyChart = document.getElementById('clinicHourlyChart');
    if (hourlyChart) {
        var counts = {};
        hourly.forEach(function (row) { counts[Number(row.hr)] = Number(row.cnt) || 0; });
        var hourMax = Math.max.apply(null, Object.keys(counts).map(function (hour) { return counts[hour]; })) || 0;
        var peakHour = 0;
        Object.keys(counts).forEach(function (hour) { if (counts[hour] > counts[peakHour]) peakHour = Number(hour); });
        hourlyChart.innerHTML = Array.from({ length: 24 }, function (_, hour) {
            var count = counts[hour] || 0;
            var label = (hour < 10 ? '0' : '') + hour + ':00';
            return '<div class="visit-hour' + (count === hourMax && count > 0 ? ' peak' : '') + '" title="' + label + ': ' + count + ' visit' + (count === 1 ? '' : 's') + '">' +
                '<span class="visit-hour-time">' + label + '</span><span class="visit-hour-count">' + count + '</span></div>';
        }).join('');
        var hourlyData = document.getElementById('clinicHourlyData');
        if (hourlyData) hourlyData.innerHTML = Array.from({ length: 24 }, function (_, hour) {
            return '<li>' + (hour < 10 ? '0' : '') + hour + ':00: ' + (counts[hour] || 0) + ' visits</li>';
        }).join('');
        setText('clinicPeakHour', hourMax ? (peakHour < 10 ? '0' : '') + peakHour + ':00 (' + hourMax + ')' : 'No visits recorded');
    }
}());

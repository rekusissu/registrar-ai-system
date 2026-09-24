let rfidDistributionPlan = null;

function rfidEscape(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function openRfidDistribution() {
    const modal = document.getElementById('rfidDistributionModal');
    const body = document.getElementById('rfidDistributionBody');
    if (!modal || !body) return;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<div class="ai-insight-card" style="text-align:center;color:#64748b"><i class="fas fa-spinner fa-spin"></i> Loading available cards and students…</div>';
    fetch('../api/rfid.php?action=distribution-preview', { headers: { Accept: 'application/json' } })
        .then(response => response.json())
        .then(payload => {
            if (!payload.success) throw new Error(payload.message || 'Could not prepare distribution.');
            rfidDistributionPlan = payload.data;
            renderRfidDistribution();
        })
        .catch(error => { body.innerHTML = '<div class="ai-insight-card" style="color:#dc2626">' + rfidEscape(error.message) + '</div>'; });
}

function closeRfidDistribution() {
    document.getElementById('rfidDistributionModal')?.classList.remove('active');
    document.body.style.overflow = '';
}

function renderRfidDistribution() {
    const body = document.getElementById('rfidDistributionBody');
    const applyButton = document.getElementById('applyRfidDistributionBtn');
    if (!body || !rfidDistributionPlan) return;
    const data = rfidDistributionPlan;
    const rows = (data.assignments || []).map(row => `<label class="rfid-distribution-row"><input type="checkbox" data-rfid-distribution-card="${Number(row.card_id)}" checked><span class="rfid-distribution-card"><i class="fas fa-microchip"></i><strong>${rfidEscape(row.card_uid)}</strong><small>Available card</small></span><span class="rfid-distribution-arrow">→</span><span class="rfid-distribution-student"><strong>${rfidEscape(row.student_name || 'Unnamed student')}</strong><small>${rfidEscape(row.student_number || 'No student #')} · ${rfidEscape(row.course || 'No course')}${row.year_level ? ` · Year ${rfidEscape(row.year_level)}` : ''}</small></span><span class="rfid-distribution-reason">${rfidEscape(row.reason)}</span></label>`).join('');
    const skipped = (data.skipped || []).map(row => `<div class="rfid-distribution-review"><i class="fas fa-triangle-exclamation"></i><span><strong>${rfidEscape(row.student_name || 'Unnamed student')}</strong> — ${rfidEscape(row.reason)}</span></div>`).join('');
    const summary = data.ai_summary || data.summary || '';
    body.innerHTML = `<div class="rfid-distribution-summary"><div><strong>${Number(data.available_cards)}</strong><span>available cards</span></div><div><strong>${Number(data.eligible_students)}</strong><span>students without cards</span></div><div><strong>${Number(data.proposed)}</strong><span>proposed assignments</span></div><div><strong>${Number(data.needs_review)}</strong><span>need review</span></div></div><div class="rfid-distribution-ai"><strong><i class="fas fa-sparkles"></i> Assistant review</strong><p>${rfidEscape(summary)}</p></div><div class="rfid-distribution-list">${rows || '<div class="ai-insight-card">No safe assignments are available.</div>'}</div>${skipped ? `<div class="quality-section-title">Needs manual review</div>${skipped}` : ''}`;
    if (applyButton) applyButton.disabled = !(data.assignments || []).length;
    body.querySelectorAll('[data-rfid-distribution-card]').forEach(input => input.addEventListener('change', updateRfidApplyButton));
    updateRfidApplyButton();
}

function updateRfidApplyButton() {
    const selected = document.querySelectorAll('[data-rfid-distribution-card]:checked').length;
    const button = document.getElementById('applyRfidDistributionBtn');
    if (button) { button.disabled = selected === 0; button.innerHTML = selected ? `<i class="fas fa-check"></i> Apply ${selected} selected` : '<i class="fas fa-check"></i> Apply selected'; }
}

function applyRfidDistribution() {
    if (!rfidDistributionPlan) return;
    const selectedIds = [...document.querySelectorAll('[data-rfid-distribution-card]:checked')].map(input => Number(input.dataset.rfidDistributionCard));
    const assignments = (rfidDistributionPlan.assignments || []).filter(row => selectedIds.includes(Number(row.card_id))).map(row => ({ card_id: row.card_id, student_id: row.student_id, expected_card_uid: row.card_uid, expected_student_number: row.student_number }));
    if (!assignments.length) { showToast('Select at least one assignment.', 'warning'); return; }
    if (!window.confirm(`Assign ${assignments.length} RFID card(s) to the selected student(s)?`)) return;
    const button = document.getElementById('applyRfidDistributionBtn');
    if (button) { button.disabled = true; button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying…'; }
    const csrfMeta = document.querySelector('meta[name=csrf-token]');
    fetch('../api/rfid.php?action=distribution-apply', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfMeta ? csrfMeta.getAttribute('content') : '' }, body: JSON.stringify({ assignments }) })
        .then(response => response.json())
        .then(payload => { if (!payload.success) throw new Error(payload.message || 'Assignment failed.'); showToast(payload.message || 'RFID cards assigned.', 'success'); window.setTimeout(() => window.location.reload(), 700); })
        .catch(error => { showToast(error.message || 'Assignment failed.', 'error'); if (button) { button.disabled = false; button.innerHTML = '<i class="fas fa-check"></i> Apply selected'; } });
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('rfidDistributionBtn')?.addEventListener('click', openRfidDistribution);
    document.getElementById('rfidDistributionModal')?.addEventListener('click', event => { if (event.target === event.currentTarget) closeRfidDistribution(); });
});

'use strict';
// The PHP payload supplies canonical term/career GWA calculations.
let awStudent = null;
let awTerm = null;
let awPreviousFocus = null;
const awNumber = value => value == null || value === '' ? '—' : Number(value).toFixed(2);
function awStatus(term, subjects) {
    if (term?.accepted_at) return ['Accepted', 'green'];
    if (!subjects.length) return ['Awaiting submission', 'amber'];
    if (subjects.some(s => !Number.isFinite(Number(s.final_rating)) || s.final_rating == null || s.final_rating === '' || Number(s.final_rating) < 1 || Number(s.final_rating) > 5)) return ['Needs correction', 'amber'];
    return ['For review', ''];
}
function awRenderTerm(index) {
    const r = awStudent;
    const terms = r.career || [];
    awTerm = terms[index] || {school_year: AH.sy, semester: AH.sem, gwa_live: r.gwa};
    const key = awTerm.school_year + '|' + awTerm.semester;
    const subjects = r.careerSubjects[key] || [];
    const status = awStatus(awTerm, subjects);
    const included = Number(awTerm.units_live || 0);
    document.getElementById('awDemoNote').hidden = awTerm.source_system !== 'demo';
    const recorded = subjects.reduce((sum, s) => sum + Math.max(0, Number(s.units) || 0), 0);
    document.querySelectorAll('[data-aw-term]').forEach(b => { b.classList.toggle('is-active', Number(b.dataset.awTerm) === index); b.setAttribute('aria-pressed', String(Number(b.dataset.awTerm) === index)); });
    document.getElementById('awGrades').innerHTML = `<div class="aw-term-heading"><div><h3>${esc(awTerm.semester)} Semester</h3><p>Academic Year ${esc(awTerm.school_year)}</p></div><span class="aw-badge ${status[1]}">${status[0]}</span></div>
      <div class="aw-totals"><div><small>Units recorded</small><strong>${awNumber(recorded)}</strong></div><div><small>Term GWA${status[0] !== 'Accepted' ? ' · provisional' : ''}</small><strong>${awNumber(awTerm.gwa_live)}</strong></div><div><small>Cumulative GWA · all recorded terms</small><strong>${awNumber(r.careerGwa)}</strong></div></div>
      <div class="aw-table-scroll"><table class="aw-table"><thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Final grade</th><th>Remarks</th></tr></thead><tbody>${subjects.length ? subjects.map(s => `<tr><td>${esc(s.subject_code || '—')}</td><td>${esc(s.subject)}</td><td>${awNumber(s.units)}</td><td>${esc(s.final_rating != null && s.final_rating !== '' && Number.isFinite(Number(s.final_rating)) ? awNumber(s.final_rating) : (s.grade || 'Not rated'))}</td><td>${esc(s.grade_status || 'Not recorded')}</td></tr>`).join('') : '<tr><td colspan="5">No subject grades received for this semester.</td></tr>'}</tbody></table></div>
      <div class="aw-calculation"><strong>GWA calculation</strong><br>${included > 0 ? `${awNumber(included)} included units · Unit-weighted average: ${awNumber(awTerm.gwa_live)}` : 'No numerical grades available for computation.'}${status[0] !== 'Accepted' ? '<br>Unverified or incomplete records must be reviewed before certification.' : ''}</div>
      ${awTerm.gwa_reported != null && awTerm.gwa_live != null && Math.abs(Number(awTerm.gwa_reported) - Number(awTerm.gwa_live)) > .01 ? `<div class="aw-calculation">Reported GWA ${awNumber(awTerm.gwa_reported)} differs from computed GWA ${awNumber(awTerm.gwa_live)}. Review the source record.</div>` : ''}
      <div class="aw-record-state">${awTerm.accepted_at ? `Accepted by Registrar · ${esc(awTerm.accepted_at)}` : 'Registrar acceptance has not been recorded for this term.'}</div>
      <details><summary>Record information</summary><p>${esc(awTerm.source_system || 'Source not recorded')} · ${esc(awTerm.received_at || 'Receipt date not recorded')}</p></details>`;
    document.getElementById('awPreview').disabled = !subjects.length;
}
openRecord = function(studentId) {
    const r = rowById(Number(studentId));
    if (!r) return;
    awStudent = r;
    awPreviousFocus = document.activeElement;
    const sheet = document.getElementById('recordSheet');
    document.getElementById('awIdentity').innerHTML = `<div class="aw-person"><span class="aw-avatar">${esc(AH.initials[r.id] || '?')}</span><div><strong>${esc(r.name)}</strong><small>${esc(r.number || 'No student number')}</small></div></div><p><small>Current program</small>${esc(r.program || 'Not recorded')}</p><p><small>Current year level</small>${esc(r.level ? 'Year ' + r.level : 'Not recorded')}</p>`;
    document.getElementById('awPeriods').innerHTML = '<h3>Academic periods</h3>' + (r.career.length ? r.career.map((t, i) => `<button type="button" data-aw-term="${i}" aria-pressed="false">${esc(t.semester)} Semester<small>${esc(t.school_year)} · ${t.accepted_at ? 'Accepted' : 'Not accepted'}</small></button>`).join('') : '<p>No historical terms on file.</p>');
    const initial = r.career.findIndex(t => t.school_year === AH.sy && t.semester === AH.sem);
    awRenderTerm(initial >= 0 ? initial : Math.max(0, r.career.length - 1));
    sheet.hidden = false;
    document.body.classList.add('aw-modal-open');
    document.getElementById('sheetTitle').focus();
};
closeRecord = function() {
    document.getElementById('recordSheet').hidden = true;
    document.body.classList.remove('aw-modal-open');
    if (awPreviousFocus?.isConnected) awPreviousFocus.focus();
};
function awFilter() {
    const search = document.getElementById('awSearch').value.trim().toLowerCase();
    const program = document.getElementById('awProgram').value;
    let count = 0;
    document.querySelectorAll('[data-aw-student]').forEach(row => { row.hidden = !(row.dataset.search.includes(search) && (!program || row.dataset.program === program)); if (!row.hidden) count++; });
    document.getElementById('awEmpty').hidden = count > 0;
    document.getElementById('awCount').textContent = `${count} student record${count === 1 ? '' : 's'}`;
}
document.getElementById('awSearch').addEventListener('input', awFilter);
document.getElementById('awProgram').addEventListener('change', awFilter);
document.addEventListener('click', event => {
    const tab = event.target.closest('[data-workspace-tab]');
    if (tab) {
        const review = tab.dataset.workspaceTab === 'review';
        document.getElementById('awStudents').hidden = review;
        document.getElementById('awReview').hidden = !review;
        document.querySelectorAll('.aw-tabs button').forEach(b => { const selected = b.dataset.workspaceTab === tab.dataset.workspaceTab; b.classList.toggle('is-active', selected); b.setAttribute('aria-pressed', String(selected)); });
    }
    const term = event.target.closest('[data-aw-term]');
    if (term) awRenderTerm(Number(term.dataset.awTerm));
    if (event.target.id === 'recordSheet') closeRecord();
});
document.getElementById('awPreview').addEventListener('click', () => printOfficial(awStudent.id, 'selected:' + awTerm.school_year + '|' + awTerm.semester));
document.getElementById('awAllRecords').addEventListener('click', () => openPrintMenu(awStudent.id));
document.getElementById('recordSheet').addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const controls = [...event.currentTarget.querySelectorAll('button:not([disabled]), summary, [href], select, input')].filter(el => el.getClientRects().length);
    const first = controls[0], last = controls[controls.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement.id === 'sheetTitle')) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
});

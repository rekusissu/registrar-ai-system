# Student Data Quality — approved technical design

Applied on 12 November 2026 to **Students → Data Quality**.

- `approved-tech-preview.png` is the approved generated concept.
- `implemented-desktop.png` and `implemented-mobile.png` show the actual interface with database records.
- Layout: cobalt/navy technical header, connected four-metric strip, issue breakdown, review queue, comparison table, manual findings, persistent review-selection section and audit footer.
- CSS is isolated in `css/student-data-quality.css`; the obsolete overlapping quality CSS in `registrar/students.php` was removed.
- Record checks still use `api/ai-tools.php?action=quality`. Counts, findings, safe suggestions and duplicates come from that response; mockup sample values are not hardcoded.
- The new `quality_history` action checks student/program access before reading that student's audit entries. History displays the author, timestamp and available field changes.
- Only the backend's supported safe suggestions (contact number, email and canonical program formatting) can be selected and applied. Identity corrections, missing information and duplicate decisions remain manual; the concept's sample name correction does not authorize automatic identity editing.
- Suggestions start unchecked. Select-all and partial selection are supported. Save retains explicit confirmation, stale-value checking and the existing audit trail.
- Mobile uses a stacked queue/inspection layout; the comparison table scrolls horizontally within its container. Escape, focus return, focus containment and keyboard tabs are supported; reduced-motion preferences are respected.

Validation: live browser checks on desktop and 390px mobile; scan/search/category states, history, select-all/partial selection, cancellation, selected-only save, audit history, stale-value rejection, program-access denial, manual-edit navigation, keyboard tabs and Escape/focus return. PHP and JavaScript syntax checks passed. Temporary QA account/student/audit records were removed; no existing student records were changed by the tests.

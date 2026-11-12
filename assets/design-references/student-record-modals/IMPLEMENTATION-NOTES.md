# Student modal workspaces

Approved references: add-student-approved.png, receive-student-approved.png, view-student-approved.png, edit-student-approved.png.

Implemented in `css/student-record-modals.css`, `js/student-record-modals.js`, with source hooks in `registrar/students.php`.

- Shared landscape frame, navy/cobalt header, cyan accents, fixed footer, independently scrolling content, responsive layout and focus handling.
- Add: four stages, original field validation and creation endpoint, final review. Includes country of birth.
- Receive: searchable incoming queue, duplicate checking, reviewed acceptance or re-enrollment using existing APIs. No invented integration data; empty intake remains empty.
- View: photo/source identity sidebar, profile/documents/academic tabs, guardian contacts and existing explicitly initiated account services.
- Edit: section navigation, before/after changes, required reason, only changed student fields submitted. Student number stays read-only.
- Update API: optimistic stale-value checks, transaction, primary-guardian-only updates and student/guardian audit entries.

Validation on 2026-11-12: PHP lint, JavaScript syntax, desktop/mobile browser checks for all four dialogs, required-step blocking, edit reason/diff/payload, intake duplicate-check gate. Backend fixture verified stale rejection, guardian-only saves, secondary guardian preservation and audit recording. Fixture and its audit rows removed. Browser submission tests intercepted writes; no existing student record was changed.

Reference fidelity correction: rebuilt the Add identity field rows, saturated navigation selection, header grid accents, inset Receive panels and process ribbon, full-width Profile segmented tabs and aligned definition rows, plus the wider blue Edit review panel. Verified four rendered desktop dialogs without frame overflow and the 390px Add layout without horizontal overflow. Additional profile fields and account services remain accessible. No student data changed.

Documents and Academic tabs redesigned as source-backed workspaces. Documents separates completeness, file tiles, and request activity. Academic renders period cards and recorded GWA without invented grades. Five modal headers now use distinct color/decorative treatments. Fixed portrait loading by serving File Storage photos through the authenticated endpoint (verified HTTP 200 image/jpeg and loaded image in View); file links also use the authorized download endpoint. Desktop and mobile browser checks passed with no page errors. No records changed.

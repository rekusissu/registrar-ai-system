# Administrative layouts — 2026-11-13

Rebuilt the Export History and Program Assignments page structures, and reorganized Settings and Audit Logs. Dedicated styles and behavior live in `css/admin-workspaces.css` and `js/admin-workspaces.js`, loaded only on these four routes by the shared footer.

- Export History: journal metrics describe only the loaded, authorization-scoped events; search/status/format filters; compact report/account/status/date columns; native details dialog contains the resource and IP; status guide distinguishes authorization, initiation and failed confirmation without claiming download completion.
- Program Assignments: contextual account rail, real account identity, searchable College/SHS/Selected catalog, current count and pending-change count, explicit save footer. Existing authenticated program-assignment API remains authoritative. No automatic selection or permission widening.
- Settings: identity sidebar, password workspace with paired new/confirm fields, collapsible system support metadata. Existing password handler, strength feedback and masking controls retained.
- Audit: labeled six-position filter grid, clear reset/apply controls, activity journal and before/after details. Fixed details cache to include every rendered page/filter result rather than fetching only the default first page.

QA: PHP lint and JavaScript syntax checks; desktop screenshots and 390px phone layouts; export filters/details/status guide; program search/selection/diff and an intercepted mock save; audit search/reset/details; existing password input visibility. No actual program assignments or passwords were changed. Browser sign-in generated normal account access audit events. Temporary QA script removed.

# Queue kiosk design references — saved for November 12, 2026

User instruction on November 11, 2026: save these for tomorrow implementation, EXCEPT the monitor design. Implementation is deferred until the user resumes it. No reminder or automatic implementation is scheduled.

## Scope
Only the tap kiosk and its output screens. Do not implement or reuse the generated queue monitor design. Do not modify queue/monitor.php as part of this saved scope.

## Saved references
- 01-kiosk-ready.png: proposed open/ready kiosk screen; card illustration, circular RFID coil, light tech frame, service instructions and navigation.
- 02-tap-flow.png: 01 reading card, 02 service selection, 03 student/priority category, 04 issued ticket.
- 03-ticket-results.png: 05 already waiting, 06 now serving, 07 rejoin cooldown, 08 new number after return.
- 04-service-exceptions.png: 09 card not accepted, 10 queue closed, 11 daily capacity reached, 12 connection problem.
- 05-request-exceptions.png: 13 daily tap limit, 14 unavailable service option, 15 repeated card read, 16 generic incomplete request.

## Design direction
Bright white/icy-blue backgrounds, navy type, cobalt buttons, cyan circuit/grid accents and chamfered frames. Keep the circular RFID coil; no debit-card chip. Use the actual assets/images/BCP_LOGO.png and correct college name rather than the generated placeholder crests. Preserve readable contrast, large touch targets, keyboard operation, responsive layouts and reduced-motion support.

## Implementation starting points
- queue/kiosk.php: kiosk markup/screens and hidden reader input.
- js/queue.js: shared queue logic, kiosk screen switching, two-step lane picker and result handling. Inspect monitor/console dependencies before changing shared code.
- css/queue.css: shared styles; scope new visual rules to the kiosk so monitor/console remain intact.
- Preserve existing API contracts and server authority over queue availability, lane routing, ticket issuance, cooldown and limits.

## Behavior and accuracy
1. Current choices are service/claim, then student/priority. Do not invent eligibility policy.
2. Issued ticket shows the actual display number, assigned window, lane and position returned by the server. Do not hardcode the preview numbers/times/positions or present a speculative wait estimate.
3. Say assigned window and wait until called after issuance. Reserve Please proceed for a ticket actually being served.
4. Existing active tickets must be displayed without issuing duplicates. Preserve cooldown, repeat-read protection, daily limits, unavailable lane, closure and capacity handling.
5. Network failure means the outcome may be unknown: never claim no ticket was issued without server confirmation. Offer Check my number before another attempt.
6. Display actual server messages and configured schedule. Do not hardcode the mockup school hours or tomorrow reopening promises.
7. The reading-card state is proposed visual feedback; coordinate it with real reader/API timing. The mockup buttons are proposed UX, not proof those actions already exist.
8. Current success auto-return is approximately seven seconds; verify timer cleanup and avoid clearing a subsequent student's screen with an older timer.
9. Keep demo state labels and invented data out of production. Review screenshot copy before implementation; this is a visual reference, not authoritative business policy.

## Verification next session
Exercise open/closed kiosk, service and claim routing, student and priority, success, already queued, now serving, cooldown/requeue, denied card, capacity/tap limits, repeated reads, invalid lane, network errors, return timers and navigation. Verify no monitor or queue-console regressions. Clean temporary scripts and fixtures after testing.

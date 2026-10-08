---
tags: [module]
---

# 🧙 `doc_wizard` — the guided request wizard

`shared/doc_wizard.php`. The domain logic behind the student's
document-request wizard and the tracking page.

## Why it exists

Six places need to agree about a document request:

| Place | Reads |
|---|---|
| `student/document-request.php` | everything |
| `api/document-request.php` | the fee quote, the requirements, the timeline |
| `student/document-track.php` | the timeline, the status colours |
| `student/documents.php` | the status colours, `doc_can_cancel()` |
| `registrar/documents.php` | the status colours, `doc_actionable_statuses()` |
| `api/documents.php` | (via `document_process.php`) |

The status vocabulary used to be typed out **twice** — once per portal.
They had already drifted: the desk knew about `Awaiting_Payment` and the
student list fell through to a grey default. Adding `Draft` and
`Cancelled` to a third copy would have fixed one portal and broken the
other. One table, `doc_status_meta()`, read by all of them.

## What is here

| Function | Answers |
|---|---|
| `doc_quote()` | The fee breakdown, line by line |
| `doc_status()` / `doc_status_meta()` | How a status is named, coloured, drawn |
| `doc_wizard_steps()` | The screens |
| `doc_payment_options()` / `doc_purposes()` | The vocabulary |
| `doc_requirements()` / `doc_requirement_state()` | The upload checklist |
| `doc_store_attachment()` | Validate and store one upload |
| `doc_track_timeline()` | The tracking timeline, as data |
| `doc_next_expectation()` | Where it is, in words |
| `doc_can_cancel()` / `doc_can_process()` / `doc_actionable_statuses()` | Who may do what |
| `doc_next_tracking_number()` | The next `DOC-YYYY-NNNN` |
| `doc_estimated_release()` | The promised date |

## Three things worth knowing before changing it

### 1. `doc_quote()` is a pure function on purpose

The browser recomputes the total live on every keystroke; the server
recomputes the same function to decide what is owed. Two independent
implementations of a price is how a student ends up quoted one amount
and charged another. The browser gets the **constants** in its bootstrap
payload rather than retyping them, so a price change moves both at once.

### 2. The tracking timeline is built from timestamps, not from status

A timeline drawn off the current status collapses to "you are here" and
loses every past step — which is the entire reason a student opens the
tracking page instead of reading a status pill.

That has consequences worth preserving:

- `Awaiting_Payment` gets its own branch, so the payment step reads
  "waiting on you" rather than being silently marked active.
- Pay-at-the-counter marks the payment step **deferred**: it is drawn
  as coming up but never claims to be "now". Without that, a request
  sitting in the queue said it owed money while the box above it said
  it did not.
- `Claimed` short-circuits to all-done. A missing intermediate
  timestamp must not leave a completed request showing live work.
- `Rejected` and `Cancelled` draw every unreached step as **stopped**,
  never pending — a rejected request did not arrive late.

### 3. Uploads are an ask, not a gate

The wizard collects requirements early and lists what is missing, but
does **not** block submission. The office still has to inspect what
arrives, and a student who genuinely has the paper but is uploading from
a phone with no signal is not someone to lock out of the whole module
over a photograph. `doc_requirement_state()['missing']` drives both the
wizard's notice and the desk's "ask the student to bring" note.

Because it is an ask, the desk's paperwork panel styles the two cases
differently on purpose: a **required** item nobody sent is amber "not
supplied", an **optional** one is grey italic "not needed". Amber means
"chase the student", and there is nothing to chase about something the
checklist marked optional.

### 4. The stage track is fulfilment-aware

`doc_stage_track()` takes **no argument** and returns four stations: Filed,
Processing, Ready, Claimed. It used to take the fulfilment type and splice
in a `Shipped` station for courier rows only.

The signature change is the point. While `?string $fulfillment = null`
survived, every call site still had to pass something and the desk still had
to read `fulfillment_type` to pass it - so removing the display would have
left the data dependency fully intact underneath.

A legacy `Shipped` row reports at the **Ready** station (index 2) rather
than index 0, so a discontinued courier request does not draw as though it
had never started. `doc_next_step()` returns null for it.

## What the desk consumes from here

`registrar/documents.php` reads all of the above and nothing else for
wizard data — deliberately, so the desk cannot drift from the portal:

- `doc_quote()`-equivalent fee fields already on the row, not a re-derivation
- `doc_status_meta()` for the pill colours and labels
- `doc_purposes()` / `doc_payment_options()`
  for both **display** and the desk's own intake form, so the desk cannot
  offer a channel the portal does not support
- `document_type_requirements` for the checklist, batched into one query
  rather than per row

## Tracking numbers

`DOC-YYYY-NNNN` — the existing house format, deliberately **not** changed
to the `REQ-2025-000123` shape in the original specification. Every
existing row, every stored filename
(`uploads/document_pdfs/DOC-2026-0005-DOC-COE.pdf`) and every bookmarked
URL is built on the current form, and a tracking number is the one string
that must never stop working for a document already issued.

Counted from `MAX`, not `COUNT`: `COUNT` hands a deleted row's number to
a second request, and a concurrent pair of filings both read the same
count. The unique index makes that a duplicate-key error the caller
retries, not two documents sharing one number.

## Related

- [[Document Requests]] · [[document_requests]] ·
  [[document_request_attachments]] · [[Digital File Storage]]
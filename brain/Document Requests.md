---
tags: [subsystem]
---

# 📄 Document Requests

Subsystem 7 — lifecycle tracking for student document requests, and the
guided wizard students file through.

## What it does

- Seven priced documents (TOR, Diploma Replacement, Certificate of
  Enrollment, Good Moral, Certified True Copy, Honorable Dismissal,
  Course Description)
- Full lifecycle: `Draft → Filed / Awaiting_Payment / Pending_Clearance
  → Processing → Ready → Claimed`, plus `Rejected` and
  `Cancelled`
- A guided wizard: **document → details → uploads → review → payment**,
  then a tracking page
- Drafts autosave on every step, so a closed tab costs nothing
- GCash receipt verification, bank transfer, or pay at the counter

## The wizard

| Screen | File |
|---|---|
| Picker, details, uploads, review, payment | `student/document-request.php` |
| Confirmation | same page, final screen |
| Tracking | `student/document-track.php` |
| List + resume-draft banner | `student/documents.php` |
| API | `api/document-request.php` |
| Domain logic | `shared/doc_wizard.php` |

It is **one page, five screens**, not five URLs. A wizard split across
five addresses loses its state the moment a student taps Back in their
browser, and Back is the one control everybody reaches for.

The step is kept in the URL hash so a refresh keeps its place.

## The three principles

1. **The tracking number is the student's anchor.** Shown on the
   confirmation, the tracking page and every list row, copyable in one
   tap.
2. **Uploads are an ask, not a gate.** The wizard collects requirements
   early and lists what is missing, but does not block submission —
   the office still inspects what arrives, and a student on a phone with
   no signal is not someone to lock out over a photograph.
3. **The fee is computed once, in `doc_quote()`.** The browser
   recomputes it live from the same constants the server uses. Two
   implementations of a price is how a student gets quoted one amount
   and charged another.

## Layout

```
[Login] → [Dashboard] → [Wizard: document → details → uploads → review → payment]
   → [Confirmation] → [Tracking] → [Collection]
```

## Tables

- [[document_requests]] — the requests themselves
- [[document_request_attachments]] — uploaded files, and the per-SKU checklist

## The desk

`registrar/documents.php` is the clerk's queue. It has to be **useful for
what the wizard produces**, not merely tolerant of it — the wizard collects
fields the desk then ignored, which made a clerk phone the student to ask
questions the portal had already answered.

What the desk reads from a request now:

| Shown | Why it matters |
|---|---|
| Uploads, as inline thumbnails, keyed to the checklist | The wizard's upload step is pointless if nobody can see the result. Served through `api/file-download.php?kind=attachment`, never from `uploads/` directly. |
| Outstanding required items, amber | Countable and filterable, unlike the desk's older free-text "ask for" note. |
| Optional items, grey "not needed" | Not a shortfall. Amber here would contradict the summary above it. |
| `purpose_code`, `notes` | "Why do they need a TOR, and where is it going?" |
| `payment_reference` | With a warning when a bank transfer is not yet confirmed paid. |
| `estimated_release_at` as **"Promised to student"** | It is the date the student was shown, so the desk is held to it. Not the registrar's own estimate. |
| `cancellation_reason`, plus a refund warning if `paid_at` is set | Money went in and the request was withdrawn — the case a clerk must not miss. |

The desk's intake form used to hardcode `payment_method: 'Counter'`, so a
walk-in paid outside the office could not be recorded as one. It is now a
select drawn from `doc_payment_options()` — the same table the wizard
uses, so the desk cannot offer a channel the portal does not support.

It also used to hardcode `fulfillment_type: 'Pickup'` and later offered a
select drawn from `doc_delivery_options()`. **Both are gone.** There is one
way to hand a document over, so the column is written by
`doc_fulfillment()` and the form does not send a fulfillment field at all.
## Pages & endpoints

- `registrar/documents.php` — the desk (drafts excluded)
- `registrar/documents-add.php` — walk-in intake
- `registrar/documents-archive.php` — legacy closed requests
- `api/documents.php` — desk transitions (`process`, `ready`, `reject`, `claim`)
- `api/document-request.php` — **the wizard** (student)
- `api/student-documents.php` — GCash receipt upload
- `api/file-download.php?kind=attachment` — authorised attachment reads

## Tests

```
php tests/document_request_api_test.php   # the wizard endpoint
php tests/document_desk_flow_test.php     # file → pay → desk releases → collect
node tests/doc_wizard_probe.js            # the wizard in a real browser
node tests/desk_probe.js                  # the desk in a real browser
php tools/_check_states.php               # all ten states render in both portals
```

Both browser probes clean up after themselves — the wizard probe files a
real request through the real endpoint and deletes it again by tracking
number, because a leaked row then appears on the desk as a genuine
student request.

`tools/_desk_markup.php` greps the desk's **raw HTML** for each panel.
`tools/_render.php` prints a tag-stripped summary, so it cannot answer
"is this markup present".

## Related

- [[Subsystems MOC]] · [[doc_wizard]] · [[Student Portal]] ·
  [[Digital File Storage]] · [[Student Management]]
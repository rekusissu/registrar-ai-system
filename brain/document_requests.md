---
tags: [table]
---

# 🗄️ `document_requests` — wizard columns

The lifecycle table for registrar document requests. See
[[Document Requests]] for the subsystem and [[doc_wizard]] for the
shared helpers that read it.

## Status vocabulary

`document_status`, widened by `migrations/document_request_wizard.sql`:

| Value | Meaning |
|---|---|
| `Draft` | Started by the student in the wizard, not submitted. **Not work** — the desk excludes it from every count. |
| `Filed` | Submitted and actionable. |
| `Pending_Clearance` | Held on an outstanding balance. |
| `Awaiting_Payment` | Waiting on money from the student. |
| `Processing` | Being prepared. |
| `Ready` | Signed, waiting for collection. |
| `Shipped` | **LEGACY.** The courier leg is withdrawn; nothing reaches it. Rows
  already in the table can still carry it, so it has to render. See below. |
| `Claimed` | Handed over. Terminal. |
| `Rejected` | Refused by the desk. Terminal. |
| `Cancelled` | Withdrawn by the student. Terminal, and **kept on record**. |

Legacy `status` (`pending/processing/approved/denied/completed/released`)
is still written for the older readers. Do not read it — it is two
generations behind and cannot express Draft, Awaiting_Payment, Shipped
or Cancelled.

### Why `Draft` is a status and not inferred

A student who started a wizard and closed the tab has created a row.
Counting that as a filed request tells a registrar the office is behind
on work nobody has asked for, so the desk filters it out of the table,
the "needs action" tile, the revenue chart and the backlog chart.

### The courier leg is gone

There was one, and it was conditional: `Shipped` sat between `Ready` and
`Claimed` only when `fulfillment_type = 'Delivery'`. `doc_stage_track()` took
the fulfilment type and inserted the station for courier rows only, because
a counter-collected request has no leg between "signed" and "handed over" -
the student carries it out.

The service is withdrawn. What changed, and why each piece had to go:

| Removed | Why it could not stay |
|---|---|
| `doc_delivery_options()` | Replaced by `doc_fulfillment()`, which returns `Pickup`. A function whose argument selects between one track and the same track is a function with a dead branch. |
| `doc_courier_fee()` | With no courier there is no charge to quote, and a leftover price getter is an invitation to wire it back up. |
| `$fulfillment` from `doc_quote()` | The quote is a pure function of the catalog row and the quantity. Nothing else may vary. |
| the parameter from `doc_stage_track()` / `doc_stage_position()` | One meaningful value, two callers, and a signature change is what stops call sites reading `fulfillment_type` at all. |
| the `ship` action in `api/documents.php` | An API verb that reaches nothing is worse than a refusal. |
| `shipped_at`, `lalamove_order_ref` writes | Nothing writes them now. The columns stay; the rows that carry them are history. |
| the delivery radio group, address field, and desk select | Two of the three old options promised something the office does not do: it runs no courier and issues no emailed copy of a TOR. |

**The `fulfillment_type` COLUMN stays.** It is NOT NULL with a default and
existing rows carry it, so dropping it means a migration and a rewrite of
the status walk for no gain. It is now written once, by
`doc_fulfillment()`, and read nowhere that branches on it.

### What a legacy `Shipped` row does

It has to keep rendering, so it is handled explicitly rather than allowed
to fall through:

* `doc_status_meta()` marks it `legacy` and labels it "On its way
  (discontinued)".
* `doc_stage_position('Shipped')` returns index 2 - the **Ready** station -
  rather than index 0, so a discontinued courier row does not draw as
  though it had never started.
* `doc_next_step()` returns `null` for it: there is no forward transition.
* `student/documents.php`'s `renderStepper()` resolves it to `Ready` for
  the same reason the desk does.
* `.pill.shipped` is kept in `css/documents.css` for the same reason. A
  legacy row rendering with no colour is a regression in the other
  direction.

## Wizard columns

Added by `migrations/document_request_wizard.sql`:

| Column | Notes |
|---|---|
| `wizard_step` | 0–4. How far the student got. Drafts only. |
| `notes` | Free text from "Additional Notes". Distinct from `purpose`. |
| `purpose_code` | Controlled purpose, so the desk can filter. `purpose` stays free text. |
| `submitted_at` | When the wizard completed. The stamp separating "filed" from "still being filled in". |
| `estimated_release_at` | Promised date, **computed once at filing**. A date that shifts on every page load cannot be quoted at a counter. |
| `payment_reference` | Quoted to the student for bank/counter payment. |
| `cancelled_at`, `cancellation_reason` | Withdrawal. |
| `delivery_address` | **UNUSED.** The column exists; nothing writes or reads it. |
| `shipped_at`, `lalamove_order_ref` | **UNUSED** for the same reason. |

`estimated_release_at` is the desk being **held to a promise**. It is
rendered on the desk as "Promised to student" for that reason — it is not
the registrar's own estimate.

## Related tables

- [[document_request_attachments]] — one row per uploaded file
- [[document_type_requirements]] — the per-SKU upload checklist

## Related

- [[Document Requests]] · [[doc_wizard]] · [[students]] · [[users]]
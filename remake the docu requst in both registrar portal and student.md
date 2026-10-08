# Document Request Module — UI/UX Architecture

> Registrar AI Support System · Frontend Specification
> Version 1.0 · Last updated: 2026-10-08

---

## Table of Contents

1. [Overview](#overview)
2. [User Flow Map](#user-flow-map)
3. [Screen-by-Screen Architecture](#screen-by-screen-architecture)
   - [Screen 1 — Student Dashboard](#screen-1--student-dashboard)
   - [Screen 2 — Start New Request (Doc Type Picker)](#screen-2--start-new-request-doc-type-picker)
   - [Screen 3 — Request Form (Details)](#screen-3--request-form-details)
   - [Screen 4 — Upload Requirements](#screen-4--upload-requirements)
   - [Screen 5 — Review & Confirm](#screen-5--review--confirm)
   - [Screen 6 — Payment](#screen-6--payment)
   - [Screen 7 — Confirmation](#screen-7--confirmation)
   - [Screen 8 — Tracking Page](#screen-8--tracking-page)
   - [AI Chat Drawer](#ai-chat-drawer)
4. [Button → Backend Action Map](#button--backend-action-map)
5. [Component Hierarchy](#component-hierarchy)
6. [Key UX Rules](#key-ux-rules)

---

## Overview

The **Document Request Module** allows students and alumni to request registrar documents (TOR, Diploma, Certificate of Enrollment, Good Moral, etc.) through a guided, multi-step wizard. It integrates payment, AI assistance, and live tracking.

**Primary goals:**

- Reduce counter traffic for routine document requests
- Provide clear status visibility for requesters
- Automate validation, pricing, and notifications
- Surface AI assistance without blocking the flow

---

## User Flow Map

```
[Login] → [Dashboard] → [New Request] → [Doc Type] → [Form] → [Upload]
   → [Review] → [Payment] → [Confirmation] → [Tracking] → [Release]
```

---

## Screen-by-Screen Architecture

### Screen 1 — Student Dashboard

```
┌─────────────────────────────────────────────────────────────────┐
│  🎓 Registrar Portal              🔔  👤 Juan Dela Cruz ▼       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Welcome back, Juan! 👋                                         │
│                                                                 │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐             │
│  │ 📄 New       │ │ 📋 My        │ │ 💬 Ask       │             │
│  │ Request      │ │ Requests     │ │ Registrar AI │             │
│  │              │ │              │ │              │             │
│  │ [Start →]    │ │ [View →]     │ │ [Chat →]     │             │
│  └──────────────┘ └──────────────┘ └──────────────┘             │
│                                                                 │
│  Recent Requests                                                │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ REQ-2025-000123  TOR         Processing   🟡  [Track]     │  │
│  │ REQ-2025-000098  Diploma     Ready        🟢  [Track]     │  │
│  │ REQ-2025-000045  Good Moral  Released     ✅  [View]      │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons & Actions:**

| Button | Action |
|---|---|
| `[Start →]` | Navigate to `/requests/new` |
| `[View →]` | Navigate to `/requests` (list) |
| `[Chat →]` | Open AI chat drawer |
| `[Track]` | Show request detail page |
| 🔔 | Notification dropdown |
| 👤 | Profile / logout menu |

---

### Screen 2 — Start New Request (Doc Type Picker)

```
┌─────────────────────────────────────────────────────────────────┐
│  ← Back           New Document Request                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  What document do you need?                                     │
│                                                                 │
│  🔍 [Search or describe your need...]  e.g. "proof of enrollment"│
│                                                                 │
│  ┌────────────────────────┐  ┌────────────────────────┐         │
│  │ 📜 Transcript of       │  │ 🎓 Diploma             │         │
│  │    Records (TOR)       │  │                        │         │
│  │ ₱150 · 5 days          │  │ ₱200 · 7 days          │         │
│  │ [Select]               │  │ [Select]               │         │
│  └────────────────────────┘  └────────────────────────┘         │
│                                                                 │
│  ┌────────────────────────┐  ┌────────────────────────┐         │
│  │ 📄 Certificate of      │  │ ⭐ Good Moral          │         │
│  │    Enrollment          │  │    Certificate         │         │
│  │ ₱50 · 2 days           │  │ ₱50 · 3 days           │         │
│  │ [Select]               │  │ [Select]               │         │
│  └────────────────────────┘  └────────────────────────┘         │
│                                                                 │
│  ┌────────────────────────┐  ┌────────────────────────┐         │
│  │ 🏆 Honorable Dismissal │  │ 📊 Certification of    │         │
│  │ ₱100 · 5 days          │  │    Grades              │         │
│  │ [Select]               │  │ ₱75 · 3 days           │         │
│  └────────────────────────┘  └────────────────────────┘         │
│                                                                 │
│  💡 Not sure? [Ask the Registrar AI 🤖]                         │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| Search bar | AI-powered fuzzy match on `document_types` |
| `[Select]` | Sets `document_type_id`, go to Step 2 |
| `[Ask the Registrar AI]` | Opens chat; AI can auto-select doc |

---

### Screen 3 — Request Form (Details)

```
┌─────────────────────────────────────────────────────────────────┐
│  ← Back           TOR Request                     Step 1 of 4   │
│  ▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  📜 Transcript of Records (TOR)                                 │
│  Base fee: ₱150 · Processing: 5 business days                   │
│                                                                 │
│  ─── Request Details ───────────────────────────────            │
│                                                                 │
│  Purpose *                                                      │
│  [▼ Employment / Further Studies / Board Exam / Other]          │
│                                                                 │
│  Number of Copies *                                             │
│  [ − ]  1  [ + ]                                                │
│                                                                 │
│  Delivery Method *                                              │
│  ( ) 🏫 Pick up at Registrar                                    │
│  ( ) 🚚 Courier (adds ₱120)                                     │
│  ( ) 📧 Digital copy (email, ₱0)                                │
│                                                                 │
│  ─── Delivery Address (if courier) ─────────────                │
│  [Street, Barangay, City, Province, ZIP]                        │
│                                                                 │
│  Additional Notes (optional)                                    │
│  [___________________________________________________]          │
│                                                                 │
│  ─────────────────────────────────────────────                  │
│                          [Cancel]   [Continue →]                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| `[−] / [+]` | Adjust copies; recalculate fee live |
| Radio delivery | Toggle address field |
| `[Cancel]` | Confirm discard → back to dashboard |
| `[Continue →]` | Save draft → Step 2 (Uploads) |

---

### Screen 4 — Upload Requirements

```
┌─────────────────────────────────────────────────────────────────┐
│  ← Back           Upload Requirements             Step 2 of 4   │
│  ▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  For TOR, you need to upload the following:                     │
│                                                                 │
│  ✅ Valid School ID                              [Uploaded ✔]   │
│  ⬜ Clearance from Library                        [📎 Upload]   │
│  ⬜ Clearance from Accounting                     [📎 Upload]   │
│  ⬜ 2x2 ID Picture (white background)             [📎 Upload]   │
│                                                                 │
│  ─── Or drag & drop files here ────────────────                 │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │      📤 Drag files here or click to browse               │   │
│  │      Accepted: JPG, PNG, PDF · Max 5MB each              │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ⚠️ Missing requirements may delay your request.                │
│                                                                 │
│  ─────────────────────────────────────────────                  │
│                     [← Back]   [Save Draft]   [Continue →]      │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| `[📎 Upload]` | File picker per requirement |
| Drag zone | Multi-file upload |
| `[Save Draft]` | Persist request with `status='draft'` |
| `[Continue →]` | Validate all required uploads, then Step 3 |

---

### Screen 5 — Review & Confirm

```
┌─────────────────────────────────────────────────────────────────┐
│  ← Back           Review Your Request             Step 3 of 4   │
│  ▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  📋 Request Summary                                             │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ Document:      Transcript of Records (TOR)                │  │
│  │ Purpose:       Employment                                 │  │
│  │ Copies:        1                                          │  │
│  │ Delivery:      Pick up at Registrar                       │  │
│  │ Attachments:   4 files                                    │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                 │
│  💰 Fee Breakdown                                               │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ TOR (1 copy)                              ₱ 150.00        │  │
│  │ Courier fee                               ₱   0.00        │  │
│  │ Processing fee                            ₱  10.00        │  │
│  │ ────────────────────────────────────────────────────────  │  │
│  │ TOTAL                                     ₱ 160.00        │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                 │
│  ☑ I certify that all information provided is true and correct. │
│  ☑ I agree to the Data Privacy Policy.                          │
│                                                                 │
│  ─────────────────────────────────────────────                  │
│                   [← Back]   [Submit Request →]                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| Checkboxes | Must both be checked to enable Submit |
| `[Submit Request →]` | Creates request → generates tracking no → Step 4 |

---

### Screen 6 — Payment

```
┌─────────────────────────────────────────────────────────────────┐
│                Payment                            Step 4 of 4   │
│  ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░░░░░░  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ✅ Request submitted!                                          │
│  Tracking No:  REQ-2025-000123                                  │
│                                                                 │
│  Amount Due:   ₱ 160.00                                         │
│                                                                 │
│  Choose payment method:                                         │
│                                                                 │
│  ┌──────────────────────────┐  ┌──────────────────────────┐     │
│  │  💙 GCash                │  │  💚 Maya                 │     │
│  │  [Pay with GCash →]      │  │  [Pay with Maya →]       │     │
│  └──────────────────────────┘  └──────────────────────────┘     │
│                                                                 │
│  ┌──────────────────────────┐  ┌──────────────────────────┐     │
│  │  🏦 Bank Transfer        │  │  🏫 Pay at Cashier       │     │
│  │  [Show Details →]        │  │  [Get Reference Code →]  │     │
│  └──────────────────────────┘  └──────────────────────────┘     │
│                                                                 │
│  [Pay Later — I'll pay at the registrar]                        │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| `[Pay with GCash/Maya]` | Redirect to payment gateway (PayMongo/Xendit) |
| `[Show Details →]` | Bank details modal |
| `[Get Reference Code →]` | Generates over-the-counter code |
| `[Pay Later]` | Keeps status `awaiting_payment`, sends reminder |

---

### Screen 7 — Confirmation

```
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│                    ✅ Request Confirmed!                        │
│                                                                 │
│  Your request has been submitted and is now being processed.    │
│                                                                 │
│  📋 Tracking Number                                             │
│  ┌───────────────────────────────────────┐                      │
│  │     REQ-2025-000123                   │  [📋 Copy]           │
│  └───────────────────────────────────────┘                      │
│                                                                 │
│  📅 Estimated Release:  Oct 15, 2026                            │
│  📍 Delivery:           Pick up at Registrar Office             │
│                                                                 │
│  What happens next?                                             │
│  1️⃣ Registrar verifies your documents (1 day)                   │
│  2️⃣ Document is prepared (3–5 days)                             │
│  3️⃣ You'll be notified when it's ready                          │
│                                                                 │
│  [Track My Request]   [Back to Dashboard]   [Download Receipt]  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| `[📋 Copy]` | Copy tracking number |
| `[Track My Request]` | Go to tracking page |
| `[Back to Dashboard]` | Home |
| `[Download Receipt]` | Generate PDF receipt |

---

### Screen 8 — Tracking Page

```
┌─────────────────────────────────────────────────────────────────┐
│  ← Back           Tracking: REQ-2025-000123                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Transcript of Records · 1 copy · Pick up                       │
│  Status: 🟡 PROCESSING                                          │
│                                                                 │
│  Timeline                                                       │
│  ─────────────────────────────────────────────                  │
│  ✅ Oct 08, 10:15 AM   Request Submitted                        │
│  ✅ Oct 08, 11:30 AM   Payment Verified (₱160 via GCash)        │
│  ✅ Oct 08, 02:00 PM   Requirements Validated                   │
│  🟡 Oct 09, 09:00 AM   Document Being Prepared                  │
│  ⬜ —                   Ready for Pickup                         │
│  ⬜ —                   Released                                │
│                                                                 │
│  🗨️ Need help with this request? [Ask AI]                       │
│                                                                 │
│  [Cancel Request]   [Message Registrar]                         │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Buttons:**

| Button | Action |
|---|---|
| `[Ask AI]` | Chat with context = this request ID |
| `[Cancel Request]` | Only if status allows; opens confirm modal |
| `[Message Registrar]` | Sends message to staff inbox |

---

### AI Chat Drawer

Always accessible from any screen via floating button or header icon.

```
┌───────────────────────────────────────┐
│  🤖 Registrar AI            [−] [×]   │
├───────────────────────────────────────┤
│                                       │
│  🤖 Hi Juan! How can I help?          │
│                                       │
│      Quick options:                   │
│      [Track my request]               │
│      [What documents do I need?]      │
│      [How much is TOR?]               │
│                                       │
│  👤 Where is my TOR?                  │
│                                       │
│  🤖 Your TOR (REQ-2025-000123) is     │
│     currently being processed.        │
│     Estimated release: Oct 15.        │
│     [View full tracking →]            │
│                                       │
├───────────────────────────────────────┤
│  [Type your question...]         [➤]  │
└───────────────────────────────────────┘
```

---

## Button → Backend Action Map

| UI Button | API Call | DB Effect |
|---|---|---|
| `[Select]` doc type | — (client state) | — |
| `[Continue →]` Step 1 | `PATCH /requests/:id/draft` | Update draft |
| `[Upload]` | `POST /requests/:id/attachments` | Insert attachment |
| `[Submit Request →]` | `POST /requests` | Insert request + tracking no |
| `[Pay with GCash]` | `POST /payments/initiate` | Insert payment `pending` |
| Payment webhook | `POST /webhooks/payment` | Update payment `paid`, request `paid` |
| `[Track My Request]` | `GET /requests/:id` | Read-only |
| `[Cancel Request]` | `PATCH /requests/:id/status` | `status='cancelled'` |
| `[Ask AI]` | `POST /ai/chat` | Insert ai_conversations |
| `[Download Receipt]` | `GET /requests/:id/receipt` | Generate PDF |

---

## Component Hierarchy

```
<App>
 ├── <Layout>
 │    ├── <Header> (notifications, profile)
 │    └── <Sidebar>
 ├── <Dashboard>
 │    ├── <QuickActionCard />   ← New Request
 │    ├── <QuickActionCard />   ← My Requests
 │    ├── <QuickActionCard />   ← Ask AI
 │    └── <RecentRequestsTable />
 ├── <RequestWizard>            ← The whole flow
 │    ├── <Stepper />
 │    ├── <Step1_DocTypeSelect />
 │    ├── <Step2_RequestForm />
 │    ├── <Step3_Uploads />
 │    ├── <Step4_Review />
 │    ├── <Step5_Payment />
 │    └── <Step6_Confirmation />
 ├── <TrackingPage>
 │    └── <StatusTimeline />
 └── <AIChatDrawer />
```

---

## Key UX Rules

1. **Always show the step count** (`Step 2 of 4`) so users know where they are.
2. **Save drafts automatically** — don't lose uploads on refresh.
3. **Live fee calculation** — every change updates total instantly.
4. **AI is optional but visible** — never blocks the flow, always one click away.
5. **Tracking number is the student's anchor** — show it everywhere.
6. **Status colors are consistent** — 🟡 processing, 🟢 ready, ✅ released, 🔴 rejected.
7. **Disable buttons, don't hide them** — e.g., Submit disabled until checkboxes ticked.

---

## Status Color Legend

| Status | Color | Emoji |
|---|---|---|
| Pending | Yellow | 🟡 |
| Processing | Yellow | 🟡 |
| Ready | Green | 🟢 |
| Released | Green | ✅ |
| Rejected | Red | 🔴 |
| Cancelled | Gray | ⚫ |

---

## Related Documents

- `architecture.md` — Full system architecture
- `api-spec.yaml` — REST API specification
- `database-schema.sql` — DB schema reference

---

*End of Document Request Module UI/UX Architecture*
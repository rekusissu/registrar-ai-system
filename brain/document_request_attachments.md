---
tags: [table]
---

# 🗄️ `document_request_attachments` · `document_type_requirements`

The two tables behind the wizard's upload step. Added by
`migrations/document_request_wizard.sql`.

## Why they exist

The wizard collects **several** files for one request — one per
checklist item, plus a drag-and-drop dropzone — and the checklist
differs per document. Neither fits where they used to live:

- `document_requests.requirement_file_path` holds exactly one path.
  Several files became a comma-separated string, and every future query
  a `FIND_IN_SET`.
- `document_catalog.requirement` is a single free-text note. It cannot
  record that an item is *required* rather than advisory, how many files
  it accepts, or what order it appears in.

## `document_type_requirements` — the checklist

One row per item a given SKU needs.

| Column | Notes |
|---|---|
| `catalog_id` | FK → `document_catalog` |
| `code` | Stable key (`school_id`, `dean_signature`). Unique per catalog. |
| `label` | What the student reads. |
| `hint` | Format guidance. |
| `is_required` | **A column, not a label prefix.** The wizard has to be able to *ask* the database which items are mandatory rather than guess from the text. |
| `max_files` | How many files satisfy the item. |
| `sort_order` | Display order. |

Data, not code, so the office can add a requirement to a document
without a deploy. Seeded by the migration; add to it, don't hardcode.

## `document_request_attachments` — the uploads

One row per uploaded file.

| Column | Notes |
|---|---|
| `request_id` | FK → `document_requests` |
| `requirement_code` | Which checklist item it satisfies. **NULL** for a loose dropzone file. |
| `original_name` | As uploaded. Display only — never used to build a path. |
| `file_path` | Relative to the repo root. The stored name is a hash. |
| `sha256` | Proves the bytes are the ones that were uploaded. |
| `mime_type`, `size_bytes` | Recorded at upload. |

`requirement_code` is what makes "still missing: library clearance" a
LEFT JOIN rather than a guess from the filename. A code that is not on
the SKU's checklist is **rejected at upload**, so a crafted code cannot
file a file against a requirement that does not exist.

The stored file is served through `api/file-download.php?kind=attachment`,
never directly: the owner may read their own, staff may read any, and
anyone else gets a 404 that does not confirm the id exists.

## Adoption

`document_requests.requirement_file_path` predates both tables and is
still written by the walk-in counter form. To pull those files into the
wizard's view:

```
php scripts/adopt_legacy_attachments.php
```

Idempotent. Files are filed against a checklist item **only** where the
SKU names exactly one — with two or more there is no honest way to know
which, and guessing would tick a box on a guess.

## Related

- [[document_requests]] · [[Document Requests]] · [[doc_wizard]]
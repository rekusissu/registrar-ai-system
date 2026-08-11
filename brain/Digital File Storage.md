---
tags: [subsystem]
---

# 📁 Digital File Storage

Subsystem 9 (Phase 1) — uploaded student documents.

## What it does

- Stores uploaded files per student with type, size, MIME, description, uploader
- Doc types: `enrollment`, `transcript`, `health`, `photo`, `clearance`, `other`
- Files land under `uploads/` (gitignored; `uploads/students/` and `uploads/ids/` keep `.htaccess`)
- Phase 1 adds `category` and `is_locked` flags

## Tables

- [[documents]] — file metadata (filename, path, size, type)

## Pages

- `registrar/file-storage.php` — file storage management
- `registrar/documents-archive.php` — archive view

## Related

- [[Subsystems MOC]] · [[Document Requests]] · [[Document Reader]] · [[documents]]

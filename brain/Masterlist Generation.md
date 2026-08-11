---
tags: [subsystem]
---

# 📊 Masterlist Generation

Subsystem 10 (Phase 1) — auto-generated, cached student masterlists.

## What it does

- Generates section/course masterlists with a per-section cap (`MAX_STUDENTS_PER_SECTION`, default 50)
- Section codes encode year/semester/number, e.g. `11001` = yr 1, sem 1, section 1
- Results cached per-user in [[masterlist_cache]] keyed by `query_hash` to cut DB load

## Tables

- [[masterlist_cache]] — cached result data (JSON) with expiry

## Pages & endpoints

- `api/masterlist.php` — masterlist generation
- `registrar/masterlist.php` — masterlist UI

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[masterlist_cache]] · [[config.php]]

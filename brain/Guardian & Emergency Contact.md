---
tags: [subsystem]
---

# 👨‍👩‍👧 Guardian & Emergency Contact

Subsystem 2 (Phase 1) — family/guardian and emergency contact management.

## What it does

- Stores guardians with relationship type and contact details
- Flags primary (`is_primary`) and emergency (`is_emergency`) contacts
- Phase 1 adds a dedicated [[emergency_contacts]] table, separate from [[guardians]]

## Tables

- [[guardians]] — family/guardian contacts (father/mother/guardian/spouse/sibling)
- [[emergency_contacts]] — dedicated emergency contact list *(added by Phase 1)*

## Pages

- `registrar/guardians.php` — guardian management

## Related

- [[Subsystems MOC]] · [[Student Management]] · [[guardians]] · [[emergency_contacts]]

---
tags: [reference]
---

# ⚙️ `shared/config.local.php`

Per-station scanner mapping — **gitignored** (`shared/config.local.php` in `.gitignore`). Copy from `shared/config.local.template.php`.

## Purpose

Each scanning PC maps itself to a reader so the kiosk knows which station it is:

```php
// Main Gate PC   → SCANNER_READER_ID = 1
// Library PC     → SCANNER_READER_ID = 2
// Lab PC         → SCANNER_READER_ID = 3
// Dormitory PC   → SCANNER_READER_ID = 4
define('SCANNER_READER_ID', 1);
```

## Related

- [[RFID Access]] · [[config.php]] · [[Setup & Verification]]

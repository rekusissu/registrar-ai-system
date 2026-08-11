---
tags: [page]
---

# 🧭 `includes/header.php`

Reusable page header — meta tags, CSS, loader. Include after session config.

## Usage

```php
$page_title = 'Dashboard';       // optional
$page_description = '...';       // optional
include 'includes/header.php';
```

## What it loads

- Font Awesome 6.5.1 + Google Fonts (Inter) via CDN
- App CSS: `page-loader.css`, `components.css`, `sidebar.css`, `registrar.css`, `dashboard.css`
- `js/page-loader.js` (must be first)
- Supports `$extra_css` array + inline `$page_styles`

## Related

- [[sidebar.php]] · [[dashboard.php]] · [[Architecture MOC]]

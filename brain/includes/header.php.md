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
- Supports `$extra_css` array (student pages pass `['student.css']`) + inline `$page_styles`

## Body hooks

- Optional `data-page="<?= $body_page ?>"` for page-specific JS (e.g. `console` queue page)

## Related

- [[sidebar.php]] · [[dashboard.php]] · [[Architecture MOC]]

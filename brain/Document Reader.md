---
tags: [architecture]
---

# 📄 Document Reader

`shared/document_reader.php` — extracts raw text from uploaded files so the AI client can parse student details.

## Supported formats

| Format | Mechanism | Depends on |
|---|---|---|
| PDF | `pdftotext` (poppler) CLI | poppler on PATH |
| DOCX | unzip + SimpleXML (`word/document.xml`) | unzip CLI + ext-simplexml |
| TXT | plain file read | — |

## Usage

```php
require_once __DIR__ . '/document_reader.php';
$text = extractDocumentText($tmpPath, $origName);
```

Returns `''` on failure; throws `RuntimeException` with a friendly message for unsupported types.

## Related

- [[AI Client]] · [[Digital File Storage]] · [[Student Template]]

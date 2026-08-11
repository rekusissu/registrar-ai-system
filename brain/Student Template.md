---
tags: [architecture]
---

# 📝 Student Template

`shared/student_template.php` — builds a fillable Word (.docx) enrolment form.

## What it does

- Generates a `.docx` with **form text fields (FFData)** so students can tab between fields in Word, plus static labels
- Students fill it out; the registrar uploads the completed form straight into the AI Document Reader
- Built with `PharData` to assemble the OOXML package — no external tools

## Structure

`studentTemplateSections()` returns all form fields grouped into sections (Personal Information, etc.). The `key` values match what the AI extractor returns.

## Related

- [[Document Reader]] · [[AI Client]] · [[Student Management]]

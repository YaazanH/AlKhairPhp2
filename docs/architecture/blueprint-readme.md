# AlKhair Architecture Notes

This directory records the domain decisions that guided the implemented AlKhair application. The documents are architectural references; the generated [`../codebase-index.md`](../codebase-index.md) is the authoritative map of current routes, source files, and symbols.

## Documents

- [`blueprint.md`](blueprint.md) — domain model, migrations, relationships, points, attendance, memorisation, finance, and delivery phases
- [`permissions-matrix.md`](permissions-matrix.md) — baseline roles, permissions, and scope expectations
- [`../codebase-index.md`](../codebase-index.md) — generated implementation index

## Implemented Decisions

- One `users` table provides authenticated identities, with separate student, teacher, and parent profiles.
- Groups are running classes; courses and curricula provide reusable learning structure.
- Enrolments connect students to groups and retain course-specific progress and lifecycle history.
- Memorisation is recorded page-by-page, with lifetime-page protection against accidental duplicates.
- Quran progress includes partial, final, and Awqaf saber workflows.
- Attendance is day-based and supports student and teacher records.
- Points use a ledger so automatic and manual adjustments remain auditable.
- Finance covers funds, currencies, exchange, requests, invoices, transactions, reports, and PDF output.
- The interface supports Arabic RTL and UK-English LTR catalogues with automated key-parity checks.
- Web and versioned API access share the same roles, permissions, and scope rules.

## Keeping the References Current

After adding routes, commands, Livewire components, or public methods, regenerate the implementation index:

```bash
php artisan codebase:index
```

Validate architectural changes with the complete suite:

```bash
php -d memory_limit=512M vendor/bin/phpunit --display-errors
```

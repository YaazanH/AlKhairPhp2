# Alkhair App Blueprint

This folder contains the first-pass product and database blueprint for the new Laravel application `alkhairapp`.

The goal is to lock the core architecture before scaffolding the Laravel codebase so migrations, permissions, APIs, and reports are built on a stable model.

## Included

- `docs/blueprint.md`: domain model, migration list, relationships, points rules, attendance design, memorization design, finance, and build order.
- `docs/permissions-matrix.md`: baseline roles and permissions for admin, manager, teacher, parent, and student.

## Locked Decisions

- One `users` table for all authenticated accounts.
- Separate business profile tables for `students`, `teachers`, and `parents`.
- `groups` represent actual running classes; `courses` remain catalog/master data.
- Points are stored as ledger transactions and may be positive or negative.
- Memorization is stored page-by-page for reporting and history.
- Lifetime memorization is stored separately to prevent duplicate page entry across enrollments.
- Attendance is recorded by day, then expanded into student and teacher records.
- Finance supports both tuition invoices and activity-related charges/payments.

## Recommended Next Build Step

After approving this blueprint, the next implementation step is:

1. scaffold the Laravel app in `alkhairapp`
2. install auth, Livewire, permissions, API auth, and OpenAPI packages
3. create migrations in the order listed in `docs/blueprint.md`
4. seed lookup tables and initial roles/permissions


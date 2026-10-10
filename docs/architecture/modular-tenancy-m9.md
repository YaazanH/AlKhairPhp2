# Modular tenancy M9: compatibility and release rehearsal

Implemented on `modules-and-tenant-feature` after M8.

## Read-only conversion plan

`LegacyModuleConversionPlanner` expands every legacy package and positive tenant override into explicit module roots, then compares dependency-resolved access with the current compatibility engine. Legacy Finance under a Core package maps to Income and Expenses plus Student Billing; Custom Printing maps to Custom Templates plus ID Cards. Negative overrides are never translated automatically and remain release blockers requiring a manual decision.

No conversion write command is included. A real package/override conversion remains a separately approved production operation after reviewing the generated plan.

## Release rehearsal

Run:

```bash
php artisan saas:rehearse-release
php artisan saas:rehearse-release --tenant=tenant-slug --json
```

The command is read-only and reports:

- landlord and tenant pending migrations;
- legacy-to-module access preservation and override blockers;
- tenant database connectivity and SQLite integrity or MySQL/MariaDB `CHECK TABLE ... QUICK` results;
- critical table row counts for before/after comparison;
- finance, student, inferable, mixed, and unlinked invoice ownership counts;
- public/private tenant storage existence, readability, and writability;
- a consolidated blocker and warning list with a non-zero exit when blocked.

`saas:migrate-tenants` now accepts `--tenant=slug` and `--pretend`, allowing a named tenant's SQL to be previewed without changes. Actual migration still requires an explicit command.

## Local MySQL evidence

The read-only rehearsal for `al-noor-test-four` connected successfully, passed MySQL quick integrity checks, found both storage scopes ready, preserved effective module access, and found no invoice ownership blockers. It correctly failed release readiness because five tenant migrations remain unapplied:

- `2026_09_21_000000_make_group_teacher_optional`
- `2026_09_21_010000_add_center_student_attendance`
- `2026_09_23_000000_allow_student_level_point_transactions`
- `2026_09_23_010000_add_student_ownership_to_invoices`
- `2026_09_24_000000_add_system_flag_to_print_templates`

Those migrations were not applied by M9.

## Production boundary

Automated tests exercise full migrations and integrity checks on disposable SQLite tenant databases. The VPS runbook requires an off-VPS backup and a non-production restoration of one tenant database plus both tenant storage directories. That real infrastructure restoration cannot be truthfully completed from this local repository and remains a production-release gate.

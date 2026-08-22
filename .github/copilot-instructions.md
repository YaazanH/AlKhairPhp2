# Copilot instructions for AlkhairApp

## Build, test, and lint

Install dependencies with:

```bash
composer install
npm install
```

Common local commands:

```bash
php artisan serve
npm run dev
composer run dev
```

`composer run dev` starts the Laravel server, queue listener, Pail logs, and Vite together. The project uses SQLite locally when `.env` is configured that way; the deployment target is MySQL and Docker is defined in `docker-compose.yml`.

Build frontend assets with:

```bash
npm run build
```

Run the full PHP test suite:

```bash
./vendor/bin/phpunit
```

Run one test file or one test method:

```bash
./vendor/bin/phpunit tests/Feature/FinanceAndActivitiesTest.php
./vendor/bin/phpunit --filter test_invoice_components_support_items_and_payments
```

Run PHP formatting checks/fixes with Laravel Pint:

```bash
vendor/bin/pint
```

The CI workflows run `npm run build`, `./vendor/bin/phpunit`, and `vendor/bin/pint`. There is no separate JavaScript lint script in `package.json`.

## Architecture

- This is a Laravel 12 application for student, Quran memorization, attendance, points, assessment, activity, finance, reporting, and public website workflows.
- The authenticated web UI is primarily implemented with Livewire Volt single-file components in `resources/views/livewire/**`. A Volt file contains both its anonymous component class (`<?php ... ?>`) and Blade markup. Routes register these with `Volt::route(...)`.
- Eloquent models in `app/Models` map the domain. Migrations in `database/migrations` are chronological and build from identity/master data through people, learning structure, attendance, Quran workflows, assessments, points, activities, finance, curriculum, and printing/reporting.
- Business workflows belong in focused services under `app/Services` (for example finance posting, points ledgers, memorization, Quran tests, curriculum progress, reporting, and PDF/print rendering). Keep complex state changes and cache synchronization out of Volt markup.
- `routes/api.php` exposes a versioned `/api/v1` Sanctum API. API controllers live under `app/Http/Controllers/Api/V1`; read endpoints are separated from operational and finance write controllers. Parent mobile endpoints must scope records to the authenticated parent’s own children.
- Traditional controllers handle exports, PDF/print output, downloads, and the public website. Blade templates under `resources/views/exports`, `resources/views/print`, and `resources/views/reports` are output-specific rendering layers.
- Roles and permissions use Spatie Permission. Seeders in `database/seeders` establish roles, permissions, master/reference data, and the local demo admin. The default admin credentials are configurable through the `SEED_ADMIN_*` environment variables documented in `README.md`.

## Repository-specific conventions

- Authorize by permission names, not role-name checks. Routes use `permission:...` middleware; Volt components normally call `authorizePermission(...)` in `mount()`. Reuse the authorization and teacher-assignment concerns in `app/Livewire/Concerns`.
- Permission checks are not sufficient for ownership-sensitive data. Apply the existing teacher/group and parent/student scoping query patterns in the component or API controller so teachers only see assigned groups and parents only see their children.
- Prefer existing domain services for writes that affect ledgers, cached totals, progression, or audit history. Finance transactions are ledger records; points are ledger transactions and voiding creates the corresponding compensating history rather than deleting the original record.
- Treat history tables as auditable records. Use activity logging and explicit void/status transitions where existing services/models provide them; do not silently overwrite or delete historical finance, attendance, memorization, assessment, Quran-test, or points data.
- Master/reference data drives business behavior. Reuse seeded records and codes for attendance statuses, point types/policies, assessment types/bands, Quran test types, currencies, payment methods, and categories instead of adding hardcoded alternatives.
- Use Arabic translations and existing localization helpers. Arabic is the default locale and the UI supports Arabic RTL plus English LTR; preserve translation keys and locale-aware formatting for dates, numbers, phones, PDFs, and search.
- Follow the existing Volt component shape: typed public state, `mount()` authorization, `with()` query/data preparation, validation in action methods, pagination via `WithPagination`, and reusable concerns/services for shared behavior.
- Test Livewire behavior with `Livewire\Volt\Volt::test(...)`; feature tests commonly use `RefreshDatabase`, seeded application data, and database assertions. When changing a workflow, update the closest existing feature test rather than adding a separate testing style.
- Keep API response shapes and pagination conventions consistent with the neighboring `app/Http/Controllers/Api/V1` controllers. Use route model binding only after enforcing the authenticated user’s ownership/scope.
- Use the existing PDF/print services and Blade layouts for generated documents. Preserve branding, Arabic text direction, page-size/template behavior, and filesystem cleanup conventions.
- New schema changes should be additive migrations with correct dependency order. Update seeders, model relationships/casts, permissions, routes, translations, and focused tests together when a feature crosses those boundaries.

## Relevant project documentation

- `README.md` contains local setup, demo-admin seeding, current implementation state, and Docker deployment pointers.
- `docs/architecture/blueprint.md` describes the domain model and locked architecture decisions.
- `docs/architecture/permissions-matrix.md` documents permission naming, baseline roles, and ownership-scoping requirements.
- `docs/api.md` and `docs/parent-mobile-api.md` document the public API surfaces.

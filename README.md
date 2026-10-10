# AlKhair App

AlKhair is a bilingual Arabic/English management platform for Quran learning centres. It combines student and teacher administration, curricula, attendance, memorisation and saber tracking, assessments, points, finance, reporting, public-site content, and printable records in one Laravel application.

## Technology

- PHP 8.2+ and Laravel 12
- Livewire 4, Volt, and Flux
- Laravel Sanctum API authentication
- Spatie roles, permissions, and activity logging
- Tailwind CSS 4 and Vite 6
- SQLite for the default local environment; MySQL-ready production configuration
- mPDF and Dompdf for generated documents

Exact dependency versions are locked in `composer.lock` and `package-lock.json`.

## Main Modules

- users, roles, permissions, and scoped access
- parents, teachers, students, photos, and files
- academic years, courses, groups, schedules, and enrolments
- curricula, subjects, lessons, topics, resources, and teacher progress
- student and teacher attendance
- Quran memorisation, partial saber, final saber, and Awqaf saber
- assessments, results, score bands, points, and student notes
- funds, currencies, exchange rates, financial requests, invoices, transactions, and reports
- ID cards, print templates, PDF previews, exports, and barcode workflows
- organisation, public website, sidebar, and programme settings
- Arabic RTL and UK-English LTR interfaces with translation-key parity checks
- versioned Sanctum API endpoints for authorised clients

## Local Setup

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

For active development, run the application, queue listener, logs, and Vite together:

```bash
composer run dev
```

If PHP and Composer are not installed globally on Windows, use the repository toolchain first:

```powershell
$env:PATH=".\\.alkhairapp-tools\\bin;.\\.alkhairapp-tools\\php;$env:PATH"
```

## Demo Administrator

`php artisan db:seed` creates a local administrator. The defaults can be overridden before seeding with:

- `SEED_ADMIN_NAME`
- `SEED_ADMIN_USERNAME`
- `SEED_ADMIN_EMAIL`
- `SEED_ADMIN_PHONE`
- `SEED_ADMIN_PASSWORD`

Do not use seeded credentials in production.

## Validation

Run the complete automated suite with the memory limit required by PDF tests:

```bash
php -d memory_limit=512M vendor/bin/phpunit --display-errors
```

Before a release, also run:

```bash
npm run build
php artisan view:cache
git diff --check
composer audit
npm audit
```

Arabic and English catalogue keys, plus UK-English spelling, are protected by `tests/Feature/LocalizationTest.php`.

## Documentation

- [`docs/api.md`](docs/api.md) — complete API reference
- [`docs/parent-mobile-api.md`](docs/parent-mobile-api.md) — parent mobile API guide
- [`docs/architecture/blueprint.md`](docs/architecture/blueprint.md) — domain and data-model decisions
- [`docs/architecture/permissions-matrix.md`](docs/architecture/permissions-matrix.md) — role and permission model
- [`docs/codebase-index.md`](docs/codebase-index.md) — generated route, source, and symbol index
- [`docs/docker-windows-production.md`](docs/docker-windows-production.md) — Windows-hosted Docker deployment
- [`docs/postman/README.md`](docs/postman/README.md) — Postman collections and environment

Regenerate and search the source index with:

```bash
php artisan codebase:index
php artisan codebase:search "assessment score"
php artisan codebase:search "حضور الطالب" --limit=50
```

The generated SQLite FTS5 search database is stored at `storage/app/private/codebase-search.sqlite` and is intentionally ignored by Git.

## Production Notes

- Configure MySQL, queues, mail, storage, and the application URL in `.env`.
- Run `php artisan migrate --force` during deployment.
- Build frontend assets with `npm ci && npm run build`.
- Cache configuration, routes, and views only after production environment variables are available.
- Keep queue workers supervised when notifications, imports, or background work are enabled.

For the supported Apache-on-Windows Docker layout, follow [`docs/docker-windows-production.md`](docs/docker-windows-production.md).

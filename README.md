# Alkhair App

Laravel 12 + Livewire foundation for the Alkhair management platform.

## Included

- authentication starter kit
- Sanctum API tokens
- Spatie roles and permissions
- Scramble OpenAPI docs
- Spatie activity log
- Alkhair reference tables and seeders

## Demo Admin

Running `php artisan db:seed` now creates a local admin user so you can inspect the app immediately:

- email: `admin@alkhair.test`
- password: `P@ssw0rd`

You can override those defaults before seeding with:

- `SEED_ADMIN_NAME`
- `SEED_ADMIN_USERNAME`
- `SEED_ADMIN_EMAIL`
- `SEED_ADMIN_PHONE`
- `SEED_ADMIN_PASSWORD`

## Architecture Docs

Initial product and schema planning lives in:

- `docs/architecture/blueprint.md`
- `docs/architecture/permissions-matrix.md`

## Current State

- local `.env` is still using SQLite so the app can boot immediately on this machine
- `.env.example` is configured for MySQL because that is the target database for the real project
- the first Alkhair migrations currently cover:
  - user identity extensions
  - academic years
  - grade levels
  - attendance statuses
  - assessment types
  - Quran test types
  - point types and point policies
  - payment methods
  - expense categories
  - app settings
  - Quran juz reference data

## Local Commands

If PHP and Composer are not installed globally on this machine, use the local toolchain created in the repo root:

```powershell
$env:PATH=".\\.alkhairapp-tools\\bin;.\\.alkhairapp-tools\\php;$env:PATH"
php artisan serve
php artisan migrate
php artisan db:seed
npm run dev
```

## Next Build Step

The next implementation phase is the people and learning structure module:

- parents
- teachers
- students
- student files/photos
- courses
- groups
- group schedules
- enrollments

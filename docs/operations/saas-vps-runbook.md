# SaaS VPS runbook

This application is deployed directly to Linux/CloudPanel. Docker is not part
of this runbook.

## Tenant media boundary

On a tenant request Laravel switches both filesystem disks to UUID-scoped
directories:

- public media: `storage/app/public/tenants/{tenant-uuid}/`
- private media: `storage/app/private/tenants/{tenant-uuid}/`

The tenant host serves public media through Laravel at `/storage/{path}`. The
web-server configuration must use the normal Laravel fallback for requests to
non-existent files so this route is reached. Do not configure a broad static
alias that bypasses Laravel for tenant media.

## Release migrations

Run the read-only release rehearsal first:

```bash
php artisan saas:rehearse-release
php artisan saas:migrate-tenants --tenant=example-tenant --pretend
```

The rehearsal exits unsuccessfully when it finds pending migrations, database
or storage failures, access-conversion differences, negative overrides, or
ambiguous invoice ownership. It does not write packages, overrides, invoices,
tenant databases, or storage. Use `--json` to save machine-readable deployment
evidence.

After creating and verifying backups, put the application into maintenance
mode and run these commands from the deployed release:

```bash
php artisan migrate --database=landlord --path=database/migrations/landlord --force
php artisan saas:migrate-tenants
php artisan saas:rehearse-release
```

`saas:migrate-tenants` runs the normal tenant migration history against every
active or trial tenant database and returns a failure if any tenant fails. Use
`--tenant=slug` for a named preview or controlled migration, `--pretend` to
print migration SQL without applying it, and `--all` only when a suspended
tenant must also receive a schema update.

## Backup and restore requirement

For every tenant, back up these two units together:

1. the tenant MySQL database named in the landlord `tenants.database_name`;
2. `storage/app/public/tenants/{uuid}` and `storage/app/private/tenants/{uuid}`.

Backups must be copied off the VPS. At least once before go-live, restore one
tenant database and both directories into a non-production environment, sign
in at that tenant subdomain, and verify a photo/logo and a private curriculum
file can be read. Never test a restoration over a live tenant.

Record the restored tenant slug, backup timestamp, database integrity result,
one public-media check, one private-file check, and the operator/date. Automated
backup tests are not a substitute for this off-VPS exercise.

## Release and rollback sequence

1. Record the deployed commit and run `saas:rehearse-release --json`.
2. Back up the landlord database, every tenant database, and each tenant's two
   storage directories. Copy the backup off the VPS and verify it is readable.
3. Put the application into maintenance mode, deploy code and built assets,
   then migrate landlord storage before tenant databases.
4. Rerun the rehearsal. Do not continue while it reports blockers.
5. Clear/cache Laravel configuration and views, restart workers, and smoke-test
   platform login plus representative tenant/module combinations.
6. Leave maintenance mode only after the smoke checks pass.

Prefer a forward fix after a migration has been applied. Do not use migration
`down()` methods as a general live rollback strategy. If a release writes
incompatible data, return to maintenance mode and restore the landlord
database, all affected tenant databases, and their matching storage snapshot
together before deploying the previous code commit. Package/module conversion
is a separately approved operation; the rehearsal never performs it.

## CloudPanel/DNS checklist

- Point the base domain and wildcard `*.example.com` DNS records at the VPS.
- Issue a wildcard TLS certificate for `*.example.com`.
- Set `TENANT_BASE_DOMAIN=example.com` and leave `SESSION_DOMAIN` empty.
- Keep MySQL bound privately; do not expose port 3306 to the internet.
- Configure the scheduler and queue worker under CloudPanel/supervisor.
- Set an off-VPS backup destination and test restoration before accepting
  tenant data.

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

Run these commands from the deployed release, after creating a database backup:

```bash
php artisan migrate --database=landlord --path=database/migrations/landlord --force
php artisan saas:migrate-tenants
```

`saas:migrate-tenants` runs the normal tenant migration history against every
active or trial tenant database and returns a failure if any tenant fails. Use
`--all` only when a suspended tenant must also receive a schema update.

## Backup and restore requirement

For every tenant, back up these two units together:

1. the tenant MySQL database named in the landlord `tenants.database_name`;
2. `storage/app/public/tenants/{uuid}` and `storage/app/private/tenants/{uuid}`.

Backups must be copied off the VPS. At least once before go-live, restore one
tenant database and both directories into a non-production environment, sign
in at that tenant subdomain, and verify a photo/logo and a private curriculum
file can be read. Never test a restoration over a live tenant.

## CloudPanel/DNS checklist

- Point the base domain and wildcard `*.example.com` DNS records at the VPS.
- Issue a wildcard TLS certificate for `*.example.com`.
- Set `TENANT_BASE_DOMAIN=example.com` and leave `SESSION_DOMAIN` empty.
- Keep MySQL bound privately; do not expose port 3306 to the internet.
- Configure the scheduler and queue worker under CloudPanel/supervisor.
- Set an off-VPS backup destination and test restoration before accepting
  tenant data.

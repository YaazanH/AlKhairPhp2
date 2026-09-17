# SaaS Multitenancy - Sprint 0 Baseline

## Purpose

This document fixes the architectural decisions that must stay stable while the
application is converted from one organisation to a SaaS platform. It is a
Sprint 0 planning and readiness artifact; it does not migrate current data,
provision a VPS, or enable payment collection.

## Agreed first-release scope

- The application is hosted directly on a Linux VPS managed through CloudPanel.
  Docker is not part of this plan.
- The platform uses one central **landlord** MySQL database and one logical MySQL
  database per tenant, all on the same MySQL server initially.
- New tenants are created only by a Platform Admin.
- Tenant users belong to exactly one tenant. A Platform Admin is automatically
  created as a protected local user in every tenant and can enter every tenant.
- Tenant sites initially use subdomains only: `{tenant-slug}.{base-domain}`.
- Plans are managed manually. There is no payment gateway, public self-sign-up,
  custom-domain support, or migration of the current production organisation in
  the first release.
- The initial packages are **Core**, **Finance**, and **Custom Printing**.

## Terminology

| Term | Meaning |
| --- | --- |
| Platform Admin | Central SaaS owner/support account. It can create tenants and is represented by a protected full-permission user in each tenant database. |
| Tenant Admin | A normal administrator for one organisation. It cannot access another tenant or alter the protected Platform Admin account. |
| Landlord database | The central database that stores platform administration, tenancy, domains, plans, and subscription state. |
| Tenant database | A database containing one organisation's application data. |

## Database boundaries

### Landlord database

The landlord database is deliberately small. It will contain new SaaS-only
tables, including:

- platform administrator identities
- tenants and tenant lifecycle/provisioning state
- tenant domains and reserved domain validation
- plans, features, plan-feature assignments, and manual subscriptions
- provisioning attempts and platform audit events

It may also host central framework infrastructure such as platform sessions,
cache, and queue metadata. Any queue payload that performs tenant work must
include the tenant UUID and resolve the tenant connection before touching
application data.

### Tenant database

Each tenant receives the complete existing application schema. This preserves
the current domain migrations and their global unique constraints, for example
student numbers, invoice numbers, finance references, and course names. No
`tenant_id` is added to existing business tables.

The current schema contains 109 created tables. All application/domain tables
are tenant-owned, including users, roles and permissions, students, parents,
teachers, learning structure, attendance, Quran workflows, points, curriculum,
finance, print templates, website settings/content, activity logging, and
Sanctum API data.

Framework tables currently present in the tenant schema (sessions, cache,
jobs, failed jobs, and job batches) may remain during the initial conversion so
existing migration history stays intact. The runtime design must still make the
connection used by each process explicit; no background process may rely on a
previous tenant connection.

### Migration strategy

- Existing `database/migrations` remains the tenant-schema migration history.
  New tenant databases run this full history during provisioning.
- New landlord-only migrations will be placed in a separate landlord migration
  path and run once against the landlord connection.
- A future tenant migration command will run a tenant migration path against
  every tenant database and report success or failure per tenant.
- `php artisan migrate --force` alone will not be treated as a SaaS-wide
  deployment command after conversion. A release will run landlord migrations,
  then the tenant migration command, then restart workers.

## Tenant resolution and access

1. A request for `al-noor.example.com` is matched to an active tenant in the
   landlord database.
2. Middleware configures the tenant database connection for the remainder of
   that request.
3. Authentication and application queries use that tenant database.
4. An unknown, suspended, or expired tenant is rejected before application
   routes execute.

The Platform Admin portal is served on the reserved base host at
`example.com/platform` during the initial release. Selecting a tenant creates a normal authenticated session
for that tenant's protected Platform Admin user. This is intentionally simple:
there are no support-access levels or tenant-to-tenant memberships for normal
users. The protected user cannot be edited, removed, or granted by Tenant
Admins, and normal activity logging identifies its actions.

Reserved names include at least `admin`, `api`, `www`, `mail`, and `support`.

## Packages and access rules

| Package | Included capability |
| --- | --- |
| Core | Local users/roles, people, courses/groups/enrolments, attendance, Quran progress/tests, assessments, curriculum, dashboard, standard reports, normal exports, and standard output. |
| Finance | Cash boxes, invoices, transactions, financial requests, finance reports, and finance-specific output. |
| Custom Printing | Creating and editing custom print templates, branded layouts, ID cards, and custom report-card templates. |

Every active tenant has Core. Finance and Custom Printing are manually enabled
or disabled from the landlord portal. Access always requires both the tenant
feature and the local user permission. Feature checks must protect routes,
server-side actions, APIs, exports, and jobs; hiding navigation is not enough.

All tenant databases receive the complete schema regardless of their plan. A
plan changes access to data and behavior, never the existence of tables.

## File storage boundary

The application is deployed directly on the VPS. Tenant files are separated
within Laravel storage, not by Docker volumes:

```text
storage/app/tenants/{tenant-uuid}/
|- public/
|  |- logo/
|  |- students/
|  |- teachers/
|  `- templates/
`- private/
   |- student-files/
   |- curriculum/
   |- finance/
   `- reports/
```

- File paths use the immutable tenant UUID, never a slug or domain.
- Databases store relative file paths only.
- Logos may be public; student files, finance attachments, signatures, and
  private reports require tenant-aware authorization before download.
- A tenant backup is its tenant database plus its tenant storage directory.
- Backups must be copied off the VPS and restoration of one tenant must be
  tested before release.

## Local-first verification environment

Sprint implementation is verified locally before any VPS deployment. From
Sprint 2 onward, local development requires MySQL because provisioning creates
separate databases; SQLite is not sufficient for that workflow.

For the current local setup, modern browsers resolve `*.localhost` to the
local machine. For example:

```text
http://localhost:8000/platform/login
http://al-noor.localhost:8000
http://test-school.localhost:8000
```

The browser and Laravel application will use the hostname to select a tenant.
Local verification requires at least two tenants and must prove that neither
can read the other tenant's data or files.

## New VPS readiness boundary

The future CloudPanel/VPS setup needs one domain, wildcard DNS pointing to the
VPS, and a wildcard TLS certificate for `*.{base-domain}`. The platform portal,
tenant subdomains, and later API host all route to the same Laravel deployment.
MySQL must not be publicly exposed. The VPS needs supervised queue workers,
the Laravel scheduler, health/log monitoring, and an off-VPS backup location.

No VPS configuration is changed in Sprint 0.

## Explicitly out of scope

- Migrating current production data and existing storage
- Deploying to the new VPS
- Docker or Compose deployment
- Payment gateway integration, invoices for SaaS billing, or public registration
- Custom domains
- Mobile application implementation
- Data-residency, per-tenant server, or multi-region hosting tiers

## Sprint 0 exit criteria

Sprint 0 is complete when:

1. This document is reviewed and accepted as the implementation baseline.
2. The current schema is recorded as a tenant schema with 109 created tables.
3. The landlord/tenant migration boundary and tenant file-path rule are fixed.
4. The first-release packages and exclusions are fixed.
5. Sprint 1 can begin without changing current production data or a VPS.

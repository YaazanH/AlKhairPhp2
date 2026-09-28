# Modular tenancy M8: tenant onboarding and capabilities

Implemented on `modules-and-tenant-feature` after M7.

## Setup model

Onboarding state is tenant-owned and stored in the existing `app_settings` table under the `onboarding` group. No package-specific tenant schema and no new migration are required.

- Progress is saved independently for each module and setup-schema version.
- Foundation setup is required and writes the same organisation name, default language, and timezone values used by normal application settings.
- Business-module steps are optional. Administrators may review the linked settings, mark a module ready, or skip it for now.
- Disabled modules disappear from the wizard but retain their saved state. Re-enabling the same version does not repeat setup.
- Raising one module's setup version prompts only that module; it does not restart the full wizard.

Newly provisioned tenants are explicitly marked as onboarding-managed. Existing tenants without that marker receive a one-time inferred-ready baseline for their currently enabled modules, including normalized foundation values, so M8 does not interrupt them. A module enabled after that baseline is detected as a new targeted setup step.

## Access and prompts

Tenant-local Platform Admin users and users with `settings.manage` can manage setup. Required foundation setup redirects those administrators to `/setup`. Optional pending steps prompt once per capability version and then allow access to their real settings pages.

Required setup is also enforced server-side. Authenticated API/domain requests return `409 setup_required: foundation` until foundation is ready; `GET /api/v1/capabilities` remains available so clients can discover setup status. Optional setup never blocks module use.

The capabilities response now includes setup status, version, management permission, and per-enabled-module readiness. Its outer version changes with either entitlement or setup state, allowing mobile clients to refresh on login/resume and after a stale response. No mobile client source exists in this repository, so M8 covers the server contract and browser UI only.

## Verification boundary

Focused tests cover new and existing tenant flows, authorization, required API enforcement, optional completion, module additions, and remove/re-enable state preservation. M8 does not apply migrations or change production tenant data.

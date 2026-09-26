# Modular tenancy M7: packages and tenant extras

Implemented on `modules-and-tenant-feature` after M6.

## Package management

Platform administrators now manage packages from a responsive card grid. A package has create and edit screens, an immutable code after creation, description, assignment status, and a module checklist sourced from the code-defined registry.

- Required dependencies are resolved by `ModuleRegistry`; only the selected package roots are persisted.
- Editing module membership for an assigned package requires previewing the same selection and confirming its impact first.
- The preview lists every assigned tenant and the effective modules added or removed after preserving that tenant's explicit extras.
- Preview signatures include the current package timestamp and calculated tenant impacts. Stale or different selections must be previewed again.
- Packages can be duplicated and deactivated. Deactivation prevents new assignments but does not detach existing tenants.
- Only unused packages can be deleted.
- Create, update, duplicate, status, and delete actions write landlord audit events. Package writes and their audit events are transactional.

The original three legacy packages remain supported. Saving metadata without changing their expanded module selection does not silently convert their stored legacy feature membership. A deliberate changed selection or duplicate creates modular feature membership; full historical conversion remains M9 work.

## Tenant-specific extras

The tenant management page displays package-provided modules and explicit tenant extras separately.

- Extra checkboxes replace only explicit positive overrides through `TenantModuleExtras`; they never subtract package modules.
- Package-supplied modules remain effective even if a redundant explicit extra is cleared.
- Dependencies are derived and are not stored as explicit extras.
- The administrator must preview the exact selection, confirm the tenant-only scope, and save against the current capability version.
- A changed capability version or a mismatched preview fails without writing.
- Recent extras audit events appear on the tenant page.

## Catalog and compatibility

`LandlordCatalogSeeder` registers missing code-defined module metadata with `firstOrCreate`. Existing names, active states, package membership, and administrator-created packages are not overwritten on repeated deployment seeds.

M7 does not apply tenant migrations, mutate tenant business data, or implement onboarding readiness. Setup workflows remain M8.

## Verification

Focused landlord, module-engine, and platform-administration tests cover dependency closure, preview confirmation, package CRUD/lifecycle, audit events, tenant isolation, stale versions, and additive extras. Real-browser checks cover package cards, package creation, tenant extras preview, desktop layout, mobile layout, accessible controls, and console errors.

# M1: registry and entitlement foundation

Implemented 2026-09-20 on `modules-and-tenant-feature`.

## Delivered

- `config/modules.php`: 18 selectable modules plus Foundation, dependencies, conditional ID-card prerequisites and initial explicit action ownership contract.
- `ModuleRegistry`: transitive closure, unknown/inactive module rejection, cycle rejection and package/extra/dependency provenance. ID Cards requires a selected subject module; it does not silently enable all people modules.
- `TenantModuleAccess`: current tenant/subscription checks, dependency-aware snapshots, deterministic content version and module-before-role action checks. Fresh reads avoid stale loaded relations and cross-tenant caches. Foundation remains accessible for operational tenants without a current subscription; business modules do not.
- `TenantModuleExtras::replace`: active Platform Admin required, preview version checked, additive explicit extras only, atomic audited writes. Existing positive override storage is reused; inferred dependencies are not stored as explicit extras. Negative legacy overrides block this write path pending conversion review. Package and subscription rows are locked while saving extras; package controller writes are now atomic and lock the package row.
- Seeder reruns preserve edited package names, status, feature membership and catalog activation. New default packages are still initialized for compatibility.
- Subscription start dates are enforced. Positive legacy extras no longer bypass suspended/expired/future subscriptions.
- Existing `TenantFeatureAccess` delegates new module codes to the registry engine. Deployed `core`, `finance`, and `custom_printing` gates remain legacy-compatible until the corresponding vertical slices migrate. The legacy Finance meaning is intentionally broader than the new Finance module.

## Read-only conversion preview

```sh
php artisan saas:preview-modules
php artisan saas:preview-modules --tenant=al-noor-test-four
```

One JSON row per tenant compares the three deployed feature gates with the proposed module snapshot, explicit extras, provenance, lifecycle and blockers. No feature registration, package conversion, migration, or tenant-data write occurs. Unknown tenant or conversion blockers produce a failing exit status. Negative overrides are reported rather than silently discarded. A plan containing `core` is considered legacy; new modular plans must omit `core`. Legacy Finance maps to Finance + Student Billing, and Custom Printing to Templates + ID Cards. Negative overrides require an explicit later mapping; the new engine fails closed for business modules in that case while current legacy routes retain their previous deny semantics.

The registry is code-defined; missing optional feature metadata rows do not prevent preview. Persisted inactive feature rows do prevent the corresponding selection. Extra writes create only their required metadata records, without changing package membership. No persistent module cache exists yet; clients will use the version contract in M2/M8.

## Verification

26 focused tests / 121 assertions passed across TenantModuleEngineTest, LandlordFoundationTest and PlatformAdministrationTest. Coverage includes dependency conflicts/cycles, source provenance, isolated additive extras, revocation, package changes, lifecycle gates, role bypass, seed safety, read-only preview, and the deployed platform flows. Pint and `git diff --check` passed. A read-only preview of local `al-noor-test-four` succeeded with no conversion errors; it retains Finance/Billing and does not gain printing modules.

## Handoff and limitations

- No runtime schema or local tenant/package records were migrated. Test migrations ran only against the configured test databases.
- No extras UI or HTTP mutation endpoint is exposed yet; the audited service is the M1 write contract for M7. Do not add ad hoc model writes as a substitute.
- The action map is deliberately partial and denies unknown actions. Complete each module's permission/route/Livewire/API/service ownership in its vertical sprint. Record-level authorization still belongs to the calling policy/service.
- This is not full module isolation yet. M2 starts Students / Parents / Parent Portal and capabilities. M3 onward migrates remaining workflows; existing broad feature gates remain until that work is verified.
- Setup readiness, client capability refresh, tailored wizard steps and full package UI remain later sprint deliverables. Registry points dependency is provisional until the manual-points-without-enrollment decision is settled; no point calculation behavior changed.
- Conversion preview is not an automatic migration or a claim that historical data conversion is safe. Mixed-student invoices and legacy negative overrides still need the planned data-specific review.

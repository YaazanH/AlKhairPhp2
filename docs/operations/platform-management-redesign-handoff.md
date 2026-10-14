# Platform management redesign handoff

Prepared 2026-10-14 for the multi-tenant `v2` application. See [the sprint plan](platform-management-redesign-plan.md) for the workflow decisions and individual checkpoints.

## Delivery status

The eight-sprint redesign is implemented and verified within the boundaries below. The user requested publication to `v2` on 2026-10-14. This document accompanies the redesign publication commit; verify its actual remote SHA before deployment. Publication does **not** deploy the application or clear the release gates below. The pre-publication baseline was `af32ef5a6cfbe141551d78f1d6f78d8e961ac45e`.

Record the verified redesign commit SHA for deployment rather than using the older baseline. Publication excludes `output/`, temporary browser artifacts, the unrelated PDF, and `git pull origin master.txt` (which can contain credentials).

This delivery introduces no migrations, dependency changes, new environment variables, or data conversion. `public/build` is Git-ignored: the server must receive a build artifact or run the locked Node build; a code pull alone is insufficient.

## Destinations and compatibility

| Area | Destination and behavior |
| --- | --- |
| Overview | `/platform`: permission-scoped operational summary, not the tenant directory |
| Tenants | `/platform/tenants`; creation at `?create=1`; old `/platform/tenants/create` redirects |
| Tenant workspace | Existing Overview, Organisation, Subscription, Billing, Resources, Features, and Activity URLs remain |
| Packages | Existing `/platform/packages` CRUD; new `/platform/packages/features` catalog |
| Requests | `/platform/support`; separate case details, feature groups, evidence, and website enquiry inbox |
| Billing | `/platform/billing`, `/platform/billing/payments`, `/platform/vouchers` |
| Operations | Existing Metrics, Storage, and Backups URLs, with policies linked to Settings |
| Access | Existing `/platform/access` user directory; new addressable user and owner-only role pages |
| Website | Existing `/platform/landing-page`, with Draft/Publishing/History view parameters and protected `/platform/landing-page/preview` |
| Reports | Existing library and editor URLs, with Definition/Publishing/History views and new read-only revision URLs |
| Settings | `/platform/settings`; old subscription settings GET redirects to `#subscriptions`; its update endpoint remains |

Old Overview search/status bookmarks intentionally redirect to Tenants for permitted users. Sidebar and direct-route permissions remain separate checks. Authentication now falls back to the first permitted Platform destination rather than a forbidden Overview; existing intended URLs retain their original behavior and authorization. Accounts with no management role land on personal Settings. Platform and tenant sign-in remain separate guards and URLs.

Contract changes to communicate to any custom callers:

- Tenant status changes and subscription reactivation require `confirm_slug`, as documented in Sprint 3. Existing cancellation and deletion confirmations remain.
- The redesigned report form sends `workspace_form=report_definition`. An empty calculation selection is respected for this form; legacy callers without the marker retain the existing default record-count behavior.
- Backup creation and restore expose existing operational/provisioned eligibility as validation feedback before invoking the coordinator. Restore still requires the tenant slug in `confirmation`; unusable archives are rejected before service invocation.
- No backend feature codes, immutable learning paths, ledger entries, invoice ownership, or installed tenant report copies were rewritten.

## Verification evidence

| Check | Result |
| --- | --- |
| Broad Platform/SaaS regression selection | 158 tests passed, 1,355 assertions |
| Post-correction authentication/navigation/administration rerun | 63 tests passed, 1,105 assertions; overlaps the other selections |
| New cross-page integration suite | 5 tests passed, 585 assertions |
| Route permission matrix | 39 GET destinations across 14 representative permission profiles |
| Browser matrix | 45 previews across English/Arabic, light/dark, 390×844 mobile and 1440×1000 desktop |
| Build, Blade cache, formatting, lint, diff checks | Passed |
| Locked dependency audit | **Not green**: two existing `league/commonmark` advisories |
| Local read-only release rehearsal | **Not green**: legacy invoice ownership flags and conversion/provisioning warnings |

Counts from reruns overlap and must not be added as independent totals. This is a focused Platform/SaaS selection, not a claim that the entire repository test suite passed.

The broad selection used all then-existing `Platform*.php` feature suites plus `SubscriptionBillingTest`, `TenantModuleEngineTest`, `ReportLibraryInstallationTest`, `TenantProvisioningFoundationTest`, and `RefreshPlatformMetricsCommandTest`. The new integration suite separately covers login fallback, authenticated sign-in, required password replacement, storage-only navigation, read-only Organisation controls, and the route matrix.

Browser QA used a local **read-only fixture host**, not live authenticated sessions. The host rejects non-GET requests and serves the same built application, Flux Lite, Livewire, and Dubai font assets as the application. Each preview render resets framework asset state and supplies the normal Livewire script injection that direct `View::render` bypasses. The final screen sweep must use this corrected host; early previews without those scripts were layout checks only.

The sweep checks page-level horizontal overflow, missing Platform translation keys, and JavaScript runtime exceptions. Manual checks cover native disclosures, field-search selection retention, collapsed validation fields, wizard progression, voucher conditional controls, restore confirmation, and mobile sidebar opening/Escape dismissal. The public-style website preview intentionally keeps the public design rather than adopting the management theme.

Limitations:

- No live provisioning, payment, password reset, role assignment, website/report publication, destructive restore/deletion, or support response was submitted in the browser. Such persistence/authorization checks use isolated test databases and fake storage or mocked destructive services.
- Browser previews do not prove real authenticated server submissions, production network behavior, or a production backup/restore drill. Route tests cover authorization separately.
- Example attachment/enquiry/restore previews include in-memory fixtures; the fixture host cannot download those invented files or mutate their invented records.
- Large/empty/validation states are covered by focused regression fixtures and prior sprint checks; the browser matrix is not an exhaustive accessibility audit or every possible data combination.
- Browser evidence is in `output/playwright/sprint8` and other sprint artifact folders. These may contain local operational data and are not release assets.

## Release gates still requiring attention

The 2026-10-14 locked Composer audit reports:

- `league/commonmark`: raw-HTML disallowed-tag bypass, medium severity, `GHSA-97jj-33gv-5xf9`.
- `league/commonmark`: GFM table parser denial of service, high severity, `GHSA-3q6v-r5mr-hxv8`.

`composer.lock` is unchanged by this redesign. Resolving these advisories is a separate dependency-update and regression task; no package versions were changed or advisories suppressed here. Do not call the release security gate green while they remain.

The local `saas:rehearse-release --json` returned `read_only: true`, `ready: false`. It flagged ambiguous legacy invoices for `hassiba` and `tt`, legacy package membership needing an approved conversion, and four unprovisioned demo tenant records. No pending landlord migrations were reported locally. These are **local findings**, not a production-state assessment. The user identified the invoices as demo data; this redesign intentionally neither assigns nor deletes them, and makes no new ownership decision a prerequisite for reviewing the UI. Rehearse on the actual deployment target and agree any necessary data/conversion work separately.

## Server application sequence — only after publication and release approval

Use the multi-tenant server and its `v2` checkout, not the single-tenant `v1` server. Run commands as the application's deployment user inside the actual application directory, following [the SaaS VPS runbook](saas-vps-runbook.md).

1. Record the deployed SHA and a verified matching database/file backup. Verify the working tree is clean and the branch is `v2`. If it is dirty or divergent, stop and inspect; do not reset, clean, or overwrite server changes.
2. Fetch `origin`, verify the published redesign SHA, and confirm deployment is a fast-forward. The SHA below is a placeholder until publication occurs:

   ```bash
   REDESIGN_COMMIT='REPLACE_WITH_VERIFIED_PUBLISHED_SHA'
   git status --short --branch
   git branch --show-current
   git rev-parse HEAD
   git fetch origin
   git rev-parse origin/v2
   git merge-base --is-ancestor HEAD origin/v2
   ```

   If the remote fetch configuration does not expose `origin/v2`, fetch that branch explicitly and inspect its SHA; do not rename or force-switch a production checkout blindly.

3. Run the read-only target rehearsal and locked audit; resolve or explicitly review the target blockers before deployment. Verify recoverable landlord/tenant databases and corresponding tenant files off-server. Preserve the existing `.env` and `APP_KEY`.
4. For an in-place deployment, enter maintenance mode before changing code/assets:

   ```bash
   php artisan down
   git pull --ff-only origin v2
   git rev-parse HEAD
   ```

   Stop if the deployed SHA does not equal the approved published SHA. Do not continue an in-place deployment with an unexpected newer remote commit.

5. Install from lockfiles if required by the deployment process and build assets:

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   npm ci
   npm run build
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan queue:restart
   ```

   A prebuilt, verified `public/build` artifact can replace the Node installation/build step. Keep the usual scheduler cron and queue/process-manager configuration; this redesign does not install them. Reload the application PHP worker service only through the server's established process manager if needed for opcode-cache invalidation.

6. This redesign adds no migrations. If the target has pending earlier migrations, review and apply them **as a separately approved migration step** using the landlord and `saas:migrate-tenants` runbook. Do not substitute an ordinary tenant `php artisan migrate`, run module conversion automatically, or delete demo invoices to silence rehearsal output.
7. Rerun the target rehearsal/audit and inspect application logs. Leave maintenance mode only after the approved release checks are satisfied. After `php artisan up`, smoke-test `/up`, Platform login, a restricted role's landing page, Overview, Tenants, Requests, Billing/Vouchers, Settings, Website/Report history, and a representative tenant login/module flow. Check both Arabic/mobile and desktop and verify actual submitted validation errors in staging.

## Rollback

Record the previous deployed commit before the upgrade. Prefer deploying an immutable previous release with its matching build assets and restarting workers through the established process manager. If only an in-place checkout is available, agree a reviewed revert/forward-fix procedure; do not use `git reset --hard` against a production working tree.

This UI delivery does not require a schema rollback. It also does not undo legitimate payments, role changes, or publications made after deployment. If any separately approved migration/conversion changes data incompatibly, restore the matching landlord/tenant databases and files together according to the VPS runbook, in maintenance mode. Do not run migration `down()` as a generic recovery step.

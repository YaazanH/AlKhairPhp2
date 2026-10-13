# Platform landing redesign handoff

Reviewed locally on 2026-10-13, branch `v2`. Changes are uncommitted and have not been deployed.

## Delivered

- Redesigned public landing page, responsive English and Arabic layouts, and separate landing stylesheet.
- Five product views: daily overview, learner progress, revenue and finance, parent and student portals, and reports. Generated WebP assets live in `public/images/platform-landing/`.
- Package explorer retains backend package names and explains individual capabilities. Full details remain expandable.
- FAQ accordion, guided demo section, footer, keyboard focus, mobile menu dismissal, form autofill, phone input, and reduced-motion support.
- Independent package visibility control: the landing page includes only packages with both `is_active` and `is_public` enabled. New packages created through the editor and duplicated packages start private. Older update requests preserve visibility when the field is omitted.

## Database state

The landlord migration `2026_10_13_090000_add_public_visibility_to_plans_table` has been applied locally. It adds `plans.is_public`, defaulting existing rows to public. This migration does not touch tenant databases or subscriptions.

At this review, Core, Core + Finance, Core + Finance + Custom Printing, and testFin are active and public. The packages named test and QA Custom Package are active and private. testFin was made private during an earlier step but is public in the current database; preserve this setting unless a further visibility change is requested.

Local visibility changes are database state, not Git changes. Configure visibility separately in the target environment using Platform > Packages > Edit package > Show on public website.

## Verification

- Production asset build and Blade compilation passed.
- Package publication implementation: 37 focused tests passed with 268 assertions.
- Latest keyboard/form polish: 11 landing and navigation tests passed with 131 assertions.
- English and Arabic mobile browser checks found no horizontal page overflow or broken section anchors. Escape closed the mobile menu and returned focus to its summary. Browser console had no errors or warnings.
- These focused checks do not establish that the entire application test suite passes.

## Release gate

The read-only local `php artisan saas:rehearse-release --json` check returned `ready: false` on 2026-10-13:

- Other landlord migrations are pending, including support settings, tenant learning path, and Platform metrics history.
- Tenant migrations are pending across the provisioned local tenants.
- hassiba and tt have ambiguous legacy invoices requiring manual ownership decisions.
- Legacy package membership still requires an approved conversion.

These are local-environment findings. Run the same gate against the actual release target; do not treat this report as evidence of production state. Resolve migration and invoice decisions through the existing SaaS runbook before calling a release ready.

### Blocker triage

A subsequent read-only inspection on 2026-10-13 found 15 tenant records, of which 11 have connected databases. All 11 connected databases have pending migrations: hassiba has 3, and each of the other 10 has 4. Four records have no provisioned database; the rehearsal reports those as warnings.

The 11 pending landlord migrations consist of 8 report templates, support request settings, tenant learning-path identity, and Platform metrics history. These belong to the broader application release and were not applied as part of the website redesign.

Read-only invoice classification confirmed the following review queue:

| Tenant | Invoice IDs | Invoice numbers | Classification |
| --- | --- | --- | --- |
| hassiba | 1, 2, 3 | DEMO-INV-001, DEMO-INV-002, DEMO-INV-003 | mixed |
| tt | 1, 2, 3 | DEMO-INV-001, DEMO-INV-002, DEMO-INV-003 | mixed |

Here, `mixed` means the invoice lacks an explicit finance designation and a student owner, while its items refer to more than one distinct student. None of these invoices qualifies for automatic single-student ownership inference. Demo-style invoice numbers alone do not establish that deleting or reclassifying these records is safe. Their intended business ownership must be decided before any conversion.

No migration, invoice, entitlement, or package visibility changes were made during this triage.

### Local migration follow-up

The subsequent migration phase created completed, verified manual tenant backups for all 11 operational tenants. A separate landlord SQL snapshot was saved in `storage/app/private/release-backups/20261013-140129/landlord.sql` (144498 bytes, SHA256 `26efa6c691c9a81a13c883eb8d55756ceea019df70fdf84ccc364b64436b9640`). The landlord snapshot was checked for successful dump completion and a nonempty file; it has not been restore-tested.

All 11 pending landlord migrations and the pending tenant migrations for all 11 connected tenants were applied locally. The earlier pending-migration inventory above is historical. No invoice ownership or package/module conversion was performed. These backups remain local; this is not evidence of an off-server production restore drill.

### Post-migration regression

The landing, navigation, and Platform administration test files passed together: 39 tests, 335 assertions. A logged-out browser click on the landing-page Sign in link reached `/platform/login`, with no console errors or warnings. The public home and login endpoints for hassiba, demo-learning, and demo-quran each returned HTTP 200. Authenticated tenant workflows were not exercised in this browser pass. The six mixed-student invoices remain unchanged and continue to block the release gate.

## Deployment sequence

1. Review and commit only the intended redesign, package visibility, generated assets, tests, Vite configuration, and this handoff. Preserve unrelated workspace files and artifacts.
2. Follow the existing `saas-vps-runbook.md` for target backups, migration review, and release checks.
3. Apply the package visibility migration on the target landlord database before serving the updated landing controller. A missing `is_public` column causes package queries to fail.
4. Build assets using `npm ci` and `npm run build`; compile Blade views with `php artisan view:cache`.
5. Review target package visibility in the Platform editor. Check public package names, details, demo form, language switch, keyboard navigation, and mobile menu.
6. Verify the target commit and application health after deployment. Publishing Git changes alone does not deploy the application.

No production changes, additional local migrations, invoice conversions, or package data changes were performed during this release review.

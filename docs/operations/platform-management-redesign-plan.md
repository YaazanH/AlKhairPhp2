# Platform management redesign — eight-sprint plan

Prepared 2026-10-13 against v2, baseline af32ef5. Planning only: no application changes in this phase.

## Direction

Redesign the management application around tasks, not its current controller/page boundaries. Reuse the established visual direction, but replace the navigation and interaction structure where needed. The public landing design remains unchanged.

The home dashboard becomes an operational summary, not the tenant directory. Tenant creation becomes a guided flow launched inside Tenants, not a separate sidebar destination. Large workflows may retain addressable routes for refresh, validation, and browser history; removing a navigation item does not require deleting its backend route.

## Source audit and workflow inventory

Reviewed Platform route definitions, navigation, management controllers, and the existing views. This is a source-level audit; live permission-by-permission browser verification is scheduled in sprint 8.

| Existing area | Actual workflow | Proposed destination |
| --- | --- | --- |
| Dashboard | Tenant search/status filtering, tenant management links, scoped support access | Overview summary + separate Tenants directory |
| Metrics | Infrastructure snapshot, storage/backup alerts, history ranges, owner refresh setting | Operations / Metrics; refresh setting in Settings |
| Tenant creation | Organisation/address, permanent learning path, database provisioning, first administrator, storage | Guided creation from Tenants, with progress/failure handling |
| Tenant overview/organisation | Setup/subscription state, balance, organisation defaults, administrator password reset | Tenant workspace overview and Organisation / Access |
| Tenant subscription/billing | Package/voucher selection, trial/paid activation, renewal, charges, offline payment credit, receipts | Tenant Subscription & Billing; global Billing overview |
| Tenant modules | Package-inherited capabilities, optional tenant extras, dependency/version/impact checks | Tenant Features; shared capability presentation with Packages |
| Tenant storage/activity | Usage breakdown, limit update, tenant audit history | Tenant Resources and Activity |
| Packages | Create/edit, capability dependencies, price/period, active/public flags, impact preview, duplicate/deactivate/delete unused | Packages & Features |
| Requests | Forwarded tenant problems/suggestions, type-specific statuses, platform response, attachments, shared suggestion groups | Requests workspace: Problems, Feature requests, Website enquiries |
| Request settings | Bilingual reason/priority/impact options and enabled states | Settings / Requests |
| Vouchers | Discount type/value, tenant scope, redemption limits, application periods, validity, active flag | Billing / Vouchers, directly discoverable |
| Subscription settings | Suspended-data retention | Settings / Subscription policy |
| Backups | History, create/download/restore, schedule/retention configuration | Operations / Backups; policy in Settings |
| Global storage | Per-tenant usage comparison | Operations / Storage |
| Platform users/roles | Account details/status/passwords, owner-managed roles and assignments | Access / Users and Roles |
| Website editor | Bilingual content/images, section structure, draft/publish/revision restore | Content / Website |
| Report library | Safe data-source/field/calculation/presentation definitions, draft/published revisions, protected system templates | Content / Report library |
| Login/password change | Platform authentication, required replacement of temporary password | Preserve separate authentication flows |

### Confirmed discoverability gaps

- Navigation calls the dashboard Tenants and uses Subscriptions to open subscription settings rather than a subscription directory.
- Vouchers exist, but only have a secondary link inside subscription settings.
- Settings are spread across subscriptions, requests, metrics, and backups; no unified settings home exists.
- Website enquiry records are persisted by PlatformLandingController, but there is no Platform enquiry inbox route in the current route group.
- Tenant status/deletion and subscription cancel/reactivate endpoints exist, but no matching route references were found in Platform views. Expose authorized lifecycle actions with deliberate confirmation, not casual inline buttons.
- Requests loads all permitted cases into a single list, with an editing form for each case. Feature-group creation precedes the working queue. There is no dedicated detail route or searchable/paginated queue in this controller.
- Capability inheritance and tenant extras are distinct backend concepts. The design must make that distinction clearer rather than rename backend module codes.

## Proposed navigation

- Overview
- Tenants — directory; Create tenant action; tenant workspace
- Packages & Features — packages; capability/dependency reference
- Requests — Problems; Feature requests and shared groups; Website enquiries
- Billing — Subscriptions; Payments; Vouchers
- Operations — Metrics; Storage; Backups
- Content — Website; Report library
- Access — Users; Roles
- Settings — grouped configuration sections

Use grouped navigation and local tabs, not one flat list of every subpage. Each destination and action must respect its actual permission, including direct URLs. New aggregate pages must not disclose restricted financial/support/access data to dashboard-only users.

## Sprints

1. **Navigation, design foundations, and Settings.** Establish the shared shell, grouped destinations, breadcrumbs, consistent filters/tables/forms/empty states, responsive navigation, and a settings hub. Consolidate existing request options, backup policies, metric refresh, and subscription retention without weakening their different owner/permission requirements. Define an extensible typed settings pattern; additional custom fields need a clear purpose, validation, scope, and consuming behavior. Do not expose arbitrary environment variables or executable configuration.
2. **Real Overview dashboard.** Split the tenant directory from home. Show permission-scoped tenant/subscription health, request attention, backup failures, resource health, and recent operational activity. Separate collected payments, subscription charges, and outstanding balances; do not label tenant credit or future package prices as revenue. Include date ranges and unavailable/stale states where relevant. Reuse cached metrics; avoid scanning every tenant database synchronously on each home visit.
3. **Tenant directory and lifecycle.** Build searchable, paginated tenant management and integrated multi-step creation. Preserve immutable address/learning path, isolation, provisioning rollback, first-admin password replacement, and old input on validation failure. Reorganize tenant detail tabs; expose controlled suspend/resume/delete and subscription lifecycle actions according to backend eligibility and permissions. Provide failure/retry handling only where safe backend support exists.
4. **Packages and Features.** Full package editor and tenant-feature redesign. Present individual capabilities, package-inherited versus additional versus dependency-included states, search/grouping, availability, and change summary. Preserve names/codes, public versus assignable flags, preview/confirmation requirements, and optimistic version checks. Include clear tenant impact before shared-package changes. Do not invent a runtime module-creation feature: the catalog is code-defined.
5. **Requests workspace.** Replace the long editable list with searchable/paginated queues and addressable request detail views. Problems: status, tenant context, response, evidence/attachments. Feature requests: review queue, existing shared-group consolidation, related tenants, and a roadmap-style view using existing statuses. Keep issue statuses distinct from feature statuses. Add a discoverable website-enquiry inbox; any new enquiry lifecycle fields or multi-message conversation require explicit backend implementation and migrations, not merely UI controls. Verify the tenant-to-Platform forwarding and response visibility contract before promising live conversation behavior.
6. **Billing, Subscriptions, and Vouchers.** Global subscription/payment visibility with tenant drill-downs; readable activation/trial/renewal/charge summaries; immutable payment ledger and receipt access. Vouchers become a visible Billing tab with understandable scope, discount, validity, usage, and limited-period behavior. Keep billing actions out of Settings; no alteration or deletion of demo invoices or historical ledger entries.
7. **Operations, Content, and Access.** Redesign metrics/history, storage comparisons, backup management and confirmed restore; website draft/publish/history; report-library definition/revision workflows; Platform account and role management. Keep operational actions on their working pages and policies linked to Settings. Preserve system-template protection, draft-versus-published boundaries, last-owner protections, and tenant-versus-Platform identity separation.
8. **Whole-application verification and handoff.** Audit every old/new GET destination, role-visible navigation, direct-route authorization, and action availability. Browser-check Arabic/English, RTL/LTR, light/dark, desktop/mobile, keyboard/focus/dialogs, form errors and long/empty/populated states. Run focused Laravel tests, build, Blade cache, and diff checks. Document migrations, redirects, deployment and rollback; perform only safe local test actions. Production publication/deployment requires the user's request.

## Definition of done for each sprint

- Complete the workflow, not just the page appearance; every rendered action has an implemented authorized endpoint.
- Existing permissions, backend domain rules, and data are preserved unless a documented change is necessary and approved.
- Review desktop/mobile and both appearances; validate Arabic/English for changed text.
- Add regression tests proportional to changed routes, settings, permissions, and state transitions.
- Record what was verified and what remains untested. Do not claim a static preview verifies authenticated submissions.
- Maintain route compatibility or intentional authorized redirects for existing bookmarks and callers.

## Execution boundaries

Eight implementation sprints is the initial estimate, not a calendar-duration promise. Sprint 7 may split if report-editor complexity warrants it; reassess after each sprint rather than claiming a fixed delivery date. Finish the shared architecture first, then deliver complete vertical workflows in order.

Sprint 7 is now split into **7A: Operations and Access**, followed by **7B: Website and Report Library**. The content editors have separate draft, publish, revision, and system-template constraints, so their redesign is a separate implementation checkpoint before Sprint 8 verification. The scope of the original plan is unchanged.

Use Laravel/Blade/Flux/Alpine and existing theme tokens; no framework replacement. Use Playwright for browser QA. Use Figma only if a design-file workflow is requested. Generated bitmap imagery is not needed for these task-focused admin interfaces; prefer existing icons and code-native charts. Do not install unrelated skills merely to increase their count.

The initial planning phase made no application changes. Implementation progress is recorded below.

## Sprint 1 implementation checkpoint

- Shared permission-filtered navigation is grouped into Workspace, Billing, Operations, Content, and Administration. Vouchers now have a direct destination; the standalone New tenant sidebar item was removed. The directory/dashboard split and integrated creation flow remain in sprints 2–3.
- `/platform/settings` collects personal preferences, subscription retention, backup policy, owner-only metrics collection interval, and the bilingual request-options editor. Each policy retains its existing update authorization and validation; personal preferences are available to authenticated Platform users without exposing restricted policy data.
- Legacy subscription settings redirects to the hub; the old update routes remain supported. Metrics and backup pages link to central policy editing rather than duplicate those forms.
- Shared breadcrumbs and responsive Settings navigation are added using existing theme tokens and Arabic/English translations. No backend capability names or historical data are changed.
- Extension pattern: add named, typed settings with validation in an authorized handler, load values only for permitted roles, expose a corresponding section, and test both update and direct-route denial. No unrestricted arbitrary key/value or environment-variable editor is introduced; new custom setting fields need a defined consuming workflow.
- Verification: navigation/settings tests passed 7 tests / 125 assertions; focused existing administration tests passed 16 tests / 140 assertions. Vite build, Blade compilation, PHP lint, and diff checks passed. Desktop and mobile dark/RTL static browser previews were inspected, with 390px document width matching the viewport. Static preview JS has cross-origin limitations; authenticated browser submissions were not exercised. Tests verified form persistence, validation, and authorization separately.
- No migrations, invoice changes, server changes, commit, or push were performed for Sprint 1.

## Sprint 2 implementation checkpoint

- Home is an operational Overview, with global tenant status counts, permission-scoped payment/charge/current-deficit summaries, subscriptions ending in seven days, request attention, verified-backup health, saved disk-usage snapshots, and owner-only recent audit activity. It no longer renders tenant names or the directory table.
- Payments use payment date; charges use ledger creation date. The 7/30/90-day selection changes period totals, while outstanding balances explicitly remain current/all-time. Positive tenant credit never cancels another tenant's deficit. No accounting-revenue recognition claim is made.
- `/platform/tenants` is a dedicated searchable, status-filtered directory with 20-row pagination and retained filter parameters. It permits view.tenants or manage.tenants; package information is visible only with subscription access. Existing tenant actions keep their individual authorization. Overview and Tenants have separate navigation states; old dashboard search/status links redirect to the directory for permitted users.
- Overview reads the latest persisted interval snapshot without invoking live tenant scans or infrastructure requests. Missing/stale data is labelled; backup and support summaries are separately permission-scoped. Needs-attention links filter suspended, expired, and failed tenants.
- Browser previews inspected English desktop and Arabic/RTL mobile; Overview and directory document widths match a 390px viewport. Static preview JavaScript has cross-origin limitations, so authenticated browser submissions were not verified.
- Build, Blade cache, PHP lint, formatting, and diff checks passed. Regression tests cover permission boundaries, pagination/search/status, old-filter redirects, financial periods/deficits, and saved/stale metrics.
- No migrations, data conversion, invoice edits, commit/push, or deployment. Integrated tenant creation and lifecycle redesign remain Sprint 3.

## Sprint 3 implementation checkpoint

- Creation now lives at `/platform/tenants?create=1`, within Tenants; the legacy create URL redirects there. The guided flow validates Organisation, First administrator, and Review before provisioning. It preserves the original provisioning command, immutable learning path, deferred subscription activation, and old non-password input. Without JavaScript the full form remains usable.
- Step changes move focus to the current heading. The review never includes the password; temporary owner passwords are excluded from validation-session flashing. Submission has a busy state and duplicate-submit protection. Browser tests stopped before actual provisioning.
- Workspace lifecycle controls require manage.tenants and explicit slug confirmation. Suspension and subscription cancellation are separate. Resume requires an existing database and primary domain. Permanent deletion is disclosed only for suspended, provisioned workspaces with a valid tenant database name; warnings explain irreversibility and recommend a verified backup. Failed provisioning records show investigation guidance rather than unsafe automatic retry/deletion.
- Subscription cancellation and reactivation are permission-gated and confirmed. Reactivation explicitly warns that an expired period may start a new paid period and debit credit. Existing billing service behavior remains unchanged.
- Backend contract note: workspace status changes and subscription reactivation now require `confirm_slug`, matching the confirmation pattern already used by cancellation and deletion. Existing callers must supply the tenant slug. Deletion and creation fallbacks return to the directory rather than the dashboard.
- Tenant-view-only users no longer receive balance/package details in the overview. Creation, lifecycle mutations, and private subscription information retain separate permission checks.
- Verification: 12 focused administration/lifecycle tests passed with 87 assertions, followed by complete navigation and subscription-billing suites: 31 tests / 308 assertions (overlapping coverage, not additive unique totals). Build, Blade cache, PHP lint, formatting, and diff checks passed. Browser wizard checks covered empty-field blocking, step progression, review values, password exclusion, and slug pattern validation; Arabic dark mobile width remained 390px. Static preview has application-module cross-origin limits; real authenticated provisioning and destructive operations were not exercised.
- Deletion success was tested only with resource cleanup mocked and an isolated test database record. No real tenant database/files, invoices, server, migrations, commit, or push were changed. Packages & Features redesign is Sprint 4.

## Sprint 4 implementation checkpoint

- Packages now supports server-side search, assignment-status/public-visibility filters, 12-row pagination, and expandable individual feature lists. Lists include effective dependencies rather than only explicit feature selections. Package names/codes and backend catalog definitions are unchanged.
- `/platform/packages/features` is a permission-protected, read-only catalog of individual capabilities, grouped by purpose and searchable by localized name or stable code. The catalog exposes required and alternative prerequisites; it does not create arbitrary runtime modules.
- A shared bilingual picker serves package editing and tenant extras. It distinguishes explicit choices, inherited package locks, automatic dependencies, unavailable features, and server-reported current enablement. Search hides presentation only: selected hidden inputs remain submitted. Automatically required features are not silently rewritten into explicit selections.
- Package editing has section navigation, clearer policy labels, and server-verified impact presentation. Tenant conflicts calculated by the existing preview now appear in the UI and block saving incompatible shared-package changes. Changing feature selection clears visible confirmation and marks the impact preview stale; backend signatures still enforce preview/confirmation.
- Tenant Features has configured-access and enabled-now summaries. Missing/current subscription and operational-state requirements are explained. Access conflicts remain readable even when package dependency normalization fails; editing controls are withheld until those conflicts are reviewed.
- Tenant preview display is scoped by tenant ID and expected access version. Existing stale-version rejection, additive extras, inherited-feature locks, assignment/public flags, and preview confirmation remain in force.
- Verification: focused package/navigation/module-engine regressions passed 27 tests / 179 assertions. Vite build, Blade cache, PHP lint, formatting, and diff checks passed. Playwright checked dependency display after unselecting a prerequisite, stale preview indication, preservation of filtered selections in FormData, and English desktop / Arabic dark mobile previews; both catalog and tenant Features fit the 390px viewport. Static preview has cross-origin application-module limits; no live authenticated package/extra writes were performed.
- No migrations, real package reassignment, tenant-extra changes, invoice changes, commit/push, or deployment. Requests workspace is Sprint 5.

## Sprint 5 implementation checkpoint

- Requests now has separate Problems and Feature requests queues, server-side search/status/tenant/group filters, 20-row pagination, and addressable detail pages. The queue no longer embeds reply forms for every case.
- Each case detail exposes context, the appropriate type-specific status choices, a tenant-administrator-visible response, evidence links for problems, and the latest 20 case-specific audit events. The response help explicitly describes replacement semantics, not private notes or a chat thread. Existing forwarding, update, and attachment contracts remain unchanged.
- Shared feature-group creation moved to its own page with related-request counts and links. A read-only status board groups the current paginated feature-request result page; its limited scope is clearly stated and it does not offer unsupported drag/drop writes.
- Website enquiries have a searchable, paginated, read-only inbox and contact/detail view. Access uses manage.landing-page; support-only and publish-only roles do not receive lead contact data. Website managers without support permissions are directed to the enquiry inbox from Requests. No enquiry statuses, email sending, assignment, or conversation history were invented, and no new schema is needed.
- Navigation, detail pages, groups, and direct URLs enforce problem/suggestion/website access independently. Group counts include suggestion cases only. Problem attachments retain their existing tenant-scoped authorization and secure download handler.
- Verification: request workspace plus forwarding/settings/group/attachment suites passed 13 tests / 94 assertions; navigation regressions passed 25 tests / 286 assertions. Build, Blade cache, PHP lint, formatting, and diff checks passed. English desktop detail and Arabic/mobile detail, status-board, and enquiry previews were inspected; each mobile page's document width matched the 390px viewport. Static previews have application-module cross-origin limits; authenticated browser response submissions were not exercised.
- No live responses, emails, request mutations, tenant/invoice edits, migrations, commit/push, or deployment were performed. Billing, Subscriptions, and Vouchers are Sprint 6.

## Sprint 6 implementation checkpoint

- Billing now has connected Subscriptions, Payments, and Vouchers destinations. The subscription queue supports search, state, upcoming expiry, and 20-row pagination; each row links to the existing tenant subscription workflow.
- Payments is a read-only, paginated offline-payment ledger with tenant/search/payment-date filters, filtered collected totals, and eligible receipt view/download links. Charges are excluded from collected totals; collection is not presented as accounting revenue. Date-only upper bounds are supported and reversed ranges rejected.
- Voucher discovery supports search, enabled/disabled filtering, and 12-card pagination. Cards distinguish disabled, scheduled, expired, exhausted, and active states while showing scope, validity, discount periods, and recorded redemption counts. Existing eligibility and immutable redemption rules remain authoritative.
- Voucher creation is a permission-gated expandable form. Conditional controls enable and require only the relevant tenant, total-use, or discounted-period fields; percentage discounts have the existing maximum of 100. Existing backend field names, enum values, activation endpoints, and validation rules are retained.
- Tenant Billing retains entered payment values after validation errors, paginates ledger entries, and exposes receipt links. Subscription estimates are explicitly labelled as previews subject to server verification. Billing-only users can navigate financial workflows without receiving tenant-management links or editing controls.
- Verification: billing workspace and existing subscription-billing suites passed 17 tests / 120 assertions; navigation tests passed 25 tests / 286 assertions. Production build, Blade cache, PHP lint, formatting, and diff checks passed. English desktop and Arabic dark mobile previews were inspected; subscription, payment, and voucher pages fit a 390px viewport. Native voucher controls were checked for conditional required/disabled states without submitting the form.
- Static previews retain application-module cross-origin limitations; authenticated browser payment and voucher submissions were not exercised. Persistence and permission behavior were checked separately in isolated test databases. No real payments, voucher mutations, invoice edits, migrations, server deployment, commit, or push were performed. Operations, Content, and Access are Sprint 7.

## Sprint 7A implementation checkpoint

- Operations has permission-filtered Metrics, Storage, and Backups local navigation. Metrics separates current health from saved history with section links and explains the independent history-range and snapshot/cache behavior. The existing metrics collection/history services and owner-only Settings policy remain unchanged.
- Backups has search, tenant, state, and trigger filters with retained query parameters and 20-row history pagination. Health counts explicitly follow tenant/search across all dates, independently of the state/trigger history filters. Completed and verified are distinguished rather than treating every completed record as verified.
- Creation and restore controls reflect their separate permissions. A backup viewer receives downloads but no restore or creation forms. Restore discloses the exact tenant, archive date, destructive scope, and existing service's safety-backup behavior. Typed slug confirmation is checked by native browser validation and the existing server rule; the browser pattern was verified with wrong and exact addresses. Busy feedback prevents duplicate form submissions and resets on browser history return.
- Backup creation and restore expose the existing active/trial plus provisioned-database requirement before invoking the coordinator. Suspended/unprovisioned tenants cannot create the mandatory safety archive; the UI withholds those restore controls, and direct requests return validation feedback. Unusable archives are rejected before invoking restore. No backup-service retention, storage-limit, archive-format, encryption, or tenant-isolation rules are changed.
- Storage comparisons include other files as a separate category, storage limits, at/over-limit and unavailable filters, search, and 20-row pagination after descending usage sorting. Failed scans remain unavailable, not zero, and cannot prevent other rows from rendering. Known totals, available scans, and limits follow the search across all pages; attention filters narrow the table only. This is an on-visit scan, not a claim of background collection or cached per-tenant usage.
- Access is split into the user directory, addressable user management, owner-only Roles, and addressable role review. Native expandable creation forms replace modal-dependent entry. User cards support mobile; account details, role assignment, password reset, and status controls have separate sections. Role deletion states the affected-user count and requires a browser confirmation; the existing last-owner and protected Owner-role checks remain authoritative.
- Permission choices are grouped by operation, bilingual display labels preserve backend codes, and legacy support/role-permission limitations are explained. Non-owner user managers cannot reach role design or submit assignments. Validation retains non-secret input, including an empty permission selection, and never replays passwords into forms.
- Verification: the updated Operations/Access workspace and existing Access suite passed 16 tests / 127 assertions. Metrics/history services also passed in the preceding combined run of 21 tests / 155 assertions, before one additional role-editor test was added. Existing metrics page/settings checks passed 3 tests / 43 assertions, and shared navigation passed 25 tests / 286 assertions. Coverage overlaps between these runs; totals are not additive. Production build, Blade cache, PHP lint, formatting, and diff checks passed.
- Playwright inspected English desktop and Arabic dark mobile previews. User directory/detail, roles/detail, Metrics, Storage, and backup confirmation fit the 390px document viewport. Native creation disclosures and wrong/exact restore confirmation were checked without submitting. Static previews retain application-module cross-origin limits; authenticated browser restore/account mutations were not exercised. Restore success was tested only with the coordinator mocked in an isolated database.
- No real archives, restored tenant data, passwords, account roles, invoice data, migrations, commit/push, or server deployment were changed. The remaining next step is Sprint 7B: Website draft/publish/history and Report Library definition/revision workflows, followed by Sprint 8 whole-application verification.

## Sprint 7B implementation checkpoint

- Website studio now separates Draft, Publishing, and Published history using addressable view parameters on the existing editor route. Bilingual fields are grouped into navigable prepared sections; disabled sections and image replacements retain the existing field names and content normalization. Unsaved-change feedback and navigation warnings distinguish browser edits from saved drafts, while invalid controls reveal their collapsed section before native validation focuses them.
- Publish-only roles can inspect a read-only saved draft, perform the same text-readiness checks as the publishing handler, and use protected previews without receiving draft-edit controls. Enquiry records were removed from the editor: only website managers receive a link to the separately authorized enquiry inbox. This closes the old publish-only editor contact-data exposure without changing enquiry storage or follow-up behavior.
- `/platform/landing-page/preview` renders the existing public design with the saved draft, an explicit private-preview banner, no-store/noindex response headers, and disabled enquiry controls. English and Arabic links remain on the protected preview route. A page-scoped revision parameter previews an older immutable content snapshot. The public landing still reads only its published revision; previews show current public packages and do not promise historical package snapshots or include unsaved browser edits.
- Publishing has a saved/public state summary, shared server-generated bilingual readiness checks, and explicit confirmation. Prepared text requirements remain unchanged, including disabled sections and the 1,500-character limit. History is paginated, shows source revision/date/publisher, offers language-specific protected previews, and warns that restore replaces the draft and immediately publishes a new revision. Existing publish and restore POST routes and permissions remain compatible.
- Image replacement now retains an archive asset when any published website revision references it. Exact stored paths are compared rather than searching JSON-escaped text; an unused draft-only image can still be removed by the existing cleanup behavior. This prevents new replacements from breaking historical previews/restores; it does not recover files already missing before this change. Regression coverage uses an isolated fake public disk only.
- Report Library has bilingual name search, kind/publication/origin filters, 12-card pagination, and visible unpublished-draft changes. Definition editing is reorganized into Source, Names/Compatibility, Fields, Calculations, Related rows, and Presentation/Sorting. Field search hides presentation only; checked hidden values remain in submitted FormData. Source reload warnings explain discarded unsaved choices, and capability display keeps backend codes unchanged.
- Definition, Publishing, and History are separate report-item views. Publishing reviews the saved definition and calculated capability requirements, not browser edits, and explains that installed tenant copies are not overwritten. Read-only revision URLs enforce item ownership, expose a human-readable summary plus an escaped complete definition reference, and offer no invented restore/installation action. Report snapshots remain immutable; built-in system items retain their existing write/publish prohibition.
- A publish-only report user cannot switch the reviewed definition to an unrelated source using the query string. Read-only forms have no draft mutation action. Validation retains empty field/calculation selections instead of restoring default checks. The redesigned form sends `workspace_form=report_definition` so intentionally selecting no calculations is respected; legacy callers without this marker retain the existing default-count behavior. Five-calculation limits and the existing safe-source/field/relationship/presentation validation remain authoritative.
- Verification: existing Website/Report Library suites passed 13 tests / 95 assertions; new content workspace coverage passed 10 tests / 160 assertions; navigation and tenant report-installation suites passed 30 tests / 396 assertions. New coverage reviews all 15 built-in templates and their published definitions in Arabic, publisher/editor boundaries, private preview headers/languages, unpublished state, revision scope, image retention/restore, and validation-state recovery. Build, Blade compilation, PHP lint, Pint, and diff checks passed.
- Playwright inspected English desktop and Arabic/mobile editor, publishing, history, library, creation, and revision previews. Measured mobile document widths matched the 390px viewport. Browser checks verified field-search selection retention, invalid collapsed-section reveal, and disabled preview enquiry controls without submitting. Static previews retain application-module cross-origin limits; authenticated browser save/publish/restore submissions were not exercised. Persistence and publication were checked separately in isolated test databases.
- No live website content, report publications, tenant-installed copies, enquiry records, real images, invoices, migrations, commit/push, or server deployment were changed. Sprint 7 is now complete; Sprint 8 is whole-application verification and handoff.

## Sprint 8 implementation checkpoint

- Completed cross-workspace verification and the [deployment handoff](platform-management-redesign-handoff.md). The eight-sprint UI delivery is complete locally; this is not a production-readiness or deployment declaration.
- Added integration coverage for 39 GET destinations across 14 representative permission profiles. Login, already-authenticated login, and required-password-change completion now land on an authorized workspace rather than assuming Overview access. Storage-only users receive a Storage back link, and read-only Organisation controls cannot submit changes. Organisation learning-path labels use the existing translations.
- Targeted regression run: 158 tests / 1,355 assertions passed. Post-fix access/navigation/administration/integration run: 63 tests / 1,105 assertions passed (overlaps the earlier run). Final complete integration class: five tests / 585 assertions passed. These are targeted suites, not a claim that the entire repository suite ran.
- Browser fixtures covered 45 screens across English/Arabic, light/dark, and mobile/desktop: 360 checks, with no detected document overflow, untranslated Platform keys, or runtime exceptions. The corrected same-origin preview harness loads the actual Livewire and Flux Lite assets; mobile navigation opening and Escape dismissal were verified. These were read-only fixtures, not live authenticated save, payment, provisioning, publication, or restore submissions.
- Production asset build, Blade view compilation, focused formatting, and diff checks passed. No migration or dependency lockfile changes were introduced.
- Release gates remain separate: the locked audit reports two league/commonmark advisories, and local read-only rehearsal reports demo legacy-invoice/package-conversion findings and unprovisioned records. No invoice ownership decisions, cleanup, conversion, dependency upgrades, or gate suppression were performed. Recheck the actual target before release.
- All redesign changes remain local and uncommitted. No push, server deployment, production mutation, or migration application was performed. The handoff records publication status, verification limits, compatibility contracts, server steps, and rollback guidance.

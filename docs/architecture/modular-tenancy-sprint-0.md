# Modular tenancy: Sprint 0 plan

Date: 2026-09-20. Branch: `modules-and-tenant-feature`.

Status: planning baseline complete; M1-M3 are implemented in the working tree. See `modular-tenancy-m1.md`, `modular-tenancy-m2.md`, and `modular-tenancy-m3.md`. This document supersedes the fixed Core/Finance/Custom Printing packaging assumptions in `saas-multitenancy-sprint-0.md`, not its database-per-tenant, subdomain, manual subscription, or direct Linux/CloudPanel hosting decisions. No Docker dependency is introduced.

## 1. Confirmed product decisions

- Platform Admin manages packages and assigns one package to each tenant. Packages have create/edit screens and a card grid.
- Tenant-specific extras are additive only. They cannot subtract package modules. An explicitly assigned extra can be revoked, but a module still supplied by the package remains available.
- Package edits affect all assigned tenants. Show affected tenants and dependency changes before confirmation. Preserve explicitly assigned extras across package changes.
- Keep the same schema in all tenant databases. Disabling a module blocks access and execution, not storage; preserve data, links, and settings.
- Foundation includes authentication, accounts, roles, tenant identity, essential settings and operational infrastructure. Business modules are selectable.
- Parents and Parent Portal are separate. Students can exist without parents. Parent Portal requires Parents and only exposes permitted child data from available modules.
- Courses, Groups and Enrollment form one module requiring Students and Teachers. Groups can be prepared and students enrolled before assigning a teacher. Teacher-led work requires an identified teacher; administrators who teach need a teacher profile.
- Student Attendance and Teacher Attendance are separate. Student Attendance includes center attendance and, when Classes is available, group attendance.
- Memorization, Quran Tests and General Assessments are separate. Quran testing does not require memorization history. Optional progress eligibility applies only when Memorization is available; authorized exceptions need a reason. Tests must not fabricate memorization sessions.
- Points and Rewards is optional. Tenant-configured rules award automatic points; authorized users can award manual points without Memorization or Assessments. Record actor/reason/date. Corrections are traceable, retries must not duplicate awards, new rules apply prospectively, and historical recalculation is a separate previewed operation.
- Activities are for registered students only. Group linkage, financial operations, points, and parent responses are conditional integrations.
- Income and Expenses and Student Billing are separate. Student Billing requires Students and Income and Expenses. New student invoices belong to one student, not a family. Parent Portal may display authorized children's invoices.
- Custom Templates and ID Cards are separate. Standard document printing/exports belong to their source module. Standard ID layouts do not require Custom Templates; custom ID layouts require both.
- Standard reports and dashboards belong to the modules supplying their data. No separate standard Reports entitlement.
- Curricula are tenant-owned only; no shared platform curriculum catalog.
- Public Website is optional. Without it the tenant subdomain shows its branded login page. No shared public landing-page module. Website content is preserved when disabled; Parent Portal is independent.
- Notifications follow their owning modules and tenant delivery preferences. External SMS/WhatsApp integration is deferred; this plan does not assume an existing notification delivery implementation.
- First-login onboarding and targeted new-module setup are planned. Save progress per tenant/module/version; skip optional steps; preserve settings on reactivation. Only authorized settings administrators and the local Platform Admin account can configure modules. Existing ready modules remain usable.

## 2. Proposed catalog and dependency graph

Codes and the technical defaults below are implementation proposals, not existing feature codes. All modules implicitly depend on Foundation. Dependencies form a directed acyclic graph and are resolved transitively.

| Code | Module and ownership | Required modules | Optional integrations |
| --- | --- | --- | --- |
| `students` | Student profiles, files, notes, basic progress shell | None | Parents; module-specific progress sections |
| `parents` | Family contact records and student links | Students | Parent Portal |
| `parent_portal` | Parent login access and child-facing web/API views | Parents | Attendance, learning, points, billing, activities |
| `teachers` | Teacher profiles and assignments | None | Attendance, classes, financial requests |
| `classes` | Courses, groups, schedules, enrollments, course lifecycle | Students, Teachers | Curriculum, attendance, learning, billing, points |
| `student_attendance` | Center attendance/scanning and conditional group attendance | Students | Classes, points, parent portal |
| `teacher_attendance` | Teacher attendance and exclusions | Teachers | Class schedules |
| `memorization` | Sessions and memorization progress | Classes (technical default) | Points, curriculum, parent portal |
| `quran_tests` | Quick entry, partial, final and other Quran test workflows | Classes (technical default) | Memorization eligibility, points, parent portal |
| `assessments` | General assessments and results | Classes (technical default) | Points, parent portal |
| `points_rewards` | Manual awards, automatic rules, ledger, reward workflows | Students (proposed; see decision D1) | Classes and source modules; finance for actual spending |
| `activities` | Events and registered-student participation | Students | Classes, finance, points, parent portal |
| `finance` | Income, expenses, cash boxes, exchange, requests, financial reports and expense documents | None | Teachers, activity payments/expenses, reward expenditure |
| `student_billing` | Student invoices, fees, payments and receipts | Students, Finance | Classes fee sources, parent portal |
| `curriculum` | Tenant curriculum content and group progress | Classes | Memorization, assessments |
| `custom_templates` | Layout designer, field registry and rendering | None | Only enabled data sources; ID Cards for card customization |
| `id_cards` | Standard card generation/printing | At least one supported subject module (conditional) | Students, Teachers, Parents; Custom Templates |
| `public_website` | Tenant-managed public pages/content/navigation | None | Explicitly published module content only |

Technical defaults: student notes/files stay in Students; grade levels, years and reference lists are available through their owning workflows rather than separate paid modules. Community contacts stay a foundation utility initially. Backups, audit and data-quality tools remain operational infrastructure, with existing permissions and module-aware domain actions/results. Scanner actions require the module for the requested action, not an ID Cards purchase. Course completion stays in Classes; progress/points/finance substeps require their respective modules.

Classes as a prerequisite for Quran Tests does not require prior Memorization. Existing test workflows are enrollment-based; this plan does not promise testing without enrollment. Review this default only if a real standalone-testing workflow is requested. ID Cards must validate supported subject types rather than enabling every people module; enumerate actual supported card subjects during its sprint.

## 3. Evidence and implementation impact

Paths below are repository-relative and were inspected for this planning pass. This is a module-level inventory, not a claim that every caller has already been audited. Each implementation sprint must enumerate and classify its routes, Livewire actions, services, exports and tests before declaring coverage complete.

| Current evidence | Impact and planned work |
| --- | --- |
| `app/Services/Landlord/TenantFeatureAccess.php` returns true for active core features and lets positive/negative overrides precede subscription checks | Replace with a central effective-module resolver. Enforce tenant/subscription lifecycle before extras; remove negative override semantics for new assignments. Preserve existing grants through an explicit conversion report. |
| `database/seeders/LandlordCatalogSeeder.php` recreates fixed plans and calls `sync()` on feature membership | Separate immutable feature registration from editable package content. Repeated seeds/deployments must not overwrite administrator packages. |
| `app/Http/Controllers/Platform/PlanManagementController.php`, `resources/views/platform/plans/index.blade.php` edit existing packages only | Add grid/create/edit, validation, dependency previews, tenant extras and audit events; distinguish inherited, explicit extra and required dependency sources. |
| `routes/web.php`, `app/Services/SidebarNavigationService.php` primarily gate Finance/Custom Printing | Replace scattered mappings with explicit module ownership. Include tabs, home widgets, search, settings and exports; preserve existing record scopes and permissions. |
| `resources/views/livewire/settings/access-control.blade.php` loads all permissions and syncs selections/cloned roles | Filter displayed permissions and validate submitted permissions/clones. Existing grants cannot bypass disabled modules. Preserve stored grants for reactivation but do not expose them as assignable while disabled. |
| `app/Providers/AppServiceProvider.php` grants super_admin a `Gate::before` bypass | Module entitlement checks must be independent of role bypass. Local Platform Admin support can administer setup but does not implicitly activate unsubscribed modules. |
| `2026_05_31_010000_make_students_parent_id_nullable.php` and student web form already allow null parent; `WriteRecordsController.php` still requires one | Align API/web validation, serialization and parent shortcuts. Preserve an existing hidden parent link on unrelated student edits. Audit name formatting, numbering, reports and import behavior for parent assumptions. |
| Group web form and `WriteRecordsController.php` require `teacher_id`; initial people/learning migration makes it non-null | Add a new migration if current schema still requires it, align API/web validation, and guard teacher-led operations. Check summaries, scope filters, notifications and model hooks for null teacher assumptions. |
| `Invoice.php` has parent ownership; invoice items carry optional student IDs; `FinanceService.php` also uses invoices for expense documents | Introduce student ownership for student billing only. Do not require students on supplier/expense documents. Update payment, print, reporting and parent access paths. Historical mixed-student invoices require explicit classification, not guessing. |
| `PointLedgerService.php` manual/automatic methods require Enrollment; `PointTransaction.php` effective totals depend on active course enrollment | Points independent of Classes needs student-level awards and revised effective-total rules, not just a new permission. Preserve existing course point semantics for enrollment-linked awards. See D1. |
| `OperationalFeatureSettings.php` couples memorization and saber entries; Quran test services call the point ledger and enrollment logic | Separate availability from tenant settings, make points optional, and decouple memorization eligibility from test progression requirements. Keep mandatory test-internal sequencing intact. |
| `routes/api.php` mixes records, finance, parent summaries and operational writes; activity registration lives in `FinanceWriteController` | Apply per-operation ownership, not controller-name gating. Free activity registration must work without Finance. Filter nested response fields and aggregate summaries as well as endpoints. |
| `PrintTemplates/*`, `IdCards/*`, print controllers and shared `id-cards.*` permissions | Split designer and printing permissions/access without losing standard printing; gate template data sources and field resolution. |
| `WebsiteService.php`, public routes and website settings | Select public homepage versus branded login using tenant entitlement and publication state. Guard public deep links and media/content exposure, not just the homepage. |
| `routes/console.php` schedules backup tasks; no `app/Jobs` or `app/Notifications` directory found in this pass | Audit commands/model hooks for domain side effects. Future jobs must carry tenant identity and recheck lifecycle/modules at execution. Backup scheduling is infrastructure, not a purchasable module. |

Mobile scope: Laravel API exists here. No Flutter manifest/Dart client files were found in the repository scan. Do not claim mobile-client completion from API work. Locate the actual client repository before scheduling client implementation; API contract work can proceed independently.

## 4. Access and module-change design

Effective modules = foundation + transitive dependencies of (current package modules UNION explicit tenant extras), subject to active catalog, operational tenant and current subscription policy. Setup readiness and user permission/record scope are additional checks, not substitutes for entitlement.

- Use one registry for module codes, dependencies, permission/action ownership, setup version, labels and optional integrations. Unknown modules/actions fail closed where module ownership is required; account/profile/logout remain explicitly classified foundation routes.
- Keep explicit extras separate from dependency-derived modules. Removing an extra cannot remove dependencies still needed by the package or another extra. If a package later includes an extra, retain its explicit provenance so subsequent package edits behave predictably.
- Preview changes per affected tenant, including extras. Reject invalid dependency cycles/combinations. An unavailable prerequisite must not be silently enabled; report the conflict. Deactivate packages for new assignments without silently suspending current subscribers. Delete packages only when unused.
- Save package changes and an audit event atomically in landlord storage. Invalidate tenant capability caches using a version; publish a change summary and module setup tasks. Recheck on subsequent requests and queued work. Never rely only on hidden buttons or initial Livewire mount checks.
- Plan changes do not mutate tenant data or run schema migrations. Removing modules also removes their entry points, optional fields/sections, jobs and notification actions. Previously queued actions must recheck eligibility.
- API capabilities report enabled modules, effective user actions, setup readiness and a version. Avoid exposing unrelated tenant/user information. Clients refresh at login/resume and handle stale screens with stable module-disabled/setup-required errors. Existing tokens still undergo server-side module and record-scope checks.
- Parent Portal being disabled prevents parent-only portal use, including existing sessions/tokens. A multi-role user may still access separately authorized staff functions. Never grant access to unrelated children.

## 5. Onboarding specification for later implementation

Foundation setup: prefilled name/logo, language, timezone and deliberately published contact preferences. Module steps appear only for enabled modules. Required configuration blocks only dependent actions; optional setup can be skipped. Manual points can work before automatic rules are configured.

Persist tenant-owned setup state per module and setup-schema version (not one global boolean): not started, in progress, ready; track optional skips separately. Server-derived readiness validates actual required settings. Feature removal hides setup and retains state; reactivation only asks for missing/new requirements. Avoid restarting the full wizard on package changes. Normal settings screens edit the same values as the wizard.

Detailed field lists, defaults, and required-versus-optional settings are a deliverable of the onboarding sprint; they are not yet finalized. Existing tenants need readiness inference from existing settings so rollout does not force all administrators through first-login setup.

## 6. Data and compatibility strategy

- Keep full tenant schemas. Add forward migrations; do not edit historical migrations. Creating a migration and applying it are separate steps. Landlord migrations and tenant migrations must run on their respective connections; ordinary default `migrate` is not a substitute for tenant iteration.
- Snapshot existing effective access before translating Core into business modules. Map legacy Finance to Finance + Student Billing and Custom Printing to Custom Templates + ID Cards, with prerequisites, after verifying actual historical access. Legacy Core grants the modules it formerly exposed, not merely Foundation. Do not overwrite custom package membership.
- Inventory existing overrides, especially negative ones. Preserve current effective access with an explicit reviewed conversion; do not silently turn negative overrides into new grants. This migration issue does not change the new extras-only product rule.
- Preserve configured automatic point policies for existing tenants; new tenants start automatic rules only after configuration. No retroactive point recalculation on rollout.
- Student Billing migration: infer a student only when invoice evidence is unambiguous. Report mixed-student/unlinked invoices and existing payments. Retain legacy records until a deliberate conversion policy is approved; never split amounts/payments automatically. New student invoices enforce one student consistently across items.
- Group teacher nullability needs MySQL and SQLite coverage. Rollback cannot reintroduce a non-null requirement while unassigned groups exist; prefer forward correction.
- Test migration on copies/disposable databases and compare row counts, balances, points and effective access. Production data conversion/deployment remains a later separately authorized action. Backup and restoration testing stays in release preparation.

## 7. Ordered implementation sprints

These numbers refer to the modular redesign, not the earlier SaaS tenancy sprints. Deliver focused tests during each sprint and preserve an all-modules compatibility mode until the affected paths are fully classified. Do not sell a combination whose service/API enforcement is incomplete.

| Sprint | Deliverables | Acceptance gate |
| --- | --- | --- |
| M1: Registry and entitlement engine | Module graph, additive extras, lifecycle checks, provenance/version, permission/action ownership contract, compatibility conversion dry run | Dependency closure/cycles, extras removal, subscription rules and admin bypass tested; editable packages survive seeding; legacy access comparison produced |
| M2: Students / Parents / Parent Portal vertical slice | Web/API parity, optional links, parent access controls, filtered roles, sidebar/settings/summary ownership; initial capabilities endpoint | Student creation without Parents succeeds on web/API; parent-only access fails without Portal; linked-child scoping passes; hidden links preserved |
| M3: Classes, teachers, attendance and curriculum | Teacher-null migration, preparation versus teaching rules, two attendance modules, tenant-owned curriculum and scoped reports | Unassigned group creation/enrollment succeeds; teacher-led writes require teacher; center attendance works without Classes; disabled-module direct requests fail |
| M4: Learning and points | Independent Quran testing, optional progress policy, assessments/memorization separation, points opt-in integrations and D1 outcome | Test without history succeeds; source workflows run without points; manual awards follow agreed enrollment rule; retries/corrections do not duplicate totals; policy changes do not rewrite history |
| M5: Activities and financial split | Student-only registration, finance integration, separate Student Billing, single-student invoices, parent invoice access and migration classifier | Free activity works without Finance; general finance works without Students; billing works without Parents; expense invoices remain valid; balances/payment integrity and historical blockers tested |
| M6: Printing, website and shared surfaces | Separate templates/cards, standard printing, login fallback, dashboard/report/search/export and operational-tool gating | No disabled-module data in nested screens, public routes, generated files or templates; standard print works without custom templates; existing Arabic/RTL/mobile layouts verified |
| M7: Package and extras management UI | Card grid, create/edit/duplicate/deactivate, checklist, dependencies, per-tenant extras, preview/audit and permission-aware settings | Package edit shows all affected tenants; extras affect only selected tenant; package-supplied modules cannot be removed per tenant; accessible desktop/mobile UI QA |
| M8: Onboarding and mobile completion | Initial wizard, targeted setup, readiness inference/versioning, capability refresh and client adaptation | New/returning tenant flows resume safely; ready modules remain usable; old/mobile clients cannot bypass backend gates; actual client QA contingent on repository access |
| M9: Compatibility and release rehearsal | Full conversion rehearsal, representative module combinations, MySQL/SQLite checks, deployment/rollback runbook and restore exercise | Access/data comparisons pass; no ambiguous conversions silently applied; all acceptance scenarios pass; production release remains a separate action |

M1 establishes write contracts for extras/dependencies before the polished M7 UI. M2 implements API behavior immediately; M8 is not a reason to postpone API authorization. M4 and M5 can be broken into smaller delivery tasks if domain refactoring warrants it.

## 8. Verification matrix

Exercise each applicable scenario through web routes, Livewire actions and API requests, using ordinary roles and the local platform support account. Inspect exports/aggregates as well as navigation.

1. Students only: no parent required, no parent data/actions exposed.
2. Students + Parents, no Portal: staff family management works; parent-only online access does not.
3. Portal + Attendance, no Billing: children/attendance work; invoice routes and summary fields are unavailable.
4. Classes + Teachers with unassigned group: preparation works, teacher-led recording fails clearly until assigned.
5. Quran Tests without Memorization: no history prerequisite; test-internal rules still apply.
6. Points without learning modules: manual awards work under D1 policy; disabled Points does not block source records or create awards.
7. Activities without Finance: registered-student participation works; payment/expense operations do not.
8. Finance without Students, and Billing without Parents: both supported; invoice classes remain distinguished.
9. ID Cards without Custom Templates: standard card works; designer unavailable. Templates cannot retrieve unavailable module fields.
10. Website removed: tenant root becomes login; public deep links blocked; content retained.
11. Extra granted/revoked, shared package edited, subscription suspended, existing token reused: access and setup state refresh consistently across tenants.
12. Permission assignment by crafted request/cloned role: unavailable permissions rejected; preexisting grants do not bypass entitlements.
13. Disabled source module with queued work/export: no new unauthorized action or data disclosure; tenant context always resolved explicitly.
14. Re-enable with saved data: no duplicate setup, points or invoices; new required settings alone prompt completion.

## 9. Remaining decisions and bounded uncertainties

### D1: Manual points without class enrollment (business decision)

Confirmed: manual points must work without Memorization and Assessments. Not yet explicitly decided: whether a student must still belong to a class. Current point totals and manual awards require enrollment and an active point-awarding course. Recommended: permit student-level manual awards without Classes, while retaining course-linked semantics for existing awards. This is a meaningful ledger change and needs the user's choice before M4; it does not block M1/M2.

### D2: Historical mixed-student invoices (conditional migration decision)

Inventory first. If no such records need migration, no user decision is needed. If they exist in a deployment dataset, present actual examples and a preservation/conversion proposal; do not ask abstract questions or infer payment allocation.

### Technical defaults delegated to implementation

Keep grouped learning workflows enrollment-based initially; keep notes/files in Students and community contacts in Foundation; standard reports are module-owned; no schema per package; deny roles from bypassing module entitlements; validate setup per action; preserve extras provenance. Guardian contact fields independent of Parent records are optional future student-profile refinement, not a reason to block the split.

### External dependency

Locate mobile client source before promising mobile UI implementation. No need to stop backend planning or M1 for this.

## 10. Sprint 0 handoff

Planning artifact, initial dependency map, source-impact inventory, migration risks, acceptance matrix and ordered backlog are complete. No application behavior, database, package assignments or server configuration were changed for Sprint 0. M1 can begin on user instruction; D1 should be settled before the points sprint. This document records decisions in the repository and does not update Codex memory.

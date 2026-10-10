# AlKhair SaaS System Overview

## 1. System purpose

AlKhair is a bilingual Arabic and English SaaS platform for managing Quran learning centres, mosques, schools, and similar educational organisations. It combines organisation management, students, teachers, courses, Quran learning, assessments, attendance, finance, reporting, public websites, and operational tools in one application.

The system has two connected areas:

1. **Platform management** is used by the SaaS owner and authorized Platform staff to manage tenants, packages, subscriptions, payments, vouchers, backups, the public SaaS website, and the shared Report & Widget Library.
2. **Tenant applications** are independent organisation workspaces. Each tenant has its own address, database, branding, users, permissions, settings, enabled modules, files, reports, and operational data.

## 2. SaaS and tenant architecture

### Tenant isolation

- Every tenant uses a separate tenant database.
- SaaS catalogue and management records are stored in a separate landlord database.
- Tenant requests are resolved from the request hostname, such as `demo.saas.example.com`.
- The tenant subdomain is permanent after creation.
- Renaming an organisation changes its displayed branding without changing its subdomain, database name, or database connection.
- Tenant files, backup records, cache entries, sessions, and report results are scoped to the active tenant.
- Enabled modules are controlled through the tenant's package and explicit Platform overrides.

### Tenant branding

- Each tenant has its own organisation name and optional logo.
- Organisation branding appears in the tenant application, browser title, public website, documents, and other branded areas.
- A tenant without a logo receives an initials-based fallback.
- Authorized tenant staff can update the organisation name and logo.
- Authorized Platform staff can correct tenant branding, and the change appears in the tenant application.
- A tenant administrator can select a tenant-wide primary colour. The system derives compatible interface shades and provides light and dark previews.

### Module catalogue

Packages can provide the following modules while respecting their dependencies:

- Foundation
- Students
- Parents
- Parent Portal
- Teachers
- Classes and Enrollment
- Student Attendance
- Teacher Attendance
- Memorization
- Quran Tests
- General Assessments
- Points and Rewards
- Activities
- Income and Expenses
- Student Billing
- Curriculum Management
- Custom Templates
- ID Cards
- Public Website

The interface, routes, permissions, reporting sources, and setup tasks respond to the modules enabled for the current tenant.

## 3. Platform management features

### Platform staff and permissions

- Platform administrators can create Platform roles with selected permissions.
- Platform users can be assigned one or more Platform roles.
- A new Platform user receives a temporary password created by the administrator and must change it after signing in.
- Authorized administrators can reset a Platform user's password.
- Platform permissions are defined for each management capability, including tenant management, subscriptions, payments, backups, support access, landing-page editing, and report-library management.
- Platform actions are recorded in a dedicated Platform audit history.

### Tenant provisioning and management

- Platform staff first creates the tenant organisation and its permanent subdomain.
- Tenant provisioning creates the isolated tenant database, runs the tenant schema, prepares the tenant application, and creates the first tenant administrator.
- Provisioning uses rollback and cleanup safeguards when creation fails.
- The creation interface displays progress while long-running tenant setup is taking place.
- Package selection, voucher assignment, subscription activation, and payment processing are handled after tenant creation.
- The tenant management workspace separates organisation information, package and subscription details, payments, balance, storage, modules, backups, and history into focused pages.
- Platform staff can view the complete tenant address before creating the tenant.

### Packages and modules

- Platform staff can create and manage packages.
- Packages define their price, billing period, storage allowance, and included modules.
- Module dependencies are validated automatically.
- Optional extra modules can be enabled for a specific tenant.
- Package changes and tenant module changes are audited.

### Subscription lifecycle

- A tenant subscription records its package, start date, end date, operational status, and billing history.
- Supported states include trial, active, grace period, suspended, cancelled, and scheduled cancellation.
- Trial tenants use the standard tenant application with a shorter subscription period.
- Tenant administrators receive expiry and grace-period warnings.
- Other tenant users receive a smaller warning close to suspension.
- Suspension blocks normal tenant operation through a clear tenant-facing page.
- Cancellation can take effect at the end of the paid period.
- Reactivation and final tenant deletion are explicit, permission-controlled, and audited operations.

### Payments, balance, and vouchers

- The Platform uses Syrian pound (SYP) as its subscription billing currency.
- Offline payments support cash, bank transfer, cheque, and other manually recorded methods.
- Payment records include amount, paid date, reference, receipt number, note, and the Platform user who recorded the payment.
- Payments add immutable prepaid credit to the tenant's balance.
- Activation and renewal consume available credit using first-in, first-out allocation.
- Renewal can occur automatically when the balance covers the required charge.
- Every charge preserves a snapshot of the package price, discount, final amount, and allocated credits.
- Vouchers can provide fixed-SYP or percentage discounts.
- Voucher controls include validity dates, usage limits, tenant restriction, and first-period, limited-period, or recurring application.
- One voucher can apply to each subscription charge.
- Refund processing and online payment gateways are outside the current version.

### Secure Platform access to tenants

- Authorized Platform staff can open a tenant application through a short-lived, signed, single-use handoff.
- Support access does not create a visible Platform user inside the tenant's user list.
- Access can be limited to read, edit, or delete capability according to Platform permission.
- The tenant audit records that Platform management performed a change without exposing the individual Platform employee to tenant users.
- The Platform audit records the exact Platform user, tenant, date, time, and access event.

### Public SaaS website

- The base SaaS domain provides a responsive Arabic and English marketing website.
- Visitors can switch language, review features, see active packages, and submit contact or demo enquiries.
- Authorized Platform users edit prepared bilingual sections, text, visibility, ordering, calls to action, and product screenshots.
- Landing-page work is saved as a draft and published separately.
- Published revisions can be reviewed and restored.

### Platform support and suggestions

- Forwarded tenant problems and suggestions appear in separate Platform work areas.
- Platform roles have separate permissions for viewing and managing support problems or product suggestions.
- Counters show requests that need attention without adding a notification system.
- Platform staff can track the current status and exchange messages with the tenant administrator.

## 4. Tenant user and access management

- Tenant users are stored inside the tenant database.
- Roles and permissions control navigation and every protected action.
- Permissions are separated into view, create, update, delete, record, approve, export, manage, and other domain-specific capabilities.
- Access scopes can restrict a user to selected students, groups, teachers, courses, cash boxes, or other allowed records.
- Tenant administrators can create users and roles, assign permissions, activate or deactivate accounts, and reset passwords.
- Temporary-password users remain on the password-change flow until the new password is committed successfully.
- Purpose-built dashboards are available for administrators, managers, teachers, group teachers, parents, and students.

## 5. Tenant setup and settings

- A guided setup workspace explains required and optional tenant configuration.
- The application blocks only actions that need missing required settings.
- Staff, students, courses, and groups continue to use their normal management pages instead of being duplicated inside the setup wizard.
- Setup status is tracked per enabled module.
- When a module's setup version changes, only that module requires review.
- Tenant settings cover organisation branding, theme, permissions, navigation, points, finance, curriculum subjects, learning progression, backups, storage, printing, and public website content.

## 6. People and academic management

### Students and parents

- Create and manage student profiles, status, grade level, contact information, photo, files, notes, and parent relationships.
- Export student and parent data.
- Manage student enrolments and group membership.
- Track a student's learning, attendance, tests, assessments, points, billing, and progression from connected pages.
- Parent accounts can access only linked children and approved information through the Parent Portal and API.

### Teachers and staff

- Create and manage teacher profiles, status, job title, attendance, schedules, and group assignments.
- Support primary and assistant teacher relationships.
- Review teacher signup requests.
- Restrict teachers to their assigned groups and records through permissions and access scopes.
- Export teacher information and attendance documents.

### Academic years, courses, groups, and enrolments

- Configure academic years, grade levels, courses, groups, capacities, schedules, teachers, and assistant teachers.
- Enrol students into groups and maintain enrolment status and history.
- Generate group rosters and course calendars.
- Complete a course with student exports, Quran final-test documents, report cards, and optional point-market processing.

## 7. Attendance

- Record centre-wide or group-based student attendance.
- Use standard and quick-entry attendance screens.
- Configure attendance statuses and distinguish attendance state from presence outcome.
- Record teacher attendance, exclusions, and attendance days.
- Apply permissions for viewing, taking attendance, and changing day status.
- Export student and teacher attendance as printable documents.

## 8. Curriculum and learning progression

### Curriculum management

- Define curricula, subjects, lessons, topics, and downloadable resources.
- Assign curriculum work to groups.
- Track lesson and topic completion for each group.
- Allow approved group-specific custom lessons.
- Display curriculum completion and progress summaries.

### Quran learning

- Record memorization sessions and page-level achievement.
- Support partial Quran tests, final Quran tests, and Awqaf/general test attempts.
- Keep the existing test-entry workflow while allowing each tenant to configure which Quran stages it uses.
- Configure whether partial-test completion is required before a final test and whether final-test completion is required before an Awqaf test.
- Reject disabled or invalid test stages and enforce the configured progression prerequisites.

### Lesson-and-level progression

- A tenant can use one configurable lesson-and-level progression profile.
- Authorized tenant staff configures ordered levels with required lessons, delivery groups, attendance threshold, final assessment, and passing score.
- A student starts at the first level and cannot skip a level.
- Students can repeat evidence where permitted.
- Promotion occurs automatically when lesson, attendance, and assessment rules pass.
- Authorized staff can promote manually with a required reason.
- The system records immutable evidence and a complete progression history for automatic and manual transitions.

## 9. Assessments, results, points, and activities

### Assessments

- Configure assessment types, assessments, assigned groups, schedule, total mark, pass mark, and score bands.
- Record student results and multiple attempts.
- Apply result permissions separately from assessment-definition permissions.
- Produce printable assessment results.

### Points and rewards

- Configure point types and point policies.
- Award points automatically or manually according to permission.
- Maintain a point transaction ledger and support voiding controlled entries.
- Use points in course-end point-market workflows.

### Activities

- Create and manage activities.
- Record registrations, participant responses, payments, and expenses.
- Provide a family-facing activity area where permitted.
- Connect activity finances to the Finance module when it is enabled.

## 10. Finance and student billing

- Configure currencies, exchange rates, categories, funds, cash boxes, and invoice kinds.
- Record income and expense transactions with an auditable ledger.
- Assign users to cash boxes and scope their access.
- Transfer funds between cash boxes and record controlled adjustments.
- Create, review, print, and track pull, expense, and revenue requests.
- Create student invoices, invoice items, payments, and printable receipts.
- Generate finance reports and export the finance ledger.
- Manage finance report templates.
- Capture invoice data from uploaded documents through the configured invoice-capture engine.

## 11. Report & Widget Designer

The Report & Widget Designer lets authorized tenant staff build reports without writing SQL or seeing physical database details.

### Available report sources

- Students
- Courses
- Groups
- Student attendance
- Memorization sessions
- Quran test workflows
- Assessments
- Assessment results
- Teachers and workload
- Finance transactions when Finance is enabled and the viewer has finance-report permission

### Report design capabilities

- Select approved fields and registered business relationships.
- Choose whether an approved one-to-many relationship is summarized or produces detailed rows where supported.
- Build permanent nested AND/OR conditions with field-appropriate operators.
- Apply search, status, date, and viewer-selected filters.
- Sort and group results by approved dimensions.
- Add up to five safe calculations, including count, total, average, minimum, maximum, absolute totals, and application-defined percentages where supported.
- Preview results with tenant, module, permission, and record-scope enforcement.
- Display grouped tables, bar charts, doughnut charts, and compatible specialized visuals.
- Save reports as drafts and maintain immutable version history.
- Restore a compatible earlier report version without overwriting history.
- Export saved reports to Excel and PDF.
- Use the same definition for the full report and dashboard widget so their values remain consistent.

### Query guidance and safety

- The designer explains the query in business language through a sentence, visual flow, badges, and structured outline.
- Every approved field has a bilingual label, data type, and explanation.
- Guidance warns about broad date ranges, wide outputs, incomplete calculations, unsuitable chart choices, and finance calculations that may mix currencies.
- Report queries have row limits, export limits, and a configurable execution timeout.
- Dashboard results use short-lived caches isolated by tenant, user, role, permission, scope, and report version.
- Disabled modules or unavailable fields pause affected reports safely while preserving their definitions.
- Report changes, role placement, layout changes, and successful exports are audited.

### Role dashboards and visibility

- A new report remains a private draft for its creator and dashboard-layout managers.
- Authorized staff can place a report on selected internal staff-role dashboards.
- Each role layout controls widget order and small, medium, or wide size.
- Placement makes the report available to that role on its dashboard and Custom Reports page.
- Removing the final placement returns the report to draft visibility.
- A role preview can test the report as an active representative user while preserving that user's data scope.
- Student and parent dashboards remain purpose-built around their own records.

### Report & Widget Library

- Platform users with separate manage and publish permissions create bilingual, data-free templates.
- Publishing creates an immutable numbered revision.
- Tenant users with installation permission browse compatible published templates.
- Installing creates an independent, editable tenant copy.
- Templates never contain another tenant's data, users, files, or database access.
- The built-in library includes student, attendance, Quran, assessment, teacher, curriculum, and finance reports and widgets.
- Specialized templates include line trends, lollipop charts, treemaps, curriculum hotbars, performance maps, leaderboards, rankings, and finance charts.

## 12. Printing, documents, and barcode tools

- Create custom print templates and preview generated output.
- Generate PDF documents with tenant branding.
- Configure page sizes and reusable document layouts.
- Print student ID cards from standard or custom templates.
- Produce report cards, invoices, receipts, rosters, calendars, attendance records, and assessment documents.
- Configure barcode actions and import barcode scan events.

## 13. Public tenant website

- Each tenant can operate a public website under its tenant domain.
- Authorized tenant staff manage bilingual pages, page content, menus, navigation, ordering, and visibility.
- The website uses the tenant's canonical organisation name, logo, and branding.
- Public content remains separate from the private tenant management area.

## 14. Support and product feedback

The tenant application has two separate workflows:

1. **Report a problem** is used for errors, outages, or urgent operational issues. Authorized tenant users submit a problem to tenant administrators, with screenshots or common documents where allowed. A tenant administrator can resolve it internally or forward it to the Platform team.
2. **Suggest an improvement** is used for feature ideas and product feedback. Authorized tenant users submit suggestions to tenant administrators, who can review and forward selected ideas to Platform product staff.

Tenant and Platform workspaces show counters for items that need attention. Email and real-time notification delivery are outside the current version.

## 15. Backups and storage

- Platform settings control automated tenant-backup frequency, retention, and tenant self-service behavior.
- Tenant backups include the complete tenant database and matching tenant files.
- Platform users can create, download, and restore backups for authorized tenants.
- A tenant administrator can download and restore only their own tenant's official backup archives when self-service is enabled.
- Restore creates a safety backup of the tenant's current state before replacing it.
- Current tenant migrations are applied after restoration so an older compatible backup can run on the current application schema.
- Storage controls preserve at least one usable tenant backup when cleanup removes older archives.
- Storage usage pages separate database or application data, uploaded files, photos, documents, and backups.
- Tenant storage details require permission; the Platform can compare storage use across tenants.
- Importing old standalone AlKhair installations and all legacy-data edge cases remain part of the ongoing migration and restore work.

## 16. Data quality, audit, and operational safeguards

- Tenant data changes can be reviewed through a tenant audit history.
- Platform management changes use a separate landlord audit history.
- Data-quality tools identify and resolve supported consistency problems.
- Tenant creation uses transactional and compensating cleanup safeguards.
- Report execution, exports, support access, subscription charges, payments, voucher use, backups, restores, and other sensitive actions enforce dedicated permissions.
- Database migrations for tenant databases use the common tenant schema and tenant migration workflow.
- The application supports local SQLite development and MySQL production deployments.

## 17. API and integrations

- Versioned APIs use Laravel Sanctum authentication.
- Parent mobile endpoints expose only linked children and permitted information.
- Reporting endpoints provide controlled summaries.
- Postman collections and API documentation are included under `docs/`.
- A prepared n8n workflow can deliver teacher daily summaries through Telegram when configured.

## 18. Languages and user experience

- The management application supports Arabic with right-to-left layout and UK English with left-to-right layout.
- Translation-key parity is validated between Arabic and English catalogues.
- Navigation adapts to the user's role, permissions, enabled modules, and tenant setup state.
- Responsive grids, tables, forms, dashboards, and public pages support desktop and mobile use.
- The application uses tenant branding inside tenant workspaces and Platform branding inside Platform management.

## 19. Technology summary

- PHP 8.2 or newer
- Laravel 12
- Livewire 4, Volt, and Flux
- Tailwind CSS 4 and Vite 6
- Spatie roles, permissions, and activity logging
- Laravel Sanctum API authentication
- MySQL in production and SQLite for the default local environment
- mPDF and Dompdf for generated documents
- Per-tenant databases with a separate landlord database for SaaS management

## 20. Current delivery status

The core tenant application, SaaS tenant management, subscriptions, offline payment ledger, vouchers, support flows, learning progression, tenant branding, public landing page, Platform roles, and Report & Widget Designer are implemented on the SaaS feature branch.

The primary area still marked as in progress is the complete standalone-to-SaaS migration and restoration workflow for every legacy-data edge case. Standard SaaS tenant backup, download, storage accounting, and controlled restore capabilities are already present.

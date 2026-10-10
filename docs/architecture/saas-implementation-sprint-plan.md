# SaaS implementation sprint plan

## Planning rule

This plan turns the agreed product backlog into an implementation order. A sprint
is not complete until its acceptance checks pass in a tenant-aware environment.
Begin the next sprint only after the previous sprint is reviewed and deployed
safely.

Standalone AlKhair import remains deferred. It is not part of this plan.

## Sprint 1 - Tenant backup, restore, and storage safety

**Backlog:** SAAS-014, excluding standalone import.

Build the tenant backup coordinator, Platform backup settings, scheduled Laravel
job, retention cleanup, manual downloads, complete restores, safety backups,
tenant self-restore, and storage-usage pages.

The backup archive contains the tenant database plus that tenant's public and
private files. It never contains landlord data or another tenant's data.

**Acceptance gate**

- A scheduled backup creates a verified complete archive for every active tenant.
- A tenant administrator can download and restore only that tenant's archive.
- Every restore creates and verifies a safety backup before replacement.
- Restore occurs through a temporary database, current migrations, required-data
  repair, and validation before live replacement.
- The Platform storage view and permitted tenant storage view show the agreed
  breakdown and one subscription data-size limit.
- Cleanup never deletes a tenant's last verified backup before a replacement
  succeeds.

## Sprint 2 - Tenant identity, branding, and first-run setup

**Backlog:** SAAS-002, SAAS-003, SAAS-005, SAAS-011.

Make the tenant record's organisation name and canonical logo the branding source
in tenant pages, the public tenant website, documents, and Platform tenant list.
Add the optional logo flow, initials fallback, tenant-domain preview, and the
short first-login setup experience.

**Acceptance gate**

- A tenant rename or logo update is visible immediately in that tenant app and
  Platform directory without changing its subdomain or database.
- The optional logo follows the agreed upload, sanitisation, and display rules.
- A Platform user sees the complete tenant URL while creating a tenant.
- A first tenant administrator sees the welcome/profile setup flow; normal work
  remains available while setup is incomplete.

## Sprint 3 - Platform users, roles, and audit authority

**Backlog:** SAAS-006.

Implement dynamic Platform roles, permissions, user creation, temporary-password
change, resets, deactivation, protected Platform Owner safeguards, and permanent
audit history.

**Acceptance gate**

- Platform Owners create roles and assign role membership.
- The last Platform Owner cannot lose Owner access.
- A new Platform user must change the temporary password before using Platform
  features.
- Deactivated Platform users cannot log in or retain an active session.
- Role, user, password-reset, and permission actions are auditable.

## Sprint 4 - Subscription, payments, vouchers, and tenant limits

**Backlog:** SAAS-008, SAAS-009, SAAS-010.

Implement plans, subscriptions, expiry warning/grace/suspension, SYP subscription
ledger, offline payment recording, receipts, advance balance, automatic prepaid
renewal, vouchers, and the single subscription data-size limit.

**Acceptance gate**

- A subscription follows the agreed warning, seven-day grace, suspension,
  cancellation, retention, and reactivation rules.
- Offline payments add immutable SYP credit and generate PDF receipts.
- Automatic renewal charges only when sufficient credit exists and records a
  price snapshot and audit event.
- Vouchers follow their validity, redemption, recurrence, and one-voucher-per-
  charge rules.
- No refund, online payment, or standalone trial feature is added in this sprint.

## Sprint 5 - Tenant support and controlled Platform access

**Backlog:** SAAS-012, SAAS-013, SAAS-001.

Implement the tenant-internal problem and suggestion flows, tenant-administrator
forwarding, Platform case handling, attention counters, and secure one-time
Platform-to-tenant handoff sessions.

**Acceptance gate**

- Tenant problem and suggestion permissions, internal lifecycles, forwarding
  boundaries, and Platform lifecycles match the agreed rules.
- Tenant users see only their own submitted items; tenant administrators manage
  their tenant's items.
- Only forwarded items reach Platform users.
- Platform-to-tenant access uses a single-use five-minute handoff, clean URL,
  permission-based read/edit/delete scope, 60-minute support session, and audit
  history.
- No tenant-side support-session banner, notifications, or sales workflow is
  added.

## Sprint 6 - Tenant theme and public Platform landing page

**Backlog:** SAAS-004, SAAS-007.

Implement the tenant-wide primary-colour theme setting and the bilingual public
Platform landing page with controlled editable content blocks.

**Acceptance gate**

- A permitted tenant administrator selects one primary colour, previews it, and
  receives a clear readability message for unsuitable choices.
- Theme changes affect only that tenant.
- The public landing page supports Arabic RTL and English content, a language
  switcher, controlled blocks, draft/publish separation, real-product visuals,
  and the simple contact request list.
- Publishing is blocked when required Arabic or English content is missing.

## Sprint 7 - Learning progression

**Backlog:** SAAS-015.

Implement the tenant-managed single progression profile: Quran or lesson-and-
level. Preserve Quran test interaction and add rule-based eligibility, level
promotion, history, and audited exceptions.

**Acceptance gate**

- A permitted tenant user selects and configures one profile before progression
  begins; it locks after the first progression assignment.
- Quran test screens and scoring remain unchanged while configured prerequisite
  eligibility is enforced.
- Lesson levels enforce their own attendance and final-assessment rules.
- Students can repeat but cannot skip stages; permitted one-level manual
  promotions require a reason and audit entry.
- Student and parent dashboards do not expose the Report & Widget Designer.

## Sprint 8 - Report & Widget Designer and Library

**Backlog:** SAAS-016.

Implement the safe reporting catalog, tenant Report & Widget Designer,
Platform-curated Report & Widget Library, role dashboard layouts, templates,
existing chart reuse, and on-demand exports.

**Acceptance gate**

- No SQL, credentials, cross-tenant data, or technical tables are exposed.
- Existing reports and widgets become protected predefined library templates.
- Platform users publish templates; permitted tenant users add compatible
  templates to their tenant designer.
- Tenant dashboard placement, not a separate sharing selector, determines report
  visibility for internal roles.
- Student and parent dashboards remain purpose-built and restricted to their own
  data.
- Excel and PDF exports use the same filters and data permissions as the
  on-screen report.

## Deferred work

- Standalone AlKhair import and restored-data mapping.
- Online payments, refunds, and scheduled report delivery.
- Individual tenant-user theme preferences.
- Tenant-side viewing or revoking of Platform support sessions.

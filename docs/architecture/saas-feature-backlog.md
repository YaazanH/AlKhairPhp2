# SaaS feature backlog

This is the source of record for SaaS feature requests. An item is a request,
not an approved design, sprint commitment, or implementation task.

When a feature is ready to be considered, decide its scope and acceptance
criteria first. Create a sprint plan only when explicitly requested, and begin
implementation only after an explicit instruction to start.

## Status meanings

| Status | Meaning |
| --- | --- |
| Requested | Captured from a product request; no technical plan yet. |
| Clarifying | Product decisions are still needed. |
| Planned | Accepted into a named sprint plan. |
| In progress | Implementation has explicitly started. |
| Implemented | Complete in the SaaS branch and validated locally; awaiting deployment. |
| Released | Deployed and verified in production. |
| Deferred | Intentionally postponed, with a reason. |

## Priority meanings

Priorities reflect the current stage: a newly deployed SaaS platform preparing
for safe tenant onboarding. They are not a sprint plan.

| Priority | Meaning |
| --- | --- |
| P0 | Required to protect data or correct the core SaaS experience before real customer data is accepted. |
| P1 | Required before onboarding and supporting multiple real tenants. |
| P2 | Valuable product and operational improvement after the foundation is ready. |
| P3 | Advanced capability to consider after earlier demand and usage are understood. |

## Requested features

### SAAS-001 — Secure platform-to-tenant access

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P2 |
| Requested outcome | A platform administrator can open a selected tenant from the Platform dashboard without manually entering tenant credentials. |
| User-facing entry point | A tenant action such as **Open tenant** in Platform administration. |
| Security constraint | Never place a username or password in a URL. |
| Candidate approach | A short-lived, single-use, opaque signed handoff token. The tenant consumes it, creates a session for the linked platform administrator, and redirects to a clean URL. |
| Agreed handoff lifetime | The unused, one-time handoff link expires after five minutes and is consumed immediately when opened. This limit applies to the handoff only, not to the resulting support session. |
| Agreed access model | Platform-to-tenant support access is permission-based. Separate Platform permissions define whether the support session may read tenant data, make non-destructive edits, or perform destructive actions. The tenant application enforces the granted level for the full support session. |
| Agreed support-session lifetime | A resulting Platform support session ends automatically after 60 minutes, independent of the normal Platform login duration. |
| Agreed destructive boundary | **Support access: delete** applies only to destructive actions inside a tenant application. It never grants deletion of the tenant itself, its database, or backups; those remain under separate Platform tenant and backup permissions. Tenant destructive actions retain their usual confirmation and audit records. |
| Agreed support-session visibility | Do not show a visible support-session banner or End Session control inside the tenant application in the first version. Keep all support access events in Platform audit history. |
| Agreed tenant-side controls | Do not add tenant-side viewing or revoking of Platform support access in the first version. Defer those controls until there is demonstrated tenant need. |
| Required audit information | Platform administrator, tenant, time, outcome, and source IP address. |
| Implemented decisions | Platform roles grant separate read, edit, or delete support access. The Platform creates a hashed, single-use five-minute handoff and redirects to the tenant without credentials. The tenant starts an audited 60-minute session, centrally blocks actions above its granted level, and redirects to a clean URL. Tenant-side banners and revocation controls remain deferred. |

### SAAS-002 — Tenant-controlled product naming and branding source

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P0 |
| Requested outcome | A tenant sees its own organisation name throughout its dashboard and public website, instead of standalone AlKhair Mosque wording. |
| Scope notes | Product names, headings, dashboard copy, website copy, browser title, and other visible tenant-specific labels should derive from one clear source of truth. |
| Agreed source of truth | The tenant record's organisation name is the canonical name. Tenant dashboard, browser title, default public-website title, and Platform tenant directory read it. Avoid duplicate name fields that can drift apart. |
| Agreed editing behaviour | A tenant administrator may rename the organisation from tenant settings. The Platform tenant directory reflects the change immediately. |
| Agreed Platform editing behaviour | A Platform user with the future tenant-management permission may correct an organisation name or logo. The change must appear immediately in both the Platform directory and that tenant's application, using the same canonical branding record rather than a second manually maintained tenant copy. |
| Public website title | A tenant may later set an optional separate public title. Until then, it defaults to the organisation name. |
| Domain rule | The subdomain is selected during tenant creation and is immutable afterward. Renaming an organisation never changes its tenant subdomain; the subdomain remains the stable tenant URL. |
| Implementation note | Implemented on the SaaS branch: canonical tenant name, immediate tenant and Platform propagation, and immutable subdomain. Optional public title remains future scope. |

### SAAS-003 — Logo during tenant creation and platform navigation

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P1 |
| Requested outcome | A platform administrator uploads a tenant logo during tenant creation; it becomes the tenant's organisation logo and appears beside that tenant in Platform lists and navigation. |
| Scope notes | The image should be stored in the tenant's UUID-isolated storage and remain separate from landlord and other tenant files. |
| Agreed availability | The logo is optional during tenant creation. A tenant administrator may add or replace it later from tenant settings. |
| Agreed branding behaviour | One canonical tenant logo asset is used everywhere tenant branding is needed: tenant dashboard, login, public website, Platform tenant directory, and tenant-generated documents that display organisation branding. Do not keep independent copies for each screen. |
| Agreed update propagation | When either a tenant administrator or authorized Platform user changes the name or logo, the new branding appears immediately in the tenant application and Platform tenant directory. It never changes the immutable subdomain, tenant database name, connection, or existing tenant data. |
| Agreed no-logo fallback | When a tenant has no uploaded logo, show a simple initials badge derived from its organisation name. Never use the Platform logo as a tenant-logo fallback. |
| Agreed upload rules | Accept PNG, JPG, WebP, and SVG logos up to 2 MB. Sanitize SVG uploads before storage. Preserve the original image without forcing a crop and fit it safely within each display location. |
| Agreed display sizes | Show the full logo on the tenant login page, dashboard header, and public website. Use a compact thumbnail or initials badge in the Platform tenant list and other dense navigation views. |
| Agreed document behaviour | Newly generated official documents that display organisation branding, such as certificates, receipts, and reports, use the tenant's current name and logo. Documents already generated remain unchanged. |
| Candidate user experience | The New tenant form accepts an optional logo, shows a preview, and the Platform dashboard uses a small thumbnail with a safe fallback when no logo exists. |
| Product decisions still needed | Accepted formats and size limit, crop/aspect-ratio behaviour, default logo, whether each replacement needs an audit event, and which generated documents use the logo initially. |

### SAAS-004 — Tenant theme and colour-scheme customization

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P2 |
| Requested outcome | Tenant branding can use colours that match the tenant's identity, including light and dark appearance. |
| Scope notes | The theme must remain accessible and readable in both light and dark mode. It must not alter other tenants or the Platform dashboard. |
| Agreed scope | Only a tenant administrator chooses the tenant's colour theme in the first version. It applies tenant-wide. Do not add individual tenant-user theme preferences now. |
| Agreed colour model | The tenant administrator chooses one primary brand colour using a colour picker. The application derives lighter, darker, hover, and readable text colours for both light and dark appearance. |
| Agreed settings experience | The theme settings page provides a live preview before saving and a **Reset to default** action. Saving updates that tenant application immediately and never affects Platform management or another tenant. |
| Agreed readability validation | Reject a colour choice only when the application cannot produce readable text and buttons in light or dark appearance. Show a clear message explaining that the selected colour does not meet readability requirements and ask the administrator to choose a different colour or reset to the default. |
| Candidate user experience | A tenant administrator selects a controlled colour palette or brand colour, previews the result, and can reset to the SaaS default. |
| Implemented decisions | A tenant administrator selects one six-digit primary brand colour. The application derives accessible light, dark, hover, foreground, and supporting shades, previews both appearances before saving, applies the result only to the current tenant, and provides a reset-to-default action. Individual user theme colours remain deferred. |

### SAAS-005 — Guided tenant setup wizard redesign

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P1 |
| Problem to solve | The current first-run wizard does not make the next action or completion path clear to a new tenant administrator. |
| Requested outcome | A new tenant administrator understands what to do, why it matters, and how to finish setup without guessing which page to open. |
| Candidate user experience | A short ordered checklist with plain-language steps, current progress, direct action buttons, optional steps clearly marked, and a final ready-to-use state. |
| Agreed access behaviour | The tenant dashboard remains accessible while setup is incomplete. A visible checklist guides the administrator; only actions that truly require missing configuration are blocked. |
| Agreed resume behaviour | A tenant administrator can dismiss and resume the checklist. It remains visible as a compact dashboard progress card until required setup is complete. |
| Agreed setup permission | Tenant administrators manage setup. A Platform administrator using approved support access may assist. Ordinary tenant users cannot change setup. |
| Agreed initial scope | The initial required step is organisation profile: organisation name, language, and timezone. Logo is optional. Staff, students, courses, and groups are created through their normal application screens and are not wizard steps. |
| Agreed first-login experience | On the first tenant-administrator sign-in, show a simple welcome screen with one clear **Set up your organisation** action. After saving the required organisation profile, take the administrator to the normal dashboard. The compact checklist card remains available until every required setup item is complete. |
| Agreed checklist states | The compact card shows only: **Complete your organisation profile** until name, language, and timezone are saved; **Optional: add your logo** which can be skipped and completed later; and **Setup complete**, which can be dismissed permanently. |
| Agreed blocking rule | Incomplete setup does not block normal application work. Only an action that genuinely requires missing organisation details, such as creating a branded document, asks the administrator to complete the relevant profile information first. Creating staff, students, lessons, and groups remains available. |
| Product decisions still needed | Arabic/English copy and the first workflow a completed tenant should see. |

### SAAS-006 — Platform team user management and permissions

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P1 |
| Requested outcome | The platform can add and manage its own staff accounts without treating them as tenant users. |
| Agreed role model | Use separate Platform Administrator accounts with dynamic permission-based Platform roles. An authorized Platform administrator can create roles, select Platform permissions, create Platform users, and assign one or more roles to each user. |
| Permission boundary | Tenant users and tenant roles remain inside the tenant database. Platform roles and Platform permissions are separate and never grant tenant access by themselves. |
| Security boundary | Platform access must not automatically expose tenant records. Any support access to a tenant should be explicit, time-limited, audited, and controlled separately. |
| Required safeguards | Keep at least one protected Platform Owner account; prevent deletion or removal of the last Owner's access; audit Platform role, permission, and user changes. |
| Agreed first-login password flow | A Platform administrator creates a temporary password for a new Platform user. On first successful login, that user must set a new password before accessing Platform features. |
| Agreed password delivery and reset | The Platform administrator shares the temporary password manually; the system does not email it. An authorized Platform administrator may reset a Platform user's password, creating a new temporary password and requiring change at next login. |
| Agreed temporary-password lifetime | A temporary password does not expire on a timer. It remains valid until the user completes the required password change or an authorized Platform user resets it. |
| Agreed deactivation behaviour | Deactivating a Platform user immediately prevents new Platform logins and ends active sessions. Preserve the account, its audit history, and work records. Do not delete it. |
| Permission-planning rule | Define each feature's exact Platform permissions when that feature is discussed and planned. Do not pre-create speculative permissions. |
| Agreed initial permission catalog | Start with: **view tenants**, **manage tenants**, **view subscriptions**, **manage subscriptions**, **record payments**, **manage vouchers**, **manage Platform problems**, **manage Platform suggestions**, **view backups**, **restore backups**, **manage backup settings**, **view Platform storage usage**, **support access: read**, **support access: edit**, **support access: delete**, **manage landing page**, **publish landing page**, **manage report library**, **publish report library**, **manage Platform users**, **manage Platform roles**, and **view audit history**. These are permissions, not fixed roles. |
| Agreed privilege-escalation safeguard | Only a Platform Owner can create or edit Platform roles and assign roles to Platform users. A user with **manage Platform users** may create accounts, reset temporary passwords, and deactivate users, but cannot change role assignments or permissions. |
| Agreed audit retention | Keep Platform audit history permanently in the first version. Record who performed an action, when, the affected user, role, tenant, subscription, payment, backup, or support item, and the action result where relevant. |
| Product decisions still needed | The initial Platform permission catalog, which actions require a second approval, temporary-password expiry, and audit-log retention. |

### SAAS-007 — Public SaaS marketing landing page

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P2 |
| Requested outcome | `saas.example.com` has a public marketing website that explains the SaaS product and directs prospective customers to contact, demo, trial, or sign-in actions. |
| Scope notes | This is a platform-wide site, separate from each tenant's public website and separate from the authenticated Platform dashboard. |
| Candidate pages | Home, features/modules, packages, frequently asked questions, contact or demo request, platform sign-in, and legal/privacy pages. |
| Brand boundary | Platform-wide AlKhair SaaS branding belongs here. Tenant names, logos, and website content stay on their own tenant subdomains. |
| Requested editing capability | Authorized Platform users can edit landing-page content and arrange supported layout sections without editing code. Draft changes are separate from published content. |
| Agreed editing model | Use a controlled page builder: authorized Platform users edit text, images, calls to action, supported feature sections, and their order through prepared layout blocks. Do not allow arbitrary HTML, JavaScript, or unrestricted page layouts. |
| Agreed language scope | The public Platform landing page is available in both Arabic and English. Each editable content block has separate Arabic and English content. |
| Agreed language experience | Show an Arabic / English selector in the header. Initially use the visitor's browser language when available, but always allow manual switching. Arabic content uses a complete right-to-left layout. |
| Agreed permission split | **Manage landing page** edits Arabic and English content, images, supported layout blocks, and drafts. **Publish landing page** makes a reviewed draft public or restores a previous published version. |
| Agreed publishing validation | Drafts may be incomplete. Publishing is blocked when a required Arabic or English content block is missing, and the editor clearly identifies the missing language and section. |
| Agreed visual approach | Use polished screenshots of the real Platform and tenant application in device-style frames. Feature copy and the displayed screenshot change together as the visitor scrolls, explaining actual product capabilities rather than showing invented screens. |
| Agreed contact action | The public contact or demo form asks for name, organisation name, email, optional phone number, and optional message. Do not ask the visitor to choose a preferred language; record the page language automatically. Requests appear in a Platform enquiries list for authorized Platform users to review and follow up manually. |
| Agreed enquiry scope | Do not add enquiry statuses, sales workflow, or conversion tracking in the first version. Keep contact requests as a simple list for authorized Platform users. |
| Implemented decisions | The base SaaS domain serves a responsive Arabic/English Platform website with browser-language detection and manual switching. Authorized Platform users edit prepared bilingual sections, visibility, ordering, copy, and product screenshots in a private draft. Publishing validates both languages and creates restorable revisions. Active packages are shown from the landlord catalogue, and demo/contact submissions are recorded in a simple Platform enquiry list without a sales workflow. |

### SAAS-008 — Subscription lifecycle, plan period, and expiry

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P1 |
| Requested outcome | Every tenant has a clear plan, start date, end date, and operational status so the platform can manage trials, renewals, expiry, suspension, and cancellation consistently. |
| Agreed plan and period model | A plan defines what the tenant receives. A subscription defines its start date, end date, and selected period: monthly, annual, or custom end date. Platform staff manages the subscription. |
| Candidate lifecycle | Draft → Trial or Active → Expiring soon → Expired or Suspended → Cancelled. Renewal returns an eligible tenant to Active. |
| Agreed expiry path | Show a renewal warning seven days before expiry. When the end date passes, allow a seven-day grace period. When grace ends, suspend the tenant from creating or changing data; never automatically delete tenant data. A renewal reactivates the tenant. |
| Agreed warning audience | Tenant administrators receive the clear expiry date and renewal guidance in their dashboard. Authorized Platform users see every tenant's subscription state. Regular tenant users do not receive pre-expiry commercial warnings. |
| Agreed suspended-user message | When a tenant is suspended, all tenant users see a simple service-unavailable message directing them to their organisation administrator. Do not show payment or subscription details to ordinary users. |
| Agreed cancellation and retention | A Platform user marks a subscription cancelled. The tenant remains usable through its paid end date and grace period, then becomes suspended. Keep suspended tenant data for a retention period controlled in Platform settings, initially twelve months. Permanent deletion is a separate explicit, audited Platform action. |
| Agreed trial scope | Do not create a separate standalone trial feature. When a short evaluation period is needed, a Platform user creates a normal tenant subscription with a short custom end date; it follows the same warning, grace-period, suspension, and retention rules. |
| Implemented decisions | Monthly, annual, and custom periods; tenant-administrator expiry warnings; seven-day grace; generic suspended access page; cancellation at paid-period end; Platform-controlled retention setting; explicit audited deletion; and reactivation. |

### SAAS-009 — Platform payment recording for offline and online payments

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P1 |
| Requested outcome | The platform can record how a tenant subscription was paid and use that record when activating or renewing the tenant. |
| Agreed first version | Support manual offline payment records only: cash, bank transfer, cheque, or other method; amount; currency; paid date; reference or receipt number; note; and the Platform user who recorded it. |
| Agreed base currency | Use Syrian pound (**SYP**) as the single Platform base currency in the first version. Plans, subscription charges, offline payments, balances, vouchers, and receipts use SYP. |
| Agreed receipt scope | Generate a Platform payment receipt with a receipt number, tenant organisation name, payment date and method, amount in SYP, payment reference and note, and the Platform user who recorded it. It can be viewed or downloaded as a PDF. |
| Recommended accounting model | Keep a small immutable subscription ledger in the landlord database, separate from tenant finance. Payments add credit; subscription charges reduce it; allocations link credits to charges. The displayed tenant balance is calculated from those entries, not edited directly. This supports advance payments, partial payments, corrections, and an auditable history. |
| Agreed partial-payment rule | A payment smaller than a subscription charge remains available as tenant credit. Automatic renewal happens only when the full charge amount is available. |
| Agreed prepaid renewal | Automatically renew a subscription when its end date arrives if the tenant has enough available balance for the selected plan and period. Create a charge using a recorded price snapshot, allocate the credit, extend the subscription, and record the automated action in the audit history. If the balance is insufficient, do not renew; continue through the agreed warning and grace-period path. |
| Agreed refund scope | Do not support refunds in the first version. A recorded payment remains in the subscription ledger; any future refund capability must be an explicit, audited feature. |
| Agreed price-change rule | A plan price change affects only charges created for future subscription periods. A tenant keeps the recorded price for an already-paid period. |
| Future online path | Add payment-provider integrations behind a provider interface after the manual flow is proven. Provider callbacks must be verified, idempotent, and audited before changing subscription status. |
| Boundary | Platform subscription payments are landlord data. They are not the tenant's internal finance, invoices, or student payments. |
| Implemented decisions | SYP-only offline payments are recorded immediately as immutable prepaid credits with method, paid date, reference, note, receipt number, and recording user. Renewal charges keep price and discount snapshots and allocate available payment credits FIFO. Partial credit remains available; refunds and online providers remain outside this first version. |

### SAAS-010 — Voucher and discount codes

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P2 |
| Requested outcome | The platform can offer controlled discounts during tenant signup or renewal. |
| Agreed first scope | Platform users create voucher codes with either a fixed-amount or percentage discount, valid-from and valid-until dates, and a redemption limit: one use, a specified total number of uses, one selected tenant, or unlimited uses while the voucher remains active. |
| Agreed application choices | A voucher can apply to the first payment only, a defined number of renewal periods, or every renewal while the voucher remains active. Each use is recorded against its subscription charge, preserving the original plan price, discount, and final charged amount. |
| Agreed stacking rule | Allow only one voucher on each subscription charge. Do not combine voucher discounts in the first version. |
| Implemented decisions | Platform users can create fixed-SYP or percentage vouchers with optional validity dates; one-use, total-use, tenant-only, or unlimited scope; and first-period, limited-period, or recurring application. Only one voucher is assigned to a subscription. Every discounted charge records the original price, discount, final charge, tenant, subscription, and voucher. Arbitrary staff discounts and voucher stacking are not supported. |

### SAAS-011 — Full tenant-domain preview during creation

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P2 |
| Requested outcome | While entering a tenant subdomain, the New tenant form immediately shows the complete resulting address, for example `demo.saas.example.com`. |
| Agreed purpose | This is a live, read-only preview to help the Platform user understand exactly which tenant URL will be created before submitting the form. It does not add a second URL field or make the subdomain editable after creation. |
| Scope notes | The preview should update as the slug changes, validate reserved or unavailable names, and match the configured tenant base domain. |
| Implementation note | Implemented on the SaaS branch: live full-domain preview during tenant creation and an immutable address after creation. |

### SAAS-012 — Tenant support incident reporting

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P1 |
| Requested outcome | A tenant user can report an error, outage, or urgent support problem to their tenant administrator. A tenant administrator can then forward it to the Platform team and follow its progress. |
| Recommended user experience | A **Report a problem** action in a Support area, distinct from feature suggestions. The form asks for a short summary, what happened, expected result, impact, urgency, and optional screenshot. |
| Agreed two-stage flow | Stage 1 is inside the tenant: an authenticated tenant user reports a problem to tenant administrators, who can manage and respond to it. Stage 2 begins only when a tenant administrator forwards the problem to Platform support. The Platform team sees only forwarded cases for that tenant. |
| Agreed forwarding boundary | Forwarding creates a linked Platform case and keeps the tenant's original report. Platform replies go to the tenant administrator first; the tenant administrator decides what to share with the internal reporter. |
| Agreed internal lifecycle | Tenant reports follow **New → In review → Resolved → Closed**. A tenant administrator may forward a report to Platform support at any stage. The linked Platform case follows its own separate lifecycle. |
| Agreed attachment scope | The first version allows screenshots and common documents on problem reports only, subject to file-size limits and tenant-isolated storage. Suggestions are text-only initially. |
| Agreed visibility signal | Do not add in-app or email notifications in the first version. Show counters only for requests needing attention: tenant administrators see new or in-review internal items, and Platform users see new or awaiting-tenant linked cases. Resolved and closed items do not increase a counter. |
| Agreed permissions | A tenant role needs a dedicated **submit problem reports** permission to create an internal report. Tenant administrators can view, manage, and forward all internal reports. Platform roles use separate permissions to view or manage only forwarded Platform cases. |
| Agreed submitter visibility | A tenant user can see the current internal status and tenant-administrator responses for their own problem reports only. They cannot see reports from other users. |
| Agreed follow-up rule | While their own report remains open, a submitter may add information or reply to a tenant administrator. Once closed, only a tenant administrator may reopen it. |
| Agreed priority rule | Problem reports use only three priorities: **Normal** for a non-blocking or individual issue, **High** when important work is blocked for several users, and **Critical** when an essential part of the tenant application cannot be used. Submitters select a suggested priority; tenant administrators can correct it before forwarding. |
| Automatic context | Record tenant, reporting user, time, current URL, application release/version, browser information, and a generated incident reference number. Do not collect passwords, session tokens, or sensitive page content automatically. |
| Agreed Platform lifecycle | Linked Platform cases follow **New → Acknowledged → Investigating → Waiting for tenant → Resolved → Closed**. |
| Product decisions still needed | Severity definitions and response targets, notification channels, screenshot/file rules, whether users can reopen incidents, public status-page scope, and retention policy. |

### SAAS-013 — Tenant feature suggestions and product feedback

| Field | Value |
| --- | --- |
| Status | Implemented |
| Priority | P2 |
| Requested outcome | Tenant users can submit improvement ideas to their tenant administrator. A tenant administrator can then forward selected suggestions to the Platform product team with the needed business context. |
| Recommended user experience | A **Suggest an improvement** action in the same Support area, with a separate form for the desired outcome, current workaround, affected users, and business impact. |
| Agreed two-stage flow | Stage 1 is inside the tenant: tenant users submit suggestions and tenant administrators review them. Stage 2 begins only when a tenant administrator forwards a suggestion to the Platform team. The Platform team sees only forwarded suggestions for that tenant. |
| Agreed forwarding boundary | Forwarding creates a linked Platform suggestion and keeps the tenant's original suggestion. Platform replies go to the tenant administrator first; the tenant administrator decides what to share internally. |
| Agreed internal lifecycle | Tenant suggestions follow **Submitted → Under review → Forwarded to Platform / Declined / Implemented internally**. A tenant administrator can add a reason when declining a suggestion. |
| Agreed Platform lifecycle | Linked Platform suggestions follow **Submitted → Under review → Planned → In progress → Released** or **Declined**. Platform users can combine duplicate suggestions from different tenants while preserving each tenant's original linked request. |
| Agreed visibility signal | Do not add in-app or email notifications in the first version. Show counters only for suggestions needing attention: tenant administrators see submitted or under-review suggestions, and Platform users see submitted or under-review linked suggestions. Completed or declined items do not increase a counter. |
| Agreed permissions | A tenant role needs a dedicated **submit suggestions** permission to create an internal suggestion. Tenant administrators can view, manage, and forward all internal suggestions. Platform roles use separate permissions to view or manage only forwarded Platform suggestions, including classification and duplicate handling. |
| Agreed submitter visibility | A tenant user can see the current internal status and tenant-administrator responses for their own suggestions only. They cannot see suggestions from other users. |
| Agreed follow-up rule | While their own suggestion remains open, a submitter may add information or reply to a tenant administrator. Once closed, only a tenant administrator may reopen it. |
| Candidate lifecycle | Submitted → Under review → Planned → In progress → Released or Declined. |
| Product value | Requests become a structured product backlog rather than untracked messages. Similar suggestions can be grouped to show demand across tenants. |
| Product decisions still needed | Whether tenants can vote on suggestions, whether roadmap status is visible to tenants, anonymous submission policy, attachments, and how product decisions are communicated. |

### SAAS-014 — Tenant migration, backup, and restore workflow

| Field | Value |
| --- | --- |
| Status | In progress |
| Priority | P0 |
| Requested outcome | The platform can safely import a standalone AlKhair installation into a new SaaS tenant and can reliably back up and restore an existing SaaS tenant. |
| Import scenario | Provision a new tenant first, then move the standalone installation's database and uploaded files into that tenant without changing other tenants or landlord data. |
| Agreed import source | Standalone imports accept only official encrypted AlKhair database and files backup archives. Direct database credentials and arbitrary SQL imports are out of scope. |
| Restore scenario | Restore a selected tenant's database and matching tenant files together, with a safety snapshot before replacement and clear confirmation of the tenant and backup being affected. |
| Agreed restore scope | The first version supports complete tenant restores only: the tenant database and all matching tenant files from the same backup archive. Do not offer table-only or file-only restores. |
| Agreed restore safeguard | Every restore creates and verifies a safety backup of the tenant's current database and matching files before any replacement begins. |
| Agreed automation | A scheduled Platform job backs up every active tenant's database plus matching public and private tenant storage. Archives are retained on the VPS and made available for authorized manual download. Automatic off-VPS upload is deferred. |
| Agreed scheduling model | The VPS has one fixed Laravel scheduler cron entry. A Platform Owner manages backup enabled state, frequency, preferred run time, and retention from Platform settings; the application decides when the scheduled job is due. |
| Agreed retention model | Platform Settings contains a per-tenant backup retention count, initially defaulting to the latest 30 complete backup archives. |
| Agreed tenant data-size rule | Each subscription plan has one tenant data-size limit. Its purpose is to prevent one tenant's database and files from growing so large that its backups consume an unfair share of VPS storage. Do not create a separate tenant backup quota or a second tenant-facing storage rule. |
| Agreed tenant storage page | A tenant user with the dedicated **view storage usage** permission can open a Storage page for that tenant only. It shows one total and a plain breakdown of photos/media, uploaded documents, other tenant files, database records, and backup archives. A usage bar shows, for example, **6.2 GB used of 10 GB**. This is informational and does not add separate limits. |
| Agreed Platform storage overview | A Platform user with **view Platform storage usage** can see every tenant's current total storage usage and the same category breakdown, with tenant-by-tenant comparison, sorting, and the same usage bar. The overview shows usage figures only; it does not grant access to the tenant's files or database contents. |
| Agreed usage-bar thresholds | Show normal below 80% of the tenant data-size limit, warning from 80% to 99%, and full at 100%. These visual states do not create notifications. |
| Agreed full-limit behaviour | At 100%, block new file uploads while keeping existing data readable and normal records usable. A tenant administrator can remove unneeded files, or Platform staff can increase the tenant's subscription data-size limit. |
| Agreed storage-cleanup safeguard | When backup storage needs space, remove only the oldest verified scheduled archives. Before a new backup completes, always retain at least one verified backup for that same tenant. Never remove the active restore safety backup. If enough space cannot be made while preserving these safeguards, fail the new backup safely and record the failure. |
| Agreed access | Platform administrators can restore any tenant. A tenant administrator may restore only that tenant's own backup after a prominent destructive-action warning and typed confirmation. Standalone-import remains Platform-only. |
| Agreed self-restore approval | A tenant administrator's same-tenant restore begins immediately after the required destructive-action warning and typed confirmation. It does not require Platform approval; the mandatory verified safety backup provides the recovery path. |
| Agreed download permission | A tenant administrator may download only that tenant's backup archives. A Platform administrator may download backup archives for any tenant. |
| Self-restore boundary | A tenant self-restore accepts only backups cryptographically identified as belonging to that same tenant UUID. It cannot import another tenant's backup. |
| Required data boundary | A tenant backup must include the tenant database plus `storage/app/public/tenants/{uuid}` and `storage/app/private/tenants/{uuid}`. It must never include another tenant or the landlord database by accident. |
| Recommended workflow | Backup source → import into a non-production rehearsal tenant → apply current tenant migrations → verify data, files, users, and platform link → approve → run the controlled production import. |
| Restore compatibility | A backup restores data, not an old application version. Before replacement, restore into a temporary database, run forward tenant migrations and idempotent required-data repair, then validate it against the currently deployed application schema. |
| Agreed import activation | An imported tenant stays inactive after validation. A Platform administrator reviews the import summary and explicitly activates it. |
| Deferred import scope | Standalone AlKhair import implementation and its edge cases are deferred. Continue defining and building new SaaS features independently of this migration path. |
| Required checks | Source/target schema comparison, migration status, row-count summary for critical tables, file inventory, database integrity, tenant-owner authentication, platform-administrator link, and tenant-domain smoke test. |
| Security requirements | Imported archives remain encrypted; accept the source `APP_KEY` only temporarily for decryption; never store or display it. Record every import, restore, actor, source, target, timestamp, and outcome in the platform audit trail. |
| Product decisions still needed | Maximum backup size, restore approval policy, handling partial restores, conflict policy for existing users, and whether imports can include legacy standalone branding. |

### SAAS-015 — Platform-managed tenant learning progression

| Field | Value |
| --- | --- |
| Status | Done |
| Priority | P2 |
| Requested outcome | Each tenant uses one Platform-managed learning-progression profile that tells staff and students what evidence is needed before a student advances. |
| Initial profile modes | Quran progression, using existing memorization and Quran-test evidence; or lesson/level progression, using lesson-group participation, attendance, and final assessments. |
| Agreed profile selection | A tenant uses one profile only: Quran or lesson-and-level. A tenant user with **manage learning progression** chooses it before any student enters progression. The selection locks after the first progression assignment. |
| Scope boundary | This is a learning-progression feature, not a generic workflow engine. It reads existing Quran tests, assessments, attendance, lessons, enrollments, and groups as evidence; it does not replace their records or screens. |
| Agreed Quran interaction boundary | Do not change the current Quran memorisation or test interaction, screens, scoring, or results. The progression profile only reads the existing records to determine eligibility and progress. |
| Agreed Quran setup | A tenant user with **manage learning progression** records which Quran tests the tenant uses and their prerequisite rules. Initial options include whether the tenant has partial tests, whether a passed partial test is required before a final test, whether the tenant has final tests, whether a passed final test is required before an Awqaf test, and whether the tenant uses an Awqaf test at all. A tenant without Awqaf completes its Quran path after its final configured test. Invalid combinations are blocked, such as requiring a partial test that the tenant does not use. |
| Agreed prerequisite enforcement | Keep existing test pages and interactions unchanged. When staff attempts to start a test that is not eligible, show a clear explanation of the missing prerequisite; once it is passed, the normal existing test flow continues. |
| Agreed Quran-setting lock | A tenant user with **manage learning progression** may correct Quran test settings only before the tenant has any Quran memorisation or test records. Once the first such record exists, the settings lock to protect existing student progress. |
| Implemented Quran foundation | The tenant now has a permission-controlled Learning Progression settings page for enabling partial, final, and Awqaf tests and choosing the partial-to-final and final-to-Awqaf prerequisites. Invalid combinations are rejected, the settings lock after Quran evidence exists, disabled stages are rejected by the existing recording services, and optional prerequisites affect final/Awqaf eligibility without redesigning the existing test screens. |
| Implemented setup gate and Quran summary | New SaaS tenants must configure learning progression before recording memorization or Quran tests. Existing tenants with Quran evidence are automatically backfilled with the legacy path. The existing student progress page now shows the configured Quran path, current next step, completed and active Juz counts, and hides test-stage columns the tenant does not use. |
| Implemented lesson-and-level foundation | An authorized tenant user can select the lesson-and-level profile and create, edit, delete, and reorder levels. Every level references existing required curriculum lessons, one or more delivery groups, an attendance threshold, an existing final assessment, and a passing score. Cross-curriculum lessons, unrelated assessments, and scores above the assessment total are rejected. The profile becomes configured after its first valid level. |
| Implemented assignment and automatic promotion | An authorized tenant user can assign a student only to the first configured level. The student's state belongs globally to the student, locks profile configuration, and remains independent of group changes. Required lessons count as delivered from existing curriculum progress; attendance is matched to those lesson dates and delivery groups; and the configured assessment score is evaluated from existing results. Passing all three rules promotes exactly one level, final-level completion is recorded, consumed evidence cannot be reused by another level, and every assignment or automatic transition creates history. The unified student progress page shows the current level and live lesson, attendance, and assessment evidence. |
| Implemented manual promotion | A tenant user needs the dedicated **Manually promote learning progression** permission. The student progress page warns that the action bypasses incomplete requirements, requires a meaningful written reason, advances exactly one configured level or completes the final level, captures the current evidence snapshot and actor, and records both progression history and the tenant data audit. |
| Implemented unified history | The student progress page shows the complete ordered progression history: initial assignment, immutable assessment-attempt snapshots, automatic promotions, manual promotions and reasons, final completion, responsible user or system, and the lesson, attendance, and score evidence captured at each decision. |
| Remaining implementation | None for the agreed initial learning-progression scope. Progression versioning and standalone-import mapping remain future compatibility work and belong with their respective migration projects. |
| Tenant control | A tenant user with the dedicated **manage learning progression** permission configures the tenant's single progression profile. The tenant cannot freely change the progression design after students have started. |
| Agreed setup gate | Staff may create students and groups before learning progression is configured. Do not allow a student to be assigned into a progression until the tenant's single profile is complete, preventing undefined promotion rules. |
| Promotion policy | Lesson/level progression promotes a student automatically after mandatory attendance and final-assessment rules pass. A user with a dedicated permission may manually promote one stage with a required reason and audit event. |
| Agreed manual-promotion permission | **Manually promote learning progression** permits an authorized tenant user to move a student forward by exactly one level despite incomplete rules. It requires a written reason and creates an audit entry. It never permits skipping levels. |
| Repeat and skip policy | A student may repeat a stage. A student cannot skip stages, including through manual promotion. |
| Progress ownership | One current progression state belongs to the student within a tenant. Evidence and history remain linked to the relevant enrollment, group, course, academic period, attendance, assessment, or Quran record. |
| Group relationship | Groups deliver lessons for a stage and may change over time. They do not own or reset the student's permanent progression state. |
| Agreed lesson-and-level structure | A tenant user with **manage learning progression** configures an ordered level structure: each level has required lessons, one or more delivery groups, an attendance requirement, and a final-assessment requirement. Students advance through Level 1, Level 2, and later levels to completion. Automatic promotion occurs when the configured rules pass. A permitted administrator may promote only one level at a time with a required reason and audit event; a student may repeat but never skip a level. |
| Agreed attendance rule | Each level has its own required attendance percentage, calculated from that level's required lessons only. |
| Agreed final-assessment rule | For each level, a tenant user with **manage learning progression** selects an existing final assessment and its passing score. A student is automatically promoted only after both the level attendance rule and final-assessment rule pass. |
| Agreed repeat behaviour | If a student does not meet the attendance requirement or fails the final assessment, they remain in the same level. Staff may record further attendance and a new assessment attempt; automatic promotion occurs when both rules later pass. |
| Agreed progression view | Show the student’s current level or Quran stage, completed requirements, remaining attendance or assessment requirement, previous levels and test attempts, and any manual-promotion reason. Do not redesign the existing Quran test screens. |
| Restored data policy | When standalone Quran data is imported into a new SaaS tenant, the Quran profile infers the student's current stage from restored records without deleting, recalculating, or requiring repetition of historical tests. |
| Deferred follow-ups | Versioning an already active progression design and mapping restored standalone Quran records are deferred to future compatibility and import work. |

### SAAS-016 — Tenant report designer and dashboard widgets

| Field | Value |
| --- | --- |
| Status | In progress |
| Priority | P3 |
| Requested outcome | Tenant administrators can design, save, share, export, and pin meaningful tenant reports and dashboard widgets without writing SQL. |
| Data model | The application provides a tenant-scoped reporting catalog of safe business data sources, fields, aggregates, and registered relationships. Tenants select from the catalog; the application builds and executes the query. |
| Supported design controls | Data source, fields, registered relationships, filters, date range, grouping, sorting, totals, chart type, table layout, report name, description, and dashboard placement. |
| Agreed calculation scope | Start with safe built-in calculations only: count, total, average, minimum, maximum, and application-defined percentages. Do not allow user-written formulas in the first version. |
| Agreed export scope | Saved reports support Excel and PDF exports in the first version. An export uses the same filters, tenant scope, and viewer data permissions as the report screen. |
| Agreed scheduled-delivery scope | Do not add scheduled report generation or delivery in the first version. Focus on report design, dashboard widgets, and on-demand Excel or PDF exports. |
| Dashboard relationship | A saved report may be added as a compact dashboard widget. The widget and full report share one definition, so values and filters stay consistent. Dashboard placement controls which tenant roles can open the report. |
| Agreed dashboard layout | A tenant administrator, or a tenant role with **manage dashboard layout**, creates and applies controlled dashboard layouts for selected tenant roles. They can add, remove, reorder, and choose small, medium, or wide sizes for widgets. Individual users do not rearrange the shared layout in the first version. Layouts remain readable on desktop and mobile. |
| Agreed report visibility | Do not choose report-viewing roles separately. A newly created report stays a draft that only its creator and dashboard-layout managers can preview or edit. When it is added to a role dashboard, users in that role can see it there and in their Reports area. Removing it from all role dashboards makes it unavailable to ordinary tenant users again. Normal data permissions still apply to every result. |
| Agreed student and parent boundary | The Report & Widget Designer is for internal staff roles only. Student and parent dashboards remain purpose-built and show only their own permitted information: a student sees personal progress, attendance, lessons or assessments, and results; a parent sees only linked children and approved information. They cannot add reports, select fields, or see tenant-wide widgets. |
| Agreed template library | Provide a curated **Report & Widget Library**. Platform users create, version, and publish reusable report and widget templates. Tenant users with the installation permission open the library, browse compatible published templates, and choose which reports or widgets to add to their tenant's Report & Widget Designer. |
| Agreed library permissions | **Manage report library** creates and edits library drafts. **Publish report library** makes reviewed templates available to tenants. A tenant role needs **install report library items** to open the library and install a compatible template; tenant administrators receive it by default. |
| Agreed existing-report migration | Existing reports and dashboard widgets become system predefined templates in the library when their related module is enabled. A tenant may add an installed template through its Report & Widget Designer when creating or editing a report or dashboard widget. The original system template remains protected; **Make a copy** creates an editable tenant report or widget. |
| Implemented foundation | Added permission-controlled tenant report drafts and approved sources for **Students**, **Courses**, **Groups**, **Student attendance**, **Memorization sessions**, Quran test workflows, **Assessments**, **Assessment results**, **Teachers and workload**, and **Finance transactions**, with safe field/filter/sort selection and a 25-row preview. Attendance supports center-wide and group attendance. Quran reporting covers memorization, Awqaf/general attempts, and partial/final workflow summaries. Assessment definitions expose schedules and scoped aggregates, while student results remain separately permission-scoped. Teacher reporting combines primary and assisted groups with active enrollment workload while excluding phone, account, and login data. Finance reporting reads the existing ledger, respects accessible cash boxes, and additionally requires **view finance reports**. Sources disappear when their tenant module is disabled. Raw SQL and arbitrary table or relationship access are not exposed. |
| Implemented calculations | Saved reports can include up to five approved calculations. Exact matching-record counts work for every source. Total, average, minimum, and maximum work over approved numeric fields for courses, groups, memorization, Quran tests, assessments, assessment results, teacher workload, and finance. Calculations use the full scoped and filtered result set rather than only the 25 preview rows. User formulas and arbitrary expressions remain unavailable. |
| Implemented grouping | Operational reports can be grouped by approved business dimensions such as status, academic year, course, group, teacher, test type, assessment type, or workload context. Finance reports can be grouped by category, fund, currency, transaction type, or direction. Each controlled group shows an exact record count and the report's approved calculations over the full scoped and filtered result set, with a 25-group preview limit. Arbitrary columns and expressions are not accepted. |
| Implemented exports | Saved reports provide on-demand Excel and PDF exports using the same approved fields, filters, sorting, tenant boundary, module availability, viewer permissions, and record scopes as the designer. Excel contains the detailed filtered rows. PDF includes the report identity, tenant branding, calculations, complete grouped summary, and detailed rows. Exports are rejected with a clear message when more than 5,000 records match, so files are never silently truncated. |
| Implemented visibility and placement | Tenant roles with **manage report dashboard layouts** can place a saved report on one or more staff-role dashboards and choose a different small, medium, or wide size for each role. A role-layout editor can reorder its widgets, change their sizes, or remove them from that role. The shared order is applied to every user with the role; users with multiple roles keep every assigned report, with the highest-precedence matching role supplying that report's position and size. Student and parent roles cannot be placement targets. The first placement publishes the report to those roles on their dashboard, full report page, narrow Custom Reports area, and exports; removing the final placement returns it to draft and immediately revokes ordinary-role access. Assigned staff can reach the Custom Reports page from navigation without receiving the broad **view reports** permission; the standard reports, rankings, and exports remain protected by that permission. Creator and layout-manager access remains available for review. Viewer record scopes, enabled modules, and Finance permission checks continue to apply. |
| Implemented presentation controls | A report designer can show grouped results as an accessible grouped table, bar chart, or doughnut chart and can choose compact or comfortable table spacing. Charts require an approved grouping and show matching record counts, avoiding misleading combinations of signed finance values or mixed currencies. The saved choice is reused by designer previews, full reports, and role dashboard widgets; detailed rows remain available on the full report. |
| Implemented library catalogue foundation | Platform roles now have separate **manage report library** and **publish report library** permissions. Authorized Platform users create bilingual, data-free report or widget drafts from the approved reporting catalogue, and publishing records an immutable numbered revision. Later draft edits do not alter the currently published revision. Tenant browsing and installation remain the next library step. |
| Implemented tenant library installation | Tenant roles use the dedicated **install report library items** permission to browse published templates. The library clearly blocks items whose required module, data-source permission, or approved field definition is unavailable. Installing copies the exact published revision into the tenant database as an editable draft, records its library UUID and revision, and never links tenant data back to the Platform template. Reinstalling creates another independently editable copy. |
| Implemented predefined template set | The library ships protected bilingual report-and-widget templates for students by group, student attendance risk, Quran test outcomes, assessment performance, teacher workload, course completion, and finance transaction summaries. Templates appear only when their required module and viewer data permissions are available. Platform users can inspect these code-owned originals but cannot edit or republish them; tenants always receive editable copies. |
| Data-isolation boundary | A library item contains only a report or widget definition, labels, presentation settings, required modules, and version information. It never contains source-tenant data, files, users, or database access. |
| Security boundary | Do not expose SQL, database credentials, sensitive technical tables, password hashes, tokens, sessions, audit internals, or backup metadata. Every result remains scoped to the current tenant, enabled modules, and viewing user's permissions and record scope. |
| User guidance | Guided templates, friendly relationship labels, field descriptions, a plain-language report summary, limited preview data, result count, warnings for likely mistakes, visible filters/date range, and role-based preview or test mode. |
| Operational controls | Query timeout, row and export limits, caching for dashboard widgets, report version history, audit events for changes/exports, and safe handling when a referenced module or field becomes unavailable. |
| Candidate first templates | Attendance risk, students by level/group, Quran-test outcomes, assessment performance, teacher workload, course completion, and finance summary when Finance is enabled. |
| Agreed first reporting catalog | Start with Students; groups and courses; attendance; Quran memorisation and tests; assessments; teachers and staff workload; and Finance only when that tenant has the Finance module enabled. |
| Agreed chart reuse | Reuse existing chart and visual-widget implementations as predefined library templates: line and bar charts, doughnut charts, group-distribution treemaps and lollipop views, curriculum progress hotbars, performance maps, ranking and leaderboard views, and finance expense and quarterly charts. Specialised visuals stay predefined templates for compatible data rather than becoming unrestricted chart types for every report. |
| Implemented specialised visual foundation | **Done.** The Students by group system template now publishes a versioned group-distribution lollipop presentation. It renders consistently in previews, full reports, and dashboard widgets, remains editable after installation, and is deliberately unavailable as an unrestricted chart choice for unrelated tenant reports. |
| Implemented chronological trend visual | **Done.** The library now includes an Attendance activity trend template that reuses the dashboard line-chart language. Approved date groupings return the latest points in chronological order, and the specialised line presentation rejects categorical groupings so it cannot imply a false time sequence. |
| Implemented proportional distribution visual | **Done.** The library now includes a Students by grade level treemap. Each tile exposes the approved group name, exact student count, and percentage, while the presentation remains available only through compatible library templates. |
| Implemented calculated chart measures and expense visual | **Done.** Library templates can bind a chart to one approved grouped calculation instead of always using record count. The Expenses by category template uses an absolute local-currency total, preserving the finance ledger sign while presenting expense magnitude consistently with the existing finance dashboard. |
| Implemented finance quarterly trend | **Done.** Finance reports can use a controlled calendar-quarter grouping without exposing date expressions. The Quarterly expense trend template plots absolute local-currency expense totals in chronological quarter order and automatically continues across years as new ledger transactions are recorded. |
| Implemented curriculum progress hotbars | **Done.** The Groups catalogue exposes the existing curriculum completion summary only when the Curriculum module is enabled. The protected Curriculum progress by group template reuses the peer-relative dashboard hotbars, showing each group’s completion percentage, attention tone, and equivalent lesson gap without exposing curriculum tables. |
| Implemented student performance map | **Done.** The protected Student performance map template reuses the existing dashboard comparison of memorized pages and points. It reads only scoped active students, exposes approved cached enrollment totals when the Memorization and Points modules are enabled, compares each student with tenant averages, and remains editable after installation without exposing enrollment tables. |
| Product decisions still needed | Which reporting catalog domains ship first, formula/calculated-field policy, sharing roles, dashboard layout controls, export formats, scheduled reports, chart library, retention of report versions, and whether reports may be copied between tenants. |

## Future requests

Add each new request with the same structure:

```md
### SAAS-XXX — Short feature name

| Field | Value |
| --- | --- |
| Status | Requested |
| Priority | Not set |
| Requested outcome | What the user should be able to achieve. |
| Scope notes | Important included or excluded behaviour. |
| Decisions still needed | Questions to resolve before planning. |
| Acceptance checks | Observable results that prove it works. |
```

## Planning boundary

This backlog intentionally contains no sprint assignments, estimates, or
implementation order. Add those only after a request to create a sprint plan.

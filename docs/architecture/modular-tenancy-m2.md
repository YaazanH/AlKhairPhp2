# M2: Students, Parents and Parent Portal

Implemented on `modules-and-tenant-feature`, 2026-09-20.

## Behavior delivered

- Student API creation accepts no parent. When Parents is unavailable, parent assignment is prohibited in API writes; omitted links remain unchanged on updates. Student update/delete also check the existing record-scope service.
- Student web forms hide parent selection and quick creation. Existing hidden links survive student edits. Parent details are hidden in the grid, duplicate dialog and progress panel; parent-based name disambiguation and student export/search do not reveal hidden family data.
- Parents routes, student routes and the parent API have request-boundary module enforcement. Livewire persists the people middleware, and mutation permission checks enforce module availability independently.
- Parent-only accounts cannot sign in or use an existing session/token while Parent Portal is disabled. Logout/token revocation remains possible. Multi-role accounts can still use independently authorized staff features; all parent-specific endpoints still require Portal.
- Parent API sections require their corresponding modules. Recursive filtering removes disabled module fields from people/parent payloads, including child and enrollment summaries. Existing linked-child ownership checks remain in force.
- Parent dashboard optional totals follow module availability. People sidebar items follow Students/Parents/Portal entitlements.
- Permission assignment displays available module permissions only. Crafted submissions cannot add unavailable permissions; cloning filters those grants. Existing dormant role grants are preserved for reactivation but cannot bypass module restrictions.
- Module denials run BEFORE Spatie permission grants and the super-admin bypass. Spatie's automatic Gate registration is disabled in configuration and registered explicitly after the module guard in AppServiceProvider. Existing permission checking behavior is retained after that guard.
- Module availability is cached only for one request and tenant in a scoped service; the next request recomputes availability. No cross-tenant or persistent permission cache was introduced.

## Capabilities contract

`GET /api/v1/capabilities` requires Sanctum authentication and returns:

```json
{
  "data": {
    "modules": ["foundation", "students"],
    "permissions": ["students.view"],
    "setup": {"status": "not_managed_yet"},
    "version": "opaque-content-hash"
  }
}
```

Modules describe tenant entitlement, not blanket access to all records. Permissions are filtered by module availability and current user permission grants. Record scopes still apply server-side. Version incorporates tenant entitlement and the current user's effective permission list. Responses are private/no-store. Setup readiness is explicitly not managed yet; do not interpret this placeholder as completed onboarding. M8 will extend it with real module readiness.

Existing parent clients receive omitted fields for disabled sections and HTTP 403 for disabled endpoints. Clients must refresh capabilities on login/resume and handle access changes. Native mobile source is not present in this repository, so this sprint changes the server contract, not a mobile binary.

## Verification and remaining boundaries

The focused people, parent mobile, write API and scoped-access run passed 19 tests / 196 assertions. A broader 49-test run (adding the M1 engine and authentication suites) caught one stale-remember-cookie middleware-order regression; the other 48 tests passed. After fixing the order, all 23 authentication/people tests passed with 129 assertions. A final focused parent-session entitlement test also passed after restricting account checks to authenticated routes, leaving public pages accessible. New scenarios cover parent-free API creation, hidden-link preservation on API/web updates, denied quick-parent mutation, denied unavailable role grants, super-admin/Gate denial ordering, existing parent-session revocation, capabilities changes and cross-family child access. Pint, Blade compilation and diff whitespace checks passed.

Playwright browser check: signed into `al-noor-test-four.localhost:8000`, opened Students and the Arabic create form without submitting data. Existing package behavior and layout were intact; a screenshot is in `output/playwright/m2-student-form.png`. A missing tenant logo at `/storage/website/branding/logo.jpeg` produced a 404 and remains a separate asset issue. Disabled-parent rendering was verified by automated Livewire tests rather than changing a real tenant's package for browser testing. This was a desktop smoke check, not full responsive/mobile-client QA.

No tenant schema migration or package reassignment is needed for M2. The existing nullable-parent migration remains the schema prerequisite.

This is the people/portal vertical slice, not completion of every module combination. Cross-module learning/finance workflows, mixed reporting and operational tools are completed in their scheduled M3–M6 sprints. The main application still has shared pages that need those subsequent slices before arbitrary module combinations are offered for production. Package/extras UI remains M7 and the onboarding wizard remains M8. Legacy negative-override blockers identified by the M1 preview require review before enabling modular access for affected tenants.

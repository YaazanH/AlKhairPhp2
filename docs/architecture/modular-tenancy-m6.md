# Modular tenancy M6: shared surfaces

M6 classifies the remaining shared presentation surfaces so a disabled module is absent from navigation, rejected by the server, and excluded from generated output.

## Module boundaries

- `custom_templates` owns the generic template designer and its view, manage, and print permissions.
- `id_cards` owns student card generation and print history. Version 1 requires `students` and includes one protected system template, so it works without `custom_templates`.
- `public_website` owns the tenant homepage, public pages, public navigation, and website settings. When disabled, `/` redirects to login and public page routes are rejected while website content remains stored.

Custom templates and ID cards are intentionally independent. Enabling the designer adds editable layouts; it does not replace or expose the system student-card template.

## Data and output isolation

- Template entities and fields are filtered by current module entitlements.
- The generic `user` source excludes student, teacher, and parent accounts when their owning module is disabled.
- Finance printing continues to use its standard output without requiring custom templates. Tenant custom layouts are available only when `custom_templates` is enabled.
- Group dashboard-card configuration and student dashboard previews are hidden when custom templates are disabled. Existing assignments are retained for re-enablement.
- Anonymous tenant media requests are allowlisted to configured website assets and media referenced by published pages. Authenticated requests to module-owned media folders are checked against the matching module; website managers may preview unpublished website uploads while editing.

## Deployment notes

Run tenant migrations after deployment to add `print_templates.is_system`. The standard student card is created lazily on first use. The migration is safe for existing templates because they default to non-system.

Role permissions must be reseeded so administrator and manager roles receive:

- `print-templates.view`
- `print-templates.manage`
- `print-templates.print`

No tenant MySQL migration was applied during implementation; verification used disposable test databases.

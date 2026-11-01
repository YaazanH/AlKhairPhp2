# Deleted memorization points repair

Deploy the code containing `points:repair-deleted-memorization` before using these commands. Run them from the production SaaS application directory, with the normal application PHP version and environment. Keep a current tenant database backup using the existing deployment backup procedure.

## Preview all operational tenants

```bash
php artisan points:repair-deleted-memorization --all-tenants --json > memorization-repair-preview.json
```

The default is read-only. Inspect each tenant's `candidate_count`, `candidates`, `enrollment_balances`, and `skipped` entries. The balances show effective enrollment points before and after removing the orphan rewards, including any unchanged manual or other automatic awards. The report contains internal student/enrollment IDs; keep it outside the public web directory.

## Apply the repair

```bash
php artisan points:repair-deleted-memorization --all-tenants --apply --json > memorization-repair-result.json
```

For a single tenant, replace `--all-tenants` with `--tenant=tenant-slug`. Omit `--json` to display a readable console summary. No permission-cache reset, queue restart, new database migration, or historical points rebuild is required for this command itself; follow the usual application deployment steps for publishing the code.

## Boundaries

- Only provisioned tenants with active or trial status are scanned. Suspended, expired, failed, and unprovisioned tenants are excluded.
- Only unvoided `memorization_session` point transactions whose source session is absent are candidates. The enrollment must still belong to the same student and resolve to an existing group and course.
- Finished courses, inactive courses, courses whose end date has passed in the tenant timezone, and finished groups/enrollments are protected. Their transactions and enrollment caches are not changed, even if their data is inconsistent with other active flags.
- Existing source sessions, unclear source IDs, missing/soft-deleted ownership, and ownership mismatches are left unchanged. Skipped records require individual investigation.
- Confirmed orphan transactions are retained and marked void with a batch ID. Only the affected eligible enrollment's `final_points_cached` is refreshed. Attendance, assessment, manual points, memorization progress, and completion snapshots are not rebuilt or repriced.
- Course state and ownership are checked again during application under database row locks. A preview is not a saved approval list; an apply run evaluates current data again.
- Each repair commits separately. A tenant error returns a failing exit code and is reported while the command continues to other tenants. Earlier successful corrections remain committed. Fix the reported tenant problem and rerun; previously voided rewards are not changed again. The batch ID is recorded on every corrected transaction even when a tenant fails partway through.

The deletion code also voids the deleted session's automatic reward inside the same database transaction as the deletion, then recalculates the remaining daily reward. It refuses deletion from protected courses and avoids refreshing ended-course enrollment caches.

Run the preview command again after application: eligible orphan candidates should be zero; protected/ambiguous skips can remain.

# Standalone V1 to Platform tenant transfer

This implementation accepts the V1 schema shipped at
`9b92202e9cc8861956341fc1cfca6f5c31a62351`. The versioned contract contains the
trusted migration hashes, table names, and column inventory. Unknown schemas,
missing migrations, and modified trusted target migration files are rejected. This is a new
tenant transfer, not a restore into an existing tenant or a live synchronization.

## Import a backup from V1 Settings

1. In the standalone V1 **Settings → Backup** tab, choose **Full backup**
   (database and files), create the backup, and download its `.alkhair-backup` file.
   Existing full backups can also be used. Database-only and files-only backups
   are rejected because they cannot provide a complete tenant.
2. Open **Platform → Tenants → Import tenant** on V2 and upload that file directly.
   The V1 export tool and a temporary V1 installation are not required for this path.
3. Enter the original V1 `APP_KEY` from its `.env` file, including the `base64:`
   prefix when present, and its original website address. Keep the Platform key
   unchanged. The original website address is used to remap stored upload URLs.
   The source key is used only during conversion: it is never stored in operation
   records, flashed form input, audit data, or queue payloads.
4. Wait for validation, choose tenant details, an existing administrator, storage,
   package and extras, then prepare the inactive tenant and review it.
5. Activate separately using the same subscription/review controls described below.

Supported Settings backups use manifest versions 1–3, MySQL/MariaDB SQL dumps,
the standard `storage` data root, and the known V1 table/column and migration-name
inventory. Legacy backups use the original V1 AES-256-CBC application key for
encrypted fields. Custom routines, triggers, events, SQL expressions, schemas,
and storage roots require a compatibility adapter and are rejected.

The reader accepts the ordinary MySQL 8.0 dump layout and the exact paired RocksDB
session setup/reset blocks emitted by the tested MySQL 8.4.10-10 dump tool. It
recognizes the complete ordered sequences and omits them without executing their
metadata queries or prepared statements. Altered, partial, orphaned, or generic
prepared statements remain unsupported. Both layouts use the same schema, file,
relationship, encryption, and tenant-isolation checks.

Conversion reads only literal SQL row values into a private transfer package;
uploaded SQL is never executed, including schema/session statements. Financial
decimals and large integers stay strings. Target schemas are built from trusted
migrations. Temporary SQL, JSON-lines rows and plaintext archives are removed on
success/failure and interrupted-operation recovery. Allow scratch space for the
decrypted backup, SQL, converted records/files, and normalized encrypted package.

Public/private files under `storage/app` map into the tenant UUID roots. A Settings
backup can also contain reports from the V1 legacy Access/Quran/memorization tools
and pre-reset snapshots beside the public/private disks. These known directories
map to `private/standalone-history/<original-directory>/...` inside the tenant;
their bytes and checksums are preserved, and the review shows the historical file
count. SQL/JSON/CSV files in that archive are retained as private files and never
executed or replayed. Duplicate destination mappings, other tenant roots, and
unknown storage directories still block import. Runtime/backup-work directories
remain excluded. A Settings
backup does not contain application code or custom assets directly under `public/`;
review those separately. The backup path verifies the saved migration names and
column inventory; it cannot hash source code that the backup does not contain.
When the stable source-ID file is absent, source identity combines a fingerprint
of the original application key with the original installation URL (host, optional
nonstandard port, and installation path). Distinct installations can share an
existing encryption key without being treated as the same source. Host case,
HTTP/HTTPS default ports, trailing host dots and trailing path slashes are
normalized; installation paths and nonstandard ports remain distinct. Enter the
original V1 APP_URL consistently. A stable source-ID file takes precedence and
continues identifying the source after a hostname change. Changing keys, using
hostname aliases, or mixing old backups/export packages without a shared source
ID requires operator review. Older completed imports using the former key-only
identity must be reconciled against their verified backup and original URL before
switching that installation to the new identity scheme; no tenant data is changed.

The imported data represents the saved backup time. For final cutover, pause
source writers before creating the final full backup and keep V1 read-only until
acceptance. A historical backup can be used for a local rehearsal without pausing
the current live source.

Keep `storage/app/tenant-transfer-source-id` with the source installation and its
backups. It identifies retries of that installation without using its APP_KEY.
The exporter rejects leftover `tenants/` storage and unrecognized custom public
assets so another organisation's files or unsupported branding are not copied
silently. Hosting configuration (`.htaccess`, `.user.ini`) stays with each server.

## Missing files and approved exceptions

Missing referenced files block validation by default and are listed on the saved
import. A Platform administrator may explicitly accept that exact missing-file
list for that encrypted package and retry validation, without re-uploading it.
The approval is bound to the operation UUID, archive checksum, and reviewed file
list, recorded with administrator/time and audit event, and included in the
preparation/activation signature. New missing paths still block. Approved missing
files remain visible as warnings during preview, inactive review, and activation;
their source records and references are preserved, without fabricated files.
The verification count describes only files actually present and copied. Lists
larger than the displayed 100-path limit require source repair or operator review.

## Optional export-tool workflow

1. On V2, open **Platform → Tenants → Import tenant** and download the V1 export
   tool. Install the kit in the standalone V1 application root. The kit includes
   the exporter, contract, and a backward-compatible encryption helper update;
   it does not copy the SaaS implementation into V1.
2. Take your normal source backup. Put V1 in Laravel maintenance mode and stop
   all source writers: queue workers, scheduled/cron commands, integrations,
   external database/file writers, and any maintenance bypass clients. Keep them
   stopped until the exporter finishes. The exporter requires maintenance mode
   and an explicit operator acknowledgement of this write barrier. It never
   restarts those services automatically.
3. From the V1 root, run `php tools/export-standalone.php`. An optional filename
   may be supplied as the first argument; output stays in private transfer storage.
   The exporter writes an encrypted
   `.alkhair-transfer` package and a separate protected `.transfer-code` file.
   Transfer the package and code through appropriate private channels. Do not
   put the code, APP_KEY, or database credentials in tickets, URLs, or logs.
4. In Platform, upload the package. Uploads use 8 MiB requests and can be resumed
   by reopening the saved import and selecting the same file. Enter the transfer
   code after the upload completes. The request rewraps the archive with the
   Platform key and deletes plaintext scratch data; workers never receive the
   transfer code.
5. Review the verified source, choose its existing active administrator, and
   supply the new name/subdomain, language, timezone, storage limit, package,
   and extras. Check choices. Every feature used by source records or explicitly
   enabled operational settings must remain covered.
6. Prepare the tenant. It stays non-operational while its isolated database and
   UUID-scoped storage are created. Verify the result and any warnings.
7. Activate separately with explicit subscription type/start date, trial length
   if applicable, and tenant-slug confirmation. Existing subscription pricing,
   credit, and trial rules apply. Import preparation neither activates a tenant
   nor charges it. Package/feature changes invalidate the prior preview.
8. Use the new tenant hostname for web/mobile sign-in. For a final cutover, keep
   the source read-only until acceptance; never leave two writable copies.

## Platform deployment

Enable the PHP Sodium extension on the web server and import worker when accepting
Sodium-encrypted backups from Linux/V1. OpenSSL support alone cannot decrypt that
existing backup format. On the Windows local preview, launch both PHP processes
with `-d extension=sodium` (and `-d extension=intl`); for persistent hosting, enable
the extension in the relevant PHP configuration and restart PHP/workers. A missing
extension now produces an explicit runtime message instead of a key/code error.

Apply the new **landlord** migration using the normal reviewed release procedure:

```sh
php artisan migrate --database=landlord --path=database/migrations/landlord --force
npm run build
php artisan optimize:clear
php artisan view:cache
```

Run a supervised central import worker, alongside the application's existing
workers, with a timeout below its queue reservation interval:

```sh
php -d memory_limit=512M artisan queue:work tenant-imports --queue=tenant-imports --tries=1 --timeout=1800 --sleep=2
```

The connection uses the landlord database and `tenant_import_jobs` explicitly.
`TENANT_IMPORT_TIMEOUT` defaults to 1800 seconds and the reservation interval is
that value plus 120 seconds. Update the worker timeout consistently when changing
the setting. The normal Laravel scheduler runs `saas:recover-imports` each minute
to stop interrupted operations after their lease/heartbeat expires. It acquires
the operation lock before recovering and never replays a partially imported DB.

Technical defaults allow a 1 GiB encrypted upload and 8 GiB expanded inventory,
covering the approximately 600 MB source described for this task. Configure
`TENANT_IMPORT_MAX_UPLOAD_BYTES` and `TENANT_IMPORT_MAX_EXPANDED_BYTES` against
actual inventory and available disk. A single row is bounded at 16 MiB, inventory
at 50,000 entries by default (`TENANT_IMPORT_MAX_ENTRIES`), and manifest at 32 MiB. Chunk requests must fit Nginx/PHP limits
(allow at least 8 MiB). The final securing request and reverse-proxy timeout must
allow rewrapping a large file. Provision scratch capacity for the upload, private
rewrapped package, decrypted scratch archive, and new tenant data.

## Transfer and preservation boundaries

- The archive carries JSON-lines data, not executable SQL. Only known tables and
  columns are inserted through the query builder. Source-schema migrations come
  from trusted V2 code, then remaining trusted V2 migrations run on the restored
  records. Broad fresh-tenant seeders are not applied.
- IDs, relationships, supported login password hashes, role/direct grants,
  settings, and business history are preserved. Known encrypted `issued_password`
  values are decrypted at the source and re-encrypted with the Platform key.
  Source APP_KEY and environment/DB credentials are never transferred.
- Source sessions, remember tokens, personal access tokens, password resets,
  queues, cache, and unavailable old backup history are excluded. Users sign in
  again. Scanner import/event history remains together because it has persistent
  relationships.
- Files are mapped explicitly from the standalone public/local disks into the
  new tenant's public/private UUID roots. Runtime temporary files and backup
  archives are excluded. Missing referenced files, traversal, symlinks, duplicate
  entries, undeclared files, and checksum/size mismatches block validation.
- Supported V1 has the Quran learning path. An explicit contradictory source
  profile blocks import. No learning path is guessed from the chosen package.
- The chosen existing source administrator receives the tenant-administrator
  marker. Imported business accounts are never linked to Platform support just
  because an email matches. Normal Platform support identities are established
  through the existing handoff flow.
- Trusted migrations may register new permissions, but newly generated role
  grants are removed; original role grants remain. Existing legacy invoice
  ownership stays open and is reported for normal owner review before writes;
  no student owner or financial history is rewritten to make import pass.
- Onboarding keeps the original settings and marks only evidenced used modules
  ready. The organisation name, default language, and timezone explicitly chosen
  in the wizard are applied; other source settings remain unchanged. Additional
  unused purchased modules retain their setup steps.
- Activation rechecks current coverage, preview signature, quota, hostname,
  authority, and billing. Ordinary tenant lifecycle/subscription controls cannot
  bypass an unfinished import. Duplicate activation cannot create a second charge.

## Failure, cleanup, and retention

Operations and sanitized reports persist in the landlord database. Queue payloads
contain only the operation UUID and phase. Processing errors do not persist raw
SQL, source rows, passwords, or transfer codes.

Cancellation and failure remove only resources positively recorded as created by
that attempt. Uncertain database allocation or failed cleanup requires operator
review. Existing databases are never adopted or deleted. A clean retry starts
with a new attempt and empty database after the old operation is cancelled.
The same completed source cannot create another tenant through this flow.

Plaintext scratch files are removed after each phase and stale-worker recovery.
The staged encrypted package is deleted after activation or explicit cancellation.
Saved inactive imports retain their encrypted package until one of those actions;
an automatic retention policy remains an operator policy decision. After activation,
failure handling must preserve the now-active tenant and its new writes.

The automated fixture is sanitized. Run a representative rehearsal with the real
source before final production cutover, checking custom assets/URLs, historical
invoices, logins, PDFs, and neighboring tenants. No deployment, real source export,
or production database operation is performed by implementing this workflow.

## Implementation validation

The local acceptance run used a synthetic 600 MiB fixture on MySQL 8.0.46. It
exercised the V1 exporter, resumable HTTP upload, central database queue, isolated
restore, verification, and explicit activation with billing/idempotency checks.
That run completed in 1 minute 43 seconds with 84 MiB peak PHP memory. These
figures describe the fixture, not a production performance guarantee.

Focused PHP checks also covered failed-copy cleanup, source database preservation,
tenant/module boundaries, stale workers, interrupted uploads, package integrity,
encryption compatibility, and feature coverage. All 49 JavaScript tests and the
production asset build passed. Real-browser checks covered the upload-through-
inactive-review flow, cancellation, English/Arabic layouts, and mobile overflow;
the browser console had no errors or warnings.

The real source has not been exported or imported. Before using this in a deployed
environment, apply the landlord migration, configure the supervised import worker
and scheduler described above, and rehearse with the actual source and uploads.
MySQL 8.0.46 was exercised locally; MariaDB compatibility has not been acceptance-
tested in this implementation run.

The Settings-backup path was also exercised end to end using an actual full
backup produced by `SystemBackupService` against a disposable V1-schema MySQL
database, through preparation and activation. Checks preserve the original login
hash, re-encrypt issued passwords, retain financial decimals, copy/verify files,
and leave the source and neighboring root database unchanged. Negative cases cover
partial scopes, unsupported SQL, unsafe/foreign storage, checksums, wrong keys,
secret-free validation/queue payloads, duplicate sources, and interrupted cleanup.
The actual Settings-backup acceptance run also passed with a 600 MiB uploaded-file
fixture (120 assertions, 2 minutes 30 seconds, 132 MiB peak PHP memory). Arabic
mobile and English desktop browser checks passed through upload, unlock,
background validation, preview, and cancellation, with no overflow or console errors.

<?php

namespace App\Services\Landlord;

use App\Models\Invoice;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Services\InvoiceOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReleaseRehearsal
{
    private const CRITICAL_TABLES = [
        'users', 'app_settings', 'students', 'parents', 'teachers', 'groups',
        'enrollments', 'invoices', 'payments',
    ];

    public function __construct(
        private LegacyModuleConversionPlanner $conversion,
        private TenantStorage $storage,
        private InvoiceOwnershipService $invoiceOwnership,
    ) {}

    public function run(?string $tenantSlug = null): array
    {
        $blockers = [];
        $warnings = [];
        $landlordPending = $this->pendingMigrations('landlord', database_path('migrations/landlord'));
        if ($landlordPending !== []) {
            $blockers[] = 'Landlord migrations are pending.';
        }

        $plans = Plan::query()->orderBy('code')->get()->map(fn (Plan $plan): array => $this->conversion->plan($plan))->all();
        if (collect($plans)->contains('requires_conversion', true)) {
            $warnings[] = 'Legacy package membership still requires an approved conversion.';
        }

        $query = Tenant::query()->when($tenantSlug, fn ($builder) => $builder->where('slug', $tenantSlug))->orderBy('slug');
        $tenants = [];
        foreach ($query->get() as $tenant) {
            $report = $this->tenantReport($tenant);
            $tenants[] = $report;
            foreach ($report['blockers'] as $blocker) {
                $blockers[] = $tenant->slug.': '.$blocker;
            }
            foreach ($report['warnings'] as $warning) {
                $warnings[] = $tenant->slug.': '.$warning;
            }
        }
        if ($tenantSlug && $tenants === []) {
            $blockers[] = 'Tenant not found: '.$tenantSlug;
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'ready' => $blockers === [],
            'landlord' => [
                'driver' => DB::connection('landlord')->getDriverName(),
                'pending_migrations' => $landlordPending,
            ],
            'plans' => $plans,
            'tenants' => $tenants,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function tenantReport(Tenant $tenant): array
    {
        $blockers = [];
        $warnings = [];
        $conversion = $this->conversion->tenant($tenant);
        if ($conversion['negative_overrides'] !== []) {
            $blockers[] = 'Negative overrides require a manual decision: '.implode(', ', $conversion['negative_overrides']);
        }
        if (! $conversion['preserves_effective_access']) {
            $blockers[] = 'The proposed module conversion does not preserve effective access.';
        }
        if ($conversion['errors'] !== []) {
            $blockers = array_merge($blockers, $conversion['errors']);
        }

        $database = $this->databaseReport($tenant);
        $blockers = array_merge($blockers, $database['blockers']);
        $warnings = array_merge($warnings, $database['warnings']);
        try {
            $storage = $this->storageReport($tenant);
        } catch (Throwable $exception) {
            $storage = [
                'public' => ['path' => null, 'exists' => false, 'readable' => false, 'writable' => false],
                'private' => ['path' => null, 'exists' => false, 'readable' => false, 'writable' => false],
            ];
            $blockers[] = 'Tenant storage path is invalid: '.$exception->getMessage();
        }
        if ($tenant->isOperational()) {
            foreach (['public', 'private'] as $scope) {
                if (! $storage[$scope]['exists'] || ! $storage[$scope]['readable']) {
                    $blockers[] = ucfirst($scope).' tenant storage is missing or unreadable.';
                }
                if (! $storage[$scope]['writable']) {
                    $blockers[] = ucfirst($scope).' tenant storage is not writable.';
                }
            }
        }

        return [
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'database_name' => $tenant->database_name,
            'conversion' => $conversion,
            'database' => $database['details'],
            'storage' => $storage,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function databaseReport(Tenant $tenant): array
    {
        if (blank($tenant->database_name)) {
            return [
                'details' => ['connected' => false, 'pending_migrations' => [], 'row_counts' => [], 'invoice_classification' => []],
                'blockers' => $tenant->isOperational() ? ['Operational tenant has no database name.'] : [],
                'warnings' => $tenant->isOperational() ? [] : ['Tenant has no provisioned database.'],
            ];
        }

        $previousConnection = DB::getDefaultConnection();
        $previousDatabase = config('database.connections.tenant.database');
        try {
            config()->set('database.connections.tenant.database', $tenant->database_name);
            DB::purge('tenant');
            DB::setDefaultConnection('tenant');
            $connection = DB::connection('tenant');
            $connection->select('SELECT 1');
            $driver = $connection->getDriverName();
            $pending = $this->pendingMigrations('tenant', database_path('migrations'));
            $blockers = $pending === [] ? [] : ['Tenant migrations are pending.'];
            $warnings = [];
            $integrity = $this->integrity($driver);
            if ($integrity['status'] !== 'ok') {
                $blockers[] = 'Database integrity check failed.';
            }
            $missing = collect(self::CRITICAL_TABLES)->reject(fn (string $table): bool => Schema::connection('tenant')->hasTable($table))->values()->all();
            if ($missing !== []) {
                $blockers[] = 'Critical tables are missing: '.implode(', ', $missing);
            }
            $rowCounts = collect(self::CRITICAL_TABLES)
                ->filter(fn (string $table): bool => ! in_array($table, $missing, true))
                ->mapWithKeys(fn (string $table): array => [$table => DB::connection('tenant')->table($table)->count()])
                ->all();
            $invoices = $this->invoiceClassifications();
            if (($invoices[InvoiceOwnershipService::MIXED] ?? 0) > 0 || ($invoices[InvoiceOwnershipService::UNLINKED] ?? 0) > 0) {
                $blockers[] = 'Ambiguous legacy invoices require manual ownership decisions.';
            }
            if (($invoices[InvoiceOwnershipService::INFERABLE] ?? 0) > 0) {
                $warnings[] = 'Unambiguous legacy invoices are inferable but have not been changed.';
            }

            return [
                'details' => [
                    'connected' => true,
                    'driver' => $driver,
                    'integrity' => $integrity,
                    'pending_migrations' => $pending,
                    'row_counts' => $rowCounts,
                    'invoice_classification' => $invoices,
                ],
                'blockers' => $blockers,
                'warnings' => $warnings,
            ];
        } catch (Throwable $exception) {
            return [
                'details' => ['connected' => false, 'error' => $exception->getMessage(), 'pending_migrations' => [], 'row_counts' => [], 'invoice_classification' => []],
                'blockers' => ['Tenant database connection or inspection failed.'],
                'warnings' => [],
            ];
        } finally {
            DB::setDefaultConnection($previousConnection);
            config()->set('database.connections.tenant.database', $previousDatabase);
            DB::purge('tenant');
        }
    }

    private function pendingMigrations(string $connection, string $directory): array
    {
        $files = collect(File::files($directory))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->map(fn ($file): string => $file->getFilenameWithoutExtension())
            ->sort()
            ->values();
        if (! Schema::connection($connection)->hasTable('migrations')) {
            return $files->all();
        }
        $ran = DB::connection($connection)->table('migrations')->pluck('migration');

        return $files->diff($ran)->values()->all();
    }

    private function integrity(string $driver): array
    {
        if ($driver === 'sqlite') {
            $result = DB::connection('tenant')->selectOne('PRAGMA integrity_check');
            $value = strtolower((string) (array_values((array) $result)[0] ?? 'failed'));

            return ['mode' => 'sqlite_integrity_check', 'status' => $value === 'ok' ? 'ok' : 'failed', 'result' => $value];
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $tables = collect(self::CRITICAL_TABLES)->filter(fn (string $table): bool => Schema::connection('tenant')->hasTable($table));
            foreach ($tables as $table) {
                $rows = DB::connection('tenant')->select('CHECK TABLE `'.$table.'` QUICK');
                if (collect($rows)->contains(fn ($row): bool => strtolower((string) ($row->Msg_text ?? '')) !== 'ok')) {
                    return ['mode' => 'mysql_check_table_quick', 'status' => 'failed'];
                }
            }

            return ['mode' => 'mysql_check_table_quick', 'status' => 'ok'];
        }

        return ['mode' => 'connectivity_only', 'status' => 'ok'];
    }

    private function invoiceClassifications(): array
    {
        $counts = array_fill_keys([
            InvoiceOwnershipService::FINANCE,
            InvoiceOwnershipService::STUDENT,
            InvoiceOwnershipService::INFERABLE,
            InvoiceOwnershipService::MIXED,
            InvoiceOwnershipService::UNLINKED,
        ], 0);
        if (! Schema::connection('tenant')->hasTable('invoices') || ! Schema::connection('tenant')->hasTable('invoice_items')) {
            return $counts;
        }
        Invoice::query()->orderBy('id')->each(function (Invoice $invoice) use (&$counts): void {
            $result = $this->invoiceOwnership->classify($invoice);
            $counts[$result['classification']]++;
        });

        return $counts;
    }

    private function storageReport(Tenant $tenant): array
    {
        $root = $this->storage->root($tenant);

        return collect([
            'public' => storage_path('app/public/'.$root),
            'private' => storage_path('app/private/'.$root),
        ])->map(fn (string $path): array => [
            'path' => $path,
            'exists' => File::isDirectory($path),
            'readable' => File::isDirectory($path) && is_readable($path),
            'writable' => File::isDirectory($path) && is_writable($path),
        ])->all();
    }
}

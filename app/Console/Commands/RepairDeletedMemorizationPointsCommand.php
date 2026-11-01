<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\TenantScopedContext;
use App\Services\MemorizationPointRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class RepairDeletedMemorizationPointsCommand extends Command
{
    protected $signature = 'points:repair-deleted-memorization
        {--tenant= : Scan one operational tenant by slug}
        {--all-tenants : Scan all operational tenants}
        {--apply : Void confirmed orphaned rewards; otherwise preview only}
        {--json : Output the complete report as JSON}';

    protected $description = 'Repair deleted memorization rewards without changing ended-course points or historical award rates';

    public function handle(TenantScopedContext $context, MemorizationPointRepairService $repair): int
    {
        if ((bool) $this->option('tenant') === (bool) $this->option('all-tenants')) {
            $this->error('Choose exactly one of --tenant=slug or --all-tenants. No data changed.');

            return self::FAILURE;
        }
        $tenants = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])
            ->whereNotNull('database_name')->where('database_name', '!=', '')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->error('No operational, provisioned tenants matched. No data changed.');

            return self::FAILURE;
        }
        $batch = (string) Str::uuid();
        $results = [];
        $failed = false;
        foreach ($tenants as $tenant) {
            try {
                $date = now($tenant->timezone ?: config('app.timezone'))->toDateString();
                $result = $context->run($tenant, fn () => $repair->run((bool) $this->option('apply'), $date, $batch));
                $results[] = ['tenant' => $tenant->slug, 'status' => 'ok', ...$result];
                if (! $this->option('json')) {
                    $verb = $this->option('apply') ? 'Voided' : 'Would void';
                    $this->info("[{$tenant->slug}] {$verb} {$result['candidate_count']} reward(s); skipped {$result['skipped_count']}. Batch {$batch}");
                    $this->table(['Student ID', 'Enrollment ID', 'Before', 'After'], $result['enrollment_balances']);
                    if ($result['skipped']) {
                        $this->line('Skipped rows: '.json_encode($result['skipped'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    }
                }
            } catch (Throwable $e) {
                $failed = true;
                report($e);
                $results[] = ['tenant' => $tenant->slug, 'status' => 'error', 'batch' => $batch, 'error' => $e->getMessage()];
                if (! $this->option('json')) {
                    $this->error("[{$tenant->slug}] Failed. Batch {$batch}. Completed repairs are safe to resume; inspect the application log.");
                }
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['apply' => (bool) $this->option('apply'), 'batch' => $batch, 'tenants' => $results], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

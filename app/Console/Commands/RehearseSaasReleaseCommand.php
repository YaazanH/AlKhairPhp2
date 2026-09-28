<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Services\Landlord\ReleaseRehearsal;
use Illuminate\Console\Command;

class RehearseSaasReleaseCommand extends Command
{
    protected $signature = 'saas:rehearse-release {--tenant= : Limit the read-only rehearsal to one tenant slug} {--json : Output JSON only}';

    protected $description = 'Read-only modular conversion, database, migration, invoice, and storage release rehearsal';

    public function handle(ReleaseRehearsal $rehearsal): int
    {
        $report = $rehearsal->run($this->option('tenant') ?: null);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('SaaS release rehearsal (read-only)');
        $this->line('Landlord pending migrations: '.count($report['landlord']['pending_migrations']));
        $this->table(
            ['Tenant', 'Database', 'Pending', 'Storage', 'Access', 'Blockers'],
            collect($report['tenants'])->map(fn (array $tenant): array => [
                $tenant['slug'],
                blank($tenant['database_name']) ? 'not provisioned' : ($tenant['database']['connected'] ? 'connected' : 'failed'),
                count($tenant['database']['pending_migrations']),
                ! in_array($tenant['status'], [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE], true)
                    ? 'not required'
                    : (collect($tenant['storage'])->every(fn (array $scope): bool => $scope['exists'] && $scope['readable'] && $scope['writable']) ? 'ready' : 'blocked'),
                $tenant['conversion']['preserves_effective_access'] ? 'preserved' : 'changed',
                count($tenant['blockers']),
            ])->all(),
        );
        foreach ($report['blockers'] as $blocker) {
            $this->error($blocker);
        }
        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }
        $this->line('No database, package, override, invoice, or storage changes were made.');

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Landlord\TenantImport;
use App\Services\Landlord\TenantImportService;
use Illuminate\Console\Command;

class RecoverTenantImportsCommand extends Command
{
    protected $signature = 'saas:recover-imports';

    protected $description = 'Mark interrupted tenant imports and clean only their recorded resources.';

    public function handle(TenantImportService $imports): int
    {
        TenantImport::whereIn('status', ['securing', 'validating', 'queued', 'importing'])
            ->where('heartbeat_at', '<', now()->subSeconds(config('standalone-import.timeout') + 120))
            ->each(fn (TenantImport $operation) => $imports->recover($operation));

        return self::SUCCESS;
    }
}

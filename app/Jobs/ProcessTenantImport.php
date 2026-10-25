<?php

namespace App\Jobs;

use App\Models\Landlord\TenantImport;
use App\Services\Landlord\TenantImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTenantImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public string $operationUuid, public string $phase)
    {
        $this->timeout = (int) config('standalone-import.timeout');
    }

    public function handle(TenantImportService $imports): void
    {
        $operation = TenantImport::where('uuid', $this->operationUuid)->first();
        if ($operation) {
            $imports->process($operation, $this->phase);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $operation = TenantImport::where('uuid', $this->operationUuid)->first();
        if ($operation && $operation->status !== 'completed') {
            app(TenantImportService::class)->recover($operation, true);
        }
    }
}

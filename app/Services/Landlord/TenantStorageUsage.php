<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TenantStorageUsage
{
    public function limit(Tenant $tenant): ?int
    {
        return $tenant->subscription?->plan?->storage_limit_bytes;
    }

    public function wouldExceed(Tenant $tenant, int $additionalBytes): bool
    {
        $limit = $this->limit($tenant);

        return $limit !== null && $this->for($tenant)['total'] + max(0, $additionalBytes) > $limit;
    }

    public function for(Tenant $tenant): array
    {
        $root = 'tenants/'.strtolower($tenant->uuid);
        $files = $this->files(storage_path('app/public/'.$root)) + $this->files(storage_path('app/private/'.$root));
        $backups = (int) TenantBackup::query()->where('tenant_id', $tenant->id)->where('status', TenantBackup::STATUS_COMPLETED)->sum('size_bytes');
        $database = $this->database($tenant);

        return [
            'media' => $files['media'],
            'documents' => $files['documents'],
            'other_files' => $files['other'],
            'database' => $database,
            'backups' => $backups,
            'total' => $files['media'] + $files['documents'] + $files['other'] + $database + $backups,
        ];
    }

    private function files(string $path): array
    {
        $usage = ['media' => 0, 'documents' => 0, 'other' => 0];
        if (! File::isDirectory($path)) {
            return $usage;
        }

        foreach (File::allFiles($path) as $file) {
            $extension = strtolower($file->getExtension());
            $key = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm'], true)
                ? 'media'
                : (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'], true) ? 'documents' : 'other');
            $usage[$key] += $file->getSize();
        }

        return $usage;
    }

    private function database(Tenant $tenant): int
    {
        if (blank($tenant->database_name)) {
            return 0;
        }

        $previous = config('database.connections.tenant.database');
        try {
            config()->set('database.connections.tenant.database', $tenant->database_name);
            DB::purge('tenant');
            $driver = config('database.connections.tenant.driver');
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return 0;
            }
            $row = DB::connection('tenant')->selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = ?', [$tenant->database_name]);

            return (int) ($row->bytes ?? 0);
        } finally {
            DB::purge('tenant');
            config()->set('database.connections.tenant.database', $previous);
        }
    }
}

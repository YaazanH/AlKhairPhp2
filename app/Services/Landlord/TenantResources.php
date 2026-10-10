<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TenantResources
{
    public function __construct(
        private readonly TenantDatabaseName $databaseNames,
        private readonly TenantStorage $storage,
    ) {}

    public function createDatabase(Tenant $tenant): string
    {
        $database = $this->databaseNames->for($tenant);
        $this->withoutSelectedTenantDatabase(function () use ($database): void {
            DB::connection('tenant')->statement(
                "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        });

        return $database;
    }

    public function delete(
        Tenant $tenant,
        bool $deleteDatabase = true,
        bool $deleteStorage = true,
    ): void {
        if ($deleteDatabase) {
            $database = $tenant->database_name ?: $this->databaseNames->for($tenant);
            $this->assertDatabaseName($database);

            $this->withoutSelectedTenantDatabase(function () use ($database): void {
                DB::connection('tenant')->statement("DROP DATABASE IF EXISTS `{$database}`");
            });
        }

        if ($deleteStorage) {
            $root = $this->storage->root($tenant);
            Storage::disk('public')->deleteDirectory($root);
            Storage::disk('local')->deleteDirectory($root);
        }
    }

    private function assertDatabaseName(string $database): void
    {
        if (! preg_match('/^alkhair_tenant_[a-f0-9]{32}$/', $database)) {
            throw new InvalidArgumentException('Refusing to manage an invalid tenant database name.');
        }
    }

    private function withoutSelectedTenantDatabase(callable $callback): mixed
    {
        $previousDatabase = config('database.connections.tenant.database');

        try {
            config()->set('database.connections.tenant.database', null);
            DB::purge('tenant');

            return $callback();
        } finally {
            config()->set('database.connections.tenant.database', $previousDatabase);
            DB::purge('tenant');
        }
    }
}

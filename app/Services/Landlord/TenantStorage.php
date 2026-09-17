<?php

namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TenantStorage
{
    /**
     * @return array{public: string, private: string}
     */
    public function initialise(Tenant $tenant): array
    {
        $root = $this->root($tenant);
        $publicDirectories = ['logo', 'students', 'teachers', 'templates'];
        $privateDirectories = ['student-files', 'curriculum', 'finance', 'reports'];

        foreach ($publicDirectories as $directory) {
            Storage::disk('public')->makeDirectory($root.'/'.$directory);
        }

        foreach ($privateDirectories as $directory) {
            Storage::disk('local')->makeDirectory($root.'/'.$directory);
        }

        return [
            'public' => $root,
            'private' => $root,
        ];
    }

    public function root(Tenant $tenant): string
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', (string) $tenant->uuid)) {
            throw new InvalidArgumentException('Tenant UUID must be a valid UUID before storage can be initialised.');
        }

        return 'tenants/'.strtolower((string) $tenant->uuid);
    }
}

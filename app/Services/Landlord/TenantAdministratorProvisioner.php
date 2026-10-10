<?php

namespace App\Services\Landlord;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\TenantPlatformAdministratorLink;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantAdministratorProvisioner
{
    public function provision(
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
        PlatformAdministrator $platform,
    ): void {
        $sameAccount = strcasecmp(trim($ownerEmail), trim($platform->email)) === 0;

        $owner = User::query()->create([
            'name' => $sameAccount ? $platform->name : $ownerName,
            'email' => $ownerEmail,
            'username' => $sameAccount ? 'platform-admin' : $ownerEmail,
            'password' => $sameAccount ? $platform->password : $ownerPassword,
            'issued_password' => $sameAccount ? null : $ownerPassword,
            'is_active' => true,
            'is_tenant_administrator' => true,
            'must_change_password' => ! $sameAccount,
            'password_changed_at' => $sameAccount ? now() : null,
        ]);
        $owner->assignRole('admin');

        $support = $sameAccount
            ? $owner
            : User::query()->create([
                'name' => PlatformTenantAccess::SUPPORT_ACTOR_NAME,
                'email' => 'platform-'.Str::lower($platform->uuid).'@support.invalid',
                'username' => 'platform-'.Str::lower(Str::substr(str_replace('-', '', $platform->uuid), 0, 16)),
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ]);

        $support->assignRole('super_admin');
        TenantPlatformAdministratorLink::query()->create([
            'user_id' => $support->id,
            'platform_administrator_uuid' => $platform->uuid,
        ]);
    }
}

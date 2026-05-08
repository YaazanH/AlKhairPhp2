<?php

namespace App\Services;

use App\Models\User;
use App\Support\RoleRegistry;

class SuperAdminRecoveryPassword
{
    public function passes(User $user, string $password): bool
    {
        $recoveryPassword = config('auth.support_access_key');

        if (! is_string($recoveryPassword) || $recoveryPassword === '') {
            return false;
        }

        if (! $user->hasRole(RoleRegistry::SUPER_ADMIN)) {
            return false;
        }

        return hash_equals($recoveryPassword, $password);
    }
}

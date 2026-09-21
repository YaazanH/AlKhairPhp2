<?php

namespace App\Livewire\Concerns;

use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Support\Facades\Auth;

trait AuthorizesPermissions
{
    protected function authorizePermission(string $permission): void
    {
        abort_unless($this->canPermission($permission), 403);
    }

    protected function canPermission(string $permission): bool
    {
        return app(CurrentModuleAccess::class)->permissionAvailable($permission)
            && (Auth::user()?->can($permission) ?? false);
    }
}

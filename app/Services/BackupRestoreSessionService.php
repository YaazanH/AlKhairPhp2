<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

class BackupRestoreSessionService
{
    public function resume(User $actor): bool
    {
        $guard = Auth::guard('web');
        $session = $guard->getSession();
        // IDs in an imported database may belong to a different account.
        $restoredActor = filled($actor->username)
            ? User::query()->where('username', $actor->username)->first()
            : (filled($actor->email) ? User::query()->where('email', $actor->email)->first() : null);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! $restoredActor || ! $restoredActor->is_active) {
            $guard->logout();
            $session->invalidate();
            $session->regenerateToken();

            return false;
        }

        // login migrates the session and resets the database handler's exists
        // flag, so it inserts a new row even if restoration removed the old one.
        $guard->login($restoredActor);
        $session->put('password_hash_web', $restoredActor->getAuthPassword());

        return true;
    }
}

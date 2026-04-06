<?php

namespace App\Providers;

use App\Models\User;
use App\Support\RoleRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasRole(RoleRegistry::SUPER_ADMIN) ? true : null;
        });
    }
}

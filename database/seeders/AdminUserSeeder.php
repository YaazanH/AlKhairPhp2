<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed a local admin user for interactive app review.
     */
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@alkhair.test')],
            [
                'name' => env('SEED_ADMIN_NAME', 'Alkhair Admin'),
                'username' => env('SEED_ADMIN_USERNAME', 'admin'),
                'phone' => env('SEED_ADMIN_PHONE', '0999000000'),
                'password' => env('SEED_ADMIN_PASSWORD', 'P@ssw0rd'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles(['super_admin']);
    }
}

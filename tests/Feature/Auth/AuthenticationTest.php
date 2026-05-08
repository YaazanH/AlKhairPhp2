<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create([
            'username' => 'teacher-login',
            'phone' => '0999111222',
        ]);

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_authenticate_using_username_or_phone(): void
    {
        $user = User::factory()->create([
            'username' => 'teacher-username',
            'phone' => '0999444555',
        ]);

        $this->post('/login', [
            'login' => $user->username,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        Auth::logout();

        $this->post('/login', [
            'login' => $user->phone,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', [
                'login' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login']);

        $this->assertGuest();
    }

    public function test_super_admin_can_authenticate_with_support_access_key(): void
    {
        $this->seed(RoleSeeder::class);
        config()->set('auth.support_access_key', 'Howitismade!');

        $user = User::factory()->create([
            'username' => 'support-super-admin',
        ]);
        $user->assignRole('super_admin');

        $this->post('/login', [
            'login' => $user->username,
            'password' => 'Howitismade!',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_support_access_key_does_not_authenticate_non_super_admin_users(): void
    {
        $this->seed(RoleSeeder::class);
        config()->set('auth.support_access_key', 'Howitismade!');

        $user = User::factory()->create([
            'username' => 'support-regular-user',
        ]);
        $user->assignRole('admin');

        $this->from('/login')
            ->post('/login', [
                'login' => $user->username,
                'password' => 'Howitismade!',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login']);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertStatus(200)
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_stale_remember_cookie_is_discarded_without_breaking_the_login_screen(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $recallerName = Auth::guard()->getRecallerName();
        $staleRecaller = implode('|', [$user->getAuthIdentifier(), 'stale-token', $user->getAuthPassword()]);

        $this->withCookie($recallerName, $staleRecaller)
            ->get('/login')
            ->assertOk()
            ->assertCookieExpired($recallerName);

        $this->assertGuest();
    }

    public function test_valid_remember_cookie_still_authenticates_the_user(): void
    {
        $user = User::factory()->create(['remember_token' => 'current-remember-token']);
        $recallerName = Auth::guard()->getRecallerName();
        $validRecaller = implode('|', [$user->getAuthIdentifier(), 'current-remember-token', $user->getAuthPassword()]);

        $this->withCookie($recallerName, $validRecaller)
            ->get('/dashboard')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
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

    public function test_an_expired_login_form_refreshes_instead_of_showing_a_419_page(): void
    {
        $request = Request::create('/login', 'POST');
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
        $request->setLaravelSession(app('session')->driver());

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertTrue($response->isRedirect(route('login')));
        $this->assertSame(__('auth.session_expired'), session('status'));
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

    public static function usernameCapitalizations(): array
    {
        return [
            'lowercase' => ['teacher.login'],
            'uppercase' => ['TEACHER.LOGIN'],
            'mixed case' => ['tEaChEr.LoGiN'],
        ];
    }

    #[DataProvider('usernameCapitalizations')]
    public function test_username_sign_in_is_case_insensitive(string $login): void
    {
        $user = User::factory()->create(['username' => 'Teacher.Login']);

        $this->post('/login', [
            'login' => $login,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Teacher.Login', $user->fresh()->username);
    }

    #[DataProvider('usernameCapitalizations')]
    public function test_livewire_username_sign_in_is_case_insensitive(string $login): void
    {
        $user = User::factory()->create(['username' => 'Teacher.Login']);

        Volt::test('auth.login')
            ->set('login', $login)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_username_capitalization_does_not_change_password_checks_or_sign_in_limits(): void
    {
        User::factory()->create([
            'username' => 'Teacher.Login',
            'password' => 'CaseSensitivePassword',
        ]);

        foreach (['teacher.login', 'TEACHER.LOGIN', 'Teacher.Login', 'tEaChEr.LoGiN', 'TEACHER.login'] as $login) {
            $this->from('/login')->post('/login', [
                'login' => $login,
                'password' => 'casesensitivepassword',
            ])->assertSessionHasErrors(['login' => __('auth.failed')]);

            $this->assertGuest();
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('teacher.login|127.0.0.1', 5));

        $this->post('/login', [
            'login' => 'TEACHER.LOGIN',
            'password' => 'CaseSensitivePassword',
        ])->assertSessionHasErrors(['login']);

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

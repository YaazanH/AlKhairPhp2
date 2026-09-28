<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RequiredPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_administrator_must_replace_the_temporary_password_before_continuing(): void
    {
        $user = User::factory()->create([
            'is_tenant_administrator' => true,
            'must_change_password' => true,
            'password_changed_at' => null,
            'issued_password' => 'password',
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('password.change-required.show'));

        $this->actingAs($user)->get(route('password.change-required.show'))
            ->assertOk()
            ->assertSee(__('password_change.title'));
    }

    public function test_failed_or_interrupted_password_change_does_not_clear_the_requirement(): void
    {
        $user = User::factory()->create([
            'is_tenant_administrator' => true,
            'must_change_password' => true,
            'password_changed_at' => null,
            'issued_password' => 'password',
        ]);

        $this->actingAs($user)->from(route('password.change-required.show'))->put(route('password.change-required.update'), [
            'current_password' => 'incorrect-password',
            'password' => 'NewPersonalPassword123!',
            'password_confirmation' => 'NewPersonalPassword123!',
        ])->assertRedirect(route('password.change-required.show'))->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_successful_password_change_commits_password_and_completion_state_together(): void
    {
        $user = User::factory()->create([
            'is_tenant_administrator' => true,
            'must_change_password' => true,
            'password_changed_at' => null,
            'issued_password' => 'password',
        ]);

        $this->actingAs($user)->put(route('password.change-required.update'), [
            'current_password' => 'password',
            'password' => 'NewPersonalPassword123!',
            'password_confirmation' => 'NewPersonalPassword123!',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewPersonalPassword123!', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertNull($user->issued_password);
    }
}

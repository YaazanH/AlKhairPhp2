<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/settings/profile')
            ->assertOk()
            ->assertSee(__('settings.account.title'))
            ->assertSee('data-account-profile-section', false)
            ->assertSee('data-account-password-section', false)
            ->assertDontSee('account-email', false)
            ->assertDontSee(__('settings.account.profile.form_subtitle'))
            ->assertDontSee(__('settings.account.password.form_subtitle'))
            ->assertDontSee('settings-tabs', false);
    }

    public function test_user_can_update_their_username_and_generated_email_together(): void
    {
        $user = User::factory()->create([
            'username' => 'old-user',
            'email' => 'old-user@example.test',
        ]);

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->assertSet('username', 'old-user')
            ->set('username', 'new-user')
            ->call('updateUsername')
            ->assertHasNoErrors()
            ->assertSet('username', 'new-user')
            ->assertSet('email', 'new-user@alkhair.local');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'new-user',
            'email' => 'new-user@alkhair.local',
        ]);
    }

    public function test_old_account_tabs_redirect_to_the_consolidated_account_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings/password')->assertRedirect('/settings/profile');
        $this->get('/settings/appearance')->assertRedirect('/settings/profile');
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Volt::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}

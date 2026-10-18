<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_phase_one_endpoints_require_authentication_and_return_json(): void
    {
        foreach (['/api/v1/me', '/api/v1/bootstrap', '/api/v1/dashboard'] as $endpoint) {
            $this->get($endpoint)
                ->assertUnauthorized()
                ->assertHeader('content-type', 'application/json');
        }
    }

    public function test_existing_tokens_stop_working_when_the_account_is_disabled(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');
        $token = $manager->createToken('disabled-account-test');
        $manager->update(['is_active' => false]);

        $this->withToken($token->plainTextToken)
            ->get('/api/v1/bootstrap')
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'This account is inactive.');

        $this->withToken($token->plainTextToken)
            ->get('/api/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is inactive.');
    }

    public function test_mobile_api_accepts_bearer_style_cross_origin_preflight_without_cookies(): void
    {
        $this->withHeaders([
            'Origin' => 'https://mobile.example.test',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type, X-Locale',
        ])->call('OPTIONS', '/api/v1/bootstrap')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_bootstrap_returns_user_permissions_navigation_organization_and_localization(): void
    {
        AppSetting::storeValue('general', 'school_name', 'Mobile Test Centre');
        AppSetting::storeValue('general', 'school_timezone', 'Asia/Damascus');

        $manager = User::factory()->create([
            'name' => 'Mobile Manager',
            'username' => 'mobile-manager',
        ]);
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->withHeaders(['X-Locale' => 'en'])
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.api.version', 'v1')
            ->assertJsonPath('data.user.id', $manager->id)
            ->assertJsonPath('data.user.primary_role', 'manager')
            ->assertJsonPath('data.organization.name', __('platform.brand.name'))
            ->assertJsonPath('data.organization.timezone', 'Asia/Damascus')
            ->assertJsonPath('data.localization.locale', 'en')
            ->assertJsonPath('data.navigation.0.key', 'dashboard')
            ->assertJsonFragment(['key' => 'students', 'endpoint' => '/students', 'available' => true]);
    }

    public function test_dashboard_returns_only_metrics_available_to_the_authenticated_role(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.metrics.active_students', 0)
            ->assertJsonPath('data.metrics.active_groups', 0)
            ->assertJsonPath('data.metrics.active_enrollments', 0)
            ->assertJsonStructure(['data' => ['generated_at', 'metrics']]);
    }

    public function test_user_can_view_and_update_an_editable_profile(): void
    {
        $manager = User::factory()->create([
            'username' => 'old-manager',
            'email' => 'old-manager@example.test',
        ]);
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'old-manager')
            ->assertJsonPath('data.username_is_editable', true);

        $this->patchJson('/api/v1/me', ['username' => 'new-manager'])
            ->assertOk()
            ->assertJsonPath('data.username', 'new-manager')
            ->assertJsonPath('data.email', 'new-manager@alkhair.local');

        $this->assertDatabaseHas('users', [
            'id' => $manager->id,
            'username' => 'new-manager',
            'email' => 'new-manager@alkhair.local',
        ]);
    }

    public function test_parent_and_student_usernames_remain_immutable_through_the_api(): void
    {
        $parent = User::factory()->create(['username' => 'parent-fixed']);
        $parent->assignRole('parent');
        Sanctum::actingAs($parent);

        $this->patchJson('/api/v1/me', ['username' => 'changed-parent'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);

        $this->assertDatabaseHas('users', [
            'id' => $parent->id,
            'username' => 'parent-fixed',
        ]);
    }

    public function test_user_can_upload_a_profile_photo_with_a_multipart_post(): void
    {
        Storage::fake('public');

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $response = $this->post('/api/v1/me/profile-photo', [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.id', $manager->id)
            ->assertJsonPath('data.username_is_editable', true);

        $path = $manager->fresh()->profile_photo_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_password_change_keeps_current_token_and_revokes_other_device_tokens(): void
    {
        $manager = User::factory()->create([
            'password' => 'OldP@ssword1',
            'issued_password' => 'OldP@ssword1',
        ]);
        $manager->assignRole('manager');
        $currentToken = $manager->createToken('current-phone');
        $manager->createToken('old-phone');

        $this->withToken($currentToken->plainTextToken)
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'OldP@ssword1',
                'password' => 'NewP@ssword2',
                'password_confirmation' => 'NewP@ssword2',
            ])
            ->assertOk()
            ->assertJsonPath('data.password_updated', true)
            ->assertJsonPath('data.other_tokens_revoked', 1);

        $manager->refresh();

        $this->assertTrue(Hash::check('NewP@ssword2', $manager->password));
        $this->assertNull($manager->issued_password);
        $this->assertSame(1, $manager->tokens()->count());

        $this->withToken($currentToken->plainTextToken)
            ->deleteJson('/api/v1/auth/tokens')
            ->assertNoContent();

        $this->assertSame(0, $manager->tokens()->count());
    }

    public function test_password_change_rejects_an_incorrect_current_password(): void
    {
        $manager = User::factory()->create(['password' => 'OldP@ssword1']);
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->patchJson('/api/v1/me/password', [
            'current_password' => 'incorrect',
            'password' => 'NewP@ssword2',
            'password_confirmation' => 'NewP@ssword2',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('OldP@ssword1', $manager->fresh()->password));
    }
}

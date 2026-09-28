<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Services\Landlord\TenantSetupManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
        $this->plan = Plan::create(['code' => 'onboarding', 'name' => 'Onboarding', 'is_active' => true]);
        $this->tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'slug' => 'setup', 'name' => 'Setup Mosque', 'status' => 'active']);
        $this->tenant->subscription()->create(['plan_id' => $this->plan->id, 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant);
        $this->modules(['students']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_existing_tenant_is_inferred_ready_without_interruption(): void
    {
        $this->actingAs($this->admin());

        $this->get('/dashboard')->assertOk();
        $summary = app(TenantSetupManager::class)->summary($this->tenant);

        $this->assertSame('ready', $summary['status']);
        $this->assertSame('ready', $summary['modules']['foundation']['status']);
        $this->assertSame('ready', $summary['modules']['students']['status']);
        $this->assertTrue((bool) AppSetting::groupValues('onboarding')->get('managed'));
    }

    public function test_new_tenant_completes_foundation_and_can_skip_optional_steps(): void
    {
        app(TenantSetupManager::class)->initialiseNewTenant();
        $this->actingAs($this->admin());

        $this->get('/dashboard')->assertRedirect(route('tenant-setup.show'));
        $this->get('/setup')->assertOk()->assertSee('data-setup-foundation', false)->assertDontSee('data-setup-modules', false);

        $this->patch('/setup/foundation', [
            'school_name' => 'Al Noor Centre',
            'default_locale' => 'en',
            'school_timezone' => 'Asia/Damascus',
        ])->assertRedirect();

        $this->assertSame('Al Noor Centre', AppSetting::groupValues('general')->get('school_name'));
        $this->assertSame('en', AppSetting::groupValues('general')->get('default_locale'));
        $this->get('/setup')->assertOk()->assertSee('data-setup-modules', false)->assertSee('data-setup-module="students"', false);

        $this->post('/setup/finish')->assertRedirect(route('dashboard'));
        $this->assertSame('ready', app(TenantSetupManager::class)->summary($this->tenant)['status']);
        $this->get('/dashboard')->assertOk();
    }

    public function test_tenant_administrator_changes_temporary_password_before_setup(): void
    {
        app(TenantSetupManager::class)->initialiseNewTenant();
        $user = $this->admin();
        $user->forceFill([
            'must_change_password' => true,
            'password_changed_at' => null,
            'issued_password' => 'password',
        ])->save();
        $this->actingAs($user);

        $this->get('/dashboard')->assertRedirect(route('password.change-required.show'));

        $this->put(route('password.change-required.update'), [
            'current_password' => 'password',
            'password' => 'NewPersonalPassword123!',
            'password_confirmation' => 'NewPersonalPassword123!',
        ])->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertRedirect(route('tenant-setup.show'));
        $this->get('/setup')->assertOk();
    }

    public function test_new_tenant_setup_is_prefilled_from_the_platform_tenant_details(): void
    {
        $this->tenant->update(['timezone' => 'Asia/Damascus', 'locale' => 'en']);

        app(TenantSetupManager::class)->initialiseNewTenant($this->tenant->fresh());

        $settings = AppSetting::groupValues('general');
        $this->assertSame('Setup Mosque', $settings->get('school_name'));
        $this->assertSame('Asia/Damascus', $settings->get('school_timezone'));
        $this->assertSame('en', $settings->get('default_locale'));
    }

    public function test_newly_enabled_module_prompts_without_resetting_ready_modules(): void
    {
        $this->actingAs($this->admin());
        $this->get('/dashboard')->assertOk();
        $before = app(TenantSetupManager::class)->summary($this->tenant);

        $this->modules(['students', 'finance']);
        $this->get('/dashboard')->assertRedirect(route('tenant-setup.show'));
        $after = app(TenantSetupManager::class)->summary($this->tenant);

        $this->assertSame('ready', $after['modules']['students']['status']);
        $this->assertSame('not_started', $after['modules']['finance']['status']);
        $this->assertNotSame($before['version'], $after['version']);
    }

    public function test_removed_and_reenabled_module_preserves_its_setup_state(): void
    {
        $this->actingAs($this->admin());
        $this->get('/dashboard')->assertOk();
        $this->modules(['students', 'finance']);
        app(TenantSetupManager::class)->mark($this->tenant, 'finance', 'ready');

        $this->modules(['students']);
        $this->assertArrayNotHasKey('finance', app(TenantSetupManager::class)->summary($this->tenant)['modules']);
        $this->modules(['students', 'finance']);

        $this->assertSame('ready', app(TenantSetupManager::class)->summary($this->tenant)['modules']['finance']['status']);
    }

    public function test_settings_permission_alone_does_not_allow_changing_initial_tenant_setup(): void
    {
        app(TenantSetupManager::class)->initialiseNewTenant();
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('settings.manage');
        $this->actingAs($user);

        $this->get('/setup')->assertForbidden();
        $this->patch('/setup/foundation', [
            'school_name' => 'Blocked',
            'default_locale' => 'en',
            'school_timezone' => 'UTC',
        ])->assertForbidden();
    }

    public function test_capabilities_report_versioned_setup_without_blocking_api_access(): void
    {
        app(TenantSetupManager::class)->initialiseNewTenant();
        $user = $this->admin();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/capabilities')
            ->assertOk()
            ->assertJsonPath('data.setup.status', 'required')
            ->assertJsonPath('data.setup.can_manage', true)
            ->assertJsonPath('data.setup.modules.0.code', 'foundation');

        $this->getJson('/api/v1/students')
            ->assertStatus(409)
            ->assertSee('setup_required: foundation');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'is_tenant_administrator' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function modules(array $codes): void
    {
        $ids = [];
        foreach ($codes as $code) {
            $ids[] = Feature::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true])->id;
        }
        $this->plan->features()->sync($ids);
    }
}

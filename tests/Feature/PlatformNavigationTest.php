<?php

namespace Tests\Feature;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformMetricSnapshot;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\PlatformSubscriptionLedgerEntry;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantSubscription;
use App\Services\Landlord\PlatformManagementMetrics;
use App\Services\Landlord\TenantModuleAccess;
use App\Services\Landlord\TenantResources;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_owner_sees_the_same_complete_navigation_on_platform_pages(): void
    {
        $owner = $this->administrator('owner@example.test');
        $expected = ['Overview', 'Tenants', 'Platform metrics', 'Packages & Features', 'Vouchers', 'Backups', 'Storage', 'Requests', 'Website editor', 'Report library', 'Users & Roles', 'Settings'];

        foreach ([
            route('platform.dashboard'),
            route('platform.tenants.index'),
            route('platform.metrics'),
            route('platform.backups.index'),
            route('platform.storage.index'),
            route('platform.landing.edit'),
            route('platform.settings.index'),
        ] as $url) {
            $response = $this->actingAs($owner, 'platform')->get($url)->assertOk();
            foreach ($expected as $label) {
                $response->assertSee($label);
            }
        }
    }

    public function test_platform_account_uses_tenant_style_menu_with_platform_guard_routes(): void
    {
        $owner = $this->administrator('account-menu@example.test');

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->actingAs($owner, 'platform')->get(route('platform.settings.index'))
                ->assertOk()
                ->assertSee('data-platform-account', false)
                ->assertSee('data-flux-profile', false)
                ->assertSee('id="platform-account-menu"', false)
                ->assertSee('aria-controls="platform-account-menu"', false)
                ->assertSee('x-show="open"', false)
                ->assertSee($owner->email)
                ->assertSee('account-preference-switch--language', false)
                ->assertSee('account-preference-switch--appearance', false)
                ->assertSee('href="'.route('platform.settings.index').'"', false)
                ->assertSee('action="'.route('platform.logout').'"', false)
                ->assertDontSee('href="'.route('settings.profile').'"', false)
                ->assertDontSee('action="'.route('logout').'"', false);
        }
    }

    public function test_navigation_shows_only_destinations_granted_to_a_platform_role(): void
    {
        $this->administrator('owner@example.test');
        $role = PlatformRole::query()->create(['name' => 'Landing editor']);
        $role->permissions()->sync(PlatformPermission::query()->whereIn('code', ['view.dashboard', 'manage.landing-page'])->pluck('id'));
        $editor = $this->administrator('editor@example.test');
        $editor->roles()->sync([$role->id]);

        $this->actingAs($editor, 'platform')->get(route('platform.landing.edit'))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Platform metrics')
            ->assertSee('Website editor')
            ->assertSee('Settings')
            ->assertDontSee('href="'.route('platform.vouchers.index').'"', false)
            ->assertDontSee('>Backups</div>', false)
            ->assertDontSee('>Storage</div>', false)
            ->assertDontSee('>Platform users</div>', false);
    }

    public function test_settings_hub_is_authenticated_and_keeps_owner_forms_together(): void
    {
        $this->get(route('platform.settings.index'))->assertRedirect(route('platform.login'));
        $owner = $this->administrator('settings-owner@example.test');
        $this->actingAs($owner, 'platform')->get(route('platform.settings.index'))->assertOk()
            ->assertSee('data-platform-settings', false)
            ->assertSee('data-platform-branding', false)
            ->assertSee(asset('images/platform-brand/alkhair-platform-logo.png'), false)
            ->assertSee(asset('images/platform-brand/alkhair-platform-icon-v2.png'), false)
            ->assertSee('action="'.route('platform.subscription-settings.update').'"', false)
            ->assertSee('action="'.route('platform.backups.settings.update').'"', false)
            ->assertSee('action="'.route('platform.metrics.settings.update').'"', false)
            ->assertSee('href="'.route('platform.support.settings.edit').'"', false);
        $this->get(route('platform.subscription-settings.edit'))
            ->assertRedirect(route('platform.settings.index').'#subscriptions');
    }

    public function test_settings_hides_restricted_forms_and_old_update_routes_still_reject_access(): void
    {
        $this->administrator('owner@example.test');
        $editor = $this->administrator('preferences@example.test');
        $this->actingAs($editor, 'platform')->get(route('platform.settings.index'))->assertOk()
            ->assertSee('Your preferences')
            ->assertDontSee('id="subscriptions"', false)
            ->assertDontSee('id="backups"', false)
            ->assertDontSee('id="monitoring"', false)
            ->assertDontSee('id="requests"', false);
        $this->put(route('platform.subscription-settings.update'), ['suspended_data_retention_months' => 18])->assertForbidden();
        $this->put(route('platform.backups.settings.update'), ['run_at' => '02:00', 'retention_count' => 30])->assertForbidden();
        $this->put(route('platform.metrics.settings.update'), ['metrics_refresh_minutes' => 10])->assertForbidden();
        $this->get(route('platform.support.settings.edit'))->assertForbidden();
    }

    public function test_subscription_viewer_gets_readonly_policy_and_direct_voucher_navigation(): void
    {
        $this->administrator('owner@example.test');
        $viewer = $this->administrator('viewer@example.test');
        $role = PlatformRole::query()->create(['name' => 'Subscription viewer']);
        $role->permissions()->sync(PlatformPermission::query()->where('code', 'view.subscriptions')->pluck('id'));
        $viewer->roles()->sync([$role->id]);
        $this->actingAs($viewer, 'platform')->get(route('platform.settings.index'))->assertOk()
            ->assertSee('id="subscriptions"', false)
            ->assertSee('href="'.route('platform.vouchers.index').'"', false)
            ->assertDontSee('action="'.route('platform.subscription-settings.update').'"', false)
            ->assertDontSee('id="backups"', false);
        $this->get(route('platform.subscription-settings.edit'))->assertRedirect(route('platform.settings.index').'#subscriptions');
        $this->put(route('platform.subscription-settings.update'), ['suspended_data_retention_months' => 18])->assertForbidden();
    }

    public function test_backup_manager_can_configure_backups_without_access_to_backup_history(): void
    {
        $this->administrator('owner@example.test');
        $manager = $this->administrator('backups@example.test');
        $role = PlatformRole::query()->create(['name' => 'Backup policy manager']);
        $role->permissions()->sync(PlatformPermission::query()->where('code', 'manage.backups')->pluck('id'));
        $manager->roles()->sync([$role->id]);
        $this->actingAs($manager, 'platform')->get(route('platform.settings.index'))->assertOk()
            ->assertSee('id="backups"', false)->assertDontSee('id="monitoring"', false);
        $this->from(route('platform.settings.index'))->put(route('platform.backups.settings.update'), [
            'is_enabled' => 0, 'run_at' => '03:30', 'retention_count' => 12,
        ])->assertRedirect(route('platform.settings.index'));
        $this->assertDatabaseHas('saas_backup_settings', ['is_enabled' => 0, 'run_at' => '03:30:00', 'retention_count' => 12], 'landlord');
        $this->get(route('platform.backups.index'))->assertForbidden();
    }

    public function test_settings_keeps_validation_input_and_supports_arabic(): void
    {
        $owner = $this->administrator('owner@example.test');
        $this->actingAs($owner, 'platform')->from(route('platform.settings.index'))
            ->put(route('platform.subscription-settings.update'), ['suspended_data_retention_months' => 121])
            ->assertRedirect(route('platform.settings.index'))->assertSessionHasErrors('suspended_data_retention_months');
        $this->get(route('platform.settings.index'))->assertOk()->assertSee('value="121"', false);
        $this->withSession(['locale' => 'ar', 'locale_user_selected' => true])->get(route('platform.settings.index'))
            ->assertOk()->assertSee('dir="rtl"', false)->assertSee('سياسة النسخ الاحتياطي');
    }

    public function test_dashboard_only_role_cannot_see_directory_finances_requests_backups_or_audit(): void
    {
        $this->administrator('owner@example.test');
        $user = $this->administrator('overview@example.test');
        $role = PlatformRole::query()->create(['name' => 'Overview only']);
        $role->permissions()->sync(PlatformPermission::query()->where('code', 'view.dashboard')->pluck('id'));
        $user->roles()->sync([$role->id]);
        $this->actingAs($user, 'platform')->get(route('platform.dashboard'))->assertOk()
            ->assertDontSee('data-overview-finance', false)->assertDontSee('data-overview-requests', false)
            ->assertDontSee('data-overview-backups', false)->assertDontSee('data-overview-activity', false)
            ->assertDontSee('href="'.route('platform.tenants.index').'"', false)->assertSee('No saved metrics yet');
        $this->get(route('platform.tenants.index'))->assertForbidden();
    }

    public function test_directory_is_paginated_filtered_and_preserves_old_filter_bookmarks(): void
    {
        $owner = $this->administrator('owner@example.test');
        for ($i = 0; $i < 23; $i++) {
            Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => sprintf('Centre %02d', $i), 'slug' => 'centre-'.$i, 'status' => 'active']);
        }
        $this->actingAs($owner, 'platform')->get(route('platform.tenants.index'))->assertOk()
            ->assertViewHas('tenants', fn ($tenants) => $tenants->total() === 23 && $tenants->count() === 20);
        $this->get(route('platform.tenants.index', ['search' => 'Centre 22', 'status' => 'active']))->assertOk()
            ->assertSee('Centre 22')->assertDontSee('Centre 00')->assertViewHas('tenants', fn ($tenants) => $tenants->total() === 1);
        $this->get(route('platform.dashboard', ['status' => 'active']))->assertRedirect(route('platform.tenants.index', ['status' => 'active']));
        $this->get(route('platform.tenants.index', ['status' => 'invalid']))->assertOk()->assertViewHas('tenants', fn ($tenants) => $tenants->total() === 23);
        $this->get(route('platform.tenants.index', ['status' => 'attention']))->assertOk()->assertViewHas('tenants', fn ($tenants) => $tenants->total() === 0);
    }

    public function test_dashboard_uses_saved_snapshot_and_labels_stale_data(): void
    {
        $owner = $this->administrator('owner@example.test');
        $this->mock(PlatformManagementMetrics::class)->shouldNotReceive('snapshot');
        PlatformMetricSnapshot::query()->create(['resolution' => 'interval', 'captured_at' => now()->subDay(), 'server_used_percent' => 42.5]);
        $this->actingAs($owner, 'platform')->get(route('platform.dashboard'))->assertOk()->assertSee('42.5%')->assertSee('This snapshot is stale');
    }

    public function test_directory_only_role_gets_tenants_without_package_information_or_dashboard_access(): void
    {
        $this->administrator('owner@example.test');
        $user = $this->administrator('directory@example.test');
        $role = PlatformRole::query()->create(['name' => 'Tenant directory reader']);
        $role->permissions()->sync(PlatformPermission::query()->where('code', 'view.tenants')->pluck('id'));
        $user->roles()->sync([$role->id]);
        $this->actingAs($user, 'platform')->get(route('platform.tenants.index'))->assertOk()
            ->assertViewHas('canViewBilling', false)->assertDontSee('>Package</th>', false);
        $this->get(route('platform.dashboard'))->assertForbidden();
    }

    public function test_request_summary_is_scoped_to_permitted_case_types(): void
    {
        $this->administrator('owner@example.test');
        $user = $this->administrator('problems@example.test');
        $role = PlatformRole::query()->create(['name' => 'Problems dashboard']);
        $role->permissions()->sync(PlatformPermission::query()->whereIn('code', ['view.dashboard', 'manage.support.problems'])->pluck('id'));
        $user->roles()->sync([$role->id]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Tenant', 'slug' => 'tenant', 'status' => 'draft']);
        foreach (['problem', 'suggestion'] as $index => $type) {
            PlatformSupportCase::query()->create(['tenant_id' => $tenant->id, 'tenant_support_request_id' => $index + 1, 'type' => $type, 'subject' => 'Request', 'message' => 'Details', 'status' => 'forwarded', 'forwarded_at' => now()]);
        }
        $this->actingAs($user, 'platform')->get(route('platform.dashboard'))->assertOk()->assertViewHas('requests', 1)->assertDontSee('data-overview-finance', false);
    }

    public function test_dashboard_finance_distinguishes_period_payments_charges_and_per_tenant_deficits(): void
    {
        $owner = $this->administrator('owner@example.test');
        $first = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'First', 'slug' => 'first', 'status' => 'draft']);
        $second = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Second', 'slug' => 'second', 'status' => 'draft']);
        foreach ([[$first->id, 'activation', 0, 100, now(), now()], [$first->id, 'offline_payment', 50, 0, now(), now()], [$second->id, 'offline_payment', 100, 0, now()->subDays(60), now()->subDays(60)]] as [$tenantId, $type, $credit, $debit, $paid, $created]) {
            PlatformSubscriptionLedgerEntry::forceCreate(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'type' => $type, 'credit_syp' => $credit, 'debit_syp' => $debit, 'currency' => 'SYP', 'paid_at' => $paid, 'created_at' => $created]);
        }
        $this->actingAs($owner, 'platform')->get(route('platform.dashboard'))->assertOk()
            ->assertViewHas('finance', fn ($finance) => $finance['payments'] == 50 && $finance['charges'] == 100 && $finance['outstanding'] == 50);
        $this->get(route('platform.dashboard', ['range' => '90d']))->assertOk()->assertViewHas('finance', fn ($finance) => $finance['payments'] == 150 && $finance['outstanding'] == 50);
        $this->get(route('platform.dashboard', ['range' => 'invalid']))->assertOk()->assertViewHas('range', '30d');
    }

    private function administrator(string $email): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }

    public function test_feature_catalog_and_package_picker_show_individual_requirements_in_both_languages(): void
    {
        $owner = $this->administrator('features@example.test');
        $this->actingAs($owner, 'platform')->get(route('platform.plans.features'))->assertOk()->assertSee('Feature catalog')->assertSee('Parent Portal')->assertSee('Requires: Parents')->assertDontSee('name="modules[]"', false);
        $this->get(route('platform.plans.create'))->assertOk()->assertSee('data-feature-picker', false)->assertSee('data-code="parent_portal"', false)->assertSee('data-requires=\'["parents"]\'', false);
        $this->withSession(['locale' => 'ar', 'locale_user_selected' => true])->get(route('platform.plans.features'))->assertOk()->assertSee('دليل الميزات')->assertSee('بوابة أولياء الأمور')->assertSee('dir="rtl"', false);
    }

    public function test_package_filters_and_dependency_inclusion_do_not_modify_saved_selection(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $owner = $this->administrator('owner@example.test');
        $plan = Plan::query()->create(['name' => 'Portal package', 'code' => 'portal-package', 'is_active' => true, 'is_public' => false]);
        $plan->features()->sync([Feature::query()->where('code', 'parent_portal')->sole()->id]);
        Plan::query()->create(['name' => 'Hidden inactive', 'code' => 'inactive', 'is_active' => false, 'is_public' => true]);
        $this->actingAs($owner, 'platform')->get(route('platform.plans.index', ['search' => 'Portal', 'status' => 'active', 'visibility' => 'private']))->assertOk()->assertSee('Portal package')->assertDontSee('Hidden inactive')->assertSee('Parents')->assertSee('Students')->assertViewHas('plans', fn ($plans) => $plans->total() === 1);
        $this->assertSame(['parent_portal'], $plan->features()->pluck('code')->all());
        $this->get(route('platform.plans.index', ['search' => 'not-present']))->assertOk()->assertSee('No packages match these filters');
        $user = $this->administrator('viewer@example.test');
        $this->actingAs($user, 'platform')->get(route('platform.plans.features'))->assertForbidden();
        $this->get(route('platform.plans.create'))->assertForbidden();
    }

    public function test_package_impact_conflicts_are_displayed_and_block_updates(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        config()->set('modules.definitions.id_cards.requires_any', ['finance']);
        $owner = $this->administrator('owner@example.test');
        $plan = Plan::query()->create(['name' => 'Finance plan', 'code' => 'finance-plan', 'is_active' => true]);
        $plan->features()->sync([Feature::query()->where('code', 'finance')->sole()->id]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Impact tenant', 'slug' => 'impact-tenant', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()]);
        $tenant->featureOverrides()->create(['feature_id' => Feature::query()->where('code', 'id_cards')->sole()->id, 'is_enabled' => true]);
        $data = ['name' => 'New selection', 'modules' => ['students'], 'is_active' => 1];
        $this->actingAs($owner, 'platform')->put(route('platform.plans.preview', $plan), $data)->assertOk()->assertSee('Dependency conflict')->assertSee('requires at least one of');
        $this->put(route('platform.plans.update', $plan), $data + ['confirm_impact' => 1])->assertSessionHasErrors('modules');
        $this->assertSame(['finance'], $plan->features()->pluck('code')->all());
    }

    public function test_unavailable_package_feature_is_visible_as_a_conflict_without_edit_controls(): void
    {
        $this->seed(LandlordCatalogSeeder::class);
        $owner = $this->administrator('owner@example.test');
        $feature = Feature::query()->where('code', 'finance')->sole();
        $plan = Plan::query()->create(['name' => 'Finance', 'code' => 'unavailable-finance', 'is_active' => true]);
        $plan->features()->sync([$feature->id]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Conflict centre', 'slug' => 'conflict-centre', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()]);
        $feature->update(['is_active' => false]);
        $this->actingAs($owner, 'platform')->get(route('platform.tenants.modules', $tenant))->assertOk()->assertSee('Feature access needs review')->assertSee('Unavailable module: finance')->assertSee('data-readonly="true"', false)->assertDontSee('name="confirm_extras"', false);
        $this->get(route('platform.plans.index'))->assertOk()->assertSee('Unavailable module: finance');
    }

    public function test_feature_preview_does_not_display_other_tenant_or_stale_version_changes(): void
    {
        $owner = $this->administrator('owner@example.test');
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Preview tenant', 'slug' => 'preview-tenant', 'status' => 'draft']);
        $version = app(TenantModuleAccess::class)->snapshot($tenant)['version'];
        $preview = ['tenant_id' => $tenant->id, 'expected_version' => $version, 'added' => ['finance'], 'removed' => [], 'effective' => []];
        $this->actingAs($owner, 'platform')->withSession(['module_preview' => array_replace($preview, ['tenant_id' => $tenant->id + 1])])->get(route('platform.tenants.modules', $tenant))->assertOk()->assertDontSee(__('platform.ui.modules.preview_ready'));
        $this->withSession(['module_preview' => array_replace($preview, ['expected_version' => str_repeat('0', 64)])])->get(route('platform.tenants.modules', $tenant))->assertOk()->assertDontSee(__('platform.ui.modules.preview_ready'));
        $this->withSession(['module_preview' => $preview])->get(route('platform.tenants.modules', $tenant))->assertOk()->assertSee(__('platform.ui.modules.preview_ready'));
    }

    public function test_tenant_creation_is_integrated_and_legacy_create_url_redirects(): void
    {
        $owner = $this->administrator('owner@example.test');
        $this->actingAs($owner, 'platform')->get(route('platform.tenants.create'))->assertRedirect(route('platform.tenants.index', ['create' => 1]));
        $this->get(route('platform.tenants.index', ['create' => 1]))->assertOk()->assertSee('data-create-step="2"', false)
            ->assertSee('data-tenant-provisioning-form', false)->assertSee('name="learning_path_type"', false)->assertDontSee('<table', false);
        $this->get(route('platform.tenants.index'))->assertOk()->assertDontSee('data-tenant-provisioning-form', false);
    }

    public function test_tenant_creation_validation_does_not_flash_temporary_password(): void
    {
        $owner = $this->administrator('owner@example.test');
        $url = route('platform.tenants.index', ['create' => 1]);
        $this->actingAs($owner, 'platform')->from($url)->post(route('platform.tenants.store'), ['name' => 'Preserved name', 'owner_password' => 'not-a-real-password'])
            ->assertRedirect($url)->assertSessionHasErrors('slug')->assertSessionHas('_old_input.name', 'Preserved name')->assertSessionMissing('_old_input.owner_password');
        $this->get($url)->assertOk()->assertSee('Preserved name')->assertDontSee('not-a-real-password');
    }

    public function test_tenant_viewer_cannot_create_change_status_delete_or_view_private_balance(): void
    {
        $this->administrator('owner@example.test');
        $viewer = $this->administrator('tenant-reader@example.test');
        $role = PlatformRole::query()->create(['name' => 'Tenant reader']);
        $role->permissions()->sync(PlatformPermission::query()->where('code', 'view.tenants')->pluck('id'));
        $viewer->roles()->sync([$role->id]);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Reader centre', 'slug' => 'reader-centre', 'status' => 'active']);
        $this->actingAs($viewer, 'platform')->get(route('platform.tenants.edit', $tenant))->assertOk()->assertViewHas('billingBalance', null)
            ->assertDontSee('data-tenant-lifecycle', false)->assertDontSee(__('platform.ui.overview.balance'))->assertDontSee(__('platform.ui.overview.current_package'));
        $this->get(route('platform.tenants.index', ['create' => 1]))->assertForbidden();
        $this->get(route('platform.tenants.create'))->assertForbidden();
        $this->post(route('platform.tenants.store'), [])->assertForbidden();
        $this->patch(route('platform.tenants.status', $tenant), ['status' => 'suspended', 'confirm_slug' => $tenant->slug])->assertForbidden();
        $this->delete(route('platform.tenants.destroy', $tenant), ['confirm_slug' => $tenant->slug])->assertForbidden();
    }

    public function test_tenant_lifecycle_requires_confirmation_and_never_deletes_resources_in_this_check(): void
    {
        $owner = $this->administrator('owner@example.test');
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Lifecycle centre', 'slug' => 'lifecycle', 'status' => 'active', 'database_name' => 'alkhair_tenant_'.str_repeat('a', 32)]);
        $tenant->domains()->create(['host' => 'lifecycle.localhost', 'is_primary' => true]);
        $this->mock(TenantResources::class)->shouldNotReceive('delete');
        $this->actingAs($owner, 'platform')->get(route('platform.tenants.edit', $tenant))->assertOk()->assertSee('Suspend workspace')->assertDontSee('action="'.route('platform.tenants.destroy', $tenant).'"', false);
        $this->patch(route('platform.tenants.status', $tenant), ['status' => 'suspended'])->assertSessionHasErrors('confirm_slug');
        $this->patch(route('platform.tenants.status', $tenant), ['status' => 'suspended', 'confirm_slug' => $tenant->slug])->assertRedirect();
        $this->get(route('platform.tenants.edit', $tenant))->assertOk()->assertSee('Resume workspace')->assertSee('Permanently delete workspace');
        $this->delete(route('platform.tenants.destroy', $tenant), ['confirm_slug' => 'wrong'])->assertSessionHasErrors('confirm_slug');
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => 'suspended'], 'landlord');
        $this->patch(route('platform.tenants.status', $tenant), ['status' => 'active', 'confirm_slug' => $tenant->slug])->assertRedirect();
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => 'active'], 'landlord');
    }

    public function test_failed_tenant_has_guidance_without_unsafe_retry_or_delete_controls(): void
    {
        $owner = $this->administrator('owner@example.test');
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Failed centre', 'slug' => 'failed-centre', 'status' => 'provisioning_failed']);
        $this->actingAs($owner, 'platform')->get(route('platform.tenants.edit', $tenant))->assertOk()->assertSee('Provisioning needs investigation')
            ->assertDontSee('action="'.route('platform.tenants.destroy', $tenant).'"', false);
    }

    public function test_confirmed_tenant_deletion_returns_to_directory_with_resource_cleanup_mocked(): void
    {
        $owner = $this->administrator('owner@example.test');
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Isolated test', 'slug' => 'isolated-test', 'status' => 'suspended']);
        $this->mock(TenantResources::class)->shouldReceive('delete')->once()->with(\Mockery::on(fn ($target) => $target->id === $tenant->id));
        $this->actingAs($owner, 'platform')->delete(route('platform.tenants.destroy', $tenant), ['confirm_slug' => $tenant->slug])->assertRedirect(route('platform.tenants.index'));
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id], 'landlord');
    }

    public function test_subscription_lifecycle_requires_confirmation_and_shows_financial_warning(): void
    {
        $owner = $this->administrator('owner@example.test');
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Subscription centre', 'slug' => 'subscription-centre', 'status' => 'active', 'database_name' => 'alkhair_tenant_'.str_repeat('b', 32)]);
        $tenant->domains()->create(['host' => 'subscription-centre.localhost', 'is_primary' => true]);
        $plan = Plan::query()->create(['code' => 'lifecycle', 'name' => 'Lifecycle', 'is_active' => true]);
        $subscription = TenantSubscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(7)]);
        $this->actingAs($owner, 'platform')->get(route('platform.tenants.subscription', $tenant))->assertOk()->assertSee('Cancel subscription');
        $this->post(route('platform.tenants.subscription.cancel', $tenant), ['confirm_slug' => 'wrong'])->assertSessionHasErrors('confirm_slug');
        $this->post(route('platform.tenants.subscription.cancel', $tenant), ['confirm_slug' => $tenant->slug])->assertRedirect();
        $this->get(route('platform.tenants.subscription', $tenant))->assertOk()->assertSee('Reactivate subscription')->assertSee('may debit the tenant balance');
        $this->post(route('platform.tenants.subscription.reactivate', $tenant), [])->assertSessionHasErrors('confirm_slug');
        $this->post(route('platform.tenants.subscription.reactivate', $tenant), ['confirm_slug' => $tenant->slug])->assertRedirect();
        $this->assertSame('active', $subscription->fresh()->status);
    }
}

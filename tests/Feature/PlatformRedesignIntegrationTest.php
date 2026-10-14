<?php

namespace Tests\Feature;

use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformLandingEnquiry;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformReportLibraryItem;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\PlatformManagementMetrics;
use App\Services\Landlord\TenantScopedContext;
use App\Services\Landlord\TenantStorageUsage;
use App\Support\PlatformWorkspaceNavigation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformRedesignIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->mock(TenantStorageUsage::class)->shouldReceive('for')->andReturn(['media' => 0, 'documents' => 0, 'other_files' => 0, 'database' => 0, 'backups' => 0, 'total' => 0]);
        $this->mock(TenantScopedContext::class)->shouldReceive('run')->andReturn(collect());
        $this->mock(PlatformManagementMetrics::class)->shouldReceive('snapshot')->andReturn([
            'tenants' => ['total' => 1, 'active' => 1, 'trial' => 0, 'suspended' => 0, 'provisioning_failed' => 0],
            'storage' => ['tenant_bytes' => 0, 'scanned_tenants' => 1, 'unavailable_tenants' => 0, 'server_total_bytes' => null, 'server_used_bytes' => null, 'server_free_bytes' => null, 'server_used_percent' => null],
            'backups' => ['latest_success_at' => null, 'failed_last_24_hours' => 0],
            'infrastructure' => ['status' => 'unconfigured'], 'alerts' => [],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_management_get_routes_match_permissions_across_representative_roles(): void
    {
        $owner = $this->user();
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'QA centre', 'slug' => 'qa-centre', 'status' => 'active', 'database_name' => 'test_only']);
        $plan = Plan::query()->create(['code' => 'qa', 'name' => 'QA package', 'is_active' => true]);
        $problem = PlatformSupportCase::query()->create(['tenant_id' => $tenant->id, 'tenant_support_request_id' => 100, 'type' => 'problem', 'status' => 'forwarded', 'subject' => 'QA problem', 'message' => 'Fixture only', 'forwarded_at' => now()]);
        $enquiry = PlatformLandingEnquiry::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'QA lead', 'organisation_name' => 'QA organisation', 'email' => 'qa@example.test', 'locale' => 'en']);
        $report = PlatformReportLibraryItem::query()->where('is_system', true)->with('publishedRevision')->firstOrFail();
        $ownerRole = $owner->roles()->where('is_owner', true)->sole();
        // expected permission lists mirror direct route contracts, not sidebar visibility.
        $destinations = [
            ['platform.settings.index', [], [], 200],
            ['platform.dashboard', [], ['view.dashboard'], 200],
            ['platform.metrics', [], ['view.dashboard'], 200],
            ['platform.tenants.index', [], ['view.tenants', 'manage.tenants'], 200],
            ['platform.tenants.create', [], ['manage.tenants'], 302],
            ['platform.tenants.edit', [$tenant], ['view.tenants'], 200],
            ['platform.tenants.organisation', [$tenant], ['view.tenants'], 200],
            ['platform.tenants.modules', [$tenant], ['view.tenants'], 200],
            ['platform.tenants.activity', [$tenant], ['view.tenants'], 200],
            ['platform.tenants.storage', [$tenant], ['view.storage', 'manage.tenants'], 200],
            ['platform.tenants.subscription', [$tenant], ['view.subscriptions', 'manage.subscriptions'], 200],
            ['platform.tenants.billing', [$tenant], ['view.subscriptions', 'manage.subscriptions'], 200],
            ['platform.billing.index', [], ['view.subscriptions', 'manage.subscriptions'], 200],
            ['platform.billing.payments', [], ['view.subscriptions', 'manage.subscriptions'], 200],
            ['platform.vouchers.index', [], ['view.subscriptions', 'manage.subscriptions'], 200],
            ['platform.subscription-settings.edit', [], ['view.subscriptions', 'manage.subscriptions'], 302],
            ['platform.backups.index', [], ['view.backups'], 200],
            ['platform.storage.index', [], ['view.storage'], 200],
            ['platform.plans.index', [], ['manage.plans'], 200],
            ['platform.plans.features', [], ['manage.plans'], 200],
            ['platform.plans.create', [], ['manage.plans'], 200],
            ['platform.plans.edit', [$plan], ['manage.plans'], 200],
            ['platform.support.index', [], ['manage.support.problems', 'manage.support.suggestions', 'manage.landing-page'], 200],
            ['platform.support.show', [$problem], ['manage.support.problems'], 200],
            ['platform.support.attachments.index', [$problem], ['manage.support.problems'], 200],
            ['platform.support.groups', [], ['manage.support.suggestions'], 200],
            ['platform.support.settings.edit', [], ['manage.support.problems'], 200],
            ['platform.enquiries.index', [], ['manage.landing-page'], 200],
            ['platform.enquiries.show', [$enquiry], ['manage.landing-page'], 200],
            ['platform.landing.edit', [], ['manage.landing-page', 'publish.landing-page'], 200],
            ['platform.landing.preview', [], ['manage.landing-page', 'publish.landing-page'], 200],
            ['platform.report-library.index', [], ['manage.report-library', 'publish.report-library'], 200],
            ['platform.report-library.create', [], ['manage.report-library'], 200],
            ['platform.report-library.edit', [$report], ['manage.report-library', 'publish.report-library'], 200],
            ['platform.report-library.revision', [$report, $report->publishedRevision], ['manage.report-library', 'publish.report-library'], 200],
            ['platform.access.index', [], ['manage.platform-users'], 200],
            ['platform.access.users.edit', [$owner], ['manage.platform-users'], 200],
            ['platform.access.roles.index', [], ['owner'], 200],
            ['platform.access.roles.edit', [$ownerRole], ['owner'], 200],
        ];
        $profiles = [[], ['view.dashboard'], ['view.tenants'], ['manage.tenants'], ['view.subscriptions'], ['manage.subscriptions'], ['manage.support.problems'], ['manage.support.suggestions'], ['manage.landing-page'], ['publish.landing-page', 'publish.report-library'], ['view.storage', 'view.backups'], ['manage.platform-users'], ['manage.plans']];
        $actors = [[$owner, ['owner']]];
        foreach ($profiles as $permissions) {
            $actors[] = [$this->user($permissions), $permissions];
        }
        foreach ($actors as [$actor, $permissions]) {
            $this->actingAs($actor, 'platform');
            foreach ($destinations as [$name, $parameters, $required, $allowedStatus]) {
                $allowed = $required === [] || $actor->isPlatformOwner() || array_intersect($permissions, $required) !== [];
                $expected = $allowed ? $allowedStatus : 403;
                if ($name === 'platform.support.index' && $permissions === ['manage.landing-page']) {
                    $expected = 302;
                }
                $response = $this->get(route($name, $parameters));
                $this->assertSame($expected, $response->status(), $name.' with '.json_encode($permissions));
            }
        }
    }

    public function test_login_and_authenticated_sign_in_use_an_authorized_platform_home(): void
    {
        $this->user();
        foreach ([['manage.plans', 'platform.plans.index'], ['view.subscriptions', 'platform.billing.index'], ['view.storage', 'platform.storage.index'], [null, 'platform.settings.index']] as [$permission, $destination]) {
            $actor = $this->user($permission ? [$permission] : []);
            auth('platform')->logout();
            session()->forget('url.intended');
            $this->post(route('platform.login.store'), ['email' => $actor->email, 'password' => 'qa-only-password'])->assertRedirect(route($destination));
            $this->get(route('platform.login'))->assertRedirect(route($destination));
            $this->get(route($destination))->assertOk();
        }
    }

    public function test_password_replacement_uses_permitted_home_and_owner_keeps_overview(): void
    {
        $owner = $this->user();
        $actor = $this->user(['view.backups']);
        $actor->update(['must_change_password' => true]);
        $this->actingAs($actor, 'platform')->get(route('platform.backups.index'))->assertRedirect(route('platform.password-change.show'));
        $this->put(route('platform.password-change.update'), ['current_password' => 'qa-only-password', 'password' => 'changed-qa-password', 'password_confirmation' => 'changed-qa-password'])->assertRedirect(route('platform.backups.index'));
        $this->get(route('platform.password-change.show'))->assertRedirect(route('platform.backups.index'));
        $this->assertSame('platform.dashboard', app(PlatformWorkspaceNavigation::class)->homeRoute($owner));
    }

    public function test_storage_only_user_has_a_working_back_link_without_billing_leaks(): void
    {
        $this->user();
        $actor = $this->user(['view.storage']);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Storage fixture', 'slug' => 'storage-fixture', 'status' => 'active']);
        $this->actingAs($actor, 'platform')->get(route('platform.tenants.storage', $tenant))->assertOk()->assertSee('Back to Storage')->assertSee('href="'.route('platform.storage.index').'"', false)->assertDontSee('href="'.route('platform.billing.index').'"', false);
    }

    public function test_tenant_reader_cannot_edit_organisation_fields_and_learning_path_is_localized(): void
    {
        $this->user();
        $actor = $this->user(['view.tenants']);
        $tenant = Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Readonly organisation', 'slug' => 'readonly-organisation', 'status' => 'active']);
        $this->actingAs($actor, 'platform')->get(route('platform.tenants.organisation', $tenant))->assertOk()->assertSee('readonly', false)->assertSee('name="timezone" class="rounded-xl border p-3 font-normal" disabled', false)->assertDontSee('action="'.route('platform.tenants.update', $tenant).'"', false);
        $this->withSession(['locale' => 'ar', 'locale_user_selected' => true])->get(route('platform.tenants.organisation', $tenant))->assertOk()->assertSee(__('platform-tenant.learning_path'));
        $this->put(route('platform.tenants.update', $tenant), ['name' => 'Not allowed'])->assertForbidden();
        $this->assertSame('Readonly organisation', $tenant->fresh()->name);
    }

    private function user(?array $permissions = null): PlatformAdministrator
    {
        $actor = PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'QA staff', 'email' => Str::uuid().'@example.test', 'password' => 'qa-only-password', 'is_active' => true]);
        if ($permissions !== null) {
            $role = PlatformRole::query()->create(['name' => 'QA role '.Str::uuid()]);
            $role->permissions()->sync(PlatformPermission::query()->whereIn('code', $permissions)->pluck('id'));
            $actor->roles()->sync([$role->id]);
        }

        return $actor;
    }
}

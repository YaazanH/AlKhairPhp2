<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforcePlatformSupportAccess;
use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformAuditEvent;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\PlatformTenantHandoff;
use App\Models\Landlord\Tenant;
use App\Models\User;
use App\Services\Landlord\PlatformTenantAccess;
use App\Services\Landlord\TenantContext;
use App\Support\ApplicationTimezone;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as AuditActivity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformTenantSupportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        app(ApplicationTimezone::class)->apply('UTC');
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_owner_can_create_and_consume_a_single_use_handoff(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();

        [$handoff, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'edit', '127.0.0.1');

        $this->assertNotSame($token, $handoff->token_hash);
        $this->assertSame(hash('sha256', $token), $handoff->token_hash);
        $this->assertTrue($handoff->expires_at->between(now()->addMinutes(4), now()->addMinutes(5)));
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'tenant_support_handoff_created', 'tenant_id' => $tenant->id], 'landlord');

        [$user, $supportSession] = app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.2');

        $this->assertTrue($user->isPlatformAdministrator());
        $this->assertSame('Platform Management', $user->name);
        $this->assertStringEndsWith('@support.invalid', $user->email);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertSame('edit', $supportSession['access_level']);
        $this->assertSame($tenant->uuid, $supportSession['tenant_uuid']);
        $this->assertGreaterThan(now()->addMinutes(59)->timestamp, $supportSession['expires_at']);
        $this->assertDatabaseHas('platform_tenant_handoffs', ['id' => $handoff->id, 'consumed_ip_address' => '127.0.0.2'], 'landlord');
        $this->assertDatabaseHas('platform_audit_events', ['event' => 'tenant_support_session_started', 'tenant_id' => $tenant->id], 'landlord');

        try {
            app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.3');
            $this->fail('A consumed handoff must not be reusable.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_tenant_changes_hide_the_platform_identity_while_the_landlord_audit_keeps_it(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        [, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'edit', '127.0.0.1');
        [$supportUser] = app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.2');
        Auth::guard('web')->login($supportUser);

        User::query()->create([
            'name' => 'Tenant User',
            'username' => 'tenant-user',
            'email' => 'tenant-user@example.test',
            'password' => 'temporary-password',
            'is_active' => true,
        ]);

        $tenantActivity = AuditActivity::query()
            ->inLog('data-audit')
            ->where('causer_id', $supportUser->id)
            ->latest('id')
            ->firstOrFail();
        $platformActivity = PlatformAuditEvent::query()
            ->where('event', 'tenant_support_session_started')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('platform_management', $tenantActivity->getProperty('actor_scope'));
        $this->assertSame($administrator->id, $platformActivity->platform_administrator_id);
        $this->assertSame('Platform Administrator', $platformActivity->platformAdministrator->name);
        $this->assertSame($tenant->id, $platformActivity->tenant_id);
    }

    public function test_platform_permission_limits_the_handoff_level(): void
    {
        $this->administrator();
        $role = PlatformRole::query()->create(['name' => 'Read-only support']);
        $role->permissions()->sync([PlatformPermission::query()->where('code', 'support-access.read')->sole()->id]);
        $reader = $this->administrator('reader@example.test');
        $reader->roles()->sync([$role->id]);

        app(PlatformTenantAccess::class)->createHandoff($reader, $this->tenant(), 'read', '127.0.0.1');
        $this->assertSame(1, PlatformTenantHandoff::query()->count());

        $this->expectException(HttpException::class);
        app(PlatformTenantAccess::class)->createHandoff($reader, $this->tenant('second'), 'edit', '127.0.0.1');
    }

    public function test_platform_action_redirects_to_the_tenant_handoff_without_credentials(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        $tenant->domains()->create(['host' => 'demo.example.test', 'is_primary' => true]);

        $response = $this->actingAs($administrator, 'platform')->post(
            route('platform.tenants.support-access.store', $tenant),
            ['access_level' => 'read'],
        );

        $location = (string) $response->headers->get('Location');
        $response->assertRedirect();
        $this->assertSame('demo.example.test', parse_url($location, PHP_URL_HOST));
        $this->assertStringStartsWith('/support-access/', (string) parse_url($location, PHP_URL_PATH));
        $this->assertStringNotContainsString($administrator->email, $location);
        $this->assertStringNotContainsString('secret-password', $location);
    }

    public function test_read_session_blocks_writes_and_records_the_denial(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        [, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'read', '127.0.0.1');
        [$user, $supportSession] = app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.1');
        Auth::guard('web')->login($user);
        app(TenantContext::class)->set($tenant);

        $request = Request::create('/students', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(PlatformTenantAccess::SESSION_KEY, $supportSession);
        $route = (new Route(['POST'], '/students', fn () => null))->name('students.store');
        $request->setRouteResolver(fn () => $route);

        try {
            app(EnforcePlatformSupportAccess::class)->handle($request, fn () => response('allowed'));
            $this->fail('Read-only Platform support must not write tenant data.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertTrue(PlatformAuditEvent::query()->where('event', 'tenant_support_action_denied')->exists());
    }

    public function test_edit_session_blocks_destructive_livewire_calls(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        [, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'edit', '127.0.0.1');
        [$user, $supportSession] = app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.1');
        Auth::guard('web')->login($user);
        app(TenantContext::class)->set($tenant);

        $request = Request::create('/livewire/update', 'POST', [
            'components' => [['calls' => [['method' => 'deleteStudent']]]],
        ]);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(PlatformTenantAccess::SESSION_KEY, $supportSession);
        $route = (new Route(['POST'], '/livewire/update', fn () => null))->name('livewire.update');
        $request->setRouteResolver(fn () => $route);

        try {
            app(EnforcePlatformSupportAccess::class)->handle($request, fn () => response('allowed'));
            $this->fail('Edit support must not run destructive Livewire actions.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_expired_handoff_is_rejected_and_the_failure_is_audited(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        [$handoff, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'read', '127.0.0.1');
        $handoff->update(['expires_at' => now()->subMinute()]);

        try {
            app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.2');
            $this->fail('An expired handoff must not create a support session.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $event = PlatformAuditEvent::query()->where('event', 'tenant_support_handoff_denied')->latest('id')->firstOrFail();
        $this->assertSame('expired', $event->properties['reason']);
        $this->assertSame('127.0.0.2', $event->ip_address);
    }

    public function test_handoff_created_in_utc_remains_valid_after_tenant_timezone_is_applied(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        [, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'read', '127.0.0.1');

        app(ApplicationTimezone::class)->apply('Asia/Damascus');

        [$user, $supportSession] = app(PlatformTenantAccess::class)->consume($token, $tenant, '127.0.0.1');

        $this->assertTrue($user->isPlatformAdministrator());
        $this->assertSame('read', $supportSession['access_level']);
        $this->assertGreaterThan(CarbonImmutable::now('UTC')->addMinutes(59)->timestamp, $supportSession['expires_at']);
        $this->assertDatabaseHas('platform_audit_events', [
            'event' => 'tenant_support_session_started',
            'tenant_id' => $tenant->id,
        ], 'landlord');
    }

    public function test_tampered_handoff_signature_is_rejected(): void
    {
        $administrator = $this->administrator();
        $tenant = $this->tenant();
        [, $token] = app(PlatformTenantAccess::class)->createHandoff($administrator, $tenant, 'read', '127.0.0.1');

        try {
            app(PlatformTenantAccess::class)->consume($token.'changed', $tenant, '127.0.0.2');
            $this->fail('A modified handoff signature must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('platform_audit_events', [
            'event' => 'tenant_support_handoff_denied',
            'tenant_id' => $tenant->id,
            'ip_address' => '127.0.0.2',
        ], 'landlord');
    }

    private function administrator(string $email = 'owner@example.test'): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform Administrator',
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
        ]);
    }

    private function tenant(string $slug = 'demo'): Tenant
    {
        return Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'database_name' => 'tenant_'.$slug,
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }
}

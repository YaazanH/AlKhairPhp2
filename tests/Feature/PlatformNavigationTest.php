<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
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
        $expected = ['Tenants overview', 'New tenant', 'Packages', 'Subscriptions', 'Backups', 'Storage', 'Tenant requests', 'Landing page', 'Report library', 'Platform users'];

        foreach ([
            route('platform.dashboard'),
            route('platform.backups.index'),
            route('platform.storage.index'),
            route('platform.landing.edit'),
        ] as $url) {
            $response = $this->actingAs($owner, 'platform')->get($url)->assertOk();
            foreach ($expected as $label) {
                $response->assertSee($label);
            }
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
            ->assertSee('Tenants overview')
            ->assertSee('Landing page')
            ->assertDontSee('>Backups</div>', false)
            ->assertDontSee('>Storage</div>', false)
            ->assertDontSee('>Platform users</div>', false);
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
}

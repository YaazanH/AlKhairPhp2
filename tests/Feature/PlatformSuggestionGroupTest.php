<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformSuggestionGroup;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSuggestionGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(LandlordCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');

        parent::tearDown();
    }

    public function test_platform_owner_can_group_forwarded_suggestions_without_losing_the_original_cases(): void
    {
        $owner = $this->administrator('owner@example.test');
        $first = $this->suggestionCase('first');
        $second = $this->suggestionCase('second');

        $this->actingAs($owner, 'platform')
            ->post(route('platform.support.suggestion-groups.store'), [
                'title' => 'Weekly summary reporting',
                'summary' => 'Requests for a shared weekly summary.',
            ])
            ->assertRedirect();

        $group = PlatformSuggestionGroup::query()->sole();
        foreach ([$first, $second] as $case) {
            $this->actingAs($owner, 'platform')
                ->put(route('platform.support.update', $case), [
                    'status' => PlatformSupportCase::STATUS_PLANNED,
                    'platform_suggestion_group_id' => $group->id,
                ])
                ->assertRedirect();
        }

        $this->assertSame($group->id, $first->fresh()->platform_suggestion_group_id);
        $this->assertSame($group->id, $second->fresh()->platform_suggestion_group_id);
        $this->assertSame(2, $group->cases()->count());
        $this->assertSame('first', $first->fresh()->tenant_support_request_id);
        $this->assertSame('second', $second->fresh()->tenant_support_request_id);
    }

    public function test_platform_user_without_suggestion_permission_cannot_create_or_group_suggestions(): void
    {
        $this->administrator('owner@example.test');
        $staff = $this->administrator('staff@example.test');
        $case = $this->suggestionCase('first');

        $this->actingAs($staff, 'platform')
            ->post(route('platform.support.suggestion-groups.store'), ['title' => 'No access'])
            ->assertForbidden();
        $this->actingAs($staff, 'platform')
            ->put(route('platform.support.update', $case), ['status' => PlatformSupportCase::STATUS_UNDER_REVIEW])
            ->assertForbidden();
    }

    private function administrator(string $email): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform administrator',
            'email' => $email,
            'password' => 'secret-password',
        ]);
    }

    private function suggestionCase(string $requestId): PlatformSupportCase
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Centre '.$requestId,
            'slug' => 'centre-'.$requestId,
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        return PlatformSupportCase::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_support_request_id' => $requestId,
            'type' => 'suggestion',
            'status' => PlatformSupportCase::STATUS_FORWARDED,
            'subject' => 'Weekly summary',
            'message' => 'A weekly summary would help.',
            'forwarded_at' => now(),
        ]);
    }
}

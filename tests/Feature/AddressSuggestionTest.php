<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AddressSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AddressSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.photon.enabled' => true]);
        Cache::flush();
        RateLimiter::clear('address-provider');
        Http::preventStrayRequests();
    }

    public function test_online_results_use_known_address_parts_and_cache_repeated_queries(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [
            ['properties' => ['name' => 'ساحة الروضة', 'district' => 'حي أبو رمانة', 'city' => 'بلدية المهاجرين', 'state' => 'محافظة دمشق', 'type' => 'locality', 'osm_key' => 'place', 'osm_value' => 'square']],
            ['properties' => ['name' => 'حمص', 'type' => 'city']],
        ]])]);
        $service = app(AddressSuggestionService::class);
        $results = $service->suggest('دمشق الروضة');
        $this->assertSame('دمشق - أبو رمانة - ساحة الروضة', $results[0]['value']);
        $this->assertSame('حمص', $results[1]['value']);
        $this->assertSame($results, $service->suggest('دمشق الروضة'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['q'] === 'دمشق الروضة' && $request['countrycode'] === 'SY');
    }

    public function test_unavailable_provider_does_not_block_address_entry(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame([], app(AddressSuggestionService::class)->suggest('دمشق'));
        config(['services.photon.enabled' => false]);
        $this->assertSame([], app(AddressSuggestionService::class)->suggest('حمص'));
        Http::assertSentCount(1);
    }

    public function test_area_completion_does_not_append_an_unrequested_business_or_street(): void
    {
        Http::fake(['*' => Http::response(['features' => [
            ['properties' => ['name' => 'شركة كهرباء', 'district' => 'حي الروضة', 'street' => 'شارع زياد', 'state' => 'محافظة دمشق']],
        ]])]);
        $this->assertSame('دمشق - الروضة', app(AddressSuggestionService::class)->suggest('دمشق - الرو')[0]['value']);
    }

    public function test_endpoint_requires_permission_and_validates_query(): void
    {
        $this->getJson(route('address-suggestions', ['q' => 'دمشق']))->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('address-suggestions', ['q' => 'دمشق']))->assertForbidden();
        Permission::findOrCreate('parents.create', 'web');
        $user->givePermissionTo('parents.create');
        $this->getJson(route('address-suggestions', ['q' => 'د']))->assertUnprocessable();
        Http::fake(['*' => Http::response(['features' => []])]);
        $this->getJson(route('address-suggestions', ['q' => 'دمشق']))->assertOk()->assertExactJson(['suggestions' => []]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use App\Models\TenantSupportRequest;
use App\Services\Landlord\SupportCaseForwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportCaseForwarderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.landlord', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('landlord');
        Artisan::call('migrate', [
            '--database' => 'landlord',
            '--path' => database_path('migrations/landlord'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');

        parent::tearDown();
    }

    public function test_forwarding_updated_tenant_request_preserves_platform_case_progress_and_reply(): void
    {
        $tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Centre',
            'slug' => 'centre',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $request = new TenantSupportRequest([
            'type' => TenantSupportRequest::TYPE_PROBLEM,
            'problem_reason' => 'technical_issue',
            'incident_reference' => 'INC-20261001-ABC123',
            'subject' => 'Cannot save attendance',
            'message' => 'The save button does not respond.',
        ]);
        $request->id = 77;
        $forwarder = app(SupportCaseForwarder::class);

        $case = $forwarder->forward($tenant, $request);
        $case->update([
            'status' => PlatformSupportCase::STATUS_INVESTIGATING,
            'platform_note' => 'We are checking the error.',
        ]);

        $request->message = 'The problem happens only for the morning group.';
        $forwardedAgain = $forwarder->forward($tenant, $request);

        $this->assertSame($case->id, $forwardedAgain->id);
        $this->assertSame(PlatformSupportCase::STATUS_INVESTIGATING, $forwardedAgain->status);
        $this->assertSame('We are checking the error.', $forwardedAgain->platform_note);
        $this->assertSame('The problem happens only for the morning group.', $forwardedAgain->message);
        $this->assertSame('INC-20261001-ABC123', $forwardedAgain->incident_reference);
        $this->assertSame('technical_issue', $forwardedAgain->problem_reason);
        $this->assertDatabaseCount('platform_support_cases', 1, 'landlord');
    }

    public function test_problem_and_suggestion_receive_their_own_valid_statuses(): void
    {
        $this->assertContains(TenantSupportRequest::STATUS_RESOLVED, TenantSupportRequest::statusesForType('problem'));
        $this->assertNotContains(TenantSupportRequest::STATUS_RELEASED, TenantSupportRequest::statusesForType('problem'));
        $this->assertContains(PlatformSupportCase::STATUS_INVESTIGATING, PlatformSupportCase::statusesForType('problem'));
        $this->assertNotContains(PlatformSupportCase::STATUS_INVESTIGATING, PlatformSupportCase::statusesForType('suggestion'));
    }
}

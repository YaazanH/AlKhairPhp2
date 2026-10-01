<?php

namespace Tests\Feature;

use App\Models\TenantSupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantSupportContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_problem_reports_receive_a_safe_reference_and_automatic_context(): void
    {
        config()->set('app.version', 'release-2026.10.01');
        $reporter = User::factory()->create();
        Permission::findOrCreate('support.problems.submit', 'web');
        $reporter->givePermissionTo('support.problems.submit');

        $this->actingAs($reporter)
            ->withHeader('User-Agent', 'SupportContextTest/1.0')
            ->post(route('support.store').'?access_token=must-not-be-recorded', [
                'type' => TenantSupportRequest::TYPE_PROBLEM,
                'subject' => 'Cannot save attendance',
                'message' => 'The save button does not respond.',
                'expected_result' => 'The attendance entry should be saved.',
                'impact' => TenantSupportRequest::IMPACT_SEVERAL_USERS,
                'priority' => 'high',
            ])
            ->assertRedirect();

        $report = TenantSupportRequest::query()->sole();
        $this->assertMatchesRegularExpression('/^INC-\d{8}-[A-Z0-9]{6}$/', $report->incident_reference);
        $this->assertSame('release-2026.10.01', $report->app_version);
        $this->assertSame('SupportContextTest/1.0', $report->browser_info);
        $this->assertStringNotContainsString('access_token', $report->reported_url);
        $this->assertSame(url('/support'), $report->reported_url);
    }

    public function test_suggestions_do_not_receive_problem_incident_context(): void
    {
        $reporter = User::factory()->create();
        Permission::findOrCreate('support.suggestions.submit', 'web');
        $reporter->givePermissionTo('support.suggestions.submit');

        $this->actingAs($reporter)
            ->post(route('support.store'), [
                'type' => TenantSupportRequest::TYPE_SUGGESTION,
                'subject' => 'Weekly summary',
                'message' => 'A weekly summary would help.',
            ])
            ->assertRedirect();

        $suggestion = TenantSupportRequest::query()->sole();
        $this->assertNull($suggestion->incident_reference);
        $this->assertNull($suggestion->reported_url);
        $this->assertNull($suggestion->browser_info);
        $this->assertNull($suggestion->app_version);
    }
}

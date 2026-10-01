<?php

namespace Tests\Feature;

use App\Models\TenantSupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantSupportSuggestionDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_capture_product_context(): void
    {
        $reporter = User::factory()->create();
        Permission::findOrCreate('support.suggestions.submit', 'web');
        $reporter->givePermissionTo('support.suggestions.submit');

        $this->actingAs($reporter)
            ->post(route('support.store'), [
                'type' => TenantSupportRequest::TYPE_SUGGESTION,
                'subject' => 'Weekly summary',
                'message' => 'A weekly summary would help staff plan their work.',
                'desired_outcome' => 'See attendance and progress for the previous week in one place.',
                'current_workaround' => 'Staff export each report separately.',
                'affected_users' => 'Teachers and centre administrators.',
                'business_impact' => TenantSupportRequest::BUSINESS_IMPACT_MEDIUM,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_support_requests', [
            'desired_outcome' => 'See attendance and progress for the previous week in one place.',
            'current_workaround' => 'Staff export each report separately.',
            'affected_users' => 'Teachers and centre administrators.',
            'business_impact' => TenantSupportRequest::BUSINESS_IMPACT_MEDIUM,
        ]);
    }

    public function test_problem_reports_do_not_keep_suggestion_only_details(): void
    {
        $reporter = User::factory()->create();
        Permission::findOrCreate('support.problems.submit', 'web');
        $reporter->givePermissionTo('support.problems.submit');

        $this->actingAs($reporter)
            ->post(route('support.store'), [
                'type' => TenantSupportRequest::TYPE_PROBLEM,
                'subject' => 'Cannot save attendance',
                'message' => 'The save button does not respond.',
                'expected_result' => 'The attendance entry should be saved.',
                'impact' => TenantSupportRequest::IMPACT_SEVERAL_USERS,
                'priority' => 'high',
                'desired_outcome' => 'This must not be stored.',
                'current_workaround' => 'This must not be stored.',
                'affected_users' => 'This must not be stored.',
                'business_impact' => TenantSupportRequest::BUSINESS_IMPACT_HIGH,
            ])
            ->assertRedirect();

        $problem = TenantSupportRequest::query()->sole();
        $this->assertNull($problem->desired_outcome);
        $this->assertNull($problem->current_workaround);
        $this->assertNull($problem->affected_users);
        $this->assertNull($problem->business_impact);
    }
}

<?php

namespace Tests\Feature;

use App\Models\TenantSupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantSupportProblemDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_problem_reports_capture_expected_result_and_impact(): void
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
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_support_requests', [
            'expected_result' => 'The attendance entry should be saved.',
            'impact' => TenantSupportRequest::IMPACT_SEVERAL_USERS,
            'priority' => 'high',
        ]);
    }

    public function test_suggestions_do_not_keep_problem_only_details(): void
    {
        $reporter = User::factory()->create();
        Permission::findOrCreate('support.suggestions.submit', 'web');
        $reporter->givePermissionTo('support.suggestions.submit');

        $this->actingAs($reporter)
            ->post(route('support.store'), [
                'type' => TenantSupportRequest::TYPE_SUGGESTION,
                'subject' => 'Weekly summary',
                'message' => 'A weekly summary would help.',
                'expected_result' => 'This must not be stored.',
                'impact' => TenantSupportRequest::IMPACT_ALL_USERS,
                'priority' => 'critical',
            ])
            ->assertRedirect();

        $suggestion = TenantSupportRequest::query()->sole();
        $this->assertNull($suggestion->expected_result);
        $this->assertNull($suggestion->impact);
        $this->assertNull($suggestion->priority);
    }

    public function test_tenant_administrator_can_correct_problem_priority_before_forwarding(): void
    {
        $reporter = User::factory()->create();
        $administrator = User::factory()->create();
        Permission::findOrCreate('support.manage', 'web');
        $administrator->givePermissionTo('support.manage');
        $request = TenantSupportRequest::query()->create([
            'type' => TenantSupportRequest::TYPE_PROBLEM,
            'status' => TenantSupportRequest::STATUS_SUBMITTED,
            'subject' => 'Cannot save attendance',
            'message' => 'The save button does not respond.',
            'expected_result' => 'The attendance entry should be saved.',
            'impact' => TenantSupportRequest::IMPACT_SEVERAL_USERS,
            'priority' => 'normal',
            'submitted_by_user_id' => $reporter->id,
        ]);

        $this->actingAs($administrator)
            ->put(route('support.update', $request), [
                'status' => TenantSupportRequest::STATUS_UNDER_REVIEW,
                'priority' => 'high',
            ])
            ->assertRedirect();

        $this->assertSame('high', $request->fresh()->priority);
    }
}

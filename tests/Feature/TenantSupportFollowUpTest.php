<?php

namespace Tests\Feature;

use App\Models\TenantSupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantSupportFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitter_can_add_information_to_their_open_request_but_not_a_closed_request(): void
    {
        $reporter = User::factory()->create();
        $request = TenantSupportRequest::query()->create([
            'type' => TenantSupportRequest::TYPE_PROBLEM,
            'status' => TenantSupportRequest::STATUS_SUBMITTED,
            'subject' => 'Cannot save attendance',
            'message' => 'The save button does not respond.',
            'submitted_by_user_id' => $reporter->id,
        ]);

        $this->actingAs($reporter)
            ->post(route('support.messages.store', $request), ['message' => 'It happens in the morning group.'])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_support_messages', [
            'tenant_support_request_id' => $request->id,
            'sender_user_id' => $reporter->id,
            'is_tenant_administrator' => false,
            'message' => 'It happens in the morning group.',
        ]);

        $request->update(['status' => TenantSupportRequest::STATUS_CLOSED]);

        $this->actingAs($reporter)
            ->post(route('support.messages.store', $request), ['message' => 'One more detail.'])
            ->assertForbidden();
    }

    public function test_another_tenant_user_cannot_reply_to_someone_elses_request_but_an_administrator_can(): void
    {
        $reporter = User::factory()->create();
        $otherUser = User::factory()->create();
        $administrator = User::factory()->create();
        Permission::findOrCreate('support.manage', 'web');
        $administrator->givePermissionTo('support.manage');
        $request = TenantSupportRequest::query()->create([
            'type' => TenantSupportRequest::TYPE_SUGGESTION,
            'status' => TenantSupportRequest::STATUS_SUBMITTED,
            'subject' => 'Add a weekly summary',
            'message' => 'A weekly summary would help staff.',
            'submitted_by_user_id' => $reporter->id,
        ]);

        $this->actingAs($otherUser)
            ->post(route('support.messages.store', $request), ['message' => 'I have an idea.'])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('support.messages.store', $request), ['message' => 'Thank you. We are reviewing this.'])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_support_messages', [
            'tenant_support_request_id' => $request->id,
            'sender_user_id' => $administrator->id,
            'is_tenant_administrator' => true,
            'message' => 'Thank you. We are reviewing this.',
        ]);
    }
}

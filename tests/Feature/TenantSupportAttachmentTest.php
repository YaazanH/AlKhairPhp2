<?php

namespace Tests\Feature;

use App\Models\TenantSupportAttachment;
use App\Models\TenantSupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantSupportAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitter_can_attach_a_problem_file_and_download_it_from_private_storage(): void
    {
        Storage::fake('local');
        $reporter = User::factory()->create();
        $request = $this->problem($reporter);

        $this->actingAs($reporter)
            ->post(route('support.attachments.store', $request), [
                'attachment' => UploadedFile::fake()->image('attendance-error.png')->size(120),
            ])
            ->assertRedirect();

        $attachment = TenantSupportAttachment::query()->sole();
        $this->assertSame($request->id, $attachment->tenant_support_request_id);
        $this->assertSame($reporter->id, $attachment->uploaded_by_user_id);
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($reporter)
            ->get(route('support.attachments.download', $attachment))
            ->assertOk();
    }

    public function test_only_the_submitter_or_tenant_administrator_can_access_problem_attachments(): void
    {
        Storage::fake('local');
        $reporter = User::factory()->create();
        $otherUser = User::factory()->create();
        $administrator = User::factory()->create();
        Permission::findOrCreate('support.manage', 'web');
        $administrator->givePermissionTo('support.manage');
        $request = $this->problem($reporter);
        $attachment = $request->attachments()->create([
            'uploaded_by_user_id' => $reporter->id,
            'path' => 'support/'.$request->id.'/error.png',
            'original_name' => 'error.png',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
        ]);
        Storage::disk('local')->put($attachment->path, 'image-content');

        $this->actingAs($otherUser)
            ->get(route('support.attachments.download', $attachment))
            ->assertForbidden();

        $this->actingAs($administrator)
            ->get(route('support.attachments.download', $attachment))
            ->assertOk();
    }

    public function test_suggestions_do_not_accept_attachments(): void
    {
        Storage::fake('local');
        $reporter = User::factory()->create();
        $request = TenantSupportRequest::query()->create([
            'type' => TenantSupportRequest::TYPE_SUGGESTION,
            'status' => TenantSupportRequest::STATUS_SUBMITTED,
            'subject' => 'Weekly summary',
            'message' => 'A summary would help.',
            'submitted_by_user_id' => $reporter->id,
        ]);

        $this->actingAs($reporter)
            ->post(route('support.attachments.store', $request), [
                'attachment' => UploadedFile::fake()->create('idea.pdf', 100, 'application/pdf'),
            ])
            ->assertNotFound();
    }

    private function problem(User $reporter): TenantSupportRequest
    {
        return TenantSupportRequest::query()->create([
            'type' => TenantSupportRequest::TYPE_PROBLEM,
            'status' => TenantSupportRequest::STATUS_SUBMITTED,
            'subject' => 'Cannot save attendance',
            'message' => 'The save button does not respond.',
            'submitted_by_user_id' => $reporter->id,
        ]);
    }
}

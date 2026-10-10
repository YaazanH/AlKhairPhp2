<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use App\Models\TenantSupportAttachment;
use App\Models\TenantSupportRequest;
use App\Models\User;
use App\Services\Landlord\TenantScopedContext;
use Database\Seeders\LandlordCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformSupportAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantDatabase;

    private Tenant $tenant;

    private PlatformSupportCase $case;

    private TenantSupportAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(LandlordCatalogSeeder::class);

        $this->tenantDatabase = storage_path('framework/testing/platform-support-'.Str::uuid().'.sqlite');
        File::ensureDirectoryExists(dirname($this->tenantDatabase));
        touch($this->tenantDatabase);
        config()->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => $this->tenantDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('tenant');
        Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]);

        $this->tenant = Tenant::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Centre',
            'slug' => 'centre',
            'database_name' => $this->tenantDatabase,
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $this->case = PlatformSupportCase::query()->create([
            'tenant_id' => $this->tenant->id,
            'tenant_support_request_id' => 77,
            'type' => 'problem',
            'status' => PlatformSupportCase::STATUS_FORWARDED,
            'subject' => 'Cannot save attendance',
            'message' => 'The save button does not respond.',
            'forwarded_at' => now(),
        ]);
        $this->attachment = app(TenantScopedContext::class)->run($this->tenant, function (): TenantSupportAttachment {
            $reporter = User::factory()->create();
            $request = new TenantSupportRequest([
                'type' => TenantSupportRequest::TYPE_PROBLEM,
                'status' => TenantSupportRequest::STATUS_SUBMITTED,
                'subject' => 'Cannot save attendance',
                'message' => 'The save button does not respond.',
                'submitted_by_user_id' => $reporter->id,
            ]);
            $request->id = 77;
            $request->save();
            Storage::disk('local')->put('support/77/error.png', 'image-content');

            return $request->attachments()->create([
                'uploaded_by_user_id' => $reporter->id,
                'path' => 'support/77/error.png',
                'original_name' => 'error.png',
                'mime_type' => 'image/png',
                'size_bytes' => 13,
            ]);
        });
    }

    protected function tearDown(): void
    {
        DB::purge('tenant');
        DB::purge('landlord');
        File::delete($this->tenantDatabase);
        File::deleteDirectory(storage_path('app/private/tenants/'.strtolower($this->tenant->uuid)));

        parent::tearDown();
    }

    public function test_platform_owner_can_list_and_download_attachments_from_a_forwarded_problem(): void
    {
        $owner = $this->platformAdministrator('owner@example.test');

        $this->actingAs($owner, 'platform')
            ->get(route('platform.support.attachments.index', $this->case))
            ->assertOk()
            ->assertSee('error.png');

        $this->actingAs($owner, 'platform')
            ->get(route('platform.support.attachments.download', [$this->case, $this->attachment->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertDatabaseHas('platform_audit_events', ['tenant_id' => $this->tenant->id, 'event' => 'platform_support_attachment_downloaded'], 'landlord');
    }

    public function test_platform_user_without_problem_permission_cannot_access_attachments(): void
    {
        $this->platformAdministrator('owner@example.test');

        $this->actingAs($this->platformAdministrator('staff@example.test'), 'platform')
            ->get(route('platform.support.attachments.index', $this->case))
            ->assertForbidden();
    }

    private function platformAdministrator(string $email): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Platform admin',
            'email' => $email,
            'password' => 'secret-password',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformAdministrator;
use App\Models\Landlord\PlatformLandingEnquiry;
use App\Models\Landlord\PlatformPermission;
use App\Models\Landlord\PlatformRole;
use App\Models\Landlord\PlatformSuggestionGroup;
use App\Models\Landlord\PlatformSupportCase;
use App\Models\Landlord\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformRequestsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_queue_is_paginated_filtered_and_does_not_render_reply_forms(): void
    {
        $owner = $this->user();
        $tenant = $this->tenant();
        for ($i = 0; $i < 23; $i++) {
            $this->case($tenant, 'problem', sprintf('Problem %02d', $i));
        }
        $this->case($tenant, 'suggestion', 'Private suggestion');
        $this->actingAs($owner, 'platform')->get(route('platform.support.index'))->assertOk()->assertViewHas('cases', fn ($cases) => $cases->total() === 23 && $cases->count() === 20)->assertDontSee('Private suggestion')->assertDontSee('name="platform_note"', false);
        $this->get(route('platform.support.index', ['search' => 'Problem 22', 'status' => 'attention', 'tenant' => $tenant->id]))->assertOk()->assertViewHas('cases', fn ($cases) => $cases->total() === 1)->assertSee('Problem 22')->assertDontSee('Problem 00');
        $this->get(route('platform.support.index', ['type' => 'suggestion', 'view' => 'board']))->assertOk()->assertSee('Private suggestion')->assertSee('current page of matching requests');
    }

    public function test_detail_preserves_status_rules_response_and_case_specific_audit(): void
    {
        $owner = $this->user();
        $case = $this->case($this->tenant(), 'problem', 'Attendance issue');
        $this->actingAs($owner, 'platform')->get(route('platform.support.show', $case))->assertOk()->assertSee('name="platform_note"', false)->assertSee('not a private note')->assertSee(route('platform.support.attachments.index', $case), false);
        $this->from(route('platform.support.show', $case))->put(route('platform.support.update', $case), ['status' => 'planned'])->assertSessionHasErrors('status');
        $this->put(route('platform.support.update', $case), ['status' => 'investigating', 'platform_note' => 'Checking the issue.'])->assertRedirect();
        $this->get(route('platform.support.show', $case))->assertOk()->assertSee('Checking the issue.')->assertViewHas('history', fn ($events) => $events->count() === 1);
        $this->assertSame('investigating', $case->fresh()->status);
    }

    public function test_problem_and_suggestion_permissions_cover_details_updates_groups_and_enquiries(): void
    {
        $this->user();
        $staff = $this->user(['manage.support.problems']);
        $tenant = $this->tenant();
        $problem = $this->case($tenant, 'problem', 'Allowed problem');
        $suggestion = $this->case($tenant, 'suggestion', 'Hidden suggestion');
        $this->actingAs($staff, 'platform')->get(route('platform.support.index', ['type' => 'suggestion']))->assertOk()->assertSee('Allowed problem')->assertDontSee('Hidden suggestion');
        $this->get(route('platform.support.show', $problem))->assertOk();
        $this->get(route('platform.support.show', $suggestion))->assertForbidden();
        $this->put(route('platform.support.update', $suggestion), ['status' => 'planned'])->assertForbidden();
        $this->get(route('platform.support.groups'))->assertForbidden();
        $this->post(route('platform.support.suggestion-groups.store'), ['title' => 'Forbidden'])->assertForbidden();
        $this->get(route('platform.enquiries.index'))->assertForbidden();
    }

    public function test_website_manager_has_readonly_enquiries_without_support_access(): void
    {
        $this->user();
        $manager = $this->user(['manage.landing-page']);
        $enquiry = PlatformLandingEnquiry::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Visitor', 'organisation_name' => 'Visitor Centre', 'email' => 'visitor@example.test', 'phone' => '0123456', 'message' => '<script>bad()</script>', 'locale' => 'en']);
        $this->actingAs($manager, 'platform')->get(route('platform.support.index'))->assertRedirect(route('platform.enquiries.index'));
        $this->get(route('platform.enquiries.index', ['search' => 'Visitor Centre']))->assertOk()->assertSee('Visitor')->assertViewHas('enquiries', fn ($enquiries) => $enquiries->total() === 1);
        $this->get(route('platform.enquiries.show', $enquiry))->assertOk()->assertSee('visitor@example.test')->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)->assertDontSee('name="platform_note"', false);
        $this->get(route('platform.support.groups'))->assertForbidden();
        $publisher = $this->user(['publish.landing-page']);
        $this->actingAs($publisher, 'platform')->get(route('platform.enquiries.show', $enquiry))->assertForbidden();
    }

    public function test_shared_groups_are_separate_and_filter_related_requests(): void
    {
        $owner = $this->user();
        $this->actingAs($owner, 'platform')->from(route('platform.support.groups'))->post(route('platform.support.suggestion-groups.store'), ['title' => 'Attendance improvements', 'summary' => 'Related requests.'])->assertRedirect(route('platform.support.groups'));
        $group = PlatformSuggestionGroup::query()->sole();
        $case = $this->case($this->tenant(), 'suggestion', 'Better attendance');
        $this->put(route('platform.support.update', $case), ['status' => 'planned', 'platform_suggestion_group_id' => $group->id])->assertRedirect();
        $this->get(route('platform.support.groups'))->assertOk()->assertSee('Attendance improvements')->assertSee('Review 1 related requests');
        $this->get(route('platform.support.index', ['type' => 'suggestion', 'group' => $group->id]))->assertOk()->assertViewHas('cases', fn ($cases) => $cases->total() === 1);
        $this->withSession(['locale' => 'ar', 'locale_user_selected' => true])->get(route('platform.support.show', $case))->assertOk()->assertSee('dir="rtl"', false)->assertSee('رد إلى مدير المؤسسة');
    }

    public function test_new_request_routes_redirect_guests_to_platform_login(): void
    {
        $case = $this->case($this->tenant(), 'problem', 'Guest test');
        foreach ([route('platform.support.show', $case), route('platform.enquiries.index'), route('platform.support.groups')] as $url) {
            $this->get($url)->assertRedirect(route('platform.login'));
        }
    }

    private function user(?array $permissions = null): PlatformAdministrator
    {
        $user = PlatformAdministrator::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Platform reviewer', 'email' => Str::uuid().'@example.test', 'password' => 'test-password', 'is_active' => true]);
        if ($permissions !== null) {
            $role = PlatformRole::query()->create(['name' => 'Reviewer '.Str::uuid()]);
            $role->permissions()->sync(PlatformPermission::query()->whereIn('code', $permissions)->pluck('id'));
            $user->roles()->sync([$role->id]);
        }

        return $user;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Request Centre', 'slug' => 'centre-'.Str::uuid(), 'status' => 'active']);
    }

    private function case(Tenant $tenant, string $type, string $subject): PlatformSupportCase
    {
        return PlatformSupportCase::query()->create(['tenant_id' => $tenant->id, 'tenant_support_request_id' => PlatformSupportCase::query()->count() + 1, 'type' => $type, 'subject' => $subject, 'message' => 'Request context', 'status' => 'forwarded', 'forwarded_at' => now()]);
    }
}

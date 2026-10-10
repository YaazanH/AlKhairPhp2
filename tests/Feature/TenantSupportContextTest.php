<?php

namespace Tests\Feature;

use App\Models\Landlord\PlatformSupportCase;
use App\Models\TenantSupportRequest;
use App\Models\User;
use App\Services\SidebarNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TenantSupportContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_navigation_is_visible_only_to_users_with_a_support_permission(): void
    {
        $user = User::factory()->create();
        $navigation = app(SidebarNavigationService::class);

        $this->assertNull(collect($navigation->sidebarFor($user))
            ->pluck('items')
            ->flatten(1)
            ->firstWhere('key', 'support'));

        Permission::findOrCreate('support.suggestions.submit', 'web');
        $user->givePermissionTo('support.suggestions.submit');

        $supportItem = collect($navigation->sidebarFor($user->fresh()))
            ->pluck('items')
            ->flatten(1)
            ->firstWhere('key', 'support');

        $this->assertNotNull($supportItem);
        $this->assertSame(route('support.index'), $supportItem['href']);
        $this->assertSame(__('ui.nav.help_requests'), $supportItem['label']);
    }

    public function test_support_request_copy_and_statuses_are_localized_to_arabic(): void
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('ar');

            $this->assertSame('مشكلة', TenantSupportRequest::typeLabel(TenantSupportRequest::TYPE_PROBLEM));
            $this->assertSame('عالية', TenantSupportRequest::priorityLabel('high'));
            $this->assertSame('قيد المراجعة', TenantSupportRequest::statusLabel(TenantSupportRequest::STATUS_UNDER_REVIEW));
            $this->assertSame('بانتظار المؤسسة', PlatformSupportCase::statusLabel(PlatformSupportCase::STATUS_WAITING_FOR_TENANT));

            $reporter = User::factory()->create();
            Permission::findOrCreate('support.suggestions.submit', 'web');
            $reporter->givePermissionTo('support.suggestions.submit');

            $this->actingAs($reporter)
                ->withSession(['locale' => 'ar', 'locale_user_selected' => true])
                ->get(route('support.index'))
                ->assertOk()
                ->assertSeeText('المساعدة والتحسينات')
                ->assertSeeText('اقتراح تحسين')
                ->assertSeeText('إرسال الطلب')
                ->assertSeeText('لا توجد طلبات بعد.');
        } finally {
            app()->setLocale($originalLocale);
        }
    }

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
                'problem_reason' => 'technical_issue',
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
                'desired_outcome' => 'See the weekly summary in one place.',
                'affected_users' => 'Teachers and administrators.',
                'business_impact' => TenantSupportRequest::BUSINESS_IMPACT_MEDIUM,
            ])
            ->assertRedirect();

        $suggestion = TenantSupportRequest::query()->sole();
        $this->assertNull($suggestion->incident_reference);
        $this->assertNull($suggestion->reported_url);
        $this->assertNull($suggestion->browser_info);
        $this->assertNull($suggestion->app_version);
    }
}

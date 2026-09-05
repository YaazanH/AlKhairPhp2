<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ParentProfile;
use App\Models\DataQualityResolution;
use App\Models\Student;
use App\Models\SystemBackup;
use App\Models\User;
use App\Services\DataQualityService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity as AuditActivity;
use Tests\TestCase;

class DataGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_both_data_governance_windows(): void
    {
        $this->assertSame('Database Audit', trans('data_governance.quality.title', locale: 'en'));
        $this->assertSame('Database Movements', trans('data_governance.audit.title', locale: 'en'));
        $this->assertSame('Database Audit', trans('ui.nav.data_quality', locale: 'en'));
        $this->assertSame('Database Movements', trans('ui.nav.data_audit', locale: 'en'));
        $this->assertSame('تدقيق قاعدة البيانات', trans('data_governance.quality.title', locale: 'ar'));
        $this->assertSame('حركات قاعدة البيانات', trans('data_governance.audit.title', locale: 'ar'));
        $this->assertSame('تدقيق البيانات', trans('ui.nav.data_quality', locale: 'ar'));
        $this->assertSame('حركات البيانات', trans('ui.nav.data_audit', locale: 'ar'));
        $this->assertSame('ابحث', trans('data_governance.quality.search_placeholder', locale: 'ar'));
        $this->assertSame('ابحث', trans('data_governance.audit.search_placeholder', locale: 'ar'));

        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('data-quality.index', absolute: false))
            ->assertOk()
            ->assertSeeText(__('data_governance.quality.title'))
            ->assertSee('<title>'.__('data_governance.quality.title').' | '.__('ui.app.name').'</title>', false);

        $this->get(route('data-audit.index', absolute: false))
            ->assertOk()
            ->assertSeeText(__('data_governance.audit.title'))
            ->assertSee('<title>'.__('data_governance.audit.title').' | '.__('ui.app.name').'</title>', false);

        $qualityView = file_get_contents(resource_path('views/livewire/data-quality/index.blade.php'));
        $this->assertStringContainsString('data-data-quality-review-action', $qualityView);
        $this->assertStringContainsString('wire:init="refreshTable"', $qualityView);
        $this->assertStringContainsString('data-data-quality-refresh-on-open', $qualityView);
        $this->assertStringContainsString('<x-admin-action-icon name="review" />', $qualityView);
        $reviewIcon = file_get_contents(resource_path('views/components/admin-action-icon.blade.php'));
        $this->assertStringContainsString('data-review-icon="supplied-gear-wrench"', $reviewIcon);
        $this->assertStringContainsString("'review' => '-8 -8 146.03 146.35'", $reviewIcon);
        $this->assertStringContainsString('class="admin-icon-button"', $qualityView);
        $this->assertStringContainsString('data-data-quality-record-panel', $qualityView);
        $this->assertStringContainsString('data-data-quality-record-details', $qualityView);
        $this->assertStringContainsString('wire:change="autosaveRecord(', $qualityView);
        $this->assertStringContainsString('data-data-quality-duplicate-record-actions', $qualityView);
        $this->assertStringContainsString('data-data-quality-edit-record', $qualityView);
        $this->assertStringContainsString('data-data-quality-delete-record', $qualityView);
        $this->assertStringNotContainsString('<x-clear-filter-button', $qualityView);
        $this->assertStringNotContainsString('wire:click="saveRecordEdits"', $qualityView);
        $this->assertStringNotContainsString("wire:click=\"decide('resolved')\"", $qualityView);
        $this->assertStringContainsString("wire:click=\"decide('not_duplicate')\" class=\"pill-link\" data-data-quality-not-duplicate-text-action", $qualityView);
        $this->assertStringNotContainsString('statusFilter', $qualityView);
        $this->assertStringNotContainsString('data-quality-status', $qualityView);
        $this->assertStringNotContainsString("__('data_governance.quality.all_statuses')", $qualityView);
        $this->assertStringNotContainsString("data_governance.quality.fields.notes", $qualityView);
        $parentEditor = file_get_contents(resource_path('views/livewire/data-quality/partials/parent-editor.blade.php'));
        $this->assertStringNotContainsString("data_governance.quality.fields.notes", $parentEditor);
        $this->assertStringContainsString("except(['deleted_at', 'notes'])", $qualityView);

        $auditView = file_get_contents(resource_path('views/livewire/data-audit/index.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('public int $perPage = 15;', $auditView);
        $this->assertStringContainsString('data-data-audit-view-action', $auditView);
        $this->assertStringContainsString('<x-admin-action-icon name="search" />', $auditView);
        $this->assertStringContainsString('data-data-audit-table', $auditView);
        $this->assertSame(5, substr_count($auditView, 'data-data-audit-content-column'));
        $this->assertStringContainsString("{{ __('data_governance.audit.module') }}</th><th class=\"px-5 py-4 text-start\">{{ __('data_governance.audit.record') }}</th><th class=\"px-5 py-4 text-center\">{{ __('data_governance.audit.event') }}", $auditView);
        $this->assertStringContainsString('data-audit-log-table__number-column', $auditView);
        $this->assertStringContainsString('data-audit-log-table__details-column', $auditView);
        $this->assertStringContainsString(".data-audit-log-table {\n    min-width: 70rem;\n    table-layout: fixed;", $styles);
        $this->assertStringContainsString(".data-audit-log-table__number-column {\n    width: 4rem;", $styles);
        $this->assertStringContainsString(".data-audit-log-table col[data-data-audit-content-column] {\n    width: calc((100% - 11rem) / 5);", $styles);
        $this->assertStringContainsString(".data-audit-log-table__details-column {\n    width: 7rem;", $styles);
        $this->assertStringContainsString("@case('search')", $reviewIcon);
        $this->assertStringContainsString('<circle cx="10.75" cy="10.75" r="6.25" />', $reviewIcon);
        $this->assertStringContainsString("@case('null')", $reviewIcon);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="8.25" />', $reviewIcon);
        $this->assertStringContainsString('stroke-width="2.5" d="M4 20 20 4"', $reviewIcon);
        $this->assertStringContainsString('class="admin-icon-button"', $auditView);
        $this->assertStringNotContainsString('<x-clear-filter-button', $auditView);
        $this->assertStringNotContainsString('$activity->causer?->email', $auditView);
        $this->assertStringNotContainsString('>#{{ $activity->subject_id }}</div>', $auditView);
        $this->assertStringContainsString('data-data-audit-sequence>{{ $bundleNumber }}', $auditView);
        $this->assertStringContainsString("bundleRecordLabel(\$bundle['subject_type'], \$bundle['record_number'])", $auditView);
        $this->assertStringContainsString('data-data-audit-bundle-row', $auditView);
        $this->assertStringContainsString("wire:click=\"viewActivityBundle(@js(\$bundle['ids']))\"", $auditView);
        $this->assertStringContainsString('data-data-audit-event-text="{{ $eventTone }}"', $auditView);
        $this->assertStringNotContainsString('data-data-audit-event-pill', $auditView);
        $this->assertStringContainsString("'text-emerald-200' => \$eventTone === 'created'", $auditView);
        $this->assertStringContainsString("'text-amber-100' => \$eventTone === 'changed'", $auditView);
        $this->assertStringContainsString("'text-red-200' => \$eventTone === 'deleted'", $auditView);
        $this->assertStringContainsString('colspan="7"', $auditView);
        $this->assertStringNotContainsString('data-data-audit-record-context', $auditView);
        $this->assertStringContainsString('data-data-audit-change-list', $auditView);
        $this->assertStringContainsString('data-data-audit-change-bundle', $auditView);
        $this->assertStringNotContainsString('data-data-audit-record-change-group', $auditView);
        $this->assertStringContainsString('data-data-audit-comparison', $auditView);
        $this->assertStringContainsString('data-data-audit-{{ $side }}-table', $auditView);
        $this->assertStringContainsString('data-data-audit-comparison-row', $auditView);
        $this->assertStringContainsString('data-data-audit-change-{{ $side }}', $auditView);
        $this->assertStringNotContainsString("__('data_governance.audit.value')", $auditView);
        $this->assertStringNotContainsString("{{ \$row['scope'] }}", $auditView);
        $this->assertStringContainsString('align-middle px-4 py-3.5', $auditView);
        $this->assertSame(2, substr_count($auditView, "'data-audit-after-accent' => \$side === 'after'"));
        $this->assertStringContainsString('class="block break-words text-neutral-200 [overflow-wrap:anywhere]"', $auditView);
        $this->assertStringNotContainsString("'text-neutral-200' => \$row[\$side]['state'] === 'value'", $auditView);
        $this->assertStringNotContainsString("'text-neutral-500' => \$row[\$side]['state'] === 'missing'", $auditView);
        $this->assertStringNotContainsString("'font-medium text-emerald-100' => \$side === 'after'", $auditView);
        $this->assertStringNotContainsString("'bg-emerald-400/[0.035]'", $auditView);
        $this->assertStringNotContainsString('data-data-audit-change-row', $auditView);
        $this->assertStringContainsString('data-data-audit-no-effective-changes', $auditView);
        $this->assertStringContainsString('data-data-audit-no-record-state', $auditView);
        $this->assertStringContainsString('<x-admin-action-icon name="null" class="h-10 w-10 text-red-200" data-data-audit-no-record-icon />', $auditView);
        $this->assertStringContainsString("__('data_governance.audit.no_record')", $auditView);
        $this->assertStringContainsString('data-data-audit-deleted-state', $auditView);
        $this->assertStringNotContainsString('data-data-audit-deleted-notice', $auditView);
        $this->assertStringContainsString("__('data_governance.audit.record_deleted')", $auditView);
        $this->assertStringContainsString('<x-admin-action-icon name="delete" class="h-10 w-10 text-red-200" data-data-audit-deleted-icon />', $auditView);
        $this->assertStringNotContainsString('rowspan="{{ count($changedFields) }}"', $auditView);
        $this->assertStringNotContainsString("json_encode(\$value", $auditView);
        $this->assertSame('تم حذف السجل', trans('data_governance.audit.record_deleted', locale: 'ar'));
        $this->assertSame('لا يوجد سجل', trans('data_governance.audit.no_record', locale: 'ar'));
        $this->assertStringNotContainsString('class="max-w-xs break-words', $auditView);
        $this->assertStringContainsString("{{ __('data_governance.quality.high_priority') }}", $qualityView);
        $this->assertStringContainsString('data-data-quality-high-priority', $qualityView);
        $this->assertStringContainsString("{{ number_format(\$highPriorityCount) }}", $qualityView);
        $this->assertStringNotContainsString('data-data-quality-highlights', $qualityView);
        $this->assertStringNotContainsString("\$counts['open']", $qualityView);
        $this->assertStringNotContainsString("\$counts['resolved']", $qualityView);
        $this->assertStringContainsString("Carbon::parse(\$value)->format('d-m-Y')", $qualityView);
        $this->assertStringContainsString("str_contains(\$field, 'phone') || \$isDate ? 'ltr' : 'auto'", $qualityView);
        $this->assertStringContainsString('{{ $issues->firstItem() + $loop->index }}', $qualityView);
        $this->assertStringContainsString('colspan="6"', $qualityView);
        $this->assertStringNotContainsString('data-quality-stat--open', $qualityView);
        $this->assertStringNotContainsString('data-quality-stat--resolved', $qualityView);
        $this->assertStringContainsString("format('d-m-Y H:i')", $auditView);
        $this->assertStringNotContainsString('H:i:s', $auditView);
        $this->assertStringContainsString('data-data-audit-time-metric', $auditView);
        $this->assertStringContainsString('data-data-audit-ip-metric', $auditView);
        $this->assertSame(2, substr_count($auditView, "'text-right' => app()->isLocale('ar')"));
        $this->assertStringContainsString('dir="ltr">{{ $this->formatTimestamp($activity->created_at) }}', $auditView);
    }

    public function test_manager_and_teacher_cannot_open_data_governance_windows(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->actingAs($manager)->get(route('data-quality.index', absolute: false))->assertForbidden();
        $this->get(route('data-audit.index', absolute: false))->assertForbidden();

        $this->actingAs($teacher)->get(route('data-quality.index', absolute: false))->assertForbidden();
        $this->get(route('data-audit.index', absolute: false))->assertForbidden();
    }

    public function test_data_quality_service_detects_duplicate_students(): void
    {
        $parent = ParentProfile::query()->create([
            'father_name' => 'أحمد محمود',
            'father_phone' => '0999999999',
            'is_active' => true,
        ]);

        foreach ([1, 2] as $number) {
            Student::query()->create([
                'parent_id' => $parent->id,
                'first_name' => 'محمد',
                'last_name' => 'أحمد',
                'birth_date' => '2014-05-20',
                'status' => 'active',
            ]);
        }

        $issues = app(DataQualityService::class)->issues();

        $this->assertTrue($issues->contains(fn (array $issue): bool => $issue['type'] === 'duplicate_student'));
    }

    public function test_data_quality_issues_are_sorted_by_priority_then_affected_record_name(): void
    {
        ParentProfile::query()->create([
            'father_name' => 'Zulu Parent',
            'father_phone' => '0999000001',
            'is_active' => true,
        ]);
        ParentProfile::query()->create([
            'father_name' => 'Zulu Parent',
            'father_phone' => '0999000001',
            'is_active' => true,
        ]);
        $studentParent = ParentProfile::query()->create([
            'father_name' => 'Student Parent',
            'father_phone' => '0999000002',
            'is_active' => true,
        ]);

        foreach ([1, 2] as $number) {
            Student::query()->create([
                'parent_id' => $studentParent->id,
                'first_name' => 'Alpha',
                'last_name' => 'Student',
                'birth_date' => '2014-05-20',
                'status' => 'active',
            ]);
        }

        ParentProfile::query()->create([
            'father_name' => 'Aaron Missing Contact',
            'is_active' => true,
        ]);

        $issues = app(DataQualityService::class)->issues()->values();

        $this->assertSame('high', $issues[0]['severity']);
        $this->assertSame('duplicate_student', $issues[0]['type']);
        $this->assertStringStartsWith('Alpha Student', $issues[0]['records'][0]);
        $this->assertSame('high', $issues[1]['severity']);
        $this->assertSame('duplicate_parent', $issues[1]['type']);
        $this->assertStringStartsWith('Zulu Parent', $issues[1]['records'][0]);
        $this->assertSame('medium', $issues[2]['severity']);
        $this->assertSame('missing_parent_contact', $issues[2]['type']);
        $this->assertStringStartsWith('Aaron Missing Contact', $issues[2]['records'][0]);
    }

    public function test_parent_email_does_not_satisfy_the_telephone_contact_check(): void
    {
        $user = User::factory()->create([
            'email' => 'parent@example.test',
            'phone' => null,
        ]);

        $parent = ParentProfile::query()->create([
            'user_id' => $user->id,
            'father_name' => 'وليد أحمد',
            'father_phone' => null,
            'mother_phone' => null,
            'home_phone' => null,
            'is_active' => true,
        ]);

        $issues = app(DataQualityService::class)->issues();

        $this->assertTrue($issues->contains(
            fn (array $issue): bool => $issue['type'] === 'missing_parent_contact'
                && $issue['entity_ids'] === [$parent->id],
        ));
    }

    public function test_authenticated_model_changes_are_recorded_with_before_and_after_values(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'سليم خالد',
            'father_phone' => '0988888888',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'عمر',
            'last_name' => 'سليم',
            'birth_date' => '2013-04-10',
            'status' => 'active',
        ]);

        $student->update(['status' => 'inactive']);

        $activity = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('active', $activity->getProperty('before.status'));
        $this->assertSame('inactive', $activity->getProperty('after.status'));
        $this->assertArrayNotHasKey('password', $activity->getProperty('after', []));

        $student->delete();
        $deletedActivity = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->where('event', 'deleted')
            ->latest('id')
            ->firstOrFail();

        app()->setLocale('ar');
        $listTimestamp = $deletedActivity->created_at->format('d-m-Y H:i');
        $timestampWithSeconds = $deletedActivity->created_at->format('d-m-Y H:i:s');

        $auditComponent = Volt::test('data-audit.index')
            ->call('viewActivity', $deletedActivity->id)
            ->assertSee('data-data-audit-deleted-state', false)
            ->assertSee('data-data-audit-deleted-icon', false)
            ->assertSee('تم حذف السجل')
            ->assertSee('data-data-audit-time-metric', false)
            ->assertSee('data-data-audit-ip-metric', false)
            ->assertSee('text-right', false)
            ->assertSee('dir="ltr">'.$listTimestamp, false)
            ->assertSee('الاسم')
            ->assertDontSee('Deleted At');

        $this->assertMatchesRegularExpression('/data-data-audit-time-metric>'.preg_quote($listTimestamp, '/').'<\/div>/', $auditComponent->html());
        $this->assertStringNotContainsString($timestampWithSeconds, $auditComponent->html());
        $this->assertSame(1, substr_count(strip_tags($auditComponent->html()), 'تم حذف السجل'));
    }

    public function test_consecutive_edits_in_one_module_are_grouped_with_all_record_changes(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        app()->setLocale('ar');

        $parent = ParentProfile::query()->create([
            'father_name' => 'سليم خالد',
            'father_phone' => '0988888888',
            'is_active' => true,
        ]);
        $firstStudent = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'عمر',
            'last_name' => 'سليم',
            'birth_date' => '2013-04-10',
            'status' => 'active',
        ]);
        $secondStudent = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'رامي',
            'last_name' => 'حسن',
            'birth_date' => '2014-05-11',
            'status' => 'active',
        ]);
        AuditActivity::query()->inLog('data-audit')->delete();

        $firstStudent->update(['first_name' => 'علي']);
        $secondStudent->update(['last_name' => 'خالد']);
        $firstStudent->update(['first_name' => 'أحمد']);

        $activity = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('event', 'updated')
            ->sole();
        $entries = $activity->getProperty('entries');

        $this->assertCount(2, $entries);
        $this->assertSame($firstStudent->id, $entries[0]['subject_id']);
        $this->assertSame('عمر', $entries[0]['before']['first_name']);
        $this->assertSame('أحمد', $entries[0]['after']['first_name']);
        $this->assertSame($secondStudent->id, $entries[1]['subject_id']);
        $this->assertSame('حسن', $entries[1]['before']['last_name']);
        $this->assertSame('خالد', $entries[1]['after']['last_name']);
        $this->assertSame(3, $activity->getProperty('merged_updates'));

        $component = Volt::test('data-audit.index');
        $groups = $component->instance()->activityChangeGroups($activity);
        $this->assertCount(2, $groups);
        $this->assertSame('السجلات المتأثرة: 2', $component->instance()->activityRecordLabel($activity));
        $this->assertCount(2, $component->instance()->bundleChangeRows(collect([$activity])));

        $bundleDescriptors = $component->instance()->consecutiveActivityBundles([
            ['id' => 42, 'subject_type' => Student::class, 'event' => 'updated'],
            ['id' => 41, 'subject_type' => Student::class, 'event' => 'updated'],
            ['id' => 40, 'subject_type' => Student::class, 'event' => 'deleted'],
            ['id' => 39, 'subject_type' => ParentProfile::class, 'event' => 'deleted'],
            ['id' => 38, 'subject_type' => Student::class, 'event' => 'deleted'],
        ]);
        $this->assertCount(4, $bundleDescriptors);
        $this->assertSame([42, 41], $bundleDescriptors[0]['ids']);
        $this->assertSame([40], $bundleDescriptors[1]['ids']);
        $this->assertSame([39], $bundleDescriptors[2]['ids']);
        $this->assertSame([38], $bundleDescriptors[3]['ids']);

        $numberedBundles = $component->instance()->numberActivityBundlesBySubjectType($bundleDescriptors);
        $this->assertSame([3, 2, 1, 1], array_column($numberedBundles, 'record_number'));

        $details = $component
            ->call('viewActivity', $activity->id)
            ->assertSee('data-data-audit-change-bundle', false)
            ->assertSee('Student #1');
        $this->assertSame(1, substr_count($details->html(), 'data-data-audit-before-table'));
        $this->assertSame(1, substr_count($details->html(), 'data-data-audit-after-table'));
        $this->assertSame(0, substr_count($details->html(), 'data-data-audit-record-change-group'));

        $parent->update(['father_phone' => '0977777777']);
        $firstStudent->update(['status' => 'inactive']);

        $this->assertSame(2, AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('event', 'updated')
            ->count());

        activity('data-audit')
            ->causedBy($admin)
            ->performedOn($firstStudent)
            ->event('restored')
            ->withProperties(['before' => [], 'after' => ['status' => 'active']])
            ->log('restored Student');
        activity('data-audit')
            ->causedBy($admin)
            ->performedOn($secondStudent)
            ->event('restored')
            ->withProperties(['before' => [], 'after' => ['status' => 'active']])
            ->log('restored Student');

        Volt::test('data-audit.index')
            ->assertViewHas('activities', function ($activities): bool {
                $bundles = $activities->getCollection();

                return $activities->total() === 4
                    && $bundles->pluck('subject_type')->all() === [Student::class, Student::class, ParentProfile::class, Student::class]
                    && $bundles->pluck('event')->all() === ['restored', 'updated', 'updated', 'updated']
                    && $bundles->pluck('record_number')->all() === [3, 2, 1, 1]
                    && count($bundles->first()['ids']) === 2;
            });
    }

    public function test_structured_setting_changes_are_logged_and_shown_as_readable_field_level_differences(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        app()->setLocale('ar');

        $before = [
            'courses' => ['group_key' => 'academics', 'sort_order' => 1],
            'groups' => ['group_key' => 'academics', 'sort_order' => 2],
        ];
        $setting = AppSetting::withoutEvents(fn (): AppSetting => AppSetting::query()->create([
            'group' => 'sidebar_navigation',
            'key' => 'items',
            'type' => 'json',
            'value' => json_encode($before),
        ]));

        $setting->update(['value' => json_encode(array_reverse($before, true))]);

        $this->assertFalse(AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', AppSetting::class)
            ->where('subject_id', $setting->id)
            ->exists());

        $after = $before;
        $after['courses']['sort_order'] = 3;
        $setting->update(['value' => json_encode($after)]);

        $activity = AuditActivity::query()
            ->inLog('data-audit')
            ->with('subject')
            ->where('subject_type', AppSetting::class)
            ->where('subject_id', $setting->id)
            ->sole();

        $this->assertIsArray(data_get($activity->getProperty('before', []), 'value'));
        $this->assertIsArray(data_get($activity->getProperty('after', []), 'value'));

        $component = Volt::test('data-audit.index');
        $rows = $component->instance()->changeRows($activity);

        $this->assertCount(1, $rows);
        $this->assertSame('الدورات', $rows[0]['scope']);
        $this->assertSame('ترتيب العنصر', $rows[0]['field']);
        $this->assertSame('1', $rows[0]['before']['value']);
        $this->assertSame('3', $rows[0]['after']['value']);
        $this->assertSame('ltr', $rows[0]['before']['direction']);
        $this->assertSame('ltr', $rows[0]['after']['direction']);

        $component
            ->call('viewActivity', $activity->id)
            ->assertSee('data-data-audit-comparison', false)
            ->assertSee('data-data-audit-before-table', false)
            ->assertSee('data-data-audit-after-table', false)
            ->assertSee('data-data-audit-change-before', false)
            ->assertSee('data-data-audit-change-after', false)
            ->assertSeeText('ترتيب العنصر')
            ->assertDontSee('{&quot;courses&quot;', false);
    }

    public function test_backup_audit_details_localise_known_fields_and_values_in_arabic(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $path = 'backups/example.alkhair-backup';
        $backup = SystemBackup::query()->create([
            'uuid' => $uuid,
            'disk' => 'local',
            'file_path' => $path,
            'filename' => basename($path),
            'trigger' => SystemBackup::TRIGGER_MANUAL,
            'status' => SystemBackup::STATUS_COMPLETED,
            'includes_files' => true,
            'encrypted' => true,
            'size_bytes' => 12445009,
            'sha256' => str_repeat('a', 64),
            'manifest_summary' => [
                'version' => 2,
                'files_count' => 3,
            ],
            'created_by' => $admin->id,
        ]);

        $activity = AuditActivity::query()
            ->inLog('data-audit')
            ->with('subject')
            ->where('subject_type', SystemBackup::class)
            ->where('subject_id', $backup->id)
            ->where('event', 'created')
            ->sole();

        app()->setLocale('ar');
        $component = Volt::test('data-audit.index');
        $rows = collect($component->instance()->changeRows($activity));

        $this->assertSame('النسخ الاحتياطية', $component->instance()->moduleLabel(SystemBackup::class));
        $this->assertSame('قيد الإنشاء', trans('data_governance.audit.field_values.status.creating'));
        $this->assertSame('---', $rows->firstWhere('field', 'المعرّف الفريد')['before']['value']);
        $this->assertSame($uuid, $rows->firstWhere('field', 'المعرّف الفريد')['after']['value']);
        $this->assertSame('محلي', $rows->firstWhere('field', 'مساحة التخزين')['after']['value']);
        $this->assertSame($path, $rows->firstWhere('field', 'مسار الملف')['after']['value']);
        $this->assertSame('يدوية', $rows->firstWhere('field', 'نوع النسخة')['after']['value']);
        $this->assertSame('مكتمل', $rows->firstWhere('field', 'الحالة')['after']['value']);
        $this->assertSame('نعم', $rows->firstWhere('field', 'يتضمن الملفات')['after']['value']);
        $this->assertSame('نعم', $rows->firstWhere('field', 'مشفّر')['after']['value']);
        $this->assertSame('2', $rows->firstWhere('field', 'الإصدار')['after']['value']);
        $this->assertSame('3', $rows->firstWhere('field', 'عدد الملفات')['after']['value']);

        $component
            ->call('viewActivity', $activity->id)
            ->assertSee('data-data-audit-no-record-state', false)
            ->assertSee('data-data-audit-no-record-icon', false)
            ->assertSeeText('لا يوجد سجل')
            ->assertDontSee('data-data-audit-change-before', false)
            ->assertSee('data-data-audit-change-after', false);

        $this->assertSame(1, substr_count($component->html(), 'data-data-audit-no-record-state'));
    }

    public function test_setting_bundle_uses_localized_titles_and_values_without_a_value_suffix(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        app()->setLocale('ar');

        [$frequency, $retention] = AppSetting::withoutEvents(fn (): array => [
            AppSetting::query()->create(['group' => 'backups', 'key' => 'frequency', 'type' => 'string', 'value' => 'daily']),
            AppSetting::query()->create(['group' => 'backups', 'key' => 'retention_count', 'type' => 'integer', 'value' => '10']),
        ]);
        AuditActivity::query()->inLog('data-audit')->delete();

        $frequency->update(['value' => 'weekly']);
        $retention->update(['value' => '14']);

        $activity = AuditActivity::query()
            ->inLog('data-audit')
            ->with('subject')
            ->where('subject_type', AppSetting::class)
            ->sole();
        $component = Volt::test('data-audit.index');
        $rows = collect($component->instance()->bundleChangeRows(collect([$activity])));
        $frequencyRow = $rows->firstWhere('field', 'الجدولة');

        $this->assertNotNull($frequencyRow);
        $this->assertSame('يومياً', $frequencyRow['before']['value']);
        $this->assertSame('أسبوعياً', $frequencyRow['after']['value']);
        $this->assertNotNull($rows->firstWhere('field', 'عدد النسخ المحتفظ بها'));
        $this->assertFalse($rows->contains(fn (array $row): bool => str_contains($row['field'], 'Value')));

        $component
            ->call('viewActivity', $activity->id)
            ->assertSeeText('الجدولة')
            ->assertSeeText('يومياً')
            ->assertSeeText('أسبوعياً')
            ->assertDontSeeText('Value');
    }

    public function test_database_movements_are_paginated_at_fifteen_bundles_per_page(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Pagination Parent',
            'father_phone' => '0999999999',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Pagination',
            'last_name' => 'Student',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        AuditActivity::query()->inLog('data-audit')->delete();

        foreach (range(1, 16) as $index) {
            $event = $index % 2 === 0 ? 'updated' : 'restored';

            activity('data-audit')
                ->causedBy($admin)
                ->performedOn($student)
                ->event($event)
                ->withProperties(['before' => ['status' => 'inactive'], 'after' => ['status' => 'active']])
                ->log($event.' Student');
        }

        $component = Volt::test('data-audit.index')
            ->assertViewHas('activities', fn ($activities): bool => $activities->perPage() === 15
                && $activities->count() === 15
                && $activities->total() === 16
                && $activities->lastPage() === 2);

        $component
            ->call('setPage', 2)
            ->assertViewHas('activities', fn ($activities): bool => $activities->currentPage() === 2
                && $activities->count() === 1
                && $activities->total() === 16);
    }

    public function test_an_all_delete_bundle_uses_the_single_centered_delete_state_and_red_event_text(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        app()->setLocale('ar');

        $parent = ParentProfile::query()->create([
            'father_name' => 'سليم خالد',
            'father_phone' => '0988888888',
            'is_active' => true,
        ]);
        $students = collect([
            Student::query()->create(['parent_id' => $parent->id, 'first_name' => 'عمر', 'last_name' => 'سليم', 'birth_date' => '2013-04-10', 'status' => 'active']),
            Student::query()->create(['parent_id' => $parent->id, 'first_name' => 'رامي', 'last_name' => 'حسن', 'birth_date' => '2014-05-11', 'status' => 'active']),
        ]);
        AuditActivity::query()->inLog('data-audit')->delete();
        $students->each->delete();

        $deletedActivityIds = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('event', 'deleted')
            ->latest('id')
            ->pluck('id')
            ->all();

        $component = Volt::test('data-audit.index')
            ->assertSee('data-data-audit-event-text="deleted"', false)
            ->call('viewActivityBundle', $deletedActivityIds)
            ->assertSee('data-data-audit-deleted-state', false)
            ->assertSee('data-data-audit-deleted-icon', false);

        $this->assertSame(1, substr_count($component->html(), 'data-data-audit-deleted-state'));
        $this->assertSame(1, substr_count(strip_tags($component->html()), 'تم حذف السجل'));
    }

    public function test_legacy_order_only_json_activity_shows_a_clear_no_value_changes_message(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        app()->setLocale('ar');

        $setting = AppSetting::withoutEvents(fn (): AppSetting => AppSetting::query()->create([
            'group' => 'sidebar_navigation',
            'key' => 'items',
            'type' => 'json',
            'value' => '{}',
        ]));
        $courses = ['group_key' => 'academics', 'sort_order' => 1];
        $groups = ['group_key' => 'academics', 'sort_order' => 2];
        $activity = activity('data-audit')
            ->causedBy($admin)
            ->performedOn($setting)
            ->event('updated')
            ->withProperties([
                'before' => ['value' => json_encode(['courses' => $courses, 'groups' => $groups])],
                'after' => ['value' => json_encode(['groups' => $groups, 'courses' => $courses])],
                'subject_label' => 'AppSetting #'.$setting->id,
                'ip_address' => '127.0.0.1',
            ])
            ->log('updated AppSetting');

        $component = Volt::test('data-audit.index');
        $this->assertSame([], $component->instance()->changeRows($activity->load('subject')));

        $component
            ->call('viewActivity', $activity->id)
            ->assertSee('data-data-audit-no-effective-changes', false)
            ->assertSeeText('لا توجد قيم متغيرة')
            ->assertSeeText('الترتيب أو التنسيق الداخلي');
    }

    public function test_quality_decisions_are_persisted_and_can_be_reopened(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'حسام علي',
            'father_phone' => '0977777777',
            'is_active' => true,
        ]);

        foreach ([1, 2] as $number) {
            Student::query()->create([
                'parent_id' => $parent->id,
                'first_name' => 'رامي',
                'last_name' => 'حسام',
                'birth_date' => '2015-02-12',
                'status' => 'active',
            ]);
        }

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_student');

        Volt::test('data-quality.index')
            ->set('selectedIssueKey', $issue['key'])
            ->call('decide', 'not_duplicate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('data_quality_resolutions', [
            'issue_key' => $issue['key'],
            'status' => 'not_duplicate',
            'resolved_by' => $admin->id,
        ]);

        $activeOnlyComponent = Volt::test('data-quality.index');
        $this->assertSame(0, $activeOnlyComponent->instance()->with()['issues']->total());
        $activeOnlyComponent->call('review', $issue['key'])->assertNotFound();

        Volt::test('data-quality.index')
            ->set('selectedIssueKey', $issue['key'])
            ->call('reopen')
            ->assertHasNoErrors();

        $this->assertFalse(DataQualityResolution::query()->where('issue_key', $issue['key'])->exists());
    }

    public function test_a_resolved_issue_that_is_still_detected_returns_as_an_active_problem(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'حسام علي',
            'father_phone' => '0977777777',
            'is_active' => true,
        ]);

        foreach ([1, 2] as $number) {
            Student::query()->create([
                'parent_id' => $parent->id,
                'first_name' => 'رامي',
                'last_name' => 'حسام',
                'birth_date' => '2015-02-12',
                'status' => 'active',
            ]);
        }

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_student');
        DataQualityResolution::query()->create([
            'issue_key' => $issue['key'],
            'issue_type' => $issue['type'],
            'status' => 'resolved',
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
        ]);

        $refreshedIssue = app(DataQualityService::class)->issues()->firstWhere('key', $issue['key']);

        $this->assertSame('open', $refreshedIssue['status']);
        $this->assertNull($refreshedIssue['resolved_at']);
        Volt::test('data-quality.index')
            ->call('refreshTable')
            ->assertViewHas('issues', fn ($issues): bool => $issues->contains('key', $issue['key']));
    }

    public function test_duplicate_student_review_is_comparison_only_and_editing_in_students_resolves_the_issue(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'نبيل سالم',
            'father_phone' => '0966666666',
            'is_active' => true,
        ]);

        $students = collect([1, 2])->map(fn () => Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'ياسر',
            'last_name' => 'نبيل',
            'birth_date' => '2014-03-11',
            'status' => 'active',
        ]));

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_student');
        $component = Volt::test('data-quality.index')->call('review', $issue['key']);
        $records = $component->get('editableRecords');
        $this->assertSame('ياسر نبيل', $records[0]['label']);
        $this->assertSame(
            [__('data_governance.quality.record_fields.enrollments_count'), __('data_governance.quality.record_fields.memorization_sessions_count')],
            array_column(array_slice($records[0]['details'], 0, 2), 'field'),
        );
        $this->assertSame(['0', '0'], array_column(array_slice($records[0]['details'], 0, 2), 'value'));
        $birthDate = collect($records[0]['details'])->firstWhere('field', __('data_governance.quality.record_fields.birth_date'));
        $this->assertSame('11-03-2014', $birthDate['value']);
        $this->assertSame('ltr', $birthDate['direction']);
        $component
            ->assertSee('11-03-2014')
            ->assertSee('data-data-quality-duplicate-record-actions', false)
            ->assertSee('data-data-quality-edit-record', false)
            ->assertSee(route('students.index', ['edit' => $students->last()->id, 'quality_issue' => $issue['key']]))
            ->assertDontSee('wire:change="autosaveRecord(0)"', false)
            ->assertDontSee('wire:change="autosaveRecord(1)"', false)
            ->assertHasNoErrors();

        $this->get(route('students.index', [
            'edit' => $students->last()->id,
            'quality_issue' => $issue['key'],
        ]))->assertOk()->assertSee('data-student-identity-row', false);

        Volt::test('students.index')
            ->set('editStudent', $students->last()->id)
            ->set('qualityIssueKey', $issue['key'])
            ->call('edit', $students->last()->id)
            ->assertSet('editingId', $students->last()->id)
            ->assertSet('showFormModal', true)
            ->set('school_name', 'مدرسة الخير')
            ->call('save')
            ->assertRedirect(route('data-quality.index'))
            ->assertHasNoErrors();

        $this->assertSame('مدرسة الخير', $students->last()->fresh()->school_name);
        $this->assertDatabaseHas('data_quality_resolutions', [
            'issue_key' => $issue['key'],
            'status' => 'resolved',
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_saving_an_unchanged_duplicate_student_from_audit_is_a_true_no_op(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'نبيل سالم',
            'father_phone' => '0966666666',
            'is_active' => true,
        ]);
        $students = collect([1, 2])->map(fn () => Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'ياسر',
            'last_name' => 'نبيل',
            'birth_date' => '2014-03-11',
            'status' => 'active',
        ]));
        $student = $students->last();
        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_student');
        $updatedAt = $student->updated_at;
        $movementCount = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->count();
        $this->travel(1)->day();

        Volt::test('students.index')
            ->set('editStudent', $student->id)
            ->set('qualityIssueKey', $issue['key'])
            ->call('edit', $student->id)
            ->call('save')
            ->assertSet('showFormModal', true)
            ->assertNoRedirect()
            ->assertHasNoErrors();

        $this->assertTrue($student->fresh()->updated_at->equalTo($updatedAt));
        $this->assertSame('2014-03-11', $student->fresh()->birth_date->toDateString());
        $this->assertSame($movementCount, AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->count());
        $this->assertFalse(DataQualityResolution::query()->where('issue_key', $issue['key'])->exists());
        $this->assertSame('open', app(DataQualityService::class)->issues()->firstWhere('key', $issue['key'])['status']);
    }

    public function test_duplicate_parent_review_uses_the_parent_name_as_its_centre_label(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        foreach ([1, 2] as $number) {
            ParentProfile::query()->create([
                'father_name' => 'وائل الزين',
                'father_phone' => '+963 944 315 855',
                'is_active' => true,
            ]);
        }

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_parent');
        $records = Volt::test('data-quality.index')->call('review', $issue['key'])->get('editableRecords');

        $this->assertSame(['وائل الزين', 'وائل الزين'], array_column($records, 'label'));
        $fatherPhone = collect($records[0]['details'])->firstWhere('field', __('data_governance.quality.record_fields.father_phone'));
        $this->assertSame('ltr', $fatherPhone['direction']);
        $this->assertSame('+963944315855', $fatherPhone['value']);
    }

    public function test_editing_a_duplicate_parent_in_the_parents_window_resolves_the_issue(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parents = collect([1, 2])->map(fn () => ParentProfile::query()->create([
            'father_name' => 'غسان درويش',
            'father_phone' => '+963 944 315 855',
            'is_active' => true,
        ]));

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_parent');

        Volt::test('data-quality.index')
            ->call('review', $issue['key'])
            ->assertSee(route('parents.index', ['edit' => $parents->last()->id, 'quality_issue' => $issue['key']]))
            ->assertDontSee('wire:model="editableRecords.0.father_name"', false)
            ->assertDontSee('wire:model="editableRecords.1.father_name"', false);

        $this->get(route('parents.index', [
            'edit' => $parents->last()->id,
            'quality_issue' => $issue['key'],
        ]))->assertOk()->assertSee('id="father-name"', false);

        Volt::test('parents.index')
            ->set('editParent', $parents->last()->id)
            ->set('qualityIssueKey', $issue['key'])
            ->call('edit', $parents->last()->id)
            ->assertSet('editingId', $parents->last()->id)
            ->assertSet('showFormModal', true)
            ->set('father_work', 'مهندس')
            ->call('save')
            ->assertRedirect(route('data-quality.index'))
            ->assertHasNoErrors();

        $this->assertSame('مهندس', $parents->last()->fresh()->father_work);
        $this->assertDatabaseHas('data_quality_resolutions', [
            'issue_key' => $issue['key'],
            'status' => 'resolved',
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_saving_an_unchanged_duplicate_parent_from_audit_is_a_true_no_op(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parents = collect([1, 2])->map(fn () => ParentProfile::query()->create([
            'father_name' => 'غسان درويش',
            'father_phone' => '+963 944 315 855',
            'is_active' => true,
        ]));
        $parent = $parents->last();
        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_parent');
        $updatedAt = $parent->updated_at;
        $movementCount = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', ParentProfile::class)
            ->where('subject_id', $parent->id)
            ->count();
        $this->travel(1)->day();

        Volt::test('parents.index')
            ->set('editParent', $parent->id)
            ->set('qualityIssueKey', $issue['key'])
            ->call('edit', $parent->id)
            ->call('save')
            ->assertSet('showFormModal', true)
            ->assertNoRedirect()
            ->assertHasNoErrors();

        $this->assertTrue($parent->fresh()->updated_at->equalTo($updatedAt));
        $this->assertSame($movementCount, AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', ParentProfile::class)
            ->where('subject_id', $parent->id)
            ->count());
        $this->assertFalse(DataQualityResolution::query()->where('issue_key', $issue['key'])->exists());
        $this->assertSame('open', app(DataQualityService::class)->issues()->firstWhere('key', $issue['key'])['status']);
    }

    public function test_missing_parent_contact_opens_the_parent_editor_without_metadata_or_a_collapsible_panel(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'علاء البدوي',
            'is_active' => true,
        ]);

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'missing_parent_contact');

        $component = Volt::test('data-quality.index')
            ->call('review', $issue['key'])
            ->assertSee('data-data-quality-direct-parent-editor', false)
            ->assertSee('data-data-quality-parent-editor', false)
            ->assertSee('data-data-quality-resolve-save-action', false)
            ->assertDontSee('data-data-quality-record-panel', false)
            ->assertDontSee('data-data-quality-record-details', false)
            ->assertDontSee('wire:change="autosaveRecord(0)"', false)
            ->assertSet('editableRecords.0.id', $parent->id);

        $component
            ->set('editableRecords.0.father_phone', '+963944315855')
            ->call('saveAndResolveParentContact')
            ->assertSet('selectedIssueKey', null)
            ->assertHasNoErrors();

        $this->assertSame('+963 944 315 855', $parent->fresh()->father_phone);
        $this->assertDatabaseHas('data_quality_resolutions', [
            'issue_key' => $issue['key'],
            'status' => 'resolved',
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_saving_unchanged_parent_contact_data_does_not_save_or_resolve_the_audit_issue(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'علاء البدوي',
            'is_active' => true,
        ]);
        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'missing_parent_contact');
        $updatedAt = $parent->updated_at;
        $this->travel(1)->day();

        Volt::test('data-quality.index')
            ->call('review', $issue['key'])
            ->call('saveAndResolveParentContact')
            ->assertSet('selectedIssueKey', $issue['key'])
            ->assertHasNoErrors();

        $this->assertTrue($parent->fresh()->updated_at->equalTo($updatedAt));
        $this->assertFalse(DataQualityResolution::query()->where('issue_key', $issue['key'])->exists());
        $this->assertSame('open', app(DataQualityService::class)->issues()->firstWhere('key', $issue['key'])['status']);
    }

    public function test_duplicate_record_can_be_deleted_from_review_and_the_window_closes(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $parent = ParentProfile::query()->create([
            'father_name' => 'فادي أحمد',
            'father_phone' => '0955555555',
            'is_active' => true,
        ]);

        $students = collect([1, 2])->map(fn () => Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'سامي',
            'last_name' => 'فادي',
            'birth_date' => '2014-06-12',
            'status' => 'active',
        ]));

        $issue = app(DataQualityService::class)->issues()->firstWhere('type', 'duplicate_student');

        Volt::test('data-quality.index')
            ->call('review', $issue['key'])
            ->call('deleteRecord', $students->last()->id)
            ->assertSet('selectedIssueKey', null)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('students', ['id' => $students->last()->id]);
        $this->assertFalse(app(DataQualityService::class)->issues()->contains(fn (array $candidate): bool => $candidate['key'] === $issue['key']));
    }
}

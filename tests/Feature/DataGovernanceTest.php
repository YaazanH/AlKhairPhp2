<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\DataQualityResolution;
use App\Models\Student;
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
        $this->assertSame('جودة قاعدة البيانات والتكرارات', trans('data_governance.quality.title', locale: 'ar'));
        $this->assertSame('تدقيق حركات قاعدة البيانات', trans('data_governance.audit.title', locale: 'ar'));
        $this->assertSame('جودة البيانات', trans('ui.nav.data_quality', locale: 'ar'));
        $this->assertSame('تدقيق البيانات', trans('ui.nav.data_audit', locale: 'ar'));
        $this->assertSame('ابحث', trans('data_governance.quality.search_placeholder', locale: 'ar'));
        $this->assertSame('ابحث', trans('data_governance.audit.search_placeholder', locale: 'ar'));

        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('data-quality.index', absolute: false))
            ->assertOk()
            ->assertSeeText(__('data_governance.quality.title'));

        $this->get(route('data-audit.index', absolute: false))
            ->assertOk()
            ->assertSeeText(__('data_governance.audit.title'));

        $qualityView = file_get_contents(resource_path('views/livewire/data-quality/index.blade.php'));
        $this->assertStringContainsString('data-data-quality-review-action', $qualityView);
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
        $this->assertStringNotContainsString("data_governance.quality.fields.notes", $qualityView);
        $parentEditor = file_get_contents(resource_path('views/livewire/data-quality/partials/parent-editor.blade.php'));
        $this->assertStringNotContainsString("data_governance.quality.fields.notes", $parentEditor);
        $this->assertStringContainsString("except(['deleted_at', 'notes'])", $qualityView);

        $auditView = file_get_contents(resource_path('views/livewire/data-audit/index.blade.php'));
        $this->assertStringContainsString('data-data-audit-view-action', $auditView);
        $this->assertStringContainsString('<x-admin-action-icon name="past" />', $auditView);
        $this->assertStringContainsString('data-past-icon="supplied-clock-history"', $reviewIcon);
        $this->assertStringContainsString('transform="translate(9 8) scale(.94)"', $reviewIcon);
        $this->assertStringContainsString("'past' => '0 0 189.99 190.27'", $reviewIcon);
        $this->assertStringContainsString('class="admin-icon-button"', $auditView);
        $this->assertStringNotContainsString('<x-clear-filter-button', $auditView);
        $this->assertStringNotContainsString('$activity->causer?->email', $auditView);
        $this->assertStringNotContainsString('>#{{ $activity->subject_id }}</div>', $auditView);
        $this->assertStringContainsString('data-data-audit-sequence>{{ $activities->total() - (($activities->firstItem() - 1) + $loop->index) }}', $auditView);
        $this->assertStringContainsString('colspan="7"', $auditView);
        $this->assertStringContainsString('class="surface-table settings-record-table" data-settings-record-table data-data-audit-changes-table', $auditView);
        $this->assertStringContainsString('<table class="table-fixed text-sm">', $auditView);
        $this->assertStringContainsString('<col class="w-[42%]">', $auditView);
        $this->assertStringContainsString("__('data_governance.audit.record_deleted')", $auditView);
        $this->assertStringContainsString('<x-admin-action-icon name="delete" class="mx-auto mb-3 h-8 w-8" data-data-audit-deleted-icon />', $auditView);
        $this->assertStringContainsString('rowspan="{{ count($changedFields) }}"', $auditView);
        $this->assertSame('تم حذف السجل', trans('data_governance.audit.record_deleted', locale: 'ar'));
        $this->assertStringNotContainsString('class="max-w-xs break-words', $auditView);
        $this->assertStringContainsString("{{ __('data_governance.quality.high_priority') }}", $qualityView);
        $this->assertStringContainsString('{{ $issues->firstItem() + $loop->index }}', $qualityView);
        $this->assertStringContainsString('colspan="6"', $qualityView);
        $this->assertStringNotContainsString('data-quality-stat--open', $qualityView);
        $this->assertStringNotContainsString('data-quality-stat--resolved', $qualityView);
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
        Volt::test('data-audit.index')
            ->call('viewActivity', $deletedActivity->id)
            ->assertSee('تم حذف السجل')
            ->assertSee($deletedActivity->getProperty('before.deleted_at'))
            ->assertSee('الاسم')
            ->assertDontSee('Deleted At');
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

        Volt::test('data-quality.index')
            ->set('selectedIssueKey', $issue['key'])
            ->call('reopen')
            ->assertHasNoErrors();

        $this->assertFalse(DataQualityResolution::query()->where('issue_key', $issue['key'])->exists());
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
        $component
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
        ]))
            ->assertOk()
            ->assertSee('id="father-name"', false)
            ->assertDontSee('id="parent-notes"', false)
            ->assertDontSee('data-parent-form-close-action', false)
            ->assertDontSee('data-parent-form-account-action', false);

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

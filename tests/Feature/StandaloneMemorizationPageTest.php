<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseLifecycleService;
use App\Services\MemorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StandaloneMemorizationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_memorization_submit_uses_the_shared_save_symbol(): void
    {
        $source = file_get_contents(resource_path('views/livewire/memorization/quick-entry.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-quick-memorization-save-action', $source);
        $this->assertStringContainsString('data-memorization-page-picker', $source);
        $this->assertStringNotContainsString('id="quick-memorization-from"', $source);
        $this->assertStringContainsString('.memorization-page-picker__grid', $styles);
        $this->assertStringContainsString('class="admin-icon-button admin-icon-button--accent quick-entry-save-action"', $source);
        $this->assertStringContainsString('<x-admin-action-icon name="save" />', $source);
        $this->assertStringNotContainsString("<button type=\"submit\" class=\"pill-link pill-link--accent\">{{ __('workflow.memorization.quick_entry.form.save') }}</button>", $source);
    }

    public function test_quick_memorization_replaces_the_form_with_a_warning_when_entries_are_disabled(): void
    {
        $this->teacherMemorizationContext();
        AppSetting::storeValue('general', 'memorization_saber_entries_enabled', false, 'boolean');

        Volt::test('memorization.quick-entry')
            ->assertSee('data-quick-entry-disabled', false)
            ->assertSee(__('quick-tests.memorization_disabled_warning'))
            ->assertSee(__('quick-tests.disabled_help'))
            ->assertDontSee('data-quick-entry-help', false)
            ->assertDontSee('wire:submit="save"', false);
    }

    public function test_teacher_workbench_uses_the_logged_in_teacher_for_new_memorization_entries(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        Volt::test('memorization.index')
            ->call('openCreateModal')
            ->assertDontSee('memorization-enrollment', false)
            ->assertDontSee('memorization-notes', false)
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-03')
            ->set('entry_type', 'new')
            ->set('from_page', '11')
            ->set('to_page', '13')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'from_page' => 11,
            'to_page' => 13,
            'pages_count' => 3,
        ]);
    }

    public function test_teacher_workbench_automatically_uses_the_newest_active_enrollment(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();

        Group::create([
            'course_id' => Course::create(['name' => 'Second Memorization Course', 'is_active' => true])->id,
            'academic_year_id' => $enrollment->group->academic_year_id,
            'teacher_id' => $enrollment->group->teacher_id,
            'name' => 'Second Memorization Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $secondGroup = Group::query()->where('name', 'Second Memorization Group')->firstOrFail();

        Enrollment::create([
            'student_id' => $enrollment->student_id,
            'group_id' => $secondGroup->id,
            'enrolled_at' => '2026-09-04',
            'status' => 'active',
        ]);

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('selectedEnrollmentId', $secondGroup->enrollments()->value('id'))
            ->set('recorded_on', '2026-09-05')
            ->set('entry_type', 'new')
            ->set('from_page', '14')
            ->set('to_page', '16')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $secondGroup->enrollments()->value('id'),
            'student_id' => $enrollment->student_id,
            'from_page' => 14,
            'to_page' => 16,
        ]);
    }

    public function test_teacher_workbench_warns_about_duplicate_pages_and_can_save_only_the_unique_pages(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-03')
            ->set('entry_type', 'new')
            ->set('from_page', '11')
            ->set('to_page', '13')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-04')
            ->set('entry_type', 'new')
            ->set('from_page', '12')
            ->set('to_page', '14')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDuplicateModal', true)
            ->assertSet('duplicatePages', [12, 13])
            ->assertSet('uniquePages', [14])
            ->call('confirmDuplicateSave')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'from_page' => 14,
            'to_page' => 14,
            'pages_count' => 1,
        ]);
    }

    public function test_teacher_quick_entry_warns_about_duplicate_pages_and_can_save_only_the_unique_pages(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedJuzNumber', QuranJuz::query()->where('from_page', '<=', 22)->where('to_page', '>=', 22)->value('juz_number'))
            ->set('selectedPages', range(22, 24))
            ->call('save')
            ->assertHasNoErrors();

        $originalJuzId = QuranJuz::where('juz_number', 30)->value('id');
        $enrollment->student->update(['quran_current_juz_id' => $originalJuzId]);

        Volt::test('memorization.quick-entry')->set('selectedStudentId', $enrollment->student_id)
            ->call('selectJuz', 2)->set('selectedPages', [22])->call('save')
            ->assertSet('uniquePages', [])->call('confirmDuplicateSave');
        $this->assertSame($originalJuzId, $enrollment->student->fresh()->quran_current_juz_id);

        $editor = Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedJuzNumber', QuranJuz::query()->where('from_page', '<=', 22)->where('to_page', '>=', 22)->value('juz_number'))
            ->set('selectedPages', range(23, 25))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDuplicateModal', true)
            ->assertSet('duplicatePages', [23, 24])
            ->assertSet('uniquePages', [25]);
        $this->assertSame($originalJuzId, $enrollment->student->fresh()->quran_current_juz_id);
        $editor->call('confirmDuplicateSave')
            ->assertHasNoErrors();

        $this->assertSame(QuranJuz::where('juz_number', 2)->value('id'), $enrollment->student->fresh()->quran_current_juz_id);

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'recorded_by_user_id' => auth()->id(),
            'from_page' => 25,
            'to_page' => 25,
            'pages_count' => 1,
        ]);
    }

    public function test_teacher_quick_entry_can_record_for_active_students_outside_their_own_group_scope(): void
    {
        [, $teacher] = $this->teacherMemorizationContext();

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0998111998',
            'status' => 'active',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Older Student Parent',
        ]);

        $olderStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Older',
            'last_name' => 'Student',
            'birth_date' => '2010-02-02',
            'status' => 'active',
        ]);

        $otherCourse = Course::create([
            'name' => 'Older Student Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $otherGroup = Group::create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Older Student Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $olderStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        Volt::test('memorization.quick-entry')
            ->assertSee('Older Student')
            ->set('selectedStudentId', $olderStudent->id)
            ->set('selectedJuzNumber', QuranJuz::query()->where('from_page', '<=', 31)->where('to_page', '>=', 31)->value('juz_number'))
            ->set('selectedPages', range(31, 32))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $olderStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_by_user_id' => auth()->id(),
            'from_page' => 31,
            'to_page' => 32,
            'pages_count' => 2,
        ]);
    }

    public function test_non_privileged_user_without_a_teacher_profile_cannot_record_as_the_group_teacher(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();

        $operator = User::factory()->create([
            'username' => 'memorization-operator',
            'phone' => '0998111002',
        ]);
        $operator->givePermissionTo('memorization.record');

        $this->actingAs($operator);

        Volt::test('memorization.quick-entry')
            ->assertSee('Memorization Student')->assertDontSee('id="quick-memorization-teacher"', false)
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedJuzNumber', QuranJuz::query()->where('from_page', '<=', 42)->where('to_page', '>=', 42)->value('juz_number'))
            ->set('selectedPages', range(42, 43))
            ->call('save')
            ->assertHasErrors('selectedEnrollmentId');

        $this->assertDatabaseCount('memorization_sessions', 0);
    }

    public function test_non_teacher_quick_entry_requires_group_selection_when_student_has_multiple_active_enrollments(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();

        Group::create([
            'course_id' => Course::create(['name' => 'Second Quick Entry Course', 'is_active' => true])->id,
            'academic_year_id' => $enrollment->group->academic_year_id,
            'teacher_id' => $enrollment->group->teacher_id,
            'name' => 'Second Quick Entry Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $secondGroup = Group::query()->where('name', 'Second Quick Entry Group')->firstOrFail();

        $secondEnrollment = Enrollment::create([
            'student_id' => $enrollment->student_id,
            'group_id' => $secondGroup->id,
            'enrolled_at' => '2026-09-06',
            'status' => 'active',
        ]);

        $operator = User::factory()->create([
            'username' => 'memorization-operator-groups',
            'phone' => '0998111003',
        ]);
        $operator->assignRole('manager');

        $this->actingAs($operator);

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedJuzNumber', QuranJuz::query()->where('from_page', '<=', 43)->where('to_page', '>=', 43)->value('juz_number'))
            ->set('selectedPages', range(43, 44))
            ->call('save')
            ->assertHasErrors(['selectedEnrollmentId']);

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $secondEnrollment->id)
            ->set('selectedJuzNumber', QuranJuz::query()->where('from_page', '<=', 43)->where('to_page', '>=', 43)->value('juz_number'))
            ->set('selectedPages', range(43, 44))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $secondEnrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $secondGroup->teacher_id,
            'recorded_by_user_id' => $operator->id,
            'from_page' => 43,
            'to_page' => 44,
            'pages_count' => 2,
        ]);
    }

    public function test_memorization_entry_student_lists_require_an_active_student_enrollment_and_course(): void
    {
        [, , $eligibleEnrollment] = $this->teacherMemorizationContext();
        $teacherId = $eligibleEnrollment->group->teacher_id;
        $yearId = $eligibleEnrollment->group->academic_year_id;

        $makeEnrollment = function (string $name, string $studentStatus, string $enrollmentStatus, bool $courseActive) use ($teacherId, $yearId): Enrollment {
            $parent = ParentProfile::create(['father_name' => $name.' Parent']);
            $student = Student::create([
                'parent_id' => $parent->id,
                'first_name' => $name,
                'last_name' => 'Student',
                'birth_date' => '2014-01-01',
                'status' => $studentStatus,
            ]);
            $course = Course::create(['name' => $name.' Course', 'is_active' => $courseActive]);
            $group = Group::create([
                'course_id' => $course->id,
                'academic_year_id' => $yearId,
                'teacher_id' => $teacherId,
                'name' => $name.' Group',
                'capacity' => 12,
                'is_active' => true,
            ]);

            return Enrollment::create([
                'student_id' => $student->id,
                'group_id' => $group->id,
                'enrolled_at' => '2026-09-01',
                'status' => $enrollmentStatus,
            ]);
        };

        $makeEnrollment('Inactive Profile', 'inactive', 'active', true);
        $makeEnrollment('Inactive Enrollment', 'active', 'inactive', true);
        $makeEnrollment('Inactive Course', 'active', 'active', false);

        Volt::test('memorization.index')
            ->call('openCreateModal')
            ->assertSee('Memorization Student')
            ->assertDontSee('Inactive Profile Student')
            ->assertDontSee('Inactive Enrollment Student')
            ->assertDontSee('Inactive Course Student');

        Volt::test('memorization.quick-entry')
            ->assertSee('Memorization Student')
            ->assertDontSee('Inactive Profile Student')
            ->assertDontSee('Inactive Enrollment Student')
            ->assertDontSee('Inactive Course Student');
    }

    public function test_editing_memorization_preserves_historic_notes_after_the_notes_field_is_removed(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        $session = app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-03',
            'entry_type' => 'new',
            'from_page' => 11,
            'to_page' => 13,
            'notes' => 'Historic memorization note',
        ]);
        $enrollment->update(['status' => 'inactive']);

        Volt::test('memorization.index')
            ->call('editSession', $session->id)
            ->assertDontSee('memorization-notes', false)
            ->assertSee('Memorization Student')
            ->assertSee('data-memorization-student-readonly', false)
            ->assertSee('readonly', false)
            ->set('recorded_on', '2026-09-04')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'id' => $session->id,
            'notes' => 'Historic memorization note',
            'recorded_on' => '2026-09-04 00:00:00',
        ]);
    }

    public function test_memorization_from_a_finished_course_cannot_be_deleted(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        $session = app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-03',
            'entry_type' => 'new',
            'from_page' => 11,
            'to_page' => 13,
        ]);

        app(CourseLifecycleService::class)->finish($enrollment->group->course);

        Volt::test('memorization.index')
            ->call('editSession', $session->id)
            ->assertSet('editingCourseFinished', true)
            ->assertDontSee('data-memorization-session-delete-action', false)
            ->call('deleteSession', $session->id)
            ->assertHasErrors(['deleteSession']);

        $this->assertDatabaseHas('memorization_sessions', ['id' => $session->id]);
    }

    public function test_missing_page_picker_records_only_selected_non_consecutive_pages(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();
        $juz = QuranJuz::where('juz_number', 30)->firstOrFail();
        $enrollment->student->update(['quran_current_juz_id' => $juz->id]);
        app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id, 'recorded_on' => '2026-09-25', 'entry_type' => 'new',
            'from_page' => 583, 'to_page' => 583,
        ]);

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('selectedJuzNumber', 30)
            ->assertSet('teacher_id', $teacher->id)
            ->assertDontSee(__('workflow.memorization.workbench.form.group_auto'))
            ->assertDontSee(__('workflow.memorization.quick_entry.picker.hint'))
            ->assertDontSee(__('workflow.memorization.quick_entry.picker.remaining', ['count' => 22]))
            ->assertSee('data-memorization-page="582"', false)
            ->assertDontSee('data-memorization-page="583"', false)
            ->assertSee('data-memorization-page="584"', false)
            ->set('selectedPages', [582, 584, 586])
            ->assertDontSee(__('workflow.memorization.quick_entry.picker.remaining', ['count' => 22]))
            ->call('save')->assertHasNoErrors()
            ->assertSet('selectedPages', [])->assertSet('selectedStudentId', null);

        $session = $enrollment->memorizationSessions()->latest('id')->firstOrFail();
        $this->assertSame([582, 584, 586], $session->pages()->orderBy('page_no')->pluck('page_no')->all());
        $this->assertSame(3, $session->pages_count);
        $this->assertSame(4, $enrollment->fresh()->memorized_pages_cached);
        $this->assertDatabaseMissing('student_page_achievements', ['student_id' => $enrollment->student_id, 'page_no' => 585]);

        Volt::test('memorization.index')->call('editSession', $session->id)
            ->set('recorded_on', '2026-09-27')->call('save')->assertHasNoErrors()
            ->assertSet('showDuplicateModal', false);
        $this->assertSame([582, 584, 586], $session->pages()->orderBy('page_no')->pluck('page_no')->all());
        $this->assertSame(3, $session->fresh()->pages_count);
    }

    public function test_picker_skips_finished_ajza_in_the_requested_direction_and_clears_selection_on_navigation(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();
        $student = $enrollment->student;
        $student->externalMemorizedJuzs()->sync(QuranJuz::whereIn('juz_number', [1, 2, 26, 27, 29, 30])->pluck('id'));

        foreach ([30 => 28, 26 => 25, 1 => 3, 25 => 25] as $current => $expected) {
            $student->update(['quran_current_juz_id' => QuranJuz::where('juz_number', $current)->value('id')]);
            Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
                ->assertSet('selectedJuzNumber', $expected);
        }

        $student->externalMemorizedJuzs()->attach(QuranJuz::where('juz_number', 25)->value('id'));
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
            ->assertSet('selectedJuzNumber', 28);
        $student->externalMemorizedJuzs()->detach(QuranJuz::where('juz_number', 25)->value('id'));

        $student->update(['quran_current_juz_id' => QuranJuz::where('juz_number', 28)->value('id')]);
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
            ->set('selectedPages', [542])->call('navigateJuz', -1)
            ->assertSet('selectedJuzNumber', 25)->assertSet('selectedPages', [])
            ->call('navigateJuz', 1)->assertSet('selectedJuzNumber', 28);

        $student->externalMemorizedJuzs()->sync(QuranJuz::pluck('id'));
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
            ->assertSet('selectedJuzNumber', null)->assertSee(__('workflow.memorization.quick_entry.picker.complete'));
    }

    public function test_quick_picker_rejects_empty_out_of_juz_and_invalid_page_selections(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $enrollment->student_id)
            ->call('save')->assertHasErrors('selectedPages')
            ->set('selectedPages', [0])->call('save')->assertHasErrors('selectedPages.0')
            ->set('selectedPages', [1])->call('save')->assertHasErrors('selectedPages')
            ->set('selectedPages', [582, 582])->call('save')->assertHasErrors('selectedPages.0');
        $this->assertSame(0, $enrollment->memorizationSessions()->count());
    }

    public function test_quick_picker_excludes_external_and_all_saber_types_across_enrollments_and_rejects_hidden_juz_on_save(): void
    {
        [, $teacher, $oldEnrollment] = $this->teacherMemorizationContext();
        $student = $oldEnrollment->student;
        $juzs = QuranJuz::all()->keyBy('juz_number');
        $student->update(['quran_current_juz_id' => $juzs[30]->id]);
        $student->externalMemorizedJuzs()->attach($juzs[30]->id);
        QuranPartialTest::create(['student_id' => $student->id, 'enrollment_id' => $oldEnrollment->id, 'juz_id' => $juzs[29]->id, 'status' => 'in_progress']);
        QuranFinalTest::create(['student_id' => $student->id, 'enrollment_id' => $oldEnrollment->id, 'juz_id' => $juzs[28]->id, 'status' => 'passed']);
        foreach (['awqaf' => 27, 'final' => 26, 'partial' => 25] as $code => $number) {
            QuranTest::create([
                'student_id' => $student->id, 'enrollment_id' => $oldEnrollment->id,
                'teacher_id' => $teacher->id, 'juz_id' => $juzs[$number]->id,
                'quran_test_type_id' => QuranTestType::where('code', $code)->value('id'),
                'tested_on' => '2026-09-01', 'status' => 'failed', 'attempt_no' => 1,
            ]);
        }
        $oldEnrollment->update(['status' => 'completed']);
        $oldEnrollment->group->course->update(['is_active' => false]);
        $newGroup = Group::create([
            'course_id' => Course::create(['name' => 'Current Memorization Course', 'is_active' => true])->id,
            'academic_year_id' => $oldEnrollment->group->academic_year_id,
            'teacher_id' => $teacher->id, 'name' => 'Current Memorization Group', 'is_active' => true,
        ]);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $newGroup->id, 'status' => 'active', 'enrolled_at' => '2026-09-20']);

        $component = Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
            ->assertSet('selectedJuzNumber', 24);
        $this->assertSame(range(1, 24), $component->get('unfinishedJuzs')->pluck('number')->all());
        $component->call('navigateJuz', 1)->assertSet('selectedJuzNumber', 24);

        foreach (range(25, 30) as $number) {
            $component->set('selectedJuzNumber', $number)->set('selectedPages', [$juzs[$number]->from_page])
                ->call('save')->assertHasErrors('selectedPages');
        }
        $this->assertSame(0, $enrollment->memorizationSessions()->count());
    }

    public function test_quick_entry_rechecks_saber_exclusions_before_confirming_duplicate_pages(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();
        app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id, 'recorded_on' => '2026-09-25', 'entry_type' => 'new',
            'from_page' => 22, 'to_page' => 23,
        ]);
        $component = Volt::test('memorization.quick-entry')->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedJuzNumber', 2)->set('selectedPages', [23, 24])->call('save')
            ->assertSet('showDuplicateModal', true);
        QuranFinalTest::create([
            'student_id' => $enrollment->student_id, 'enrollment_id' => $enrollment->id,
            'juz_id' => QuranJuz::where('juz_number', 2)->value('id'), 'status' => 'in_progress',
        ]);

        $component->call('confirmDuplicateSave')->assertSet('showDuplicateModal', false)->assertHasErrors('selectedPages');
        $this->assertSame(1, $enrollment->memorizationSessions()->count());
        $this->assertSame([22, 23], $enrollment->memorizationSessions()->firstOrFail()->pages()->orderBy('page_no')->pluck('page_no')->all());
    }

    #[DataProvider('recordingAdministratorRoles')]
    public function test_administrator_teacher_is_preselected_for_the_chosen_group_and_can_be_overridden(string $role): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Teacher::create(['user_id' => $admin->id, 'first_name' => 'Administrator', 'last_name' => 'Teacher', 'phone' => '0998111778', 'status' => 'active']);
        $otherUser = User::factory()->create();
        $otherUser->assignRole('teacher');
        $otherTeacher = Teacher::create(['user_id' => $otherUser->id, 'first_name' => 'Other', 'last_name' => 'Recorder', 'phone' => '0998111777', 'status' => 'active']);
        $this->actingAs($admin);

        Volt::test('memorization.quick-entry')->assertSee('id="quick-memorization-teacher"', false)->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('teacher_id', $teacher->id)
            ->assertDontSee(__('workflow.memorization.quick_entry.group_teacher_context', ['name' => trim($teacher->first_name.' '.$teacher->last_name)]))
            ->set('teacher_id', $otherTeacher->id)->set('selectedJuzNumber', 30)->set('selectedPages', [582])
            ->call('save')->assertHasNoErrors();
        $this->assertSame($otherTeacher->id, $enrollment->memorizationSessions()->firstOrFail()->teacher_id);

        $group = Group::create([
            'course_id' => Course::create(['name' => 'Other Recording Course', 'is_active' => true])->id,
            'academic_year_id' => $enrollment->group->academic_year_id,
            'teacher_id' => $otherTeacher->id, 'name' => 'Other Recording Group', 'is_active' => true,
        ]);
        $otherEnrollment = Enrollment::create(['student_id' => $enrollment->student_id, 'group_id' => $group->id, 'status' => 'active', 'enrolled_at' => '2026-09-20']);
        Volt::test('memorization.quick-entry')->assertSee('id="quick-memorization-teacher"', false)->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('teacher_id', null)
            ->set('selectedEnrollmentId', $enrollment->id)->assertSet('teacher_id', $teacher->id)
            ->set('selectedEnrollmentId', $otherEnrollment->id)->assertSet('teacher_id', $otherTeacher->id)
            ->set('selectedStudentId', null)->assertSet('teacher_id', null);
    }

    public static function recordingAdministratorRoles(): array
    {
        return [['manager'], ['admin'], ['super_admin']];
    }

    public function test_teacher_dropdown_is_hidden_and_a_forged_teacher_id_does_not_change_the_recorder(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();
        $otherTeacher = Teacher::create(['first_name' => 'Different', 'last_name' => 'Teacher', 'phone' => '0998111779', 'status' => 'active']);
        $enrollment->group->update(['teacher_id' => $otherTeacher->id]);
        Volt::test('memorization.quick-entry')->assertDontSee('id="quick-memorization-teacher"', false)
            ->set('selectedStudentId', $enrollment->student_id)->assertSet('teacher_id', $teacher->id)
            ->set('teacher_id', $otherTeacher->id)->set('selectedJuzNumber', 30)->set('selectedPages', [582])
            ->call('save')->assertHasNoErrors();
        $this->assertSame($teacher->id, $enrollment->memorizationSessions()->sole()->teacher_id);
    }

    public function test_administrator_can_default_to_a_group_teacher_without_a_login_but_cannot_choose_an_inactive_teacher(): void
    {
        [$user, , $enrollment] = $this->teacherMemorizationContext();
        $user->assignRole('manager');
        $teacher = Teacher::create(['first_name' => 'Group', 'last_name' => 'Teacher', 'phone' => '0998111780', 'status' => 'active']);
        $inactive = Teacher::create(['first_name' => 'Inactive', 'last_name' => 'Teacher', 'phone' => '0998111781', 'status' => 'inactive']);
        $enrollment->group->update(['teacher_id' => $teacher->id]);
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('teacher_id', $teacher->id)->set('selectedJuzNumber', 30)->set('selectedPages', [582])
            ->set('teacher_id', $inactive->id)->call('save')->assertHasErrors('teacher_id')
            ->set('teacher_id', $teacher->id)->call('save')->assertHasNoErrors();
        $this->assertSame($teacher->id, $enrollment->memorizationSessions()->sole()->teacher_id);
    }

    public function test_browsing_juzs_only_changes_current_juz_after_saving_a_page(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();
        $student = $enrollment->student;
        $juzIds = QuranJuz::pluck('id', 'juz_number');
        app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id, 'recorded_on' => now()->toDateString(),
            'entry_type' => 'new', 'from_page' => 582, 'to_page' => 582,
        ]);
        $student->update(['quran_current_juz_id' => $juzIds[30]]);
        $editor = Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
            ->assertSee('data-memorization-juz-trigger', false)->assertSee('data-juz-choice="2"', false)
            ->set('selectedPages', [582])->call('navigateJuz', -1)
            ->assertSet('selectedJuzNumber', 29)->assertSet('selectedPages', [])->assertHasNoErrors();
        $this->assertSame($juzIds[30], $student->fresh()->quran_current_juz_id);
        $editor->call('selectJuz', 2)->assertSet('selectedJuzNumber', 2)
            ->assertSee('data-memorization-page="22"', false)->assertDontSee('data-memorization-page="582"', false)
            ->set('selectedPages', [22])->call('selectJuz', 2)->assertSet('selectedPages', [22]);
        $this->assertSame($juzIds[30], $student->fresh()->quran_current_juz_id);
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)->assertSet('selectedJuzNumber', 30);
        $editor->set('selectedPages', [])->call('save')->assertHasErrors('selectedPages');
        $this->assertSame($juzIds[30], $student->fresh()->quran_current_juz_id);
        $editor->set('selectedPages', [22]);
        $editor->call('save')->assertHasNoErrors();
        $this->assertSame($juzIds[2], $student->fresh()->quran_current_juz_id);
        $this->assertSame([22], $enrollment->memorizationSessions()->latest('id')->first()->pages()->pluck('page_no')->all());
    }

    public function test_quick_juz_switch_rejects_excluded_finished_and_invalid_juz_without_changing_student(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();
        $student = $enrollment->student;
        $juzIds = QuranJuz::pluck('id', 'juz_number');
        $student->update(['quran_current_juz_id' => $juzIds[30]]);
        $student->externalMemorizedJuzs()->attach($juzIds[5]);
        app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id, 'recorded_on' => '2026-09-26', 'entry_type' => 'new', 'from_page' => 1, 'to_page' => 21,
        ]);
        $student->refresh()->update(['quran_current_juz_id' => $juzIds[30]]);
        $editor = Volt::test('memorization.quick-entry')->set('selectedStudentId', $student->id)
            ->assertDontSee('data-juz-choice="1"', false)->assertDontSee('data-juz-choice="5"', false);
        foreach ([0, 1, 5, 31] as $number) {
            $editor->call('selectJuz', $number)->assertHasErrors('selectedJuzNumber')->assertSet('selectedJuzNumber', 30);
            $this->assertSame($juzIds[30], $student->fresh()->quran_current_juz_id);
        }
        $student->externalMemorizedJuzs()->attach($juzIds[2]);
        $editor->call('selectJuz', 2)->assertHasErrors('selectedJuzNumber');
        $this->assertSame($juzIds[30], $student->fresh()->quran_current_juz_id);
    }

    public function test_automatic_picker_fallback_does_not_change_current_juz(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();
        $juzId = QuranJuz::where('juz_number', 30)->value('id');
        $enrollment->student->update(['quran_current_juz_id' => $juzId]);
        $enrollment->student->externalMemorizedJuzs()->attach($juzId);
        Volt::test('memorization.quick-entry')->set('selectedStudentId', $enrollment->student_id)->assertSet('selectedJuzNumber', 29);
        $this->assertSame($juzId, $enrollment->student->fresh()->quran_current_juz_id);
    }

    public function test_quick_juz_switch_requires_recording_permission(): void
    {
        [$user, , $enrollment] = $this->teacherMemorizationContext();
        $editor = Volt::test('memorization.quick-entry')->set('selectedStudentId', $enrollment->student_id);
        $current = $enrollment->student->quran_current_juz_id;
        $user->syncRoles([]);
        $user->syncPermissions([]);
        $editor->call('selectJuz', 2)->assertForbidden();
        $this->assertSame($current, $enrollment->student->fresh()->quran_current_juz_id);
    }

    private function teacherMemorizationContext(): array
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'username' => 'memorization-teacher',
            'phone' => '0998111000',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Memorization',
            'last_name' => 'Teacher',
            'phone' => '0998111001',
            'status' => 'active',
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Memorization Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Memorization',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Memorization Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Memorization Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser);

        return [$teacherUser, $teacher, $enrollment];
    }
}

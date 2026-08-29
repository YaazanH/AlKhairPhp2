<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestAttempt;
use App\Models\QuranPartialTestPart;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Models\User;
use App\Services\QuranFinalTestService;
use App\Services\QuranPartialTestService;
use App\Services\QuranProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StandaloneWorkflowPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_memorization_starts_partial_or_final_tests_without_entering_normal_eligibility_lists(): void
    {
        [, $enrollment] = $this->teacherContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();
        $enrollment->student->externalMemorizedJuzs()->attach($juz->id);

        $this->assertNotContains($juz->id, app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($enrollment->student)->all());
        $this->assertNotContains($juz->id, app(QuranFinalTestService::class)->eligibleJuzIdsForStudent($enrollment->student)->all());

        $partial = app(QuranPartialTestService::class)->createForExternalMemorization($enrollment->fresh('student'), $juz);
        $final = app(QuranFinalTestService::class)->createForExternalMemorization($enrollment->fresh('student'), $juz);

        $this->assertSame('in_progress', $partial->status);
        $this->assertCount(4, $partial->parts);
        $this->assertSame('in_progress', $final->status);
    }

    public function test_teacher_partial_test_workbench_uses_the_logged_in_teacher(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();
        $session = MemorizationSession::query()->create([
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'new',
            'from_page' => $juz->from_page,
            'pages_count' => $juz->to_page - $juz->from_page + 1,
            'recorded_on' => '2026-09-09',
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'to_page' => $juz->to_page,
        ]);

        foreach (range($juz->from_page, $juz->to_page) as $pageNo) {
            StudentPageAchievement::query()->create([
                'first_enrollment_id' => $enrollment->id,
                'first_recorded_on' => '2026-09-09',
                'first_session_id' => $session->id,
                'page_no' => $pageNo,
                'student_id' => $enrollment->student_id,
            ]);
        }

        Volt::test('quran-partial-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('juz_id', $juz->id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertHasNoErrors();

        $partialTest = QuranPartialTest::query()->firstOrFail();
        $part = $partialTest->parts()->where('part_number', 1)->firstOrFail();

        Volt::test('quran-partial-tests.show', ['partialTest' => $partialTest])
            ->call('openAttemptModal', $part->id)
            ->set('tested_on', '2026-09-10')
            ->set('mistake_count', '2')
            ->call('saveAttempt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_partial_test_attempts', [
            'attempt_no' => 1,
            'mistake_count' => 2,
            'quran_partial_test_part_id' => $part->id,
            'status' => 'passed',
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_partial_test_workbench_warns_before_creating_another_open_cycle(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        $juzs = QuranJuz::query()
            ->whereIn('juz_number', [1, 2])
            ->orderBy('juz_number')
            ->get();

        $session = MemorizationSession::query()->create([
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'new',
            'from_page' => $juzs->first()->from_page,
            'pages_count' => $juzs->last()->to_page - $juzs->first()->from_page + 1,
            'recorded_on' => '2026-09-09',
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'to_page' => $juzs->last()->to_page,
        ]);

        foreach (range($juzs->first()->from_page, $juzs->last()->to_page) as $pageNo) {
            StudentPageAchievement::query()->create([
                'first_enrollment_id' => $enrollment->id,
                'first_recorded_on' => '2026-09-09',
                'first_session_id' => $session->id,
                'page_no' => $pageNo,
                'student_id' => $enrollment->student_id,
            ]);
        }

        $openPartialTest = QuranPartialTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juzs->first()->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);

        foreach (range(1, 4) as $partNumber) {
            $openPartialTest->parts()->create([
                'part_number' => $partNumber,
                'status' => 'pending',
            ]);
        }

        Volt::test('quran-partial-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juzs->last()->id)
            ->call('save')
            ->assertSet('showOpenTestWarningModal', true)
            ->call('confirmOpenTestWarningCreate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_partial_tests', [
            'juz_id' => $juzs->last()->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);
    }

    public function test_partial_test_workbench_can_filter_by_juz(): void
    {
        [$teacher] = $this->teacherContext();
        $juzs = QuranJuz::query()
            ->whereIn('juz_number', [1, 2])
            ->orderBy('juz_number')
            ->get();

        $firstEnrollment = $this->makeEnrollment($teacher->id, 'Partial Filter Alpha');
        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Partial Filter Beta');

        foreach ([[$firstEnrollment, $juzs->first(), 'passed'], [$secondEnrollment, $juzs->last(), 'in_progress']] as [$enrollment, $juz, $status]) {
            $partialTest = QuranPartialTest::query()->create([
                'created_by' => $teacher->user_id,
                'enrollment_id' => $enrollment->id,
                'juz_id' => $juz->id,
                'passed_on' => $status === 'passed' ? '2026-09-15' : null,
                'status' => $status,
                'student_id' => $enrollment->student_id,
            ]);

            foreach (range(1, 4) as $partNumber) {
                $partialTest->parts()->create([
                    'part_number' => $partNumber,
                    'passed_on' => $status === 'passed' ? '2026-09-1'.$partNumber : null,
                    'status' => $status === 'passed' ? 'passed' : 'pending',
                ]);
            }
        }

        Volt::test('quran-partial-tests.index')
            ->set('juzFilter', (string) $juzs->first()->id)
            ->assertSee('Partial Filter Alpha')
            ->assertDontSee('Partial Filter Beta');
    }

    public function test_teacher_final_test_workbench_uses_the_logged_in_teacher_and_locks_after_pass(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'passed_on' => '2026-09-09',
            'status' => 'passed',
            'student_id' => $enrollment->student_id,
        ]);

        foreach (range(1, 4) as $partNumber) {
            $partialTest->parts()->create([
                'part_number' => $partNumber,
                'passed_on' => '2026-09-0'.(5 + $partNumber),
                'status' => 'passed',
            ]);
        }

        Volt::test('quran-final-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->assertSet('juz_id', $juz->id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertHasNoErrors();

        $finalTest = QuranFinalTest::query()->firstOrFail();

        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->call('openAttemptModal')
            ->set('tested_on', '2026-09-12')
            ->set('score', '95')
            ->call('saveAttempt')
            ->assertHasNoErrors()
            ->call('openAttemptModal')
            ->assertHasErrors(['attempt']);

        $this->assertDatabaseHas('quran_final_test_attempts', [
            'attempt_no' => 1,
            'quran_final_test_id' => $finalTest->id,
            'status' => 'passed',
            'teacher_id' => $teacher->id,
        ]);

        $this->assertDatabaseHas('quran_final_tests', [
            'id' => $finalTest->id,
            'status' => 'passed',
        ]);
    }

    public function test_partial_and_final_test_student_lists_require_an_active_student_enrollment_and_course(): void
    {
        [$teacher, $eligibleEnrollment] = $this->teacherContext();
        $inactiveStudentEnrollment = $this->makeEnrollment($teacher->id, 'Inactive Quran Profile');
        $inactiveStudentEnrollment->student()->update(['status' => 'inactive']);
        $inactiveEnrollment = $this->makeEnrollment($teacher->id, 'Inactive Quran Enrollment');
        $inactiveEnrollment->update(['status' => 'inactive']);
        $inactiveCourseEnrollment = $this->makeEnrollment($teacher->id, 'Inactive Quran Course');
        $inactiveCourseEnrollment->group->course()->update(['is_active' => false]);

        foreach (['quran-partial-tests.index', 'quran-final-tests.index'] as $component) {
            Volt::test($component)
                ->call('openCreateModal')
                ->assertSee($eligibleEnrollment->student->full_name)
                ->assertDontSee($inactiveStudentEnrollment->student->full_name)
                ->assertDontSee($inactiveEnrollment->student->full_name)
                ->assertDontSee($inactiveCourseEnrollment->student->full_name);
        }
    }

    public function test_recording_on_behalf_teacher_lists_exclude_admins_and_managers(): void
    {
        $enrollment = $this->managerContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $makeTeacher = function (string $role, string $firstName, string $phone): Teacher {
            $user = User::factory()->create([
                'name' => $firstName,
                'username' => str($firstName)->slug()->value(),
                'phone' => $phone,
            ]);
            $user->assignRole($role);

            return Teacher::create([
                'user_id' => $user->id,
                'first_name' => $firstName,
                'last_name' => 'Recorder',
                'phone' => $phone,
                'status' => 'active',
            ]);
        };

        $eligibleTeacher = $makeTeacher('teacher', 'Eligible Teacher', '0998555101');
        $adminTeacher = $makeTeacher('admin', 'Admin Teacher', '0998555102');
        $managerTeacher = $makeTeacher('manager', 'Manager Teacher', '0998555103');

        $partialTest = QuranPartialTest::create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);
        $part = $partialTest->parts()->create(['part_number' => 1, 'status' => 'pending']);

        $finalTest = QuranFinalTest::create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);

        Volt::test('quran-partial-tests.show', ['partialTest' => $partialTest])
            ->call('openAttemptModal', $part->id)
            ->assertSee('Eligible Teacher Recorder')
            ->assertDontSee('Admin Teacher Recorder')
            ->assertDontSee('Manager Teacher Recorder');

        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->call('openAttemptModal')
            ->assertSee('Eligible Teacher Recorder')
            ->assertDontSee('Admin Teacher Recorder')
            ->assertDontSee('Manager Teacher Recorder');
    }

    public function test_super_admin_can_edit_partial_and_final_test_attempts(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        auth()->user()->syncRoles(['super_admin']);
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);
        $part = $partialTest->parts()->create(['part_number' => 1, 'status' => 'pending']);
        foreach (range(2, 4) as $partNumber) {
            $partialTest->parts()->create(['part_number' => $partNumber, 'status' => 'pending']);
        }
        $partialAttempt = $part->attempts()->create([
            'attempt_no' => 1,
            'mistake_count' => 5,
            'status' => 'failed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-10',
        ]);

        Volt::test('quran-partial-tests.show', ['partialTest' => $partialTest])
            ->call('openEditAttempt', $partialAttempt->id)
            ->assertSet('editingAttemptId', $partialAttempt->id)
            ->assertSet('selectedPartId', $part->id)
            ->assertSet('showAttemptModal', true)
            ->assertSee('admin-modal__dialog--compact', false)
            ->assertSee('admin-action-cluster admin-action-cluster--end', false)
            ->set('mistake_count', '2')
            ->assertSet('mistake_count', '2')
            ->call('saveAttempt')
            ->assertSet('showAttemptModal', false)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_partial_test_attempts', ['id' => $partialAttempt->id, 'mistake_count' => 2, 'status' => 'passed']);
        $this->assertDatabaseHas('quran_partial_test_parts', ['id' => $part->id, 'status' => 'passed']);

        $finalTest = QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);
        $finalAttempt = $finalTest->attempts()->create([
            'attempt_no' => 1,
            'score' => 60,
            'status' => 'failed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-11',
        ]);

        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->call('openEditAttempt', $finalAttempt->id)
            ->assertSee('admin-modal__dialog--compact', false)
            ->assertSee('admin-action-cluster admin-action-cluster--end', false)
            ->set('score', '95.5')
            ->call('saveAttempt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_final_test_attempts', ['id' => $finalAttempt->id, 'score' => 95.5, 'status' => 'passed']);
        $this->assertDatabaseHas('quran_final_tests', ['id' => $finalTest->id, 'status' => 'passed']);

        $awqafType = QuranTestType::query()->where('code', 'awqaf')->firstOrFail();
        QuranTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $awqafType->id,
            'tested_on' => '2026-09-12',
            'score' => 96,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->assertViewHas('hasRelatedAwqafTest', true)
            ->assertDontSee('data-final-saber-delete', false)
            ->assertDontSee('data-final-saber-edit', false)
            ->assertDontSee('data-final-saber-attempt-delete', false);
    }

    public function test_editing_a_final_saber_attempt_preserves_historic_notes_after_the_notes_field_is_removed(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        auth()->user()->syncRoles(['super_admin']);
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();
        $finalTest = QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);
        $attempt = $finalTest->attempts()->create([
            'attempt_no' => 1,
            'notes' => 'Historic final saber note',
            'score' => 60,
            'status' => 'failed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-11',
        ]);

        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->assertDontSee('final-attempt-notes', false);

        app(QuranFinalTestService::class)->updateAttempt($attempt, $teacher, [
            'score' => 95.5,
            'tested_on' => '2026-09-12',
        ]);

        $this->assertDatabaseHas('quran_final_test_attempts', [
            'id' => $attempt->id,
            'notes' => 'Historic final saber note',
            'score' => 95.5,
        ]);
    }

    public function test_partial_saber_and_quarter_attempts_cannot_be_deleted_after_a_related_final_saber_exists(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        auth()->user()->syncRoles(['super_admin']);
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'passed',
            'student_id' => $enrollment->student_id,
        ]);
        $part = $partialTest->parts()->create([
            'part_number' => 1,
            'status' => 'passed',
        ]);
        $attempt = $part->attempts()->create([
            'attempt_no' => 1,
            'mistake_count' => 1,
            'status' => 'passed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-10',
        ]);

        QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);

        Volt::test('quran-partial-tests.show', ['partialTest' => $partialTest])
            ->assertViewHas('hasRelatedFinalTest', true)
            ->assertSeeText(__('workflow.quran_partial_tests.part.quarters.1'))
            ->assertDontSee('data-partial-saber-delete', false)
            ->assertDontSee('data-partial-saber-edit', false)
            ->assertDontSee('data-partial-saber-attempt-delete', false)
            ->assertDontSee('partial-attempt-notes', false)
            ->set('editingAttemptId', $attempt->id)
            ->call('deleteAttempt')
            ->assertHasErrors(['attempt'])
            ->call('deleteTest')
            ->assertHasErrors(['deleteTest']);

        $this->assertDatabaseHas('quran_partial_tests', ['id' => $partialTest->id]);
        $this->assertDatabaseHas('quran_partial_test_attempts', ['id' => $attempt->id]);
    }

    public function test_final_test_workbench_warns_when_same_juz_already_has_an_open_cycle(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'passed_on' => '2026-09-09',
            'status' => 'passed',
            'student_id' => $enrollment->student_id,
        ]);

        foreach (range(1, 4) as $partNumber) {
            $partialTest->parts()->create([
                'part_number' => $partNumber,
                'passed_on' => '2026-09-0'.(5 + $partNumber),
                'status' => 'passed',
            ]);
        }

        QuranFinalTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $enrollment->student_id,
        ]);

        $this->assertFalse(
            app(QuranFinalTestService::class)
                ->eligibleJuzIdsForStudent($enrollment->student->fresh())
                ->contains($juz->id)
        );

        Volt::test('quran-final-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertSet('showOpenTestWarningModal', true)
            ->assertSet('existingFinalTestSummary.juz_number', $juz->juz_number);
    }

    public function test_final_test_workbench_can_filter_by_juz(): void
    {
        [$teacher] = $this->teacherContext();
        $juzs = QuranJuz::query()
            ->whereIn('juz_number', [1, 2])
            ->orderBy('juz_number')
            ->get();

        $firstEnrollment = $this->makeEnrollment($teacher->id, 'Final Filter Alpha');
        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Final Filter Beta');

        QuranFinalTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $firstEnrollment->id,
            'juz_id' => $juzs->first()->id,
            'passed_on' => '2026-09-16',
            'status' => 'passed',
            'student_id' => $firstEnrollment->student_id,
        ]);

        QuranFinalTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $secondEnrollment->id,
            'juz_id' => $juzs->last()->id,
            'status' => 'in_progress',
            'student_id' => $secondEnrollment->student_id,
        ]);

        Volt::test('quran-final-tests.index')
            ->set('juzFilter', (string) $juzs->first()->id)
            ->assertSee('Final Filter Alpha')
            ->assertDontSee('Final Filter Beta');
    }

    public function test_final_test_workbench_can_sort_by_last_quiz_date(): void
    {
        [$teacher] = $this->teacherContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $olderEnrollment = $this->makeEnrollment($teacher->id, 'Final Date Older');
        $newerEnrollment = $this->makeEnrollment($teacher->id, 'Final Date Newer');

        $olderFinalTest = QuranFinalTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $olderEnrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $olderEnrollment->student_id,
        ]);

        $newerFinalTest = QuranFinalTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $newerEnrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $newerEnrollment->student_id,
        ]);

        QuranFinalTestAttempt::query()->create([
            'attempt_no' => 1,
            'quran_final_test_id' => $olderFinalTest->id,
            'score' => 55,
            'status' => 'failed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-10',
        ]);

        QuranFinalTestAttempt::query()->create([
            'attempt_no' => 1,
            'quran_final_test_id' => $newerFinalTest->id,
            'score' => 60,
            'status' => 'failed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-20',
        ]);

        Volt::test('quran-final-tests.index')
            ->assertSet('sortField', 'last_tested_on')
            ->assertSet('sortDirection', 'desc')
            ->assertSeeInOrder(['Final Date Newer', 'Final Date Older']);

        Volt::test('quran-final-tests.index')
            ->set('sortField', 'last_tested_on')
            ->set('sortDirection', 'asc')
            ->assertSee('10-09-2026')
            ->assertSee('20-09-2026')
            ->assertSeeInOrder(['Final Date Older', 'Final Date Newer'])
            ->call('sortBy', 'last_tested_on')
            ->assertSeeInOrder(['Final Date Newer', 'Final Date Older']);
    }

    public function test_partial_test_workbench_shows_last_test_date_and_defaults_to_newest_record(): void
    {
        [$teacher] = $this->teacherContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();
        $olderEnrollment = $this->makeEnrollment($teacher->id, 'Partial Record Older');
        $newerEnrollment = $this->makeEnrollment($teacher->id, 'Partial Record Newer');

        $olderTest = QuranPartialTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $olderEnrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $olderEnrollment->student_id,
        ]);
        $newerTest = QuranPartialTest::query()->create([
            'created_by' => $teacher->user_id,
            'enrollment_id' => $newerEnrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $newerEnrollment->student_id,
        ]);

        $olderPart = QuranPartialTestPart::query()->create([
            'part_number' => 1,
            'quran_partial_test_id' => $olderTest->id,
            'status' => 'pending',
        ]);
        $newerPart = QuranPartialTestPart::query()->create([
            'part_number' => 1,
            'quran_partial_test_id' => $newerTest->id,
            'status' => 'pending',
        ]);
        QuranPartialTestAttempt::query()->create([
            'attempt_no' => 1,
            'mistake_count' => 4,
            'quran_partial_test_part_id' => $olderPart->id,
            'status' => 'failed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-12',
        ]);
        QuranPartialTestAttempt::query()->create([
            'attempt_no' => 1,
            'mistake_count' => 2,
            'quran_partial_test_part_id' => $newerPart->id,
            'status' => 'passed',
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-22',
        ]);

        Volt::test('quran-partial-tests.index')
            ->assertSet('sortField', 'last_tested_on')
            ->assertSet('sortDirection', 'desc')
            ->assertSee('12-09-2026')
            ->assertSee('22-09-2026')
            ->assertSeeInOrder(['Partial Record Newer', 'Partial Record Older']);
    }

    public function test_manager_point_ledger_workbench_creates_and_updates_manual_entries(): void
    {
        $enrollment = $this->managerContext();
        $bonus = PointType::query()->create([
            'name' => 'Workbench Reward',
            'code' => 'workbench-reward',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        Volt::test('points.index')
            ->assertDontSee('Workbench Manager Group Parent')
            ->assertDontSee(__('workflow.points.workbench.table.headers.void_reason'))
            ->assertViewHas('manualPointTypes', function ($pointTypes): bool {
                $names = $pointTypes->pluck('name')->values();

                return $names->all() === $names->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            })
            ->call('openCreateModal')
            ->assertSee('id="points-workbench-type" wire:model="manual_point_type_id" data-search-input="true" data-open-on-focus="true"', false)
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('manual_point_type_id', $bonus->id)
            ->call('saveManualAndNew')
            ->assertHasNoErrors()
            ->assertSee('points-ledger-table', false)
            ->assertSee('points-ledger-desktop', false)
            ->assertSee('points-ledger-mobile', false)
            ->assertSee('points-ledger-mobile__metrics', false)
            ->assertSee('points-ledger-entered-at', false)
            ->assertSee('data-has-void-reason="false"', false)
            ->assertSet('showFormModal', true)
            ->assertSet('selectedStudentId', null)
            ->assertSet('manual_point_type_id', $bonus->id)
            ->set('stateFilter', 'all')
            ->assertSee('data-has-void-reason="true"', false)
            ->assertSee(__('workflow.points.workbench.table.headers.void_reason'));

        $transaction = PointTransaction::query()->where('source_type', 'manual')->firstOrFail();

        Volt::test('points.index')
            ->assertSee('wire:click="editManual('.$transaction->id.')"', false)
            ->assertDontSee('wire:click="openVoidModal('.$transaction->id.')"', false)
            ->call('editManual', $transaction->id)
            ->assertSee('wire:click="openVoidModal('.$transaction->id.')"', false)
            ->assertSee('data-points-manual-update-action', false)
            ->assertSee('data-points-manual-delete-action', false)
            ->assertSee('admin-modal-action-button', false)
            ->call('openVoidModal', $transaction->id)
            ->assertSee('data-points-void-confirm-action', false)
            ->assertDontSee('data-points-void-close-action', false)
            ->assertSee('data-icon-name="delete"', false)
            ->call('closeVoidModal')
            ->call('saveManual')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_transactions', [
            'id' => $transaction->id,
            'points' => 5,
            'notes' => null,
        ]);
    }

    public function test_manager_awqaf_workbench_uses_group_teacher_and_hides_recorded_juzs(): void
    {
        $enrollment = $this->managerContext();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        $finalTest = QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'passed_on' => '2026-09-12',
            'status' => 'passed',
            'student_id' => $enrollment->student_id,
        ]);

        $finalTest->attempts()->create([
            'attempt_no' => 1,
            'score' => 91,
            'status' => 'passed',
            'teacher_id' => $enrollment->group->teacher_id,
            'tested_on' => '2026-09-12',
        ]);

        Volt::test('quran-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertHasErrors(['score' => 'required_if'])
            ->set('score', '90')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_tests', [
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'teacher_id' => $enrollment->group->teacher_id,
            'score' => 90,
        ]);

        $this->assertFalse(
            app(QuranProgressionService::class)
                ->eligibleAwqafJuzIdsForStudent($enrollment->student_id)
                ->contains($juz->id)
        );
    }

    public function test_awqaf_workbench_can_filter_by_juz(): void
    {
        $firstEnrollment = $this->managerContext();
        $managerUser = auth()->user();

        $teacher = Teacher::create([
            'first_name' => 'Awqaf',
            'last_name' => 'Filter Teacher',
            'phone' => '0998444999',
            'status' => 'active',
        ]);

        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Awqaf Filter Beta');
        $this->actingAs($managerUser);

        $awqafType = QuranTestType::query()->where('code', 'awqaf')->firstOrFail();
        $juzs = QuranJuz::query()
            ->whereIn('juz_number', [1, 2])
            ->orderBy('juz_number')
            ->get();

        QuranTest::query()->create([
            'enrollment_id' => $firstEnrollment->id,
            'student_id' => $firstEnrollment->student_id,
            'teacher_id' => $firstEnrollment->group->teacher_id,
            'juz_id' => $juzs->first()->id,
            'quran_test_type_id' => $awqafType->id,
            'tested_on' => '2026-09-20',
            'score' => 90,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        QuranTest::query()->create([
            'enrollment_id' => $secondEnrollment->id,
            'student_id' => $secondEnrollment->student_id,
            'teacher_id' => $secondEnrollment->group->teacher_id,
            'juz_id' => $juzs->last()->id,
            'quran_test_type_id' => $awqafType->id,
            'tested_on' => '2026-09-21',
            'score' => 71,
            'status' => 'failed',
            'attempt_no' => 1,
        ]);

        Volt::test('quran-tests.index')
            ->set('juzFilter', (string) $juzs->first()->id)
            ->assertSee('Workbench Manager Group')
            ->assertDontSee('Awqaf Filter Beta');
    }

    public function test_awqaf_workbench_lists_students_eligible_for_awqaf_test(): void
    {
        $firstEnrollment = $this->managerContext();
        $managerUser = auth()->user();

        $teacher = Teacher::create([
            'first_name' => 'Eligible',
            'last_name' => 'Teacher',
            'phone' => '0998444010',
            'status' => 'active',
        ]);

        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Eligible Beta');
        $this->actingAs($managerUser);

        $juzs = QuranJuz::query()
            ->whereIn('juz_number', [1, 2])
            ->orderBy('juz_number')
            ->get();

        QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $firstEnrollment->id,
            'juz_id' => $juzs->first()->id,
            'passed_on' => '2026-09-15',
            'status' => 'passed',
            'student_id' => $firstEnrollment->student_id,
        ]);

        QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $firstEnrollment->id,
            'juz_id' => $juzs->last()->id,
            'passed_on' => '2026-09-16',
            'status' => 'passed',
            'student_id' => $firstEnrollment->student_id,
        ]);

        QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $secondEnrollment->id,
            'juz_id' => $juzs->first()->id,
            'passed_on' => '2026-09-15',
            'status' => 'passed',
            'student_id' => $secondEnrollment->student_id,
        ]);

        $awqafType = QuranTestType::query()->where('code', 'awqaf')->firstOrFail();

        QuranTest::query()->create([
            'enrollment_id' => $secondEnrollment->id,
            'student_id' => $secondEnrollment->student_id,
            'teacher_id' => $secondEnrollment->group->teacher_id,
            'juz_id' => $juzs->first()->id,
            'quran_test_type_id' => $awqafType->id,
            'tested_on' => '2026-09-20',
            'score' => 90,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        Volt::test('quran-tests.index')
            ->call('openEligibleAwqafModal')
            ->assertSet('showEligibleAwqafModal', true)
            ->assertSee(trim($firstEnrollment->student->first_name.' '.$firstEnrollment->student->last_name))
            ->assertSee($firstEnrollment->student->parentProfile->father_name)
            ->assertSee($firstEnrollment->student->birth_date?->format('Y'))
            ->assertSee('2')
            ->assertSee('data-eligible-awqaf-table', false)
            ->assertSee('data-settings-record-table', false)
            ->assertDontSee(__('workflow.quran_tests.eligible_modal.summary', ['count' => 1]));
    }

    private function teacherContext(): array
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'username' => 'workbench-teacher',
            'phone' => '0998333000',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Workbench',
            'last_name' => 'Teacher',
            'phone' => '0998333001',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Workbench Teacher Group');

        $this->actingAs($teacherUser);

        return [$teacher, $enrollment];
    }

    private function managerContext(): Enrollment
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'workbench-manager',
            'phone' => '0998444000',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Manager',
            'last_name' => 'Teacher',
            'phone' => '0998444001',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Workbench Manager Group');

        $this->actingAs($manager);

        return $enrollment;
    }

    private function makeEnrollment(int $teacherId, string $groupName): Enrollment
    {
        $parent = ParentProfile::create([
            'father_name' => $groupName.' Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => $groupName,
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => $groupName.' Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacherId,
            'name' => $groupName,
            'capacity' => 12,
            'is_active' => true,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);
    }
}

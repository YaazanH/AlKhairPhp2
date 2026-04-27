<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\StudentPageAchievement;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StandaloneWorkflowPagesTest extends TestCase
{
    use RefreshDatabase;

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
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertHasNoErrors();

        $partialTest = QuranPartialTest::query()->firstOrFail();
        $part = $partialTest->parts()->where('part_number', 1)->firstOrFail();

        Volt::test('quran-partial-tests.show', ['partialTest' => $partialTest])
            ->call('openAttemptModal', $part->id)
            ->set('tested_on', '2026-09-10')
            ->set('score', '95')
            ->call('saveAttempt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_partial_test_attempts', [
            'attempt_no' => 1,
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

        Volt::test('quran-final-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertSet('showOpenTestWarningModal', true)
            ->assertSet('existingFinalTestSummary.juz_number', $juz->juz_number);
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
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('manual_point_type_id', $bonus->id)
            ->call('saveManual')
            ->assertHasNoErrors();

        $transaction = PointTransaction::query()->where('source_type', 'manual')->firstOrFail();

        Volt::test('points.index')
            ->call('editManual', $transaction->id)
            ->call('saveManual')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_transactions', [
            'id' => $transaction->id,
            'points' => 5,
            'notes' => null,
        ]);
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

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

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

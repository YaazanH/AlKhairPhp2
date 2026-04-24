<?php

namespace Tests\Feature;

use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QuranWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_attendance_creates_records_and_automatic_points(): void
    {
        [$group, $enrollment] = $this->workflowContext();
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        Volt::test('groups.attendance', ['group' => $group])
            ->set('attendance_date', '2026-09-02')
            ->set('selected_statuses.'.$enrollment->id, (string) $present->id)
            ->call('saveAttendance')
            ->assertHasNoErrors();

        $day = GroupAttendanceDay::query()->where('group_id', $group->id)->whereDate('attendance_date', '2026-09-02')->firstOrFail();

        $this->assertDatabaseHas('student_attendance_records', [
            'group_attendance_day_id' => $day->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $enrollment->id,
            'source_type' => 'student_attendance_record',
            'points' => 2,
        ]);

        $this->assertSame(2, $enrollment->fresh()->final_points_cached);
    }

    public function test_teacher_attendance_creates_a_whole_day_record(): void
    {
        [$group] = $this->workflowContext();
        $teacher = $group->teacher;
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        Volt::test('teachers.attendance')
            ->set('attendance_date', '2026-09-02')
            ->set('selected_statuses.'.$teacher->id, (string) $present->id)
            ->call('saveAttendance')
            ->assertHasNoErrors();

        $day = TeacherAttendanceDay::query()->whereDate('attendance_date', '2026-09-02')->firstOrFail();

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $teacher->id,
            'attendance_status_id' => $present->id,
        ]);
    }

    public function test_memorization_creates_lifetime_page_achievements_and_can_save_only_unique_pages_when_duplicates_exist(): void
    {
        [, $enrollment] = $this->workflowContext('teacher');

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->set('recorded_on', '2026-09-03')
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('entry_type', 'new')
            ->set('from_page', '5')
            ->set('to_page', '7')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $session = MemorizationSession::query()->firstOrFail();

        $this->assertSame(3, $session->pages_count);
        $this->assertSame(3, StudentPageAchievement::query()->where('student_id', $enrollment->student_id)->count());
        $this->assertSame(3, $enrollment->fresh()->memorized_pages_cached);

        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $enrollment->id,
            'source_type' => 'memorization_session',
            'source_id' => $session->id,
            'points' => 40,
        ]);

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->call('editSession', $session->id)
            ->set('to_page', '6')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $this->assertSame(2, $session->fresh()->pages_count);
        $this->assertSame(2, StudentPageAchievement::query()->where('student_id', $enrollment->student_id)->count());
        $this->assertSame(2, $enrollment->fresh()->memorized_pages_cached);

        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $enrollment->id,
            'source_type' => 'memorization_session',
            'source_id' => $session->id,
            'points' => 25,
            'voided_at' => null,
        ]);

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->set('recorded_on', '2026-09-04')
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('entry_type', 'new')
            ->set('from_page', '6')
            ->set('to_page', '8')
            ->call('saveMemorization')
            ->assertHasNoErrors()
            ->assertSet('showDuplicateModal', true)
            ->assertSet('duplicatePages', [6])
            ->assertSet('uniquePages', [7, 8])
            ->call('confirmDuplicateSave')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $enrollment->group->teacher_id,
            'from_page' => 7,
            'to_page' => 8,
            'pages_count' => 2,
        ]);

        $this->assertSame(4, StudentPageAchievement::query()->where('student_id', $enrollment->student_id)->count());
        $this->assertSame(4, $enrollment->fresh()->memorized_pages_cached);
    }

    public function test_memorization_point_tiers_are_calculated_per_day(): void
    {
        [, $enrollment] = $this->workflowContext('tiered-memorization');

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->set('recorded_on', '2026-09-07')
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('entry_type', 'new')
            ->set('from_page', '10')
            ->set('to_page', '10')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $this->assertSame(10, PointTransaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('source_type', 'memorization_session')
            ->whereNull('voided_at')
            ->sum('points'));

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->set('recorded_on', '2026-09-07')
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('entry_type', 'new')
            ->set('from_page', '11')
            ->set('to_page', '11')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $this->assertSame(2, MemorizationSession::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(2, StudentPageAchievement::query()->where('student_id', $enrollment->student_id)->count());
        $this->assertSame(1, PointTransaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('source_type', 'memorization_session')
            ->whereNull('voided_at')
            ->count());
        $this->assertSame(25, PointTransaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('source_type', 'memorization_session')
            ->whereNull('voided_at')
            ->sum('points'));
    }

    public function test_quran_test_progression_blocks_final_until_four_partials_pass(): void
    {
        [, $enrollment] = $this->workflowContext('teacher');
        $partial = QuranTestType::query()->where('code', 'partial')->firstOrFail();
        $final = QuranTestType::query()->where('code', 'final')->firstOrFail();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        Volt::test('enrollments.quran-tests', ['enrollment' => $enrollment])
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('juz_id', $juz->id)
            ->set('quran_test_type_id', $final->id)
            ->set('tested_on', '2026-09-05')
            ->set('status', 'passed')
            ->call('saveQuranTest')
            ->assertHasErrors(['quran_test_type_id']);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            QuranTest::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'teacher_id' => $enrollment->group->teacher_id,
                'juz_id' => $juz->id,
                'quran_test_type_id' => $partial->id,
                'tested_on' => '2026-09-0'.$attempt,
                'score' => 95,
                'status' => 'passed',
                'attempt_no' => $attempt,
            ]);
        }

        Volt::test('enrollments.quran-tests', ['enrollment' => $enrollment])
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('juz_id', $juz->id)
            ->set('quran_test_type_id', $final->id)
            ->set('tested_on', '2026-09-09')
            ->set('status', 'passed')
            ->call('saveQuranTest')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_tests', [
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $final->id,
            'attempt_no' => 1,
        ]);
    }

    public function test_point_ledger_allows_manual_entries_and_voiding(): void
    {
        [, $enrollment] = $this->workflowContext();
        $bonus = PointType::query()->create([
            'name' => 'Manual Reward',
            'code' => 'manual-reward',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        Volt::test('enrollments.points', ['enrollment' => $enrollment])
            ->set('manual_point_type_id', $bonus->id)
            ->call('saveManual')
            ->assertHasNoErrors();

        $transaction = PointTransaction::query()->where('source_type', 'manual')->firstOrFail();
        $this->assertSame(5, $enrollment->fresh()->final_points_cached);

        Volt::test('enrollments.points', ['enrollment' => $enrollment])
            ->call('editManual', $transaction->id)
            ->call('saveManual')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_transactions', [
            'id' => $transaction->id,
            'points' => 5,
            'notes' => null,
        ]);
        $this->assertSame(5, $enrollment->fresh()->final_points_cached);

        Volt::test('enrollments.points', ['enrollment' => $enrollment])
            ->call('void', $transaction->id);

        $this->assertNotNull($transaction->fresh()->voided_at);
        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
    }

    public function test_teacher_workflow_access_is_restricted_to_assigned_groups(): void
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'username' => 'assigned-teacher',
            'phone' => '0777000001',
        ]);
        $teacherUser->assignRole('teacher');

        $assignedTeacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0991000001',
            'status' => 'active',
        ]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0991000002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Teacher Access Course',
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Teacher Access Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Teacher',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

        $assignedGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $assignedTeacher->id,
            'name' => 'Assigned Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $assignedEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $assignedGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser);

        $this->get(route('groups.attendance', $assignedGroup, absolute: false))->assertOk();
        $this->get(route('enrollments.memorization', $assignedEnrollment, absolute: false))->assertOk();

        $this->get(route('groups.attendance', $otherGroup, absolute: false))->assertForbidden();
        $this->get(route('enrollments.memorization', $otherEnrollment, absolute: false))->assertForbidden();
    }

    private function workflowContext(string $actingRole = 'manager'): array
    {
        $this->seed();

        $parent = ParentProfile::create([
            'father_name' => 'Workflow Parent',
            'father_phone' => '0944000000',
        ]);

        $teacherAttributes = [
            'first_name' => 'Workflow',
            'last_name' => 'Teacher',
            'phone' => '0944000001',
            'status' => 'active',
            'is_helping' => true,
        ];

        if ($actingRole === 'teacher') {
            $teacherUser = User::factory()->create([
                'username' => 'workflow-teacher',
                'phone' => '0666000001',
            ]);
            $teacherUser->assignRole('teacher');

            $teacherAttributes['user_id'] = $teacherUser->id;
        }

        $teacher = Teacher::create($teacherAttributes);

        if ($actingRole === 'teacher') {
            $this->actingAs($teacher->user);
        } else {
            $manager = User::factory()->create([
                'username' => 'workflow-manager',
                'phone' => '0666000001',
            ]);
            $manager->assignRole('manager');
            $this->actingAs($manager);
        }

        $course = Course::create([
            'name' => 'Workflow Course',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Workflow',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Workflow Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        return [$group, $enrollment];
    }
}

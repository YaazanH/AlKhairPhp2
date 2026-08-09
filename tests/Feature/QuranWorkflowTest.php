<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestPart;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\User;
use App\Services\MemorizationService;
use App\Services\PointLedgerService;
use App\Services\QuranProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $attendanceDate = '2026-09-02';

        $group->schedules()->create([
            'day_of_week' => Carbon::parse($attendanceDate)->dayOfWeek,
            'starts_at' => '15:00',
            'ends_at' => '16:00',
            'is_active' => true,
        ]);

        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->set('attendance_date', $attendanceDate)
            ->set('day_status', 'open')
            ->set('default_attendance_status_id', (string) $present->id)
            ->call('saveDay')
            ->assertHasNoErrors();

        $day = TeacherAttendanceDay::query()->whereDate('attendance_date', $attendanceDate)->firstOrFail();

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $teacher->id,
            'attendance_status_id' => $present->id,
        ]);
    }

    public function test_teacher_attendance_preloads_scheduled_teachers_and_allows_manual_additions(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'teacher-attendance-manager',
            'phone' => '0666000100',
        ]);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $scheduledTeacher = Teacher::create([
            'first_name' => 'Scheduled',
            'last_name' => 'Teacher',
            'phone' => '0944000100',
            'status' => 'active',
            'is_helping' => false,
        ]);

        $extraTeacher = Teacher::create([
            'first_name' => 'Manual',
            'last_name' => 'Teacher',
            'phone' => '0944000101',
            'status' => 'active',
            'is_helping' => false,
        ]);

        $course = Course::create([
            'name' => 'Teacher Attendance Course',
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'teacher_id' => $scheduledTeacher->id,
            'name' => 'Teacher Attendance Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $attendanceDate = '2026-09-03';

        $group->schedules()->create([
            'day_of_week' => Carbon::parse($attendanceDate)->dayOfWeek,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
            'is_active' => true,
        ]);

        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->set('attendance_date', $attendanceDate)
            ->set('day_status', 'open')
            ->set('default_attendance_status_id', (string) $present->id)
            ->call('saveDay')
            ->assertHasNoErrors();

        $day = TeacherAttendanceDay::query()->whereDate('attendance_date', $attendanceDate)->firstOrFail();

        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])
            ->assertSee($scheduledTeacher->first_name.' '.$scheduledTeacher->last_name)
            ->assertSee(__('workflow.teacher_attendance.day_details.stats.scheduled'))
            ->call('openManualTeacherModal')
            ->set('manual_teacher_id', (string) $extraTeacher->id)
            ->call('addManualTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $scheduledTeacher->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $extraTeacher->id,
            'attendance_status_id' => $present->id,
        ]);

        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])
            ->call('removeTeacher', $scheduledTeacher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teacher_attendance_exclusions', [
            'teacher_id' => $scheduledTeacher->id,
            'excluded_by' => $manager->id,
        ]);
        $this->assertDatabaseMissing('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $scheduledTeacher->id,
        ]);

        $nextDate = Carbon::parse($attendanceDate)->addWeek()->toDateString();
        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->set('attendance_date', $nextDate)
            ->set('day_status', 'open')
            ->set('default_attendance_status_id', (string) $present->id)
            ->call('saveDay')
            ->assertHasNoErrors();

        $nextDay = TeacherAttendanceDay::query()->whereDate('attendance_date', $nextDate)->firstOrFail();
        $this->assertDatabaseMissing('teacher_attendance_records', [
            'teacher_attendance_day_id' => $nextDay->id,
            'teacher_id' => $scheduledTeacher->id,
        ]);

        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $nextDay])
            ->call('openManualTeacherModal')
            ->set('manual_teacher_id', (string) $scheduledTeacher->id)
            ->call('addManualTeacher')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('teacher_attendance_exclusions', ['teacher_id' => $scheduledTeacher->id]);
        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $nextDay->id,
            'teacher_id' => $scheduledTeacher->id,
        ]);

        $laterDate = Carbon::parse($attendanceDate)->addWeeks(2)->toDateString();
        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->set('attendance_date', $laterDate)
            ->set('day_status', 'open')
            ->set('default_attendance_status_id', (string) $present->id)
            ->call('saveDay')
            ->assertHasNoErrors();

        $laterDay = TeacherAttendanceDay::query()->whereDate('attendance_date', $laterDate)->firstOrFail();
        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $laterDay->id,
            'teacher_id' => $scheduledTeacher->id,
        ]);
    }

    public function test_teacher_attendance_preloads_unassigned_helping_teachers(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'teacher-attendance-helping-manager',
            'phone' => '0666000102',
        ]);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $scheduledTeacher = Teacher::create([
            'first_name' => 'Scheduled',
            'last_name' => 'Primary',
            'phone' => '0944000102',
            'status' => 'active',
            'is_helping' => false,
        ]);

        $unassignedHelpingTeacher = Teacher::create([
            'first_name' => 'Helping',
            'last_name' => 'Free',
            'phone' => '0944000103',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $assignedHelpingTeacher = Teacher::create([
            'first_name' => 'Helping',
            'last_name' => 'Assigned',
            'phone' => '0944000104',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $course = Course::create([
            'name' => 'Teacher Attendance Helper Course',
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'teacher_id' => $scheduledTeacher->id,
            'assistant_teacher_id' => $assignedHelpingTeacher->id,
            'name' => 'Teacher Attendance Helper Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $attendanceDate = '2026-09-04';

        $group->schedules()->create([
            'day_of_week' => Carbon::parse($attendanceDate)->dayOfWeek,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
            'is_active' => true,
        ]);

        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->set('attendance_date', $attendanceDate)
            ->set('day_status', 'open')
            ->set('default_attendance_status_id', (string) $present->id)
            ->call('saveDay')
            ->assertHasNoErrors();

        $day = TeacherAttendanceDay::query()->whereDate('attendance_date', $attendanceDate)->firstOrFail();

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $scheduledTeacher->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $unassignedHelpingTeacher->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $assignedHelpingTeacher->id,
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

        $unchangedSessionPageId = $session->pages()->where('page_no', 6)->value('id');
        $unchangedAchievementId = StudentPageAchievement::query()->where('student_id', $enrollment->student_id)->where('page_no', 6)->value('id');

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->call('editSession', $session->id)
            ->set('to_page', '6')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $this->assertSame(2, $session->fresh()->pages_count);
        $this->assertSame($unchangedSessionPageId, $session->pages()->where('page_no', 6)->value('id'));
        $this->assertSame($unchangedAchievementId, StudentPageAchievement::query()->where('student_id', $enrollment->student_id)->where('page_no', 6)->value('id'));
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

    public function test_juz_memorized_at_another_mosque_is_treated_as_duplicate_and_cannot_earn_points(): void
    {
        [, $enrollment] = $this->workflowContext('teacher');
        $juz = QuranJuz::query()->orderBy('juz_number')->firstOrFail();
        $candidatePages = [$juz->from_page, min($juz->from_page + 1, $juz->to_page)];

        app(MemorizationService::class)->saveSession($enrollment, [
            'recorded_on' => '2026-09-12',
            'teacher_id' => $enrollment->group->teacher_id,
            'entry_type' => 'new',
            'from_page' => $candidatePages[0],
            'to_page' => $candidatePages[1],
            'notes' => null,
        ]);

        $this->assertDatabaseCount('student_page_achievements', count($candidatePages));
        $this->assertDatabaseHas('point_transactions', ['voided_at' => null]);

        $enrollment->student->externalMemorizedJuzs()->sync([$juz->id]);
        app(MemorizationService::class)->rebuildStudentAchievementsAndPoints($enrollment->student);

        $duplicates = app(MemorizationService::class)->findDuplicatePages(
            $enrollment,
            $candidatePages,
            'new',
        );

        $this->assertSame($candidatePages, $duplicates);
        $this->assertDatabaseCount('student_page_achievements', 0);
        $this->assertDatabaseMissing('point_transactions', ['voided_at' => null]);
    }

    public function test_adding_a_new_memorization_day_does_not_recalculate_historical_points(): void
    {
        [, $enrollment] = $this->workflowContext('tiered-memorization');

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->set('recorded_on', '2026-09-08')
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('entry_type', 'new')
            ->set('from_page', '20')
            ->set('to_page', '20')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $historicalTransaction = PointTransaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('source_type', 'memorization_session')
            ->whereNull('voided_at')
            ->firstOrFail();

        Volt::test('enrollments.memorization', ['enrollment' => $enrollment])
            ->set('recorded_on', '2026-09-09')
            ->set('teacher_id', $enrollment->group->teacher_id)
            ->set('entry_type', 'new')
            ->set('from_page', '21')
            ->set('to_page', '21')
            ->call('saveMemorization')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_transactions', [
            'id' => $historicalTransaction->id,
            'points' => 10,
            'voided_at' => null,
        ]);
        $this->assertSame(2, PointTransaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('source_type', 'memorization_session')
            ->whereNull('voided_at')
            ->count());
        $this->assertSame(20, $enrollment->fresh()->final_points_cached);
    }

    public function test_final_test_progression_requires_a_passed_partial_cycle_and_saves_attempt_history(): void
    {
        [, $enrollment] = $this->workflowContext('teacher');
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        Volt::test('quran-final-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $enrollment->id)
            ->set('juz_id', $juz->id)
            ->call('save')
            ->assertHasErrors(['juz_id']);

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'passed_on' => '2026-09-08',
            'status' => 'passed',
            'student_id' => $enrollment->student_id,
        ]);

        foreach (range(1, 4) as $partNumber) {
            QuranPartialTestPart::query()->create([
                'part_number' => $partNumber,
                'passed_on' => '2026-09-0'.(4 + $partNumber),
                'quran_partial_test_id' => $partialTest->id,
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

        $newCurrentJuz = QuranJuz::query()->whereKeyNot($juz->id)->orderBy('juz_number')->firstOrFail();

        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->set('tested_on', '2026-09-09')
            ->set('score', '94')
            ->call('saveAttempt')
            ->assertHasNoErrors()
            ->assertSet('showCurrentJuzModal', true)
            ->set('newCurrentJuzId', $newCurrentJuz->id)
            ->call('saveCurrentJuz')
            ->assertHasNoErrors()
            ->assertSet('showCurrentJuzModal', false);

        $this->assertDatabaseHas('quran_final_tests', [
            'id' => $finalTest->id,
            'status' => 'passed',
        ]);

        $this->assertDatabaseHas('quran_final_test_attempts', [
            'quran_final_test_id' => $finalTest->id,
            'attempt_no' => 1,
            'status' => 'passed',
            'teacher_id' => $enrollment->group->teacher_id,
        ]);
        $this->assertSame($newCurrentJuz->id, $enrollment->student->fresh()->quran_current_juz_id);
    }

    public function test_awqaf_test_progression_blocks_until_final_cycle_passes(): void
    {
        [, $enrollment] = $this->workflowContext('teacher');
        $awqaf = QuranTestType::query()->where('code', 'awqaf')->firstOrFail();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        Volt::test('enrollments.quran-tests', ['enrollment' => $enrollment])
            ->set('juz_id', $juz->id)
            ->set('tested_on', '2026-09-10')
            ->set('status', 'passed')
            ->call('saveQuranTest')
            ->assertHasErrors(['juz_id']);

        $finalTest = QuranFinalTest::query()->create([
            'created_by' => auth()->id(),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'passed_on' => '2026-09-09',
            'status' => 'passed',
            'student_id' => $enrollment->student_id,
        ]);

        $finalTest->attempts()->create([
            'attempt_no' => 1,
            'score' => 92,
            'status' => 'passed',
            'teacher_id' => $enrollment->group->teacher_id,
            'tested_on' => '2026-09-09',
        ]);

        Volt::test('enrollments.quran-tests', ['enrollment' => $enrollment])
            ->set('juz_id', $juz->id)
            ->set('tested_on', '2026-09-10')
            ->set('status', 'passed')
            ->call('saveQuranTest')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_tests', [
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $awqaf->id,
            'attempt_no' => 1,
            'teacher_id' => $enrollment->group->teacher_id,
        ]);

        $this->assertFalse(
            app(QuranProgressionService::class)
                ->eligibleAwqafJuzIdsForStudent($enrollment->student_id)
                ->contains($juz->id)
        );

        Volt::test('enrollments.quran-tests', ['enrollment' => $enrollment])
            ->set('juz_id', $juz->id)
            ->set('tested_on', '2026-09-11')
            ->set('status', 'passed')
            ->call('saveQuranTest')
            ->assertHasErrors(['juz_id']);
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

    public function test_points_follow_course_activation_state(): void
    {
        [, $enrollment] = $this->workflowContext();

        $transaction = PointTransaction::query()->create([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'point_type_id' => PointType::query()->create([
                'name' => 'Course Toggle Reward',
                'code' => 'course-toggle-reward',
                'category' => 'manual',
                'default_points' => 5,
                'allow_manual_entry' => true,
                'allow_negative' => false,
                'is_active' => true,
            ])->id,
            'policy_id' => null,
            'source_type' => 'manual',
            'source_id' => null,
            'points' => 5,
            'entered_by' => auth()->id(),
            'entered_at' => now(),
            'notes' => 'Course activation test',
        ]);

        app(PointLedgerService::class)->syncEnrollmentCaches($enrollment->fresh(['student']));

        $this->assertSame(5, $enrollment->fresh()->final_points_cached);
        $this->assertTrue($transaction->fresh()->isEffectivelyActive());

        $course = $enrollment->group->course;
        $course->update(['is_active' => false]);

        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
        $this->assertFalse($transaction->fresh()->isEffectivelyActive());

        $course->update(['is_active' => true]);

        $this->assertSame(5, $enrollment->fresh()->final_points_cached);
        $this->assertTrue($transaction->fresh()->isEffectivelyActive());
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

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

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

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

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

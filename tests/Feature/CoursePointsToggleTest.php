<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointPolicy;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PointLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CoursePointsToggleTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $points = false): array
    {
        $this->seed();
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);
        $course = Course::create(['academic_year_id' => AcademicYear::where('is_active', true)->value('id'), 'name' => 'Reversible points', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'is_active' => true, 'awards_points' => $points]);
        $teacher = Teacher::create(['first_name' => 'Test', 'last_name' => 'Teacher', 'phone' => '0999111222', 'status' => 'active']);
        $group = Group::create(['name' => 'Test group', 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'academic_year_id' => $course->academic_year_id, 'is_active' => true]);
        $parent = ParentProfile::create(['father_name' => 'Test parent']);
        $student = Student::create(['parent_id' => $parent->id, 'first_name' => 'Test', 'last_name' => 'Student', 'status' => 'active', 'birth_date' => '2014-01-01']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'status' => 'active', 'enrolled_at' => '2026-09-01']);

        return [$course, $enrollment, $teacher];
    }

    public function test_toggling_restores_exact_existing_awards_and_keeps_voided_awards_voided(): void
    {
        [$course, $enrollment] = $this->context(true);
        $ledger = app(PointLedgerService::class);
        $type = PointType::firstOrFail();
        $award = $ledger->recordManualPoints($enrollment, $type, 25, 'Preserved award');
        $voided = $ledger->recordManualPoints($enrollment, $type, 7);
        $voided->update(['voided_at' => now()]);
        $currentJuz = QuranJuz::where('number', 20)->value('id');
        $enrollment->student->update(['quran_current_juz_id' => $currentJuz]);
        for ($i = 0; $i < 2; $i++) {
            $course->update(['awards_points' => false]);
            $this->assertSame(0, $enrollment->fresh()->final_points_cached);
            Volt::test('points.index')->set('stateFilter', 'all')->assertDontSee('Preserved award');
            $course->update(['awards_points' => true]);
            $this->assertSame(25, $enrollment->fresh()->final_points_cached);
        }
        $this->assertSame(2, PointTransaction::count());
        $this->assertNull($award->fresh()->voided_at);
        $this->assertNotNull($voided->fresh()->voided_at);
        $this->assertSame($currentJuz, $enrollment->student->fresh()->quran_current_juz_id);
    }

    public function test_automatic_activity_while_disabled_is_retained_but_not_counted_until_enabled(): void
    {
        [$course, $enrollment] = $this->context();
        $ledger = app(PointLedgerService::class);
        $transaction = $ledger->recordAutomaticPoints($enrollment, 'test_activity', 77, PointType::firstOrFail(), null, 12);
        $ledger->syncEnrollmentCaches($enrollment);
        $this->assertNotNull($transaction);
        $this->assertFalse($transaction->isEffectivelyActive());
        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
        $course->update(['awards_points' => true]);
        $this->assertSame(12, $enrollment->fresh()->final_points_cached);
        $this->assertSame(1, PointTransaction::count());
    }

    public function test_enabling_backfills_historical_attendance_and_memorization_using_event_dates_once(): void
    {
        [$course, $enrollment, $teacher] = $this->context();
        $status = AttendanceStatus::where('code', 'present')->firstOrFail();
        $day = GroupAttendanceDay::create(['group_id' => $enrollment->group_id, 'attendance_date' => '2026-09-10', 'status' => 'closed']);
        $record = StudentAttendanceRecord::create(['group_attendance_day_id' => $day->id, 'enrollment_id' => $enrollment->id, 'attendance_status_id' => $status->id]);
        $session = MemorizationSession::create(['enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'teacher_id' => $teacher->id, 'recorded_on' => '2026-09-10', 'entry_type' => 'new', 'from_page' => 1, 'to_page' => 1, 'pages_count' => 1]);
        StudentPageAchievement::create(['student_id' => $enrollment->student_id, 'page_no' => 1, 'first_enrollment_id' => $enrollment->id, 'first_session_id' => $session->id, 'first_recorded_on' => '2026-09-10']);
        // A current multiplier must not accidentally multiply an old reward.
        $this->travelTo(now()->setDate(2026, 10, 5));
        AppSetting::storeValue('points', 'automatic_multiplier_from', '2026-10-01');
        AppSetting::storeValue('points', 'automatic_multiplier_until', '2026-10-31');
        $course->update(['awards_points' => true]);
        $this->assertSame(12, $enrollment->fresh()->final_points_cached);
        $this->assertDatabaseHas('point_transactions', ['source_type' => 'memorization_session', 'source_id' => $session->id, 'points' => 10, 'entered_at' => '2026-09-10 00:00:00']);
        $this->assertDatabaseHas('point_transactions', ['source_type' => 'student_attendance_record', 'source_id' => $record->id, 'points' => 2]);
        $course->update(['awards_points' => false]);
        $course->update(['awards_points' => true]);
        $this->assertSame(2, PointTransaction::count());
        $this->assertSame(12, $enrollment->fresh()->final_points_cached);
    }

    public function test_course_manager_saves_details_and_calendar_together_without_points(): void
    {
        [$course] = $this->context();
        Volt::test('courses.index')->call('edit', $course->id)->assertSet('showFormModal', true)
            ->set('name', 'Updated course')->set('ends_on', '2027-01-31')
            ->set('showCalendarModal', true)->set('calendarDate', '2027-01-10')->set('calendarName', 'New event')
            ->call('save')->assertHasNoErrors()->assertSet('showFormModal', false);
        $this->assertSame('Updated course', $course->fresh()->name);
        $this->assertSame('2027-01-10', $course->calendarEntries()->sole()->date->toDateString());
        $this->assertFalse($course->fresh()->awards_points);
    }

    public function test_invalid_calendar_rolls_back_course_changes_and_cancelling_discards_both_tabs(): void
    {
        [$course] = $this->context();
        $editor = Volt::test('courses.index')->call('edit', $course->id)->set('name', 'Must not persist')
            ->set('awards_points', true)->set('calendarDate', '2027-02-01')->set('calendarName', 'Outside dates')
            ->call('save')->assertHasErrors('calendarDate')->assertSet('showFormModal', true);
        $this->assertSame('Reversible points', $course->fresh()->name);
        $this->assertFalse($course->fresh()->awards_points);
        $editor->call('cancel')->call('edit', $course->id)->assertSet('calendarName', '')->assertSet('name', 'Reversible points');
        $this->assertSame(0, $course->calendarEntries()->count());
    }

    public function test_enabling_restores_assessment_partial_final_and_awqaf_rewards_once(): void
    {
        [$course, $enrollment, $teacher] = $this->context();
        $pointType = PointType::firstOrFail();
        foreach ([['quran_partial_test_part', 'part_passed'], ['quran_partial_test', 'partial_passed'], ['quran_final_test', 'final_passed'], ['quran_test', 'awqaf_passed']] as [$source, $trigger]) {
            PointPolicy::create(['name' => $source, 'source_type' => $source, 'trigger_key' => $trigger, 'point_type_id' => $pointType->id, 'points' => 9, 'is_active' => true, 'period_type' => 'date_window', 'active_from' => '2026-09-01', 'active_until' => '2026-09-30', 'priority' => 999]);
        }
        $base = ['enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'juz_id' => QuranJuz::firstOrFail()->id, 'passed_on' => '2026-09-10', 'status' => 'passed'];
        $partial = QuranPartialTest::create($base);
        $part = $partial->parts()->create(['part_number' => 1, 'status' => 'passed', 'passed_on' => '2026-09-10']);
        $part->attempts()->create(['teacher_id' => $teacher->id, 'tested_on' => '2026-09-10', 'status' => 'passed', 'attempt_no' => 1, 'mistake_count' => 0]);
        $final = QuranFinalTest::create($base);
        $final->attempts()->create(['teacher_id' => $teacher->id, 'tested_on' => '2026-09-10', 'status' => 'passed', 'attempt_no' => 1, 'score' => 95]);
        QuranTest::create(array_merge($base, ['teacher_id' => $teacher->id, 'tested_on' => '2026-09-10', 'quran_test_type_id' => QuranTestType::where('code', 'awqaf')->firstOrFail()->id, 'score' => 95]));
        $type = AssessmentType::create(['name' => 'Historical assessment', 'code' => 'historical-assessment', 'is_scored' => true]);
        AssessmentScoreBand::create(['assessment_type_id' => $type->id, 'name' => 'Passed', 'from_mark' => 0, 'to_mark' => 100, 'point_type_id' => $pointType->id, 'points' => 8, 'is_active' => true]);
        $assessment = Assessment::create(['group_id' => $enrollment->group_id, 'assessment_type_id' => $type->id, 'title' => 'Old assessment', 'scheduled_at' => '2026-09-10']);
        AssessmentResult::create(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'score' => 95, 'status' => 'passed']);
        $this->travelTo(now()->setDate(2026, 10, 5));
        $course->update(['awards_points' => true]);
        $this->assertSame(44, $enrollment->fresh()->final_points_cached);
        $this->assertSame(5, PointTransaction::count());
        $this->assertSame(['assessment_result', 'quran_final_test', 'quran_partial_test', 'quran_partial_test_part', 'quran_test'], PointTransaction::orderBy('source_type')->pluck('source_type')->all());
        $course->update(['awards_points' => false]);
        $course->update(['awards_points' => true]);
        $this->assertSame(44, $enrollment->fresh()->final_points_cached);
        $this->assertSame(5, PointTransaction::count());
    }

    public function test_active_course_calendar_remains_available_in_an_inactive_academic_year(): void
    {
        [$course] = $this->context();
        $year = AcademicYear::create(['name' => 'Older year', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => false]);
        $course->update(['academic_year_id' => $year->id]);
        Volt::test('courses.index')->call('openCourseCalendar', $course->id)
            ->set('calendarDate', '2026-10-10')->set('calendarName', 'An event')
            ->call('saveCourseCalendar')->assertHasNoErrors();
        $this->assertSame(1, $course->calendarEntries()->count());
    }
}

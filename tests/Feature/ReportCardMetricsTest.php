<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Services\CourseEndService;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_card_metrics_follow_attendance_days_and_named_point_awards(): void
    {
        $this->seed();

        $parent = ParentProfile::create(['father_name' => 'Report Parent']);
        $teacher = Teacher::create(['first_name' => 'Report', 'last_name' => 'Teacher', 'phone' => '0944000999', 'status' => 'active']);
        $course = Course::create(['name' => 'Report Card Course', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'is_active' => true, 'awards_points' => true]);
        $year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $group = Group::create(['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'name' => 'Report Card Group', 'capacity' => 20, 'is_active' => true]);
        $student = Student::create(['parent_id' => $parent->id, 'first_name' => 'Report', 'last_name' => 'Student', 'birth_date' => '2014-01-01', 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);

        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();
        foreach (['2026-09-01', '2026-09-03', '2026-09-05'] as $index => $date) {
            $day = GroupAttendanceDay::create(['group_id' => $group->id, 'attendance_date' => $date, 'status' => 'closed']);
            if ($index === 0) {
                StudentAttendanceRecord::create(['group_attendance_day_id' => $day->id, 'enrollment_id' => $enrollment->id, 'attendance_status_id' => $present->id]);
            }
        }

        $session = MemorizationSession::create(['enrollment_id' => $enrollment->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'recorded_on' => '2026-09-01', 'entry_type' => 'new', 'from_page' => 1, 'to_page' => 6, 'pages_count' => 6]);
        foreach (range(1, 6) as $page) MemorizationSessionPage::create(['memorization_session_id' => $session->id, 'page_no' => $page]);

        $juzs = QuranJuz::query()->orderBy('juz_number')->take(2)->get();
        foreach ($juzs as $index => $juz) {
            $test = QuranFinalTest::create(['enrollment_id' => $enrollment->id, 'student_id' => $student->id, 'juz_id' => $juz->id, 'status' => $index === 0 ? 'passed' : 'in_progress', 'passed_on' => $index === 0 ? '2026-09-10' : null]);
            QuranFinalTestAttempt::create(['quran_final_test_id' => $test->id, 'teacher_id' => $teacher->id, 'tested_on' => '2026-09-10', 'score' => $index === 0 ? 90 : 40, 'status' => $index === 0 ? 'passed' : 'failed', 'attempt_no' => 1]);
        }

        $quizType = AssessmentType::query()->where('code', 'quiz')->firstOrFail();
        $finalType = AssessmentType::create(['name' => 'Final Exam', 'code' => 'final_exam', 'is_scored' => true, 'is_active' => true]);
        foreach ([[$quizType, 'Worship assessment', 80], [$finalType, 'Final exam', 90]] as [$type, $title, $score]) {
            $assessment = Assessment::create(['group_id' => $group->id, 'assessment_type_id' => $type->id, 'title' => $title, 'total_mark' => 100, 'pass_mark' => 60, 'is_active' => true]);
            AssessmentResult::create(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'score' => $score, 'status' => 'passed', 'attempt_no' => 1]);
        }

        foreach ([['شيك ٢٥', 25], ['شيك ٥٠', 50], ['فارس قرآني', 25]] as [$name, $points]) {
            $type = PointType::create(['name' => $name, 'code' => 'award-'.PointType::query()->count(), 'category' => 'manual', 'default_points' => $points, 'allow_manual_entry' => true, 'allow_negative' => false, 'is_active' => true]);
            PointTransaction::create(['student_id' => $student->id, 'enrollment_id' => $enrollment->id, 'point_type_id' => $type->id, 'source_type' => 'manual', 'points' => $points, 'entered_at' => '2026-09-10 10:00:00']);
        }

        $row = app(CourseEndService::class)->studentRows($course)->firstWhere('enrollment_id', $enrollment->id);

        $this->assertSame(3, $row['attendance_days_created']);
        $this->assertSame(1, $row['days_attended']);
        $this->assertEquals(33.33, $row['attendance_average']);
        $this->assertSame(6, $row['memorized_pages']);
        $this->assertEquals(2.0, $row['daily_memorization_average']);
        $this->assertEquals(6.0, $row['weekly_memorization_average']);
        $this->assertSame(1, $row['final_tests']);
        $this->assertEquals(80.0, $row['assessment_average']);
        $this->assertEquals(90.0, $row['final_score']);
        $this->assertSame(2, $row['cheques_count']);
        $this->assertSame(1, $row['leaderboard_count']);
        $this->assertSame(200, $row['points_after']);

        $ineligibleStudent = Student::create(['parent_id' => $parent->id, 'first_name' => 'No', 'last_name' => 'Passed Test', 'birth_date' => '2014-02-01', 'status' => 'active']);
        $ineligibleEnrollment = Enrollment::create(['student_id' => $ineligibleStudent->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        $registry = app(PrintTemplateFieldRegistry::class);
        $eligibleIds = collect($registry->optionsFor('course_student'))->pluck('id');

        $this->assertTrue($eligibleIds->contains($enrollment->id));
        $this->assertFalse($eligibleIds->contains($ineligibleEnrollment->id));

        $enrollment->setAttribute('report_card_special_note', 'Excellent progress');
        $this->assertSame('Excellent progress', $registry->resolve(['course_student' => $enrollment], 'course_student', 'special_note'));
    }
}

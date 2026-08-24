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
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseEndService;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_course_report_flow_creates_a_preconfigured_exclusive_report_card_template(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $course = Course::create([
            'name' => 'Template Defaults Course',
            'is_active' => true,
        ]);
        $createTemplateUrl = route('print-templates.templates.create', [
            'course_report' => 1,
            'course_id' => $course->id,
        ]);
        $reportCardSetupUrl = route('courses.end.report-cards.create', $course);

        $this->actingAs($manager)
            ->get(route('courses.end', $course))
            ->assertOk()
            ->assertSee($reportCardSetupUrl, false)
            ->assertSee('course-end-final-tests-dual', false)
            ->assertSee('width: 5rem;', false)
            ->assertSee('.course-end-final-tests-table .course-end-final-tests-spacer { width: 8%; padding: 0; }', false)
            ->assertSee('course-end-final-tests-spacer', false);

        $this->actingAs($manager)
            ->get($reportCardSetupUrl)
            ->assertOk()
            ->assertViewHas('emptyStateCreateUrl', $createTemplateUrl);

        $this->actingAs($manager)
            ->get($createTemplateUrl)
            ->assertOk()
            ->assertViewHas('template', fn (PrintTemplate $template) => $template->is_report_card
                && ! $template->is_student_card
                && $template->data_sources === [[
                    'key' => 'course_student',
                    'entity' => 'course_student',
                    'mode' => 'multiple',
                ]])
            ->assertSee('data-template-report-card', false)
            ->assertSee('data-source-multiple-records', false);

        $this->actingAs($manager)
            ->from($createTemplateUrl)
            ->post(route('print-templates.templates.store'), [
                'name' => 'Invalid Mixed Card',
                'width_mm' => 85.6,
                'height_mm' => 53.98,
                'data_sources_json' => json_encode([
                    ['entity' => 'course_student', 'mode' => 'multiple'],
                ]),
                'layout_json' => json_encode([]),
                'is_active' => '1',
                'is_student_card' => '1',
                'is_report_card' => '1',
            ])
            ->assertRedirect($createTemplateUrl)
            ->assertSessionHasErrors('is_report_card');
    }

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
        $this->assertSame('very_good', app(CourseEndService::class)->finalTestRows($course)->first()['grade']);
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

    public function test_report_card_notes_are_course_scoped_and_persisted_from_the_dedicated_preview(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $parent = ParentProfile::create(['father_name' => 'Preview Parent']);
        $teacher = Teacher::create(['first_name' => 'Preview', 'last_name' => 'Teacher', 'phone' => '0944000888', 'status' => 'active']);
        $year = AcademicYear::create([
            'name' => '2027/2028',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-06-30',
            'is_current' => true,
            'is_active' => true,
        ]);
        $course = Course::create([
            'name' => 'Dedicated Report Preview Course',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-06-30',
            'is_active' => true,
        ]);
        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Dedicated Preview Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Dedicated',
            'last_name' => 'Student',
            'birth_date' => '2015-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2027-09-01',
            'status' => 'active',
            'report_card_special_note' => 'Previously saved course note',
        ]);
        $juz = QuranJuz::create(['juz_number' => 1, 'from_page' => 1, 'to_page' => 21]);
        QuranFinalTest::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'passed',
            'passed_on' => '2027-10-01',
            'created_by' => $manager->id,
        ]);

        $template = PrintTemplate::create([
            'name' => 'Dedicated Report Card',
            'width_mm' => 190,
            'height_mm' => 277,
            'paper_size' => 'a4',
            'orientation' => 'portrait',
            'data_sources' => [
                ['entity' => 'course_student', 'mode' => 'multiple'],
            ],
            'layout_json' => [[
                'type' => 'dynamic_text',
                'source' => 'course_student',
                'field' => 'special_note',
                'x' => 10,
                'y' => 10,
                'width' => 100,
                'height' => 12,
                'z_index' => 1,
                'styling' => [
                    'font_size' => 4,
                    'font_weight' => '400',
                    'color' => '#102316',
                    'text_align' => 'right',
                ],
            ]],
            'is_active' => true,
            'is_report_card' => true,
        ]);

        $this->actingAs($manager)
            ->get(route('print-templates.print.create', ['template' => $template->id]))
            ->assertNotFound();

        $reportCardSetupUrl = route('courses.end.report-cards.create', $course);
        $noteSaveUrl = route('courses.end.report-cards.notes.update', [
            'course' => $course,
            'enrollment' => $enrollment,
        ]);

        $this->actingAs($manager)
            ->get($reportCardSetupUrl)
            ->assertOk()
            ->assertSee('Previously saved course note')
            ->assertSee('data-report-card-note', false)
            ->assertSee($noteSaveUrl, false)
            ->assertDontSee('data-source-search="course_student"', false);

        $this->actingAs($manager)
            ->patchJson($noteSaveUrl, [
                'special_note' => 'Saved without opening preview',
            ])
            ->assertOk()
            ->assertJson([
                'saved' => true,
                'special_note' => 'Saved without opening preview',
            ]);

        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'report_card_special_note' => 'Saved without opening preview',
        ]);

        $response = $this->actingAs($manager)->post(route('courses.end.report-cards.preview', $course), [
            'template_id' => $template->id,
            'sources' => [
                'course_student' => ['multiple' => [$enrollment->id]],
            ],
            'special_notes' => [
                $enrollment->id => 'Automatically saved course note',
            ],
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
            'copy_count' => 1,
        ]);

        $response
            ->assertOk()
            ->assertViewIs('courses.report-cards.preview')
            ->assertSee('Automatically saved course note')
            ->assertSee($reportCardSetupUrl, false)
            ->assertDontSee(__('print_templates.print.preview.subtitle'))
            ->assertDontSee(__('print_templates.print.warnings.unused_space'));

        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'report_card_special_note' => 'Automatically saved course note',
        ]);
    }
}

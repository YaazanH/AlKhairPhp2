<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupCurriculumLessonProgress;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'en']);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response
            ->assertOk()
            ->assertSee('Dashboard Setup')
            ->assertSee('Assign a role');
    }

    public function test_dashboard_context_falls_back_to_the_active_academic_year_then_the_holiday_message(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'username' => 'manager-dashboard-context-fallback',
            'phone' => '7000099',
        ]);
        $manager->assignRole('manager');

        $academicYear = AcademicYear::create([
            'name' => 'Academic Year 2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('dashboard-course-context__course', false)
            ->assertSee('Academic Year 2026/2027')
            ->assertDontSee(__('dashboard.common.no_active_courses'));

        $academicYear->update(['is_active' => false]);
        config(['app.locale' => 'ar']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('عطلة - لا يوجد دورات فعالة حالياً');
    }

    public function test_manager_users_see_the_management_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'username' => 'manager-dashboard',
            'phone' => '7000001',
        ]);

        $manager->assignRole('manager');

        ParentProfile::create([
            'father_name' => 'Ahmad Ali',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Yousef',
            'last_name' => 'Teacher',
            'phone' => '0944000002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Quran Foundations',
            'is_active' => true,
            'is_default' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Boys A',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => ParentProfile::query()->firstOrFail()->id,
            'first_name' => 'Omar',
            'last_name' => 'Ali',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 42,
            'memorized_pages_cached' => 18,
        ]);

        $belowAverageStudent = Student::create([
            'parent_id' => ParentProfile::query()->firstOrFail()->id,
            'first_name' => 'Nabil',
            'last_name' => 'Below Average',
            'birth_date' => '2015-03-10',
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $belowAverageStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 0,
            'memorized_pages_cached' => 0,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => now()->toDateString(),
            'entry_type' => 'new',
            'from_page' => 1,
            'to_page' => 5,
            'pages_count' => 5,
        ]);

        $present = AttendanceStatus::create([
            'name' => 'Present',
            'code' => 'present-dashboard',
            'scope' => 'student',
            'is_present' => true,
            'is_active' => true,
        ]);
        $attendanceDay = GroupAttendanceDay::create([
            'group_id' => $group->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'closed',
        ]);
        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $attendanceDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $present->id,
        ]);

        $otherCourse = Course::create(['name' => 'Other Course', 'is_active' => true]);
        Group::create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Excluded Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Management Dashboard')
            ->assertSee('Quran Foundations')
            ->assertSee('Average Attendance')
            ->assertSee('Final Tested Juz')
            ->assertSeeInOrder(['Active Groups', 'Average Attendance', 'Memorized Pages', 'Final Tested Juz', 'Total Points'])
            ->assertSee('dashboard-manager-highlights', false)
            ->assertSee('dashboard-course-context__course', false)
            ->assertSee('Number of Students per Group')
            ->assertSee('Comparison between Attendance and Memorisation')
            ->assertSee('Student Performance Map')
            ->assertDontSee('Each point is a student. Select one to open their summary.')
            ->assertSee('dashboard-ranking-grid mt-6 grid items-stretch', false)
            ->assertSee('dashboard-performance-map__plot', false)
            ->assertSee('dashboard-performance-map__point', false)
            ->assertSee('dashboard-performance-map__point--above-average', false)
            ->assertSee('data-performance-dot-size=', false)
            ->assertSee('dashboard-performance-map__point--below-average', false)
            ->assertSee('data-performance-cluster-size="1"', false)
            ->assertSee('data-performance-cluster-radius="0.5"', false)
            ->assertDontSee('data-performance-dimmed-border-layer', false)
            ->assertDontSee('dashboard-performance-map__x-axis', false)
            ->assertDontSee('dashboard-performance-map__y-axis', false)
            ->assertDontSee('Strong in both')
            ->assertDontSee('Building momentum')
            ->assertSee('wire:click="showManagerStudent('.$student->id.')"', false)
            ->assertDontSee('wire:click="showManagerStudent('.$belowAverageStudent->id.')"', false)
            ->assertSee('Top Groups by Memorisation')
            ->assertDontSee('Latest five attendance days')
            ->assertDontSee('Count')
            ->assertSee('Groups')
            ->assertSee('Boys A')
            ->assertSee('Memorized pages: 5')
            ->assertSee('Students attended: 1')
            ->assertSee('dashboard-line-point__tooltip', false)
            ->assertSee('data-dashboard-line-tooltip-value-only', false)
            ->assertSee('width="30" height="15"', false)
            ->assertSee('dashboard-line-point__tooltip-value', false)
            ->assertSee('data-dashboard-bar-tooltip-value-only', false)
            ->assertDontSee('absolute inset-x-0 -top-6 text-sm font-semibold', false)
            ->assertSee('dashboard-lollipop-attendance__tooltip', false)
            ->assertSee('100.0%', false)
            ->assertSee('dashboard-line-chart', false)
            ->assertSee('viewBox="0 0 458 220"', false)
            ->assertSee('data-dashboard-expanded-line-chart', false)
            ->assertSee('max-w-none', false)
            ->assertSee('dashboard-treemap', false)
            ->assertSee('data-dashboard-lollipop-number-gap', false)
            ->assertSee('dashboard-lollipop-row__label', false)
            ->assertSee('dashboard-lollipop-row__track', false)
            ->assertSee('inset-inline-start: calc(100% - .4375rem)', false)
            ->assertSee('data-dashboard-centered-bar-chart', false)
            ->assertSee('data-dashboard-vertically-centered-bar-card', false)
            ->assertSee('data-dashboard-bar-content-centered', false)
            ->assertSee('dashboard-curriculum-progress-card', false)
            ->assertDontSee('data-dashboard-groups-axis', false)
            ->assertSee('flex-col justify-center', false)
            ->assertSee('data-dashboard-balanced-axis', false)
            ->assertSee('grid-cols-[3rem_minmax(0,1fr)_3rem]', false)
            ->assertSee('grid-template-columns: repeat(1, minmax(0, 1fr))', false)
            ->assertDontSee('stroke-dasharray', false)
            ->assertDontSee('<div class="eyebrow">Curricula</div>', false)
            ->assertDontSee('Recent Groups')
            ->assertDontSee('Students with an active enrollment in the default course')
            ->assertDontSee('Excluded Group');

        $dashboardCss = file_get_contents(resource_path('css/app.css'));
        $dashboardSource = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));
        $this->assertStringNotContainsString("\$loop->even ? 'badge-soft--emerald' : ''", $dashboardSource);
        $this->assertStringContainsString('.dashboard-performance-map__plot {', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__average-line--vertical {', $dashboardCss);
        $this->assertStringContainsString('background-size: 20% 100%, 100% 20%', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--below-average {', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__dimmed-layer {', $dashboardCss);
        $this->assertStringContainsString('opacity: 0.08;', $dashboardCss);
        $this->assertStringContainsString('width: 6px;', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--above-average .dashboard-performance-map__dot {', $dashboardCss);
        $this->assertStringContainsString('width: var(--performance-dot-size, 12px);', $dashboardCss);
        $this->assertStringNotContainsString('.dashboard-performance-map__dimmed-border-layer {', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point:hover .dashboard-performance-map__dot,', $dashboardCss);
        $this->assertStringContainsString('background: #34d399;', $dashboardCss);
        $this->assertStringContainsString('padding-top: 1.25rem !important;', $dashboardCss);
        $this->assertStringContainsString('margin-top: 0 !important;', $dashboardCss);
        $this->assertStringContainsString(".dashboard-treemap {\n    display: grid;\n    grid-template-columns: max-content minmax(0, 1fr) auto;\n    column-gap: 0;", $dashboardCss);
        $this->assertStringContainsString(".dashboard-lollipop-row {\n    display: grid;\n    grid-column: 1 / -1;\n    grid-template-columns: subgrid;", $dashboardCss);
        $this->assertStringContainsString(".dashboard-lollipop-row__label {\n    margin-inline-end: 2.25rem;\n    overflow: visible;\n    text-overflow: clip;\n    white-space: nowrap;", $dashboardCss);
        $this->assertStringContainsString('margin-inline-start: var(--dashboard-lollipop-number-gap, 1.125rem);', $dashboardCss);
        $this->assertStringContainsString('synchronizeDashboardLollipopNumberGap', file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('grid-cols-[minmax(5rem,9rem)_minmax(0,1fr)_auto]', $dashboardSource);

        Volt::test('dashboard')
            ->call('showManagerStudent', $student->id)
            ->assertSet('selectedManagerStudentId', $student->id)
            ->assertSee('Student Highlights')
            ->assertSee('Omar Ali')
            ->assertSee('42')
            ->assertSee('18');
    }

    public function test_manager_performance_map_ranks_projected_course_end_points_but_keeps_the_original_average(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'username' => 'manager-performance-rules',
            'phone' => '7000011',
        ]);
        $manager->assignRole('manager');
        $teacher = Teacher::create([
            'first_name' => 'Points',
            'last_name' => 'Teacher',
            'phone' => '0944000015',
            'status' => 'active',
        ]);
        $course = Course::create([
            'name' => 'Points Projection Course',
            'is_active' => true,
            'is_default' => true,
            'awards_points' => true,
        ]);
        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Projected Points Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $pointType = PointType::create([
            'name' => 'Dashboard Projection Points',
            'code' => 'dashboard-projection-points',
            'category' => 'behavior',
            'default_points' => 0,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        $students = collect([
            ['name' => 'Before Second After First', 'points' => 900, 'pages' => 40],
            ['name' => 'Before First After Second', 'points' => 1000, 'pages' => 30],
            ['name' => 'Bronze Student', 'points' => 800, 'pages' => 20],
            ['name' => 'Fourth Student', 'points' => 600, 'pages' => 10],
        ])->map(function (array $row) use ($group, $manager, $pointType): array {
            [$firstName, $lastName] = explode(' ', $row['name'], 2);
            $student = Student::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => '2014-01-01',
                'status' => 'active',
            ]);
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'group_id' => $group->id,
                'enrolled_at' => '2026-09-01',
                'status' => 'active',
                'final_points_cached' => $row['points'],
                'memorized_pages_cached' => $row['pages'],
            ]);
            PointTransaction::create([
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'point_type_id' => $pointType->id,
                'source_type' => 'manual',
                'source_id' => $enrollment->id,
                'points' => $row['points'],
                'entered_by' => $manager->id,
                'entered_at' => now(),
            ]);

            return ['student' => $student, 'enrollment' => $enrollment, ...$row];
        });

        $present = AttendanceStatus::create([
            'name' => 'Projection Present',
            'code' => 'projection-present',
            'scope' => 'student',
            'is_present' => true,
            'is_active' => true,
        ]);
        $attendanceDay = GroupAttendanceDay::create([
            'group_id' => $group->id,
            'attendance_date' => '2026-09-10',
            'status' => 'closed',
        ]);
        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $attendanceDay->id,
            'enrollment_id' => $students[0]['enrollment']->id,
            'attendance_status_id' => $present->id,
        ]);

        foreach ([
            'required_passed_final_tests' => 0,
            'required_memorized_pages' => 35,
            'required_passed_quizzes' => 0,
            'retain_percentage' => 50,
            'minimum_points' => 0,
        ] as $key => $value) {
            AppSetting::storeValue('course_completion', $key, $value, 'integer');
        }
        AppSetting::storeValue('course_completion', 'final_rule_operator', 'and');
        AppSetting::storeValue('course_completion', 'assessment_type_requirements', [], 'array');
        AppSetting::storeValue('course_completion', 'final_test_grade_ids', [], 'array');
        AppSetting::storeValue('course_completion', 'assessment_grade_ids', [], 'array');

        $this->actingAs($manager);

        Volt::test('dashboard')
            ->assertViewHas('studentPerformance', function ($rows) use ($students): bool {
                $rowsByStudent = $rows->keyBy(fn (array $row) => $row['student']->id);

                return $rowsByStudent[$students[0]['student']->id]['points_before'] === 900
                    && $rowsByStudent[$students[0]['student']->id]['points'] === 900
                    && $rowsByStudent[$students[0]['student']->id]['rank'] === 1
                    && $rowsByStudent[$students[1]['student']->id]['points_before'] === 1000
                    && $rowsByStudent[$students[1]['student']->id]['points'] === 500
                    && $rowsByStudent[$students[1]['student']->id]['rank'] === 2
                    && $rowsByStudent[$students[2]['student']->id]['points'] === 400
                    && $rowsByStudent[$students[2]['student']->id]['rank'] === 3
                    && $rowsByStudent[$students[3]['student']->id]['points'] === 300
                    && $rowsByStudent[$students[3]['student']->id]['rank'] === null;
            })
            ->assertSee('data-points-average-before-rules="825"', false)
            ->assertSee('dashboard-performance-map__point--rank-1', false)
            ->assertSee('dashboard-performance-map__point--rank-2', false)
            ->assertSee('dashboard-performance-map__point--rank-3', false)
            ->assertSee('data-dashboard-centered-bar-chart', false);

        $dashboardCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.dashboard-performance-map__point--rank-1 .dashboard-performance-map__dot {', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--rank-2 .dashboard-performance-map__dot {', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--rank-3 .dashboard-performance-map__dot {', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--rank-1:hover .dashboard-performance-map__dot,', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--rank-2:hover .dashboard-performance-map__dot,', $dashboardCss);
        $this->assertStringContainsString('.dashboard-performance-map__point--rank-3:hover .dashboard-performance-map__dot,', $dashboardCss);
    }

    public function test_manager_curriculum_progress_uses_two_column_peer_relative_hotbars(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'username' => 'manager-curriculum-hotbars',
            'phone' => '7000016',
        ]);
        $manager->assignRole('manager');
        $teacher = Teacher::create([
            'first_name' => 'Hassan',
            'last_name' => 'Teacher',
            'phone' => '0944000016',
            'status' => 'active',
        ]);
        $course = Course::create([
            'name' => 'Curriculum Progress Course',
            'is_active' => true,
            'is_default' => true,
        ]);
        $academicYear = AcademicYear::create([
            'name' => 'Curriculum Progress Year',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $curriculum = Curriculum::create([
            'course_id' => $course->id,
            'name' => 'Peer Progress Curriculum',
            'is_active' => true,
        ]);
        $definition = CurriculumSubjectDefinition::create([
            'name' => 'Peer Progress Subject',
            'is_active' => true,
        ]);
        $subject = CurriculumSubject::create([
            'curriculum_id' => $curriculum->id,
            'subject_definition_id' => $definition->id,
        ]);
        $lessons = collect(range(1, 10))->map(fn (int $number) => CurriculumLesson::create([
            'curriculum_subject_id' => $subject->id,
            'name' => 'Lesson '.$number,
            'sort_order' => $number * 10,
        ]));

        $gradeLevels = collect([
            30 => GradeLevel::create(['name' => 'Grade C', 'sort_order' => 30, 'is_active' => true]),
            10 => GradeLevel::create(['name' => 'Grade A', 'sort_order' => 10, 'is_active' => true]),
            20 => GradeLevel::create(['name' => 'Grade B', 'sort_order' => 20, 'is_active' => true]),
        ]);

        $groups = collect([
            ['name' => 'Group A', 'completed' => 10, 'grade_sort' => 30],
            ['name' => 'Group B', 'completed' => 8, 'grade_sort' => 10],
            ['name' => 'Group C', 'completed' => 6, 'grade_sort' => 20],
            ['name' => 'Group D', 'completed' => 3, 'grade_sort' => null],
        ])->map(function (array $entry) use ($course, $academicYear, $curriculum, $teacher, $lessons, $gradeLevels): Group {
            $group = Group::create([
                'course_id' => $course->id,
                'academic_year_id' => $academicYear->id,
                'teacher_id' => $teacher->id,
                'grade_level_id' => $entry['grade_sort'] === null ? null : $gradeLevels[$entry['grade_sort']]->id,
                'curriculum_id' => $curriculum->id,
                'name' => $entry['name'],
                'capacity' => 20,
                'is_active' => true,
            ]);

            $lessons->take($entry['completed'])->each(fn (CurriculumLesson $lesson) => GroupCurriculumLessonProgress::create([
                'group_id' => $group->id,
                'curriculum_lesson_id' => $lesson->id,
                'teacher_id' => $teacher->id,
                'status' => 'taught',
                'taught_on' => now()->toDateString(),
            ]));

            return $group;
        });

        $this->actingAs($manager);
        app()->setLocale('ar');

        Volt::test('dashboard')
            ->assertViewHas('curriculumProgress', function ($rows) use ($groups): bool {
                $byGroup = $rows->keyBy(fn (array $row) => $row['group']->id);

                return $rows->pluck('group.id')->values()->all() === [
                    $groups[1]->id,
                    $groups[2]->id,
                    $groups[0]->id,
                    $groups[3]->id,
                ]
                    && $byGroup[$groups[0]->id]['percentage'] === 100.0
                    && $byGroup[$groups[0]->id]['tone'] === 'success'
                    && $byGroup[$groups[1]->id]['tone'] === 'success'
                    && $byGroup[$groups[2]->id]['percentage_gap'] === 10.0
                    && $byGroup[$groups[2]->id]['lessons_behind'] === 1
                    && $byGroup[$groups[2]->id]['tone'] === 'warning'
                    && $byGroup[$groups[3]->id]['percentage_gap'] === 50.0
                    && $byGroup[$groups[3]->id]['lessons_behind'] === 5
                    && $byGroup[$groups[3]->id]['tone'] === 'danger';
            })
            ->assertSee('data-dashboard-curriculum-hotbars', false)
            ->assertSee('data-dashboard-curriculum-name-gap="حلقة"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('md:grid-cols-2', false)
            ->assertSee('data-progress-tone="success"', false)
            ->assertSee('data-progress-tone="warning"', false)
            ->assertSee('data-progress-tone="danger"', false)
            ->assertSee('data-lessons-behind="1"', false)
            ->assertSee('data-lessons-behind="5"', false)
            ->assertSee('dashboard-lollipop-attendance__tooltip dashboard-curriculum-hotbar__tooltip', false)
            ->assertSee('Hassan Teacher');

        $dashboardSource = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));
        $dashboardCss = file_get_contents(resource_path('css/app.css'));
        $dashboardJavascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("'tone' => \$percentageGap > 15 ? 'danger' : (\$percentageGap > 5 ? 'warning' : 'success')", $dashboardSource);
        $this->assertStringContainsString('.dashboard-curriculum-hotbar__fill--warning,', $dashboardCss);
        $this->assertStringContainsString('.dashboard-curriculum-hotbar__fill--danger,', $dashboardCss);
        $this->assertStringContainsString('.dashboard-curriculum-hotbar__marker {', $dashboardCss);
        $this->assertStringContainsString('grid-template-columns: var(--dashboard-curriculum-identity-width, max-content) max-content minmax(8rem, 1fr);', $dashboardCss);
        $this->assertStringContainsString('content: attr(data-dashboard-curriculum-name-gap);', $dashboardCss);
        $this->assertStringContainsString('width: 100%;', $dashboardCss);
        $this->assertStringContainsString('max-width: none;', $dashboardCss);
        $this->assertStringContainsString('synchronizeDashboardCurriculumHotbarWidths', $dashboardJavascript);
        $this->assertStringContainsString('.dashboard-curriculum-hotbars::before {', $dashboardCss);
        $this->assertStringContainsString("->orderByRaw('CASE WHEN grade_level_id IS NULL THEN 1 ELSE 0 END')", $dashboardSource);
    }

    public function test_super_admin_users_see_the_management_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'super-admin-dashboard',
            'phone' => '7000009',
        ]);

        $user->assignRole('super_admin');

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Management Dashboard')
            ->assertDontSee('Dashboard Setup');
    }

    public function test_custom_roles_with_manager_dashboard_permission_see_the_management_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::findOrCreate('site-director', 'web');
        $role->givePermissionTo(['dashboard.manager.view']);

        $user = User::factory()->create([
            'username' => 'custom-manager-dashboard',
            'phone' => '7000010',
        ]);

        $user->assignRole($role);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Management Dashboard')
            ->assertDontSee('Dashboard Setup');
    }

    public function test_teacher_users_see_only_their_group_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'teacher-dashboard',
            'phone' => '7000002',
        ]);

        $user->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Salim',
            'last_name' => 'Adib',
            'phone' => '0944000011',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Advanced Memorization',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Teacher Group',
            'capacity' => 15,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Teacher Dashboard')
            ->assertSee('Your Groups')
            ->assertSee('Teacher Group');
    }

    public function test_group_supervisor_teacher_sees_group_dashboard_and_assigned_course(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'group-supervisor-dashboard']);
        $user->assignRole('teacher');
        $supervisorRole = Role::findOrCreate('مشرف حلقة', 'web');
        $teacher = Teacher::create([
            'user_id' => $user->id,
            'access_role_id' => $supervisorRole->id,
            'first_name' => 'Supervisor',
            'last_name' => 'Teacher',
            'phone' => '0944111222',
            'status' => 'active',
        ]);
        Course::create(['name' => 'Default Course', 'is_active' => true, 'is_default' => true]);
        $assignedCourse = Course::create(['name' => 'Assigned Special Course', 'is_active' => true]);
        $academicYear = AcademicYear::create([
            'name' => 'Hidden Academic Year',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $group = Group::create([
            'course_id' => $assignedCourse->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Supervisor Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $student = Student::create([
            'first_name' => 'Ranked',
            'last_name' => 'Student',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 75,
            'memorized_pages_cached' => 12,
        ]);
        $present = AttendanceStatus::create([
            'name' => 'Present',
            'code' => 'supervisor-dashboard-present',
            'scope' => 'student',
            'is_present' => true,
            'is_active' => true,
        ]);
        $attendanceDay = GroupAttendanceDay::create([
            'group_id' => $group->id,
            'attendance_date' => '2026-09-10',
            'status' => 'closed',
        ]);
        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $attendanceDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $present->id,
        ]);
        MemorizationSession::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'from_page' => 10,
            'to_page' => 12,
            'pages_count' => 3,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Assigned Special Course')
            ->assertSee('dashboard-course-context__course', false)
            ->assertSee('dashboard-course-context__group', false)
            ->assertDontSee('Hidden Academic Year')
            ->assertSee('Supervisor Group')
            ->assertSee("Copy today's summary")
            ->assertSee('Average attendance percentage')
            ->assertSee('100.0%')
            ->assertSee('Top 5 Students by Memorization')
            ->assertSee('Top Students by Points')
            ->assertSee('Curriculum progress')
            ->assertDontSee('<div class="eyebrow">Curricula</div>', false)
            ->assertDontSee('Latest Memorization Entries')
            ->assertSee('Ranked Student')
            ->assertSee('dashboard-line-chart', false)
            ->assertSee('teacher-memorization-ranking-row', false)
            ->assertSee('teacher-points-card', false)
            ->assertSee('teacher-points-table', false)
            ->assertDontSee('teacher-points-mobile', false)
            ->assertSee('teacher-curriculum-card', false);

        $dashboardCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.teacher-points-card {', $dashboardCss);
        $this->assertStringContainsString('overflow: hidden !important;', $dashboardCss);
        $this->assertStringContainsString('.teacher-points-table table {', $dashboardCss);
        $this->assertStringContainsString('min-width: 34rem;', $dashboardCss);
        $this->assertStringNotContainsString('.teacher-points-mobile {', $dashboardCss);
        $this->assertStringContainsString('.teacher-memorization-ranking-row {', $dashboardCss);

        Volt::test('dashboard')
            ->call('copyTeacherTodaySummary', $group->id)
            ->assertDispatched('admin-copy-text', fn ($event, $params) => str_contains($params['text'], 'Supervisor Group')
                && str_contains($params['text'], 'Attendance:')
                && ! str_contains($params['text'], 'Memorization:')
                && str_contains($params['text'], 'Assigned Special Course')
                && str_contains($params['text'], 'May Allah accept from us and from you 🌺'))
            ->call('openTeacherLeaderboard')
            ->assertSet('showTeacherLeaderboardModal', true)
            ->call('openTeacherMemorizations')
            ->assertSet('showTeacherMemorizationsModal', true);
    }

    public function test_parent_users_see_only_their_students(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'parent-dashboard',
            'phone' => '7000003',
        ]);

        $user->assignRole('parent');

        $parent = ParentProfile::create([
            'user_id' => $user->id,
            'father_name' => 'Maher Hasan',
            'father_phone' => '0944000010',
        ]);

        Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Omar',
            'last_name' => 'Hasan',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Parent Dashboard')
            ->assertSee('Your Students')
            ->assertSee('Omar Hasan');
    }

    public function test_student_users_see_only_their_enrollments(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'student-dashboard',
            'phone' => '7000004',
        ]);

        $user->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Parent Name',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'first_name' => 'Aya',
            'last_name' => 'Hasan',
            'birth_date' => '2013-03-03',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0944000099',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 12,
            'memorized_pages_cached' => 6,
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Student Dashboard')
            ->assertSee('Your Enrollments')
            ->assertSee('Student Group');
    }

    public function test_student_dashboard_can_show_group_card_preview_from_dashboard_settings(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'student-dashboard-card',
            'phone' => '7000104',
        ]);

        $user->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Parent Name',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'first_name' => 'Aya',
            'last_name' => 'Hasan',
            'birth_date' => '2013-03-03',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0944000199',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 12,
            'memorized_pages_cached' => 6,
        ]);

        $template = PrintTemplate::create([
            'name' => 'Student Dashboard Card',
            'width_mm' => 85.6,
            'height_mm' => 54.0,
            'background_image' => null,
            'data_sources' => [
                ['entity' => 'student', 'mode' => 'single'],
            ],
            'layout_json' => [
                [
                    'id' => 'student-name',
                    'type' => 'dynamic_text',
                    'source' => 'student',
                    'field' => 'full_name',
                    'content' => '',
                    'x' => 8,
                    'y' => 8,
                    'width' => 55,
                    'height' => 10,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                        'border_radius' => 0,
                        'object_fit' => 'cover',
                        'letter_spacing' => 0,
                        'show_text' => true,
                        'barcode_format' => 'code39',
                        'line_height' => 1.2,
                    ],
                ],
                [
                    'id' => 'group-name',
                    'type' => 'dynamic_text',
                    'source' => 'student',
                    'field' => 'group_name',
                    'content' => '',
                    'x' => 8,
                    'y' => 22,
                    'width' => 55,
                    'height' => 8,
                    'z_index' => 2,
                    'styling' => [
                        'font_size' => 3.6,
                        'font_weight' => '600',
                        'color' => '#102316',
                        'text_align' => 'left',
                        'border_radius' => 0,
                        'object_fit' => 'cover',
                        'letter_spacing' => 0,
                        'show_text' => true,
                        'barcode_format' => 'code39',
                        'line_height' => 1.2,
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        AppSetting::storeValue('general', 'student_dashboard_card_templates', [
            (string) $group->id => $template->id,
        ], 'array');

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Your printed cards')
            ->assertSee('Student Dashboard Card')
            ->assertSee('Aya Hasan')
            ->assertSee('Student Group');
    }

    public function test_student_dashboard_can_show_group_card_preview_from_any_active_print_template(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'student-dashboard-generic-card',
            'phone' => '7000105',
        ]);

        $user->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Parent Name',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'first_name' => 'Hasan',
            'last_name' => 'Hamdan',
            'birth_date' => '2013-03-03',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0944000298',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Generic Card Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Generic Card Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $template = PrintTemplate::create([
            'name' => 'Generic Dashboard Card',
            'width_mm' => 85.6,
            'height_mm' => 54.0,
            'background_image' => null,
            'data_sources' => [],
            'layout_json' => [
                [
                    'id' => 'title',
                    'type' => 'custom_text',
                    'content' => 'Generic preview card',
                    'x' => 8,
                    'y' => 8,
                    'width' => 55,
                    'height' => 10,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        AppSetting::storeValue('general', 'student_dashboard_card_templates', [
            (string) $group->id => $template->id,
        ], 'array');

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Your printed cards')
            ->assertSee('Generic Dashboard Card')
            ->assertSee('Generic Card Group')
            ->assertSee('Generic preview card');
    }

    public function test_student_dashboard_cached_points_only_count_active_enrollments(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'username' => 'student-dashboard-active-points',
            'phone' => '7000204',
        ]);

        $user->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Parent Name',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'first_name' => 'Aya',
            'last_name' => 'Hasan',
            'birth_date' => '2013-03-03',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0944000299',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $activeGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Active Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $inactiveGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Inactive Group',
            'capacity' => 10,
            'is_active' => false,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $activeGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 12,
            'memorized_pages_cached' => 6,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $inactiveGroup->id,
            'enrolled_at' => '2026-08-01',
            'status' => 'cancelled',
            'final_points_cached' => 99,
            'memorized_pages_cached' => 40,
        ]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Student Dashboard')
            ->assertSee('Active Group')
            ->assertSee('Inactive Group')
            ->assertSee('>12<', false)
            ->assertSee('>6<', false)
            ->assertDontSee('>111<', false)
            ->assertDontSee('>46<', false);
    }
}

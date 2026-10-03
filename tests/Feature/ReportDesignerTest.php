<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestAttempt;
use App\Models\QuranPartialTestPart;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\ReportDefinition;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\ReportDesignerQueryService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportDesignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_administrator_can_save_and_preview_a_student_report_draft(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'report-admin']);
        $administrator->assignRole('admin');
        Student::query()->create([
            'first_name' => 'Mariam',
            'last_name' => 'Ahmad',
            'student_number' => 'S-100',
            'birth_date' => '2014-01-01',
            'status' => 'active',
            'joined_at' => '2026-09-01',
        ]);

        $this->actingAs($administrator)
            ->get(route('reports.designer', absolute: false))
            ->assertOk()
            ->assertSee(__('report_designer.title'));

        Volt::test('reports.designer')
            ->call('create')
            ->set('name', 'Active students')
            ->set('description', 'Operational active-student list')
            ->set('selectedFields', ['student_number', 'full_name', 'status'])
            ->set('statusFilter', 'active')
            ->set('sortField', 'full_name')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Mariam Ahmad')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('report_definitions', [
            'name' => 'Active students',
            'data_source' => 'students',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
        ]);
    }

    public function test_report_preview_rejects_fields_outside_the_approved_catalog(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'safe-report-admin']);
        $administrator->assignRole('admin');
        $this->actingAs($administrator);

        Volt::test('reports.designer')
            ->call('create')
            ->set('selectedFields', ['full_name', 'password'])
            ->call('preview')
            ->assertHasErrors('selectedFields');
    }

    public function test_courses_and_groups_are_approved_sources_with_operational_counts(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'catalog-report-admin']);
        $administrator->assignRole('admin');
        $year = AcademicYear::query()->create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Amina',
            'last_name' => 'Saleh',
            'phone' => '0900000001',
            'status' => 'active',
        ]);
        $course = Course::query()->create([
            'academic_year_id' => $year->id,
            'name' => 'Quran Foundations',
            'starts_on' => '2026-09-10',
            'is_active' => true,
        ]);
        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Morning Group',
            'capacity' => 12,
            'starts_on' => '2026-09-12',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'first_name' => 'Mariam',
            'last_name' => 'Ahmad',
            'student_number' => 'S-301',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-12',
            'status' => 'active',
        ]);

        $service = app(ReportDesignerQueryService::class);
        $courses = $service->preview([
            'data_source' => 'courses',
            'selected_fields' => ['course_name', 'groups_count', 'active_enrollments_count'],
            'filters' => ['status' => 'active'],
            'sort_direction' => 'asc',
        ], $administrator);
        $groups = $service->preview([
            'data_source' => 'groups',
            'selected_fields' => ['group_name', 'teacher_name', 'active_enrollments_count', 'available_places'],
            'filters' => ['status' => 'active'],
            'sort_direction' => 'asc',
        ], $administrator);

        $this->assertSame([
            'course_name' => 'Quran Foundations',
            'groups_count' => 1,
            'active_enrollments_count' => 1,
        ], $courses['rows'][0]);
        $this->assertSame([
            'group_name' => 'Morning Group',
            'teacher_name' => 'Amina Saleh',
            'active_enrollments_count' => 1,
            'available_places' => 11,
        ], $groups['rows'][0]);
    }

    public function test_group_and_course_previews_respect_the_users_group_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-group-report-user']);
        $user->givePermissionTo('report-designer.view');
        $year = AcademicYear::query()->create([
            'name' => 'Scoped year',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Scoped',
            'last_name' => 'Teacher',
            'phone' => '0900000002',
            'status' => 'active',
        ]);
        $visibleCourse = Course::query()->create(['name' => 'Visible Course', 'is_active' => true]);
        $hiddenCourse = Course::query()->create(['name' => 'Hidden Course', 'is_active' => true]);
        $groupAttributes = ['academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'capacity' => 10, 'is_active' => true];
        $visibleGroup = Group::query()->create($groupAttributes + ['course_id' => $visibleCourse->id, 'name' => 'Visible Group']);
        Group::query()->create($groupAttributes + ['course_id' => $hiddenCourse->id, 'name' => 'Hidden Group']);
        app(AccessScopeService::class)->syncUserOverrides($user, ['group' => [$visibleGroup->id]]);

        $service = app(ReportDesignerQueryService::class);
        $courses = $service->preview([
            'data_source' => 'courses',
            'selected_fields' => ['course_name', 'groups_count'],
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
        ], $user);
        $groups = $service->preview([
            'data_source' => 'groups',
            'selected_fields' => ['group_name', 'course_name'],
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([['course_name' => 'Visible Course', 'groups_count' => 1]], $courses['rows']);
        $this->assertSame([['group_name' => 'Visible Group', 'course_name' => 'Visible Course']], $groups['rows']);
    }

    public function test_student_attendance_source_supports_center_attendance_and_presence_filters(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'attendance-report-admin']);
        $administrator->assignRole('admin');
        $student = Student::query()->create([
            'first_name' => 'Layla',
            'last_name' => 'Hassan',
            'student_number' => 'S-401',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $present = AttendanceStatus::query()->create([
            'name' => 'Present',
            'code' => 'designer-present',
            'scope' => 'student',
            'is_present' => true,
            'is_active' => true,
        ]);
        $day = StudentAttendanceDay::query()->create([
            'attendance_date' => '2026-10-01',
            'scope' => 'center',
            'status' => 'closed',
            'created_by' => $administrator->id,
        ]);
        StudentAttendanceRecord::query()->create([
            'student_attendance_day_id' => $day->id,
            'student_id' => $student->id,
            'attendance_status_id' => $present->id,
            'notes' => 'Arrived on time',
        ]);

        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'student_attendance',
            'selected_fields' => ['attendance_date', 'student_number', 'full_name', 'attendance_status', 'presence_result', 'attendance_scope', 'notes'],
            'filters' => ['status' => 'present', 'date_from' => '2026-10-01', 'date_to' => '2026-10-01'],
            'sort_direction' => 'asc',
        ], $administrator);

        $this->assertSame([[
            'attendance_date' => '2026-10-01',
            'student_number' => $student->fresh()->student_number,
            'full_name' => 'Layla Hassan',
            'attendance_status' => 'Present',
            'presence_result' => __('report_designer.presence_results.present'),
            'attendance_scope' => __('report_designer.attendance_scopes.center'),
            'notes' => 'Arrived on time',
        ]], $preview['rows']);
    }

    public function test_student_attendance_preview_respects_group_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-attendance-report-user']);
        $user->givePermissionTo('report-designer.view');
        $year = AcademicYear::query()->create([
            'name' => 'Attendance year',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Attendance',
            'last_name' => 'Teacher',
            'phone' => '0900000003',
            'status' => 'active',
        ]);
        $course = Course::query()->create(['academic_year_id' => $year->id, 'name' => 'Attendance Course', 'is_active' => true]);
        $groupAttributes = ['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'capacity' => 10, 'is_active' => true];
        $visibleGroup = Group::query()->create($groupAttributes + ['name' => 'Visible Attendance Group']);
        $hiddenGroup = Group::query()->create($groupAttributes + ['name' => 'Hidden Attendance Group']);
        $visibleStudent = Student::query()->create(['first_name' => 'Visible', 'last_name' => 'Attendee', 'student_number' => 'S-501', 'birth_date' => '2014-01-01', 'status' => 'active']);
        $hiddenStudent = Student::query()->create(['first_name' => 'Hidden', 'last_name' => 'Attendee', 'student_number' => 'S-502', 'birth_date' => '2014-01-02', 'status' => 'active']);
        $visibleEnrollment = Enrollment::query()->create(['student_id' => $visibleStudent->id, 'group_id' => $visibleGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        $hiddenEnrollment = Enrollment::query()->create(['student_id' => $hiddenStudent->id, 'group_id' => $hiddenGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        $status = AttendanceStatus::query()->create(['name' => 'Present', 'code' => 'scope-present', 'scope' => 'student', 'is_present' => true, 'is_active' => true]);
        $day = StudentAttendanceDay::query()->create(['attendance_date' => '2026-10-02', 'course_id' => $course->id, 'scope' => 'groups', 'status' => 'closed']);
        $visibleDay = GroupAttendanceDay::query()->create(['group_id' => $visibleGroup->id, 'student_attendance_day_id' => $day->id, 'attendance_date' => '2026-10-02', 'status' => 'closed']);
        $hiddenDay = GroupAttendanceDay::query()->create(['group_id' => $hiddenGroup->id, 'student_attendance_day_id' => $day->id, 'attendance_date' => '2026-10-02', 'status' => 'closed']);
        StudentAttendanceRecord::query()->create(['group_attendance_day_id' => $visibleDay->id, 'enrollment_id' => $visibleEnrollment->id, 'attendance_status_id' => $status->id]);
        StudentAttendanceRecord::query()->create(['group_attendance_day_id' => $hiddenDay->id, 'enrollment_id' => $hiddenEnrollment->id, 'attendance_status_id' => $status->id]);
        app(AccessScopeService::class)->syncUserOverrides($user, ['group' => [$visibleGroup->id]]);

        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'student_attendance',
            'selected_fields' => ['full_name', 'group_name'],
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([['full_name' => 'Visible Attendee', 'group_name' => 'Visible Attendance Group']], $preview['rows']);
    }

    public function test_memorization_and_quran_test_sources_respect_group_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-quran-report-user']);
        $user->givePermissionTo('report-designer.view');
        $year = AcademicYear::query()->create([
            'name' => 'Quran report year',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Quran',
            'last_name' => 'Teacher',
            'phone' => '0900000004',
            'status' => 'active',
        ]);
        $course = Course::query()->create(['academic_year_id' => $year->id, 'name' => 'Quran Course', 'is_active' => true]);
        $groupAttributes = ['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'capacity' => 10, 'is_active' => true];
        $visibleGroup = Group::query()->create($groupAttributes + ['name' => 'Visible Quran Group']);
        $hiddenGroup = Group::query()->create($groupAttributes + ['name' => 'Hidden Quran Group']);
        $visibleStudent = Student::query()->create(['first_name' => 'Visible', 'last_name' => 'Learner', 'student_number' => 'S-601', 'birth_date' => '2014-01-01', 'status' => 'active']);
        $hiddenStudent = Student::query()->create(['first_name' => 'Hidden', 'last_name' => 'Learner', 'student_number' => 'S-602', 'birth_date' => '2014-01-02', 'status' => 'active']);
        $visibleEnrollment = Enrollment::query()->create(['student_id' => $visibleStudent->id, 'group_id' => $visibleGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        $hiddenEnrollment = Enrollment::query()->create(['student_id' => $hiddenStudent->id, 'group_id' => $hiddenGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        foreach ([$visibleEnrollment, $hiddenEnrollment] as $enrollment) {
            MemorizationSession::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'teacher_id' => $teacher->id,
                'recorded_on' => '2026-10-03',
                'entry_type' => 'new',
                'from_page' => 1,
                'to_page' => 3,
                'pages_count' => 3,
            ]);
        }
        $juz = QuranJuz::query()->create(['juz_number' => 1, 'from_page' => 1, 'to_page' => 21]);
        $testType = QuranTestType::query()->create(['name' => 'Awqaf', 'code' => 'designer-awqaf', 'sort_order' => 1, 'is_active' => true]);
        foreach ([$visibleEnrollment, $hiddenEnrollment] as $enrollment) {
            QuranTest::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'teacher_id' => $teacher->id,
                'juz_id' => $juz->id,
                'quran_test_type_id' => $testType->id,
                'tested_on' => '2026-10-04',
                'score' => 92,
                'status' => 'passed',
                'attempt_no' => 1,
            ]);

            $partial = QuranPartialTest::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'juz_id' => $juz->id,
                'status' => 'passed',
                'passed_on' => '2026-10-05',
                'created_by' => $user->id,
            ]);
            $part = QuranPartialTestPart::query()->create([
                'quran_partial_test_id' => $partial->id,
                'part_number' => 1,
                'status' => 'passed',
                'passed_on' => '2026-10-05',
            ]);
            QuranPartialTestAttempt::query()->create([
                'quran_partial_test_part_id' => $part->id,
                'teacher_id' => $teacher->id,
                'tested_on' => '2026-10-05',
                'mistake_count' => 2,
                'score' => 88,
                'status' => 'passed',
                'attempt_no' => 1,
                'notes' => 'Partial attempt note',
            ]);

            $final = QuranFinalTest::query()->create([
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'juz_id' => $juz->id,
                'status' => 'passed',
                'passed_on' => '2026-10-06',
                'created_by' => $user->id,
            ]);
            QuranFinalTestAttempt::query()->create([
                'quran_final_test_id' => $final->id,
                'teacher_id' => $teacher->id,
                'tested_on' => '2026-10-06',
                'score' => 91,
                'status' => 'passed',
                'attempt_no' => 1,
                'notes' => 'Final attempt note',
            ]);
        }
        app(AccessScopeService::class)->syncUserOverrides($user, ['group' => [$visibleGroup->id]]);

        $service = app(ReportDesignerQueryService::class);
        $memorization = $service->preview([
            'data_source' => 'memorization_sessions',
            'selected_fields' => ['recorded_on', 'full_name', 'entry_type', 'pages_count', 'group_name'],
            'filters' => ['status' => 'new', 'date_from' => '2026-10-03', 'date_to' => '2026-10-03'],
            'sort_direction' => 'asc',
        ], $user);
        $tests = $service->preview([
            'data_source' => 'quran_tests',
            'selected_fields' => ['tested_on', 'full_name', 'test_type', 'juz_number', 'test_status', 'score', 'group_name'],
            'filters' => ['status' => 'passed', 'date_from' => '2026-10-04', 'date_to' => '2026-10-04'],
            'sort_direction' => 'asc',
        ], $user);
        $partialTests = $service->preview([
            'data_source' => 'quran_partial_tests',
            'selected_fields' => ['full_name', 'juz_number', 'test_status', 'passed_parts_count', 'parts_count', 'attempts_count', 'latest_score', 'latest_mistake_count', 'latest_tested_on', 'group_name'],
            'filters' => ['status' => 'passed', 'date_from' => '2026-10-05', 'date_to' => '2026-10-05'],
            'sort_direction' => 'asc',
        ], $user);
        $finalTests = $service->preview([
            'data_source' => 'quran_final_tests',
            'selected_fields' => ['full_name', 'juz_number', 'test_status', 'attempts_count', 'latest_score', 'latest_tested_on', 'passed_on', 'group_name'],
            'filters' => ['status' => 'passed', 'date_from' => '2026-10-06', 'date_to' => '2026-10-06'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([[
            'recorded_on' => '2026-10-03',
            'full_name' => 'Visible Learner',
            'entry_type' => __('report_designer.entry_types.new'),
            'pages_count' => 3,
            'group_name' => 'Visible Quran Group',
        ]], $memorization['rows']);
        $this->assertSame([[
            'tested_on' => '2026-10-04',
            'full_name' => 'Visible Learner',
            'test_type' => 'Awqaf',
            'juz_number' => 1,
            'test_status' => __('report_designer.test_statuses.passed'),
            'score' => 92.0,
            'group_name' => 'Visible Quran Group',
        ]], $tests['rows']);
        $this->assertSame([[
            'full_name' => 'Visible Learner',
            'juz_number' => 1,
            'test_status' => __('report_designer.test_statuses.passed'),
            'attempts_count' => 1,
            'latest_tested_on' => '2026-10-05',
            'latest_score' => 88.0,
            'group_name' => 'Visible Quran Group',
            'passed_parts_count' => 1,
            'parts_count' => 1,
            'latest_mistake_count' => 2,
        ]], $partialTests['rows']);
        $this->assertSame([[
            'full_name' => 'Visible Learner',
            'juz_number' => 1,
            'test_status' => __('report_designer.test_statuses.passed'),
            'attempts_count' => 1,
            'latest_tested_on' => '2026-10-06',
            'latest_score' => 91.0,
            'passed_on' => '2026-10-06',
            'group_name' => 'Visible Quran Group',
        ]], $finalTests['rows']);
    }

    public function test_preview_respects_the_users_existing_student_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-report-user']);
        $user->givePermissionTo('report-designer.view');
        $visible = Student::query()->create([
            'first_name' => 'Visible',
            'last_name' => 'Student',
            'student_number' => 'S-201',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        Student::query()->create([
            'first_name' => 'Hidden',
            'last_name' => 'Student',
            'student_number' => 'S-202',
            'birth_date' => '2014-01-02',
            'status' => 'active',
        ]);
        app(AccessScopeService::class)->syncUserOverrides($user, ['student' => [$visible->id]]);

        $definition = ReportDefinition::query()->create([
            'name' => 'Scoped students',
            'data_source' => 'students',
            'selected_fields' => ['student_number', 'full_name'],
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user);

        Volt::test('reports.designer')
            ->call('viewDefinition', $definition->id)
            ->set('selectedFields', ['student_number', 'full_name', 'password'])
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Visible Student')
            ->assertDontSee('Hidden Student');
    }

    public function test_teacher_without_designer_permission_cannot_open_the_designer(): void
    {
        $this->seed(RoleSeeder::class);

        $teacher = User::factory()->create(['username' => 'report-teacher']);
        $teacher->assignRole('teacher');

        $this->actingAs($teacher)
            ->get(route('reports.designer', absolute: false))
            ->assertForbidden();
    }
}

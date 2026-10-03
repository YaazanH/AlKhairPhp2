<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceTransaction;
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
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerQueryService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
            ->call('addCalculation')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Mariam Ahmad')
            ->assertSee(__('report_designer.calculations.record_count'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('report_definitions', [
            'name' => 'Active students',
            'data_source' => 'students',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
        ]);
        $this->assertSame(
            [['operation' => 'count', 'field' => null]],
            ReportDefinition::query()->where('name', 'Active students')->sole()->calculations,
        );
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

    public function test_saved_report_exports_reuse_approved_fields_filters_and_access_rules(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create(['username' => 'report-export-owner']);
        $owner->givePermissionTo('report-designer.view');
        $otherUser = User::factory()->create(['username' => 'report-export-other']);
        $otherUser->givePermissionTo('report-designer.view');
        $included = Student::query()->create([
            'first_name' => 'Included',
            'last_name' => 'Student',
            'student_number' => 'EXPORT-001',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $visibleStudentIds = [$included->id];
        foreach (range(2, 30) as $number) {
            $visibleStudentIds[] = Student::query()->create([
                'first_name' => 'Included '.$number,
                'last_name' => 'Student',
                'student_number' => 'EXPORT-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'birth_date' => '2014-01-01',
                'status' => 'active',
            ])->id;
        }
        $excluded = Student::query()->create([
            'first_name' => 'Excluded',
            'last_name' => 'Student',
            'student_number' => 'EXPORT-002',
            'birth_date' => '2014-01-02',
            'status' => 'inactive',
        ]);
        app(AccessScopeService::class)->syncUserOverrides($owner, ['student' => [...$visibleStudentIds, $excluded->id]]);
        $definition = ReportDefinition::query()->create([
            'name' => 'Active student export',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['student_number', 'full_name', 'status'],
            'calculations' => [['operation' => 'count', 'field' => null]],
            'group_by' => 'status',
            'filters' => ['status' => 'active', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_field' => 'student_number',
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $xlsx = $this->actingAs($owner)->get(route('reports.designer.export.xlsx', $definition, absolute: false));
        $xlsx->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'report-export-test-');
        file_put_contents($path, $xlsx->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $worksheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('Included Student', $worksheet);
        $this->assertStringContainsString('Included 30 Student', $worksheet);
        $this->assertStringNotContainsString('Excluded Student', $worksheet);

        $pdf = $this->get(route('reports.designer.export.pdf', $definition, absolute: false));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $this->actingAs($otherUser)
            ->get(route('reports.designer.export.xlsx', $definition, absolute: false))
            ->assertNotFound();
    }

    public function test_grouping_rejects_fields_outside_the_approved_dimensions(): void
    {
        $this->expectException(ValidationException::class);

        app(ReportDesignerCatalog::class)->validateGrouping(
            ReportDesignerCatalog::FINANCE_TRANSACTIONS,
            'description',
        );
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
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'sum', 'field' => 'active_enrollments_count'],
            ],
            'group_by' => 'academic_year',
            'filters' => ['status' => 'active'],
            'sort_direction' => 'asc',
        ], $administrator);
        $groups = $service->preview([
            'data_source' => 'groups',
            'selected_fields' => ['group_name', 'teacher_name', 'active_enrollments_count', 'available_places'],
            'calculations' => [
                ['operation' => 'avg', 'field' => 'capacity'],
                ['operation' => 'sum', 'field' => 'available_places'],
            ],
            'group_by' => 'teacher_name',
            'filters' => ['status' => 'active'],
            'sort_direction' => 'asc',
        ], $administrator);

        $this->assertSame([
            'course_name' => 'Quran Foundations',
            'groups_count' => 1,
            'active_enrollments_count' => 1,
        ], $courses['rows'][0]);
        $this->assertSame([1, 1.0], array_column($courses['calculations'], 'value'));
        $this->assertSame('2026/2027', $courses['grouping']['rows'][0]['group']);
        $this->assertSame(1, $courses['grouping']['rows'][0]['record_count']);
        $this->assertSame(1.0, $courses['grouping']['rows'][0]['report_calculation_1']);
        $this->assertSame([
            'group_name' => 'Morning Group',
            'teacher_name' => 'Amina Saleh',
            'active_enrollments_count' => 1,
            'available_places' => 11,
        ], $groups['rows'][0]);
        $this->assertSame([12.0, 11.0], array_column($groups['calculations'], 'value'));
        $this->assertSame('Amina Saleh', $groups['grouping']['rows'][0]['group']);
        $this->assertSame(12.0, $groups['grouping']['rows'][0]['report_calculation_0']);
        $this->assertSame(11.0, $groups['grouping']['rows'][0]['report_calculation_1']);
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

    public function test_assessment_sources_scope_shared_groups_results_and_aggregates(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-assessment-report-user']);
        $user->givePermissionTo('report-designer.view');
        $year = AcademicYear::query()->create([
            'name' => 'Assessment report year',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Assessment',
            'last_name' => 'Teacher',
            'phone' => '0900000005',
            'status' => 'active',
        ]);
        $course = Course::query()->create(['academic_year_id' => $year->id, 'name' => 'Assessment Course', 'is_active' => true]);
        $groupAttributes = ['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'capacity' => 10, 'is_active' => true];
        $visibleGroup = Group::query()->create($groupAttributes + ['name' => 'Visible Assessment Group']);
        $hiddenGroup = Group::query()->create($groupAttributes + ['name' => 'Hidden Assessment Group']);
        $visibleStudent = Student::query()->create(['first_name' => 'Visible', 'last_name' => 'Candidate', 'student_number' => 'S-701', 'birth_date' => '2014-01-01', 'status' => 'active']);
        $hiddenStudent = Student::query()->create(['first_name' => 'Hidden', 'last_name' => 'Candidate', 'student_number' => 'S-702', 'birth_date' => '2014-01-02', 'status' => 'active']);
        $visibleEnrollment = Enrollment::query()->create(['student_id' => $visibleStudent->id, 'group_id' => $visibleGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        $hiddenEnrollment = Enrollment::query()->create(['student_id' => $hiddenStudent->id, 'group_id' => $hiddenGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        $type = AssessmentType::query()->create(['name' => 'Quiz', 'code' => 'designer-quiz', 'is_scored' => true, 'is_active' => true]);
        $assessment = Assessment::query()->create([
            'group_id' => $visibleGroup->id,
            'group_scope' => 'multiple',
            'assessment_type_id' => $type->id,
            'title' => 'Shared Monthly Quiz',
            'due_at' => '2026-10-07 10:00:00',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $assessment->groups()->sync([$visibleGroup->id, $hiddenGroup->id]);
        AssessmentResult::query()->create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $visibleEnrollment->id,
            'student_id' => $visibleStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 85,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);
        AssessmentResult::query()->create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $hiddenEnrollment->id,
            'student_id' => $hiddenStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 40,
            'status' => 'failed',
            'attempt_no' => 1,
        ]);
        app(AccessScopeService::class)->syncUserOverrides($user, ['group' => [$visibleGroup->id]]);

        $service = app(ReportDesignerQueryService::class);
        $assessments = $service->preview([
            'data_source' => 'assessments',
            'selected_fields' => ['assessment_title', 'assessment_groups', 'results_count', 'passed_results_count', 'failed_results_count', 'average_score'],
            'filters' => ['status' => 'active', 'date_from' => '2026-10-07', 'date_to' => '2026-10-07'],
            'sort_direction' => 'asc',
        ], $user);
        $results = $service->preview([
            'data_source' => 'assessment_results',
            'selected_fields' => ['due_at', 'assessment_title', 'full_name', 'score', 'result_status', 'group_name'],
            'filters' => ['status' => 'passed', 'date_from' => '2026-10-07', 'date_to' => '2026-10-07'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([[
            'assessment_title' => 'Shared Monthly Quiz',
            'assessment_groups' => 'Visible Assessment Group',
            'results_count' => 1,
            'passed_results_count' => 1,
            'failed_results_count' => 0,
            'average_score' => 85.0,
        ]], $assessments['rows']);
        $this->assertSame([[
            'due_at' => '2026-10-07 10:00',
            'assessment_title' => 'Shared Monthly Quiz',
            'full_name' => 'Visible Candidate',
            'score' => 85.0,
            'result_status' => __('report_designer.assessment_statuses.passed'),
            'group_name' => 'Visible Assessment Group',
        ]], $results['rows']);
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

    public function test_teacher_workload_preview_respects_teacher_scope_and_combines_primary_and_assisted_groups(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'scoped-teacher-report-user']);
        $user->givePermissionTo('report-designer.view');
        $year = AcademicYear::query()->create([
            'name' => 'Teacher workload year',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'academic_year_id' => $year->id,
            'name' => 'Teacher Workload Course',
            'is_active' => true,
        ]);
        $visibleTeacher = Teacher::query()->create([
            'first_name' => 'Visible',
            'last_name' => 'Teacher',
            'phone' => '0900000006',
            'job_title' => 'Quran Instructor',
            'status' => 'active',
            'is_helping' => true,
            'hired_at' => '2026-09-15',
        ]);
        $hiddenTeacher = Teacher::query()->create([
            'first_name' => 'Hidden',
            'last_name' => 'Teacher',
            'phone' => '0900000007',
            'status' => 'active',
        ]);
        $primaryGroup = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $visibleTeacher->id,
            'name' => 'Primary Group',
            'capacity' => 10,
            'is_active' => true,
        ]);
        $assistedGroup = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $hiddenTeacher->id,
            'assistant_teacher_id' => $visibleTeacher->id,
            'name' => 'Assisted Group',
            'capacity' => 10,
            'is_active' => true,
        ]);
        $primaryStudent = Student::query()->create([
            'first_name' => 'Primary',
            'last_name' => 'Student',
            'student_number' => 'S-801',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $assistedStudent = Student::query()->create([
            'first_name' => 'Assisted',
            'last_name' => 'Student',
            'student_number' => 'S-802',
            'birth_date' => '2014-01-02',
            'status' => 'active',
        ]);
        Enrollment::query()->create([
            'student_id' => $primaryStudent->id,
            'group_id' => $primaryGroup->id,
            'enrolled_at' => '2026-09-20',
            'status' => 'active',
        ]);
        Enrollment::query()->create([
            'student_id' => $assistedStudent->id,
            'group_id' => $assistedGroup->id,
            'enrolled_at' => '2026-09-20',
            'status' => 'active',
        ]);
        app(AccessScopeService::class)->syncUserOverrides($user, ['teacher' => [$visibleTeacher->id]]);

        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'teachers',
            'selected_fields' => [
                'full_name',
                'teacher_status',
                'job_title',
                'hired_at',
                'is_helping',
                'assigned_groups_count',
                'assisted_groups_count',
                'active_groups_count',
                'active_enrollments_count',
                'assigned_groups',
                'assigned_courses',
            ],
            'filters' => ['status' => 'active', 'date_from' => '2026-09-15', 'date_to' => '2026-09-15'],
            'sort_field' => 'full_name',
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([[
            'full_name' => 'Visible Teacher',
            'teacher_status' => __('report_designer.teacher_statuses.active'),
            'job_title' => 'Quran Instructor',
            'hired_at' => '2026-09-15',
            'is_helping' => __('report_designer.helping_statuses.yes'),
            'assigned_groups_count' => 1,
            'assisted_groups_count' => 1,
            'active_groups_count' => 2,
            'active_enrollments_count' => 2,
            'assigned_groups' => 'Primary Group, Assisted Group',
            'assigned_courses' => 'Teacher Workload Course',
        ]], $preview['rows']);
    }

    public function test_finance_transaction_source_requires_finance_permission_and_reads_the_ledger(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['name' => 'Finance Reporter', 'username' => 'finance-report-user']);
        $user->givePermissionTo('report-designer.view');
        $catalog = app(ReportDesignerCatalog::class);
        $this->assertArrayNotHasKey('finance_transactions', $catalog->sources($user));
        ReportDefinition::query()->create([
            'name' => 'Sensitive finance draft',
            'data_source' => 'finance_transactions',
            'selected_fields' => ['transaction_number', 'amount'],
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->actingAs($user)
            ->get(route('reports.designer', absolute: false))
            ->assertOk()
            ->assertDontSee('Sensitive finance draft');

        $user->givePermissionTo('finance.reports.view');
        $this->assertArrayHasKey('finance_transactions', $catalog->sources($user));
        $this->get(route('reports.designer', absolute: false))
            ->assertOk()
            ->assertSee('Sensitive finance draft');

        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = FinanceCurrency::query()->where('is_local', true)->firstOrFail();
        $category = FinanceCategory::query()->firstOrCreate(
            ['code' => 'designer-donation'],
            ['name' => 'General donations', 'type' => 'income', 'mode' => 'donation', 'is_donation' => true, 'is_active' => true],
        );
        FinanceTransaction::query()->create([
            'transaction_no' => 'TX-DESIGNER-001',
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $category->id,
            'type' => 'income',
            'direction' => 'in',
            'amount' => 500,
            'signed_amount' => 500,
            'rate_to_base' => 1,
            'base_amount' => 500,
            'local_amount' => 500,
            'transaction_date' => '2026-10-02',
            'description' => 'Friday donation',
            'entered_by' => $user->id,
        ]);
        FinanceTransaction::query()->create([
            'transaction_no' => 'TX-DESIGNER-002',
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $category->id,
            'type' => 'expense',
            'direction' => 'out',
            'amount' => 100,
            'signed_amount' => -100,
            'rate_to_base' => 1,
            'base_amount' => -100,
            'local_amount' => -100,
            'transaction_date' => '2026-10-03',
            'description' => 'Hidden by income filter',
            'entered_by' => $user->id,
        ]);

        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'finance_transactions',
            'selected_fields' => [
                'transaction_date',
                'transaction_number',
                'transaction_type',
                'transaction_direction',
                'finance_category',
                'cash_box',
                'currency',
                'amount',
                'signed_amount',
                'local_amount',
                'entered_by',
                'description',
            ],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'sum', 'field' => 'amount'],
                ['operation' => 'avg', 'field' => 'local_amount'],
                ['operation' => 'max', 'field' => 'signed_amount'],
            ],
            'group_by' => 'finance_category',
            'filters' => [
                'status' => 'income',
                'search' => 'donation',
                'date_from' => '2026-10-02',
                'date_to' => '2026-10-02',
            ],
            'sort_field' => 'transaction_date',
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([[
            'transaction_date' => '2026-10-02',
            'transaction_number' => 'TX-DESIGNER-001',
            'transaction_type' => __('finance.transaction_types.income'),
            'transaction_direction' => __('report_designer.finance_directions.in'),
            'finance_category' => 'General donations',
            'cash_box' => $cashBox->name,
            'currency' => $currency->code,
            'amount' => 500.0,
            'signed_amount' => 500.0,
            'local_amount' => 500.0,
            'entered_by' => 'Finance Reporter',
            'description' => 'Friday donation',
        ]], $preview['rows']);
        $this->assertSame([
            ['label' => __('report_designer.calculations.record_count'), 'value' => 1],
            ['label' => __('report_designer.calculations.field', [
                'operation' => __('report_designer.calculation_operations.sum'),
                'field' => __('report_designer.fields.amount'),
            ]), 'value' => 500.0],
            ['label' => __('report_designer.calculations.field', [
                'operation' => __('report_designer.calculation_operations.avg'),
                'field' => __('report_designer.fields.local_amount'),
            ]), 'value' => 500.0],
            ['label' => __('report_designer.calculations.field', [
                'operation' => __('report_designer.calculation_operations.max'),
                'field' => __('report_designer.fields.signed_amount'),
            ]), 'value' => 500.0],
        ], $preview['calculations']);
        $this->assertSame([
            'label' => __('report_designer.fields.finance_category'),
            'columns' => [
                'group' => __('report_designer.grouping.group'),
                'record_count' => __('report_designer.calculations.record_count'),
                'report_calculation_1' => __('report_designer.calculations.field', [
                    'operation' => __('report_designer.calculation_operations.sum'),
                    'field' => __('report_designer.fields.amount'),
                ]),
                'report_calculation_2' => __('report_designer.calculations.field', [
                    'operation' => __('report_designer.calculation_operations.avg'),
                    'field' => __('report_designer.fields.local_amount'),
                ]),
                'report_calculation_3' => __('report_designer.calculations.field', [
                    'operation' => __('report_designer.calculation_operations.max'),
                    'field' => __('report_designer.fields.signed_amount'),
                ]),
            ],
            'rows' => [[
                'group' => 'General donations',
                'record_count' => 1,
                'report_calculation_1' => 500.0,
                'report_calculation_2' => 500.0,
                'report_calculation_3' => 500.0,
            ]],
            'limit' => ReportDesignerQueryService::GROUP_PREVIEW_LIMIT,
        ], $preview['grouping']);
    }

    public function test_calculations_use_every_filtered_record_beyond_the_preview_limit(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['username' => 'full-calculation-report-user']);
        $user->givePermissionTo(['report-designer.view', 'finance.reports.view']);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = FinanceCurrency::query()->where('is_local', true)->firstOrFail();

        foreach (range(1, 30) as $number) {
            FinanceTransaction::query()->create([
                'transaction_no' => sprintf('TX-BULK-%03d', $number),
                'cash_box_id' => $cashBox->id,
                'currency_id' => $currency->id,
                'type' => 'income',
                'direction' => 'in',
                'amount' => $number,
                'signed_amount' => $number,
                'rate_to_base' => 1,
                'base_amount' => $number,
                'local_amount' => $number,
                'transaction_date' => '2026-10-03',
                'description' => 'bulk-calc',
                'entered_by' => $user->id,
            ]);
        }

        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'finance_transactions',
            'selected_fields' => ['transaction_number', 'amount'],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'sum', 'field' => 'amount'],
            ],
            'group_by' => 'transaction_type',
            'filters' => ['status' => 'income', 'search' => 'bulk-calc'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertCount(ReportDesignerQueryService::PREVIEW_LIMIT, $preview['rows']);
        $this->assertSame(30, $preview['total']);
        $this->assertSame(30, $preview['calculations'][0]['value']);
        $this->assertSame(465.0, $preview['calculations'][1]['value']);
        $this->assertSame([[
            'group' => __('finance.transaction_types.income'),
            'record_count' => 30,
            'report_calculation_1' => 465.0,
        ]], $preview['grouping']['rows']);
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

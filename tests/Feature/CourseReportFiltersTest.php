<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\CourseLifecycleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourseReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->teacher = Teacher::create(['first_name' => 'Report', 'last_name' => 'Teacher', 'phone' => '0944000401', 'status' => 'active']);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);
        $this->year = AcademicYear::create(['name' => 'Current year', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'is_current' => true, 'is_active' => true]);
    }

    public function test_courses_are_included_by_default_in_the_database_and_model(): void
    {
        $id = DB::table('courses')->insertGetId(['name' => 'Existing archived course', 'is_active' => false]);
        $this->assertTrue(Course::findOrFail($id)->show_in_report_filters);
        $this->assertTrue((new Course)->show_in_report_filters);
    }

    public function test_archive_toggle_persists_without_changing_course_lifecycle_or_closing_the_popup(): void
    {
        $year = AcademicYear::create(['name' => 'Closed year', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_active' => false]);
        $course = Course::create(['academic_year_id' => $year->id, 'name' => 'Archived', 'is_active' => false, 'finished_at' => '2026-08-31 12:00:00']);
        $original = $course->fresh()->only(['is_active', 'finished_at', 'awards_points', 'academic_year_id']);

        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSee('data-course-report-filter-toggle', false)
            ->assertSee('data-icon-name="filter-exclude"', false)
            ->call('toggleReportFilterVisibility', $course->id)
            ->assertSet('showArchiveModal', true)
            ->assertSee('data-icon-name="filter-include"', false)
            ->assertViewHas('archivedCourse', fn (Course $archived) => ! $archived->show_in_report_filters);

        $this->assertFalse($course->fresh()->show_in_report_filters);
        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSee('data-icon-name="filter-include"', false)
            ->call('toggleReportFilterVisibility', $course->id)
            ->assertSee('data-icon-name="filter-exclude"', false);

        $this->assertTrue($course->fresh()->show_in_report_filters);
        $this->assertEquals($original, $course->fresh()->only(array_keys($original)));
    }

    public function test_inactive_course_archive_has_a_read_only_overview_beside_copy(): void
    {
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Archived overview course',
            'is_active' => false,
            'finished_at' => '2026-08-31 12:00:00',
        ]);

        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSeeInOrder(['data-course-archive-view-action', 'data-course-archive-copy-action'], false)
            ->assertSee('data-open-action', false)
            ->assertSee(route('courses.archive-overview', $course), false)
            ->assertSee('hidden xl:inline-flex', false)
            ->assertSee('data-icon-name="open"', false);

        $this->get(route('courses.archive-overview', $course))
            ->assertOk()
            ->assertSee('data-course-archive-overview', false)
            ->assertSee('data-read-only="true"', false)
            ->assertSee(__('course_archive_overview.archive_name', ['course' => $course->name]))
            ->assertSee('data-course-archive-date-range', false)
            ->assertSee('data-course-archive-finished-date', false)
            ->assertSee('data-course-archive-metrics', false)
            ->assertSeeInOrder([
                __('course_archive_overview.sections.groups'),
                __('course_archive_overview.sections.attendance'),
                __('course_archive_overview.sections.assessments'),
                __('course_archive_overview.sections.points'),
                __('course_archive_overview.sections.end'),
            ])
            ->assertDontSee('data-course-archive-section="notes"', false);

        $archiveSource = file_get_contents(resource_path('views/livewire/courses/archive-overview.blade.php'));
        $this->assertStringNotContainsString('StudentNote::query()', $archiveSource);
        $this->assertStringNotContainsString("section === 'notes'", $archiveSource);

        $overview = Volt::test('courses.archive-overview', ['course' => $course]);

        foreach (['groups', 'assessments', 'points', 'end'] as $section) {
            $overview
                ->call('showSection', $section)
                ->assertSet('section', $section)
                ->assertSee('data-course-archive-real-component="'.$section.'"', false);
        }

        Volt::test('assessments.index', [
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])
            ->assertSet('courseFilter', (string) $course->id)
            ->assertSee('data-archive-course-subtitle', false)
            ->assertSee($course->name)
            ->assertDontSee('assessment-course-filter', false);

        $overview
            ->call('showSection', 'end')
            ->assertSee('data-course-archive-completion-settings', false)
            ->assertSeeInOrder(['data-archive-settings-condition', 'data-archive-settings-grade-scope'], false)
            ->assertSee('data-course-archive-settings-summary', false)
            ->assertSee(__('course_archive_overview.completion_settings.title'));

        $archiveSource = file_get_contents(resource_path('views/livewire/courses/archive-overview.blade.php'));
        $completionSettingsSource = file_get_contents(resource_path('views/livewire/courses/partials/archive-completion-settings.blade.php'));
        $this->assertStringContainsString('grid-template-columns: minmax(7rem, .7fr) minmax(0, 2fr)', $archiveSource);
        $this->assertStringContainsString('direction: rtl; grid-column: 1;', $archiveSource);
        $this->assertStringContainsString('direction: rtl; grid-column: 2;', $archiveSource);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $archiveSource);
        $this->assertStringNotContainsString('completion_settings.read_only', $archiveSource);
        $this->assertStringNotContainsString('completion_settings.read_only', $completionSettingsSource);

        $overview
            ->call('showSection', 'attendance')
            ->assertSee('data-course-archive-real-component="attendance"', false)
            ->assertDontSee('data-course-archive-legacy-view', false);
    }

    public function test_course_archive_opens_with_groups_and_preserves_the_historical_roster(): void
    {
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Historical course',
            'is_active' => false,
            'finished_at' => '2026-08-31 12:00:00',
        ]);
        $group = Group::create([
            'academic_year_id' => $this->year->id,
            'course_id' => $course->id,
            'teacher_id' => $this->teacher->id,
            'name' => 'Historical group',
            'is_active' => false,
            'course_finished_at' => '2026-08-31 12:00:00',
        ]);
        $student = Student::create([
            'first_name' => 'Historical',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'inactive',
        ]);
        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-01-10',
            'status' => 'completed',
            'course_finished_at' => '2026-08-31 12:00:00',
        ]);
        $otherCourse = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Other historical course',
            'is_active' => false,
            'finished_at' => '2026-08-31 12:00:00',
        ]);
        $otherGroup = Group::create([
            'academic_year_id' => $this->year->id,
            'course_id' => $otherCourse->id,
            'teacher_id' => $this->teacher->id,
            'name' => 'Other historical group',
            'is_active' => false,
            'course_finished_at' => '2026-08-31 12:00:00',
        ]);

        $archivedGroupRoute = route('courses.archive-overview.groups.show', [$course, $group]);
        $archivedRosterPdfRoute = route('groups.roster.pdf', [
            'group' => $group,
            'archive_course_id' => $course->id,
        ]);

        Volt::test('courses.archive-overview', ['course' => $course])
            ->assertSet('section', 'groups')
            ->assertSee('data-course-archive-groups-table', false)
            ->assertSee('data-archive-groups-table-layout', false)
            ->assertSee('<colgroup><col style="width:4%"><col style="width:18%"><col style="width:18%"><col style="width:18%"><col style="width:9.56%"><col style="width:5.44%"><col style="width:15%"><col style="width:12%"></colgroup>', false)
            ->assertSee('data-group-curriculum-status', false)
            ->assertSee('group-curriculum-status--missing', false)
            ->assertSee('Historical group')
            ->assertSee($archivedGroupRoute, false)
            ->assertDontSee('wire:click="openArchivedGroup', false)
            ->assertDontSee('data-course-archive-group-detail', false);

        $this->get($archivedGroupRoute)
            ->assertOk()
            ->assertSee('data-archived-group-native-view', false)
            ->assertSee('data-group-roster-table', false)
            ->assertSee('Historical Student')
            ->assertSee(__('crud.common.status_options.completed'))
            ->assertSee('data-archive-course-subtitle', false)
            ->assertSee($course->name)
            ->assertSee(route('courses.archive-overview', ['course' => $course, 'section' => 'groups']), false)
            ->assertDontSee('data-group-hero-schedule-action', false)
            ->assertDontSee('data-group-hero-edit-action', false)
            ->assertSee('data-group-roster-pdf-action', false)
            ->assertSee($archivedRosterPdfRoute, false);

        $this->get($archivedRosterPdfRoute)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('groups.roster.pdf', $group))->assertNotFound();

        $this->get(route('courses.archive-overview.groups.show', [$course, $otherGroup]))->assertNotFound();
        $this->get(route('groups.roster.pdf', [
            'group' => $otherGroup,
            'archive_course_id' => $course->id,
        ]))->assertNotFound();
    }

    public function test_inactive_or_finished_groups_are_not_accessible_from_the_live_groups_workspace(): void
    {
        $activeCourse = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Active course',
            'is_active' => true,
        ]);
        $archivedCourse = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Archived course',
            'is_active' => false,
            'finished_at' => '2026-08-31 12:00:00',
        ]);
        $groupDefaults = ['academic_year_id' => $this->year->id, 'teacher_id' => $this->teacher->id];
        $activeGroup = Group::create([...$groupDefaults, 'course_id' => $activeCourse->id, 'name' => 'Open group', 'is_active' => true]);
        $inactiveGroup = Group::create([...$groupDefaults, 'course_id' => $activeCourse->id, 'name' => 'Inactive group', 'is_active' => false]);
        $finishedGroup = Group::create([...$groupDefaults, 'course_id' => $activeCourse->id, 'name' => 'Finished group', 'is_active' => true, 'course_finished_at' => '2026-08-31 12:00:00']);
        $archivedCourseGroup = Group::create([...$groupDefaults, 'course_id' => $archivedCourse->id, 'name' => 'Archived course group', 'is_active' => true]);

        $this->get(route('groups.show', $activeGroup))->assertOk();
        $this->get(route('groups.show', $inactiveGroup))->assertNotFound();
        $this->get(route('groups.schedules', $finishedGroup))->assertNotFound();
        $this->get(route('groups.attendance', $archivedCourseGroup))->assertNotFound();
        $this->get(route('groups.roster.export', $inactiveGroup))->assertNotFound();
        $this->get(route('groups.roster.pdf', $inactiveGroup))->assertNotFound();

        Volt::test('groups.index')
            ->set('statusFilter', 'inactive')
            ->assertSee('Inactive group')
            ->assertDontSee(route('groups.show', $inactiveGroup), false);
    }

    public function test_archive_tabs_hide_lifecycle_statuses_and_voided_points(): void
    {
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Clean archive course',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'is_active' => false,
            'finished_at' => '2026-09-30 12:00:00',
        ]);
        $group = Group::create([
            'teacher_id' => $this->teacher->id,
            'academic_year_id' => $this->year->id,
            'course_id' => $course->id,
            'name' => 'Clean archive group',
            'is_active' => false,
        ]);
        $student = Student::create([
            'first_name' => 'Archive',
            'last_name' => 'Points Student',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'completed',
        ]);
        $retainedType = PointType::create([
            'name' => 'Retained archive points',
            'code' => 'retained-archive-points',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);
        $voidedType = PointType::create([
            'name' => 'Deleted archive points',
            'code' => 'deleted-archive-points',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        foreach ([[$retainedType, null], [$voidedType, '2026-09-29 12:00:00']] as [$type, $voidedAt]) {
            PointTransaction::create([
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'point_type_id' => $type->id,
                'source_type' => 'manual',
                'points' => 5,
                'entered_at' => '2026-09-20 12:00:00',
                'voided_at' => $voidedAt,
                'void_reason' => $voidedAt ? 'Deleted before archive' : null,
            ]);
        }

        StudentAttendanceDay::create([
            'course_id' => $course->id,
            'attendance_date' => '2026-09-20',
            'status' => 'closed',
        ]);

        Volt::test('points.index', [
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])
            ->assertSee('Retained archive points')
            ->assertDontSee('Deleted archive points')
            ->assertDontSee('id="points-state-filter"', false)
            ->assertDontSee('data-points-ledger-state=', false)
            ->assertDontSee(__('workflow.common.ledger_state.inactive_source'))
            ->assertSee('data-has-void-reason="false"', false)
            ->assertSee('data-shows-state="false"', false);

        Volt::test('attendance.index', [
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])
            ->assertSee('data-archive-course-subtitle', false)
            ->assertSee($course->name)
            ->assertDontSee('id="student-attendance-status"', false)
            ->assertDontSee('attendance-days-status', false);

        Volt::test('assessments.index', [
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])->assertDontSee('id="assessment-status-filter"', false);

        Volt::test('courses.archive-overview', ['course' => $course])
            ->assertSeeInOrder([
                'data-course-archive-start-date',
                '01-09-2026',
                'archive-course-date-range__arrow',
                'data-course-archive-end-date',
                '30-09-2026',
            ], false);

        $archiveSource = file_get_contents(resource_path('views/livewire/courses/archive-overview.blade.php'));
        $this->assertStringContainsString('.archive-course-date-range__values bdi { margin-top: 0; }', $archiveSource);
        $this->assertStringContainsString('align-self: center; line-height: 1;', $archiveSource);
    }

    public function test_archive_completion_settings_use_the_snapshot_saved_when_the_course_finished(): void
    {
        AppSetting::storeValue('course_completion', 'required_memorized_pages', 80, 'integer');
        AppSetting::storeValue('course_completion', 'retain_percentage', 40, 'integer');
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Snapshotted completion settings',
            'is_active' => true,
        ]);

        app(CourseLifecycleService::class)->finish($course);
        AppSetting::storeValue('course_completion', 'required_memorized_pages', 5, 'integer');
        AppSetting::storeValue('course_completion', 'retain_percentage', 90, 'integer');

        Volt::test('courses.archive-overview', ['course' => $course->fresh()])
            ->call('showSection', 'end')
            ->assertViewHas('completionSettings', fn (array $settings) => $settings['required_memorized_pages'] === 80
                && $settings['retain_percentage'] === 40);
    }

    public function test_archive_attendance_average_is_students_present_per_day_and_rows_open_read_only_details(): void
    {
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Attendance archive',
            'is_active' => false,
            'finished_at' => '2026-09-30 12:00:00',
        ]);
        $group = Group::create([
            'teacher_id' => $this->teacher->id,
            'academic_year_id' => $this->year->id,
            'course_id' => $course->id,
            'name' => 'Archive group',
            'is_active' => false,
        ]);
        $present = AttendanceStatus::create([
            'name' => 'Present in archive',
            'code' => 'archive-present',
            'scope' => 'student',
            'is_present' => true,
            'is_active' => true,
        ]);
        $enrollments = collect(['First', 'Second', 'Third'])->map(function (string $firstName) use ($group) {
            $student = Student::create([
                'first_name' => $firstName,
                'last_name' => 'Archived student',
                'birth_date' => '2013-01-01',
                'status' => 'active',
            ]);

            return Enrollment::create([
                'student_id' => $student->id,
                'group_id' => $group->id,
                'enrolled_at' => '2026-09-01',
                'status' => 'completed',
            ]);
        });

        foreach ([['2026-09-20', 2], ['2026-09-21', 3]] as [$date, $presentCount]) {
            $day = StudentAttendanceDay::create([
                'course_id' => $course->id,
                'attendance_date' => $date,
                'status' => 'closed',
            ]);
            $groupDay = GroupAttendanceDay::create([
                'group_id' => $group->id,
                'student_attendance_day_id' => $day->id,
                'attendance_date' => $date,
                'status' => 'closed',
            ]);

            foreach ($enrollments->take($presentCount) as $enrollment) {
                StudentAttendanceRecord::create([
                    'group_attendance_day_id' => $groupDay->id,
                    'enrollment_id' => $enrollment->id,
                    'attendance_status_id' => $present->id,
                ]);
            }
        }

        $latestDay = StudentAttendanceDay::query()->latest('attendance_date')->firstOrFail();
        $latestGroupDay = $latestDay->groupAttendanceDays()->firstOrFail();
        $teacherDay = TeacherAttendanceDay::create([
            'attendance_date' => '2026-09-21',
            'status' => 'closed',
        ]);
        TeacherAttendanceRecord::create([
            'teacher_attendance_day_id' => $teacherDay->id,
            'teacher_id' => $this->teacher->id,
            'archived_course_id' => $course->id,
            'course_finished_at' => $course->finished_at,
            'attendance_status_id' => $present->id,
        ]);

        $archivedAttendance = Volt::test('attendance.index', [
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])
            ->assertSet('export_course_id', (string) $course->id)
            ->assertSet('export_date_from', '2026-09-20')
            ->assertSet('export_date_to', '2026-09-21')
            ->assertSee('data-export-action', false)
            ->assertSee($course->name)
            ->call('openExportModal')
            ->assertSet('showExportModal', true)
            ->assertSee('data-archive-attendance-export-course', false);

        $archivedAttendance
            ->call('switchExportType', 'teachers')
            ->assertSee('teacher-attendance/export/pdf', false)
            ->call('switchExportType', 'students')
            ->assertSee('student-attendance/export/pdf', false);

        $this->get(route('student-attendance.export', [
            'course_id' => $course->id,
            'date_from' => '2026-09-20',
            'date_to' => '2026-09-21',
        ]))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->get(route('teacher-attendance.export', [
            'course_id' => $course->id,
            'date_from' => '2026-09-20',
            'date_to' => '2026-09-21',
        ]))->assertOk()->assertHeader('content-type', 'application/pdf');

        Volt::test('courses.archive-overview', ['course' => $course])
            ->assertViewHas('metrics', fn (array $metrics) => collect($metrics)
                ->firstWhere('label', __('dashboard.manager.stats.average_daily_attendance.label'))['value'] === 3)
            ->assertViewHas('metrics', fn (array $metrics) => collect($metrics)
                ->firstWhere('label', __('dashboard.manager.stats.total_points.label'))['value'] === 0)
            ->assertViewHas('metrics', fn (array $metrics) => collect($metrics)
                ->firstWhere('label', __('course_end.highlights.points_after'))['value'] === 600)
            ->assertSee('data-course-archive-real-component="groups"', false);

        Volt::test('student-attendance.show', [
            'studentAttendanceDay' => $latestDay,
            'unified' => true,
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])
            ->assertSee('Archive group')
            ->assertSee('data-archive-course-subtitle', false)
            ->assertSee($course->name)
            ->assertDontSee('data-student-attendance-day-delete-action', false);

        $this->get(route('attendance.show', [
            'type' => 'students',
            'day' => $latestDay,
            'archive_course_id' => $course->id,
        ]))
            ->assertOk()
            ->assertSee('Archive group');

        $this->get(route('student-attendance.mark', [
            'groupAttendanceDay' => $latestGroupDay,
            'archive_course_id' => $course->id,
        ]))
            ->assertOk()
            ->assertSee('First Archived student')
            ->assertSee('Present in archive');

        Volt::test('teachers.attendance-show', [
            'teacherAttendanceDay' => $teacherDay,
            'unified' => true,
            'archiveCourseId' => $course->id,
            'archiveReadOnly' => true,
        ])
            ->assertSee('Report Teacher')
            ->assertSee('data-archive-course-subtitle', false)
            ->assertSee($course->name)
            ->assertDontSee('data-teacher-attendance-day-delete-action', false);

        foreach ([
            'livewire/assessments/results.blade.php',
            'livewire/attendance/show.blade.php',
        ] as $view) {
            $this->assertStringContainsString('data-archive-course-subtitle', file_get_contents(resource_path('views/'.$view)));
        }
    }

    public function test_active_course_cannot_open_the_archived_course_overview(): void
    {
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Active course',
            'is_active' => true,
        ]);

        $this->get(route('courses.archive-overview', $course))->assertNotFound();
    }

    public function test_view_only_user_cannot_toggle_report_visibility(): void
    {
        $course = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Archived', 'is_active' => false]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('courses.view');
        $this->actingAs($viewer);

        Volt::test('courses.index')->call('toggleReportFilterVisibility', $course->id)->assertForbidden();
        $this->assertTrue($course->fresh()->show_in_report_filters);
    }

    public function test_active_courses_cannot_be_excluded_through_the_archive_toggle(): void
    {
        $course = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Active', 'is_active' => true]);
        Volt::test('courses.index')->call('toggleReportFilterVisibility', $course->id)->assertStatus(409);
        $this->assertTrue($course->fresh()->show_in_report_filters);
    }

    public static function reportPages(): array
    {
        return array_map(fn ($page) => [$page], ['reports.index', 'reports.rankings', 'reports.student-activity-summary', 'reports.student-quran-tests']);
    }

    #[DataProvider('reportPages')]
    public function test_report_filters_include_active_and_enabled_archived_courses_with_their_groups(string $page): void
    {
        $active = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Active', 'is_active' => true, 'show_in_report_filters' => false]);
        $included = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Included archive', 'is_active' => false]);
        $excluded = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Excluded archive', 'is_active' => false, 'show_in_report_filters' => false]);
        $deleted = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Deleted archive', 'is_active' => false]);
        $deleted->delete();
        $activeGroup = Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $active->id, 'name' => 'Active group', 'is_active' => true]);
        $archivedGroup = Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $included->id, 'name' => 'Archived group', 'is_active' => false]);
        Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $excluded->id, 'name' => 'Excluded group', 'is_active' => true]);
        Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $active->id, 'name' => 'Independently inactive group', 'is_active' => false]);

        Volt::test($page)
            ->assertViewHas('courses', fn ($courses) => $courses->modelKeys() === [$active->id, $included->id])
            ->assertViewHas('groups', fn ($groups) => $groups->modelKeys() === [$activeGroup->id, $archivedGroup->id])
            ->set('course_id', $included->id)
            ->assertViewHas('groups', fn ($groups) => $groups->modelKeys() === [$archivedGroup->id]);

        $included->update(['show_in_report_filters' => false]);
        Volt::test($page)
            ->assertViewHas('courses', fn ($courses) => $courses->modelKeys() === [$active->id])
            ->assertViewHas('groups', fn ($groups) => $groups->modelKeys() === [$activeGroup->id]);
    }

    public function test_student_ranking_group_filter_honours_archived_course_visibility(): void
    {
        $shown = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Included archive', 'is_active' => false]);
        $hidden = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Excluded archive', 'is_active' => false, 'show_in_report_filters' => false]);
        $group = Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $shown->id, 'name' => 'Shown group', 'is_active' => false]);
        Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $hidden->id, 'name' => 'Hidden group', 'is_active' => true]);
        Volt::test('reports.students-ranking')->assertViewHas('groups', fn ($groups) => $groups->modelKeys() === [$group->id]);
    }

    public function test_course_list_orders_by_course_dates_then_enrollment_count_newest_first(): void
    {
        $older = Course::create(['academic_year_id' => $this->year->id, 'name' => 'A older', 'starts_on' => '2025-09-01', 'ends_on' => '2027-08-31']);
        $moreEnrolled = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Z latest more enrolled', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $lessEnrolled = Course::create(['academic_year_id' => $this->year->id, 'name' => 'A latest less enrolled', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $undated = Course::create(['academic_year_id' => $this->year->id, 'name' => 'C undated']);

        $moreGroup = Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $moreEnrolled->id, 'name' => 'More enrolled group', 'is_active' => true]);
        $lessGroup = Group::create(['teacher_id' => $this->teacher->id, 'academic_year_id' => $this->year->id, 'course_id' => $lessEnrolled->id, 'name' => 'Less enrolled group', 'is_active' => true]);
        $students = collect(['First', 'Second', 'Third'])->map(fn (string $name) => Student::create(['first_name' => $name, 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']));
        Enrollment::create(['student_id' => $students[0]->id, 'group_id' => $moreGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        Enrollment::create(['student_id' => $students[1]->id, 'group_id' => $moreGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);
        Enrollment::create(['student_id' => $students[2]->id, 'group_id' => $lessGroup->id, 'enrolled_at' => '2026-09-01', 'status' => 'active']);

        // Create years out of chronological order and give their courses
        // conflicting dates so course-date precedence is observable.
        $newestYear = AcademicYear::create(['name' => 'Newest year', 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31', 'is_current' => false, 'is_active' => true]);
        $previousYear = AcademicYear::create(['name' => 'Previous year', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_current' => false, 'is_active' => false]);
        $newestYearCourse = Course::create(['academic_year_id' => $newestYear->id, 'name' => 'Newest year course', 'starts_on' => '2024-09-01', 'ends_on' => '2024-12-31']);
        $previousYearCourse = Course::create(['academic_year_id' => $previousYear->id, 'name' => 'Previous year course', 'starts_on' => '2030-09-01', 'ends_on' => '2030-12-31', 'is_active' => false]);
        $unassigned = Course::create(['name' => 'No academic year', 'starts_on' => '2031-09-01', 'ends_on' => '2031-12-31']);

        Volt::test('courses.index')
            ->assertViewHas('courses', fn ($courses) => $courses->getCollection()->modelKeys() === [$moreEnrolled->id, $lessEnrolled->id, $older->id, $undated->id])
            ->set('statusFilter', 'all')
            ->set('academicYearFilter', 'all')
            ->assertViewHas('courses', fn ($courses) => $courses->getCollection()->modelKeys() === [$unassigned->id, $previousYearCourse->id, $moreEnrolled->id, $lessEnrolled->id, $older->id, $newestYearCourse->id, $undated->id])
            ->set('search', 'enrolled')
            ->assertViewHas('courses', fn ($courses) => $courses->getCollection()->modelKeys() === [$moreEnrolled->id, $lessEnrolled->id]);
    }

    public function test_copy_of_an_excluded_archive_defaults_to_included(): void
    {
        $year = $this->year;
        $course = Course::create(['academic_year_id' => $year->id, 'name' => 'Excluded archive', 'is_active' => false, 'show_in_report_filters' => false]);

        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSeeInOrder(['data-course-archive-copy-action', 'data-course-report-filter-toggle'], false)
            ->call('duplicateArchived', $course->id)
            ->assertHasNoErrors();

        $copy = Course::query()->whereKeyNot($course->id)->sole();
        $this->assertTrue($copy->is_active);
        $this->assertTrue($copy->show_in_report_filters);
        $this->assertFalse($course->fresh()->show_in_report_filters);
    }
}

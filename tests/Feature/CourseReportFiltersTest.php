<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\User;
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

    public function test_course_list_orders_by_academic_year_then_start_and_end_dates_newest_first(): void
    {
        $older = Course::create(['academic_year_id' => $this->year->id, 'name' => 'A older', 'starts_on' => '2025-09-01', 'ends_on' => '2027-08-31']);
        $longer = Course::create(['academic_year_id' => $this->year->id, 'name' => 'Z latest longer', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $shorter = Course::create(['academic_year_id' => $this->year->id, 'name' => 'B latest shorter', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $undated = Course::create(['academic_year_id' => $this->year->id, 'name' => 'C undated']);

        // Create years out of chronological order and give their courses
        // conflicting dates so academic-year precedence is observable.
        $newestYear = AcademicYear::create(['name' => 'Newest year', 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31', 'is_current' => false, 'is_active' => true]);
        $previousYear = AcademicYear::create(['name' => 'Previous year', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_current' => false, 'is_active' => false]);
        $newestYearCourse = Course::create(['academic_year_id' => $newestYear->id, 'name' => 'Newest year course', 'starts_on' => '2024-09-01', 'ends_on' => '2024-12-31']);
        $previousYearCourse = Course::create(['academic_year_id' => $previousYear->id, 'name' => 'Previous year course', 'starts_on' => '2030-09-01', 'ends_on' => '2030-12-31', 'is_active' => false]);
        $unassigned = Course::create(['name' => 'No academic year', 'starts_on' => '2031-09-01', 'ends_on' => '2031-12-31']);

        Volt::test('courses.index')
            ->assertViewHas('courses', fn ($courses) => $courses->getCollection()->modelKeys() === [$longer->id, $shorter->id, $older->id, $undated->id])
            ->set('statusFilter', 'all')
            ->set('academicYearFilter', 'all')
            ->assertViewHas('courses', fn ($courses) => $courses->getCollection()->modelKeys() === [$newestYearCourse->id, $longer->id, $shorter->id, $older->id, $undated->id, $previousYearCourse->id, $unassigned->id])
            ->set('search', 'latest')
            ->assertViewHas('courses', fn ($courses) => $courses->getCollection()->modelKeys() === [$longer->id, $shorter->id]);
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

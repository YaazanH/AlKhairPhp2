<?php

namespace Tests\Feature;

use App\Exceptions\ReportQueryTimeoutException;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Models\Enrollment;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupCurriculumLessonProgress;
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
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\ReportDashboardService;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerGuidance;
use App\Services\ReportDesignerQueryService;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PDOException;
use Spatie\Activitylog\Models\Activity as AuditActivity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportDesignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_report_timeout_errors_are_recognized_without_hiding_other_database_errors(): void
    {
        $service = app(ReportDesignerQueryService::class);
        $method = new \ReflectionMethod($service, 'isQueryTimeout');

        $timeout = new PDOException('Query execution was interrupted, maximum statement execution time exceeded');
        $timeout->errorInfo = ['HY000', 3024, 'Query execution was interrupted'];
        $ordinary = new PDOException('Unknown column');
        $ordinary->errorInfo = ['42S22', 1054, 'Unknown column'];

        $this->assertTrue($method->invoke($service, new QueryException('mysql', 'select 1', [], $timeout)));
        $this->assertFalse($method->invoke($service, new QueryException('mysql', 'select missing', [], $ordinary)));
    }

    public function test_report_guidance_explains_the_design_and_flags_likely_scope_and_currency_mistakes(): void
    {
        $this->seed(RoleSeeder::class);
        $this->app->setLocale('en');

        $administrator = User::factory()->create(['username' => 'report-guidance-admin']);
        $administrator->assignRole('admin');
        $administrator->givePermissionTo('finance.reports.view');
        $catalog = app(ReportDesignerCatalog::class);
        $guidance = app(ReportDesignerGuidance::class);
        $studentFields = array_slice(array_keys($catalog->fields(ReportDesignerCatalog::STUDENTS)), 0, 9);

        $studentDesign = $guidance->build([
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => $studentFields,
            'calculations' => [],
            'group_by' => null,
            'presentation' => ['type' => ReportDesignerCatalog::PRESENTATION_TABLE],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
        ], $administrator);

        $this->assertStringContainsString('Students', $studentDesign['summary']);
        $this->assertContains(__('report_designer.guidance.warnings.no_filters'), $studentDesign['warnings']);
        $this->assertContains(__('report_designer.guidance.warnings.many_fields'), $studentDesign['warnings']);

        $financeDesign = $guidance->build([
            'data_source' => ReportDesignerCatalog::FINANCE_TRANSACTIONS,
            'selected_fields' => ['transaction_date', 'amount'],
            'calculations' => [['operation' => 'sum', 'field' => 'amount']],
            'group_by' => 'finance_category',
            'presentation' => ['type' => ReportDesignerCatalog::PRESENTATION_TABLE],
            'filters' => ['status' => 'expense', 'search' => '', 'date_from' => '', 'date_to' => ''],
        ], $administrator);

        $this->assertContains(__('report_designer.guidance.warnings.no_date_range'), $financeDesign['warnings']);
        $this->assertContains(__('report_designer.guidance.warnings.mixed_currency'), $financeDesign['warnings']);

        $this->actingAs($administrator);
        Volt::test('reports.designer')
            ->call('create')
            ->assertSee('data-report-guidance', false)
            ->assertSee(__('report_designer.guidance.warnings.no_filters'));
    }

    public function test_report_timeout_is_shown_inside_the_designer_preview(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'report-timeout-admin']);
        $administrator->assignRole('admin');
        $this->actingAs($administrator);

        $this->mock(ReportDesignerQueryService::class, function ($mock): void {
            $mock->shouldReceive('preview')->once()->andThrow(new ReportQueryTimeoutException);
        });

        Volt::test('reports.designer')
            ->call('create')
            ->set('name', 'Slow report')
            ->set('selectedFields', ['full_name'])
            ->call('preview')
            ->assertHasErrors('preview')
            ->assertSee(__('report_designer.errors.query_timeout'))
            ->assertSee('data-report-timeout-error', false);
    }

    public function test_timed_out_dashboard_widget_fails_independently_and_is_not_cached(): void
    {
        $this->seed(RoleSeeder::class);
        Cache::flush();

        $role = Role::findOrCreate('timeout-report-reviewer', 'web');
        $viewer = User::factory()->create(['username' => 'timeout-report-viewer']);
        $viewer->assignRole($role);
        $definition = ReportDefinition::query()->create([
            'name' => 'Slow dashboard report',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_PUBLISHED,
        ]);
        $definition->dashboardRoles()->attach($role->id, ['position' => 1, 'size' => 'medium']);

        $this->mock(ReportDesignerQueryService::class, function ($mock): void {
            $mock->shouldReceive('preview')->twice()->andThrow(new ReportQueryTimeoutException);
        });

        $first = app(ReportDashboardService::class)->widgetsFor($viewer)->sole()['preview'];
        $second = app(ReportDashboardService::class)->widgetsFor($viewer)->sole()['preview'];

        $this->assertSame(__('report_designer.errors.query_timeout'), $first['error']);
        $this->assertSame($first, $second);
    }

    public function test_timed_out_export_returns_a_clear_error_and_is_not_audited_as_successful(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create(['username' => 'report-timeout-exporter']);
        $owner->givePermissionTo('report-designer.view');
        $definition = ReportDefinition::query()->create([
            'name' => 'Slow export',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $this->mock(ReportDesignerQueryService::class, function ($mock): void {
            $mock->shouldReceive('export')->once()->andThrow(new ReportQueryTimeoutException);
        });

        $this->actingAs($owner)
            ->get(route('reports.designer.export.xlsx', $definition, absolute: false))
            ->assertStatus(422)
            ->assertSee(__('report_designer.errors.query_timeout'));

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => $definition->getMorphClass(),
            'subject_id' => $definition->id,
            'event' => 'report_exported',
        ]);
    }

    public function test_outdated_report_definition_stays_manageable_but_cannot_execute_or_reach_a_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'outdated-report-admin']);
        $administrator->assignRole('admin');
        $adminRole = Role::findByName('admin', 'web');
        $definition = ReportDefinition::query()->create([
            'name' => 'Outdated student report',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name', 'retired_field'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_PUBLISHED,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);
        $definition->dashboardRoles()->attach($adminRole->id, ['position' => 1, 'size' => 'medium']);

        $this->actingAs($administrator)
            ->get(route('reports.designer', absolute: false))
            ->assertOk()
            ->assertSee('Outdated student report')
            ->assertSee(__('report_designer.compatibility.definition_outdated'))
            ->assertSee('data-report-incompatible', false);

        $this->assertTrue(app(ReportDashboardService::class)->reportsFor($administrator)->isEmpty());
        $this->get(route('reports.designer.show', $definition, absolute: false))->assertNotFound();
        $this->get(route('reports.designer.export.xlsx', $definition, absolute: false))->assertNotFound();

        Volt::test('reports.designer')
            ->call('delete', $definition->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('report_definitions', ['id' => $definition->id]);
    }

    public function test_report_with_disabled_source_remains_visible_to_the_designer_with_a_clear_warning(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'disabled-source-report-admin']);
        $administrator->assignRole('admin');
        ReportDefinition::query()->create([
            'name' => 'Paused memorization report',
            'data_source' => ReportDesignerCatalog::MEMORIZATION_SESSIONS,
            'selected_fields' => ['full_name', 'pages_count'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        $this->mock(CurrentModuleAccess::class, function ($mock): void {
            $mock->makePartial();
            $mock->shouldReceive('enabled')->andReturnUsing(
                fn (string $module): bool => $module !== 'memorization',
            );
        });

        $this->actingAs($administrator)
            ->get(route('reports.designer', absolute: false))
            ->assertOk()
            ->assertSee('Paused memorization report')
            ->assertSee(__('report_designer.compatibility.source_unavailable'));
    }

    public function test_full_report_timeout_returns_the_same_clear_error_as_other_report_surfaces(): void
    {
        $this->seed(RoleSeeder::class);

        $owner = User::factory()->create(['username' => 'full-report-timeout-owner']);
        $owner->givePermissionTo('report-designer.view');
        $definition = ReportDefinition::query()->create([
            'name' => 'Slow full report',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $this->mock(ReportDesignerQueryService::class, function ($mock): void {
            $mock->shouldReceive('preview')->once()->andThrow(new ReportQueryTimeoutException);
        });

        $this->actingAs($owner)
            ->get(route('reports.designer.show', $definition, absolute: false))
            ->assertStatus(422)
            ->assertSee(__('report_designer.errors.query_timeout'));
    }

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

    public function test_report_definition_changes_are_recorded_in_the_tenant_audit_trail(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'report-audit-admin']);
        $administrator->assignRole('admin');
        $this->actingAs($administrator);

        $definition = ReportDefinition::query()->create([
            'name' => 'Audited report',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);
        $definition->update(['name' => 'Updated audited report']);
        $definition->delete();

        $events = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', $definition->getMorphClass())
            ->where('subject_id', $definition->id)
            ->orderBy('id')
            ->pluck('event')
            ->all();

        $this->assertSame(['created', 'updated', 'deleted'], $events);
    }

    public function test_report_design_versions_are_preserved_and_an_older_revision_can_be_restored(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'report-version-admin']);
        $administrator->assignRole('admin');
        $this->actingAs($administrator);

        $component = Volt::test('reports.designer')
            ->call('create')
            ->set('name', 'Original report name')
            ->set('description', 'Original purpose')
            ->call('save')
            ->assertHasNoErrors();

        $definition = ReportDefinition::query()->where('name', 'Original report name')->sole();
        $firstRevision = $definition->revisions()->sole();
        $this->assertSame(1, $firstRevision->revision_number);
        $this->assertSame('created', $firstRevision->action);

        $component
            ->set('name', 'Updated report name')
            ->set('description', 'Updated purpose')
            ->call('save')
            ->assertHasNoErrors();
        $definition->forceFill(['status' => ReportDefinition::STATUS_PUBLISHED])->saveQuietly();

        $component
            ->call('openHistory', $definition->id)
            ->assertSee(__('report_designer.history.revision', ['number' => 2]))
            ->assertSee(__('report_designer.history.revision', ['number' => 1]))
            ->call('restoreRevision', $firstRevision->id)
            ->assertHasNoErrors();

        $definition->refresh();
        $this->assertSame('Original report name', $definition->name);
        $this->assertSame('Original purpose', $definition->description);
        $this->assertSame(ReportDefinition::STATUS_PUBLISHED, $definition->status);
        $this->assertSame(3, $definition->revisions()->count());

        $restored = $definition->revisions()->latest('revision_number')->firstOrFail();
        $this->assertSame('restored', $restored->action);
        $this->assertSame(1, $restored->restored_from_revision_number);
        $this->assertSame('Original report name', data_get($restored->snapshot, 'name'));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $definition->getMorphClass(),
            'subject_id' => $definition->id,
            'event' => 'report_revision_restored',
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

    public function test_student_performance_map_uses_approved_enrollment_totals(): void
    {
        $this->seed(RoleSeeder::class);
        $administrator = User::factory()->create(['username' => 'performance-map-admin']);
        $administrator->assignRole('admin');
        $year = AcademicYear::query()->create(['name' => 'Performance year', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'is_current' => true, 'is_active' => true]);
        $teacher = Teacher::query()->create(['first_name' => 'Performance', 'last_name' => 'Teacher', 'phone' => '0900000099', 'status' => 'active']);
        $course = Course::query()->create(['name' => 'Performance course', 'is_active' => true]);
        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Performance group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'first_name' => 'Omar',
            'last_name' => 'Map',
            'student_number' => 'MAP-001',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 42,
            'memorized_pages_cached' => 18,
        ]);
        $secondStudent = Student::query()->create([
            'first_name' => 'Ziad',
            'last_name' => 'Sessions',
            'birth_date' => '2014-02-01',
            'status' => 'active',
        ]);
        $secondEnrollment = Enrollment::query()->create([
            'student_id' => $secondStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 2,
            'memorized_pages_cached' => 6,
        ]);
        MemorizationSession::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-10-01',
            'entry_type' => 'new',
            'from_page' => 1,
            'to_page' => 20,
            'pages_count' => 20,
        ]);
        foreach ([1, 2] as $day) {
            MemorizationSession::query()->create([
                'enrollment_id' => $secondEnrollment->id,
                'student_id' => $secondStudent->id,
                'teacher_id' => $teacher->id,
                'recorded_on' => '2026-10-0'.$day,
                'entry_type' => 'new',
                'from_page' => (($day - 1) * 3) + 1,
                'to_page' => $day * 3,
                'pages_count' => 3,
            ]);
        }

        $calculations = [
            ['operation' => 'count', 'field' => null],
            ['operation' => 'sum', 'field' => 'memorized_pages'],
            ['operation' => 'sum', 'field' => 'points_balance'],
        ];
        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['student_number', 'full_name', 'memorized_pages', 'points_balance'],
            'calculations' => $calculations,
            'group_by' => 'student_identity',
            'filters' => ['status' => 'active'],
            'sort_field' => 'full_name',
            'sort_direction' => 'asc',
        ], $administrator);

        $this->assertSame(18, $preview['rows'][0]['memorized_pages']);
        $this->assertSame(42, $preview['rows'][0]['points_balance']);
        $studentLabel = 'Omar Map ('.$student->student_number.')';
        $this->assertSame($studentLabel, $preview['grouping']['rows'][0]['group']);
        $this->assertSame(18.0, $preview['grouping']['rows'][0]['report_calculation_1']);
        $this->assertSame(42.0, $preview['grouping']['rows'][0]['report_calculation_2']);
        $this->assertSame(['students', 'points_rewards', 'memorization'], app(ReportDesignerCatalog::class)->requiredModulesForDefinition(
            ReportDesignerCatalog::STUDENTS,
            ['student_identity', 'memorized_pages', 'points_balance'],
        ));

        $presentation = app(ReportDesignerCatalog::class)->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_PERFORMANCE_MAP,
            'density' => 'comfortable',
            'x_metric' => 'report_calculation_1',
            'metric' => 'report_calculation_2',
        ], 'student_identity', true, ReportDesignerCatalog::STUDENTS, $calculations);
        $html = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            ['grouping' => $preview['grouping'], 'presentation' => $presentation],
        );

        $this->assertStringContainsString('data-report-presentation="performance_map"', $html);
        $this->assertStringContainsString('dashboard-performance-map__plot', $html);
        $this->assertStringContainsString('dashboard-performance-map__point--below-average', $html);

        $leaderboardCalculations = [
            ['operation' => 'count', 'field' => null],
            ['operation' => 'sum', 'field' => 'pages_count'],
        ];
        $leaderboard = app(ReportDesignerQueryService::class)->preview([
            'data_source' => ReportDesignerCatalog::MEMORIZATION_SESSIONS,
            'selected_fields' => ['recorded_on', 'full_name', 'pages_count'],
            'calculations' => $leaderboardCalculations,
            'group_by' => 'student_identity',
            'presentation' => [
                'type' => ReportDesignerCatalog::PRESENTATION_LEADERBOARD,
                'density' => 'comfortable',
                'metric' => 'report_calculation_1',
            ],
            'filters' => ['status' => 'all', 'date_from' => '', 'date_to' => ''],
            'sort_field' => 'recorded_on',
            'sort_direction' => 'desc',
        ], $administrator);

        $this->assertStringStartsWith('Omar Map', $leaderboard['grouping']['rows'][0]['group']);
        $this->assertSame(20.0, $leaderboard['grouping']['rows'][0]['report_calculation_1']);
        $this->assertSame(1, $leaderboard['grouping']['rows'][0]['record_count']);
        $this->assertSame(6.0, $leaderboard['grouping']['rows'][1]['report_calculation_1']);
        $this->assertSame(2, $leaderboard['grouping']['rows'][1]['record_count']);

        $leaderboardHtml = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            ['grouping' => $leaderboard['grouping'], 'presentation' => [
                'type' => ReportDesignerCatalog::PRESENTATION_LEADERBOARD,
                'metric' => 'report_calculation_1',
            ]],
        );
        $this->assertStringContainsString('data-report-presentation="leaderboard"', $leaderboardHtml);
        $this->assertStringContainsString('teacher-memorization-ranking-row', $leaderboardHtml);
        $this->assertStringContainsString('20', $leaderboardHtml);

        $rankingPresentation = app(ReportDesignerCatalog::class)->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_RANKING,
            'density' => 'comfortable',
            'metric' => 'report_calculation_1',
        ], 'group_name', true, ReportDesignerCatalog::MEMORIZATION_SESSIONS, $leaderboardCalculations);
        $rankingHtml = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            [
                'grouping' => [
                    'label' => 'Group',
                    'columns' => ['group' => 'Group', 'record_count' => 'Sessions', 'report_calculation_1' => 'Total pages'],
                    'rows' => [
                        ['group' => 'Group B', 'record_count' => 2, 'report_calculation_1' => 6.0],
                        ['group' => 'Group A', 'record_count' => 1, 'report_calculation_1' => 20.0],
                    ],
                ],
                'presentation' => $rankingPresentation,
            ],
        );
        $this->assertStringContainsString('data-report-presentation="ranking"', $rankingHtml);
        $this->assertTrue(strpos($rankingHtml, 'Group A') < strpos($rankingHtml, 'Group B'));
        $this->assertStringContainsString('sm:order-2', $rankingHtml);
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

        $exports = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', $definition->getMorphClass())
            ->where('subject_id', $definition->id)
            ->where('event', 'report_exported')
            ->orderBy('id')
            ->get();
        $this->assertSame(['xlsx', 'pdf'], $exports->pluck('properties.after.export_format')->all());
        $this->assertSame([30, 30], $exports->pluck('properties.after.exported_rows')->all());

        $this->actingAs($otherUser)
            ->get(route('reports.designer.export.xlsx', $definition, absolute: false))
            ->assertNotFound();
        $this->assertSame(2, AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', $definition->getMorphClass())
            ->where('subject_id', $definition->id)
            ->where('event', 'report_exported')
            ->count());
    }

    public function test_grouping_rejects_fields_outside_the_approved_dimensions(): void
    {
        $this->expectException(ValidationException::class);

        app(ReportDesignerCatalog::class)->validateGrouping(
            ReportDesignerCatalog::FINANCE_TRANSACTIONS,
            'description',
        );
    }

    public function test_specialized_group_distribution_is_reserved_for_compatible_library_templates(): void
    {
        $catalog = app(ReportDesignerCatalog::class);

        try {
            $catalog->validatePresentation([
                'type' => ReportDesignerCatalog::PRESENTATION_LOLLIPOP,
                'density' => 'comfortable',
            ], 'current_group');
            $this->fail('A specialized presentation must not be available to unrestricted report definitions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('presentationType', $exception->errors());
        }

        $presentation = $catalog->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_LOLLIPOP,
            'density' => 'comfortable',
        ], 'current_group', true);
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_LOLLIPOP, $presentation['type']);

        $html = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            [
                'grouping' => [
                    'label' => 'Current group',
                    'columns' => ['group' => 'Current group', 'record_count' => 'Records'],
                    'rows' => [
                        ['group' => 'Group A', 'record_count' => 8],
                        ['group' => 'Group B', 'record_count' => 3],
                    ],
                ],
                'presentation' => $presentation,
            ],
        );

        $this->assertStringContainsString('data-report-presentation="lollipop"', $html);
        $this->assertStringContainsString('Group A', $html);
        $this->assertStringContainsString('8', $html);

        try {
            $catalog->validatePresentation([
                'type' => ReportDesignerCatalog::PRESENTATION_LINE,
                'density' => 'comfortable',
            ], 'presence_result', true, ReportDesignerCatalog::STUDENT_ATTENDANCE);
            $this->fail('A time trend must reject non-date groupings.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('presentationType', $exception->errors());
        }

        $linePresentation = $catalog->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_LINE,
            'density' => 'comfortable',
        ], 'attendance_date', true, ReportDesignerCatalog::STUDENT_ATTENDANCE);
        $lineHtml = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            [
                'grouping' => [
                    'label' => 'Attendance date',
                    'columns' => ['group' => 'Attendance date', 'record_count' => 'Records'],
                    'rows' => [
                        ['group' => '2026-10-01', 'record_count' => 2],
                        ['group' => '2026-10-02', 'record_count' => 5],
                    ],
                ],
                'presentation' => $linePresentation,
            ],
        );
        $this->assertStringContainsString('data-report-presentation="line"', $lineHtml);
        $this->assertStringContainsString('<polyline', $lineHtml);
        $this->assertStringContainsString('2026-10-02: 5', $lineHtml);

        $treemapPresentation = $catalog->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_TREEMAP,
            'density' => 'comfortable',
        ], 'grade_level', true, ReportDesignerCatalog::STUDENTS);
        $treemapHtml = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            [
                'grouping' => [
                    'label' => 'Grade level',
                    'columns' => ['group' => 'Grade level', 'record_count' => 'Records'],
                    'rows' => [
                        ['group' => 'Grade 4', 'record_count' => 6],
                        ['group' => 'Grade 5', 'record_count' => 4],
                    ],
                ],
                'presentation' => $treemapPresentation,
            ],
        );
        $this->assertStringContainsString('data-report-presentation="treemap"', $treemapHtml);
        $this->assertStringContainsString('Grade 4', $treemapHtml);
        $this->assertStringContainsString('60.0%', $treemapHtml);

        $financeCalculations = [
            ['operation' => 'count', 'field' => null],
            ['operation' => 'absolute_sum', 'field' => 'local_amount'],
        ];
        $expensePresentation = $catalog->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_DONUT,
            'density' => 'comfortable',
            'metric' => 'report_calculation_1',
        ], 'finance_category', false, ReportDesignerCatalog::FINANCE_TRANSACTIONS, $financeCalculations);
        $expenseHtml = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            [
                'grouping' => [
                    'label' => 'Category',
                    'columns' => [
                        'group' => 'Category',
                        'record_count' => 'Records',
                        'report_calculation_1' => 'Absolute total of local amount',
                    ],
                    'rows' => [
                        ['group' => 'Supplies', 'record_count' => 2, 'report_calculation_1' => 100.0],
                        ['group' => 'Transport', 'record_count' => 1, 'report_calculation_1' => 300.0],
                    ],
                ],
                'presentation' => $expensePresentation,
            ],
        );
        $this->assertStringContainsString('Absolute total of local amount', $expenseHtml);
        $this->assertStringContainsString('Transport', $expenseHtml);
        $this->assertStringContainsString('300', $expenseHtml);

        $quarterPresentation = $catalog->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_LINE,
            'density' => 'comfortable',
            'metric' => 'report_calculation_1',
        ], 'transaction_quarter', true, ReportDesignerCatalog::FINANCE_TRANSACTIONS, $financeCalculations);
        $this->assertSame(ReportDesignerCatalog::PRESENTATION_LINE, $quarterPresentation['type']);

        try {
            $catalog->validatePresentation([
                'type' => ReportDesignerCatalog::PRESENTATION_DONUT,
                'density' => 'comfortable',
                'metric' => 'report_calculation_2',
            ], 'finance_category', false, ReportDesignerCatalog::FINANCE_TRANSACTIONS, $financeCalculations);
            $this->fail('A chart measure must reference an approved calculation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('presentationMetric', $exception->errors());
        }
    }

    public function test_grouped_report_presentation_is_saved_and_reused_in_preview_full_report_and_dashboard(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'presentation-admin']);
        $administrator->assignRole('admin');
        Student::query()->create([
            'first_name' => 'Active',
            'last_name' => 'Student',
            'student_number' => 'PRESENT-001',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        Student::query()->create([
            'first_name' => 'Inactive',
            'last_name' => 'Student',
            'student_number' => 'PRESENT-002',
            'birth_date' => '2014-01-01',
            'status' => 'inactive',
        ]);

        $this->actingAs($administrator);
        Volt::test('reports.designer')
            ->call('create')
            ->set('name', 'Students by status chart')
            ->set('groupBy', 'status')
            ->set('presentationType', ReportDesignerCatalog::PRESENTATION_BAR)
            ->set('tableDensity', 'compact')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-report-presentation="bar"')
            ->call('save')
            ->assertHasNoErrors();

        $definition = ReportDefinition::query()->where('name', 'Students by status chart')->firstOrFail();
        $this->assertSame([
            'type' => ReportDesignerCatalog::PRESENTATION_BAR,
            'density' => 'compact',
        ], $definition->presentation);

        $this->get(route('reports.designer.show', $definition, absolute: false))
            ->assertOk()
            ->assertSee('data-report-presentation="bar"', false);

        Volt::test('reports.designer')
            ->call('managePlacement', $definition->id)
            ->set('placementRoleIds', [Role::findByName('admin', 'web')->id])
            ->call('savePlacement')
            ->assertHasNoErrors();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Students by status chart')
            ->assertSee('data-report-presentation="bar"', false);

        Volt::test('reports.designer')
            ->call('create')
            ->set('name', 'Invalid ungrouped chart')
            ->set('presentationType', ReportDesignerCatalog::PRESENTATION_DONUT)
            ->call('save')
            ->assertHasErrors(['presentationType']);
    }

    public function test_dashboard_placement_controls_role_visibility_and_returns_to_draft_when_removed(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'report-layout-admin']);
        $administrator->assignRole('admin');
        $viewerRole = Role::findOrCreate('programme-reviewer', 'web');
        $viewer = User::factory()->create(['username' => 'placed-report-viewer']);
        $viewer->assignRole($viewerRole);
        $student = Student::query()->create([
            'first_name' => 'Scoped',
            'last_name' => 'Learner',
            'student_number' => 'PLACED-001',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        app(AccessScopeService::class)->syncUserOverrides($viewer, ['student' => [$student->id]]);
        $definition = ReportDefinition::query()->create([
            'name' => 'Role performance report',
            'description' => 'Visible only while placed for this role.',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name', 'status'],
            'calculations' => [['operation' => 'count', 'field' => null]],
            'filters' => ['status' => 'active', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('reports.designer.show', $definition, absolute: false))
            ->assertNotFound();
        $this->get(route('reports.designer.export.xlsx', $definition, absolute: false))->assertNotFound();

        $this->actingAs($administrator);
        Volt::test('reports.designer')
            ->call('managePlacement', $definition->id)
            ->set('placementRoleIds', [$viewerRole->id])
            ->set('placementSizes.'.$viewerRole->id, 'wide')
            ->call('savePlacement')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('report_dashboard_placements', [
            'report_definition_id' => $definition->id,
            'role_id' => $viewerRole->id,
            'size' => 'wide',
        ]);
        $this->assertSame(ReportDefinition::STATUS_PUBLISHED, $definition->fresh()->status);
        $placementAudit = AuditActivity::query()
            ->inLog('data-audit')
            ->where('subject_type', $definition->getMorphClass())
            ->where('subject_id', $definition->id)
            ->where('event', 'report_dashboard_updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($viewerRole->id, data_get($placementAudit->properties, 'after.dashboard_placements.0.role_id'));
        $this->assertSame('wide', data_get($placementAudit->properties, 'after.dashboard_placements.0.size'));
        Volt::test('reports.designer')
            ->call('edit', $definition->id)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(ReportDefinition::STATUS_PUBLISHED, $definition->fresh()->status);

        $this->actingAs($viewer)
            ->get(route('reports.designer.show', $definition, absolute: false))
            ->assertOk()
            ->assertSee('Role performance report')
            ->assertSee('Scoped Learner');
        $this->get(route('reports.designer.export.xlsx', $definition, absolute: false))->assertOk();
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Role performance report')
            ->assertSee('Scoped Learner');
        $this->get(route('reports.index', absolute: false))->assertForbidden();
        $this->get(route('reports.custom', absolute: false))
            ->assertOk()
            ->assertSee('Role performance report')
            ->assertDontSee(__('reports.navigation.student_activity_title'));
        $reportsNavigation = collect(app(SidebarNavigationService::class)->sidebarFor($viewer))
            ->flatMap(fn (array $group) => $group['items'])
            ->firstWhere('key', 'reports');
        $this->assertSame(__('ui.nav.custom_reports'), $reportsNavigation['label']);
        $this->assertSame(route('reports.custom'), $reportsNavigation['href']);

        $this->actingAs($administrator);
        Volt::test('reports.designer')
            ->call('managePlacement', $definition->id)
            ->set('placementRoleIds', [])
            ->call('savePlacement')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('report_dashboard_placements', ['report_definition_id' => $definition->id]);
        $this->assertSame(ReportDefinition::STATUS_DRAFT, $definition->fresh()->status);
        $this->actingAs($viewer)
            ->get(route('reports.designer.show', $definition, absolute: false))
            ->assertNotFound();
        $this->get('/dashboard')->assertDontSee('Role performance report');
        $this->get(route('reports.custom', absolute: false))->assertForbidden();
        $this->assertNull(collect(app(SidebarNavigationService::class)->sidebarFor($viewer->fresh()))
            ->flatMap(fn (array $group) => $group['items'])
            ->firstWhere('key', 'reports'));
    }

    public function test_role_dashboard_layout_can_reorder_remove_and_size_widgets_per_role(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'role-layout-admin']);
        $administrator->assignRole('admin');
        $firstRole = Role::findOrCreate('first-review-team', 'web');
        $secondRole = Role::findOrCreate('second-review-team', 'web');
        $firstRole->givePermissionTo('reports.view');
        $secondRole->givePermissionTo('reports.view');
        $firstViewer = User::factory()->create(['username' => 'first-layout-viewer']);
        $firstViewer->assignRole($firstRole);
        $secondViewer = User::factory()->create(['username' => 'second-layout-viewer']);
        $secondViewer->assignRole($secondRole);

        $firstReport = ReportDefinition::query()->create([
            'name' => 'First role widget',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_DRAFT,
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);
        $secondReport = $firstReport->replicate()->fill(['name' => 'Second role widget']);
        $secondReport->save();

        $this->actingAs($administrator);
        Volt::test('reports.designer')
            ->call('managePlacement', $firstReport->id)
            ->set('placementRoleIds', [$firstRole->id, $secondRole->id])
            ->set('placementSizes.'.$firstRole->id, 'small')
            ->set('placementSizes.'.$secondRole->id, 'wide')
            ->call('savePlacement')
            ->assertHasNoErrors();
        Volt::test('reports.designer')
            ->call('managePlacement', $secondReport->id)
            ->set('placementRoleIds', [$firstRole->id])
            ->set('placementSizes.'.$firstRole->id, 'medium')
            ->call('savePlacement')
            ->assertHasNoErrors();

        Volt::test('reports.designer')
            ->call('openRoleLayouts')
            ->call('selectLayoutRole', $firstRole->id)
            ->call('moveLayoutItem', 1, 'up')
            ->set('layoutItems.0.size', 'wide')
            ->call('saveRoleLayout')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('report_dashboard_placements', [
            'report_definition_id' => $secondReport->id,
            'role_id' => $firstRole->id,
            'position' => 1,
            'size' => 'wide',
        ]);
        $this->assertDatabaseHas('report_dashboard_placements', [
            'report_definition_id' => $firstReport->id,
            'role_id' => $firstRole->id,
            'position' => 2,
            'size' => 'small',
        ]);
        $this->assertSame(4, AuditActivity::query()
            ->inLog('data-audit')
            ->where('event', 'report_dashboard_updated')
            ->whereIn('subject_id', [$firstReport->id, $secondReport->id])
            ->count());

        $firstWidgets = app(ReportDashboardService::class)->widgetsFor($firstViewer);
        $this->assertSame([$secondReport->id, $firstReport->id], $firstWidgets->pluck('report.id')->all());
        $this->assertSame(['wide', 'small'], $firstWidgets->pluck('size')->all());
        $secondWidgets = app(ReportDashboardService::class)->widgetsFor($secondViewer);
        $this->assertSame([$firstReport->id], $secondWidgets->pluck('report.id')->all());
        $this->assertSame(['wide'], $secondWidgets->pluck('size')->all());

        $this->actingAs($firstViewer)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Second role widget', 'First role widget'])
            ->assertSee('data-report-widget="'.$secondReport->id.'" data-report-widget-size="wide"', false)
            ->assertSee('data-report-widget="'.$firstReport->id.'" data-report-widget-size="small"', false);

        $this->actingAs($administrator);
        Volt::test('reports.designer')
            ->call('openRoleLayouts')
            ->call('selectLayoutRole', $firstRole->id)
            ->call('removeLayoutItem', 1)
            ->call('saveRoleLayout')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('report_dashboard_placements', [
            'report_definition_id' => $firstReport->id,
            'role_id' => $firstRole->id,
        ]);
        $this->assertSame(ReportDefinition::STATUS_PUBLISHED, $firstReport->fresh()->status);
        $this->assertSame([$firstReport->id], app(ReportDashboardService::class)->reportsFor($secondViewer)->pluck('id')->all());
    }

    public function test_dashboard_widget_cache_is_scoped_and_invalidated_by_access_and_report_changes(): void
    {
        $this->seed(RoleSeeder::class);
        Cache::flush();

        $role = Role::findOrCreate('cached-report-reviewer', 'web');
        $firstViewer = User::factory()->create(['username' => 'cached-report-first']);
        $firstViewer->assignRole($role);
        $secondViewer = User::factory()->create(['username' => 'cached-report-second']);
        $secondViewer->assignRole($role);
        $firstStudent = Student::query()->create([
            'first_name' => 'First',
            'last_name' => 'Scoped',
            'student_number' => 'CACHE-001',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $secondStudent = Student::query()->create([
            'first_name' => 'Second',
            'last_name' => 'Scoped',
            'student_number' => 'CACHE-002',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        app(AccessScopeService::class)->syncUserOverrides($firstViewer, ['student' => [$firstStudent->id]]);
        app(AccessScopeService::class)->syncUserOverrides($secondViewer, ['student' => [$firstStudent->id]]);

        $definition = ReportDefinition::query()->create([
            'name' => 'Cached scoped report',
            'data_source' => ReportDesignerCatalog::STUDENTS,
            'selected_fields' => ['full_name'],
            'calculations' => [],
            'filters' => ['status' => 'all', 'search' => '', 'date_from' => '', 'date_to' => ''],
            'sort_direction' => 'asc',
            'status' => ReportDefinition::STATUS_PUBLISHED,
        ]);
        $definition->dashboardRoles()->attach($role->id, ['position' => 1, 'size' => 'medium']);

        $this->mock(ReportDesignerQueryService::class, function ($mock): void {
            $mock->shouldReceive('preview')->times(4)->andReturn(
                ['cache_marker' => 1],
                ['cache_marker' => 2],
                ['cache_marker' => 3],
                ['cache_marker' => 4],
            );
        });

        $this->assertSame(1, app(ReportDashboardService::class)->widgetsFor($firstViewer)->first()['preview']['cache_marker']);
        $this->assertSame(1, app(ReportDashboardService::class)->widgetsFor($firstViewer)->first()['preview']['cache_marker']);

        app(AccessScopeService::class)->syncUserOverrides($firstViewer, ['student' => [$secondStudent->id]]);
        $this->assertSame(2, app(ReportDashboardService::class)->widgetsFor($firstViewer)->first()['preview']['cache_marker']);

        $definition->update([
            'filters' => ['status' => 'active', 'search' => '', 'date_from' => '', 'date_to' => ''],
        ]);
        $this->assertSame(3, app(ReportDashboardService::class)->widgetsFor($firstViewer)->first()['preview']['cache_marker']);
        $this->assertSame(4, app(ReportDashboardService::class)->widgetsFor($secondViewer)->first()['preview']['cache_marker']);
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

    public function test_group_reports_reuse_curriculum_progress_in_peer_relative_hotbars(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = User::factory()->create(['username' => 'curriculum-hotbar-report-admin']);
        $administrator->assignRole('admin');
        $year = AcademicYear::query()->create([
            'name' => 'Curriculum year',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'academic_year_id' => $year->id,
            'name' => 'Curriculum course',
            'is_active' => true,
        ]);
        $curriculum = Curriculum::query()->create([
            'course_id' => $course->id,
            'name' => 'Core curriculum',
            'is_active' => true,
        ]);
        $definition = CurriculumSubjectDefinition::query()->create(['name' => 'Core subject', 'is_active' => true]);
        $subject = CurriculumSubject::query()->create([
            'curriculum_id' => $curriculum->id,
            'subject_definition_id' => $definition->id,
        ]);
        $lessons = collect(range(1, 4))->map(fn (int $number): CurriculumLesson => CurriculumLesson::query()->create([
            'curriculum_subject_id' => $subject->id,
            'name' => 'Curriculum lesson '.$number,
            'sort_order' => $number * 10,
        ]));
        $advanced = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'curriculum_id' => $curriculum->id,
            'name' => 'Advanced group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $catchingUp = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'curriculum_id' => $curriculum->id,
            'name' => 'Catching-up group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $lessons->each(fn (CurriculumLesson $lesson) => GroupCurriculumLessonProgress::query()->create([
            'group_id' => $advanced->id,
            'curriculum_lesson_id' => $lesson->id,
            'status' => 'taught',
            'taught_on' => '2026-10-01',
        ]));
        $lessons->take(2)->each(fn (CurriculumLesson $lesson) => GroupCurriculumLessonProgress::query()->create([
            'group_id' => $catchingUp->id,
            'curriculum_lesson_id' => $lesson->id,
            'status' => 'taught',
            'taught_on' => '2026-10-01',
        ]));

        $calculations = [
            ['operation' => 'count', 'field' => null],
            ['operation' => 'avg', 'field' => 'curriculum_progress_percentage'],
            ['operation' => 'max', 'field' => 'curriculum_total_lessons'],
        ];
        $preview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => ReportDesignerCatalog::GROUPS,
            'selected_fields' => ['group_name', 'curriculum_name', 'curriculum_completed_lessons', 'curriculum_total_lessons', 'curriculum_progress_percentage'],
            'calculations' => $calculations,
            'group_by' => 'group_name',
            'filters' => ['status' => 'active'],
            'sort_field' => 'group_name',
            'sort_direction' => 'asc',
        ], $administrator);

        $rows = collect($preview['rows'])->keyBy('group_name');
        $this->assertSame(100.0, $rows['Advanced group']['curriculum_progress_percentage']);
        $this->assertSame(50.0, $rows['Catching-up group']['curriculum_progress_percentage']);
        $this->assertSame(2.0, $rows['Catching-up group']['curriculum_completed_lessons']);
        $this->assertSame(['classes', 'curriculum'], app(ReportDesignerCatalog::class)->requiredModulesForDefinition(
            ReportDesignerCatalog::GROUPS,
            ['group_name', 'curriculum_progress_percentage'],
        ));

        $presentation = app(ReportDesignerCatalog::class)->validatePresentation([
            'type' => ReportDesignerCatalog::PRESENTATION_HOTBAR,
            'density' => 'comfortable',
            'metric' => 'report_calculation_1',
            'total_metric' => 'report_calculation_2',
        ], 'group_name', true, ReportDesignerCatalog::GROUPS, $calculations);
        $html = Blade::render(
            '<x-reports.group-presentation :grouping="$grouping" :presentation="$presentation" />',
            ['grouping' => $preview['grouping'], 'presentation' => $presentation],
        );

        $this->assertStringContainsString('data-report-presentation="hotbar"', $html);
        $this->assertStringContainsString('data-progress-tone="success"', $html);
        $this->assertStringContainsString('data-progress-tone="danger"', $html);
        $this->assertStringContainsString('data-lessons-behind="2"', $html);
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

        $secondStudent = Student::query()->create([
            'first_name' => 'Omar',
            'last_name' => 'Saleh',
            'student_number' => 'S-402',
            'birth_date' => '2014-02-01',
            'status' => 'active',
        ]);
        foreach ([
            ['date' => '2026-09-30', 'students' => [$student]],
            ['date' => '2026-10-02', 'students' => [$student, $secondStudent]],
        ] as $attendance) {
            $attendanceDay = StudentAttendanceDay::query()->create([
                'attendance_date' => $attendance['date'],
                'scope' => 'center',
                'status' => 'closed',
                'created_by' => $administrator->id,
            ]);
            foreach ($attendance['students'] as $attendee) {
                StudentAttendanceRecord::query()->create([
                    'student_attendance_day_id' => $attendanceDay->id,
                    'student_id' => $attendee->id,
                    'attendance_status_id' => $present->id,
                ]);
            }
        }

        $trend = app(ReportDesignerQueryService::class)->preview([
            'data_source' => ReportDesignerCatalog::STUDENT_ATTENDANCE,
            'selected_fields' => ['attendance_date', 'full_name'],
            'calculations' => [['operation' => 'count', 'field' => null]],
            'group_by' => 'attendance_date',
            'filters' => ['status' => 'all'],
            'sort_direction' => 'asc',
        ], $administrator);

        $this->assertSame([
            ['group' => '2026-09-30', 'record_count' => 1],
            ['group' => '2026-10-01', 'record_count' => 1],
            ['group' => '2026-10-02', 'record_count' => 2],
        ], $trend['grouping']['rows']);
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

        $expensePreview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'finance_transactions',
            'selected_fields' => ['transaction_number', 'finance_category', 'local_amount'],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'absolute_sum', 'field' => 'local_amount'],
            ],
            'group_by' => 'finance_category',
            'filters' => ['status' => 'expense'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame(100.0, $expensePreview['calculations'][1]['value']);
        $this->assertSame(100.0, $expensePreview['grouping']['rows'][0]['report_calculation_1']);

        FinanceTransaction::query()->create([
            'transaction_no' => 'TX-DESIGNER-003',
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $category->id,
            'type' => 'expense',
            'direction' => 'out',
            'amount' => 250,
            'signed_amount' => -250,
            'rate_to_base' => 1,
            'base_amount' => -250,
            'local_amount' => -250,
            'transaction_date' => '2026-06-03',
            'description' => 'Second-quarter expense',
            'entered_by' => $user->id,
        ]);

        $quarterPreview = app(ReportDesignerQueryService::class)->preview([
            'data_source' => 'finance_transactions',
            'selected_fields' => ['transaction_date', 'transaction_number', 'local_amount'],
            'calculations' => [
                ['operation' => 'count', 'field' => null],
                ['operation' => 'absolute_sum', 'field' => 'local_amount'],
            ],
            'group_by' => 'transaction_quarter',
            'filters' => ['status' => 'expense'],
            'sort_direction' => 'asc',
        ], $user);

        $this->assertSame([
            ['group' => '2026-Q2', 'record_count' => 1, 'report_calculation_1' => 250.0],
            ['group' => '2026-Q4', 'record_count' => 1, 'report_calculation_1' => 100.0],
        ], $quarterPreview['grouping']['rows']);
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

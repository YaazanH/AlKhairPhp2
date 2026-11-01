<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\Landlord\TenantContext;
use App\Services\LearningProgressionService;
use App\Services\PointLedgerService;
use App\Services\QuranPartialTestService;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalModulesTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.landlord', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('landlord');
        Artisan::call('migrate', ['--database' => 'landlord', '--path' => database_path('migrations/landlord'), '--realpath' => true, '--force' => true]);
        $this->seed(RoleSeeder::class);
        $this->plan = Plan::create(['code' => 'operational', 'name' => 'Operational', 'is_active' => true]);
        $tenant = Tenant::create(['uuid' => (string) Str::uuid(), 'slug' => 'operations', 'name' => 'Operations', 'status' => 'active']);
        $tenant->subscription()->create(['plan_id' => $this->plan->id, 'status' => 'active']);
        app(TenantContext::class)->set($tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        DB::purge('landlord');
        parent::tearDown();
    }

    public function test_group_can_be_created_and_enrolled_before_a_teacher_is_assigned(): void
    {
        $this->modules(['classes']);
        $this->admin();
        $year = AcademicYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['name' => 'Preparation', 'is_active' => true]);

        $response = $this->postJson('/api/v1/groups', [
            'academic_year_id' => $year->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'name' => 'Prepared group',
        ])->assertCreated()->assertJsonPath('teacher_id', null);

        $student = Student::create(['first_name' => 'Ready', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $this->postJson('/api/v1/enrollments', [
            'enrolled_at' => '2026-09-21',
            'group_id' => $response->json('id'),
            'status' => 'active',
            'student_id' => $student->id,
        ])->assertCreated();

        $this->assertDatabaseHas('groups', ['id' => $response->json('id'), 'teacher_id' => null]);
    }

    public function test_disabled_operational_modules_are_hidden_and_rejected(): void
    {
        $this->modules(['students']);
        $this->admin();

        $this->get('/teachers')->assertForbidden();
        $this->get('/groups')->assertForbidden();
        $this->get('/student-attendance')->assertForbidden();
        $this->get('/teacher-attendance')->assertForbidden();
        $this->get('/curricula')->assertForbidden();
        $this->get('/memorization')->assertForbidden();
        $this->get('/quran-tests')->assertForbidden();
        $this->get('/assessments')->assertForbidden();
        $this->get('/points')->assertForbidden();

        $keys = collect(app(SidebarNavigationService::class)->sidebarFor(auth()->user()))
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('key'))
            ->all();
        $this->assertContains('students', $keys);
        $this->assertNotContains('teachers', $keys);
        $this->assertNotContains('groups', $keys);
        $this->assertNotContains('student_attendance', $keys);
        $this->assertNotContains('teacher_attendance', $keys);
        $this->assertNotContains('curricula', $keys);
        $this->assertNotContains('memorization', $keys);
        $this->assertNotContains('quran_tests', $keys);
        $this->assertNotContains('assessments', $keys);
        $this->assertNotContains('point_ledger', $keys);
    }

    public function test_disabled_points_module_hides_settings_links_and_rejects_settings_routes(): void
    {
        $this->modules(['students']);
        $this->admin();

        $pointsSettingsUrl = route('settings.points');

        $this->get(route('settings.organization'))
            ->assertOk()
            ->assertDontSee('href="'.$pointsSettingsUrl.'"', false);

        $this->get(route('barcode-actions.index'))
            ->assertOk()
            ->assertDontSee('href="'.$pointsSettingsUrl.'"', false);

        $this->get($pointsSettingsUrl)->assertForbidden();
        $this->get(route('settings.tracking'))->assertForbidden();
    }

    public function test_center_student_attendance_works_without_classes(): void
    {
        $this->modules(['student_attendance']);
        $this->admin();
        $student = Student::create(['first_name' => 'Center', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $status = AttendanceStatus::create(['code' => 'present-center', 'name' => 'Present', 'scope' => 'student', 'is_active' => true, 'is_present' => true]);

        $response = $this->postJson('/api/v1/student-attendance/days', [
            'attendance_date' => '2026-09-21',
            'default_attendance_status_id' => $status->id,
        ])->assertOk()->assertJsonPath('scope', 'center');

        $this->assertDatabaseHas('student_attendance_records', [
            'student_attendance_day_id' => $response->json('id'),
            'student_id' => $student->id,
            'enrollment_id' => null,
        ]);
        $this->get('/student-attendance')->assertOk();
        $this->get('/groups')->assertForbidden();
    }

    public function test_teacher_attendance_works_without_classes(): void
    {
        $this->modules(['teacher_attendance']);
        $this->admin();
        $teacher = Teacher::create(['first_name' => 'Active', 'last_name' => 'Teacher', 'phone' => '100', 'status' => 'active']);
        $status = AttendanceStatus::create(['code' => 'teacher-present', 'name' => 'Present', 'scope' => 'teacher', 'is_active' => true, 'is_present' => true]);

        $this->postJson('/api/v1/teacher-attendance', [
            'attendance_date' => '2026-09-21',
            'records' => [['attendance_status_id' => $status->id, 'teacher_id' => $teacher->id]],
        ])->assertOk()->assertJsonPath('course_id', null)->assertJsonPath('status', 'open');
        $day = TeacherAttendanceDay::sole();
        $this->postJson('/api/v1/teacher-attendance', [
            'attendance_date' => '2026-09-21',
            'status' => 'closed',
            'records' => [['attendance_status_id' => $status->id, 'teacher_id' => $teacher->id]],
        ])->assertOk()->assertJsonPath('id', $day->id)->assertJsonPath('status', 'closed');
        $this->postJson('/api/v1/teacher-attendance', [
            'attendance_date' => '2026-09-21',
            'records' => [['attendance_status_id' => $status->id, 'teacher_id' => $teacher->id]],
        ])->assertConflict();
        $this->postJson('/api/v1/teacher-attendance', [
            'attendance_date' => '2026-09-22', 'course_id' => 1,
            'records' => [['attendance_status_id' => $status->id, 'teacher_id' => $teacher->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('course_id');
        $this->getJson('/api/v1/reports/teachers/daily-summary?include_empty=1')
            ->assertOk()
            ->assertJsonPath('totals.absences_count', 0)
            ->assertJsonPath('totals.memorization_sessions_count', 0);

        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->assertDontSee('id="teacher-attendance-day-course"', false)
            ->assertSee(__('modules.teacher_attendance.teachers_included', ['count' => '1']));
    }

    public function test_teacher_led_write_is_rejected_until_group_has_a_teacher(): void
    {
        $this->modules(['memorization']);
        $this->admin();
        $year = AcademicYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['name' => 'Teaching', 'is_active' => true]);
        $group = Group::create(['academic_year_id' => $year->id, 'course_id' => $course->id, 'name' => 'Waiting', 'capacity' => 10, 'is_active' => true]);
        $student = Student::create(['first_name' => 'Waiting', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-21', 'status' => 'active']);

        $this->postJson('/api/v1/enrollments/'.$enrollment->id.'/memorization', [])
            ->assertStatus(422)
            ->assertJsonPath('message', __('modules.errors.teacher_required'));
    }

    public function test_manual_points_can_be_awarded_to_an_unenrolled_student_and_api_retries_are_idempotent(): void
    {
        $this->modules(['points_rewards']);
        $this->admin();
        $student = Student::create(['first_name' => 'Independent', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $pointType = PointType::create([
            'name' => 'Participation',
            'code' => 'participation',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);
        $payload = ['point_type_id' => $pointType->id, 'points' => 5, 'notes' => 'Helped another student'];

        $this->withHeader('Idempotency-Key', 'manual-student-award-1')
            ->postJson('/api/v1/students/'.$student->id.'/points/manual', $payload)
            ->assertCreated()
            ->assertJsonPath('enrollment_id', null);
        $this->withHeader('Idempotency-Key', 'manual-student-award-1')
            ->postJson('/api/v1/students/'.$student->id.'/points/manual', $payload)
            ->assertOk();

        $this->assertSame(1, PointTransaction::query()->count());
        $this->assertSame(5, (int) PointTransaction::query()->effectiveActive()->sum('points'));
        $this->assertDatabaseHas('point_transactions', [
            'student_id' => $student->id,
            'enrollment_id' => null,
            'idempotency_key' => 'manual-student-award-1',
            'notes' => 'Helped another student',
        ]);

        Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('pointTransactions', fn ($transactions) => $transactions->count() === 1)
            ->assertViewHas('stats', fn (array $stats) => $stats['points'] === 5);
    }

    public function test_quran_partial_testing_does_not_require_memorization_history_when_module_is_absent(): void
    {
        $this->modules(['quran_tests']);
        $this->admin();
        app(LearningProgressionService::class)->storeQuranSettings([
            'partial_test_enabled' => true,
            'partial_test_required_for_final' => true,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => true,
            'awqaf_test_enabled' => true,
        ]);
        $year = AcademicYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['name' => 'Quran tests', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'Test', 'last_name' => 'Teacher', 'phone' => '200', 'status' => 'active']);
        $group = Group::create(['academic_year_id' => $year->id, 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'name' => 'Tests', 'capacity' => 10, 'is_active' => true]);
        $student = Student::create(['first_name' => 'No', 'last_name' => 'History', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-21', 'status' => 'active']);
        $juz = QuranJuz::create(['juz_number' => 1, 'from_page' => 1, 'to_page' => 21]);

        $test = app(QuranPartialTestService::class)->create($enrollment, $juz);

        $this->assertSame($student->id, $test->student_id);
        $this->assertSame(0, $student->pageAchievements()->count());
        $this->get('/quran-partial-tests')->assertOk();
        $this->get('/memorization')->assertForbidden();
    }

    public function test_automatic_points_are_skipped_when_points_module_is_disabled(): void
    {
        $this->modules(['classes']);
        $this->admin();
        $year = AcademicYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['name' => 'No rewards', 'is_active' => true, 'awards_points' => true]);
        $teacher = Teacher::create(['first_name' => 'Course', 'last_name' => 'Teacher', 'phone' => '300', 'status' => 'active']);
        $group = Group::create(['academic_year_id' => $year->id, 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'name' => 'Class', 'capacity' => 10, 'is_active' => true]);
        $student = Student::create(['first_name' => 'Source', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-21', 'status' => 'active']);
        $pointType = PointType::create(['name' => 'Automatic', 'code' => 'automatic', 'category' => 'automatic', 'default_points' => 3, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true]);

        $transaction = app(PointLedgerService::class)->recordAutomaticPoints($enrollment, 'assessment_result', 99, $pointType, null, 3);

        $this->assertNull($transaction);
        $this->assertDatabaseCount('point_transactions', 0);
        $this->get('/points')->assertForbidden();
    }

    public function test_full_student_progress_scope_still_respects_disabled_modules(): void
    {
        $this->modules(['classes']);
        $user = $this->admin();
        app(AccessScopeService::class)->syncUserOverrides($user, [AccessScopeService::ALL_STUDENT_PROGRESS => [1]]);
        $year = AcademicYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['name' => 'Retained course', 'is_active' => true, 'awards_points' => true]);
        $group = Group::create(['academic_year_id' => $year->id, 'course_id' => $course->id, 'name' => 'Retained group', 'is_active' => true]);
        $student = Student::create(['first_name' => 'Retained', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-21', 'status' => 'active']);
        $type = PointType::create(['name' => 'Hidden reward', 'code' => 'hidden-reward', 'category' => 'manual', 'default_points' => 5, 'is_active' => true]);
        PointTransaction::create(['student_id' => $student->id, 'enrollment_id' => $enrollment->id, 'point_type_id' => $type->id, 'source_type' => 'manual', 'points' => 5, 'entered_at' => now()]);

        Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('pointTransactions', fn ($transactions) => $transactions->isEmpty())
            ->assertViewHas('enrollmentTotalPoints', fn ($points) => $points->isEmpty())
            ->assertDontSee('data-enrollment-total-points', false)
            ->call('showDetails', 'points')
            ->assertViewHas('paginatedDetails', fn ($details) => $details->total() === 0);
        $this->assertDatabaseCount('point_transactions', 1);
    }

    public function test_automatic_point_retries_do_not_duplicate_or_reprice_history(): void
    {
        $this->modules(['classes', 'points_rewards']);
        $this->admin();
        $year = AcademicYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['name' => 'Rewards', 'is_active' => true, 'awards_points' => true]);
        $teacher = Teacher::create(['first_name' => 'Reward', 'last_name' => 'Teacher', 'phone' => '400', 'status' => 'active']);
        $group = Group::create(['academic_year_id' => $year->id, 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'name' => 'Rewards', 'capacity' => 10, 'is_active' => true]);
        $student = Student::create(['first_name' => 'Reward', 'last_name' => 'Student', 'birth_date' => '2015-01-01', 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-21', 'status' => 'active']);
        $pointType = PointType::create(['name' => 'Automatic', 'code' => 'automatic-retry', 'category' => 'automatic', 'default_points' => 3, 'allow_manual_entry' => false, 'allow_negative' => false, 'is_active' => true]);
        $ledger = app(PointLedgerService::class);

        $first = $ledger->recordAutomaticPoints($enrollment, 'assessment_result', 101, $pointType, null, 3);
        $retryAfterRuleChange = $ledger->recordAutomaticPoints($enrollment, 'assessment_result', 101, $pointType, null, 9);

        $this->assertSame($first?->id, $retryAfterRuleChange?->id);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertSame(3, (int) PointTransaction::query()->sum('points'));
    }

    #[DataProvider('attendanceSettingsTenants')]
    public function test_attendance_settings_work_without_points_and_preserve_existing_reward_values(string $module, string $locale): void
    {
        $this->modules([$module]);
        $this->admin();
        app()->setLocale($locale);
        session(['locale' => $locale]);

        $this->get(route('settings.organization'))->assertOk()
            ->assertSee('href="'.route('settings.attendance-statuses').'"', false)
            ->assertDontSee('href="'.route('settings.assessment-types').'"', false)
            ->assertDontSee('href="'.route('settings.points').'"', false);
        $this->get(route('settings.attendance-statuses'))->assertOk()
            ->assertSee(__('settings.tracking.sections.attendance_status.table'))
            ->assertDontSee(__('settings.tracking.table.default_points'))
            ->assertDontSee('wire:model="attendance_status_default_points"', false)
            ->assertDontSee('wire:submit="saveAssessmentType"', false);
        $this->get(route('settings.assessment-types'))->assertForbidden();
        $this->get(route('settings.points'))->assertForbidden();

        $editor = Volt::test('settings.tracking', ['section' => 'attendance'])
            ->call('openAttendanceStatusModal')->set('attendance_status_name', 'Remote attendance')
            ->set('attendance_status_code', 'remote-attendance')->set('attendance_status_is_present', true)
            ->set('attendance_status_default_points', 'invalid-forged-value')
            ->call('saveAttendanceStatus')->assertHasNoErrors();
        $status = AttendanceStatus::where('code', 'remote-attendance')->firstOrFail();
        $this->assertSame(0, $status->default_points);

        $status->update(['default_points' => 17]);
        $editor->call('editAttendanceStatus', $status->id)
            ->assertSet('attendance_status_default_points', '0')
            ->assertDontSee('wire:model="attendance_status_default_points"', false)
            ->set('attendance_status_name', 'Updated remote attendance')
            ->set('attendance_status_default_points', '-999')
            ->call('saveAttendanceStatus')->assertHasNoErrors();
        $this->assertSame(17, $status->fresh()->default_points);
        $this->assertSame('Updated remote attendance', $status->fresh()->name);
        $this->assertDatabaseCount('point_transactions', 0);

        Volt::test('settings.tracking', ['section' => 'attendance'])->call('openAssessmentTypeModal')->assertForbidden();
    }

    public static function attendanceSettingsTenants(): array
    {
        return [
            'student-arabic' => ['student_attendance', 'ar'],
            'student-english' => ['student_attendance', 'en'],
            'teacher-arabic' => ['teacher_attendance', 'ar'],
            'teacher-english' => ['teacher_attendance', 'en'],
        ];
    }

    public function test_assessment_types_can_be_managed_without_points(): void
    {
        $this->modules(['assessments']);
        $this->admin();
        $this->get(route('settings.organization'))->assertOk()
            ->assertSee('href="'.route('settings.assessment-types').'"', false)
            ->assertDontSee('href="'.route('settings.attendance-statuses').'"', false)
            ->assertDontSee('href="'.route('settings.points').'"', false);
        $this->get(route('settings.assessment-types'))->assertOk()
            ->assertSee(__('settings.tracking.sections.assessment_type.table'))
            ->assertDontSee('wire:model="point_policy_points"', false)
            ->assertDontSee('wire:submit="saveAttendanceStatus"', false);

        $editor = Volt::test('settings.tracking', ['section' => 'assessments'])
            ->call('openAssessmentTypeModal')->assertSee(__('settings.tracking.fields.is_scored'))
            ->set('assessment_type_name', 'Weekly quiz')
            ->set('assessment_type_code', 'weekly-quiz')->set('assessment_type_is_scored', true)
            ->call('saveAssessmentType')->assertHasNoErrors();
        $type = AssessmentType::where('code', 'weekly-quiz')->firstOrFail();
        $this->assertTrue($type->is_scored);
        $editor->call('editAssessmentType', $type->id)->set('assessment_type_name', 'Updated quiz')
            ->call('saveAssessmentType')->assertHasNoErrors();
        $this->assertSame('Updated quiz', $type->fresh()->name);
        $this->assertDatabaseCount('point_transactions', 0);

        Volt::test('settings.tracking', ['section' => 'assessments'])->call('openAttendanceStatusModal')->assertForbidden();
        Volt::test('settings.tracking', ['section' => 'assessments'])->call('saveSaberRules')->assertForbidden();
        Volt::test('settings.points')->assertForbidden();
    }

    public function test_operational_settings_require_their_own_modules_even_when_points_is_enabled(): void
    {
        $this->modules(['points_rewards']);
        $this->admin();
        $this->get(route('settings.points'))->assertOk()
            ->assertDontSee('wire:submit="saveAttendanceStatus"', false)
            ->assertDontSee('wire:submit="saveAssessmentType"', false);
        $this->get(route('settings.attendance-statuses'))->assertForbidden();
        $this->get(route('settings.assessment-types'))->assertForbidden();
        Volt::test('settings.tracking', ['section' => 'attendance'])->assertForbidden();
        Volt::test('settings.tracking', ['section' => 'assessments'])->assertForbidden();
    }

    public function test_attendance_points_remain_editable_when_points_is_enabled(): void
    {
        $this->modules(['student_attendance', 'points_rewards']);
        $this->admin();
        Volt::test('settings.tracking', ['section' => 'attendance'])->call('openAttendanceStatusModal')
            ->assertSee('wire:model="attendance_status_default_points"', false)
            ->set('attendance_status_name', 'Rewarded attendance')->set('attendance_status_code', 'rewarded')
            ->set('attendance_status_default_points', '9')->call('saveAttendanceStatus')->assertHasNoErrors();
        $status = AttendanceStatus::where('code', 'rewarded')->firstOrFail();
        $this->assertSame(9, $status->default_points);
        Volt::test('settings.tracking', ['section' => 'attendance'])->call('editAttendanceStatus', $status->id)
            ->set('attendance_status_default_points', '12')->call('saveAttendanceStatus')->assertHasNoErrors();
        $this->assertSame(12, $status->fresh()->default_points);
    }

    public function test_operational_settings_recheck_module_access_before_saving(): void
    {
        $this->modules(['student_attendance', 'assessments', 'points_rewards']);
        $this->admin();
        $attendance = Volt::test('settings.tracking', ['section' => 'attendance']);
        $assessments = Volt::test('settings.tracking', ['section' => 'assessments']);
        $points = Volt::test('settings.points');
        $this->modules([]);

        $attendance->call('saveAttendanceStatus')->assertForbidden();
        $assessments->call('saveAssessmentType')->assertForbidden();
        $points->call('savePointType')->assertForbidden();
    }

    public function test_operational_settings_require_settings_manage_permission(): void
    {
        $this->modules(['student_attendance', 'assessments']);
        $this->actingAs(User::factory()->create(['is_active' => true]));
        $this->get(route('settings.attendance-statuses'))->assertForbidden();
        $this->get(route('settings.assessment-types'))->assertForbidden();
        Volt::test('settings.tracking', ['section' => 'attendance'])->assertForbidden();
        Volt::test('settings.tracking', ['section' => 'assessments'])->assertForbidden();
    }

    private function modules(array $codes): void
    {
        $ids = collect($codes)->map(fn (string $code) => Feature::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true])->id);
        $this->plan->features()->sync($ids);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
        Sanctum::actingAs($user, ['*'], 'web');

        return $user;
    }
}

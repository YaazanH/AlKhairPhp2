<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\Tenant;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
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

        $keys = collect(app(SidebarNavigationService::class)->sidebarFor(auth()->user()))
            ->flatMap(fn (array $group) => collect($group['items'])->pluck('key'))
            ->all();
        $this->assertContains('students', $keys);
        $this->assertNotContains('teachers', $keys);
        $this->assertNotContains('groups', $keys);
        $this->assertNotContains('student_attendance', $keys);
        $this->assertNotContains('teacher_attendance', $keys);
        $this->assertNotContains('curricula', $keys);
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
        ])->assertOk();
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

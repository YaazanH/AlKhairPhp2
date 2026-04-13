<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

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

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->actingAs($manager);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Management Dashboard')
            ->assertSee('Recent Groups')
            ->assertSee('Boys A');
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
}

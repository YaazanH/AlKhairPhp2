<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
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
            ->assertSee('Students by Group')
            ->assertSee('Memorization and Attendance')
            ->assertSee('Top 3 Students')
            ->assertSee('Top Groups by Memorisation')
            ->assertSee('Latest five attendance days')
            ->assertSee('Count')
            ->assertSee('Groups')
            ->assertSee('Boys A')
            ->assertSee('Memorized pages: 5')
            ->assertSee('Students attended: 1')
            ->assertSee('dashboard-line-point__tooltip', false)
            ->assertSee('dashboard-line-chart', false)
            ->assertSee('dashboard-treemap', false)
            ->assertSee('mx-auto mt-8 grid w-full max-w-xl', false)
            ->assertDontSee('stroke-dasharray', false)
            ->assertDontSee('Recent Groups')
            ->assertDontSee('Students with an active enrollment in the default course')
            ->assertDontSee('Excluded Group');

        Volt::test('dashboard')
            ->call('showManagerStudent', $student->id)
            ->assertSet('selectedManagerStudentId', $student->id)
            ->assertSee('Student Highlights')
            ->assertSee('Omar Ali')
            ->assertSee('42')
            ->assertSee('18');
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
            ->assertDontSee('Hidden Academic Year')
            ->assertSee('Supervisor Group')
            ->assertSee('Average student attendance')
            ->assertSee('100.0%')
            ->assertSee('Top 5 Students by Memorization')
            ->assertSee('Top Students by Points')
            ->assertSee('Curriculum progress')
            ->assertDontSee('Latest Memorization Entries')
            ->assertSee('Ranked Student')
            ->assertSee('dashboard-line-chart', false);

        Volt::test('dashboard')
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

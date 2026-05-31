<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillStudentAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_command_creates_missing_student_accounts_for_active_students_only_by_default(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Account Parent',
            'is_active' => true,
        ]);

        $activeStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Active',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $inactiveStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Inactive',
            'last_name' => 'Student',
            'birth_date' => '2011-01-01',
            'status' => 'inactive',
        ]);

        $this->artisan('students:backfill-accounts --all')
            ->assertExitCode(0);

        $activeStudent->refresh()->load('user');
        $inactiveStudent->refresh()->load('user');

        $this->assertNotNull($activeStudent->user_id);
        $this->assertNull($inactiveStudent->user_id);
        $this->assertSame($activeStudent->student_number, $activeStudent->user->username);
        $this->assertTrue($activeStudent->user->hasRole('student'));
        $this->assertTrue($activeStudent->user->is_active);
        $this->assertNotEmpty($activeStudent->user->issued_password);
    }

    public function test_command_can_optionally_include_inactive_students(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Inactive Parent',
            'is_active' => true,
        ]);

        $inactiveStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Inactive',
            'last_name' => 'Student',
            'birth_date' => '2011-01-01',
            'status' => 'inactive',
        ]);

        $this->artisan('students:backfill-accounts --all --include-inactive')
            ->assertExitCode(0);

        $inactiveStudent->refresh()->load('user');

        $this->assertNotNull($inactiveStudent->user_id);
        $this->assertSame($inactiveStudent->student_number, $inactiveStudent->user->username);
        $this->assertTrue($inactiveStudent->user->hasRole('student'));
        $this->assertFalse($inactiveStudent->user->is_active);
    }

    public function test_command_skips_students_that_already_have_accounts(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Skip Parent',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Existing',
            'last_name' => 'Account',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $this->artisan('students:backfill-accounts --all')
            ->assertExitCode(0);

        $userId = $student->fresh()->user_id;

        $this->artisan('students:backfill-accounts --all')
            ->assertExitCode(0);

        $this->assertSame($userId, $student->fresh()->user_id);
        $this->assertSame(1, Student::query()->whereNotNull('user_id')->count());
    }

    public function test_command_supports_dry_run(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Dry Parent',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Dry',
            'last_name' => 'Run',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $this->artisan('students:backfill-accounts --all --dry-run')
            ->assertExitCode(0);

        $this->assertNull($student->fresh()->user_id);
    }

    public function test_command_can_target_students_by_course_scope(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Scope Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Scope',
            'last_name' => 'Teacher',
            'phone' => '0944008123',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $targetCourse = Course::create([
            'name' => 'Target Course',
            'is_active' => true,
        ]);

        $otherCourse = Course::create([
            'name' => 'Other Course',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $targetGroup = Group::create([
            'course_id' => $targetCourse->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Target Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $targetStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Target',
            'last_name' => 'Scope',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Scope',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $targetStudent->id,
            'group_id' => $targetGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->artisan('students:backfill-accounts --course-id='.$targetCourse->id)
            ->assertExitCode(0);

        $this->assertNotNull($targetStudent->fresh()->user_id);
        $this->assertNull($otherStudent->fresh()->user_id);
    }
}

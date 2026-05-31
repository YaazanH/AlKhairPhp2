<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PointLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearStudentDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_clear_parent_links_and_force_active_students_inactive(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Parent Owner',
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

        $this->artisan('students:clear-data --all --clear-parents')
            ->assertExitCode(0);

        $this->assertNull($activeStudent->fresh()->parent_id);
        $this->assertSame('inactive', $activeStudent->fresh()->status);
        $this->assertNull($inactiveStudent->fresh()->parent_id);
        $this->assertSame('inactive', $inactiveStudent->fresh()->status);
    }

    public function test_command_can_delete_detached_parents_and_their_accounts(): void
    {
        $parentUser = User::factory()->create();
        $orphanParentUser = User::factory()->create();

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Detached Parent',
            'is_active' => true,
        ]);

        $orphanParent = ParentProfile::create([
            'user_id' => $orphanParentUser->id,
            'father_name' => 'Already Orphaned Parent',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Detached',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $this->artisan('students:clear-data --all --clear-parents --delete-parents')
            ->assertExitCode(0);

        $this->assertNull($student->fresh()->parent_id);
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
        $this->assertSoftDeleted('parents', ['id' => $orphanParent->id]);
        $this->assertDatabaseMissing('users', ['id' => $parentUser->id]);
        $this->assertDatabaseMissing('users', ['id' => $orphanParentUser->id]);
    }

    public function test_command_keeps_parents_that_still_have_other_students(): void
    {
        $parentUser = User::factory()->create();

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Shared Parent',
            'is_active' => true,
        ]);

        $targetStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Target',
            'last_name' => 'Keep Parent',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Keep Parent',
            'birth_date' => '2011-01-01',
            'status' => 'active',
        ]);

        $this->artisan(sprintf(
            'students:clear-data --student-number-from=%s --student-number-to=%s --clear-parents --delete-parents',
            $targetStudent->student_number,
            $targetStudent->student_number,
        ))->assertExitCode(0);

        $this->assertNull($targetStudent->fresh()->parent_id);
        $this->assertSame($parent->id, $otherStudent->fresh()->parent_id);
        $this->assertDatabaseHas('parents', ['id' => $parent->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $parentUser->id]);
    }

    public function test_command_can_clear_points_for_a_student_number_range_and_refresh_caches(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Points Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Points',
            'last_name' => 'Teacher',
            'phone' => '0944009001',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $course = Course::create([
            'name' => 'Points Course',
            'is_active' => true,
        ]);

        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Points Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $pointType = PointType::create([
            'name' => 'Manual',
            'code' => 'manual',
            'category' => 'manual',
            'default_points' => 0,
            'allow_manual_entry' => true,
            'allow_negative' => true,
            'is_active' => true,
        ]);

        $enteredBy = User::factory()->create();

        $targetStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Target',
            'last_name' => 'Points',
            'birth_date' => '2010-01-01',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Points',
            'birth_date' => '2010-01-01',
            'status' => 'active',
        ]);

        $targetEnrollment = Enrollment::create([
            'student_id' => $targetStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        PointTransaction::create([
            'student_id' => $targetStudent->id,
            'enrollment_id' => $targetEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 7,
            'entered_by' => $enteredBy->id,
            'entered_at' => now(),
        ]);

        PointTransaction::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $enteredBy->id,
            'entered_at' => now(),
        ]);

        app(PointLedgerService::class)->syncEnrollmentCaches($targetEnrollment->fresh(['student']));
        app(PointLedgerService::class)->syncEnrollmentCaches($otherEnrollment->fresh(['student']));

        $this->assertSame(7, $targetEnrollment->fresh()->final_points_cached);
        $this->assertSame(4, $otherEnrollment->fresh()->final_points_cached);

        $this->artisan(sprintf(
            'students:clear-data --student-number-from=%s --student-number-to=%s --clear-points',
            $targetStudent->student_number,
            $targetStudent->student_number,
        ))->assertExitCode(0);

        $this->assertSame(0, PointTransaction::query()->where('student_id', $targetStudent->id)->count());
        $this->assertSame(1, PointTransaction::query()->where('student_id', $otherStudent->id)->count());
        $this->assertSame(0, $targetEnrollment->fresh()->final_points_cached);
        $this->assertSame(4, $otherEnrollment->fresh()->final_points_cached);
    }

    public function test_command_supports_dry_run(): void
    {
        $parent = ParentProfile::create([
            'father_name' => 'Dry Run Parent',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Dry',
            'last_name' => 'Run',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        $this->artisan('students:clear-data --all --clear-parents --dry-run')
            ->assertExitCode(0);

        $this->assertSame($parent->id, $student->fresh()->parent_id);
        $this->assertSame('active', $student->fresh()->status);
    }
}

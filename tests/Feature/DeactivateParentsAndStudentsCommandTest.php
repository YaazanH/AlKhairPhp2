<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivateParentsAndStudentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_deactivates_parents_students_and_linked_users(): void
    {
        $parentUser = User::factory()->create(['is_active' => true]);
        $studentUser = User::factory()->create(['is_active' => true]);

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'أحمد محمد',
            'is_active' => true,
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'محمد',
            'last_name' => 'أحمد',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $this->artisan('people:deactivate-parents-students')
            ->assertExitCode(0);

        $this->assertFalse($parent->fresh()->is_active);
        $this->assertSame('inactive', $student->fresh()->status);
        $this->assertFalse($parentUser->fresh()->is_active);
        $this->assertFalse($studentUser->fresh()->is_active);
    }

    public function test_command_supports_dry_run(): void
    {
        $parentUser = User::factory()->create(['is_active' => true]);
        $studentUser = User::factory()->create(['is_active' => true]);

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'سامي خالد',
            'is_active' => true,
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'خالد',
            'last_name' => 'سامي',
            'birth_date' => '2011-01-01',
            'status' => 'graduated',
        ]);

        $this->artisan('people:deactivate-parents-students --dry-run')
            ->assertExitCode(0);

        $this->assertTrue($parent->fresh()->is_active);
        $this->assertSame('graduated', $student->fresh()->status);
        $this->assertTrue($parentUser->fresh()->is_active);
        $this->assertTrue($studentUser->fresh()->is_active);
    }

    public function test_command_can_leave_linked_users_active(): void
    {
        $parentUser = User::factory()->create(['is_active' => true]);
        $studentUser = User::factory()->create(['is_active' => true]);

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'باسل عمر',
            'is_active' => true,
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'عمر',
            'last_name' => 'باسل',
            'birth_date' => '2010-01-01',
            'status' => 'blocked',
        ]);

        $this->artisan('people:deactivate-parents-students --profiles-only')
            ->assertExitCode(0);

        $this->assertFalse($parent->fresh()->is_active);
        $this->assertSame('inactive', $student->fresh()->status);
        $this->assertTrue($parentUser->fresh()->is_active);
        $this->assertTrue($studentUser->fresh()->is_active);
    }
}

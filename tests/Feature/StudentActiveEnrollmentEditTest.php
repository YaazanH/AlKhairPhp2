<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentActiveEnrollmentEditTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private Enrollment $enrollment;

    private Group $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');
        $this->actingAs($user);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_active' => true, 'is_current' => true]);
        $course = Course::create(['name' => 'Active editing course', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'Test', 'last_name' => 'Teacher', 'phone' => '0944003311', 'status' => 'active']);
        $attributes = ['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'is_active' => true];
        $source = Group::create([...$attributes, 'name' => 'Original group']);
        $this->destination = Group::create([...$attributes, 'name' => 'Destination group']);
        $this->student = Student::create(['first_name' => 'Unique', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'status' => 'active']);
        $this->enrollment = Enrollment::create(['student_id' => $this->student->id, 'group_id' => $source->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'notes' => 'Retain this']);
    }

    public function test_pencil_enables_group_selection_and_save_preserves_the_enrollment(): void
    {
        $component = Volt::test('students.index')->call('edit', $this->student->id)
            ->assertSee('Active editing course')->assertSee('Original group')
            ->assertSee('data-student-active-group-edit', false)
            ->assertSet('activeEnrollmentGroupChanges', [])
            ->call('editActiveEnrollmentGroup', $this->enrollment->id)
            ->assertSet('activeEnrollmentGroupChanges.'.$this->enrollment->id, $this->enrollment->group_id)
            ->set('activeEnrollmentGroupChanges.'.$this->enrollment->id, $this->destination->id);
        $this->assertNotSame($this->destination->id, $this->enrollment->fresh()->group_id);
        $component->call('save')->assertHasNoErrors()->assertSet('showFormModal', false)
            ->assertSet('activeEnrollmentGroupChanges', []);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('enrollments', ['id' => $this->enrollment->id, 'group_id' => $this->destination->id, 'notes' => 'Retain this', 'status' => 'active']);
        $this->assertSame('2026-09-01', $this->enrollment->fresh()->enrolled_at->toDateString());
    }

    public function test_cancelling_keeps_the_original_group_and_clears_the_draft(): void
    {
        Volt::test('students.index')->call('edit', $this->student->id)
            ->call('editActiveEnrollmentGroup', $this->enrollment->id)
            ->set('activeEnrollmentGroupChanges.'.$this->enrollment->id, $this->destination->id)
            ->call('cancel')->assertSet('activeEnrollmentGroupChanges', []);
        $this->assertNotSame($this->destination->id, $this->enrollment->fresh()->group_id);
    }

    public function test_group_must_be_active_and_in_the_same_course(): void
    {
        $otherCourse = Course::create(['name' => 'Other course', 'is_active' => true]);
        $this->destination->update(['course_id' => $otherCourse->id]);
        $component = Volt::test('students.index')->call('edit', $this->student->id)
            ->call('editActiveEnrollmentGroup', $this->enrollment->id)
            ->set('activeEnrollmentGroupChanges.'.$this->enrollment->id, $this->destination->id)
            ->set('first_name', 'Should not save')->call('save')
            ->assertHasErrors('activeEnrollmentGroupChanges.'.$this->enrollment->id);
        $this->assertSame('Unique', $this->student->fresh()->first_name);
        $this->destination->update(['course_id' => $this->enrollment->group->course_id, 'is_active' => false]);
        $component->call('save')->assertHasErrors('activeEnrollmentGroupChanges.'.$this->enrollment->id);
        $this->assertNotSame($this->destination->id, $this->enrollment->fresh()->group_id);
    }

    public function test_inactive_course_enrollment_is_not_shown_or_editable(): void
    {
        $this->enrollment->group->course->update(['is_active' => false]);
        $component = Volt::test('students.index')->call('edit', $this->student->id)
            ->assertDontSee('data-student-active-enrollment', false);
        $this->expectException(ModelNotFoundException::class);
        $component->call('editActiveEnrollmentGroup', $this->enrollment->id);
    }

    public function test_enrollment_update_permission_is_required_even_for_a_tampered_draft(): void
    {
        $user = auth()->user();
        $user->roles->first()->revokePermissionTo('enrollments.update');
        $user->unsetRelation('roles');
        Volt::test('students.index')->call('edit', $this->student->id)
            ->assertDontSee('data-student-active-group-edit', false)
            ->set('activeEnrollmentGroupChanges.'.$this->enrollment->id, $this->destination->id)
            ->call('save')->assertForbidden();
        $this->assertNotSame($this->destination->id, $this->enrollment->fresh()->group_id);
    }
}

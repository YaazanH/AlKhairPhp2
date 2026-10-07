<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\GradeLevelFromAge;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentFormAutofillTest extends TestCase
{
    use RefreshDatabase;

    public function test_birth_year_selects_the_named_grade_even_when_sort_numbers_have_changed(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');
        $this->actingAs($user);
        AcademicYear::create(['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'is_active' => true]);
        $eighth = GradeLevel::create(['name' => 'الصف الثامن', 'sort_order' => 8, 'is_active' => true]);
        $ninth = GradeLevel::create(['name' => 'الصف التاسع', 'sort_order' => 50, 'is_active' => true]);
        Volt::test('students.index')->call('openCreateModal')->set('birth_date', '2013')
            ->assertSet('grade_level_id', $eighth->id)
            ->set('birth_date', '2012')->assertSet('grade_level_id', $ninth->id)
            ->set('birth_date', '')->assertSet('grade_level_id', null);
    }

    public function test_missing_or_inactive_grades_do_not_select_an_unrelated_sort_number(): void
    {
        GradeLevel::create(['name' => 'الصف الحادي عشر', 'sort_order' => 11, 'is_active' => true]);
        GradeLevel::create(['name' => 'الصف الثامن', 'sort_order' => 8, 'is_active' => false]);
        $this->assertNull(GradeLevelFromAge::resolve(6));
        $this->assertNull(GradeLevelFromAge::resolve(13));
        $university = GradeLevel::create(['name' => 'المرحلة الجامعية', 'sort_order' => 20, 'is_active' => true]);
        $this->assertSame($university->id, GradeLevelFromAge::resolve(19));
    }

    public function test_automatic_group_selection_prefers_fewer_active_enrollments_for_the_same_grade(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');
        $this->actingAs($user);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true, 'is_active' => true]);
        $grade = GradeLevel::create(['name' => 'الصف الثامن', 'sort_order' => 8, 'is_active' => true]);
        $course = Course::create(['name' => 'Current course', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'Test', 'last_name' => 'Teacher', 'phone' => '0944003311', 'status' => 'active']);
        $attributes = ['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $grade->id, 'is_active' => true];
        $busier = Group::create([...$attributes, 'name' => 'A group']);
        $quieter = Group::create([...$attributes, 'name' => 'Z group']);
        foreach ([$busier, $busier, $quieter, $quieter, $quieter] as $index => $group) {
            $student = Student::create(['first_name' => 'Student '.$index, 'last_name' => 'Test', 'birth_date' => '2013-01-01', 'status' => 'active']);
            Enrollment::create(['student_id' => $student->id, 'group_id' => $group->id, 'enrolled_at' => '2026-09-01', 'status' => $index < 3 ? 'active' : 'completed']);
        }

        Volt::test('students.index')->call('openCreateModal')->set('birth_date', '2013')
            ->assertSet('enrollment_group_id', $quieter->id);

        // The same balancing applies when there is no current academic year.
        $year->update(['is_current' => false]);
        Volt::test('students.index')->call('openCreateModal')->set('grade_level_id', $grade->id)
            ->assertSet('enrollment_group_id', $quieter->id);
    }
}

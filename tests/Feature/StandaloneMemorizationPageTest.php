<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StandaloneMemorizationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_workbench_uses_the_logged_in_teacher_for_new_memorization_entries(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-03')
            ->set('entry_type', 'new')
            ->set('from_page', '11')
            ->set('to_page', '13')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'from_page' => 11,
            'to_page' => 13,
            'pages_count' => 3,
        ]);
    }

    public function test_teacher_workbench_requires_group_selection_when_student_has_multiple_active_enrollments(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();

        Group::create([
            'course_id' => $enrollment->group->course_id,
            'academic_year_id' => $enrollment->group->academic_year_id,
            'teacher_id' => $enrollment->group->teacher_id,
            'name' => 'Second Memorization Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $secondGroup = Group::query()->where('name', 'Second Memorization Group')->firstOrFail();

        Enrollment::create([
            'student_id' => $enrollment->student_id,
            'group_id' => $secondGroup->id,
            'enrolled_at' => '2026-09-04',
            'status' => 'active',
        ]);

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-05')
            ->set('entry_type', 'new')
            ->set('from_page', '14')
            ->set('to_page', '16')
            ->call('save')
            ->assertHasErrors(['selectedEnrollmentId']);
    }

    public function test_teacher_workbench_warns_about_duplicate_pages_and_can_save_only_the_unique_pages(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-03')
            ->set('entry_type', 'new')
            ->set('from_page', '11')
            ->set('to_page', '13')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('memorization.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('recorded_on', '2026-09-04')
            ->set('entry_type', 'new')
            ->set('from_page', '12')
            ->set('to_page', '14')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDuplicateModal', true)
            ->assertSet('duplicatePages', [12, 13])
            ->assertSet('uniquePages', [14])
            ->call('confirmDuplicateSave')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'from_page' => 14,
            'to_page' => 14,
            'pages_count' => 1,
        ]);
    }

    public function test_teacher_quick_entry_warns_about_duplicate_pages_and_can_save_only_the_unique_pages(): void
    {
        [, $teacher, $enrollment] = $this->teacherMemorizationContext();

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('from_page', '21')
            ->set('to_page', '23')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('from_page', '22')
            ->set('to_page', '24')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDuplicateModal', true)
            ->assertSet('duplicatePages', [22, 23])
            ->assertSet('uniquePages', [24])
            ->call('confirmDuplicateSave')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'recorded_by_user_id' => auth()->id(),
            'from_page' => 24,
            'to_page' => 24,
            'pages_count' => 1,
        ]);
    }

    public function test_teacher_quick_entry_can_record_for_active_students_outside_their_own_group_scope(): void
    {
        [, $teacher] = $this->teacherMemorizationContext();

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0998111998',
            'status' => 'active',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Older Student Parent',
        ]);

        $olderStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Older',
            'last_name' => 'Student',
            'birth_date' => '2010-02-02',
            'status' => 'active',
        ]);

        $otherCourse = Course::create([
            'name' => 'Older Student Course',
            'is_active' => true,
        ]);

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

        $otherGroup = Group::create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Older Student Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $olderStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        Volt::test('memorization.quick-entry')
            ->assertSee('Older Student')
            ->set('selectedStudentId', $olderStudent->id)
            ->set('from_page', '31')
            ->set('to_page', '32')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $olderStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_by_user_id' => auth()->id(),
            'from_page' => 31,
            'to_page' => 32,
            'pages_count' => 2,
        ]);
    }

    public function test_non_teacher_user_with_memorization_permission_can_use_quick_entry_for_any_active_student(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();

        $operator = User::factory()->create([
            'username' => 'memorization-operator',
            'phone' => '0998111002',
        ]);
        $operator->givePermissionTo('memorization.record');

        $this->actingAs($operator);

        Volt::test('memorization.quick-entry')
            ->assertSee('Memorization Student')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('from_page', '41')
            ->set('to_page', '42')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $enrollment->group->teacher_id,
            'recorded_by_user_id' => $operator->id,
            'from_page' => 41,
            'to_page' => 42,
            'pages_count' => 2,
        ]);
    }

    public function test_non_teacher_quick_entry_requires_group_selection_when_student_has_multiple_active_enrollments(): void
    {
        [, , $enrollment] = $this->teacherMemorizationContext();

        Group::create([
            'course_id' => $enrollment->group->course_id,
            'academic_year_id' => $enrollment->group->academic_year_id,
            'teacher_id' => $enrollment->group->teacher_id,
            'name' => 'Second Quick Entry Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $secondGroup = Group::query()->where('name', 'Second Quick Entry Group')->firstOrFail();

        $secondEnrollment = Enrollment::create([
            'student_id' => $enrollment->student_id,
            'group_id' => $secondGroup->id,
            'enrolled_at' => '2026-09-06',
            'status' => 'active',
        ]);

        $operator = User::factory()->create([
            'username' => 'memorization-operator-groups',
            'phone' => '0998111003',
        ]);
        $operator->givePermissionTo('memorization.record');

        $this->actingAs($operator);

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('from_page', '43')
            ->set('to_page', '44')
            ->call('save')
            ->assertHasErrors(['selectedEnrollmentId']);

        Volt::test('memorization.quick-entry')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('selectedEnrollmentId', $secondEnrollment->id)
            ->set('from_page', '43')
            ->set('to_page', '44')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('memorization_sessions', [
            'enrollment_id' => $secondEnrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $secondGroup->teacher_id,
            'recorded_by_user_id' => $operator->id,
            'from_page' => 43,
            'to_page' => 44,
            'pages_count' => 2,
        ]);
    }

    private function teacherMemorizationContext(): array
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'username' => 'memorization-teacher',
            'phone' => '0998111000',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Memorization',
            'last_name' => 'Teacher',
            'phone' => '0998111001',
            'status' => 'active',
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Memorization Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Memorization',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Memorization Course',
            'is_active' => true,
        ]);

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Memorization Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser);

        return [$teacherUser, $teacher, $enrollment];
    }
}

<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_notes_page_requires_authentication(): void
    {
        $this->get(route('student-notes.index', absolute: false))->assertRedirect('/login');
    }

    public function test_manager_can_create_update_and_delete_student_notes(): void
    {
        [$student, $enrollment] = $this->managerNotesContext();

        Volt::test('student-notes.index')
            ->set('student_id', $student->id)
            ->set('enrollment_id', $enrollment->id)
            ->set('source', 'management')
            ->set('visibility', 'shared_internal')
            ->set('noted_at', '2026-09-10')
            ->set('body', 'Needs revised tajweed practice before the next weekly review.')
            ->call('save')
            ->assertHasNoErrors();

        $note = StudentNote::query()->firstOrFail();

        $this->assertDatabaseHas('student_notes', [
            'id' => $note->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'source' => 'management',
            'visibility' => 'shared_internal',
        ]);

        Volt::test('student-notes.index')
            ->call('edit', $note->id)
            ->set('visibility', 'visible_to_parent')
            ->set('body', 'Parent should review memorization at home twice this week.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('student_notes', [
            'id' => $note->id,
            'visibility' => 'visible_to_parent',
            'body' => 'Parent should review memorization at home twice this week.',
        ]);

        Volt::test('student-notes.index')
            ->call('delete', $note->id);

        $this->assertSoftDeleted('student_notes', [
            'id' => $note->id,
        ]);
    }

    public function test_student_notes_use_the_compact_date_form_and_generic_table_without_parent_names(): void
    {
        [$student] = $this->managerNotesContext();

        $otherParent = ParentProfile::create([
            'father_name' => 'Second Parent Name',
            'father_phone' => '0944000399',
        ]);

        Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'birth_date' => '2015-05-12',
            'status' => 'active',
        ]);

        StudentNote::create([
            'student_id' => $student->id,
            'author_id' => auth()->id(),
            'source' => 'management',
            'visibility' => 'shared_internal',
            'body' => 'Generic table note',
            'noted_at' => '2026-09-10 15:30:00',
        ]);

        Volt::test('student-notes.index')
            ->call('create')
            ->assertDontSee('admin-kpi-grid', false)
            ->assertSee('wire:model="noted_at" type="date"', false)
            ->assertDontSee('type="datetime-local"', false)
            ->assertDontSee('wire:model="enrollment_id"', false)
            ->assertSee('data-student-notes-generic-table', false)
            ->assertSee(__('notes.log.table.student'))
            ->assertSee(__('notes.log.table.date'))
            ->assertSee(__('notes.log.table.visibility'))
            ->assertSee(__('notes.log.table.note'))
            ->assertSee(__('notes.log.table.actions'))
            ->assertSee('data-student-notes-actions-column', false)
            ->assertSee('data-student-note-edit', false)
            ->assertSee('data-edit-action', false)
            ->assertSee('data-student-note-save', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertDontSee('data-create-and-new-action', false)
            ->assertDontSee('data-icon-name="save-new"', false)
            ->assertDontSee('data-student-note-delete', false)
            ->assertSee('Notes Student')
            ->assertDontSee('Notes Parent')
            ->assertDontSee('Second Parent Name')
            ->assertSee('10-09-2026')
            ->assertDontSee('15:30');

        Volt::test('student-notes.index')
            ->call('edit', StudentNote::query()->firstOrFail()->id)
            ->assertSee('data-student-note-update-action', false)
            ->assertSee('data-student-note-delete', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertSee('data-icon-name="delete"', false);
    }

    public function test_teacher_notes_are_scoped_to_assigned_students_and_own_entries(): void
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'name' => 'Scoped Teacher',
            'username' => 'scoped-teacher',
            'phone' => '0777000300',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Scoped',
            'last_name' => 'Teacher',
            'phone' => '0991000301',
            'status' => 'active',
        ]);

        $managerUser = User::factory()->create([
            'name' => 'Notes Manager',
            'username' => 'notes-manager',
            'phone' => '0777000302',
        ]);
        $managerUser->assignRole('manager');

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0991000303',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Teacher Notes Course',
            'is_active' => true,
        ]);

        $assignedParent = ParentProfile::create(['father_name' => 'Assigned Parent']);
        $otherParent = ParentProfile::create(['father_name' => 'Other Parent']);

        $assignedStudent = Student::create([
            'parent_id' => $assignedParent->id,
            'first_name' => 'Assigned',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2013-03-22',
            'status' => 'active',
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $assignedGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Assigned Notes Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Notes Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $assignedEnrollment = Enrollment::create([
            'student_id' => $assignedStudent->id,
            'group_id' => $assignedGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $sharedNote = StudentNote::create([
            'student_id' => $assignedStudent->id,
            'enrollment_id' => $assignedEnrollment->id,
            'author_id' => $managerUser->id,
            'source' => 'management',
            'visibility' => 'shared_internal',
            'body' => 'shared assigned note',
            'noted_at' => '2026-09-10 10:00:00',
        ]);

        StudentNote::create([
            'student_id' => $assignedStudent->id,
            'enrollment_id' => $assignedEnrollment->id,
            'author_id' => $managerUser->id,
            'source' => 'management',
            'visibility' => 'private_management',
            'body' => 'hidden management note',
            'noted_at' => '2026-09-10 11:00:00',
        ]);

        $teacherNote = StudentNote::create([
            'student_id' => $assignedStudent->id,
            'enrollment_id' => $assignedEnrollment->id,
            'author_id' => $teacherUser->id,
            'source' => 'teacher',
            'visibility' => 'private_teacher',
            'body' => 'my teacher note',
            'noted_at' => '2026-09-10 12:00:00',
        ]);

        StudentNote::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'author_id' => $managerUser->id,
            'source' => 'management',
            'visibility' => 'shared_internal',
            'body' => 'other group note',
            'noted_at' => '2026-09-10 13:00:00',
        ]);

        $this->actingAs($teacherUser);

        $this->get(route('student-notes.index', absolute: false))
            ->assertOk()
            ->assertSee('shared assigned note')
            ->assertSee('my teacher note')
            ->assertDontSee('hidden management note')
            ->assertDontSee('other group note');

        Volt::test('student-notes.index')
            ->call('edit', $teacherNote->id)
            ->set('body', 'updated teacher note')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('student_notes', [
            'id' => $teacherNote->id,
            'body' => 'updated teacher note',
        ]);

        Volt::test('student-notes.index')
            ->call('delete', $sharedNote->id)
            ->assertForbidden();

        Volt::test('student-notes.index')
            ->set('student_id', $otherStudent->id)
            ->set('source', 'teacher')
            ->set('visibility', 'shared_internal')
            ->set('noted_at', '2026-09-11')
            ->set('body', 'unauthorized note')
            ->call('save')
            ->assertForbidden();

        Volt::test('student-notes.index')
            ->set('student_id', $assignedStudent->id)
            ->set('enrollment_id', $assignedEnrollment->id)
            ->set('source', 'teacher')
            ->set('visibility', 'private_management')
            ->set('noted_at', '2026-09-11')
            ->set('body', 'bad visibility')
            ->call('save')
            ->assertHasErrors(['visibility']);
    }

    private function managerNotesContext(): array
    {
        $this->seed();

        $manager = User::factory()->create([
            'name' => 'Manager User',
            'username' => 'notes-manager-user',
            'phone' => '0666000300',
        ]);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $parent = ParentProfile::create([
            'father_name' => 'Notes Parent',
            'father_phone' => '0944000300',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Notes',
            'last_name' => 'Teacher',
            'phone' => '0944000301',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Notes Course',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Notes',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Notes Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        return [$student, $enrollment];
    }
}

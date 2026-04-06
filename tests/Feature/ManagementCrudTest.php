<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\StudentFile;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ManagementCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_parent_and_teacher_components_support_crud_operations(): void
    {
        $this->signIn();

        Volt::test('courses.index')
            ->set('name', 'Quran Foundations')
            ->set('description', 'Foundational memorization track')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $course = Course::query()->firstOrFail();

        Volt::test('courses.index')
            ->call('edit', $course->id)
            ->set('description', 'Updated course description')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'description' => 'Updated course description',
            'is_active' => false,
        ]);

        Volt::test('parents.index')
            ->set('father_name', 'Ahmad Ali')
            ->set('father_phone', '0944000000')
            ->set('mother_name', 'Mona Ali')
            ->set('mother_phone', '0944000001')
            ->set('notes', 'Primary family contact')
            ->call('save')
            ->assertHasNoErrors();

        $parent = ParentProfile::query()->firstOrFail();

        $this->assertNotNull($parent->user_id);
        $this->assertTrue($parent->user->hasRole('parent'));

        Volt::test('parents.index')
            ->call('edit', $parent->id)
            ->set('father_work', 'Engineer')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parents', [
            'id' => $parent->id,
            'father_work' => 'Engineer',
        ]);

        Volt::test('teachers.index')
            ->set('first_name', 'Yousef')
            ->set('last_name', 'Teacher')
            ->set('phone', '0944000002')
            ->set('job_title', 'Lead Teacher')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $teacher = Teacher::query()->firstOrFail();

        $this->assertNotNull($teacher->user_id);
        $this->assertTrue($teacher->user->hasRole('teacher'));

        Volt::test('teachers.index')
            ->call('edit', $teacher->id)
            ->set('status', 'blocked')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id,
            'status' => 'blocked',
        ]);

        Volt::test('courses.index')
            ->call('delete', $course->id);

        Volt::test('parents.index')
            ->call('delete', $parent->id);

        Volt::test('teachers.index')
            ->call('delete', $teacher->id);

        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
        $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
    }

    public function test_profile_account_access_is_managed_separately_from_profile_data(): void
    {
        $this->signIn();

        $gradeLevel = GradeLevel::create([
            'name' => 'Grade 5',
            'sort_order' => 15,
            'is_active' => true,
        ]);

        Volt::test('parents.index')
            ->set('father_name', 'Account Parent')
            ->set('father_phone', '0944111000')
            ->call('save')
            ->assertHasNoErrors();

        $parent = ParentProfile::query()->firstOrFail();

        Volt::test('teachers.index')
            ->set('first_name', 'Account')
            ->set('last_name', 'Teacher')
            ->set('phone', '0944111001')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $teacher = Teacher::query()->firstOrFail();

        Volt::test('students.index')
            ->set('parent_id', $parent->id)
            ->set('first_name', 'Account')
            ->set('last_name', 'Student')
            ->set('birth_date', '2015-04-01')
            ->set('grade_level_id', $gradeLevel->id)
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $student = Student::query()->firstOrFail();

        $this->assertNotNull($parent->fresh()->user?->issued_password);
        $this->assertNotNull($teacher->fresh()->user?->issued_password);
        $this->assertNotNull($student->fresh()->user?->issued_password);

        Volt::test('parents.index')
            ->call('openAccountModal', $parent->id)
            ->set('account_password', 'ParentPass123!')
            ->call('saveAccount')
            ->assertHasNoErrors();

        Volt::test('teachers.index')
            ->call('openAccountModal', $teacher->id)
            ->set('account_password', 'TeacherPass123!')
            ->call('saveAccount')
            ->assertHasNoErrors();

        Volt::test('students.index')
            ->call('openAccountModal', $student->id)
            ->set('account_password', 'StudentPass123!')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $parentUser = $parent->fresh()->user;
        $teacherUser = $teacher->fresh()->user;
        $studentUser = $student->fresh()->user;

        $this->assertSame('ParentPass123!', $parentUser->issued_password);
        $this->assertSame('TeacherPass123!', $teacherUser->issued_password);
        $this->assertSame('StudentPass123!', $studentUser->issued_password);
        $this->assertTrue(Hash::check('ParentPass123!', $parentUser->password));
        $this->assertTrue(Hash::check('TeacherPass123!', $teacherUser->password));
        $this->assertTrue(Hash::check('StudentPass123!', $studentUser->password));
    }

    public function test_student_group_and_enrollment_components_support_crud_and_delete_guards(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
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

        $gradeLevel = GradeLevel::create([
            'name' => 'Grade 6',
            'sort_order' => 16,
            'is_active' => true,
        ]);

        $studentComponent = Volt::test('students.index')
            ->call('openCreateModal')
            ->call('openQuickParentForm')
            ->set('quick_parent_father_name', 'Maher Hasan')
            ->set('quick_parent_father_phone', '0944000010')
            ->set('quick_parent_mother_name', 'Sana Hasan')
            ->set('quick_parent_mother_phone', '0944000012')
            ->call('saveQuickParent')
            ->assertHasNoErrors();

        $parent = ParentProfile::query()->firstOrFail();

        $studentComponent
            ->set('first_name', 'Omar')
            ->set('last_name', 'Hasan')
            ->set('birth_date', '2014-05-12')
            ->set('gender', 'male')
            ->set('school_name', 'Alkhair School')
            ->set('grade_level_id', $gradeLevel->id)
            ->set('status', 'active')
            ->set('joined_at', '2026-09-01')
            ->call('save')
            ->assertHasNoErrors();

        $student = Student::query()->firstOrFail();

        $this->assertNotNull($student->user_id);
        $this->assertTrue($student->user->hasRole('student'));

        Volt::test('groups.index')
            ->set('course_id', $course->id)
            ->set('academic_year_id', $academicYear->id)
            ->set('teacher_id', $teacher->id)
            ->set('grade_level_id', $gradeLevel->id)
            ->set('name', 'Boys A')
            ->set('capacity', '20')
            ->set('starts_on', '2026-09-01')
            ->set('monthly_fee', '25.00')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $group = Group::query()->firstOrFail();

        Volt::test('groups.index')
            ->call('openRosterModal', $group->id)
            ->set('roster_student_id', $student->id)
            ->set('roster_enrolled_at', '2026-09-01')
            ->call('addStudentToRoster')
            ->assertHasNoErrors();

        $enrollment = Enrollment::query()->firstOrFail();

        Volt::test('groups.index')
            ->call('openRosterModal', $group->id)
            ->set('roster_student_id', $student->id)
            ->set('roster_enrolled_at', '2026-10-01')
            ->call('addStudentToRoster')
            ->assertHasErrors(['roster_student_id']);

        Volt::test('enrollments.index')
            ->set('student_id', $student->id)
            ->set('group_id', $group->id)
            ->set('enrolled_at', '2026-10-01')
            ->set('status', 'active')
            ->call('save')
            ->assertHasErrors(['student_id']);

        Volt::test('students.index')
            ->call('delete', $student->id)
            ->assertHasErrors(['delete']);

        Volt::test('groups.index')
            ->call('delete', $group->id)
            ->assertHasErrors(['delete']);

        Volt::test('parents.index')
            ->call('delete', $parent->id)
            ->assertHasErrors(['delete']);

        Volt::test('enrollments.index')
            ->call('edit', $enrollment->id)
            ->set('status', 'completed')
            ->set('left_at', '2027-05-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'status' => 'completed',
        ]);

        $this->assertSame('2027-05-01', $enrollment->fresh()->left_at?->format('Y-m-d'));

        Volt::test('groups.index')
            ->call('openRosterModal', $group->id)
            ->call('removeStudentFromRoster', $enrollment->id);

        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);

        Volt::test('groups.index')
            ->call('edit', $group->id)
            ->set('capacity', '25')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('groups.index')
            ->call('delete', $group->id);

        Volt::test('students.index')
            ->call('edit', $student->id)
            ->set('school_name', 'Updated School')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('students.index')
            ->call('delete', $student->id);

        Volt::test('parents.index')
            ->call('delete', $parent->id);

        $this->assertSoftDeleted('groups', ['id' => $group->id]);
        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
    }

    public function test_view_only_access_cannot_create_records(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'name' => 'View Only User',
            'username' => 'view-only-user',
            'phone' => '0888888888',
        ]);

        $user->givePermissionTo(Permission::findByName('courses.view', 'web'));

        $this->actingAs($user);

        Volt::test('courses.index')
            ->set('name', 'Unauthorized Course')
            ->call('save')
            ->assertForbidden();
    }

    public function test_group_schedules_component_supports_crud_operations(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Schedule',
            'last_name' => 'Teacher',
            'phone' => '0944001100',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Schedule Course',
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
            'name' => 'Schedule Group',
            'capacity' => 18,
            'is_active' => true,
        ]);

        Volt::test('groups.schedules', ['group' => $group])
            ->set('day_of_week', '6')
            ->set('starts_at', '15:00')
            ->set('ends_at', '17:00')
            ->set('room_name', 'Room A')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $schedule = GroupSchedule::query()->firstOrFail();

        Volt::test('groups.schedules', ['group' => $group])
            ->call('edit', $schedule->id)
            ->set('room_name', 'Room B')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('group_schedules', [
            'id' => $schedule->id,
            'room_name' => 'Room B',
        ]);

        $this->assertSame('15:00', $schedule->fresh()->starts_at?->format('H:i'));

        Volt::test('groups.schedules', ['group' => $group])
            ->call('delete', $schedule->id);

        $this->assertDatabaseMissing('group_schedules', [
            'id' => $schedule->id,
        ]);
    }

    public function test_student_media_component_supports_photo_and_file_uploads(): void
    {
        $this->signIn();

        Storage::fake('public');

        $parent = ParentProfile::create([
            'father_name' => 'Media Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Media',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        Volt::test('students.files', ['student' => $student])
            ->set('photo_upload', UploadedFile::fake()->create('student-photo.jpg', 128, 'image/jpeg'))
            ->call('savePhoto')
            ->assertHasNoErrors();

        $student->refresh();

        $this->assertNotNull($student->photo_path);
        Storage::disk('public')->assertExists($student->photo_path);

        Volt::test('students.files', ['student' => $student])
            ->set('file_type', 'identity')
            ->set('file_upload', UploadedFile::fake()->create('id-card.pdf', 128, 'application/pdf'))
            ->call('uploadFile')
            ->assertHasNoErrors();

        $studentFile = StudentFile::query()->firstOrFail();

        $this->assertDatabaseHas('student_files', [
            'id' => $studentFile->id,
            'student_id' => $student->id,
            'file_type' => 'identity',
        ]);

        Storage::disk('public')->assertExists($studentFile->file_path);

        Volt::test('students.files', ['student' => $student])
            ->call('deleteFile', $studentFile->id);

        $this->assertSoftDeleted('student_files', [
            'id' => $studentFile->id,
        ]);

        Volt::test('students.files', ['student' => $student])
            ->call('removePhoto');

        $this->assertNull($student->fresh()->photo_path);
    }

    private function signIn(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'name' => 'Manager User',
            'username' => 'manager-user',
            'phone' => '0999999999',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);
    }
}

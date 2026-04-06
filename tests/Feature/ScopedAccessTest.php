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
use App\Services\AccessScopeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ScopedAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_management_pages_only_show_self_and_assigned_group_records(): void
    {
        $this->seed(RoleSeeder::class);

        [$course, $academicYear] = $this->createAcademicContext();
        [$teacherUser, $teacherProfile] = $this->createTeacherUser('scoped-teacher', '7100001', 'Salim', 'Adib');
        $teacherUser->givePermissionTo(['teachers.view', 'groups.view', 'students.view', 'enrollments.view']);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0944000022',
            'status' => 'active',
        ]);

        $assignedGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacherProfile->id,
            'name' => 'Assigned Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $assignedParent = ParentProfile::create([
            'father_name' => 'Assigned Parent',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Parent',
        ]);

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
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $assignedStudent->id,
            'group_id' => $assignedGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser);

        $this->get(route('teachers.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Salim Adib')
            ->assertDontSeeText('Other Teacher');

        $this->get(route('groups.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Assigned Group')
            ->assertDontSeeText('Other Group');

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Assigned Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Assigned Group')
            ->assertSeeText('Assigned Student')
            ->assertDontSeeText('Other Group')
            ->assertDontSeeText('Other Student');
    }

    public function test_teacher_cannot_edit_records_outside_their_scope(): void
    {
        $this->seed(RoleSeeder::class);

        [$course, $academicYear] = $this->createAcademicContext();
        [$teacherUser, $teacherProfile] = $this->createTeacherUser('scoped-editor', '7100002', 'Editor', 'Teacher');
        $teacherUser->givePermissionTo([
            'teachers.view',
            'teachers.update',
            'groups.view',
            'groups.update',
            'students.view',
            'students.update',
            'enrollments.view',
            'enrollments.update',
        ]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Locked',
            'last_name' => 'Teacher',
            'phone' => '0944000033',
            'status' => 'active',
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacherProfile->id,
            'name' => 'Own Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Locked Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $ownParent = ParentProfile::create(['father_name' => 'Own Parent']);
        $otherParent = ParentProfile::create(['father_name' => 'Locked Parent']);

        $ownStudent = Student::create([
            'parent_id' => $ownParent->id,
            'first_name' => 'Own',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Locked',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser);

        Volt::test('teachers.index')
            ->call('edit', $otherTeacher->id)
            ->assertForbidden();

        Volt::test('groups.index')
            ->call('edit', $otherGroup->id)
            ->assertForbidden();

        Volt::test('students.index')
            ->call('edit', $otherStudent->id)
            ->assertForbidden();

        Volt::test('enrollments.index')
            ->call('edit', $otherEnrollment->id)
            ->assertForbidden();
    }

    public function test_manual_group_scope_overrides_expand_teacher_visibility_without_full_access(): void
    {
        $this->seed(RoleSeeder::class);

        [$course, $academicYear] = $this->createAcademicContext();
        [$teacherUser, $teacherProfile] = $this->createTeacherUser('override-teacher', '7100003', 'Override', 'Teacher');
        $teacherUser->givePermissionTo(['groups.view', 'students.view', 'enrollments.view']);

        $otherTeacher = Teacher::create([
            'first_name' => 'External',
            'last_name' => 'Teacher',
            'phone' => '0944000044',
            'status' => 'active',
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacherProfile->id,
            'name' => 'Own Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $overrideGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Override Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $hiddenGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Hidden Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'parent_id' => ParentProfile::create(['father_name' => 'Own Family'])->id,
            'first_name' => 'Own',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $overrideStudent = Student::create([
            'parent_id' => ParentProfile::create(['father_name' => 'Override Family'])->id,
            'first_name' => 'Override',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        $hiddenStudent = Student::create([
            'parent_id' => ParentProfile::create(['father_name' => 'Hidden Family'])->id,
            'first_name' => 'Hidden',
            'last_name' => 'Student',
            'birth_date' => '2014-05-14',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $overrideStudent->id,
            'group_id' => $overrideGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $hiddenStudent->id,
            'group_id' => $hiddenGroup->id,
            'enrolled_at' => '2026-09-03',
            'status' => 'active',
        ]);

        app(AccessScopeService::class)->syncUserOverrides($teacherUser, [
            'group' => [$overrideGroup->id],
        ]);

        $this->actingAs($teacherUser);

        $this->get(route('groups.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Own Group')
            ->assertSeeText('Override Group')
            ->assertDontSeeText('Hidden Group');

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Own Student')
            ->assertSeeText('Override Student')
            ->assertDontSeeText('Hidden Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Own Group')
            ->assertSeeText('Override Group')
            ->assertDontSeeText('Hidden Group');
    }

    public function test_parent_permissions_are_scoped_to_their_family_records(): void
    {
        $this->seed(RoleSeeder::class);

        [$course, $academicYear] = $this->createAcademicContext();
        $teacher = Teacher::create([
            'first_name' => 'Parent',
            'last_name' => 'Teacher',
            'phone' => '0944000055',
            'status' => 'active',
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Parent Group',
            'capacity' => 14,
            'is_active' => true,
        ]);

        $parentUser = User::factory()->create([
            'username' => 'scoped-parent',
            'phone' => '7100004',
        ]);
        $parentUser->assignRole('parent');
        $parentUser->givePermissionTo(['parents.view', 'students.view', 'enrollments.view']);

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Scoped Parent',
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Parent',
        ]);

        $ownStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Family',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $this->actingAs($parentUser);

        $this->get(route('parents.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Scoped Parent')
            ->assertDontSeeText('Other Parent');

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Family Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Family Student')
            ->assertDontSeeText('Other Student');
    }

    public function test_student_permissions_are_scoped_to_their_own_profile_and_enrollments(): void
    {
        $this->seed(RoleSeeder::class);

        [$course, $academicYear] = $this->createAcademicContext();
        $teacher = Teacher::create([
            'first_name' => 'Student',
            'last_name' => 'Teacher',
            'phone' => '0944000066',
            'status' => 'active',
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Scope Group',
            'capacity' => 14,
            'is_active' => true,
        ]);

        $studentUser = User::factory()->create([
            'username' => 'scoped-student',
            'phone' => '7100005',
        ]);
        $studentUser->assignRole('student');
        $studentUser->givePermissionTo(['students.view', 'enrollments.view']);

        $parent = ParentProfile::create([
            'father_name' => 'Student Parent',
        ]);

        $ownStudent = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Scoped',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $this->actingAs($studentUser);

        $this->get(route('students.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Scoped Student')
            ->assertDontSeeText('Other Student');

        $this->get(route('enrollments.index', absolute: false))
            ->assertOk()
            ->assertSeeText('Scoped Student')
            ->assertDontSeeText('Other Student');
    }

    private function createAcademicContext(): array
    {
        $course = Course::create([
            'name' => 'Scoped Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        return [$course, $academicYear];
    }

    private function createTeacherUser(string $username, string $phone, string $firstName, string $lastName): array
    {
        $user = User::factory()->create([
            'username' => $username,
            'phone' => $phone,
        ]);

        $user->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => '09'.$phone,
            'status' => 'active',
        ]);

        return [$user, $teacher];
    }
}

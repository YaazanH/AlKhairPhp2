<?php

namespace Tests\Feature;

use App\Models\AttendanceStatus;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\StudentFile;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ParentNumberService;
use App\Services\StudentNumberService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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
        $teacherAccessRole = Role::query()->create([
            'name' => 'lead-teacher',
            'guard_name' => 'web',
        ]);

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

        $this->assertSame('P'.str_pad((string) $parent->id, 6, '0', STR_PAD_LEFT), $parent->parent_number);
        $this->assertNotNull($parent->user_id);
        $this->assertTrue($parent->user->hasRole('parent'));
        $this->assertSame($parent->parent_number, $parent->user->username);

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
            ->set('access_role_id', (string) $teacherAccessRole->id)
            ->set('course_id', $course->id)
            ->set('status', 'active')
            ->set('is_helping', true)
            ->call('save')
            ->assertHasNoErrors();

        $teacher = Teacher::query()->firstOrFail();

        $this->assertNotNull($teacher->user_id);
        $this->assertFalse($teacher->user->hasRole('teacher'));
        $this->assertTrue($teacher->user->hasRole($teacherAccessRole->name));
        $this->assertSame($teacherAccessRole->id, $teacher->access_role_id);
        $this->assertSame($course->id, $teacher->course_id);
        $this->assertTrue($teacher->is_helping);

        Volt::test('teachers.index')
            ->call('edit', $teacher->id)
            ->set('status', 'blocked')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('teachers.index')
            ->call('toggleHelping', $teacher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id,
            'status' => 'blocked',
            'is_helping' => false,
        ]);

        Volt::test('courses.index')
            ->call('delete', $course->id);

        $parentUserId = $parent->fresh()->user_id;
        $teacherUserId = $teacher->fresh()->user_id;

        Volt::test('parents.index')
            ->call('delete', $parent->id);

        Volt::test('teachers.index')
            ->call('delete', $teacher->id);

        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
        $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
        $this->assertDatabaseMissing('users', ['id' => $parentUserId]);
        $this->assertDatabaseMissing('users', ['id' => $teacherUserId]);
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
            ->set('birth_date', '2015')
            ->set('grade_level_id', $gradeLevel->id)
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $student = Student::query()->firstOrFail();
        $this->assertSame('2015-01-01', $student->birth_date?->format('Y-m-d'));

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

    public function test_editing_parent_with_duplicate_primary_phone_uses_another_available_phone_for_the_linked_user(): void
    {
        $this->signIn();

        User::factory()->create([
            'name' => 'Existing Phone Owner',
            'username' => 'existing-phone-owner',
            'phone' => '0944999000',
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Legacy Parent',
            'father_work' => 'Engineer',
            'father_phone' => '0944999000',
            'mother_name' => 'Legacy Mother',
            'mother_phone' => '0944999001',
            'home_phone' => null,
            'address' => 'Damascus',
            'notes' => 'Needs a linked account.',
            'is_active' => true,
        ]);

        Volt::test('parents.index')
            ->call('edit', $parent->id)
            ->set('father_name', 'Legacy Parent Updated')
            ->call('save')
            ->assertHasNoErrors();

        $parent->refresh()->load('user');

        $this->assertNotNull($parent->user_id);
        $this->assertSame('Legacy Parent Updated', $parent->father_name);
        $this->assertSame($parent->parent_number, $parent->user->username);
        $this->assertSame('0944999001', $parent->user->phone);
        $this->assertTrue($parent->user->hasRole('parent'));
    }

    public function test_parent_tab_can_open_a_child_list_action_for_linked_students(): void
    {
        $this->signIn();

        $parent = ParentProfile::query()->create([
            'father_name' => 'Family Contact',
            'father_work' => 'Merchant',
            'father_phone' => '0944777000',
            'mother_name' => 'Family Mother',
            'mother_phone' => '0944777001',
            'address' => 'Damascus',
            'is_active' => true,
        ]);

        Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Ali',
            'last_name' => 'Family',
            'birth_date' => '2015-01-01',
            'status' => 'active',
        ]);

        Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Mona',
            'last_name' => 'Family',
            'birth_date' => '2014-01-01',
            'status' => 'graduated',
        ]);

        Volt::test('parents.index')
            ->call('openChildrenModal', $parent->id)
            ->assertSet('showChildrenModal', true)
            ->assertSee('Ali Family')
            ->assertSee('Mona Family')
            ->assertSee('Children');
    }

    public function test_student_group_and_enrollment_components_support_crud_and_delete_guards(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Salim',
            'last_name' => 'Adib',
            'phone' => '0944000011',
            'status' => 'active',
            'is_helping' => true,
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

        $this->assertNotNull($parent->user_id);
        $this->assertTrue($parent->user->hasRole('parent'));
        $this->assertNotEmpty($parent->user->issued_password);
        $this->assertSame($parent->parent_number, $parent->user->username);

        $studentComponent
            ->set('first_name', 'Omar')
            ->set('last_name', 'Hasan')
            ->set('student_phone', '0944999911')
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
        $this->assertSame($student->student_number, $student->user->username);
        $this->assertSame('0944999911', $student->user->phone);

        Volt::test('groups.index')
            ->set('course_id', $course->id)
            ->set('academic_year_id', $academicYear->id)
            ->set('teacher_id', $teacher->id)
            ->set('grade_level_id', $gradeLevel->id)
            ->set('name', 'Boys A')
            ->set('capacity', '20')
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

        $studentUserId = $student->fresh()->user_id;
        $parentUserId = $parent->fresh()->user_id;

        Volt::test('students.index')
            ->call('delete', $student->id);

        Volt::test('parents.index')
            ->call('delete', $parent->id);

        $this->assertSoftDeleted('groups', ['id' => $group->id]);
        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
        $this->assertDatabaseMissing('users', ['id' => $studentUserId]);
        $this->assertDatabaseMissing('users', ['id' => $parentUserId]);
    }

    public function test_enrollment_create_and_new_keeps_selected_group_and_ignores_exit_fields(): void
    {
        $this->signIn();

        $parent = ParentProfile::create([
            'father_name' => 'Fast Parent',
            'father_phone' => '0944001111',
            'mother_name' => 'Fast Mother',
            'mother_phone' => '0944001112',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Fast',
            'last_name' => 'Teacher',
            'phone' => '0944001113',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $course = Course::create([
            'name' => 'Fast Enrollment Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2027/2028',
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-07-31',
            'is_active' => true,
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Fast Enrollment Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Fast',
            'last_name' => 'Student',
            'birth_date' => '2016-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);

        Volt::test('enrollments.index')
            ->call('openCreateModal')
            ->set('group_id', $group->id)
            ->set('student_id', $student->id)
            ->set('enrolled_at', '2027-09-01')
            ->set('status', 'active')
            ->set('left_at', '2028-01-01')
            ->set('notes', 'Should not be stored on create.')
            ->call('saveAndNew')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', true)
            ->assertSet('group_id', $group->id)
            ->assertSet('student_id', null)
            ->assertSet('left_at', '')
            ->assertSet('notes', '');

        $enrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();

        $this->assertNull($enrollment->left_at);
        $this->assertNull($enrollment->notes);
    }

    public function test_group_create_modal_defaults_to_current_year_and_create_and_new_preserves_course(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Group',
            'last_name' => 'Teacher',
            'phone' => '0944003111',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $course = Course::create([
            'name' => 'Repeat Group Course',
            'is_active' => true,
        ]);

        $olderYear = AcademicYear::create([
            'name' => '2025/2026',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_current' => false,
            'is_active' => true,
        ]);

        $currentYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        Volt::test('groups.index')
            ->call('openCreateModal')
            ->assertSet('academic_year_id', $currentYear->id)
            ->set('course_id', $course->id)
            ->set('academic_year_id', $olderYear->id)
            ->set('teacher_id', $teacher->id)
            ->set('name', 'Legacy Group A')
            ->set('capacity', '20')
            ->set('is_active', true)
            ->call('createAndNew')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', true)
            ->assertSet('course_id', $course->id)
            ->assertSet('academic_year_id', $currentYear->id)
            ->assertSet('teacher_id', null)
            ->assertSet('name', '');

        $this->assertDatabaseHas('groups', [
            'course_id' => $course->id,
            'academic_year_id' => $olderYear->id,
            'name' => 'Legacy Group A',
        ]);
    }

    public function test_group_roster_shows_extended_student_and_parent_details(): void
    {
        $this->signIn();

        $gradeLevel = GradeLevel::create([
            'name' => 'Grade 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026 / 2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);

        $course = Course::create([
            'name' => 'Roster Course',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Roster',
            'last_name' => 'Teacher',
            'phone' => '0944003112',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Fares Hamdan',
            'mother_name' => 'Mona Hamdan',
            'father_phone' => '0999000001',
            'mother_phone' => '0999000002',
            'home_phone' => '0111234567',
            'is_active' => true,
        ]);

        $studentUser = User::factory()->create([
            'name' => 'Hasan Hamdan',
            'username' => 'student-roster',
            'phone' => '0999000003',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Hasan',
            'last_name' => 'Hamdan',
            'student_number' => 'S000777',
            'birth_date' => '2013-01-01',
            'grade_level_id' => $gradeLevel->id,
            'status' => 'active',
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'grade_level_id' => $gradeLevel->id,
            'name' => 'Roster Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $student->refresh();
        $parent->refresh();

        Volt::test('groups.index')
            ->call('openRosterModal', $group->id)
            ->assertSee($student->student_number)
            ->assertSee('0999000003')
            ->assertSee('Grade 7')
            ->assertSee($parent->parent_number)
            ->assertSee('Fares Hamdan')
            ->assertSee('Mona Hamdan')
            ->assertSee('0999000001');
    }

    public function test_group_quick_summary_shows_attendance_and_memorized_pages_for_the_selected_date(): void
    {
        $this->signIn();

        $present = AttendanceStatus::create([
            'name' => 'Present',
            'code' => 'present',
            'scope' => 'student',
            'default_points' => 0,
            'color' => '#22c55e',
            'is_present' => true,
            'is_default' => true,
            'is_active' => true,
        ]);

        $gradeLevel = GradeLevel::create([
            'name' => 'Grade 6',
            'sort_order' => 6,
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026 / 2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);

        $course = Course::create([
            'name' => 'Summary Course',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Summary',
            'last_name' => 'Teacher',
            'phone' => '0944004012',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Samer Hamdan',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Hasan',
            'last_name' => 'Hamdan',
            'student_number' => 'S000601',
            'birth_date' => '2013-01-01',
            'grade_level_id' => $gradeLevel->id,
            'status' => 'active',
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'grade_level_id' => $gradeLevel->id,
            'name' => 'Summary Group',
            'capacity' => 15,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $attendanceDay = GroupAttendanceDay::create([
            'group_id' => $group->id,
            'student_attendance_day_id' => null,
            'attendance_date' => '2026-09-10',
            'status' => 'open',
        ]);

        $attendanceDay->records()->create([
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $present->id,
        ]);

        $session = MemorizationSession::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'pages_count' => 3,
        ]);

        MemorizationSessionPage::insert([
            ['memorization_session_id' => $session->id, 'page_no' => 11],
            ['memorization_session_id' => $session->id, 'page_no' => 12],
            ['memorization_session_id' => $session->id, 'page_no' => 13],
        ]);

        Volt::test('groups.index')
            ->call('openQuickSummaryModal', $group->id)
            ->assertSet('showQuickSummaryModal', true)
            ->set('quickSummaryDate', '2026-09-10')
            ->assertSee('Hasan Hamdan')
            ->assertSee('Present')
            ->assertSee(__('crud.groups.quick_summary.copy_group_action'))
            ->assertSee(__('crud.groups.quick_summary.memorized_pages', ['pages' => '11-13']))
            ->call('copyQuickSummary')
            ->assertDispatched('admin-copy-text');
    }

    public function test_teacher_role_options_include_basic_management_roles(): void
    {
        $this->signIn();

        Volt::test('teachers.index')
            ->call('openCreateModal')
            ->assertSee(__('ui.roles.super_admin'))
            ->assertSee(__('ui.roles.admin'))
            ->assertSee(__('ui.roles.manager'));
    }

    public function test_student_search_matches_full_name_across_first_and_last_name(): void
    {
        $this->signIn();

        $firstParent = ParentProfile::create([
            'father_name' => 'Family One',
            'is_active' => true,
        ]);

        $secondParent = ParentProfile::create([
            'father_name' => 'Family Two',
            'is_active' => true,
        ]);

        Student::create([
            'parent_id' => $firstParent->id,
            'first_name' => 'Hasan',
            'last_name' => 'Hamdan',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        Student::create([
            'parent_id' => $secondParent->id,
            'first_name' => 'Hasan',
            'last_name' => 'Darwish',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        Student::create([
            'parent_id' => $secondParent->id,
            'first_name' => 'Omar',
            'last_name' => 'Hamdan',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        Volt::test('students.index')
            ->set('search', 'Hasan Hamdan')
            ->assertSee('Hasan Hamdan')
            ->assertDontSee('Hasan Darwish')
            ->assertDontSee('Omar Hamdan');
    }

    public function test_student_search_normalizes_arabic_name_variants(): void
    {
        $this->signIn();

        $targetParent = ParentProfile::create([
            'father_name' => 'أسرة الهدف',
            'is_active' => true,
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'أسرة أخرى',
            'is_active' => true,
        ]);

        Student::create([
            'parent_id' => $targetParent->id,
            'first_name' => 'إياد',
            'last_name' => 'أحمد',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'إياد',
            'last_name' => 'سليم',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        Volt::test('students.index')
            ->set('search', 'اياد احمد')
            ->assertSee('إياد أحمد')
            ->assertDontSee('إياد سليم');
    }

    public function test_student_bulk_status_can_deactivate_current_course_students_and_sync_accounts(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Bulk',
            'last_name' => 'Teacher',
            'phone' => '0944011001',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $course = Course::create([
            'name' => 'Bulk Course',
            'is_active' => true,
        ]);

        $otherCourse = Course::create([
            'name' => 'Other Course',
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
            'name' => 'Bulk Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Bulk Parent',
            'is_active' => true,
        ]);

        $activeUser = User::factory()->create(['is_active' => true]);
        $inactiveUser = User::factory()->create(['is_active' => false]);
        $blockedUser = User::factory()->create(['is_active' => true]);
        $otherUser = User::factory()->create(['is_active' => true]);

        $activeStudent = Student::create([
            'user_id' => $activeUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Course',
            'last_name' => 'Active',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $inactiveStudent = Student::create([
            'user_id' => $inactiveUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Course',
            'last_name' => 'Inactive',
            'birth_date' => '2012-01-01',
            'status' => 'inactive',
        ]);

        $blockedStudent = Student::create([
            'user_id' => $blockedUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Course',
            'last_name' => 'Blocked',
            'birth_date' => '2012-01-01',
            'status' => 'blocked',
        ]);

        $otherStudent = Student::create([
            'user_id' => $otherUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $activeStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $inactiveStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $blockedStudent->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        Volt::test('students.index')
            ->call('openBulkStatusModal')
            ->set('bulk_scope', 'course')
            ->set('bulk_course_id', $course->id)
            ->set('bulk_status_action', 'deactivate')
            ->set('bulk_sync_accounts', true)
            ->call('applyBulkStatus')
            ->assertHasNoErrors();

        $this->assertSame('inactive', $activeStudent->fresh()->status);
        $this->assertFalse($activeUser->fresh()->is_active);
        $this->assertSame('inactive', $inactiveStudent->fresh()->status);
        $this->assertFalse($inactiveUser->fresh()->is_active);
        $this->assertSame('blocked', $blockedStudent->fresh()->status);
        $this->assertTrue($blockedUser->fresh()->is_active);
        $this->assertSame('active', $otherStudent->fresh()->status);
        $this->assertTrue($otherUser->fresh()->is_active);
    }

    public function test_student_bulk_activation_skips_students_without_parents(): void
    {
        $this->signIn();

        $student = Student::create([
            'first_name' => 'No Parent',
            'last_name' => 'Student',
            'birth_date' => '2012-01-01',
            'status' => 'inactive',
        ]);

        Volt::test('students.index')
            ->call('openBulkStatusModal')
            ->set('bulk_scope', 'all')
            ->set('bulk_status_action', 'activate')
            ->set('bulk_sync_accounts', false)
            ->call('applyBulkStatus')
            ->assertHasErrors(['bulk_status']);

        $this->assertSame('inactive', $student->fresh()->status);
    }

    public function test_student_is_forced_inactive_when_saved_without_a_parent(): void
    {
        $student = Student::create([
            'first_name' => 'Rule',
            'last_name' => 'Check',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $this->assertSame('inactive', $student->fresh()->status);

        $student->status = 'active';
        $student->save();

        $this->assertSame('inactive', $student->fresh()->status);
    }

    public function test_parent_bulk_status_can_activate_by_parent_number_range_and_sync_accounts(): void
    {
        $this->signIn();

        $firstUser = User::factory()->create(['is_active' => false]);
        $secondUser = User::factory()->create(['is_active' => false]);

        $firstParent = ParentProfile::create([
            'user_id' => $firstUser->id,
            'father_name' => 'First Parent',
            'is_active' => false,
        ]);

        $secondParent = ParentProfile::create([
            'user_id' => $secondUser->id,
            'father_name' => 'Second Parent',
            'is_active' => false,
        ]);

        $from = app(ParentNumberService::class)->formatForId($firstParent->id);
        $to = app(ParentNumberService::class)->formatForId($firstParent->id);

        Volt::test('parents.index')
            ->call('openBulkStatusModal')
            ->set('bulk_scope', 'parent_number_range')
            ->set('bulk_parent_number_from', $from)
            ->set('bulk_parent_number_to', $to)
            ->set('bulk_status_action', 'activate')
            ->set('bulk_sync_accounts', true)
            ->call('applyBulkStatus')
            ->assertHasNoErrors();

        $this->assertTrue($firstParent->fresh()->is_active);
        $this->assertTrue($firstUser->fresh()->is_active);
        $this->assertFalse($secondParent->fresh()->is_active);
        $this->assertFalse($secondUser->fresh()->is_active);
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

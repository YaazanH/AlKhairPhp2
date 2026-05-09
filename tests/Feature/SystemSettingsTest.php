<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Enrollment;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentGender;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseCompletionRuleService;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_require_the_settings_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'name' => 'Settings Manager',
            'phone' => '0777000001',
            'username' => 'settings-manager',
        ]);
        $manager->assignRole('manager');

        $teacher = User::factory()->create([
            'name' => 'Settings Teacher',
            'phone' => '0777000002',
            'username' => 'settings-teacher',
        ]);
        $teacher->assignRole('teacher');

        $this->get(route('settings.organization'))->assertRedirect(route('login'));

        $this->actingAs($manager);
        $this->get(route('settings.organization'))->assertOk();
        $this->get(route('settings.tracking'))->assertOk();
        $this->get(route('settings.course-completion'))->assertOk();
        $this->get(route('settings.sidebar-navigation'))->assertOk();
        $this->get(route('settings.points'))->assertOk();
        $this->get(route('settings.finance'))->assertOk();

        auth()->logout();

        $this->actingAs($teacher);
        $this->get(route('settings.organization'))->assertForbidden();
        $this->get(route('settings.tracking'))->assertForbidden();
        $this->get(route('settings.course-completion'))->assertForbidden();
        $this->get(route('settings.sidebar-navigation'))->assertForbidden();
        $this->get(route('settings.points'))->assertForbidden();
        $this->get(route('settings.finance'))->assertForbidden();
    }

    public function test_sidebar_navigation_settings_require_the_specific_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'name' => 'Navigation Settings User',
            'phone' => '0777000003',
            'username' => 'navigation-settings-user',
        ]);

        $user->givePermissionTo('settings.manage');

        $this->actingAs($user);
        $this->get(route('settings.sidebar-navigation'))->assertForbidden();

        $user->givePermissionTo('sidebar-navigation.manage');
        $this->actingAs($user->fresh());

        $this->get(route('settings.sidebar-navigation'))->assertOk();
    }

    public function test_manager_can_manage_organization_settings(): void
    {
        $this->signIn();

        $parent = ParentProfile::query()->create([
            'father_name' => 'Mahmoud Darwish',
            'is_active' => true,
        ]);
        $this->assertSame('P'.str_pad((string) $parent->id, 6, '0', STR_PAD_LEFT), $parent->fresh()->parent_number);

        $student = Student::query()->create([
            'birth_date' => '2013-05-10',
            'first_name' => 'Ahmad',
            'last_name' => 'Darwish',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $this->assertSame((string) $student->id, $student->fresh()->student_number);

        Volt::test('settings.organization')
            ->set('school_name', 'Alkhair Center')
            ->set('school_phone', '0944555000')
            ->set('school_email', 'info@alkhair.test')
            ->set('student_number_prefix', 'S')
            ->set('student_number_length', '6')
            ->set('parent_number_prefix', 'F')
            ->set('parent_number_length', '5')
            ->set('school_address', 'Damascus')
            ->set('school_timezone', 'Asia/Damascus')
            ->set('school_currency', 'SYP')
            ->call('saveOrganizationSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'school_name',
            'value' => 'Alkhair Center',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'student_number_prefix',
            'value' => 'S',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'student_number_length',
            'value' => '6',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'parent_number_prefix',
            'value' => 'F',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'parent_number_length',
            'value' => '5',
        ]);

        $this->assertSame('S'.str_pad((string) $student->id, 6, '0', STR_PAD_LEFT), $student->fresh()->student_number);
        $this->assertSame('F'.str_pad((string) $parent->id, 5, '0', STR_PAD_LEFT), $parent->fresh()->parent_number);

        $secondStudent = Student::query()->create([
            'birth_date' => '2014-01-01',
            'first_name' => 'Bilal',
            'last_name' => 'Darwish',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $this->assertSame('S'.str_pad((string) $secondStudent->id, 6, '0', STR_PAD_LEFT), $secondStudent->fresh()->student_number);

        $secondParent = ParentProfile::query()->create([
            'father_name' => 'Bilal Darwish',
            'is_active' => true,
        ]);

        $this->assertSame('F'.str_pad((string) $secondParent->id, 5, '0', STR_PAD_LEFT), $secondParent->fresh()->parent_number);

        Volt::test('settings.organization')
            ->set('academic_year_name', '2026/2027')
            ->set('academic_year_starts_on', '2026-08-01')
            ->set('academic_year_ends_on', '2027-07-31')
            ->set('academic_year_is_current', true)
            ->call('saveAcademicYear')
            ->assertHasNoErrors();

        $academicYear = AcademicYear::query()->firstOrFail();

        Volt::test('settings.organization')
            ->call('editAcademicYear', $academicYear->id)
            ->set('academic_year_is_active', false)
            ->call('saveAcademicYear')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', [
            'id' => $academicYear->id,
            'is_active' => false,
            'is_current' => true,
        ]);

        Volt::test('settings.organization')
            ->set('grade_level_name', 'Grade 5')
            ->set('grade_level_sort_order', '5')
            ->call('saveGradeLevel')
            ->assertHasNoErrors();

        $gradeLevel = GradeLevel::query()->firstOrFail();

        Volt::test('settings.organization')
            ->call('editGradeLevel', $gradeLevel->id)
            ->set('grade_level_sort_order', '6')
            ->call('saveGradeLevel')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('grade_levels', [
            'id' => $gradeLevel->id,
            'sort_order' => 6,
        ]);

        Volt::test('settings.organization')
            ->call('deleteAcademicYear', $academicYear->id);

        Volt::test('settings.organization')
            ->call('deleteGradeLevel', $gradeLevel->id);

        Volt::test('settings.organization')
            ->set('student_gender_name', 'Not Specified')
            ->set('student_gender_code', 'not_specified')
            ->set('student_gender_sort_order', '30')
            ->set('student_gender_is_active', true)
            ->call('saveStudentGender')
            ->assertHasNoErrors();

        $studentGender = StudentGender::query()->where('code', 'not_specified')->firstOrFail();

        Volt::test('settings.organization')
            ->call('editStudentGender', $studentGender->id)
            ->set('student_gender_sort_order', '35')
            ->call('saveStudentGender')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('student_genders', [
            'id' => $studentGender->id,
            'sort_order' => 35,
        ]);

        Volt::test('settings.organization')
            ->call('deleteStudentGender', $studentGender->id);

        $this->assertDatabaseMissing('academic_years', ['id' => $academicYear->id]);
        $this->assertDatabaseMissing('grade_levels', ['id' => $gradeLevel->id]);
        $this->assertDatabaseMissing('student_genders', ['id' => $studentGender->id]);
    }

    public function test_manager_can_manage_course_completion_rules_and_apply_point_adjustments(): void
    {
        $user = $this->signIn();

        $academicYear = AcademicYear::query()->create([
            'name' => '2028/2029',
            'starts_on' => '2028-08-01',
            'ends_on' => '2029-07-31',
            'is_current' => false,
            'is_active' => true,
        ]);

        $teacher = Teacher::query()->create([
            'first_name' => 'Course',
            'last_name' => 'Teacher',
            'phone' => '0944777001',
            'status' => 'active',
        ]);

        $course = Course::query()->create([
            'name' => 'Completion Course',
            'is_active' => true,
        ]);

        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Completion Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Completion Parent',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Completion',
            'last_name' => 'Student',
            'birth_date' => '2014-05-10',
            'status' => 'active',
        ]);

        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2028-09-01',
            'status' => 'active',
        ]);

        $presentStatus = AttendanceStatus::query()->create([
            'name' => 'Present Test',
            'code' => 'present-test',
            'scope' => 'student',
            'default_points' => 0,
            'is_present' => true,
            'is_default' => true,
            'is_active' => true,
        ]);

        $attendanceDay = StudentAttendanceDay::query()->create([
            'attendance_date' => '2028-09-10',
            'status' => 'closed',
            'created_by' => $user->id,
        ]);

        $groupAttendanceDay = GroupAttendanceDay::query()->create([
            'group_id' => $group->id,
            'student_attendance_day_id' => $attendanceDay->id,
            'attendance_date' => '2028-09-10',
            'status' => 'closed',
            'created_by' => $user->id,
        ]);

        StudentAttendanceRecord::query()->create([
            'group_attendance_day_id' => $groupAttendanceDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $presentStatus->id,
        ]);

        $quizType = AssessmentType::query()->create([
            'name' => 'Quiz',
            'code' => 'quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $assessment = Assessment::query()->create([
            'group_id' => $group->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Completion Quiz',
            'scheduled_at' => '2028-09-11 10:00:00',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        AssessmentResult::query()->create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'score' => 88,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $pointType = PointType::query()->create([
            'name' => 'Completion Base',
            'code' => 'completion-base',
            'category' => 'behavior',
            'default_points' => 0,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        PointTransaction::query()->create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'source_id' => $enrollment->id,
            'points' => 40,
            'entered_by' => $user->id,
            'entered_at' => now(),
            'notes' => 'Base points before completion review',
        ]);

        Volt::test('settings.course-completion')
            ->set('required_passed_final_tests', '1')
            ->set('required_passed_quizzes', '1')
            ->set('required_present_attendance', '1')
            ->set('retain_percentage', '50')
            ->call('saveRules')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'course_completion',
            'key' => 'retain_percentage',
            'value' => '50',
        ]);

        Volt::test('settings.course-completion')
            ->set('academic_year_id', (string) $academicYear->id)
            ->set('course_id', (string) $course->id)
            ->set('group_id', (string) $group->id)
            ->set('enrollment_status', 'active')
            ->call('applyRules')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $enrollment->id,
            'source_type' => CourseCompletionRuleService::ADJUSTMENT_SOURCE_TYPE,
            'points' => -20,
        ]);

        $this->assertSame(20, $enrollment->fresh()->final_points_cached);
    }

    public function test_authorized_user_can_save_sidebar_navigation_settings(): void
    {
        $user = $this->signIn();

        Volt::test('settings.sidebar-navigation')
            ->set('group_settings.platform.title', 'Home Area')
            ->set('group_settings.platform.sort_order', '5')
            ->set('item_settings.reports.group_key', 'finance')
            ->set('item_settings.reports.sort_order', '99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'sidebar_navigation',
            'key' => 'groups',
        ]);

        $groups = AppSetting::groupValues('sidebar_navigation')->get('groups');
        $items = AppSetting::groupValues('sidebar_navigation')->get('items');

        $this->assertSame('Home Area', $groups['platform']['title']);
        $this->assertSame(5, $groups['platform']['sort_order']);
        $this->assertSame('finance', $items['reports']['group_key']);
        $this->assertSame(99, $items['reports']['sort_order']);
    }

    public function test_authorized_user_can_add_a_custom_sidebar_group_and_assign_pages_to_it(): void
    {
        $user = $this->signIn();

        $component = Volt::test('settings.sidebar-navigation')
            ->call('addGroup');

        $groupSettings = $component->get('group_settings');
        $customGroupKey = collect(array_keys($groupSettings))
            ->first(fn (string $key): bool => str_starts_with($key, 'custom_'));

        $this->assertNotNull($customGroupKey);

        $component
            ->set("group_settings.$customGroupKey.title", 'Quran Shortcuts')
            ->set("group_settings.$customGroupKey.sort_order", '55')
            ->set('item_settings.quran_partial_tests.group_key', $customGroupKey)
            ->set('item_settings.quran_partial_tests.sort_order', '1')
            ->call('save')
            ->assertHasNoErrors();

        $groups = AppSetting::groupValues('sidebar_navigation')->get('groups');
        $items = AppSetting::groupValues('sidebar_navigation')->get('items');

        $this->assertSame('Quran Shortcuts', $groups[$customGroupKey]['title']);
        $this->assertTrue((bool) $groups[$customGroupKey]['is_custom']);
        $this->assertSame($customGroupKey, $items['quran_partial_tests']['group_key']);

        $sidebarGroups = app(SidebarNavigationService::class)->sidebarFor($user->fresh());
        $customGroup = collect($sidebarGroups)->firstWhere('key', $customGroupKey);

        $this->assertNotNull($customGroup);
        $this->assertSame('Quran Shortcuts', $customGroup['title']);
        $this->assertContains('quran_partial_tests', array_column($customGroup['items'], 'key'));
    }

    public function test_student_promotion_action_requires_explicit_permission_to_appear(): void
    {
        $user = $this->signIn();

        $this->get(route('settings.organization'))
            ->assertOk()
            ->assertDontSee(__('settings.organization.actions.promote_students'));

        $user->givePermissionTo('students.promote-grade-levels');

        $this->actingAs($user->fresh());

        $this->get(route('settings.organization'))
            ->assertOk()
            ->assertSee(__('settings.organization.actions.promote_students'));
    }

    public function test_authorized_user_can_promote_students_to_the_next_active_grade_level(): void
    {
        $user = $this->signIn();
        $user->givePermissionTo('students.promote-grade-levels');
        $this->actingAs($user->fresh());

        $gradeOne = GradeLevel::query()->create([
            'is_active' => true,
            'name' => 'Grade 1',
            'sort_order' => 1,
        ]);

        $gradeTwo = GradeLevel::query()->create([
            'is_active' => true,
            'name' => 'Grade 2',
            'sort_order' => 2,
        ]);

        $gradeThree = GradeLevel::query()->create([
            'is_active' => true,
            'name' => 'Grade 3',
            'sort_order' => 3,
        ]);

        $inactiveGrade = GradeLevel::query()->create([
            'is_active' => false,
            'name' => 'Legacy Grade',
            'sort_order' => 99,
        ]);

        $parent = ParentProfile::query()->create([
            'father_name' => 'Promotion Parent',
            'is_active' => true,
        ]);

        $studentOne = Student::query()->create([
            'birth_date' => '2014-01-10',
            'first_name' => 'Student',
            'last_name' => 'One',
            'grade_level_id' => $gradeOne->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $studentTwo = Student::query()->create([
            'birth_date' => '2014-02-10',
            'first_name' => 'Student',
            'last_name' => 'Two',
            'grade_level_id' => $gradeTwo->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $studentThree = Student::query()->create([
            'birth_date' => '2014-03-10',
            'first_name' => 'Student',
            'last_name' => 'Three',
            'grade_level_id' => $gradeThree->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $studentWithoutGrade = Student::query()->create([
            'birth_date' => '2014-04-10',
            'first_name' => 'Student',
            'last_name' => 'No Grade',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        $studentOutsideActiveGrades = Student::query()->create([
            'birth_date' => '2014-05-10',
            'first_name' => 'Student',
            'last_name' => 'Legacy',
            'grade_level_id' => $inactiveGrade->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);

        Volt::test('settings.organization')
            ->call('promoteStudentsToNextGrade')
            ->assertHasNoErrors();

        $this->assertSame($gradeTwo->id, $studentOne->fresh()->grade_level_id);
        $this->assertSame($gradeThree->id, $studentTwo->fresh()->grade_level_id);
        $this->assertSame($gradeThree->id, $studentThree->fresh()->grade_level_id);
        $this->assertNull($studentWithoutGrade->fresh()->grade_level_id);
        $this->assertSame($inactiveGrade->id, $studentOutsideActiveGrades->fresh()->grade_level_id);
    }

    public function test_manager_can_manage_tracking_point_and_finance_settings(): void
    {
        $this->signIn();

        $gradeLevel = GradeLevel::query()->create([
            'is_active' => true,
            'name' => 'Grade 4',
            'sort_order' => 4,
        ]);

        Volt::test('settings.tracking')
            ->set('attendance_status_name', 'Late')
            ->set('attendance_status_code', 'late-api')
            ->set('attendance_status_scope', 'student')
            ->set('attendance_status_default_points', '-1')
            ->set('attendance_status_is_present', true)
            ->set('attendance_status_is_default', true)
            ->call('saveAttendanceStatus')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_statuses', [
            'code' => 'late-api',
            'default_points' => -1,
            'is_default' => true,
            'scope' => 'student',
        ]);

        $this->assertSame(1, AttendanceStatus::query()->where('is_default', true)->count());

        Volt::test('settings.tracking')
            ->set('assessment_type_name', 'Oral Exam')
            ->set('assessment_type_code', 'oral-exam')
            ->set('assessment_type_is_scored', true)
            ->call('saveAssessmentType')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assessment_types', [
            'code' => 'oral-exam',
            'name' => 'Oral Exam',
        ]);

        Volt::test('settings.tracking')
            ->set('partial_test_fail_threshold', '4')
            ->call('savePartialTestRules')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'tracking',
            'key' => 'quran_partial_test_fail_threshold',
            'value' => '4',
        ]);

        Volt::test('settings.tracking')
            ->set('final_test_failed_from', '0')
            ->set('final_test_failed_to', '74.99')
            ->set('final_test_passed_from', '75')
            ->set('final_test_passed_to', '100')
            ->call('saveFinalTestRules')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'tracking',
            'key' => 'quran_final_test_passed_from',
            'value' => '75',
        ]);

        Volt::test('settings.points')
            ->set('point_type_name', 'Behavior Bonus')
            ->set('point_type_code', 'behavior-bonus')
            ->set('point_type_category', 'behavior')
            ->set('point_type_default_points', '3')
            ->set('point_type_allow_manual_entry', true)
            ->set('point_type_allow_negative', false)
            ->call('savePointType')
            ->assertHasNoErrors();

        $pointType = \App\Models\PointType::query()->where('code', 'behavior-bonus')->firstOrFail();

        Volt::test('settings.points')
            ->set('point_policy_point_type_id', $pointType->id)
            ->set('point_policy_name', 'Behavior Grade 4')
            ->set('point_policy_source_type', 'behavior')
            ->set('point_policy_trigger_key', 'excellent')
            ->set('point_policy_grade_level_id', $gradeLevel->id)
            ->set('point_policy_from_value', '90')
            ->set('point_policy_to_value', '100')
            ->set('point_policy_points', '7')
            ->set('point_policy_priority', '10')
            ->call('savePointPolicy')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_policies', [
            'grade_level_id' => $gradeLevel->id,
            'name' => 'Behavior Grade 4',
            'point_type_id' => $pointType->id,
            'points' => 7,
            'source_type' => 'behavior',
            'trigger_key' => 'excellent',
        ]);

        Volt::test('settings.finance')
            ->set('invoice_prefix', 'ALK')
            ->call('saveFinanceSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'finance',
            'key' => 'invoice_prefix',
            'value' => 'ALK',
        ]);

        Volt::test('settings.finance')
            ->set('payment_method_name', 'Bank Transfer')
            ->set('payment_method_code', 'bank-transfer')
            ->call('savePaymentMethod')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'bank-transfer',
            'name' => 'Bank Transfer',
        ]);

        Volt::test('settings.organization')
            ->set('expense_category_name', 'Transport')
            ->set('expense_category_code', 'transport')
            ->call('saveExpenseCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expense_categories', [
            'code' => 'transport',
            'name' => 'Transport',
        ]);
    }

    private function signIn(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'name' => 'Manager User',
            'phone' => '0999999910',
            'username' => 'settings-manager-user',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);

        return $user;
    }
}

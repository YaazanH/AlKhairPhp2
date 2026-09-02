<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentGender;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\CourseCompletionRuleService;
use App\Services\SidebarNavigationService;
use App\Support\OperationalFeatureSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_years_are_sorted_by_start_date_and_cannot_finish_before_their_courses(): void
    {
        $this->signIn();

        $olderYear = AcademicYear::query()->create([
            'name' => 'Academic Year Older',
            'starts_on' => '2024-08-01',
            'ends_on' => '2025-07-31',
            'is_current' => false,
            'is_active' => true,
        ]);
        $newerYear = AcademicYear::query()->create([
            'name' => 'Academic Year Newer',
            'starts_on' => '2028-08-01',
            'ends_on' => '2029-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'academic_year_id' => $newerYear->id,
            'name' => 'Unfinished Academic Year Course',
            'is_active' => true,
        ]);

        $component = Volt::test('settings.organization')
            ->assertSeeInOrder([$newerYear->name, $olderYear->name])
            ->assertSee(__('crud.common.actions.edit'))
            ->call('editAcademicYear', $newerYear->id)
            ->assertSet('academic_year_courses_count', 1)
            ->assertSet('academic_year_unfinished_courses_count', 1)
            ->call('finishAcademicYear')
            ->assertHasErrors('academicYearFinish');

        app(\App\Services\CourseLifecycleService::class)->finish($course);

        $component
            ->call('editAcademicYear', $newerYear->id)
            ->assertSet('academic_year_unfinished_courses_count', 0)
            ->call('finishAcademicYear')
            ->assertHasNoErrors()
            ->assertSet('showAcademicYearModal', true)
            ->assertSet('academic_year_creation_required', true)
            ->assertSet('academic_year_name', 'العام الدراسي ٢٠٢٩ - ٢٠٣٠م');

        $this->assertDatabaseHas('academic_years', [
            'id' => $newerYear->id,
            'is_active' => false,
            'is_current' => false,
        ]);
        $this->assertSame(1, AcademicYear::query()->where('is_current', true)->count());
        $this->assertDatabaseHas('academic_years', [
            'starts_on' => '2029-08-01 00:00:00',
            'is_active' => true,
            'is_current' => true,
        ]);

        $nextAcademicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();

        $component
            ->assertSet('academic_year_editing_id', $nextAcademicYear->id)
            ->call('closeAcademicYearModal')
            ->assertSet('showAcademicYearModal', true)
            ->assertSet('academic_year_creation_required', true)
            ->set('academic_year_name', 'Edited New Academic Year')
            ->call('saveAcademicYear')
            ->assertHasNoErrors()
            ->assertSet('showAcademicYearModal', false)
            ->assertSet('academic_year_creation_required', false);

        $this->assertDatabaseHas('academic_years', [
            'id' => $nextAcademicYear->id,
            'name' => 'Edited New Academic Year',
        ]);

        Volt::test('settings.organization')
            ->call('editAcademicYear', $newerYear->id)
            ->assertHasErrors('academicYear')
            ->assertSet('academic_year_editing_id', null)
            ->call('reactivateAcademicYear', $newerYear->id)
            ->assertHasErrors('academicYearReactivation');
    }

    public function test_empty_active_academic_year_can_be_deleted_then_an_inactive_year_can_be_reactivated(): void
    {
        $this->signIn();

        $inactiveYear = AcademicYear::query()->create([
            'name' => 'Available for Reactivation',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_current' => false,
            'is_active' => false,
        ]);
        $emptyActiveYear = AcademicYear::query()->create([
            'name' => 'Empty Active Year',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $component = Volt::test('settings.organization')
            ->assertDontSee('wire:click="deleteAcademicYear('.$emptyActiveYear->id.')"', false)
            ->assertDontSee('wire:click="reactivateAcademicYear('.$inactiveYear->id.')"', false)
            ->call('editAcademicYear', $emptyActiveYear->id)
            ->assertSee('data-settings-academic-year-delete-action', false)
            ->call('deleteAcademicYear', $emptyActiveYear->id)
            ->assertHasNoErrors()
            ->assertSee('wire:click="reactivateAcademicYear('.$inactiveYear->id.')"', false)
            ->call('reactivateAcademicYear', $inactiveYear->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('academic_years', ['id' => $emptyActiveYear->id]);
        $this->assertDatabaseHas('academic_years', [
            'id' => $inactiveYear->id,
            'is_active' => true,
            'is_current' => true,
        ]);
    }

    public function test_finished_academic_year_can_be_reactivated_only_when_no_year_is_active_then_its_course_records_can_be_restored(): void
    {
        $this->signIn();

        $academicYear = AcademicYear::query()->create([
            'name' => 'Restorable Academic Year',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Restorable Course',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Restorable',
            'last_name' => 'Teacher',
            'phone' => '0944007011',
            'status' => 'active',
            'is_helping' => true,
        ]);
        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'name' => 'Restorable Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'first_name' => 'Restorable',
            'last_name' => 'Student',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-08-10',
            'status' => 'active',
        ]);

        app(\App\Services\CourseLifecycleService::class)->finish($course);
        $academicYear->update(['is_active' => false, 'is_current' => false]);

        Volt::test('settings.organization')
            ->assertSee('data-settings-academic-year-reactivate-action', false)
            ->assertDontSee('wire:click="editAcademicYear('.$academicYear->id.')"', false)
            ->call('reactivateAcademicYear', $academicYear->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', [
            'id' => $academicYear->id,
            'is_active' => true,
            'is_current' => true,
        ]);

        Volt::test('courses.index')
            ->call('reactivate', $course->id)
            ->assertHasNoErrors();

        $this->assertTrue($course->fresh()->is_active);
        $this->assertTrue($group->fresh()->is_active);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNull($group->fresh()->course_finished_at);
        $this->assertNull($enrollment->fresh()->course_finished_at);
    }

    public function test_linked_attendance_status_can_be_deleted_without_deleting_history(): void
    {
        $this->signIn();
        $status = AttendanceStatus::query()->create([
            'name' => 'Temporary late',
            'code' => 'temporary-late',
            'scope' => 'teacher',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Status',
            'last_name' => 'Teacher',
            'phone' => '0999555000',
            'status' => 'active',
        ]);
        $day = TeacherAttendanceDay::query()->create([
            'attendance_date' => now()->toDateString(),
            'created_by' => auth()->id(),
        ]);
        $record = TeacherAttendanceRecord::query()->create([
            'teacher_attendance_day_id' => $day->id,
            'teacher_id' => $teacher->id,
            'attendance_status_id' => $status->id,
        ]);

        Volt::test('settings.tracking')
            ->call('deleteAttendanceStatus', $status->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('attendance_statuses', ['id' => $status->id]);
        $this->assertNull($record->fresh()->attendance_status_id);
    }

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

    public function test_tracking_settings_hide_unused_awqaf_subject_management_and_include_points(): void
    {
        $this->signIn();

        Volt::test('settings.tracking')
            ->assertDontSee(__('settings.tracking.sections.awqaf_subject.table'))
            ->assertSee(__('settings.points.title'));
    }

    public function test_settings_navigation_and_saber_rules_use_the_single_table_layout(): void
    {
        $this->signIn();

        $this->get(route('settings.organization'))
            ->assertOk()
            ->assertSee('settings-tab__title', false);

        Volt::test('settings.tracking')
            ->assertSee('data-saber-rule-card="partial"', false)
            ->assertSee('data-saber-rule-card="final-passed"', false)
            ->assertDontSee('data-saber-rule-card="final-failed"', false)
            ->assertSee(__('settings.tracking.fields.passing_grade'))
            ->assertDontSee('<fieldset', false)
            ->assertDontSee('<th>'.__('settings.tracking.table.rule').'</th>', false);
    }

    public function test_operational_statuses_can_be_toggled_without_opening_the_general_settings_editor(): void
    {
        $this->signIn();

        $component = Volt::test('settings.organization')
            ->assertSet('barcode_scanner_enabled', true)
            ->assertSet('memorization_saber_entries_enabled', true)
            ->assertSee('wire:click="toggleBarcodeScanner"', false)
            ->assertSee('wire:click="toggleMemorizationSaberEntries"', false)
            ->call('toggleBarcodeScanner')
            ->assertSet('barcode_scanner_enabled', false)
            ->call('toggleMemorizationSaberEntries')
            ->assertSet('memorization_saber_entries_enabled', false);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'dashboard',
            'key' => 'barcode_scanner_enabled',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'memorization_saber_entries_enabled',
            'value' => '0',
        ]);

        $component
            ->call('toggleBarcodeScanner')
            ->assertSet('barcode_scanner_enabled', true)
            ->call('toggleMemorizationSaberEntries')
            ->assertSet('memorization_saber_entries_enabled', true);
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
            ->assertSee('data-general-prefix-value', false)
            ->assertSee('data-organization-edit-action', false)
            ->assertSee('title="'.__('settings.organization.actions.save_settings').'"', false)
            ->assertSee('aria-label="'.__('settings.organization.actions.save_settings').'"', false)
            ->assertDontSee('wire:click="openOrganizationModal" class="pill-link"', false)
            ->call('openOrganizationModal')
            ->assertSee('data-organization-settings-save-icon', false)
            ->assertSee('form="organization-settings-form"', false)
            ->assertDontSee('wire:click="closeOrganizationModal" class="pill-link"', false)
            ->assertSee('data-organization-settings-primary-box', false)
            ->assertSee('data-organization-settings-numbering-group', false)
            ->assertSee('md:grid-cols-4', false)
            ->assertSee('data-organization-settings-locale-group', false)
            ->assertSee('md:grid-cols-[minmax(0,1.35fr)_minmax(7rem,0.6fr)_minmax(9rem,0.8fr)]', false)
            ->assertSee('wire:model="school_address" type="text"', false)
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
            ->set('barcode_scanner_enabled', false)
            ->set('memorization_saber_entries_enabled', false)
            ->set('activity_entries_enabled', false)
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

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'memorization_saber_entries_enabled',
            'value' => '0',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'general',
            'key' => 'activity_entries_enabled',
            'value' => '0',
        ]);

        $this->assertFalse(OperationalFeatureSettings::memorizationAndSabersEnabled());
        $this->assertFalse(OperationalFeatureSettings::activitiesEnabled());

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
            ->set('academic_year_name', 'Dates are required')
            ->call('saveAcademicYear')
            ->assertHasErrors([
                'academic_year_starts_on' => 'required',
                'academic_year_ends_on' => 'required',
            ]);

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
            ->call('finishAcademicYear')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('academic_years', [
            'id' => $academicYear->id,
            'is_active' => false,
            'is_current' => false,
        ]);
        $this->assertSame(1, AcademicYear::query()->where('is_current', true)->count());

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

    public function test_main_page_logo_upload_is_saved_immediately_and_can_be_removed(): void
    {
        Storage::fake('public');
        $this->signIn();

        $component = Volt::test('settings.organization')
            ->set('pdf_logo_upload', UploadedFile::fake()->image('main-page-logo.png', 300, 120))
            ->assertHasNoErrors();

        $path = (string) AppSetting::groupValues('general')->get('pdf_logo_path');
        $this->assertNotSame('', $path);
        Storage::disk('public')->assertExists($path);

        $component->call('removePdfLogo')->assertHasNoErrors();

        $this->assertNull(AppSetting::groupValues('general')->get('pdf_logo_path'));
        Storage::disk('public')->assertMissing($path);
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
            'course_id' => $group->course_id,
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

    public function test_course_completion_final_rule_splits_deselected_grades_into_a_new_persistent_row(): void
    {
        $this->signIn();

        $firstGrade = GradeLevel::query()->create(['name' => 'Grade A', 'sort_order' => 10, 'is_active' => true]);
        $secondGrade = GradeLevel::query()->create(['name' => 'Grade B', 'sort_order' => 20, 'is_active' => true]);
        $thirdGrade = GradeLevel::query()->create(['name' => 'Grade C', 'sort_order' => 30, 'is_active' => true]);

        Volt::test('settings.course-completion')
            ->assertSee('data-course-completion-final-rule-row', false)
            ->assertSee('data-search-selection-required="true"', false)
            ->assertSee('data-show-chevron="false"', false)
            ->assertSee('data-course-completion-grade-button', false)
            ->assertDontSee(__('settings.course_completion.fields.required_present_attendance'))
            ->assertSee(__('settings.course_completion.fields.retain_percentage'))
            ->assertSee(__('settings.course_completion.fields.minimum_points'))
            ->assertSee(__('settings.course_completion.labels.point_unit'))
            ->call('openGradeRule', 'final', 0)
            ->assertSee('data-course-completion-grade-save', false)
            ->assertSet('final_test_grade_ids', [$firstGrade->id, $secondGrade->id, $thirdGrade->id])
            ->assertSet('gradeRuleSelectedGradeIds', [$firstGrade->id, $secondGrade->id, $thirdGrade->id])
            ->set('gradeRuleSelectedGradeIds', [$firstGrade->id, $secondGrade->id])
            ->assertSee('data-course-completion-grade-action="add"', false)
            ->call('saveGradeRuleModal')
            ->assertSet('additional_final_rules.0.grade_ids', [$thirdGrade->id])
            ->call('openGradeRule', 'final', 1)
            ->assertSee('data-course-completion-grade-action="save"', false)
            ->assertSee('data-course-completion-rule-delete', false)
            ->call('saveGradeRuleModal')
            ->set('additional_final_rules.0.required_passed_final_tests', '4')
            ->set('additional_final_rules.0.required_memorized_pages', '120')
            ->set('additional_final_rules.0.final_rule_operator', 'or')
            ->call('saveRules')
            ->assertHasNoErrors();

        $rows = app(CourseCompletionRuleService::class)->settings()['final_rule_rows'];

        $this->assertCount(2, $rows);
        $this->assertSame([$firstGrade->id, $secondGrade->id], $rows[0]['grade_ids']);
        $this->assertSame([$thirdGrade->id], $rows[1]['grade_ids']);
        $this->assertSame(4, $rows[1]['required_passed_final_tests']);
        $this->assertSame(120, $rows[1]['required_memorized_pages']);
        $this->assertSame('or', $rows[1]['final_rule_operator']);
    }

    public function test_reselecting_a_grade_removes_its_now_empty_split_completion_rule(): void
    {
        $this->signIn();

        $firstGrade = GradeLevel::query()->create(['name' => 'Grade A', 'sort_order' => 10, 'is_active' => true]);
        $secondGrade = GradeLevel::query()->create(['name' => 'Grade B', 'sort_order' => 20, 'is_active' => true]);

        Volt::test('settings.course-completion')
            ->call('openGradeRule', 'final', 0)
            ->set('gradeRuleSelectedGradeIds', [$firstGrade->id])
            ->call('saveGradeRuleModal')
            ->assertSet('additional_final_rules.0.grade_ids', [$secondGrade->id])
            ->call('openGradeRule', 'final', 0)
            ->set('gradeRuleSelectedGradeIds', [$firstGrade->id, $secondGrade->id])
            ->call('saveGradeRuleModal')
            ->assertSet('final_test_grade_ids', [$firstGrade->id, $secondGrade->id])
            ->assertSet('additional_final_rules', []);
    }

    public function test_deleting_a_secondary_completion_rule_returns_its_grades_to_the_main_rule(): void
    {
        $this->signIn();

        $firstGrade = GradeLevel::query()->create(['name' => 'Grade A', 'sort_order' => 10, 'is_active' => true]);
        $secondGrade = GradeLevel::query()->create(['name' => 'Grade B', 'sort_order' => 20, 'is_active' => true]);

        Volt::test('settings.course-completion')
            ->call('openGradeRule', 'final', 0)
            ->set('gradeRuleSelectedGradeIds', [$firstGrade->id])
            ->call('saveGradeRuleModal')
            ->assertSet('additional_final_rules.0.grade_ids', [$secondGrade->id])
            ->call('openGradeRule', 'final', 1)
            ->assertSee('data-course-completion-rule-delete', false)
            ->call('deleteFinalRule', 1)
            ->assertSet('showGradeRuleModal', false)
            ->assertSet('final_test_grade_ids', [$firstGrade->id, $secondGrade->id])
            ->assertSet('additional_final_rules', []);
    }

    public function test_deselecting_a_grade_from_a_secondary_completion_rule_returns_it_to_the_main_rule(): void
    {
        $this->signIn();

        $firstGrade = GradeLevel::query()->create(['name' => 'Grade A', 'sort_order' => 10, 'is_active' => true]);
        $secondGrade = GradeLevel::query()->create(['name' => 'Grade B', 'sort_order' => 20, 'is_active' => true]);
        $thirdGrade = GradeLevel::query()->create(['name' => 'Grade C', 'sort_order' => 30, 'is_active' => true]);

        Volt::test('settings.course-completion')
            ->call('openGradeRule', 'final', 0)
            ->set('gradeRuleSelectedGradeIds', [$firstGrade->id])
            ->call('saveGradeRuleModal')
            ->assertSet('additional_final_rules.0.grade_ids', [$secondGrade->id, $thirdGrade->id])
            ->call('openGradeRule', 'final', 1)
            ->set('gradeRuleSelectedGradeIds', [$secondGrade->id])
            ->assertSee('data-course-completion-grade-action="save"', false)
            ->assertDontSee('data-course-completion-grade-action="add"', false)
            ->call('saveGradeRuleModal')
            ->assertSet('final_test_grade_ids', [$firstGrade->id, $thirdGrade->id])
            ->assertSet('additional_final_rules.0.grade_ids', [$secondGrade->id])
            ->assertCount('additional_final_rules', 1);
    }

    public function test_assessment_rules_use_a_card_selector_and_per_assessment_grade_editor(): void
    {
        $this->signIn();

        $firstGrade = GradeLevel::query()->create(['name' => 'Grade A', 'sort_order' => 10, 'is_active' => true]);
        $secondGrade = GradeLevel::query()->create(['name' => 'Grade B', 'sort_order' => 20, 'is_active' => true]);
        $firstType = AssessmentType::query()->create(['name' => 'Oral', 'code' => 'oral', 'is_scored' => true, 'is_active' => true]);
        $secondType = AssessmentType::query()->create(['name' => 'Written', 'code' => 'written', 'is_scored' => true, 'is_active' => true]);

        Volt::test('settings.course-completion')
            ->assertSee(__('settings.course_completion.labels.no_assessment_types'))
            ->assertSee('class="admin-empty-state admin-empty-state--compact"', false)
            ->call('openAssessmentTypeModal')
            ->assertSee('wire:click="closeAssessmentTypeModal"', false)
            ->assertSee($firstType->name)
            ->assertSee($secondType->name)
            ->assertSee('data-course-completion-assessment-choice', false)
            ->assertDontSee('type="checkbox" value="'.$firstType->id.'" wire:model="enabled_assessment_type_ids"', false)
            ->call('toggleAssessmentTypeSelection', $firstType->id)
            ->assertSet('assessment_type_selections', [$firstType->id])
            ->assertSee('data-course-completion-assessment-add', false)
            ->assertDontSee('wire:click="closeAssessmentTypeModal"', false)
            ->call('addSelectedAssessmentType')
            ->assertSet('enabled_assessment_type_ids', [$firstType->id])
            ->assertSet('assessment_rule_grade_ids.'.$firstType->id, [$firstGrade->id, $secondGrade->id])
            ->call('openAssessmentTypeModal')
            ->assertDontSee('wire:click="toggleAssessmentTypeSelection('.$firstType->id.')"', false)
            ->assertSee('wire:click="toggleAssessmentTypeSelection('.$secondType->id.')"', false)
            ->call('closeAssessmentTypeModal')
            ->call('openGradeRule', 'assessment', $firstType->id)
            ->assertSee('data-course-completion-assessment-delete', false)
            ->assertSet('gradeRuleSelectedGradeIds', [$firstGrade->id, $secondGrade->id])
            ->set('gradeRuleSelectedGradeIds', [$secondGrade->id])
            ->call('saveGradeRuleModal')
            ->assertSet('assessment_rule_grade_ids.'.$firstType->id, [$secondGrade->id])
            ->set('assessment_type_requirements.'.$firstType->id, '2')
            ->call('saveRules')
            ->assertHasNoErrors()
            ->call('openGradeRule', 'assessment', $firstType->id)
            ->call('removeAssessmentRule')
            ->assertSet('enabled_assessment_type_ids', []);

        $this->assertSame(
            [$secondGrade->id],
            app(CourseCompletionRuleService::class)->settings()['assessment_rule_grade_ids'][$firstType->id],
        );
    }

    public function test_authorized_user_can_save_sidebar_navigation_settings(): void
    {
        $user = $this->signIn();

        Volt::test('settings.sidebar-navigation')
            ->assertSee('data-sidebar-group-edit-action', false)
            ->assertDontSee('✎', false)
            ->assertSee('nav-sort-group--dragging', false)
            ->assertSee('nav-sort-item--drop-target', false)
            ->assertSee('nav-sort-item--settled', false)
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

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.nav-sort-group--dragging', $css);
        $this->assertStringContainsString('.nav-sort-item--drop-target', $css);
        $this->assertStringContainsString('.nav-sort-group--drop-target::before', $css);
        $this->assertStringContainsString('margin-top: 1.5rem !important;', $css);
        $this->assertStringContainsString('@keyframes sort-drop-gap-highlight', $css);
        $this->assertStringContainsString('@keyframes sort-drop-settle', $css);
    }

    public function test_authorized_user_can_add_a_custom_sidebar_group_and_assign_pages_to_it(): void
    {
        $user = $this->signIn();

        $component = Volt::test('settings.sidebar-navigation')
            ->call('addGroup')
            ->assertDispatched('sidebar-navigation-group-added')
            ->assertSee('data-sidebar-navigation-group=', false)
            ->assertSee('data-sidebar-navigation-group-title', false)
            ->assertSee('data-sidebar-group-delete-action', false);

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

    public function test_sidebar_includes_student_progress_item_for_users_with_student_access(): void
    {
        $user = $this->signIn();

        $sidebarGroups = app(SidebarNavigationService::class)->sidebarFor($user->fresh());
        $peopleGroup = collect($sidebarGroups)->firstWhere('key', 'people');

        $this->assertNotNull($peopleGroup);
        $this->assertContains('student_progress', array_column($peopleGroup['items'], 'key'));
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
            ->set('final_test_passed_from', '75')
            ->call('saveFinalTestRules')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'tracking',
            'key' => 'quran_final_test_passed_from',
            'value' => '75',
        ]);

        Volt::test('settings.points')
            ->assertSet('automatic_multiplier', '2')
            ->assertSee('points-multiplier-select', false)
            ->assertSee('data-clearable="false"', false)
            ->assertSee('data-search-selection-required="true"', false)
            ->assertSee('data-show-chevron="false"', false)
            ->assertDontSee('<option value="1">x1</option>', false)
            ->assertSee('md:grid-cols-[3rem_1fr_1fr_auto]', false)
            ->assertSee('class="points-multiplier-field"', false)
            ->assertSee('class="points-multiplier-select h-12 w-12', false)
            ->assertSee('data-points-multiplier-save-action', false)
            ->assertSee('class="admin-icon-button admin-icon-button--accent points-multiplier-save-button"', false)
            ->assertSee('title="'.__('crud.common.actions.save').'"', false)
            ->assertSee('aria-label="'.__('crud.common.actions.save').'"', false)
            ->set('partial_test_fail_threshold', '4')
            ->set('final_test_passed_from', '75')
            ->call('saveSaberRules')
            ->assertHasNoErrors();

        $finalRules = app(\App\Services\QuranFinalTestRuleService::class);
        $this->assertSame('failed', $finalRules->statusForScore(74.99));
        $this->assertSame('passed', $finalRules->statusForScore(75));
        $this->assertSame('passed', $finalRules->statusForScore(100));

        Volt::test('settings.points')
            ->set('point_type_name', 'Behavior Bonus')
            ->set('point_type_code', 'behavior-bonus')
            ->set('point_type_category', 'behavior')
            ->set('point_type_default_points', '3')
            ->set('point_type_allow_manual_entry', true)
            ->set('point_type_allow_negative', false)
            ->call('savePointType')
            ->assertHasNoErrors();

        $pointType = PointType::query()->where('code', 'behavior-bonus')->firstOrFail();

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
            ->assertSee('data-finance-settings-summary', false)
            ->assertSee('data-finance-settings-edit-action', false)
            ->assertSee('title="'.__('finance.actions.edit').'"', false)
            ->assertSee('aria-label="'.__('finance.actions.edit').'"', false)
            ->assertDontSee('wire:click="openFinanceSettingsModal" class="pill-link"', false)
            ->assertSee('data-finance-settings-primary-row', false)
            ->assertSee('data-finance-settings-prefix-row', false)
            ->assertSee('grid min-w-[72rem] grid-cols-8', false)
            ->assertSee('data-finance-prefix-value', false)
            ->assertSee('data-withdrawal-requests-status', false)
            ->call('toggleWithdrawalRequests')
            ->assertSet('withdrawal_requests_enabled', false)
            ->call('openFinanceSettingsModal')
            ->assertSet('showFinanceSettingsModal', true)
            ->assertSee('data-finance-settings-save-icon', false)
            ->call('closeFinanceSettingsModal')
            ->assertSet('showFinanceSettingsModal', false);

        $this->assertDatabaseHas('app_settings', [
            'group' => 'finance',
            'key' => 'withdrawal_requests_enabled',
            'value' => '0',
        ]);

        Volt::test('settings.finance')
            ->set('invoice_prefix', 'ALK')
            ->set('transaction_prefix', 'MOV')
            ->set('pull_request_prefix', 'PUL')
            ->set('expense_request_prefix', 'EXP')
            ->set('revenue_request_prefix', 'REV')
            ->set('exchange_prefix', 'EXC')
            ->call('saveFinanceSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'group' => 'finance',
            'key' => 'invoice_prefix',
            'value' => 'ALK',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'group' => 'finance',
            'key' => 'transaction_prefix',
            'value' => 'MOV',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'group' => 'finance',
            'key' => 'exchange_prefix',
            'value' => 'EXC',
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

    public function test_shared_table_pagination_keeps_controls_on_one_row_and_summary_below(): void
    {
        $paginationView = file_get_contents(resource_path('views/vendor/livewire/tailwind.blade.php'));
        $paginationCss = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('class="app-pagination__mobile"', $paginationView);
        $this->assertStringContainsString('class="app-pagination__desktop"', $paginationView);
        $this->assertStringNotContainsString('app-pagination__mobile sm:hidden', $paginationView);
        $this->assertMatchesRegularExpression('/\.app-pagination__summary\s*\{[^}]*grid-column:\s*1;[^}]*grid-row:\s*2;[^}]*font-size:\s*0\.72rem;/s', $paginationCss);
        $this->assertMatchesRegularExpression('/\.app-pagination__mobile\s*\{[^}]*grid-row:\s*1;/s', $paginationCss);
        $this->assertMatchesRegularExpression('/\.app-pagination__nav\s*\{[^}]*grid-row:\s*1;/s', $paginationCss);
        $this->assertStringNotContainsString(".app-pagination__summary {\n        display: none;", $paginationCss);
        $this->assertStringContainsString('flex-wrap: nowrap;', $paginationCss);
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

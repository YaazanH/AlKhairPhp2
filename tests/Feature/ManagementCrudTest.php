<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FatherJob;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupSchedule;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\PrintTemplate;
use App\Models\QuranJuz;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentFile;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\CourseLifecycleService;
use App\Services\ParentNumberService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagementCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_final_saber_pdf_keeps_a_four_millimetre_header_gap_and_aligned_metadata(): void
    {
        $html = view('reports.course-final-tests', [
            'course' => new Course(['name' => 'PDF Course']),
            'rows' => collect([
                ['name' => 'PDF Student', 'juz' => 3, 'mark' => 95],
            ]),
            'logo' => null,
        ])->render();

        $this->assertStringContainsString('@page{margin:35mm 14mm 18mm', $html);
        $this->assertStringContainsString('.header-title{font-family:dubai,sans-serif;font-size:20px;font-weight:bold}', $html);
        $this->assertStringContainsString('.report-table thead tr:not(.report-page-gap) th{font-family:dubai,sans-serif;font-weight:bold}', $html);
        $this->assertStringContainsString('.meta-label{font-family:dubaimedium,sans-serif;font-weight:normal', $html);
        $this->assertStringContainsString('.header-meta[dir=rtl] .meta-label{text-align:left;padding-left:.8mm}', $html);
        $this->assertStringContainsString('.header-meta[dir=rtl] .meta-value{text-align:right;padding-right:.8mm}', $html);
        $this->assertStringContainsString('.report-page-gap th{background:#fff;border:0;font-size:0;height:4mm', $html);
        $this->assertStringContainsString('<tr class="report-page-gap"><th colspan="5">&nbsp;</th></tr>', $html);
        $this->assertStringContainsString('class="header-meta-table"', $html);
        $this->assertStringContainsString('class="meta-label" style="text-align:', $html);
        $this->assertStringContainsString(__('course_end.date_label'), $html);
        $this->assertStringContainsString(__('course_end.final_tests_total'), $html);
    }

    public function test_student_names_are_trimmed_when_saved(): void
    {
        $student = Student::create([
            'first_name' => '   Ahmad',
            'last_name' => '  Khaled  ',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        $this->assertSame('Ahmad', $student->fresh()->first_name);
        $this->assertSame('Khaled', $student->fresh()->last_name);
    }

    public function test_students_with_the_same_first_and_last_names_use_the_fathers_full_name(): void
    {
        $firstParent = ParentProfile::create(['father_name' => 'Mahmoud Khaled Ali']);
        $secondParent = ParentProfile::create(['father_name' => 'Samer Nabil Ali']);
        $first = Student::create(['parent_id' => $firstParent->id, 'first_name' => 'Omar', 'last_name' => 'Ali', 'birth_date' => '2012-01-01', 'status' => 'active']);
        $second = Student::create(['parent_id' => $secondParent->id, 'first_name' => 'Omar', 'last_name' => 'Ali', 'birth_date' => '2013-01-01', 'status' => 'active']);

        $this->assertSame('Omar Mahmoud Khaled Ali', $first->fresh('parentProfile')->full_name);
        $this->assertSame('Omar Samer Nabil Ali', $second->fresh('parentProfile')->full_name);
    }

    public function test_renaming_school_and_parent_work_settings_updates_linked_profiles_and_rejects_duplicates(): void
    {
        $this->signIn();
        $school = School::create(['name' => 'Old School', 'is_active' => false]);
        School::create(['name' => 'Existing School', 'is_active' => true]);
        $job = FatherJob::create(['name' => 'Old Job', 'is_active' => false]);
        FatherJob::create(['name' => 'Existing Job', 'is_active' => true]);
        $parent = ParentProfile::create(['father_name' => 'Settings Parent', 'father_work' => ' old JOB ']);
        $student = Student::create(['parent_id' => $parent->id, 'first_name' => 'Settings', 'last_name' => 'Student', 'birth_date' => '2013-01-01', 'school_name' => ' old SCHOOL ', 'status' => 'active']);

        Volt::test('settings.organization')
            ->assertSee('data-school-usage-count="1"', false)
            ->assertSee('data-father-job-usage-count="1"', false)
            ->assertSee('data-school-reference-edit-icon', false)
            ->assertSee('data-father-job-edit-icon', false)
            ->call('editSchoolReference', $school->id)
            ->assertSee('data-school-reference-delete', false)
            ->assertDontSee('wire:model="school_reference_is_active"', false)
            ->call('cancelSchoolReference')
            ->call('editFatherJob', $job->id)
            ->assertSee('data-father-job-delete', false)
            ->assertDontSee('wire:model="father_job_is_active"', false);

        Volt::test('settings.organization')->call('editSchoolReference', $school->id)->set('school_reference_name', 'New School')->call('saveSchoolReference')->assertHasNoErrors();
        Volt::test('settings.organization')->call('editFatherJob', $job->id)->set('father_job_name', 'New Job')->call('saveFatherJob')->assertHasNoErrors();

        $this->assertSame('New School', $student->fresh()->school_name);
        $this->assertSame('New Job', $parent->fresh()->father_work);
        $this->assertTrue($school->fresh()->is_active);
        $this->assertTrue($job->fresh()->is_active);

        Volt::test('settings.organization')->call('editSchoolReference', $school->id)->set('school_reference_name', ' Existing School ')->call('saveSchoolReference')->assertHasErrors('school_reference_name');
        Volt::test('settings.organization')->call('editFatherJob', $job->id)->set('father_job_name', ' Existing Job ')->call('saveFatherJob')->assertHasErrors('father_job_name');
    }

    public function test_course_parent_and_teacher_components_support_crud_operations(): void
    {
        $this->signIn();

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        Volt::test('courses.index')
            ->assertSet('academicYearFilter', (string) $academicYear->id)
            ->assertSee('course-academic-year-filter', false)
            ->set('academic_year_id', $academicYear->id)
            ->set('name', 'Quran Foundations')
            ->set('description', 'Foundational memorization track')
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
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('courses.index')
            ->call('edit', $course->id)
            ->call('deactivate', $course->id)
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'description' => 'Updated course description',
            'is_active' => false,
        ]);

        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSee(__('crud.courses.archive.title', ['course' => $course->name]))
            ->assertSee(__('crud.courses.actions.reactivate'))
            ->call('reactivate', $course->id)
            ->assertHasNoErrors()
            ->assertSet('showArchiveModal', false);

        $this->assertTrue($course->fresh()->is_active);

        Volt::test('parents.index')
            ->assertDontSee('wire:click="openCreateModal"', false)
            ->assertSee('data-parent-table-controls', false)
            ->assertSee('admin-toolbar__controls--compact', false)
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
            ->call('openCreateModal')
            ->assertSee('data-teacher-identity-grid', false)
            ->assertSee('data-teacher-photo-box', false)
            ->assertSee('data-teacher-role-options', false)
            ->assertDontSee('data-teacher-active-toggle', false)
            ->assertDontSee('id="teacher-status"', false)
            ->assertDontSee('id="teacher-notes"', false)
            ->set('first_name', 'Yousef')
            ->set('last_name', 'Teacher')
            ->set('phone', '0944000002')
            ->set('access_roles', [$teacherAccessRole->name])
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
            ->assertSee('data-teacher-active-toggle', false)
            ->set('account_is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('teachers.index')
            ->call('toggleHelping', $teacher->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teachers', [
            'id' => $teacher->id,
            'status' => 'inactive',
            'is_helping' => false,
        ]);
        $this->assertFalse($teacher->fresh()->user->is_active);

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

    public function test_finishing_a_course_archives_related_records_and_reactivation_restores_only_changed_records(): void
    {
        $this->signIn();

        $academicYear = AcademicYear::create([
            'name' => 'Lifecycle 2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $course = Course::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Lifecycle Course',
            'is_active' => true,
            'awards_points' => true,
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Lifecycle',
            'last_name' => 'Teacher',
            'phone' => '0944000098',
            'course_id' => $course->id,
            'status' => 'active',
            'is_helping' => true,
        ]);
        $activeGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Lifecycle Active Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $inactiveGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Lifecycle Inactive Group',
            'capacity' => 20,
            'is_active' => false,
        ]);
        $student = Student::create([
            'first_name' => 'Lifecycle',
            'last_name' => 'Student',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        $activeEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $activeGroup->id,
            'enrolled_at' => '2026-08-10',
            'status' => 'active',
        ]);
        $historicalEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $inactiveGroup->id,
            'enrolled_at' => '2026-08-01',
            'left_at' => '2026-08-05',
            'status' => 'completed',
        ]);
        $assessmentType = AssessmentType::create([
            'name' => 'Lifecycle Assessment',
            'code' => 'lifecycle-assessment',
            'is_scored' => true,
            'is_active' => true,
        ]);
        $activeAssessment = Assessment::create([
            'group_id' => $activeGroup->id,
            'assessment_type_id' => $assessmentType->id,
            'title' => 'Active lifecycle assessment',
            'is_active' => true,
        ]);
        $inactiveAssessment = Assessment::create([
            'group_id' => $inactiveGroup->id,
            'assessment_type_id' => $assessmentType->id,
            'title' => 'Historical lifecycle assessment',
            'is_active' => false,
        ]);
        $attendanceStatus = AttendanceStatus::create([
            'name' => 'Lifecycle present',
            'code' => 'lifecycle-present',
            'scope' => 'both',
            'is_present' => true,
            'is_active' => true,
        ]);
        $studentAttendanceDay = StudentAttendanceDay::create([
            'attendance_date' => '2026-08-15',
            'course_id' => $course->id,
            'status' => 'open',
        ]);
        $groupAttendanceDay = GroupAttendanceDay::create([
            'group_id' => $activeGroup->id,
            'student_attendance_day_id' => $studentAttendanceDay->id,
            'attendance_date' => '2026-08-15',
            'status' => 'open',
        ]);
        $teacherAttendanceDay = TeacherAttendanceDay::create([
            'attendance_date' => '2026-08-15',
            'status' => 'open',
        ]);
        $teacherAttendanceRecord = TeacherAttendanceRecord::create([
            'teacher_attendance_day_id' => $teacherAttendanceDay->id,
            'teacher_id' => $teacher->id,
            'attendance_status_id' => $attendanceStatus->id,
        ]);
        $pointType = PointType::create([
            'name' => 'Lifecycle points',
            'code' => 'lifecycle-points',
            'category' => 'ManualEntry',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);
        PointTransaction::create([
            'student_id' => $student->id,
            'enrollment_id' => $activeEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 5,
            'entered_at' => '2026-08-15 12:00:00',
        ]);

        Volt::test('courses.index')
            ->call('deactivate', $course->id)
            ->assertHasNoErrors();

        $this->assertFalse($course->fresh()->is_active);
        $this->assertFalse($course->fresh()->awards_points);
        $this->assertTrue($course->fresh()->course_finished_was_awarding_points);
        $this->assertNotNull($course->fresh()->finished_at);
        $this->assertFalse($activeGroup->fresh()->is_active);
        $this->assertNotNull($activeGroup->fresh()->course_finished_at);
        $this->assertTrue($activeGroup->fresh()->course_finished_was_active);
        $this->assertFalse($inactiveGroup->fresh()->is_active);
        $this->assertNotNull($inactiveGroup->fresh()->course_finished_at);
        $this->assertFalse($inactiveGroup->fresh()->course_finished_was_active);
        $this->assertSame('completed', $activeEnrollment->fresh()->status);
        $this->assertNotNull($activeEnrollment->fresh()->course_finished_at);
        $this->assertSame('active', $activeEnrollment->fresh()->course_finished_previous_status);
        $this->assertNull($activeEnrollment->fresh()->course_finished_previous_left_at);
        $this->assertSame('completed', $historicalEnrollment->fresh()->status);
        $this->assertNotNull($historicalEnrollment->fresh()->course_finished_at);
        $this->assertSame('completed', $historicalEnrollment->fresh()->course_finished_previous_status);
        $this->assertSame('2026-08-05', $historicalEnrollment->fresh()->course_finished_previous_left_at?->toDateString());
        $this->assertFalse($activeAssessment->fresh()->is_active);
        $this->assertNotNull($activeAssessment->fresh()->course_finished_at);
        $this->assertFalse($inactiveAssessment->fresh()->is_active);
        $this->assertNull($inactiveAssessment->fresh()->course_finished_at);
        $this->assertSame('closed', $studentAttendanceDay->fresh()->status);
        $this->assertNotNull($studentAttendanceDay->fresh()->course_finished_at);
        $this->assertTrue($studentAttendanceDay->fresh()->course_finished_was_open);
        $this->assertSame('closed', $groupAttendanceDay->fresh()->status);
        $this->assertSame($course->id, $teacherAttendanceRecord->fresh()->archived_course_id);
        $this->assertNotNull($teacherAttendanceRecord->fresh()->course_finished_at);

        Volt::test('student-attendance.index')->assertDontSee('15-08-2026');
        Volt::test('teachers.attendance')->assertDontSee('15-08-2026');
        Volt::test('assessments.index')
            ->set('statusFilter', 'all')
            ->assertDontSee('Active lifecycle assessment')
            ->assertDontSee('Historical lifecycle assessment');
        Volt::test('points.index')
            ->set('stateFilter', 'all')
            ->assertDontSee('Lifecycle Student');

        Volt::test('groups.show', ['group' => $activeGroup])
            ->call('openEdit')
            ->assertHasErrors('group');

        Volt::test('groups.schedules', ['group' => $activeGroup])
            ->set('day_of_week', '6')
            ->set('time_slot', 'between_afternoon_sunset')
            ->call('save')
            ->assertHasErrors('group');

        Volt::test('student-attendance.show', ['studentAttendanceDay' => $studentAttendanceDay])
            ->call('toggleDayStatus')
            ->assertHasErrors('day');

        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSet('editingAcademicYearIsActive', true);

        Volt::test('courses.index')
            ->call('openArchive', $course->id)
            ->assertSee(__('crud.courses.actions.reactivate'))
            ->call('reactivate', $course->id)
            ->assertHasNoErrors()
            ->assertSet('showArchiveModal', false);

        $this->assertTrue($course->fresh()->is_active);
        $this->assertTrue($course->fresh()->awards_points);
        $this->assertNull($course->fresh()->course_finished_was_awarding_points);
        $this->assertNull($course->fresh()->finished_at);
        $this->assertTrue($activeGroup->fresh()->is_active);
        $this->assertNull($activeGroup->fresh()->course_finished_at);
        $this->assertNull($activeGroup->fresh()->course_finished_was_active);
        $this->assertFalse($inactiveGroup->fresh()->is_active);
        $this->assertNull($inactiveGroup->fresh()->course_finished_at);
        $this->assertNull($inactiveGroup->fresh()->course_finished_was_active);
        $this->assertSame('active', $activeEnrollment->fresh()->status);
        $this->assertNull($activeEnrollment->fresh()->left_at);
        $this->assertNull($activeEnrollment->fresh()->course_finished_previous_status);
        $this->assertSame('completed', $historicalEnrollment->fresh()->status);
        $this->assertSame('2026-08-05', $historicalEnrollment->fresh()->left_at?->toDateString());
        $this->assertNull($historicalEnrollment->fresh()->course_finished_previous_status);
        $this->assertTrue($activeAssessment->fresh()->is_active);
        $this->assertFalse($inactiveAssessment->fresh()->is_active);
        $this->assertSame('open', $studentAttendanceDay->fresh()->status);
        $this->assertNull($studentAttendanceDay->fresh()->course_finished_at);
        $this->assertFalse($studentAttendanceDay->fresh()->course_finished_was_open);
        $this->assertSame('open', $groupAttendanceDay->fresh()->status);
        $this->assertNull($teacherAttendanceRecord->fresh()->archived_course_id);
        $this->assertNull($teacherAttendanceRecord->fresh()->course_finished_at);

        Volt::test('student-attendance.index')->assertSee('15-08-2026');
        Volt::test('teachers.attendance')->assertSee('15-08-2026');
        Volt::test('assessments.index')
            ->set('statusFilter', 'all')
            ->assertSee('Active lifecycle assessment')
            ->assertSee('Historical lifecycle assessment');
        Volt::test('points.index')
            ->set('stateFilter', 'all')
            ->assertSee('Lifecycle Student');
    }

    public function test_legacy_finished_courses_gain_archive_markers_and_restore_rows_changed_by_the_old_lifecycle(): void
    {
        $this->signIn();

        $academicYear = AcademicYear::create([
            'name' => 'Legacy lifecycle 2025/2026',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $course = Course::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Legacy Finished Course',
            'is_active' => false,
            'awards_points' => true,
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Legacy',
            'last_name' => 'Teacher',
            'phone' => '0944000097',
            'course_id' => $course->id,
            'status' => 'active',
            'is_helping' => true,
        ]);
        $legacyGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Legacy Changed Group',
            'capacity' => 20,
            'is_active' => false,
        ]);
        $historicalGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Previously Inactive Group',
            'capacity' => 20,
            'is_active' => false,
        ]);
        $student = Student::create([
            'first_name' => 'Legacy',
            'last_name' => 'Student',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        $legacyEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $legacyGroup->id,
            'enrolled_at' => '2025-08-01',
            'left_at' => '2026-07-31',
            'status' => 'completed',
        ]);
        $historicalEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $historicalGroup->id,
            'enrolled_at' => '2025-08-01',
            'left_at' => '2026-06-01',
            'status' => 'completed',
        ]);
        $assessmentType = AssessmentType::create([
            'name' => 'Legacy lifecycle assessment',
            'code' => 'legacy-lifecycle-assessment',
            'is_scored' => true,
            'is_active' => true,
        ]);
        $legacyAssessment = Assessment::create([
            'group_id' => $legacyGroup->id,
            'assessment_type_id' => $assessmentType->id,
            'title' => 'Legacy Changed Assessment',
            'is_active' => false,
        ]);
        $historicalAssessment = Assessment::create([
            'group_id' => $historicalGroup->id,
            'assessment_type_id' => $assessmentType->id,
            'title' => 'Previously Inactive Assessment',
            'is_active' => false,
        ]);

        $legacyFinishedAt = Carbon::parse('2026-07-31 12:00:00');
        $olderTimestamp = $legacyFinishedAt->copy()->subDay();
        foreach ([$course, $legacyGroup, $legacyEnrollment, $legacyAssessment] as $legacyRecord) {
            $legacyRecord->forceFill(['updated_at' => $legacyFinishedAt])->saveQuietly();
        }
        foreach ([$historicalGroup, $historicalEnrollment, $historicalAssessment] as $historicalRecord) {
            $historicalRecord->forceFill(['updated_at' => $olderTimestamp])->saveQuietly();
        }

        $lifecycle = app(CourseLifecycleService::class);
        $lifecycle->adoptLegacyFinishedState($course->fresh());

        $this->assertNotNull($course->fresh()->finished_at);
        $this->assertFalse($course->fresh()->awards_points);
        $this->assertSame([
            'groups' => 2,
            'enrollments' => 2,
            'assessments' => 1,
            'student_attendance' => 0,
            'teacher_attendance' => 0,
        ], $lifecycle->archiveSummary($course->fresh()));

        Volt::test('courses.index')
            ->call('reactivate', $course->id)
            ->assertHasNoErrors();

        $this->assertTrue($course->fresh()->is_active);
        $this->assertTrue($course->fresh()->awards_points);
        $this->assertTrue($legacyGroup->fresh()->is_active);
        $this->assertFalse($historicalGroup->fresh()->is_active);
        $this->assertSame('active', $legacyEnrollment->fresh()->status);
        $this->assertNull($legacyEnrollment->fresh()->left_at);
        $this->assertSame('completed', $historicalEnrollment->fresh()->status);
        $this->assertSame('2026-06-01', $historicalEnrollment->fresh()->left_at?->toDateString());
        $this->assertTrue($legacyAssessment->fresh()->is_active);
        $this->assertFalse($historicalAssessment->fresh()->is_active);
    }

    public function test_copying_a_course_copies_course_and_group_metadata_without_enrollments(): void
    {
        $this->signIn();

        $academicYear = AcademicYear::create([
            'name' => 'Copy metadata 2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $course = Course::create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Metadata source course',
            'description' => 'Keep this course description',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-05-31',
            'is_active' => true,
            'awards_points' => false,
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Metadata',
            'last_name' => 'Teacher',
            'phone' => '0944000088',
            'course_id' => $course->id,
            'status' => 'active',
            'is_helping' => true,
        ]);
        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Metadata source group',
            'capacity' => 27,
            'starts_on' => '2026-09-03',
            'ends_on' => '2027-05-28',
            'monthly_fee' => 125,
            'is_active' => true,
        ]);
        $course->schedules()->create([
            'day_of_week' => 6,
            'time_slot' => 'morning',
        ]);
        $student = Student::create([
            'first_name' => 'Not',
            'last_name' => 'Copied',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-03',
            'status' => 'active',
        ]);

        $component = Volt::test('courses.index')
            ->call('edit', $course->id)
            ->call('duplicate', $course->id)
            ->assertHasNoErrors()
            ->assertSet('showFormModal', true)
            ->assertSet('copySetup', true);

        $copy = Course::query()->whereKeyNot($course->id)->where('description', 'Keep this course description')->firstOrFail();
        $copiedGroup = $copy->groups()->firstOrFail();

        $component
            ->assertSet('editingId', $copy->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertSet('showScheduleModal', true)
            ->assertSet('syncScheduleToGroups', true)
            ->call('saveCourseSchedule')
            ->assertHasNoErrors()
            ->assertSet('showScheduleModal', false);

        $this->assertSame($course->academic_year_id, $copy->academic_year_id);
        $this->assertSame($course->starts_on?->toDateString(), $copy->starts_on?->toDateString());
        $this->assertSame($course->ends_on?->toDateString(), $copy->ends_on?->toDateString());
        $this->assertFalse($copy->awards_points);
        $this->assertTrue($copy->is_active);
        $this->assertSame(27, $copiedGroup->capacity);
        $this->assertSame('125.00', $copiedGroup->monthly_fee);
        $this->assertTrue($copiedGroup->is_active);
        $this->assertSame('Metadata source group', $copiedGroup->name);
        $this->assertSame(0, $copiedGroup->enrollments()->count());
        $this->assertDatabaseHas('course_schedules', [
            'course_id' => $copy->id,
            'day_of_week' => 6,
            'time_slot' => 'morning',
        ]);
        $this->assertDatabaseHas('group_schedules', [
            'group_id' => $copiedGroup->id,
            'day_of_week' => 6,
            'time_slot' => 'morning',
        ]);
    }

    public function test_profile_account_credentials_can_be_updated(): void
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
            ->call('edit', $teacher->id)
            ->assertSee('data-teacher-profile-account-form', false)
            ->assertSee('data-teacher-active-toggle', false)
            ->assertSee('wire:model="account_username"', false)
            ->assertSee('wire:model="account_password"', false)
            ->assertSee('wire:click="deleteEditingTeacher"', false)
            ->assertDontSee('wire:click="openAccountModal', false)
            ->set('account_password', 'TeacherPass123!')
            ->call('save')
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

    public function test_creating_a_student_can_default_and_override_the_initial_group_assignment(): void
    {
        $this->signIn();

        $parent = ParentProfile::create([
            'father_name' => 'Samer Hasan',
            'is_active' => true,
        ]);

        $targetGrade = GradeLevel::create([
            'name' => 'Grade 4',
            'sort_order' => 4,
            'is_active' => true,
        ]);

        $otherGrade = GradeLevel::create([
            'name' => 'Grade 5',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $unmatchedGrade = GradeLevel::create([
            'name' => 'Grade 6',
            'sort_order' => 6,
            'is_active' => true,
        ]);

        $currentAcademicYear = AcademicYear::create([
            'name' => '2026 / 2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
            'is_active' => true,
        ]);

        $previousAcademicYear = AcademicYear::create([
            'name' => '2025 / 2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
            'is_current' => false,
            'is_active' => true,
        ]);

        $course = Course::create([
            'name' => 'Immediate Enrollment Course',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Default',
            'last_name' => 'Teacher',
            'phone' => '0944003311',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $olderMatchingGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $previousAcademicYear->id,
            'teacher_id' => $teacher->id,
            'grade_level_id' => $targetGrade->id,
            'name' => 'Older Grade 4 Group',
            'capacity' => 18,
            'is_active' => true,
        ]);

        $currentMatchingGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $currentAcademicYear->id,
            'teacher_id' => $teacher->id,
            'grade_level_id' => $targetGrade->id,
            'name' => 'Current Grade 4 Group',
            'capacity' => 18,
            'is_active' => true,
        ]);

        $otherGradeGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $currentAcademicYear->id,
            'teacher_id' => $teacher->id,
            'grade_level_id' => $otherGrade->id,
            'name' => 'Current Grade 5 Group',
            'capacity' => 18,
            'is_active' => true,
        ]);

        Volt::test('students.index')
            ->call('openCreateModal')
            ->set('grade_level_id', $targetGrade->id)
            ->assertSet('enrollment_group_id', $currentMatchingGroup->id);

        Volt::test('students.index')
            ->call('openCreateModal')
            ->set('grade_level_id', $unmatchedGrade->id)
            ->assertSet('enrollment_group_id', null);

        Volt::test('students.index')
            ->call('openCreateModal')
            ->set('parent_id', $parent->id)
            ->set('first_name', 'Ahmad')
            ->set('last_name', 'Hasan')
            ->set('birth_date', '2014')
            ->set('grade_level_id', $targetGrade->id)
            ->assertSet('enrollment_group_id', $currentMatchingGroup->id)
            ->set('enrollment_group_id', $otherGradeGroup->id)
            ->set('joined_at', '2026-09-01')
            ->call('save')
            ->assertHasNoErrors();

        $student = Student::query()->firstOrFail();

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'group_id' => $otherGradeGroup->id,
            'enrolled_at' => '2026-09-01 00:00:00',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('enrollments', [
            'student_id' => $student->id,
            'group_id' => $olderMatchingGroup->id,
        ]);
    }

    public function test_student_form_calculates_the_grade_and_manages_previously_memorized_juz_chips(): void
    {
        $this->signIn();

        AcademicYear::create([
            'name' => '2026 / 2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'is_current' => true,
            'is_active' => true,
        ]);

        $calculatedGrade = GradeLevel::create([
            'name' => 'Grade 7',
            'sort_order' => 17,
            'is_active' => true,
        ]);

        $firstJuz = QuranJuz::create([
            'juz_number' => 4,
            'from_page' => 62,
            'to_page' => 81,
        ]);
        $secondJuz = QuranJuz::create([
            'juz_number' => 7,
            'from_page' => 122,
            'to_page' => 141,
        ]);
        $selectableParent = ParentProfile::create([
            'father_name' => 'Parent Selection',
            'is_active' => true,
        ]);
        $displayCurrentJuzNumber = app()->getLocale() === 'ar' ? '٤' : '4';

        Volt::test('students.index')
            ->call('openCreateModal')
            ->assertSee('data-student-identity-row', false)
            ->assertSee('data-student-parent-row', false)
            ->assertSee('data-student-juz-row', false)
            ->assertSeeInOrder(['data-student-juz-row', 'data-student-enrollment-group-field'], false)
            ->assertSee('data-memorized-juz-input', false)
            ->assertSee('data-current-juz-input', false)
            ->call('openQuickParentForm')
            ->assertSet('showQuickParentForm', true)
            ->assertDontSee('data-student-parent-row', false)
            ->assertSee(__('crud.parents.form.placeholders.address'))
            ->assertSee('wire:click="closeQuickParentForm"', false)
            ->call('closeQuickParentForm')
            ->assertSee('data-student-parent-row', false)
            ->assertSee('wire:model.live="parent_id"', false)
            ->set('parent_id', $selectableParent->id)
            ->assertDontSee('data-student-parent-row', false)
            ->assertSee('data-student-parent-locked', false)
            ->assertSee('Parent Selection')
            ->assertSee('wire:click="clearSelectedParent"', false)
            ->call('clearSelectedParent')
            ->assertSet('parent_id', null)
            ->assertSee('data-student-parent-row', false)
            ->assertSee('min-h-[2.875rem]', false)
            ->assertSee('wire:blur="commitCurrentJuz"', false)
            ->assertSee('wire:keydown.enter.prevent="commitCurrentJuz"', false)
            ->assertSee(__('crud.students.form.placeholders.enter_memorized_juz'))
            ->assertDontSee(__('crud.students.form.grade_calculated_help'))
            ->assertDontSee(__('crud.students.form.external_memorized_juzs_help'))
            ->assertSee('wire:keydown.tab="addExternalMemorizedJuz"', false)
            ->set('birth_date', '2014')
            ->assertSet('grade_level_id', $calculatedGrade->id)
            ->set('external_memorized_juz_input', '31')
            ->call('addExternalMemorizedJuz')
            ->assertHasErrors(['external_memorized_juz_input'])
            ->assertSee(__('crud.students.errors.juz_number_range'))
            ->set('external_memorized_juz_input', '4')
            ->call('addExternalMemorizedJuz')
            ->assertSet('external_memorized_juz_ids', [$firstJuz->id])
            ->assertSet('external_memorized_juz_input', '')
            ->assertDontSee(__('crud.students.form.placeholders.enter_memorized_juz'))
            ->assertSee('student-memorized-juz-'.$firstJuz->id, false)
            ->set('external_memorized_juz_input', '7')
            ->call('addExternalMemorizedJuz')
            ->assertSet('external_memorized_juz_ids', [$firstJuz->id, $secondJuz->id])
            ->call('removeExternalMemorizedJuz', $firstJuz->id)
            ->assertSet('external_memorized_juz_ids', [$secondJuz->id])
            ->assertDontSee('student-memorized-juz-'.$firstJuz->id, false)
            ->set('first_name', 'Calculated')
            ->set('last_name', 'Student')
            ->set('quran_current_juz_number', '31')
            ->call('save')
            ->assertHasErrors(['quran_current_juz_number'])
            ->assertSee(__('crud.students.errors.juz_number_range'))
            ->set('quran_current_juz_number', '4')
            ->call('commitCurrentJuz')
            ->assertHasNoErrors(['quran_current_juz_number'])
            ->assertSet('quran_current_juz_id', $firstJuz->id)
            ->assertSet('quran_current_juz_locked', true)
            ->assertSee('data-current-juz-locked', false)
            ->assertSee(__('crud.students.labels.juz_number', ['number' => $displayCurrentJuzNumber]))
            ->assertSee('wire:click="clearCurrentJuz"', false)
            ->call('clearCurrentJuz')
            ->assertDispatched('focus-current-juz')
            ->assertSet('quran_current_juz_id', null)
            ->assertSet('quran_current_juz_number', '')
            ->assertSet('quran_current_juz_locked', false)
            ->assertSee('data-current-juz-input', false)
            ->set('quran_current_juz_number', '4')
            ->call('commitCurrentJuz')
            ->call('save')
            ->assertHasNoErrors();

        $student = Student::query()->where('first_name', 'Calculated')->firstOrFail();

        $this->assertSame($calculatedGrade->id, $student->grade_level_id);
        $this->assertSame($firstJuz->id, $student->quran_current_juz_id);
        $this->assertSame('2014-01-01', $student->birth_date?->format('Y-m-d'));
        $this->assertDatabaseHas('student_external_memorized_juz', [
            'student_id' => $student->id,
            'quran_juz_id' => $secondJuz->id,
        ]);
        $this->assertDatabaseMissing('student_external_memorized_juz', [
            'student_id' => $student->id,
            'quran_juz_id' => $firstJuz->id,
        ]);
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
        $this->assertSame('+963 944 999 001', $parent->user->phone);
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
        $this->assertSame('+963 944 999 911', $student->user->phone);

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
        $this->assertSame(now()->toDateString(), $enrollment->enrolled_at?->toDateString());
        $this->assertSame('active', $enrollment->status);
    }

    public function test_new_enrollment_group_picker_is_searchable_sorted_and_limited_to_active_courses(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Enrollment',
            'last_name' => 'Picker Teacher',
            'phone' => '0944001114',
            'status' => 'active',
        ]);
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);
        $activeCourse = Course::create(['name' => 'Active Picker Course', 'is_active' => true]);
        $inactiveCourse = Course::create(['name' => 'Inactive Picker Course', 'is_active' => false]);

        $zuluGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Zulu Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $alphaGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Alpha Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $inactiveGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Inactive Group',
            'capacity' => 20,
            'is_active' => false,
        ]);
        Group::create([
            'course_id' => $inactiveCourse->id,
            'academic_year_id' => $year->id,
            'teacher_id' => $teacher->id,
            'name' => 'Inactive Course Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $student = Student::create([
            'first_name' => 'Enrollment',
            'last_name' => 'Picker Student',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);

        $component = Volt::test('enrollments.index')
            ->call('openCreateModal')
            ->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [$alphaGroup->id, $zuluGroup->id]);

        $this->assertStringContainsString(
            'id="enrollment-group" wire:model.live="group_id" data-search-input="true" data-open-on-focus="true"',
            $component->html(),
        );

        $component
            ->set('group_id', $inactiveGroup->id)
            ->set('student_id', $student->id)
            ->call('save')
            ->assertHasErrors(['group_id']);
    }

    public function test_group_create_modal_derives_the_current_year_and_create_and_new_preserves_course(): void
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

        AcademicYear::create([
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
            ->assertSee('data-group-form-row="identity"', false)
            ->assertSee('data-group-form-row="teachers"', false)
            ->assertSee('data-group-form-row="learning"', false)
            ->assertSee('data-group-form-row="capacity-template"', false)
            ->assertDontSee(__('crud.groups.form.active_group'))
            ->assertSet('academic_year_id', $currentYear->id)
            ->set('course_id', $course->id)
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
            'academic_year_id' => $currentYear->id,
            'name' => 'Legacy Group A',
        ]);
    }

    public function test_group_dashboard_card_template_can_be_selected_from_group_actions(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Card',
            'last_name' => 'Teacher',
            'phone' => '0944003114',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $course = Course::create([
            'name' => 'Card Course',
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
            'name' => 'Card Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $template = PrintTemplate::create([
            'name' => 'Generic Group Card',
            'width_mm' => 85.6,
            'height_mm' => 54.0,
            'background_image' => null,
            'data_sources' => [],
            'layout_json' => [],
            'is_active' => true,
        ]);

        Volt::test('groups.index')
            ->call('openDashboardCardTemplateModal', $group->id)
            ->assertSet('showDashboardCardTemplateModal', true)
            ->assertSee('Generic Group Card')
            ->set('dashboard_card_template_id', (string) $template->id)
            ->call('saveDashboardCardTemplate')
            ->assertHasNoErrors()
            ->assertSet('showDashboardCardTemplateModal', false);

        $templateMap = AppSetting::groupValues('general')->get('student_dashboard_card_templates');

        $this->assertIsArray($templateMap);
        $this->assertSame($template->id, $templateMap[(string) $group->id] ?? null);
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
            'academic_year_id' => $academicYear->id,
            'name' => 'Roster Course',
            'is_active' => true,
        ]);

        $currentJuz = QuranJuz::create([
            'juz_number' => 7,
            'from_page' => 122,
            'to_page' => 141,
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
            'quran_current_juz_id' => $currentJuz->id,
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
            ->assertSee('+963 999 000 003')
            ->assertSee('Grade 7')
            ->assertSee($parent->parent_number)
            ->assertSee('Fares Hamdan')
            ->assertSee('Mona Hamdan')
            ->assertSee('+963 999 000 001');

        Volt::test('groups.show', ['group' => $group])
            ->assertSee($student->student_number)
            ->assertSee('Grade 7')
            ->assertSee('7')
            ->assertSee('01-09-2026')
            ->assertSee('Fares Hamdan')
            ->assertSee('+963 999 000 001')
            ->assertSee('group-show-details__grid', false)
            ->assertSee('data-group-copy-summary', false)
            ->assertSee('group-roster-table__name-value', false)
            ->assertDontSee('min-w-[88rem]', false)
            ->set('showScheduleModal', true)
            ->assertSee('settings-record-table', false)
            ->assertSee('schedule-add-row', false)
            ->call('openEdit')
            ->assertSee('data-group-form-row="identity"', false)
            ->assertSee('data-group-form-row="teachers"', false)
            ->assertSee('data-group-form-row="learning"', false)
            ->assertSee('data-group-form-row="capacity-template"', false)
            ->assertDontSee(__('crud.groups.form.fields.monthly_fee'))
            ->assertDontSee(__('crud.groups.form.fields.starts_on'))
            ->assertDontSee(__('crud.groups.form.fields.ends_on'));

        $groupCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.group-roster-table th:nth-child(2)', $groupCss);
        $this->assertStringContainsString('.group-roster-table th:nth-child(8)', $groupCss);
        $this->assertStringContainsString('width: 5.12%;', $groupCss);
        $this->assertStringContainsString('width: 12.88%;', $groupCss);
        $this->assertStringContainsString('width: 12.6%;', $groupCss);
        $this->assertStringContainsString('width: 11.7%;', $groupCss);
        $this->assertStringContainsString('width: 16.7%;', $groupCss);
        $this->assertStringContainsString('width: 6.75rem !important;', $groupCss);
        $this->assertStringContainsString('width: 8.25rem !important;', $groupCss);
        $this->assertStringContainsString('.group-show-hero-layout > :first-child,', $groupCss);
        $this->assertStringContainsString('flex: 0 0 auto;', $groupCss);

        $rosterPdfHtml = view('exports.group-roster-pdf', [
            'enrollments' => Enrollment::query()->where('group_id', $group->id)->with(['student.parentProfile', 'student.user', 'student.gradeLevel', 'student.quranCurrentJuz'])->get(),
            'group' => $group->fresh(['teacher']),
            'logoImage' => null,
        ])->render();

        $this->assertStringContainsString('.title-row td', $rosterPdfHtml);
        $this->assertStringContainsString('border: 0;', $rosterPdfHtml);
        $this->assertStringContainsString('@page { margin: 35mm 10mm 18mm;', $rosterPdfHtml);
        $this->assertStringContainsString('height: 4mm;', $rosterPdfHtml);
        $this->assertStringContainsString('<tr class="roster-page-gap"><th colspan="7">&nbsp;</th></tr>', $rosterPdfHtml);
        $this->assertStringContainsString('<th style="width: 18%;">اسم الطالب</th>', $rosterPdfHtml);
        $this->assertStringContainsString('<th style="width: 16%;">جوال الأب</th>', $rosterPdfHtml);
        $this->assertStringContainsString('رقم الطالب', $rosterPdfHtml);
        $this->assertStringNotContainsString('>باركود</th>', $rosterPdfHtml);
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
            ->set('first_name', 'محمد')
            ->assertSet('account_username', '')
            ->set('last_name', 'الخير')
            ->assertSet('account_username', 'mohammad.alkhair')
            ->set('first_name', 'أحمد')
            ->assertSet('account_username', 'ahmad.alkhair')
            ->assertDontSee(__('crud.teachers.form.options.select_access_role'))
            ->assertSee('wire:model.live="access_roles" type="checkbox"', false)
            ->assertSee(__('ui.roles.super_admin'))
            ->assertSee(__('ui.roles.admin'))
            ->assertSee(__('ui.roles.manager'));
    }

    public function test_teacher_roles_are_multiselect_and_financial_signatures_are_managed_from_teachers_only(): void
    {
        $this->signIn();
        Storage::fake('public');

        $adminRole = Role::findByName('admin', 'web');
        $managerRole = Role::findByName('manager', 'web');
        $scopeParent = ParentProfile::create(['father_name' => 'Teacher Scope Parent']);

        Volt::test('teachers.index')
            ->call('openCreateModal')
            ->assertSee('admin-modal__dialog--2xl', false)
            ->assertSee('data-teacher-identity-third', false)
            ->assertSee('data-teacher-identity-half', false)
            ->assertSee('data-teacher-additional-permissions', false)
            ->assertSee(__('access.users.sections.additional_permissions'))
            ->assertSee('data-teacher-direct-permissions', false)
            ->assertSee('data-teacher-scope-overrides', false)
            ->set('first_name', 'Multi')
            ->set('last_name', 'Role')
            ->set('phone', '0944777000')
            ->set('account_username', 'multi-role-teacher')
            ->set('access_roles', [$managerRole->name, $adminRole->name])
            ->set('direct_permissions', ['points.create-manual'])
            ->set('scope_parents', [$scopeParent->id])
            ->assertSee('data-teacher-finance-signature', false)
            ->set('finance_signature_upload', UploadedFile::fake()->image('teacher-signature.png', 600, 180))
            ->call('save')
            ->assertHasNoErrors();

        $teacher = Teacher::query()->with(['user.roles', 'user.permissions', 'user.scopeOverrides'])->where('first_name', 'Multi')->firstOrFail();

        $this->assertSame($adminRole->id, $teacher->access_role_id);
        $this->assertTrue($teacher->user->hasAllRoles([$adminRole->name, $managerRole->name]));
        $this->assertFalse($teacher->user->hasRole('teacher'));
        $this->assertTrue($teacher->user->hasDirectPermission('points.create-manual'));
        $this->assertSame([$scopeParent->id], $teacher->user->scopeOverrides->where('scope_type', 'parent')->pluck('scope_id')->all());
        $this->assertNotNull($teacher->user->finance_signature_path);
        Storage::disk('public')->assertExists($teacher->user->finance_signature_path);

        auth()->user()->givePermissionTo(['users.view', 'users.update']);

        Volt::test('users.index')
            ->assertDontSee('data-user-edit-action="'.$teacher->user_id.'"', false)
            ->call('edit', $teacher->user_id)
            ->assertForbidden();

        Volt::test('teachers.index')
            ->call('edit', $teacher->id)
            ->set('first_name', 'Updated Multi')
            ->assertSet('account_username', 'multi-role-teacher')
            ->assertSet('access_roles', fn (array $roles): bool => collect($roles)->sort()->values()->all() === collect([$adminRole->name, $managerRole->name])->sort()->values()->all())
            ->assertSet('direct_permissions', fn (array $permissions): bool => in_array('points.create-manual', $permissions, true))
            ->assertSet('scope_parents', [$scopeParent->id])
            ->assertSee('data-teacher-finance-signature', false);

        auth()->user()->revokePermissionTo('users.update');

        Volt::test('teachers.index')
            ->call('edit', $teacher->id)
            ->assertSee('data-teacher-direct-permissions', false)
            ->assertSee('data-teacher-scope-overrides', false)
            ->call('save')
            ->assertHasNoErrors();

        $teacher->user->refresh()->load(['permissions', 'scopeOverrides']);
        $this->assertTrue($teacher->user->hasDirectPermission('points.create-manual'));
        $this->assertSame([$scopeParent->id], $teacher->user->scopeOverrides->where('scope_type', 'parent')->pluck('scope_id')->all());
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

    public function test_student_search_uses_only_name_or_student_number(): void
    {
        $this->signIn();

        $parent = ParentProfile::create([
            'father_name' => 'Unique Parent Lookup',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Numbered',
            'last_name' => 'Student',
            'student_number' => 'ST-90817',
            'school_name' => 'Unique School Lookup',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);

        Volt::test('students.index')
            ->set('search', $student->fresh()->student_number)
            ->assertSee('Numbered Student');

        Volt::test('students.index')
            ->set('search', 'Unique Parent Lookup')
            ->assertDontSee('Numbered Student');

        Volt::test('students.index')
            ->set('search', 'Unique School Lookup')
            ->assertDontSee('Numbered Student');
    }

    public function test_creating_a_student_with_the_same_name_and_birth_year_updates_the_inactive_record(): void
    {
        $this->signIn();

        $oldParent = ParentProfile::create([
            'father_name' => 'Old Parent',
            'is_active' => true,
        ]);

        $existingStudent = Student::create([
            'parent_id' => $oldParent->id,
            'first_name' => 'Ahmad',
            'last_name' => 'Same Student',
            'birth_date' => '2014-08-20',
            'school_name' => 'Old School',
            'status' => 'inactive',
            'notes' => 'Old notes',
        ]);

        $component = Volt::test('students.index')
            ->call('openCreateModal')
            ->set('first_name', 'Ahmad')
            ->set('last_name', 'Same Student')
            ->set('birth_date', '2014')
            ->set('school_name', 'New School')
            ->set('notes', 'Updated notes')
            ->call('openQuickParentForm')
            ->set('quick_parent_father_name', 'New Parent')
            ->set('quick_parent_father_phone', '0944555010')
            ->call('saveQuickParent')
            ->assertHasNoErrors();

        $newParent = ParentProfile::query()->where('father_name', 'New Parent')->firstOrFail();

        $component
            ->assertSet('parent_id', $newParent->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Student::query()->count());
        $this->assertDatabaseHas('students', [
            'id' => $existingStudent->id,
            'parent_id' => $newParent->id,
            'first_name' => 'Ahmad',
            'last_name' => 'Same Student',
            'school_name' => 'New School',
            'notes' => 'Updated notes',
        ]);
        $this->assertSame('2014-01-01', $existingStudent->fresh()->birth_date?->format('Y-m-d'));
        $this->assertSame('active', $existingStudent->fresh()->status);
    }

    public function test_creating_a_student_matching_an_active_student_shows_details_without_changing_data(): void
    {
        $this->signIn();

        $originalParent = ParentProfile::create([
            'father_name' => 'Original Parent',
            'is_active' => true,
        ]);
        $existingStudent = Student::create([
            'parent_id' => $originalParent->id,
            'first_name' => 'Active',
            'last_name' => 'Duplicate',
            'birth_date' => '2014-08-20',
            'school_name' => 'Original School',
            'status' => 'active',
            'notes' => 'Original notes',
        ]);

        $component = Volt::test('students.index')
            ->call('openCreateModal')
            ->set('first_name', 'Active')
            ->set('last_name', 'Duplicate')
            ->set('birth_date', '2014')
            ->set('school_name', 'Changed School')
            ->set('notes', 'Changed notes')
            ->call('openQuickParentForm')
            ->set('quick_parent_father_name', 'Submitted Parent')
            ->set('quick_parent_father_phone', '0944555099')
            ->call('saveQuickParent')
            ->assertSet('showDuplicateStudentModal', true)
            ->assertSet('duplicateStudentId', $existingStudent->id);

        $this->assertDatabaseMissing('parents', ['father_name' => 'Submitted Parent']);

        $component
            ->call('closeDuplicateStudentModal')
            ->call('saveAndNew')
            ->assertSet('showDuplicateStudentModal', true)
            ->assertSet('duplicateStudentId', $existingStudent->id)
            ->assertSet('showFormModal', true)
            ->assertSee('Original School')
            ->assertSee('Original notes');

        $this->assertSame(1, Student::query()->count());
        $this->assertDatabaseHas('students', [
            'id' => $existingStudent->id,
            'parent_id' => $originalParent->id,
            'school_name' => 'Original School',
            'status' => 'active',
            'notes' => 'Original notes',
        ]);
        $this->assertDatabaseMissing('students', [
            'school_name' => 'Changed School',
        ]);
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

    public function test_student_bulk_activation_includes_students_without_parents(): void
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
            ->assertHasNoErrors();

        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_student_can_be_saved_active_without_a_parent(): void
    {
        $student = Student::create([
            'first_name' => 'Rule',
            'last_name' => 'Check',
            'birth_date' => '2012-01-01',
            'status' => 'active',
        ]);

        $this->assertSame('active', $student->fresh()->status);

        $student->status = 'active';
        $student->save();

        $this->assertSame('active', $student->fresh()->status);
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
            ->set('time_slot', 'between_afternoon_sunset')
            ->call('save')
            ->assertHasNoErrors();

        $schedule = GroupSchedule::query()->firstOrFail();

        Volt::test('groups.schedules', ['group' => $group])
            ->call('edit', $schedule->id)
            ->set('time_slot', 'after_night')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('group_schedules', [
            'id' => $schedule->id,
            'time_slot' => 'after_night',
        ]);

        $this->assertSame('20:30', $schedule->fresh()->starts_at?->format('H:i'));

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
            ->set('photo_upload', UploadedFile::fake()->create('student-photo.jpg', 15360, 'image/jpeg'))
            ->call('savePhoto')
            ->assertHasNoErrors();

        $student->refresh();

        $this->assertNotNull($student->photo_path);
        Storage::disk('public')->assertExists($student->photo_path);

        Volt::test('students.index')
            ->set('quick_photo_upload', UploadedFile::fake()->create('replacement-photo.webp', 15360, 'image/webp'))
            ->call('uploadStudentPhoto', $student->id)
            ->assertHasNoErrors();

        $this->assertStringEndsWith('.webp', $student->fresh()->photo_path);

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

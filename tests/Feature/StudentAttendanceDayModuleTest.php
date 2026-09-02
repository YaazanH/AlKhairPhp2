<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupSchedule;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\StudentAttendanceDayService;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentAttendanceDayModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_an_attendance_day_for_active_groups(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-manager',
            'phone' => '0998111000',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Attendance',
            'last_name' => 'Teacher',
            'phone' => '0998111001',
            'status' => 'active',
        ]);

        $selectedCourse = Course::create([
            'name' => 'Selected Attendance Course',
            'is_active' => true,
        ]);

        $firstEnrollment = $this->makeEnrollment($teacher->id, 'Morning Group', true, $selectedCourse);
        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Evening Group', true, $selectedCourse);
        $inactiveEnrollment = $this->makeEnrollment($teacher->id, 'Inactive Group', false, $selectedCourse);
        $otherCourseEnrollment = $this->makeEnrollment($teacher->id, 'Other Course Group');
        $this->scheduleGroupForDate($firstEnrollment->group, '2026-10-01');
        $this->scheduleGroupForDate($secondEnrollment->group, '2026-10-01');
        $this->scheduleGroupForDate($inactiveEnrollment->group, '2026-10-01');
        $this->scheduleGroupForDate($otherCourseEnrollment->group, '2026-10-01');
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        $this->actingAs($manager);

        Volt::test('student-attendance.index')
            ->assertSee('id="student-attendance-search" wire:model.live="search" type="date"', false)
            ->call('openCreateModal')
            ->set('course_id', (string) $selectedCourse->id)
            ->set('attendance_date', '2026-10-01')
            ->set('day_status', 'open')
            ->set('notes', 'Day-first attendance')
            ->call('saveDay')
            ->assertHasNoErrors();

        $day = StudentAttendanceDay::query()
            ->whereDate('attendance_date', '2026-10-01')
            ->where('course_id', $selectedCourse->id)
            ->firstOrFail();

        $this->assertSame($selectedCourse->id, $day->course_id);

        $this->assertDatabaseHas('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $firstEnrollment->group_id,
        ]);

        $this->assertDatabaseHas('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $secondEnrollment->group_id,
        ]);

        $this->assertDatabaseMissing('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $inactiveEnrollment->group_id,
        ]);

        $this->assertDatabaseMissing('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $otherCourseEnrollment->group_id,
        ]);

        $this->assertDatabaseHas('student_attendance_records', [
            'enrollment_id' => $firstEnrollment->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseHas('student_attendance_records', [
            'enrollment_id' => $secondEnrollment->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseMissing('student_attendance_records', [
            'enrollment_id' => $inactiveEnrollment->id,
        ]);

        $this->assertSame(2, StudentAttendanceRecord::query()->count());
        $this->assertSame(4, PointTransaction::query()->where('source_type', 'student_attendance_record')->whereNull('voided_at')->sum('points'));
    }

    public function test_student_and_teacher_attendance_days_require_a_default_status(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-required-status-manager',
            'phone' => '0998111888',
        ]);
        $manager->assignRole('manager');

        $course = Course::create([
            'name' => 'Required Status Course',
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        Volt::test('student-attendance.index')
            ->call('openCreateModal')
            ->assertSee('id="attendance-day-default-status" wire:model="default_attendance_status_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true"', false)
            ->assertSee('<option value="" disabled hidden>', false)
            ->set('course_id', (string) $course->id)
            ->set('default_attendance_status_id', '')
            ->call('saveDay')
            ->assertHasErrors(['default_attendance_status_id' => 'required']);

        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->assertSee('id="teacher-attendance-day-default-status" wire:model="default_attendance_status_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true"', false)
            ->assertSee('<option value="" disabled hidden>', false)
            ->set('course_id', (string) $course->id)
            ->set('default_attendance_status_id', '')
            ->call('saveDay')
            ->assertHasErrors(['default_attendance_status_id' => 'required']);
    }

    public function test_student_and_teacher_attendance_days_require_non_clearable_dates_and_courses(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-required-context-manager',
            'phone' => '0998111887',
        ]);
        $manager->assignRole('manager');

        Course::create([
            'name' => 'Required Attendance Context Course',
            'is_active' => true,
        ]);

        $this->actingAs($manager);

        Volt::test('student-attendance.index')
            ->call('openCreateModal')
            ->assertSee('id="attendance-day-course" wire:model.live="course_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true"', false)
            ->assertSee('id="attendance-day-date" wire:model.live="attendance_date" value="', false)
            ->assertSee('type="date" required aria-required="true" data-clearable="false"', false)
            ->set('course_id', '')
            ->set('attendance_date', '')
            ->call('saveDay')
            ->assertHasErrors([
                'course_id' => 'required',
                'attendance_date' => 'required',
            ]);

        Volt::test('teachers.attendance')
            ->call('openCreateModal')
            ->assertSee('id="teacher-attendance-day-course" wire:model.live="course_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true"', false)
            ->assertSee('id="teacher-attendance-day-date" wire:model.live="attendance_date" value="', false)
            ->assertSee('type="date" required aria-required="true" data-clearable="false"', false)
            ->set('course_id', '')
            ->set('attendance_date', '')
            ->call('saveDay')
            ->assertHasErrors([
                'course_id' => 'required',
                'attendance_date' => 'required',
            ]);
    }

    public function test_manager_can_add_extra_group_to_attendance_day_manually(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-extra-manager',
            'phone' => '0998111999',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Extra',
            'last_name' => 'Teacher',
            'phone' => '0998111998',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Manual Attendance Course',
            'is_active' => true,
        ]);

        $scheduledEnrollment = $this->makeEnrollment($teacher->id, 'Scheduled Group', true, $course);
        $extraEnrollment = $this->makeEnrollment($teacher->id, 'Extra Group', true, $course);
        $this->scheduleGroupForDate($scheduledEnrollment->group, '2026-10-06');

        $this->actingAs($manager);

        Volt::test('student-attendance.index')
            ->call('openCreateModal')
            ->set('course_id', (string) $course->id)
            ->set('attendance_date', '2026-10-06')
            ->set('day_status', 'open')
            ->call('saveDay')
            ->assertHasNoErrors();

        $day = StudentAttendanceDay::query()
            ->whereDate('attendance_date', '2026-10-06')
            ->where('course_id', $course->id)
            ->firstOrFail();

        $this->assertDatabaseHas('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $scheduledEnrollment->group_id,
        ]);

        $this->assertDatabaseMissing('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $extraEnrollment->group_id,
        ]);

        Volt::test('student-attendance.show', ['studentAttendanceDay' => $day])
            ->call('openManualGroupModal')
            ->assertSee('admin-modal__dialog--xl admin-modal__dialog--compact', false)
            ->set('manual_group_id', (string) $extraEnrollment->group_id)
            ->call('addManualGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('group_attendance_days', [
            'student_attendance_day_id' => $day->id,
            'group_id' => $extraEnrollment->group_id,
        ]);
    }

    public function test_day_details_show_total_group_students_separately_from_present_students(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-counts-manager',
            'phone' => '0998111777',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Counts',
            'last_name' => 'Teacher',
            'phone' => '0998111778',
            'status' => 'active',
        ]);

        $firstEnrollment = $this->makeEnrollment($teacher->id, 'Separate Counts Group');

        $secondParent = ParentProfile::create([
            'father_name' => 'Separate Counts Parent 2',
        ]);

        $secondStudent = Student::create([
            'parent_id' => $secondParent->id,
            'first_name' => 'Separate Counts',
            'last_name' => 'Student 2',
            'birth_date' => '2013-04-10',
            'status' => 'active',
        ]);

        $secondEnrollment = Enrollment::create([
            'student_id' => $secondStudent->id,
            'group_id' => $firstEnrollment->group_id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();
        $absent = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->where('is_present', false)
            ->firstOrFail();

        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-07',
            collect([$firstEnrollment->group]),
            $manager,
            null,
            'open',
            $present->id,
        );

        $groupDay = GroupAttendanceDay::query()
            ->where('student_attendance_day_id', $day->id)
            ->where('group_id', $firstEnrollment->group_id)
            ->firstOrFail();

        StudentAttendanceRecord::query()
            ->where('group_attendance_day_id', $groupDay->id)
            ->where('enrollment_id', $secondEnrollment->id)
            ->update(['attendance_status_id' => $absent->id]);

        $this->actingAs($manager)
            ->get(route('student-attendance.show', $day, absolute: false))
            ->assertOk()
            ->assertSeeTextInOrder([
                'Separate Counts Group',
                '2',
                '1',
            ]);
    }

    public function test_manager_can_toggle_student_attendance_day_status_for_all_groups(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-toggle-manager',
            'phone' => '0998111666',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Toggle',
            'last_name' => 'Teacher',
            'phone' => '0998111667',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Toggle Group');
        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-08',
            collect([$enrollment->group]),
            $manager,
            null,
            'open',
        );

        $groupDay = GroupAttendanceDay::query()
            ->where('student_attendance_day_id', $day->id)
            ->where('group_id', $enrollment->group_id)
            ->firstOrFail();

        $this->assertSame('open', $day->status);
        $this->assertSame('open', $groupDay->status);

        $this->actingAs($manager);

        $dayComponent = Volt::test('student-attendance.show', ['studentAttendanceDay' => $day])
            ->call('openManualGroupModal')
            ->assertSet('showManualGroupModal', true)
            ->call('toggleDayStatus')
            ->assertSet('showManualGroupModal', false)
            ->assertHasNoErrors();

        $this->assertSame('closed', $day->fresh()->status);
        $this->assertSame('closed', $groupDay->fresh()->status);

        $closedGroupAttendance = Volt::test('groups.attendance', ['group' => $enrollment->group])
            ->set('attendance_date', '2026-10-08');
        $this->assertStringNotContainsString('wire:model="selected_statuses.'.$enrollment->id.'"', $closedGroupAttendance->html());

        $dayComponent
            ->call('toggleDayStatus')
            ->assertSet('showManualGroupModal', false)
            ->assertDontSee(__('workflow.student_attendance.day_details.manual_add.title'))
            ->assertHasNoErrors();

        $this->assertSame('open', $day->fresh()->status);
        $this->assertSame('open', $groupDay->fresh()->status);

        $openGroupAttendance = Volt::test('groups.attendance', ['group' => $enrollment->group])
            ->set('attendance_date', '2026-10-08');
        $this->assertStringContainsString('wire:model="selected_statuses.'.$enrollment->id.'"', $openGroupAttendance->html());
    }

    public function test_group_shortcut_links_to_parent_day_and_marking_updates_records_and_points(): void
    {
        $this->seed();

        [$teacherUser, $teacher, $enrollment] = $this->teacherContext('attendance-shortcut');
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        Volt::test('groups.attendance', ['group' => $enrollment->group])
            ->set('attendance_date', '2026-10-02')
            ->set('selected_statuses.'.$enrollment->id, (string) $present->id)
            ->call('saveEnrollmentStatus', $enrollment->id)
            ->assertHasNoErrors();

        $groupDay = GroupAttendanceDay::query()
            ->where('group_id', $enrollment->group_id)
            ->whereDate('attendance_date', '2026-10-02')
            ->firstOrFail();

        $this->assertNotNull($groupDay->student_attendance_day_id);

        $this->actingAs($teacherUser);

        Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay])
            ->set('selected_statuses.'.$enrollment->id, (string) $present->id)
            ->call('saveEnrollmentStatus', $enrollment->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('student_attendance_records', [
            'group_attendance_day_id' => $groupDay->id,
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $present->id,
        ]);

        $this->assertDatabaseHas('point_transactions', [
            'enrollment_id' => $enrollment->id,
            'source_type' => 'student_attendance_record',
            'source_id' => $groupDay->records()->firstOrFail()->id,
            'points' => 2,
            'voided_at' => null,
        ]);

        $this->assertSame('open', $groupDay->fresh()->studentAttendanceDay->status);
        $this->assertSame(2, PointTransaction::query()->where('enrollment_id', $enrollment->id)->where('source_type', 'student_attendance_record')->whereNull('voided_at')->sum('points'));
    }

    public function test_closed_student_attendance_day_blocks_group_and_quick_attendance_updates(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-closed-manager',
            'phone' => '0998111555',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Closed',
            'last_name' => 'Teacher',
            'phone' => '0998111556',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Closed Lock Group');
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();
        $attemptedStatus = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->whereKeyNot($present->id)
            ->firstOrFail();

        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-10',
            collect([$enrollment->group]),
            $manager,
            null,
            'open',
        );

        app(StudentAttendanceDayService::class)->setDayStatus($day, 'closed');

        $groupDay = GroupAttendanceDay::query()
            ->where('student_attendance_day_id', $day->id)
            ->where('group_id', $enrollment->group_id)
            ->firstOrFail();

        $this->actingAs($manager);

        Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay])
            ->set('selected_statuses.'.$enrollment->id, (string) $attemptedStatus->id)
            ->call('saveEnrollmentStatus', $enrollment->id)
            ->assertHasErrors(['selected_statuses.'.$enrollment->id]);

        Volt::test('student-attendance.quick', ['studentAttendanceDay' => $day->fresh()])
            ->set('selected_status_id', (string) $attemptedStatus->id)
            ->call('markEnrollment', $enrollment->id)
            ->assertHasErrors(['scan_value']);

        $this->assertDatabaseHas('student_attendance_records', [
            'enrollment_id' => $enrollment->id,
            'attendance_status_id' => $present->id,
        ]);
    }

    public function test_quick_attendance_marks_student_from_day_level_list_and_scan(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-quick-manager',
            'phone' => '0998111444',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Quick',
            'last_name' => 'Teacher',
            'phone' => '0998111445',
            'status' => 'active',
        ]);

        $firstEnrollment = $this->makeEnrollment($teacher->id, 'Quick First Group');
        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Quick Second Group', course: $firstEnrollment->group->course);
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-11',
            collect([$firstEnrollment->group, $secondEnrollment->group]),
            $manager,
            null,
            'open',
        );

        $this->actingAs($manager);

        Volt::test('student-attendance.quick', ['studentAttendanceDay' => $day])
            ->assertSee('Quick First Group Student')
            ->assertSee('Quick Second Group Student')
            ->set('selected_status_id', (string) $present->id)
            ->call('markEnrollment', $firstEnrollment->id)
            ->assertHasNoErrors()
            ->set('scan_value', (string) $secondEnrollment->student->fresh()->student_number)
            ->call('scanStudent')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('student_attendance_records', [
            'enrollment_id' => $firstEnrollment->id,
            'attendance_status_id' => $present->id,
        ]);
        $this->assertDatabaseHas('student_attendance_records', [
            'enrollment_id' => $secondEnrollment->id,
            'attendance_status_id' => $present->id,
        ]);
    }

    public function test_teacher_can_only_open_attendance_days_for_accessible_groups(): void
    {
        $this->seed();

        [$teacherUser, , $assignedEnrollment] = $this->teacherContext('attendance-allowed');
        [, , $otherEnrollment] = $this->teacherContext('attendance-hidden', otherTeacher: true);

        $service = app(StudentAttendanceDayService::class);

        $allowedDay = $service->createOrSyncDay('2026-10-03', collect([$assignedEnrollment->group]), $teacherUser);
        $forbiddenDay = $service->createOrSyncDay('2026-10-04', collect([$otherEnrollment->group]), $teacherUser);

        $this->actingAs($teacherUser);

        $this->get(route('student-attendance.show', $allowedDay, absolute: false))
            ->assertOk()
            ->assertSeeText($assignedEnrollment->group->name);

        $this->get(route('student-attendance.show', $forbiddenDay, absolute: false))
            ->assertForbidden();

        $forbiddenGroupDay = GroupAttendanceDay::query()
            ->where('student_attendance_day_id', $forbiddenDay->id)
            ->where('group_id', $otherEnrollment->group_id)
            ->firstOrFail();

        $this->get(route('student-attendance.mark', $forbiddenGroupDay, absolute: false))
            ->assertForbidden();
    }

    public function test_teacher_day_details_page_hides_status_toggle_without_the_separate_permission(): void
    {
        $this->seed();

        [$teacherUser, , $assignedEnrollment] = $this->teacherContext('attendance-toggle-hidden');

        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-09',
            collect([$assignedEnrollment->group]),
            $teacherUser,
            null,
            'open',
        );

        $this->actingAs($teacherUser)
            ->get(route('student-attendance.show', $day, absolute: false))
            ->assertOk()
            ->assertDontSeeText('Close day')
            ->assertDontSeeText('Reopen day');

        Volt::test('student-attendance.show', ['studentAttendanceDay' => $day])
            ->call('toggleDayStatus')
            ->assertForbidden();

        $this->assertSame('open', $day->fresh()->status);
    }

    public function test_attendance_index_still_loads_after_days_exist(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-index-manager',
            'phone' => '0998222000',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Index',
            'last_name' => 'Teacher',
            'phone' => '0998222001',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Index Group');

        app(StudentAttendanceDayService::class)->createOrSyncDay('2026-10-05', collect([$enrollment->group]), $manager);

        $this->actingAs($manager)
            ->get(route('student-attendance.index', absolute: false))
            ->assertOk()
            ->assertSeeText('05-10-2026');
    }

    public function test_disabling_course_points_removes_old_attendance_points_from_effective_totals(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'attendance-no-points-manager',
            'phone' => '0998222111',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'No Points',
            'last_name' => 'Teacher',
            'phone' => '0998222112',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'No Points Attendance Course',
            'is_active' => true,
            'awards_points' => true,
        ]);
        $enrollment = $this->makeEnrollment($teacher->id, 'No Points Group', true, $course);
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();
        $service = app(StudentAttendanceDayService::class);

        $this->actingAs($manager);

        $day = $service->createOrSyncDay('2026-10-12', collect([$enrollment->group]), $manager, null, 'open', null, $course->id);
        $service->recordEnrollmentStatus($day, $enrollment->fresh(['student', 'group.course']), $present);

        $this->assertSame(2, $enrollment->fresh()->final_points_cached);
        $this->assertSame(2, PointTransaction::query()->where('enrollment_id', $enrollment->id)->effectiveActive()->sum('points'));

        $course->update(['awards_points' => false]);

        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
        $this->assertSame(0, PointTransaction::query()->where('enrollment_id', $enrollment->id)->effectiveActive()->sum('points'));
        $this->assertSame(1, PointTransaction::query()->where('enrollment_id', $enrollment->id)->inactiveSource()->count());

        $service->recordEnrollmentStatus($day, $enrollment->fresh(['student', 'group.course']), $present);

        $this->assertSame(0, $enrollment->fresh()->final_points_cached);
        $this->assertSame(0, PointTransaction::query()->where('enrollment_id', $enrollment->id)->whereNull('voided_at')->sum('points'));
    }

    public function test_manager_can_export_student_attendance_for_a_selected_course_and_period(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'student-attendance-export-manager',
            'phone' => '0998222990',
        ]);
        $manager->assignRole('manager');
        $teacher = Teacher::create([
            'first_name' => 'Export',
            'last_name' => 'Teacher',
            'phone' => '0998222991',
            'status' => 'active',
        ]);
        $enrollment = $this->makeEnrollment($teacher->id, 'Export Attendance Group');
        $this->makeEnrollment($teacher->id, 'Second Export Attendance Group', true, $enrollment->group->course);
        $enrollment->student->update(['student_number' => 'ST-EXPORT-1']);
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();
        $service = app(StudentAttendanceDayService::class);
        $day = $service->createOrSyncDay('2026-10-14', collect([$enrollment->group]), $manager);
        $service->recordEnrollmentStatus($day, $enrollment->fresh(['student', 'group.course']), $present);

        $this->actingAs($manager)
            ->get(route('student-attendance.index', absolute: false))
            ->assertOk()
            ->assertSee('data-export-action', false)
            ->assertSee('aria-label="'.__('workflow.student_attendance.export.action').'"', false)
            ->assertSeeText('Export Attendance Group');

        Volt::test('student-attendance.index')
            ->assertSee('admin-modal-portal', false)
            ->call('openExportModal')
            ->assertSet('showExportModal', true)
            ->assertSee('admin-modal--full-viewport', false);

        $response = $this->get(route('student-attendance.export', [
            'course_id' => $enrollment->group->course_id,
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ], absolute: false));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()));
    }

    public function test_attendance_screens_replace_missing_statuses_with_the_configured_default(): void
    {
        $this->seed();

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $teacher = Teacher::create([
            'first_name' => 'Default',
            'last_name' => 'Attendance',
            'phone' => '0998111555',
            'status' => 'active',
        ]);
        $enrollment = $this->makeEnrollment($teacher->id, 'Default Status Group');
        $defaultStatus = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->orderByDesc('is_default')
            ->orderByDesc('is_present')
            ->firstOrFail();

        $studentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-15',
            collect([$enrollment->group]),
            $manager,
            null,
            'open',
            $defaultStatus->id,
        );
        $groupDay = $studentDay->groupAttendanceDays()->firstOrFail();
        $groupDay->records()->update(['attendance_status_id' => null]);

        $this->actingAs($manager);
        Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay]);

        $this->assertDatabaseMissing('student_attendance_records', [
            'group_attendance_day_id' => $groupDay->id,
            'attendance_status_id' => null,
        ]);

        $teacherDay = app(TeacherAttendanceDayService::class)->createOrSyncDay(
            '2026-10-16',
            collect([$teacher]),
            $manager,
        );
        TeacherAttendanceRecord::query()
            ->where('teacher_attendance_day_id', $teacherDay->id)
            ->update(['attendance_status_id' => null]);

        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $teacherDay]);

        $this->assertDatabaseMissing('teacher_attendance_records', [
            'teacher_attendance_day_id' => $teacherDay->id,
            'attendance_status_id' => null,
        ]);
    }

    private function teacherContext(string $groupName, bool $otherTeacher = false): array
    {
        $teacherUser = User::factory()->create([
            'username' => $groupName.'-teacher',
            'phone' => fake()->unique()->numerify('0998#######'),
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $otherTeacher ? null : $teacherUser->id,
            'first_name' => 'Scoped',
            'last_name' => 'Teacher',
            'phone' => fake()->unique()->numerify('0997#######'),
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, $groupName);

        if (! $otherTeacher) {
            $this->actingAs($teacherUser);
        }

        return [$teacherUser, $teacher, $enrollment];
    }

    private function makeEnrollment(int $teacherId, string $groupName, bool $isActive = true, ?Course $course = null): Enrollment
    {
        $parent = ParentProfile::create([
            'father_name' => $groupName.' Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => $groupName,
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $course ??= Course::create([
            'name' => $groupName.' Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacherId,
            'name' => $groupName,
            'capacity' => 12,
            'is_active' => $isActive,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);
    }

    private function scheduleGroupForDate(Group $group, string $date): void
    {
        GroupSchedule::create([
            'group_id' => $group->id,
            'day_of_week' => Carbon::parse($date)->dayOfWeek,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'is_active' => true,
        ]);
    }
}

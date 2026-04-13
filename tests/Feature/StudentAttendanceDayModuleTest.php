<?php

namespace Tests\Feature;

use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentAttendanceDayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $firstEnrollment = $this->makeEnrollment($teacher->id, 'Morning Group');
        $secondEnrollment = $this->makeEnrollment($teacher->id, 'Evening Group');
        $inactiveEnrollment = $this->makeEnrollment($teacher->id, 'Inactive Group', false);
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        $this->actingAs($manager);

        Volt::test('student-attendance.index')
            ->call('openCreateModal')
            ->set('attendance_date', '2026-10-01')
            ->set('day_status', 'open')
            ->set('notes', 'Day-first attendance')
            ->call('saveDay')
            ->assertHasNoErrors();

        $day = StudentAttendanceDay::query()
            ->whereDate('attendance_date', '2026-10-01')
            ->firstOrFail();

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

    public function test_group_shortcut_links_to_parent_day_and_marking_updates_records_and_points(): void
    {
        $this->seed();

        [$teacherUser, $teacher, $enrollment] = $this->teacherContext('attendance-shortcut');
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();

        Volt::test('groups.attendance', ['group' => $enrollment->group])
            ->set('attendance_date', '2026-10-02')
            ->set('selected_statuses.'.$enrollment->id, (string) $present->id)
            ->call('saveAttendance')
            ->assertHasNoErrors();

        $groupDay = GroupAttendanceDay::query()
            ->where('group_id', $enrollment->group_id)
            ->whereDate('attendance_date', '2026-10-02')
            ->firstOrFail();

        $this->assertNotNull($groupDay->student_attendance_day_id);

        $this->actingAs($teacherUser);

        Volt::test('student-attendance.mark', ['groupAttendanceDay' => $groupDay])
            ->set('day_status', 'closed')
            ->set('selected_statuses.'.$enrollment->id, (string) $present->id)
            ->call('saveAttendance')
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

        $this->assertSame('closed', $groupDay->fresh()->studentAttendanceDay->status);
        $this->assertSame(2, PointTransaction::query()->where('enrollment_id', $enrollment->id)->where('source_type', 'student_attendance_record')->whereNull('voided_at')->sum('points'));
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
            ->assertSeeText('2026-10-05');
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

    private function makeEnrollment(int $teacherId, string $groupName, bool $isActive = true): Enrollment
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

        $course = Course::create([
            'name' => $groupName.' Course',
            'is_active' => true,
        ]);

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

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
}

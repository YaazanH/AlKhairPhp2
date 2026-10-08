<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Group;
use App\Models\StudentAttendanceDay;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\User;
use App\Services\StudentAttendanceDayService;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UnifiedAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $user = User::factory()->create();
        $user->assignRole('manager');
        $this->actingAs($user);
    }

    public function test_combined_creation_and_switching_keep_the_same_course_and_date(): void
    {
        $course = Course::create(['name' => 'Combined course', 'is_active' => true]);
        $teacher = Teacher::create(['phone' => fake()->unique()->numerify('0997#######'), 'first_name' => 'Scheduled', 'last_name' => 'Teacher', 'status' => 'active']);
        $group = Group::create(['academic_year_id' => AcademicYear::where('is_current', true)->value('id'), 'name' => 'Combined group', 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'is_active' => true]);
        $group->schedules()->create(['day_of_week' => 2, 'starts_at' => '09:00', 'ends_at' => '10:00', 'is_active' => true]);
        $present = AttendanceStatus::where('code', 'present')->firstOrFail();
        Volt::test('attendance.index')->call('openCreateModal')
            ->set('course_id', (string) $course->id)->set('attendance_date', '2026-10-06')
            ->set('default_attendance_status_id', (string) $present->id)->call('saveDay')->assertHasNoErrors();
        $studentDay = StudentAttendanceDay::where('course_id', $course->id)->firstOrFail();
        $teacherDay = TeacherAttendanceDay::where('course_id', $course->id)->firstOrFail();
        $this->assertSame('2026-10-06', $teacherDay->attendance_date->toDateString());
        $this->assertTrue($teacherDay->records()->where('teacher_id', $teacher->id)->exists());
        Volt::test('attendance.show', ['type' => 'students', 'day' => $studentDay->id])
            ->assertSet('tab', 'students')->assertSee('Combined group')
            ->call('switchTab', 'teachers')->assertSet('tab', 'teachers')->assertSee('Scheduled Teacher')
            ->call('switchTab', 'students')->assertSee('Combined group');
        Volt::test('attendance.index')->assertViewHas('days', fn ($days) => $days->total() === 1)->call('openExportModal')
            ->call('switchExportType', 'teachers')->assertSee('teacher-attendance/export/pdf', false)
            ->call('switchExportType', 'students')->assertSee('student-attendance/export/pdf', false);
        $this->get(route('attendance.index'))->assertOk();
        $this->get(route('attendance.show', ['type' => 'students', 'day' => $studentDay->id]))->assertOk();
    }

    public function test_active_manual_teachers_are_added_to_existing_and_new_future_days_and_removal_preserves_history(): void
    {
        $teacher = Teacher::create(['phone' => fake()->unique()->numerify('0997#######'), 'first_name' => 'Manual', 'last_name' => 'Active', 'status' => 'active']);
        $inactive = Teacher::create(['phone' => fake()->unique()->numerify('0997#######'), 'first_name' => 'Hidden', 'last_name' => 'Inactive', 'status' => 'inactive']);
        $service = app(TeacherAttendanceDayService::class);
        $past = $service->createOrSyncDay('2026-10-01', collect([$teacher]), auth()->user(), status: 'closed');
        $future = $service->createOrSyncDay('2026-10-10', collect(), auth()->user(), status: 'closed');
        $day = $service->createOrSyncDay('2026-10-06', collect(), auth()->user());
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])->call('openManualTeacherModal')
            ->assertSee('Manual Active')->assertDontSee('Hidden Inactive')
            ->set('manual_teacher_id', (string) $inactive->id)->call('addManualTeacher')->assertHasErrors('manual_teacher_id')
            ->set('manual_teacher_id', (string) $teacher->id)->call('addManualTeacher')->assertHasNoErrors();
        $this->assertTrue($future->records()->where('teacher_id', $teacher->id)->exists());
        $createdLater = $service->createOrSyncDay('2026-10-12', collect(), auth()->user(), status: 'closed');
        $this->assertTrue($createdLater->records()->where('teacher_id', $teacher->id)->exists());
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])->call('removeTeacher', $teacher->id)->assertHasNoErrors();
        $this->assertTrue($past->records()->where('teacher_id', $teacher->id)->exists());
        $this->assertFalse($day->records()->where('teacher_id', $teacher->id)->exists());
        $this->assertFalse($future->records()->where('teacher_id', $teacher->id)->exists());
        $newDay = $service->createOrSyncDay('2026-10-14', collect([$teacher]), auth()->user(), status: 'closed');
        $this->assertFalse($newDay->records()->where('teacher_id', $teacher->id)->exists());
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $day])->set('manual_teacher_id', (string) $teacher->id)->call('addManualTeacher')->assertHasNoErrors();
        $this->assertTrue($newDay->records()->where('teacher_id', $teacher->id)->exists());
        $this->assertSame('closed', $future->fresh()->status);
    }

    public function test_teacher_days_on_the_same_date_remain_separate_by_course(): void
    {
        $first = Course::create(['name' => 'First', 'is_active' => true]);
        $second = Course::create(['name' => 'Second', 'is_active' => true]);
        $service = app(TeacherAttendanceDayService::class);
        $a = $service->createOrSyncDay('2026-10-06', collect(), auth()->user(), courseId: $first->id);
        $b = $service->createOrSyncDay('2026-10-06', collect(), auth()->user(), courseId: $second->id);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($first->id, $a->fresh()->course_id);
        Volt::test('attendance.index')->assertViewHas('days', fn ($days) => $days->total() === 2)->assertSee('First')->assertSee('Second');
    }

    public function test_shared_day_controls_open_close_and_delete_student_and_teacher_attendance_together(): void
    {
        [$course] = $this->scheduledCourse('Shared controls');
        $present = AttendanceStatus::where('code', 'present')->firstOrFail();

        Volt::test('attendance.index')->call('openCreateModal')
            ->set('course_id', (string) $course->id)
            ->set('attendance_date', '2026-10-06')
            ->set('default_attendance_status_id', (string) $present->id)
            ->call('saveDay')
            ->assertHasNoErrors();

        $studentDay = StudentAttendanceDay::where('course_id', $course->id)->firstOrFail();
        $teacherDay = TeacherAttendanceDay::where('course_id', $course->id)->firstOrFail();
        Volt::test('student-attendance.show', ['studentAttendanceDay' => $studentDay, 'unified' => true])
            ->assertSee('$parent.toggleDayStatus', false)
            ->assertSee('$parent.deleteDay', false);
        Volt::test('teachers.attendance-show', ['teacherAttendanceDay' => $teacherDay, 'unified' => true])
            ->assertSee('$parent.toggleDayStatus', false)
            ->assertSee('$parent.deleteDay', false);
        $component = Volt::test('attendance.show', ['type' => 'students', 'day' => $studentDay->id]);

        $component->call('toggleDayStatus')->assertHasNoErrors();
        $this->assertSame('closed', $studentDay->fresh()->status);
        $this->assertSame('closed', $studentDay->groupAttendanceDays()->firstOrFail()->status);
        $this->assertSame('closed', $teacherDay->fresh()->status);

        $component->call('switchTab', 'teachers')->call('toggleDayStatus')->assertHasNoErrors();
        $this->assertSame('open', $studentDay->fresh()->status);
        $this->assertSame('open', $studentDay->groupAttendanceDays()->firstOrFail()->status);
        $this->assertSame('open', $teacherDay->fresh()->status);

        $component->call('deleteDay')->assertHasNoErrors();
        $this->assertDatabaseMissing('student_attendance_days', ['id' => $studentDay->id]);
        $this->assertDatabaseMissing('teacher_attendance_days', ['id' => $teacherDay->id]);
    }

    public function test_starting_a_missing_side_repairs_the_shared_attendance_day_in_one_action(): void
    {
        [$course, $teacher, $group] = $this->scheduledCourse('Repair pair');
        $studentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-06',
            collect([$group]),
            auth()->user(),
            courseId: $course->id,
        );

        Volt::test('attendance.show', ['type' => 'students', 'day' => $studentDay->id])
            ->call('switchTab', 'teachers')
            ->call('startAttendance')
            ->assertHasNoErrors();

        $this->assertSame(1, StudentAttendanceDay::where('course_id', $course->id)->count());
        $teacherDay = TeacherAttendanceDay::where('course_id', $course->id)->firstOrFail();
        $this->assertSame($studentDay->status, $teacherDay->status);
        $this->assertTrue($teacherDay->records()->where('teacher_id', $teacher->id)->exists());
    }

    public function test_teacher_only_user_can_use_the_combined_list_but_cannot_switch_to_students(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.teacher.view');
        $this->actingAs($user);
        Volt::test('attendance.index')->assertSet('exportType', 'teachers')
            ->call('switchExportType', 'students')->assertForbidden();
        $this->get(route('attendance.index'))->assertOk();
    }

    private function scheduledCourse(string $name): array
    {
        $course = Course::create(['name' => $name, 'is_active' => true]);
        $teacher = Teacher::create([
            'phone' => fake()->unique()->numerify('0997#######'),
            'first_name' => 'Shared',
            'last_name' => 'Teacher',
            'status' => 'active',
        ]);
        $group = Group::create([
            'academic_year_id' => AcademicYear::where('is_current', true)->value('id'),
            'name' => $name.' group',
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'is_active' => true,
        ]);
        $group->schedules()->create([
            'day_of_week' => 2,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'is_active' => true,
        ]);

        return [$course, $teacher, $group];
    }
}

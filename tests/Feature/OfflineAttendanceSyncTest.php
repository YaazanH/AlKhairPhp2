<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAttendanceRecord;
use App\Models\User;
use App\Services\StudentAttendanceDayService;
use App\Services\TeacherAttendanceDayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineAttendanceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_student_and_teacher_attendance_changes_can_sync_together(): void
    {
        $context = $this->attendanceContext();

        $this->actingAs($context['manager'])
            ->postJson(route('attendance.offline-sync'), [
                'operations' => [
                    [
                        'client_operation_id' => 'student-operation-1',
                        'day_id' => $context['groupDay']->id,
                        'entity_id' => $context['enrollment']->id,
                        'status_id' => $context['absent']->id,
                        'type' => 'student',
                    ],
                    [
                        'client_operation_id' => 'teacher-operation-1',
                        'day_id' => $context['teacherDay']->id,
                        'entity_id' => $context['teacher']->id,
                        'status_id' => $context['absent']->id,
                        'type' => 'teacher',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.1.status', 'synced');

        $this->assertDatabaseHas('student_attendance_records', [
            'group_attendance_day_id' => $context['groupDay']->id,
            'enrollment_id' => $context['enrollment']->id,
            'attendance_status_id' => $context['absent']->id,
        ]);
        $this->assertDatabaseHas('teacher_attendance_records', [
            'teacher_attendance_day_id' => $context['teacherDay']->id,
            'teacher_id' => $context['teacher']->id,
            'attendance_status_id' => $context['absent']->id,
        ]);
    }

    public function test_a_queued_change_becomes_a_conflict_when_the_day_was_closed(): void
    {
        $context = $this->attendanceContext();
        $context['studentDay']->update(['status' => 'closed']);

        $this->actingAs($context['manager'])
            ->postJson(route('attendance.offline-sync'), [
                'operations' => [[
                    'client_operation_id' => 'closed-student-operation',
                    'day_id' => $context['groupDay']->id,
                    'entity_id' => $context['enrollment']->id,
                    'status_id' => $context['absent']->id,
                    'type' => 'student',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.client_operation_id', 'closed-student-operation')
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.message', __('workflow.offline_attendance.conflicts.closed_day'));

        $this->assertDatabaseMissing('student_attendance_records', [
            'group_attendance_day_id' => $context['groupDay']->id,
            'enrollment_id' => $context['enrollment']->id,
            'attendance_status_id' => $context['absent']->id,
        ]);
    }

    public function test_attendance_marking_pages_expose_the_offline_queue_hooks(): void
    {
        $context = $this->attendanceContext();

        $this->actingAs($context['manager'])
            ->get(route('student-attendance.mark', $context['groupDay']))
            ->assertOk()
            ->assertSee('data-offline-attendance-root', false)
            ->assertSee('data-offline-attendance-select', false)
            ->assertSee(route('attendance.offline-sync'), false);

        $this->get(route('teacher-attendance.show', $context['teacherDay']))
            ->assertOk()
            ->assertSee('data-offline-attendance-root', false)
            ->assertSee('data-offline-attendance-choice', false)
            ->assertSee('data-attendance-type="teacher"', false);
    }

    public function test_sync_status_is_a_single_dot_in_the_attendance_day_date_card(): void
    {
        $context = $this->attendanceContext();

        $studentPage = $this->actingAs($context['manager'])
            ->get(route('attendance.show', ['type' => 'students', 'day' => $context['studentDay']->id]))
            ->assertOk();

        $studentPage
            ->assertSee('data-student-attendance-day-date-metric', false)
            ->assertSee('attendance-day-date-label--compact', false)
            ->assertSee(__('workflow.student_attendance.days.table.headers.date'))
            ->assertSee('class="offline-attendance-dot"', false)
            ->assertSee('data-attendance-sync-state', false)
            ->assertDontSee('offline-attendance-state', false)
            ->assertDontSee('data-attendance-sync-text', false)
            ->assertDontSee('data-attendance-row-sync', false);

        $this->get(route('student-attendance.mark', $context['groupDay']))
            ->assertOk()
            ->assertDontSee('class="offline-attendance-dot"', false)
            ->assertDontSee('data-attendance-sync-state', false);

        $this->get(route('teacher-attendance.show', $context['teacherDay']))
            ->assertOk()
            ->assertSee('data-teacher-attendance-day-date-metric', false)
            ->assertSee('attendance-day-date-label--compact', false)
            ->assertSee(__('workflow.student_attendance.days.table.headers.date'))
            ->assertSee('class="offline-attendance-dot"', false)
            ->assertSee('data-attendance-sync-state', false)
            ->assertDontSee('offline-attendance-state', false)
            ->assertDontSee('data-attendance-sync-text', false)
            ->assertDontSee('data-attendance-row-sync', false);
    }

    /** @return array<string, mixed> */
    protected function attendanceContext(): array
    {
        $this->seed();

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $academicYear = AcademicYear::query()->create([
            'name' => 'Offline attendance year',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'academic_year_id' => $academicYear->id,
            'name' => 'Offline attendance course',
            'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Offline',
            'last_name' => 'Teacher',
            'phone' => '0999000199',
            'status' => 'active',
        ]);
        $group = Group::query()->create([
            'academic_year_id' => $academicYear->id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'name' => 'Offline group',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'first_name' => 'Offline',
            'last_name' => 'Student',
            'birth_date' => '2013-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-10-10',
            'status' => 'active',
        ]);
        $present = AttendanceStatus::query()->where('code', 'present')->firstOrFail();
        $absent = AttendanceStatus::query()
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'teacher', 'both'])
            ->where('is_present', false)
            ->firstOrFail();

        $studentDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
            '2026-10-10',
            collect([$group]),
            $manager,
            defaultAttendanceStatusId: $present->id,
        );
        $groupDay = $studentDay->groupAttendanceDays()->firstOrFail();
        $teacherDay = app(TeacherAttendanceDayService::class)->createOrSyncDay(
            '2026-10-10',
            collect([$teacher]),
            $manager,
            defaultAttendanceStatusId: $present->id,
            courseId: $course->id,
        );

        $this->assertInstanceOf(TeacherAttendanceRecord::class, $teacherDay->records()->first());

        return compact('absent', 'enrollment', 'groupDay', 'manager', 'present', 'studentDay', 'teacher', 'teacherDay');
    }
}

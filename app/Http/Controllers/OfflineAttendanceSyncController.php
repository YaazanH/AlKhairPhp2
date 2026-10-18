<?php

namespace App\Http\Controllers;

use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\GroupAttendanceDay;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Services\AccessScopeService;
use App\Services\Landlord\CurrentModuleAccess;
use App\Services\StudentAttendanceDayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OfflineAttendanceSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:200'],
            'operations.*.client_operation_id' => ['required', 'string', 'max:80'],
            'operations.*.day_id' => ['required', 'integer'],
            'operations.*.entity_id' => ['required', 'integer'],
            'operations.*.status_id' => ['required', 'integer'],
            'operations.*.type' => ['required', Rule::in(['student', 'teacher'])],
        ]);

        foreach (collect($validated['operations'])->pluck('type')->unique() as $type) {
            app(CurrentModuleAccess::class)->ensure($type === 'student' ? 'student_attendance' : 'teacher_attendance');
            if ($type === 'student') {
                app(CurrentModuleAccess::class)->ensure('classes');
            }
        }

        $results = collect($validated['operations'])
            ->map(fn (array $operation): array => $this->syncOperation($request, $operation))
            ->values()
            ->all();

        return response()->json(['results' => $results]);
    }

    protected function syncOperation(Request $request, array $operation): array
    {
        try {
            DB::transaction(function () use ($operation, $request): void {
                if ($operation['type'] === 'student') {
                    $this->syncStudentOperation($request, $operation);

                    return;
                }

                $this->syncTeacherOperation($request, $operation);
            });

            return [
                'client_operation_id' => $operation['client_operation_id'],
                'status' => 'synced',
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'client_operation_id' => $operation['client_operation_id'],
                'message' => $exception->getMessage(),
                'status' => 'conflict',
            ];
        }
    }

    protected function syncStudentOperation(Request $request, array $operation): void
    {
        abort_unless($request->user()?->can('attendance.student.take'), 403);

        $groupDay = GroupAttendanceDay::query()
            ->with(['group', 'studentAttendanceDay'])
            ->lockForUpdate()
            ->findOrFail($operation['day_id']);

        abort_unless(app(AccessScopeService::class)->canAccessGroupAttendanceDay($request->user(), $groupDay), 403);

        if (! $groupDay->studentAttendanceDay || $groupDay->studentAttendanceDay->status === 'closed' || $groupDay->studentAttendanceDay->course_finished_at) {
            throw new InvalidArgumentException(__('workflow.offline_attendance.conflicts.closed_day'));
        }

        $enrollment = Enrollment::query()
            ->whereKey($operation['entity_id'])
            ->where('group_id', $groupDay->group_id)
            ->where('status', 'active')
            ->first();

        if (! $enrollment) {
            throw new InvalidArgumentException(__('workflow.offline_attendance.conflicts.roster_changed'));
        }

        abort_unless(app(AccessScopeService::class)->canAccessEnrollment($request->user(), $enrollment), 403);

        $status = AttendanceStatus::query()
            ->whereKey($operation['status_id'])
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->first();

        if (! $status) {
            throw new InvalidArgumentException(__('workflow.offline_attendance.conflicts.status_changed'));
        }

        if ($groupDay->records()->where('enrollment_id', $enrollment->id)->value('attendance_status_id') === $status->id) {
            return;
        }

        app(StudentAttendanceDayService::class)->recordEnrollmentStatus(
            $groupDay->studentAttendanceDay,
            $enrollment,
            $status,
        );
    }

    protected function syncTeacherOperation(Request $request, array $operation): void
    {
        abort_unless($request->user()?->can('attendance.teacher.take'), 403);

        $day = TeacherAttendanceDay::query()->lockForUpdate()->findOrFail($operation['day_id']);
        abort_unless(app(AccessScopeService::class)->canAccessTeacherAttendanceDay($request->user(), $day), 403);

        if ($day->status === 'closed' || $day->course_finished_at) {
            throw new InvalidArgumentException(__('workflow.offline_attendance.conflicts.closed_day'));
        }

        $record = TeacherAttendanceRecord::query()
            ->where('teacher_attendance_day_id', $day->id)
            ->where('teacher_id', $operation['entity_id'])
            ->lockForUpdate()
            ->first();

        if (! $record || $record->course_finished_at) {
            throw new InvalidArgumentException(__('workflow.offline_attendance.conflicts.roster_changed'));
        }

        $teacher = Teacher::query()->findOrFail($operation['entity_id']);
        abort_unless(app(AccessScopeService::class)->canAccessTeacher($request->user(), $teacher), 403);

        $status = AttendanceStatus::query()
            ->whereKey($operation['status_id'])
            ->where('is_active', true)
            ->whereIn('scope', ['teacher', 'both'])
            ->first();

        if (! $status) {
            throw new InvalidArgumentException(__('workflow.offline_attendance.conflicts.status_changed'));
        }

        if ($record->attendance_status_id === $status->id) {
            return;
        }

        $record->update(['attendance_status_id' => $status->id]);
    }
}

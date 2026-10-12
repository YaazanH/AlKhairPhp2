<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use App\Models\QuranTest;
use App\Models\StudentAttendanceDay;
use App\Models\TeacherAttendanceDay;
use App\Services\AccessScopeService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationalRecordsController extends Controller
{
    public function studentAttendanceDays(Request $request)
    {
        $this->authorizePermission($request, 'attendance.student.view');
        $validated = $this->validatedListFilters($request, [
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
        ]);
        $scopes = app(AccessScopeService::class);
        $groupIds = $scopes->isUnrestricted($request->user()) ? null : $scopes->accessibleGroupIds($request->user());

        return $scopes->scopeStudentAttendanceDays(StudentAttendanceDay::query(), $request->user())
            ->with('course')
            ->withCount([
                'groupAttendanceDays as groups_count' => fn (Builder $query) => $query->when($groupIds !== null, fn (Builder $builder) => $builder->whereIn('group_id', $groupIds)),
                'groupAttendanceDays as records_count' => fn (Builder $query) => $query
                    ->when($groupIds !== null, fn (Builder $builder) => $builder->whereIn('group_id', $groupIds))
                    ->join('student_attendance_records', 'student_attendance_records.group_attendance_day_id', '=', 'group_attendance_days.id'),
            ])
            ->when($validated['course_id'] ?? null, fn (Builder $query, int $courseId) => $query->where('course_id', $courseId))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $groupId) => $query->whereHas('groupAttendanceDays', fn (Builder $groups) => $groups->where('group_id', $groupId)))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('attendance_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('attendance_date', '<=', $date))
            ->latest('attendance_date')->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (StudentAttendanceDay $day) => $this->studentAttendanceDaySummary($day));
    }

    public function studentAttendanceDay(Request $request, StudentAttendanceDay $studentAttendanceDay)
    {
        $this->authorizePermission($request, 'attendance.student.view');
        $this->authorizeStudentAttendanceDayScope($request, $studentAttendanceDay);

        return response()->json(['data' => $this->studentAttendanceDayPayload($studentAttendanceDay, $request)]);
    }

    public function updateStudentAttendanceDayStatus(Request $request, StudentAttendanceDay $studentAttendanceDay)
    {
        $this->authorizePermission($request, 'attendance.student.toggle-day-status');
        $this->authorizeStudentAttendanceDayScope($request, $studentAttendanceDay);
        $validated = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);

        if ($studentAttendanceDay->course_finished_at) {
            throw ValidationException::withMessages(['status' => __('crud.courses.errors.finished_read_only')]);
        }

        $studentAttendanceDay = app(StudentAttendanceDayService::class)->setDayStatus($studentAttendanceDay, $validated['status']);

        return response()->json(['data' => $this->studentAttendanceDayPayload($studentAttendanceDay, $request)]);
    }

    public function teacherAttendanceDays(Request $request)
    {
        $this->authorizePermission($request, 'attendance.teacher.view');
        $validated = $this->validatedListFilters($request, [
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
        ]);
        $scopes = app(AccessScopeService::class);
        $teacherIds = $scopes->isUnrestricted($request->user()) ? null : $scopes->accessibleTeacherIds($request->user());

        return $scopes->scopeTeacherAttendanceDays(TeacherAttendanceDay::query(), $request->user())
            ->with('course')
            ->withCount(['records' => fn (Builder $query) => $query->when($teacherIds !== null, fn (Builder $builder) => $builder->whereIn('teacher_id', $teacherIds))])
            ->when($validated['course_id'] ?? null, fn (Builder $query, int $courseId) => $query->where('course_id', $courseId))
            ->when($validated['teacher_id'] ?? null, fn (Builder $query, int $teacherId) => $query->whereHas('records', fn (Builder $records) => $records->where('teacher_id', $teacherId)))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('attendance_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('attendance_date', '<=', $date))
            ->latest('attendance_date')->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (TeacherAttendanceDay $day) => [
                'attendance_date' => $day->attendance_date?->format('Y-m-d'),
                'course' => $day->course ? ['id' => $day->course->id, 'name' => $day->course->name] : null,
                'id' => $day->id,
                'notes' => $day->notes,
                'records_count' => $day->records_count,
                'status' => $day->status,
            ]);
    }

    public function teacherAttendanceDay(Request $request, TeacherAttendanceDay $teacherAttendanceDay)
    {
        $this->authorizePermission($request, 'attendance.teacher.view');
        abort_unless(app(AccessScopeService::class)->canAccessTeacherAttendanceDay($request->user(), $teacherAttendanceDay), 403);
        $scopes = app(AccessScopeService::class);
        $teacherIds = $scopes->isUnrestricted($request->user()) ? null : $scopes->accessibleTeacherIds($request->user());
        $teacherAttendanceDay->load([
            'course',
            'records' => fn ($query) => $query->when($teacherIds !== null, fn ($builder) => $builder->whereIn('teacher_id', $teacherIds))->with(['status', 'teacher']),
        ]);

        return response()->json(['data' => [
            'attendance_date' => $teacherAttendanceDay->attendance_date?->format('Y-m-d'),
            'course' => $teacherAttendanceDay->course ? ['id' => $teacherAttendanceDay->course->id, 'name' => $teacherAttendanceDay->course->name] : null,
            'id' => $teacherAttendanceDay->id,
            'notes' => $teacherAttendanceDay->notes,
            'records' => $teacherAttendanceDay->records->map(fn ($record) => [
                'attendance_status' => $record->status ? ['code' => $record->status->code, 'id' => $record->status->id, 'name' => $record->status->name] : null,
                'id' => $record->id,
                'notes' => $record->notes,
                'teacher' => $record->teacher ? ['id' => $record->teacher->id, 'name' => trim($record->teacher->first_name.' '.$record->teacher->last_name)] : null,
            ])->values(),
            'status' => $teacherAttendanceDay->status,
        ]]);
    }

    public function memorization(Request $request)
    {
        $this->authorizePermission($request, 'memorization.view');

        return $this->memorizationResponse($request);
    }

    public function enrollmentMemorization(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'memorization.view');
        $this->authorizeEnrollmentScope($request, $enrollment);

        return $this->memorizationResponse($request, $enrollment);
    }

    public function quranTests(Request $request)
    {
        $this->authorizeAnyPermission($request, ['quran-tests.view', 'quran-awqaf-tests.view']);

        return $this->quranTestsResponse($request);
    }

    public function enrollmentQuranTests(Request $request, Enrollment $enrollment)
    {
        $this->authorizeAnyPermission($request, ['quran-tests.view', 'quran-awqaf-tests.view']);
        $this->authorizeEnrollmentScope($request, $enrollment);

        return $this->quranTestsResponse($request, $enrollment);
    }

    public function points(Request $request)
    {
        $this->authorizePermission($request, 'points.view');

        return $this->pointsResponse($request);
    }

    public function enrollmentPoints(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'points.view');
        $this->authorizeEnrollmentScope($request, $enrollment);

        return $this->pointsResponse($request, $enrollment);
    }

    public function assessment(Request $request, Assessment $assessment)
    {
        $this->authorizePermission($request, 'assessments.view');
        abort_unless(app(AccessScopeService::class)->canAccessAssessment($request->user(), $assessment), 403);
        $assessment->load(['group.course', 'groups.course', 'type']);
        $results = app(AccessScopeService::class)->scopeAssessmentResults(
            AssessmentResult::query()->where('assessment_id', $assessment->id),
            $request->user(),
        )->with(['enrollment.student', 'teacher'])->orderBy('enrollment_id')->get();

        return response()->json(['data' => [
            'description' => $assessment->description,
            'due_at' => $assessment->due_at?->toIso8601String(),
            'group_scope' => $assessment->group_scope,
            'groups' => $assessment->groups->whenEmpty(fn ($groups) => $assessment->group ? collect([$assessment->group]) : $groups)->map(fn ($group) => [
                'course_name' => $group->course?->name,
                'id' => $group->id,
                'name' => $group->name,
            ])->values(),
            'id' => $assessment->id,
            'is_active' => $assessment->is_active,
            'pass_mark' => $assessment->pass_mark !== null ? (float) $assessment->pass_mark : null,
            'results' => $results->map(fn (AssessmentResult $result) => [
                'attempt_no' => $result->attempt_no,
                'enrollment_id' => $result->enrollment_id,
                'id' => $result->id,
                'notes' => $result->notes,
                'score' => $result->score !== null ? (float) $result->score : null,
                'status' => $result->status,
                'student_id' => $result->student_id,
                'student_name' => $result->enrollment?->student?->full_name,
                'teacher_name' => $result->teacher ? trim($result->teacher->first_name.' '.$result->teacher->last_name) : null,
            ])->values(),
            'scheduled_at' => $assessment->scheduled_at?->toIso8601String(),
            'title' => $assessment->title,
            'total_mark' => $assessment->total_mark !== null ? (float) $assessment->total_mark : null,
            'type' => $assessment->type ? ['code' => $assessment->type->code, 'id' => $assessment->type->id, 'name' => $assessment->type->name] : null,
        ]]);
    }

    protected function memorizationResponse(Request $request, ?Enrollment $enrollment = null)
    {
        $validated = $this->validatedListFilters($request, [
            'entry_type' => ['nullable', Rule::in(['new', 'review', 'correction'])],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        return app(AccessScopeService::class)->scopeMemorizationSessions(MemorizationSession::query(), $request->user())
            ->with(['enrollment.group.course', 'student', 'teacher'])
            ->when($enrollment, fn (Builder $query) => $query->where('enrollment_id', $enrollment->id))
            ->when($validated['entry_type'] ?? null, fn (Builder $query, string $type) => $query->where('entry_type', $type))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $groupId) => $query->whereHas('enrollment', fn (Builder $enrollments) => $enrollments->where('group_id', $groupId)))
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $studentId) => $query->where('student_id', $studentId))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('recorded_on', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('recorded_on', '<=', $date))
            ->latest('recorded_on')->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (MemorizationSession $session) => [
                'course_name' => $session->enrollment?->group?->course?->name,
                'enrollment_id' => $session->enrollment_id,
                'entry_type' => $session->entry_type,
                'from_page' => $session->from_page,
                'group_name' => $session->enrollment?->group?->name,
                'id' => $session->id,
                'notes' => $session->notes,
                'pages_count' => $session->pages_count,
                'recorded_on' => $session->recorded_on?->format('Y-m-d'),
                'student' => $session->student ? ['id' => $session->student->id, 'name' => $session->student->full_name] : null,
                'teacher' => $session->teacher ? ['id' => $session->teacher->id, 'name' => trim($session->teacher->first_name.' '.$session->teacher->last_name)] : null,
                'to_page' => $session->to_page,
            ]);
    }

    protected function quranTestsResponse(Request $request, ?Enrollment $enrollment = null)
    {
        $validated = $this->validatedListFilters($request, [
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'juz_id' => ['nullable', 'integer', 'exists:quran_juzs,id'],
            'status' => ['nullable', Rule::in(['passed', 'failed', 'cancelled'])],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        return app(AccessScopeService::class)->scopeQuranTests(QuranTest::query(), $request->user())
            ->with(['enrollment.group.course', 'juz', 'student', 'teacher', 'type'])
            ->when($enrollment, fn (Builder $query) => $query->where('enrollment_id', $enrollment->id))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $groupId) => $query->whereHas('enrollment', fn (Builder $enrollments) => $enrollments->where('group_id', $groupId)))
            ->when($validated['juz_id'] ?? null, fn (Builder $query, int $juzId) => $query->where('juz_id', $juzId))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $studentId) => $query->where('student_id', $studentId))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('tested_on', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('tested_on', '<=', $date))
            ->latest('tested_on')->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (QuranTest $test) => [
                'attempt_no' => $test->attempt_no,
                'course_name' => $test->enrollment?->group?->course?->name,
                'enrollment_id' => $test->enrollment_id,
                'group_name' => $test->enrollment?->group?->name,
                'id' => $test->id,
                'juz' => $test->juz ? ['id' => $test->juz->id, 'juz_number' => $test->juz->juz_number] : null,
                'notes' => $test->notes,
                'score' => $test->score !== null ? (float) $test->score : null,
                'status' => $test->status,
                'student' => $test->student ? ['id' => $test->student->id, 'name' => $test->student->full_name] : null,
                'teacher' => $test->teacher ? ['id' => $test->teacher->id, 'name' => trim($test->teacher->first_name.' '.$test->teacher->last_name)] : null,
                'tested_on' => $test->tested_on?->format('Y-m-d'),
                'type' => $test->type ? ['code' => $test->type->code, 'id' => $test->type->id, 'name' => $test->type->name] : null,
            ]);
    }

    protected function pointsResponse(Request $request, ?Enrollment $enrollment = null)
    {
        $validated = $this->validatedListFilters($request, [
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'point_type_id' => ['nullable', 'integer', 'exists:point_types,id'],
            'state' => ['nullable', Rule::in(['active', 'voided', 'inactive_source'])],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        return app(AccessScopeService::class)->scopePointTransactions(PointTransaction::query(), $request->user())
            ->with(['enrollment.group.course', 'pointType', 'student'])
            ->when($enrollment, fn (Builder $query) => $query->where('enrollment_id', $enrollment->id))
            ->when($validated['group_id'] ?? null, fn (Builder $query, int $groupId) => $query->whereHas('enrollment', fn (Builder $enrollments) => $enrollments->where('group_id', $groupId)))
            ->when($validated['point_type_id'] ?? null, fn (Builder $query, int $typeId) => $query->where('point_type_id', $typeId))
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $studentId) => $query->where('student_id', $studentId))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('entered_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('entered_at', '<=', $date))
            ->when(($validated['state'] ?? null) === 'active', fn (Builder $query) => $query->effectiveActive())
            ->when(($validated['state'] ?? null) === 'voided', fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->when(($validated['state'] ?? null) === 'inactive_source', fn (Builder $query) => $query->inactiveSource())
            ->latest('entered_at')->latest('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (PointTransaction $transaction) => [
                'course_name' => $transaction->enrollment?->group?->course?->name,
                'effective_state' => $transaction->effectiveState(),
                'entered_at' => $transaction->entered_at?->toIso8601String(),
                'enrollment_id' => $transaction->enrollment_id,
                'group_name' => $transaction->enrollment?->group?->name,
                'id' => $transaction->id,
                'notes' => $transaction->notes,
                'point_type' => $transaction->pointType ? ['code' => $transaction->pointType->code, 'id' => $transaction->pointType->id, 'name' => $transaction->pointType->name] : null,
                'points' => $transaction->points,
                'source_id' => $transaction->source_id,
                'source_type' => $transaction->source_type,
                'student' => $transaction->student ? ['id' => $transaction->student->id, 'name' => $transaction->student->full_name] : null,
                'void_reason' => $transaction->void_reason,
                'voided_at' => $transaction->voided_at?->toIso8601String(),
            ]);
    }

    protected function studentAttendanceDayPayload(StudentAttendanceDay $day, Request $request): array
    {
        $scopes = app(AccessScopeService::class);
        $groupIds = $scopes->isUnrestricted($request->user()) ? null : $scopes->accessibleGroupIds($request->user());
        $day->load([
            'course',
            'groupAttendanceDays' => fn ($query) => $query
                ->when($groupIds !== null, fn ($builder) => $builder->whereIn('group_id', $groupIds))
                ->with(['group', 'records.enrollment.student', 'records.status']),
        ]);

        return [
            'attendance_date' => $day->attendance_date?->format('Y-m-d'),
            'course' => $day->course ? ['id' => $day->course->id, 'name' => $day->course->name] : null,
            'course_finished_at' => $day->course_finished_at?->toIso8601String(),
            'groups' => $day->groupAttendanceDays->map(fn ($groupDay) => [
                'group' => $groupDay->group ? ['id' => $groupDay->group->id, 'name' => $groupDay->group->name] : null,
                'id' => $groupDay->id,
                'notes' => $groupDay->notes,
                'records' => $groupDay->records->map(fn ($record) => [
                    'attendance_status' => $record->status ? ['code' => $record->status->code, 'id' => $record->status->id, 'name' => $record->status->name] : null,
                    'enrollment_id' => $record->enrollment_id,
                    'id' => $record->id,
                    'notes' => $record->notes,
                    'student' => $record->enrollment?->student ? ['id' => $record->enrollment->student->id, 'name' => $record->enrollment->student->full_name] : null,
                ])->values(),
                'status' => $groupDay->status,
            ])->values(),
            'id' => $day->id,
            'notes' => $day->notes,
            'status' => $day->status,
        ];
    }

    protected function studentAttendanceDaySummary(StudentAttendanceDay $day): array
    {
        return [
            'attendance_date' => $day->attendance_date?->format('Y-m-d'),
            'course' => $day->course ? ['id' => $day->course->id, 'name' => $day->course->name] : null,
            'course_finished_at' => $day->course_finished_at?->toIso8601String(),
            'groups_count' => $day->groups_count,
            'id' => $day->id,
            'notes' => $day->notes,
            'records_count' => $day->records_count,
            'status' => $day->status,
        ];
    }

    protected function validatedListFilters(Request $request, array $rules = []): array
    {
        return $request->validate(array_merge([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $rules));
    }

    protected function authorizeStudentAttendanceDayScope(Request $request, StudentAttendanceDay $day): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessStudentAttendanceDay($request->user(), $day), 403);
    }

    protected function authorizeEnrollmentScope(Request $request, Enrollment $enrollment): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessEnrollment($request->user(), $enrollment), 403);
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function authorizeAnyPermission(Request $request, array $permissions): void
    {
        abort_unless(collect($permissions)->contains(fn (string $permission) => $request->user()?->can($permission)), 403);
    }
}

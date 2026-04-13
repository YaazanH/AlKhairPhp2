<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentPageAchievement;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use App\Services\AssessmentService;
use App\Services\PointLedgerService;
use App\Services\QuranProgressionService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperationalWriteController extends Controller
{
    /**
     * Upsert one group attendance day and its student attendance records.
     */
    public function storeGroupAttendance(Request $request, Group $group)
    {
        $this->authorizePermission($request, 'attendance.student.take');
        $this->authorizeTeacherGroupScope($request, $group);

        $validated = $request->validate([
            'attendance_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.attendance_status_id' => ['required', 'integer', Rule::exists('attendance_statuses', 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('scope', ['student', 'both']))],
            'records.*.enrollment_id' => ['required', 'integer', Rule::exists('enrollments', 'id')->whereNull('deleted_at')],
            'records.*.notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $enrollmentIds = collect($validated['records'])->pluck('enrollment_id')->unique()->values();
        $enrollments = Enrollment::query()
            ->with('student')
            ->whereIn('id', $enrollmentIds->all())
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        if ($enrollments->count() !== $enrollmentIds->count()) {
            return response()->json([
                'message' => 'One or more enrollment records do not belong to this active group.',
            ], 422);
        }

        $attendanceStatuses = AttendanceStatus::query()
            ->whereIn('id', collect($validated['records'])->pluck('attendance_status_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $day = DB::transaction(function () use ($request, $group, $validated, $enrollments, $attendanceStatuses) {
            $notes = blank($validated['notes'] ?? null) ? null : $validated['notes'];
            $status = $validated['status'] ?? 'completed';
            $studentAttendanceDay = app(StudentAttendanceDayService::class)->createOrSyncDay(
                $validated['attendance_date'],
                collect([$group]),
                $request->user(),
                $notes,
                $status,
            );
            $day = GroupAttendanceDay::query()
                ->where('student_attendance_day_id', $studentAttendanceDay->id)
                ->where('group_id', $group->id)
                ->firstOrFail();

            $day->update([
                'created_by' => $day->created_by ?? $request->user()->id,
                'notes' => $notes,
                'status' => $status,
            ]);

            $ledger = app(PointLedgerService::class);

            foreach ($validated['records'] as $recordInput) {
                $record = StudentAttendanceRecord::query()->updateOrCreate(
                    [
                        'group_attendance_day_id' => $day->id,
                        'enrollment_id' => $recordInput['enrollment_id'],
                    ],
                    [
                        'attendance_status_id' => $recordInput['attendance_status_id'],
                        'notes' => blank($recordInput['notes'] ?? null) ? null : $recordInput['notes'],
                    ],
                );

                $enrollment = $enrollments[$record->enrollment_id];
                $attendanceStatus = $attendanceStatuses[$record->attendance_status_id];

                $ledger->voidSourceTransactions('student_attendance_record', $record->id, 'Superseded by an integration API attendance update.');

                $ledger->recordAttendanceStatusPoints(
                    $enrollment,
                    'student_attendance_record',
                    $record->id,
                    $attendanceStatus,
                    'Automatic attendance points from the integration API.',
                );

                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
            }

            app(StudentAttendanceDayService::class)->syncAggregateStatus($studentAttendanceDay);

            return $day->fresh(['records.status', 'records.enrollment.student']);
        });

        return response()->json([
            'attendance_date' => $day->attendance_date?->format('Y-m-d'),
            'group_id' => $day->group_id,
            'id' => $day->id,
            'records' => $day->records->map(fn (StudentAttendanceRecord $record) => [
                'attendance_status_code' => $record->status?->code,
                'attendance_status_id' => $record->attendance_status_id,
                'attendance_status_name' => $record->status?->name,
                'enrollment_id' => $record->enrollment_id,
                'notes' => $record->notes,
                'student_id' => $record->enrollment?->student_id,
                'student_name' => trim(($record->enrollment?->student?->first_name ?? '').' '.($record->enrollment?->student?->last_name ?? '')),
            ])->values()->all(),
            'status' => $day->status,
        ]);
    }

    /**
     * Upsert one teacher attendance day and its teacher attendance records.
     */
    public function storeTeacherAttendance(Request $request)
    {
        $this->authorizePermission($request, 'attendance.teacher.take');

        $validated = $request->validate([
            'attendance_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.attendance_status_id' => ['required', 'integer', Rule::exists('attendance_statuses', 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('scope', ['teacher', 'both']))],
            'records.*.notes' => ['nullable', 'string'],
            'records.*.teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        foreach (collect($validated['records'])->pluck('teacher_id')->filter()->unique() as $teacherId) {
            $teacher = Teacher::query()->findOrFail($teacherId);
            abort_unless(app(AccessScopeService::class)->canAccessTeacher($request->user(), $teacher), 403);
        }

        $day = DB::transaction(function () use ($request, $validated) {
            $day = TeacherAttendanceDay::query()->updateOrCreate(
                ['attendance_date' => $validated['attendance_date']],
                [
                    'created_by' => $request->user()->id,
                    'notes' => blank($validated['notes'] ?? null) ? null : $validated['notes'],
                    'status' => $validated['status'] ?? 'completed',
                ],
            );

            foreach ($validated['records'] as $recordInput) {
                TeacherAttendanceRecord::query()->updateOrCreate(
                    [
                        'teacher_attendance_day_id' => $day->id,
                        'teacher_id' => $recordInput['teacher_id'],
                    ],
                    [
                        'attendance_status_id' => $recordInput['attendance_status_id'],
                        'notes' => blank($recordInput['notes'] ?? null) ? null : $recordInput['notes'],
                    ],
                );
            }

            return $day->fresh(['records.status', 'records.teacher']);
        });

        return response()->json([
            'attendance_date' => $day->attendance_date?->format('Y-m-d'),
            'id' => $day->id,
            'records' => $day->records->map(fn (TeacherAttendanceRecord $record) => [
                'attendance_status_code' => $record->status?->code,
                'attendance_status_id' => $record->attendance_status_id,
                'attendance_status_name' => $record->status?->name,
                'notes' => $record->notes,
                'teacher_id' => $record->teacher_id,
                'teacher_name' => trim(($record->teacher?->first_name ?? '').' '.($record->teacher?->last_name ?? '')),
            ])->values()->all(),
            'status' => $day->status,
        ]);
    }

    /**
     * Record a memorization session for one enrollment.
     */
    public function storeMemorization(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'memorization.record');
        $this->authorizeTeacherEnrollmentScope($request, $enrollment);

        $validated = $request->validate([
            'entry_type' => ['required', Rule::in(['new', 'review'])],
            'from_page' => ['required', 'integer', 'min:1', 'max:604'],
            'notes' => ['nullable', 'string'],
            'recorded_on' => ['required', 'date'],
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'to_page' => ['required', 'integer', 'min:1', 'max:604', 'gte:from_page'],
        ]);

        $this->authorizeTeacherPayloadScope($request, (int) $validated['teacher_id']);

        $pages = range((int) $validated['from_page'], (int) $validated['to_page']);
        $existingPages = StudentPageAchievement::query()
            ->where('student_id', $enrollment->student_id)
            ->whereIn('page_no', $pages)
            ->pluck('page_no')
            ->all();

        if ($validated['entry_type'] !== 'review' && $existingPages !== [] && ! $request->user()?->can('memorization.override-duplicate-page')) {
            return response()->json([
                'duplicates' => array_values($existingPages),
                'message' => 'One or more pages were already achieved by this student.',
            ], 422);
        }

        $session = DB::transaction(function () use ($validated, $enrollment, $pages, $existingPages) {
            $session = MemorizationSession::query()->create([
                'enrollment_id' => $enrollment->id,
                'entry_type' => $validated['entry_type'],
                'from_page' => $validated['from_page'],
                'notes' => blank($validated['notes'] ?? null) ? null : $validated['notes'],
                'pages_count' => count($pages),
                'recorded_on' => $validated['recorded_on'],
                'student_id' => $enrollment->student_id,
                'teacher_id' => $validated['teacher_id'],
                'to_page' => $validated['to_page'],
            ]);

            $session->pages()->createMany(collect($pages)->map(fn (int $pageNo) => [
                'page_no' => $pageNo,
            ])->all());

            $newPages = array_values(array_diff($pages, $existingPages));

            if ($newPages !== []) {
                StudentPageAchievement::query()->insert(collect($newPages)->map(fn (int $pageNo) => [
                    'first_enrollment_id' => $enrollment->id,
                    'first_recorded_on' => $validated['recorded_on'],
                    'first_session_id' => $session->id,
                    'page_no' => $pageNo,
                    'student_id' => $enrollment->student_id,
                ])->all());

                $ledger = app(PointLedgerService::class);
                $policy = $ledger->resolvePolicy('memorization', 'page', $enrollment->student?->grade_level_id);

                if ($policy) {
                    $ledger->recordAutomaticPoints(
                        $enrollment,
                        'memorization_session',
                        $session->id,
                        $policy->pointType,
                        $policy,
                        $policy->points * count($newPages),
                        'Automatic memorization points from the integration API.',
                    );
                }

                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
            }

            return $session->fresh(['teacher']);
        });

        return response()->json([
            'entry_type' => $session->entry_type,
            'from_page' => $session->from_page,
            'id' => $session->id,
            'new_pages_count' => StudentPageAchievement::query()
                ->where('student_id', $enrollment->student_id)
                ->where('first_session_id', $session->id)
                ->count(),
            'notes' => $session->notes,
            'pages_count' => $session->pages_count,
            'recorded_on' => $session->recorded_on?->format('Y-m-d'),
            'teacher_id' => $session->teacher_id,
            'teacher_name' => trim(($session->teacher?->first_name ?? '').' '.($session->teacher?->last_name ?? '')),
            'to_page' => $session->to_page,
        ], 201);
    }

    /**
     * Record one Quran test for an enrollment.
     */
    public function storeQuranTest(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'quran-tests.record');
        $this->authorizeTeacherEnrollmentScope($request, $enrollment);

        $validated = $request->validate([
            'juz_id' => ['required', 'integer', Rule::exists('quran_juzs', 'id')],
            'notes' => ['nullable', 'string'],
            'quran_test_type_id' => ['required', 'integer', Rule::exists('quran_test_types', 'id')->where('is_active', true)],
            'score' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['required', Rule::in(['passed', 'failed', 'cancelled'])],
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->whereNull('deleted_at')],
            'tested_on' => ['required', 'date'],
        ]);

        $this->authorizeTeacherPayloadScope($request, (int) $validated['teacher_id']);

        $testType = QuranTestType::query()->findOrFail($validated['quran_test_type_id']);
        $progression = app(QuranProgressionService::class)->validate($enrollment, (int) $validated['juz_id'], $testType);
        $score = $validated['score'] ?? null;

        if ($progression && ! $request->user()?->can('quran-tests.override-progression')) {
            return response()->json([
                'message' => $progression,
            ], 422);
        }

        $test = QuranTest::query()->create([
            'attempt_no' => app(QuranProgressionService::class)->nextAttemptNumber($enrollment, (int) $validated['juz_id'], (int) $validated['quran_test_type_id']),
            'enrollment_id' => $enrollment->id,
            'juz_id' => $validated['juz_id'],
            'notes' => blank($validated['notes'] ?? null) ? null : $validated['notes'],
            'quran_test_type_id' => $validated['quran_test_type_id'],
            'score' => $score === '' ? null : $score,
            'status' => $validated['status'],
            'student_id' => $enrollment->student_id,
            'teacher_id' => $validated['teacher_id'],
            'tested_on' => $validated['tested_on'],
        ]);

        $test->load(['juz', 'teacher', 'type']);

        return response()->json([
            'attempt_no' => $test->attempt_no,
            'id' => $test->id,
            'juz_id' => $test->juz_id,
            'juz_number' => $test->juz?->juz_number,
            'quran_test_type_id' => $test->quran_test_type_id,
            'quran_test_type_name' => $test->type?->name,
            'score' => $test->score !== null ? (float) $test->score : null,
            'status' => $test->status,
            'teacher_id' => $test->teacher_id,
            'teacher_name' => trim(($test->teacher?->first_name ?? '').' '.($test->teacher?->last_name ?? '')),
            'tested_on' => $test->tested_on?->format('Y-m-d'),
        ], 201);
    }

    /**
     * Record a manual point transaction for one enrollment.
     */
    public function storeManualPoint(Request $request, Enrollment $enrollment)
    {
        $this->authorizePermission($request, 'points.create-manual');
        $this->authorizeTeacherEnrollmentScope($request, $enrollment);

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'point_type_id' => ['required', 'integer', Rule::exists('point_types', 'id')->where('is_active', true)],
            'points' => ['required', 'integer'],
        ]);

        $pointType = PointType::query()->findOrFail($validated['point_type_id']);

        if (! $pointType->allow_manual_entry) {
            return response()->json([
                'message' => 'This point type cannot be entered manually.',
            ], 422);
        }

        if (! $pointType->allow_negative && (int) $validated['points'] < 0) {
            return response()->json([
                'message' => 'This point type does not allow negative values.',
            ], 422);
        }

        $transaction = PointTransaction::query()->create([
            'enrollment_id' => $enrollment->id,
            'entered_at' => now(),
            'entered_by' => $request->user()->id,
            'notes' => blank($validated['notes'] ?? null) ? null : $validated['notes'],
            'point_type_id' => $pointType->id,
            'points' => (int) $validated['points'],
            'policy_id' => null,
            'source_id' => null,
            'source_type' => 'manual',
            'student_id' => $enrollment->student_id,
        ]);

        app(PointLedgerService::class)->syncEnrollmentCaches($enrollment->fresh(['student']));

        return response()->json($this->pointTransactionPayload($transaction->fresh(['pointType'])), 201);
    }

    /**
     * Void one point transaction while preserving its history.
     */
    public function voidPoint(Request $request, PointTransaction $pointTransaction)
    {
        $this->authorizePermission($request, 'points.void');

        $enrollment = $pointTransaction->enrollment()->with('group', 'student')->first();
        abort_unless($enrollment, 404);

        $this->authorizeTeacherEnrollmentScope($request, $enrollment);

        if (! $pointTransaction->voided_at) {
            $pointTransaction->update([
                'void_reason' => 'Voided from the integration API.',
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
            ]);

            app(PointLedgerService::class)->syncEnrollmentCaches($enrollment->fresh(['student']));
        }

        return response()->json($this->pointTransactionPayload($pointTransaction->fresh(['pointType', 'voidedBy'])));
    }

    /**
     * Upsert assessment results for one assessment.
     */
    public function storeAssessmentResults(Request $request, Assessment $assessment)
    {
        $this->authorizePermission($request, 'assessment-results.record');
        $this->authorizeTeacherAssessmentScope($request, $assessment);

        $maxMark = $assessment->total_mark !== null ? (float) $assessment->total_mark : 100;

        $validated = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.attempt_no' => ['nullable', 'integer', 'min:1'],
            'results.*.enrollment_id' => ['required', 'integer', Rule::exists('enrollments', 'id')->whereNull('deleted_at')],
            'results.*.notes' => ['nullable', 'string'],
            'results.*.score' => ['nullable', 'numeric', 'min:0', 'max:'.$maxMark],
            'results.*.status' => ['nullable', 'in:passed,failed,absent,pending'],
        ]);

        $enrollmentIds = collect($validated['results'])->pluck('enrollment_id')->unique()->values();
        $enrollments = Enrollment::query()
            ->whereIn('id', $enrollmentIds->all())
            ->where('group_id', $assessment->group_id)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        if ($enrollments->count() !== $enrollmentIds->count()) {
            return response()->json([
                'message' => 'One or more enrollment records do not belong to this active assessment group.',
            ], 422);
        }

        $teacherId = $request->user()?->teacherProfile?->id ?: $assessment->group?->teacher_id;
        $service = app(AssessmentService::class);
        $savedIds = [];

        foreach ($validated['results'] as $resultInput) {
            $status = $resultInput['status'] ?? 'pending';
            $score = $resultInput['score'] ?? null;
            $notes = $resultInput['notes'] ?? null;

            if (($score === null || $score === '') && $status === 'pending' && blank($notes)) {
                continue;
            }

            $result = AssessmentResult::query()->updateOrCreate(
                [
                    'assessment_id' => $assessment->id,
                    'enrollment_id' => $resultInput['enrollment_id'],
                ],
                [
                    'attempt_no' => (int) ($resultInput['attempt_no'] ?? 1),
                    'notes' => blank($notes) ? null : $notes,
                    'score' => $score === '' ? null : $score,
                    'status' => $status,
                    'student_id' => $enrollments[$resultInput['enrollment_id']]->student_id,
                    'teacher_id' => $teacherId,
                ],
            );

            $service->syncResultPoints($result->fresh(['assessment.type', 'enrollment.student']));
            $savedIds[] = $result->id;
        }

        $results = AssessmentResult::query()
            ->with(['enrollment.student'])
            ->whereIn('id', $savedIds)
            ->orderBy('enrollment_id')
            ->get();

        return response()->json([
            'assessment_id' => $assessment->id,
            'results' => $results->map(fn (AssessmentResult $result) => [
                'attempt_no' => $result->attempt_no,
                'enrollment_id' => $result->enrollment_id,
                'id' => $result->id,
                'notes' => $result->notes,
                'score' => $result->score !== null ? (float) $result->score : null,
                'status' => $result->status,
                'student_id' => $result->student_id,
                'student_name' => trim(($result->enrollment?->student?->first_name ?? '').' '.($result->enrollment?->student?->last_name ?? '')),
            ])->all(),
        ]);
    }

    protected function authorizeTeacherPayloadScope(Request $request, int $teacherId): void
    {
        $teacher = Teacher::query()->findOrFail($teacherId);
        abort_unless(app(AccessScopeService::class)->canAccessTeacher($request->user(), $teacher), 403);
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function authorizeTeacherAssessmentScope(Request $request, Assessment $assessment): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessAssessment($request->user(), $assessment), 403);
    }

    protected function authorizeTeacherEnrollmentScope(Request $request, Enrollment $enrollment): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessEnrollment($request->user(), $enrollment), 403);
    }

    protected function authorizeTeacherGroupScope(Request $request, Group $group): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessGroup($request->user(), $group), 403);
    }

    protected function pointTransactionPayload(PointTransaction $transaction): array
    {
        return [
            'entered_at' => $transaction->entered_at?->toIso8601String(),
            'enrollment_id' => $transaction->enrollment_id,
            'id' => $transaction->id,
            'notes' => $transaction->notes,
            'point_type_id' => $transaction->point_type_id,
            'point_type_name' => $transaction->pointType?->name,
            'points' => $transaction->points,
            'source_type' => $transaction->source_type,
            'student_id' => $transaction->student_id,
            'voided_at' => $transaction->voided_at?->toIso8601String(),
            'voided_by' => $transaction->voided_by,
        ];
    }
}

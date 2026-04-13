<?php

namespace App\Services\BarcodeActions;

use App\Models\AttendanceStatus;
use App\Models\BarcodeAction;
use App\Models\BarcodeScanEvent;
use App\Models\BarcodeScanImport;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ScannerDumpImportService
{
    public function __construct(
        protected AccessScopeService $accessScopes,
        protected BarcodeActionCatalogService $catalog,
        protected PointLedgerService $ledger,
        protected StudentAttendanceDayService $attendanceDayService,
    ) {
    }

    public function preview(int $courseId, string $attendanceDate, string $rawDump, User $actor): array
    {
        $tokens = $this->tokenize($rawDump);
        $rows = [];
        $currentAction = null;
        $duplicateKeys = [];
        $blockingErrors = 0;
        $readyCount = 0;
        $skippedCount = 0;

        if (! $this->courseIsAccessible($courseId, $actor)) {
            return [
                'rows' => [],
                'ready_count' => 0,
                'skipped_count' => 0,
                'error_count' => 1,
                'messages' => [__('barcodes.import.errors.course_not_accessible')],
            ];
        }

        if ($tokens === []) {
            return [
                'rows' => [],
                'ready_count' => 0,
                'skipped_count' => 0,
                'error_count' => 1,
                'messages' => [__('barcodes.import.errors.empty_dump')],
            ];
        }

        foreach ($tokens as $index => $rawValue) {
            $sequenceNo = $index + 1;
            $normalized = $this->catalog->normalizeBarcodeValue($rawValue);
            $action = $this->catalog->findAction($normalized);

            if ($action) {
                $currentAction = $action;
                $rows[] = [
                    'sequence_no' => $sequenceNo,
                    'raw_value' => $rawValue,
                    'normalized_value' => $normalized,
                    'token_type' => 'action',
                    'barcode_action_id' => $action->id,
                    'action_code' => $action->code,
                    'action_name' => $action->name,
                    'student_id' => null,
                    'student_name' => null,
                    'enrollment_id' => null,
                    'group_name' => null,
                    'result' => 'context',
                    'message' => __('barcodes.import.messages.action_selected', ['action' => $action->name]),
                    'blocking' => false,
                ];

                continue;
            }

            $studentNumber = $this->catalog->studentNumberFromBarcode($normalized);

            if (! $studentNumber) {
                $blockingErrors++;
                $rows[] = $this->errorRow($sequenceNo, $rawValue, $normalized, 'unknown', __('barcodes.import.errors.unknown_barcode'));

                continue;
            }

            if (! $currentAction) {
                $blockingErrors++;
                $rows[] = $this->errorRow($sequenceNo, $rawValue, $normalized, 'student', __('barcodes.import.errors.student_before_action'));

                continue;
            }

            $student = $this->resolveStudent($studentNumber, $actor);

            if (! $student) {
                $blockingErrors++;
                $rows[] = $this->errorRow($sequenceNo, $rawValue, $normalized, 'student', __('barcodes.import.errors.student_missing', ['number' => $studentNumber]));

                continue;
            }

            $enrollmentResult = $this->resolveEnrollment($student, $courseId, $actor);

            if ($enrollmentResult['error']) {
                $blockingErrors++;
                $rows[] = $this->errorRow($sequenceNo, $rawValue, $normalized, 'student', $enrollmentResult['error'], $currentAction, $student);

                continue;
            }

            /** @var Enrollment $enrollment */
            $enrollment = $enrollmentResult['enrollment'];
            $validationError = $this->validateAction($currentAction, $enrollment);

            if ($validationError) {
                $blockingErrors++;
                $rows[] = $this->errorRow($sequenceNo, $rawValue, $normalized, 'student', $validationError, $currentAction, $student, $enrollment);

                continue;
            }

            $duplicateKey = implode('|', [
                $currentAction->id,
                $student->id,
                $enrollment->id,
                $attendanceDate,
            ]);

            if (isset($duplicateKeys[$duplicateKey])) {
                $skippedCount++;
                $rows[] = $this->studentRow($sequenceNo, $rawValue, $normalized, $currentAction, $student, $enrollment, 'skipped', __('barcodes.import.warnings.duplicate_scan'), false);

                continue;
            }

            $duplicateKeys[$duplicateKey] = true;
            $readyCount++;
            $rows[] = $this->studentRow($sequenceNo, $rawValue, $normalized, $currentAction, $student, $enrollment, 'ready', __('barcodes.import.messages.ready'), false);
        }

        return [
            'rows' => $rows,
            'ready_count' => $readyCount,
            'skipped_count' => $skippedCount,
            'error_count' => $blockingErrors,
            'messages' => [],
        ];
    }

    public function apply(int $courseId, string $attendanceDate, string $rawDump, User $actor): array
    {
        $preview = $this->preview($courseId, $attendanceDate, $rawDump, $actor);

        if ($preview['error_count'] > 0 || $preview['ready_count'] < 1) {
            return $preview + ['import' => null];
        }

        $import = DB::transaction(function () use ($actor, $attendanceDate, $courseId, $preview, $rawDump): BarcodeScanImport {
            $import = BarcodeScanImport::query()->create([
                'course_id' => $courseId,
                'attendance_date' => $attendanceDate,
                'raw_dump' => $rawDump,
                'status' => 'processed',
                'processed_count' => 0,
                'error_count' => $preview['error_count'],
                'created_by' => $actor->id,
                'processed_at' => now(),
            ]);

            $processedCount = 0;

            foreach ($preview['rows'] as $row) {
                $event = BarcodeScanEvent::query()->create([
                    'barcode_scan_import_id' => $import->id,
                    'sequence_no' => $row['sequence_no'],
                    'raw_value' => $row['raw_value'],
                    'normalized_value' => $row['normalized_value'],
                    'token_type' => $row['token_type'],
                    'barcode_action_id' => $row['barcode_action_id'] ?? null,
                    'student_id' => $row['student_id'] ?? null,
                    'enrollment_id' => $row['enrollment_id'] ?? null,
                    'result' => $row['result'],
                    'message' => $row['message'],
                ]);

                if ($row['result'] !== 'ready') {
                    continue;
                }

                $action = BarcodeAction::query()
                    ->with(['attendanceStatus', 'pointType'])
                    ->findOrFail($row['barcode_action_id']);
                $enrollment = Enrollment::query()
                    ->with(['student', 'group'])
                    ->findOrFail($row['enrollment_id']);
                $applied = $this->applyAction($action, $enrollment, $attendanceDate, $actor, $event);

                $event->update([
                    'applied_model_type' => $applied::class,
                    'applied_model_id' => $applied->getKey(),
                    'result' => 'applied',
                    'message' => __('barcodes.import.messages.applied'),
                ]);

                $processedCount++;
            }

            $import->update(['processed_count' => $processedCount]);

            return $import->fresh(['course', 'events']);
        });

        return $preview + ['import' => $import];
    }

    protected function applyAction(BarcodeAction $action, Enrollment $enrollment, string $attendanceDate, User $actor, BarcodeScanEvent $event): Model
    {
        if ($action->isAttendance()) {
            return $this->applyAttendanceAction($action, $enrollment, $attendanceDate, $actor);
        }

        return $this->applyPointAction($action, $enrollment, $actor, $event);
    }

    protected function applyAttendanceAction(BarcodeAction $action, Enrollment $enrollment, string $attendanceDate, User $actor): StudentAttendanceRecord
    {
        /** @var AttendanceStatus $status */
        $status = $action->attendanceStatus()->firstOrFail();
        $studentDay = $this->attendanceDayService->createOrSyncDay(
            $attendanceDate,
            collect([$enrollment->group]),
            $actor,
            null,
            'open',
        );

        $groupDay = GroupAttendanceDay::query()
            ->where('group_id', $enrollment->group_id)
            ->whereDate('attendance_date', $studentDay->attendance_date->toDateString())
            ->firstOrFail();

        $record = StudentAttendanceRecord::query()->updateOrCreate(
            [
                'group_attendance_day_id' => $groupDay->id,
                'enrollment_id' => $enrollment->id,
            ],
            [
                'attendance_status_id' => $status->id,
                'notes' => __('barcodes.import.notes.attendance'),
            ],
        );

        $this->ledger->voidSourceTransactions('student_attendance_record', $record->id, __('workflow.student_attendance.messages.void_reason'));
        $this->ledger->recordAttendanceStatusPoints(
            $enrollment,
            'student_attendance_record',
            $record->id,
            $status,
            __('workflow.student_attendance.messages.automatic_points', ['status' => $status->name]),
        );

        $this->ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
        $this->attendanceDayService->syncAggregateStatus($studentDay);

        return $record;
    }

    protected function applyPointAction(BarcodeAction $action, Enrollment $enrollment, User $actor, BarcodeScanEvent $event): PointTransaction
    {
        /** @var PointType $pointType */
        $pointType = $action->pointType()->firstOrFail();

        $transaction = PointTransaction::query()->create([
            'student_id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'point_type_id' => $pointType->id,
            'policy_id' => null,
            'source_type' => 'barcode_scan',
            'source_id' => $event->id,
            'points' => (int) $pointType->default_points,
            'entered_by' => $actor->id,
            'entered_at' => now(),
            'notes' => __('barcodes.import.notes.points', ['action' => $action->name]),
        ]);

        $this->ledger->syncEnrollmentCaches($enrollment->fresh(['student']));

        return $transaction;
    }

    protected function courseIsAccessible(int $courseId, User $actor): bool
    {
        return Course::query()->whereKey($courseId)->exists()
            && $this->accessScopes->scopeGroups(Group::query()->where('course_id', $courseId), $actor)->exists();
    }

    protected function errorRow(int $sequenceNo, string $rawValue, string $normalized, string $tokenType, string $message, ?BarcodeAction $action = null, ?Student $student = null, ?Enrollment $enrollment = null): array
    {
        return [
            'sequence_no' => $sequenceNo,
            'raw_value' => $rawValue,
            'normalized_value' => $normalized,
            'token_type' => $tokenType,
            'barcode_action_id' => $action?->id,
            'action_code' => $action?->code,
            'action_name' => $action?->name,
            'student_id' => $student?->id,
            'student_name' => $student?->full_name,
            'enrollment_id' => $enrollment?->id,
            'group_name' => $enrollment?->group?->name,
            'result' => 'error',
            'message' => $message,
            'blocking' => true,
        ];
    }

    protected function resolveEnrollment(Student $student, int $courseId, User $actor): array
    {
        $enrollments = $this->accessScopes->scopeEnrollments(
            Enrollment::query()
                ->with(['group.course'])
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->whereHas('group', fn ($query) => $query->where('course_id', $courseId)),
            $actor,
        )->get();

        if ($enrollments->isEmpty()) {
            return [
                'enrollment' => null,
                'error' => __('barcodes.import.errors.no_enrollment_in_course', ['student' => $student->full_name]),
            ];
        }

        if ($enrollments->count() > 1) {
            return [
                'enrollment' => null,
                'error' => __('barcodes.import.errors.multiple_enrollments_in_course', ['student' => $student->full_name]),
            ];
        }

        return [
            'enrollment' => $enrollments->first(),
            'error' => null,
        ];
    }

    protected function resolveStudent(string $studentNumber, User $actor): ?Student
    {
        return $this->accessScopes->scopeStudents(
            Student::query()->where(function ($query) use ($studentNumber) {
                $query
                    ->where('student_number', $studentNumber)
                    ->orWhere('id', (int) $studentNumber);
            }),
            $actor,
        )->first();
    }

    protected function studentRow(int $sequenceNo, string $rawValue, string $normalized, BarcodeAction $action, Student $student, Enrollment $enrollment, string $result, string $message, bool $blocking): array
    {
        return [
            'sequence_no' => $sequenceNo,
            'raw_value' => $rawValue,
            'normalized_value' => $normalized,
            'token_type' => 'student',
            'barcode_action_id' => $action->id,
            'action_code' => $action->code,
            'action_name' => $action->name,
            'student_id' => $student->id,
            'student_name' => $student->full_name,
            'enrollment_id' => $enrollment->id,
            'group_name' => $enrollment->group?->name,
            'result' => $result,
            'message' => $message,
            'blocking' => $blocking,
        ];
    }

    protected function tokenize(string $rawDump): array
    {
        return preg_split('/[\s,;]+/u', trim($rawDump), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    protected function validateAction(BarcodeAction $action, Enrollment $enrollment): ?string
    {
        if ($action->isAttendance()) {
            $status = $action->attendanceStatus;

            if (! $status || ! $status->is_active || ! in_array($status->scope, ['student', 'both'], true)) {
                return __('barcodes.import.errors.invalid_attendance_action');
            }

            return null;
        }

        if ($action->isPoints()) {
            $pointType = $action->pointType;

            if (! $pointType || ! $pointType->is_active || ! $pointType->allow_manual_entry) {
                return __('barcodes.import.errors.invalid_point_action');
            }

            $points = (int) $pointType->default_points;

            if ($points === 0) {
                return __('barcodes.import.errors.invalid_point_action');
            }

            if (! $pointType->allow_negative && $points < 0) {
                return __('workflow.points.errors.negative_not_allowed');
            }

            return null;
        }

        return __('barcodes.import.errors.unsupported_action');
    }
}

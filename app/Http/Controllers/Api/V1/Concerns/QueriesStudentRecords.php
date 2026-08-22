<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\AssessmentResult;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Per-student record listings shared by the parent and role-aware mobile APIs.
 *
 * Every method takes an optional $scope callable so a caller can narrow the
 * query further — role-aware endpoints pass the AccessScopeService scope so a
 * teacher only sees the records belonging to groups they actually supervise,
 * while the parent endpoints pass none and see everything for their own child.
 */
trait QueriesStudentRecords
{
    use PresentsMobileRecords;

    protected function studentAttendancePayload(Student $student, array $filters, ?callable $scope = null): array
    {
        $query = StudentAttendanceRecord::query()
            ->with(['attendanceDay.group.course', 'status', 'enrollment.group'])
            ->whereHas('enrollment', fn (Builder $builder) => $builder->where('student_id', $student->id))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder
                ->whereHas('attendanceDay', fn (Builder $dayQuery) => $dayQuery->whereDate('attendance_date', '>=', $date)))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder
                ->whereHas('attendanceDay', fn (Builder $dayQuery) => $dayQuery->whereDate('attendance_date', '<=', $date)))
            ->latest('id');

        $records = $this->applyScope($query, $scope)->paginate($filters['per_page']);

        return $this->paginated($records, fn (StudentAttendanceRecord $record): array => [
            'id' => $record->id,
            'date' => $this->date($record->attendanceDay?->attendance_date),
            'day_status' => $record->attendanceDay?->status,
            'status' => [
                'id' => $record->status?->id,
                'name' => $record->status?->name,
                'code' => $record->status?->code,
            ],
            'group' => $this->groupSummary($record->attendanceDay?->group ?? $record->enrollment?->group),
            'notes' => $record->notes,
        ]);
    }

    protected function studentMemorizationPayload(Student $student, array $filters, ?callable $scope = null): array
    {
        $query = MemorizationSession::query()
            ->with(['enrollment.group.course', 'teacher', 'pages'])
            ->where('student_id', $student->id)
            ->when($filters['entry_type'] ?? null, fn (Builder $builder, string $type) => $builder->where('entry_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('recorded_on', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('recorded_on', '<=', $date))
            ->orderByDesc('recorded_on')
            ->orderByDesc('id');

        $sessions = $this->applyScope($query, $scope)->paginate($filters['per_page']);

        return $this->paginated($sessions, fn (MemorizationSession $session): array => [
            'id' => $session->id,
            'date' => $this->date($session->recorded_on),
            'entry_type' => $session->entry_type,
            'from_page' => $session->from_page,
            'to_page' => $session->to_page,
            'pages_count' => $session->pages_count,
            'pages' => $session->pages->pluck('page_no')->values(),
            'group' => $this->groupSummary($session->enrollment?->group),
            'teacher' => $this->teacherSummary($session->teacher),
            'notes' => $session->notes,
        ]);
    }

    protected function studentPointsPayload(Student $student, array $filters, ?callable $scope = null): array
    {
        $query = PointTransaction::query()
            ->with(['pointType', 'policy', 'enrollment.group.course', 'enteredBy'])
            ->notVoided()
            ->where('student_id', $student->id)
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('entered_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('entered_at', '<=', $date))
            ->orderByDesc('entered_at')
            ->orderByDesc('id');

        $transactions = $this->applyScope($query, $scope)->paginate($filters['per_page']);

        return $this->paginated($transactions, fn (PointTransaction $transaction): array => [
            'id' => $transaction->id,
            'entered_at' => $this->datetime($transaction->entered_at),
            'points' => $transaction->points,
            'source_type' => $transaction->source_type,
            'point_type' => [
                'id' => $transaction->pointType?->id,
                'name' => $transaction->pointType?->name,
                'code' => $transaction->pointType?->code,
                'category' => $transaction->pointType?->category,
            ],
            'policy' => [
                'id' => $transaction->policy?->id,
                'name' => $transaction->policy?->name,
            ],
            'group' => $this->groupSummary($transaction->enrollment?->group),
            'notes' => $transaction->notes,
        ]);
    }

    protected function studentAssessmentsPayload(Student $student, array $filters, ?callable $scope = null): array
    {
        $query = AssessmentResult::query()
            ->with(['assessment.type', 'assessment.group.course', 'teacher', 'enrollment.group'])
            ->where('student_id', $student->id)
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder
                ->where(function (Builder $inner) use ($date) {
                    $inner
                        ->whereHas('assessment', fn (Builder $assessment) => $assessment->whereDate('scheduled_at', '>=', $date))
                        ->orWhereDate('created_at', '>=', $date);
                }))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder
                ->where(function (Builder $inner) use ($date) {
                    $inner
                        ->whereHas('assessment', fn (Builder $assessment) => $assessment->whereDate('scheduled_at', '<=', $date))
                        ->orWhereDate('created_at', '<=', $date);
                }))
            ->latest('id');

        $results = $this->applyScope($query, $scope)->paginate($filters['per_page']);

        return $this->paginated($results, fn (AssessmentResult $result): array => [
            'id' => $result->id,
            'assessment' => [
                'id' => $result->assessment?->id,
                'title' => $result->assessment?->title,
                'type' => [
                    'id' => $result->assessment?->type?->id,
                    'name' => $result->assessment?->type?->name,
                    'code' => $result->assessment?->type?->code,
                ],
                'scheduled_at' => $this->datetime($result->assessment?->scheduled_at),
                'due_at' => $this->datetime($result->assessment?->due_at),
                'total_mark' => $this->decimal($result->assessment?->total_mark),
                'pass_mark' => $this->decimal($result->assessment?->pass_mark),
                'group' => $this->groupSummary($result->assessment?->group ?? $result->enrollment?->group),
            ],
            'score' => $this->decimal($result->score),
            'status' => $result->status,
            'attempt_no' => $result->attempt_no,
            'teacher' => $this->teacherSummary($result->teacher),
            'notes' => $result->notes,
        ]);
    }

    /**
     * @param  callable(Builder): Builder|null  $scope  narrows the note query
     * @param  string|null  $visibility  restricts to a single visibility value
     */
    protected function studentNotesPayload(Student $student, array $filters, ?callable $scope = null, ?string $visibility = null): array
    {
        $query = StudentNote::query()
            ->with(['author', 'enrollment.group'])
            ->where('student_id', $student->id)
            ->when($visibility, fn (Builder $builder, string $value) => $builder->where('visibility', $value))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('noted_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('noted_at', '<=', $date))
            ->orderByDesc('noted_at')
            ->orderByDesc('id');

        $notes = $this->applyScope($query, $scope)->paginate($filters['per_page']);

        return $this->paginated($notes, fn (StudentNote $note): array => [
            'id' => $note->id,
            'source' => $note->source,
            'noted_at' => $this->datetime($note->noted_at),
            'body' => $note->body,
            'visibility' => $note->visibility,
            'group' => $this->groupSummary($note->enrollment?->group),
            'author' => [
                'id' => $note->author?->id,
                'name' => $note->author?->name,
            ],
        ]);
    }

    protected function studentQuranTestsPayload(Student $student, array $filters): array
    {
        $items = collect()
            ->merge($this->legacyQuranTests($student))
            ->merge($this->partialQuranTests($student))
            ->merge($this->finalQuranTests($student))
            ->filter(fn (array $item): bool => $this->withinDateRange($item['date'] ?? null, $filters))
            ->sortByDesc(fn (array $item): string => (string) ($item['date'] ?? ''))
            ->values();

        return $this->paginatedCollection($items, $filters['per_page']);
    }

    protected function legacyQuranTests(Student $student): Collection
    {
        return QuranTest::query()
            ->with(['juz', 'type', 'teacher', 'enrollment.group.course'])
            ->where('student_id', $student->id)
            ->get()
            ->map(fn (QuranTest $test): array => [
                'id' => $test->id,
                'kind' => 'legacy',
                'date' => $this->date($test->tested_on),
                'status' => $test->status,
                'score' => $this->decimal($test->score),
                'attempt_no' => $test->attempt_no,
                'juz' => $this->juzSummary($test->juz),
                'type' => [
                    'id' => $test->type?->id,
                    'name' => $test->type?->name,
                    'code' => $test->type?->code,
                ],
                'group' => $this->groupSummary($test->enrollment?->group),
                'teacher' => $this->teacherSummary($test->teacher),
                'notes' => $test->notes,
            ]);
    }

    protected function partialQuranTests(Student $student): Collection
    {
        return QuranPartialTest::query()
            ->with(['juz', 'enrollment.group.course', 'parts.attempts.teacher'])
            ->where('student_id', $student->id)
            ->get()
            ->map(function (QuranPartialTest $test): array {
                $attempts = $test->parts->flatMap->attempts;
                $latestAttempt = $attempts->sortByDesc('tested_on')->first();

                return [
                    'id' => $test->id,
                    'kind' => 'partial',
                    'date' => $this->date($test->passed_on ?? $latestAttempt?->tested_on ?? $test->created_at),
                    'status' => $test->status,
                    'juz' => $this->juzSummary($test->juz),
                    'group' => $this->groupSummary($test->enrollment?->group),
                    'parts' => $test->parts->map(fn ($part): array => [
                        'id' => $part->id,
                        'part_number' => $part->part_number,
                        'status' => $part->status,
                        'passed_on' => $this->date($part->passed_on),
                        'attempts' => $part->attempts->map(fn ($attempt): array => [
                            'id' => $attempt->id,
                            'tested_on' => $this->date($attempt->tested_on),
                            'score' => $this->decimal($attempt->score),
                            'status' => $attempt->status,
                            'attempt_no' => $attempt->attempt_no,
                            'teacher' => $this->teacherSummary($attempt->teacher),
                            'notes' => $attempt->notes,
                        ])->values(),
                    ])->values(),
                ];
            });
    }

    protected function finalQuranTests(Student $student): Collection
    {
        return QuranFinalTest::query()
            ->with(['juz', 'enrollment.group.course', 'attempts.teacher'])
            ->where('student_id', $student->id)
            ->get()
            ->map(function (QuranFinalTest $test): array {
                $latestAttempt = $test->attempts->sortByDesc('tested_on')->first();

                return [
                    'id' => $test->id,
                    'kind' => 'final',
                    'date' => $this->date($test->passed_on ?? $latestAttempt?->tested_on ?? $test->created_at),
                    'status' => $test->status,
                    'juz' => $this->juzSummary($test->juz),
                    'group' => $this->groupSummary($test->enrollment?->group),
                    'attempts' => $test->attempts->map(fn ($attempt): array => [
                        'id' => $attempt->id,
                        'tested_on' => $this->date($attempt->tested_on),
                        'score' => $this->decimal($attempt->score),
                        'status' => $attempt->status,
                        'attempt_no' => $attempt->attempt_no,
                        'teacher' => $this->teacherSummary($attempt->teacher),
                        'notes' => $attempt->notes,
                    ])->values(),
                ];
            });
    }

    /**
     * @param  callable(Builder): Builder|null  $scope
     */
    private function applyScope(Builder $query, ?callable $scope): Builder
    {
        return $scope ? $scope($query) : $query;
    }
}

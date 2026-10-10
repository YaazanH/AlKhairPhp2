<?php

namespace App\Services;

use App\Models\MemorizationSession;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranPartialTestAttempt;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeacherDailySummaryService
{
    public function summary(?User $user, array $filters = []): array
    {
        $date = Carbon::parse($filters['date'] ?? now())->toDateString();
        $includeEmpty = filter_var($filters['include_empty'] ?? false, FILTER_VALIDATE_BOOL);
        $teacherId = isset($filters['teacher_id']) && $filters['teacher_id'] !== null
            ? (int) $filters['teacher_id']
            : null;

        $teachers = app(AccessScopeService::class)
            ->scopeTeachers(Teacher::query(), $user)
            ->when($teacherId, fn (Builder $query) => $query->whereKey($teacherId))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $summaries = $teachers->map(fn (Teacher $teacher) => $this->emptySummary($teacher));

        if ($teachers->isEmpty()) {
            return [
                'date' => $date,
                'teachers_in_scope' => 0,
                'teachers_with_activity' => 0,
                'totals' => $this->totals(collect()),
                'teachers' => [],
            ];
        }

        $this->attachAbsences($summaries, $teachers, $user, $date);
        $this->attachMemorization($summaries, $teachers, $user, $date);
        $this->attachFailedPartialAttempts($summaries, $teachers, $user, $date);
        $this->attachFailedFinalAttempts($summaries, $teachers, $user, $date);

        $teachersWithActivity = $summaries
            ->filter(fn (array $summary) => $this->hasActivity($summary))
            ->values();

        $teachersPayload = ($includeEmpty ? $summaries->values() : $teachersWithActivity)
            ->values();

        return [
            'date' => $date,
            'teachers_in_scope' => $teachers->count(),
            'teachers_with_activity' => $teachersWithActivity->count(),
            'totals' => $this->totals($summaries),
            'teachers' => $teachersPayload->all(),
        ];
    }

    protected function attachAbsences(Collection $summaries, Collection $teachers, ?User $user, string $date): void
    {
        $records = app(AccessScopeService::class)
            ->scopeStudentAttendanceRecords(StudentAttendanceRecord::query(), $user)
            ->with(['attendanceDay.group.course', 'enrollment.student', 'status'])
            ->whereHas('attendanceDay', fn (Builder $query) => $query->whereDate('attendance_date', $date))
            ->whereHas('status', fn (Builder $query) => $query->where('is_present', false))
            ->get();

        foreach ($records as $record) {
            $group = $record->attendanceDay?->group;

            if (! $group) {
                continue;
            }

            $teacherIds = collect([$group->teacher_id, $group->assistant_teacher_id])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->filter(fn (int $id) => $teachers->has($id));

            foreach ($teacherIds as $teacherId) {
                $summary = $summaries->get($teacherId);

                $summary['absences'][] = [
                    'course_name' => $group->course?->name,
                    'group_name' => $group->name,
                    'status' => $record->status?->name,
                    'student_id' => $record->enrollment?->student?->id,
                    'student_name' => $record->enrollment?->student?->full_name ?: '',
                ];
                $summary['absences_count']++;

                $summaries->put($teacherId, $summary);
            }
        }
    }

    protected function attachFailedFinalAttempts(Collection $summaries, Collection $teachers, ?User $user, string $date): void
    {
        $attempts = QuranFinalTestAttempt::query()
            ->with(['finalTest.enrollment.group.course', 'finalTest.juz', 'finalTest.student'])
            ->whereDate('tested_on', $date)
            ->where('status', 'failed')
            ->whereHas('finalTest', fn (Builder $query) => app(AccessScopeService::class)->scopeQuranFinalTests($query, $user))
            ->get();

        foreach ($attempts as $attempt) {
            $teacherId = (int) $attempt->teacher_id;

            if (! $teachers->has($teacherId)) {
                continue;
            }

            $finalTest = $attempt->finalTest;
            $summary = $summaries->get($teacherId);

            $summary['failed_final_attempts'][] = [
                'attempt_no' => (int) $attempt->attempt_no,
                'course_name' => $finalTest?->enrollment?->group?->course?->name,
                'group_name' => $finalTest?->enrollment?->group?->name,
                'juz_number' => $finalTest?->juz?->juz_number,
                'score' => $attempt->score !== null ? (float) $attempt->score : null,
                'student_id' => $finalTest?->student?->id,
                'student_name' => $finalTest?->student?->full_name ?: '',
            ];
            $summary['failed_final_attempts_count']++;

            $summaries->put($teacherId, $summary);
        }
    }

    protected function attachFailedPartialAttempts(Collection $summaries, Collection $teachers, ?User $user, string $date): void
    {
        $attempts = QuranPartialTestAttempt::query()
            ->with(['part.partialTest.enrollment.group.course', 'part.partialTest.juz', 'part.partialTest.student'])
            ->whereDate('tested_on', $date)
            ->where('status', 'failed')
            ->whereHas('part.partialTest', fn (Builder $query) => app(AccessScopeService::class)->scopeQuranPartialTests($query, $user))
            ->get();

        foreach ($attempts as $attempt) {
            $teacherId = (int) $attempt->teacher_id;

            if (! $teachers->has($teacherId)) {
                continue;
            }

            $partialTest = $attempt->part?->partialTest;
            $summary = $summaries->get($teacherId);

            $summary['failed_partial_attempts'][] = [
                'attempt_no' => (int) $attempt->attempt_no,
                'course_name' => $partialTest?->enrollment?->group?->course?->name,
                'group_name' => $partialTest?->enrollment?->group?->name,
                'juz_number' => $partialTest?->juz?->juz_number,
                'mistake_count' => $attempt->mistake_count,
                'part_number' => $attempt->part?->part_number,
                'student_id' => $partialTest?->student?->id,
                'student_name' => $partialTest?->student?->full_name ?: '',
            ];
            $summary['failed_partial_attempts_count']++;

            $summaries->put($teacherId, $summary);
        }
    }

    protected function attachMemorization(Collection $summaries, Collection $teachers, ?User $user, string $date): void
    {
        $sessions = app(AccessScopeService::class)
            ->scopeMemorizationSessions(MemorizationSession::query(), $user)
            ->with(['enrollment.group.course', 'student'])
            ->whereDate('recorded_on', $date)
            ->get();

        foreach ($sessions as $session) {
            $teacherId = (int) $session->teacher_id;

            if (! $teachers->has($teacherId)) {
                continue;
            }

            $summary = $summaries->get($teacherId);

            $summary['memorization_entries'][] = [
                'course_name' => $session->enrollment?->group?->course?->name,
                'entry_type' => $session->entry_type,
                'from_page' => $session->from_page,
                'group_name' => $session->enrollment?->group?->name,
                'pages_count' => (int) $session->pages_count,
                'student_id' => $session->student?->id,
                'student_name' => $session->student?->full_name ?: '',
                'to_page' => $session->to_page,
            ];
            $summary['memorization_sessions_count']++;
            $summary['memorized_pages'] += (int) $session->pages_count;

            $summaries->put($teacherId, $summary);
        }
    }

    protected function emptySummary(Teacher $teacher): array
    {
        return [
            'absences' => [],
            'absences_count' => 0,
            'failed_final_attempts' => [],
            'failed_final_attempts_count' => 0,
            'failed_partial_attempts' => [],
            'failed_partial_attempts_count' => 0,
            'memorization_entries' => [],
            'memorization_sessions_count' => 0,
            'memorized_pages' => 0,
            'teacher' => [
                'id' => (int) $teacher->id,
                'name' => trim($teacher->first_name.' '.$teacher->last_name),
            ],
        ];
    }

    protected function hasActivity(array $summary): bool
    {
        return $summary['absences_count'] > 0
            || $summary['memorization_sessions_count'] > 0
            || $summary['failed_partial_attempts_count'] > 0
            || $summary['failed_final_attempts_count'] > 0;
    }

    protected function totals(Collection $summaries): array
    {
        return [
            'absences_count' => (int) $summaries->sum('absences_count'),
            'failed_final_attempts_count' => (int) $summaries->sum('failed_final_attempts_count'),
            'failed_partial_attempts_count' => (int) $summaries->sum('failed_partial_attempts_count'),
            'memorization_sessions_count' => (int) $summaries->sum('memorization_sessions_count'),
            'memorized_pages' => (int) $summaries->sum('memorized_pages'),
        ];
    }
}

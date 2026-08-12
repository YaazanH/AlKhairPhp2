<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PointTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourseEndService
{
    public function __construct(protected CourseCompletionRuleService $rules)
    {
    }

    public function summary(Course $course): array
    {
        $rows = $this->studentRows($course);
        $tests = $this->finalTestRows($course);

        return [
            'students' => $rows->count(),
            'points_before' => $rows->sum('points_before'),
            'points_after' => $rows->sum('points_after'),
            'memorized_pages' => $rows->sum('memorized_pages'),
            'final_tests' => $tests->count(),
        ];
    }

    public function studentRows(Course $course): Collection
    {
        $settings = $this->rules->settings();

        return $this->enrollments($course)
            ->map(function (Enrollment $enrollment) use ($settings): array {
                $criteria = $this->rules->criteriaForEnrollment($enrollment, $settings);
                $pointsBefore = (int) PointTransaction::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->effectiveActive()
                    ->where('source_type', '!=', CourseCompletionRuleService::ADJUSTMENT_SOURCE_TYPE)
                    ->sum('points');
                $pointsAfter = $this->calculatedPoints($pointsBefore, $criteria['passed'], $settings);
                $finals = $enrollment->quranFinalTests->filter(fn ($test) => $test->attempts->contains('status', 'passed'));
                $scores = $finals->map(fn ($test) => $test->attempts->firstWhere('status', 'passed')?->score)->filter(fn ($score) => $score !== null);
                $attendance = $enrollment->studentAttendanceRecords;
                $regularAssessmentResults = $enrollment->assessmentResults
                    ->filter(fn ($result) => ! $this->isFinalAssessment($result))
                    ->where('status', '!=', 'absent');
                $assessmentScores = $regularAssessmentResults->pluck('score')->filter(fn ($score) => $score !== null);
                $finalExamScores = $enrollment->assessmentResults
                    ->filter(fn ($result) => $this->isFinalAssessment($result))
                    ->pluck('score')->filter(fn ($score) => $score !== null);
                $memorizedPages = $enrollment->memorizationSessions->flatMap->pages->pluck('page_no')->unique()->count();
                $attendanceDaysCreated = $this->attendanceDaysCreatedForEnrollment($enrollment);
                $daysAttended = $attendance
                    ->filter(fn ($record) => (bool) $record->status?->is_present)
                    ->pluck('group_attendance_day_id')
                    ->unique()
                    ->count();
                $dailyMemorizationAverage = $attendanceDaysCreated > 0
                    ? round($memorizedPages / $attendanceDaysCreated, 2)
                    : 0.0;
                $rewardTransactions = $enrollment->pointTransactions
                    ->filter(fn (PointTransaction $transaction) => $transaction->voided_at === null)
                    ->reject(fn (PointTransaction $transaction) => $transaction->source_type === CourseCompletionRuleService::ADJUSTMENT_SOURCE_TYPE);
                $chequesCount = $rewardTransactions->filter(fn (PointTransaction $transaction) => $this->pointTransactionHasName($transaction, ['شيك ٢٥', 'شيك ٥٠', 'شيك 25', 'شيك 50']))->count();
                $leaderboardCount = $rewardTransactions->filter(fn (PointTransaction $transaction) => $this->pointTransactionHasName($transaction, ['فارس قرآني', 'فارس قراني']))->count();

                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'name' => $enrollment->student?->full_name ?? '',
                    'group' => $enrollment->group?->name ?? '',
                    'points_before' => $pointsBefore,
                    'points_after' => $pointsAfter,
                    'attendance_average' => $attendanceDaysCreated > 0 ? round(($daysAttended / $attendanceDaysCreated) * 100, 2) : 0.0,
                    'attendance_days_created' => $attendanceDaysCreated,
                    'days_attended' => $daysAttended,
                    'days_absent' => $attendance->filter(fn ($record) => $record->status && ! $record->status->is_present)->count(),
                    'memorized_pages' => $memorizedPages,
                    'daily_memorization_average' => $dailyMemorizationAverage,
                    'weekly_memorization_average' => round($dailyMemorizationAverage * 3, 2),
                    'final_tests' => $finals->count(),
                    'final_score' => $finalExamScores->isEmpty() ? null : round((float) $finalExamScores->average(), 2),
                    'final_juzs' => $finals->pluck('juz.juz_number')->filter()->sort()->implode(', '),
                    'final_marks' => $scores->map(fn ($score) => \App\Support\PercentageFormatter::format($score))->implode(', '),
                    'assessment_count' => $regularAssessmentResults->count(),
                    'assessment_average' => $assessmentScores->isEmpty() ? null : round((float) $assessmentScores->average(), 2),
                    'cheques_count' => $chequesCount,
                    'leaderboard_count' => $leaderboardCount,
                    'passed_rules' => (bool) $criteria['passed'],
                ];
            })
            ->sortByDesc('points_after')
            ->values();
    }

    public function finalTestRows(Course $course): Collection
    {
        return $this->enrollments($course)
            ->flatMap(fn (Enrollment $enrollment) => $enrollment->quranFinalTests
                ->filter(fn ($test) => $test->attempts->contains('status', 'passed'))
                ->map(function ($test) use ($enrollment): array {
                    $attempt = $test->attempts->firstWhere('status', 'passed');

                    return [
                        'name' => $enrollment->student?->full_name ?? '',
                        'student_id' => $enrollment->student_id,
                        'juz' => $test->juz?->juz_number,
                        'mark' => $attempt?->score,
                    ];
                }))
            ->sort(fn (array $left, array $right) => [mb_strtolower($left['name']), (int) $left['juz']] <=> [mb_strtolower($right['name']), (int) $right['juz']])
            ->values();
    }

    protected function enrollments(Course $course): Collection
    {
        return Enrollment::query()
            ->with([
                'student',
                'group.attendanceDays',
                'memorizationSessions.pages',
                'studentAttendanceRecords.status',
                'quranFinalTests.juz',
                'quranFinalTests.attempts',
                'assessmentResults.assessment.type',
                'pointTransactions.pointType',
                'pointTransactions.policy',
            ])
            ->whereHas('group', fn ($query) => $query->where('course_id', $course->id))
            ->whereIn('status', ['active', 'completed'])
            ->get();
    }

    protected function calculatedPoints(int $basePoints, bool $passed, array $settings): int
    {
        if ($basePoints <= 0) {
            return 200;
        }

        $target = $passed
            ? $basePoints
            : min($basePoints, max((int) ($basePoints * (((int) $settings['retain_percentage']) / 100)), (int) $settings['minimum_points']));

        return max(200, (int) (ceil($target / 100) * 100));
    }

    protected function attendanceDaysCreatedForEnrollment(Enrollment $enrollment): int
    {
        $from = collect([$enrollment->enrolled_at, $enrollment->group?->course?->starts_on])->filter()->max();
        $to = collect([$enrollment->left_at, $enrollment->group?->course?->ends_on])->filter()->min();

        return $enrollment->group?->attendanceDays
            ?->filter(fn ($day) => (! $from || $day->attendance_date->greaterThanOrEqualTo($from)) && (! $to || $day->attendance_date->lessThanOrEqualTo($to)))
            ->pluck('attendance_date')
            ->map->toDateString()
            ->unique()
            ->count() ?? 0;
    }

    protected function isFinalAssessment($result): bool
    {
        $assessment = $result->assessment;
        $code = Str::lower((string) $assessment?->type?->code);
        $name = Str::lower(trim(($assessment?->type?->name ?? '').' '.($assessment?->title ?? '')));

        return in_array($code, ['final', 'final_exam', 'final-exam'], true)
            || Str::contains($name, ['final exam', 'final assessment', 'نهائي']);
    }

    protected function pointTransactionHasName(PointTransaction $transaction, array $names): bool
    {
        $candidateNames = [$transaction->pointType?->name, $transaction->policy?->name];

        return collect($candidateNames)
            ->filter()
            ->map(fn (string $name) => Str::squish($name))
            ->contains(fn (string $name) => in_array($name, $names, true));
    }
}

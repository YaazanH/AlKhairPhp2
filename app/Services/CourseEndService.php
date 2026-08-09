<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PointTransaction;
use Illuminate\Support\Collection;

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
                $finals = $enrollment->quranFinalTests->filter(fn ($test) => $test->attempts->isNotEmpty());
                $scores = $finals->map(fn ($test) => $test->attempts->firstWhere('status', 'passed')?->score)->filter(fn ($score) => $score !== null);
                $attendance = $enrollment->studentAttendanceRecords;
                $assessmentScores = $enrollment->assessmentResults->where('status', '!=', 'absent')->pluck('score')->filter(fn ($score) => $score !== null);
                $finalExamScores = $enrollment->assessmentResults
                    ->filter(fn ($result) => in_array($result->assessment?->type?->code, ['final', 'final_exam'], true))
                    ->pluck('score')->filter(fn ($score) => $score !== null);

                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $enrollment->student_id,
                    'name' => $enrollment->student?->full_name ?? '',
                    'group' => $enrollment->group?->name ?? '',
                    'points_before' => $pointsBefore,
                    'points_after' => $pointsAfter,
                    'days_attended' => $attendance->filter(fn ($record) => (bool) $record->status?->is_present)->count(),
                    'days_absent' => $attendance->filter(fn ($record) => $record->status && ! $record->status->is_present)->count(),
                    'memorized_pages' => $enrollment->memorizationSessions->flatMap->pages->pluck('page_no')->unique()->count(),
                    'final_tests' => $finals->count(),
                    'final_score' => $finalExamScores->isEmpty() ? null : round((float) $finalExamScores->average(), 2),
                    'final_juzs' => $finals->pluck('juz.juz_number')->filter()->sort()->implode(', '),
                    'final_marks' => $scores->map(fn ($score) => \App\Support\PercentageFormatter::format($score))->implode(', '),
                    'assessment_count' => $enrollment->assessmentResults->count(),
                    'assessment_average' => $assessmentScores->isEmpty() ? null : round((float) $assessmentScores->average(), 2),
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
                'group',
                'memorizationSessions.pages',
                'studentAttendanceRecords.status',
                'quranFinalTests.juz',
                'quranFinalTests.attempts',
                'assessmentResults.assessment.type',
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
}

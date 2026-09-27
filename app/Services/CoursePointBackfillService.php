<?php

namespace App\Services;

use App\Models\AssessmentResult;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentPageAchievement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoursePointBackfillService
{
    public function __construct(private PointLedgerService $ledger) {}

    public function restoreMissingAwards(Course $course): void
    {
        if (! $course->is_active || ! $course->awards_points) {
            return;
        }

        DB::transaction(function () use ($course): void {
            // Serialize repeated toggles/backfills for the same course.
            Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            Enrollment::query()->with(['student', 'group.course'])->whereHas('student')
                ->whereHas('group', fn ($query) => $query->where('course_id', $course->id))
                ->chunkById(100, function ($enrollments): void {
                    foreach ($enrollments as $enrollment) {
                        $this->restoreEnrollment($enrollment);
                    }
                });
        });
    }

    private function hasHistory(string $source, int $id): bool
    {
        // A deliberately voided award must never be resurrected by this switch.
        return PointTransaction::query()->where('source_type', $source)->where('source_id', $id)->exists();
    }

    private function restoreEnrollment(Enrollment $enrollment): void
    {
        foreach (StudentAttendanceRecord::query()->with(['status', 'attendanceDay'])->where('enrollment_id', $enrollment->id)->lazyById(100) as $record) {
            if ($record->status && ! $this->hasHistory('student_attendance_record', $record->id)) {
                $this->ledger->atHistoricalDate($record->attendanceDay->attendance_date->toDateString(), fn () => $this->ledger->recordAttendanceStatusPoints($enrollment, 'student_attendance_record', $record->id, $record->status));
            }
        }

        foreach (AssessmentResult::query()->with(['enrollment.student', 'assessment'])->where('enrollment_id', $enrollment->id)->lazyById(100) as $result) {
            if ($result->assessment && ! $this->hasHistory('assessment_result', $result->id)) {
                $this->ledger->atHistoricalDate(($result->assessment->scheduled_at ?? $result->created_at)->toDateString(), function () use ($result): void {
                    // Use this ledger instance so policy windows and multipliers use the event date.
                    $service = app(AssessmentService::class);
                    if (! in_array($result->status, ['passed', 'failed'], true) || $result->score === null) {
                        return;
                    }
                    $band = $service->resolveScoreBand($result->assessment, (float) $result->score);
                    if ($band?->pointType) {
                        $this->ledger->recordAutomaticPoints($result->enrollment, 'assessment_result', $result->id, $band->pointType, null, $service->effectiveBandPoints($band));
                    }
                });
            }
        }

        $this->restoreMemorization($enrollment);

        foreach (QuranPartialTest::query()->with(['parts.attempts', 'enrollment.student', 'student'])->where('enrollment_id', $enrollment->id)->lazyById(100) as $test) {
            foreach ($test->parts->where('status', 'passed') as $part) {
                if (! $this->hasHistory('quran_partial_test_part', $part->id)) {
                    $attempt = $part->attempts->where('status', 'passed')->sortBy([['tested_on', 'asc'], ['id', 'asc']])->first();
                    $this->ledger->atHistoricalDate(($part->passed_on ?? $part->created_at)->toDateString(), fn () => $this->ledger->recordQuranPartialTestPartPoints($part, $attempt ? (float) $attempt->mistake_count : null));
                }
            }
            if ($test->status === 'passed' && ! $this->hasHistory('quran_partial_test', $test->id)) {
                $this->ledger->atHistoricalDate(($test->passed_on ?? $test->created_at)->toDateString(), fn () => $this->ledger->recordQuranPartialTestPoints($test));
            }
        }

        foreach (QuranFinalTest::query()->with(['attempts', 'enrollment.student', 'student'])->where('enrollment_id', $enrollment->id)->where('status', 'passed')->lazyById(100) as $test) {
            if (! $this->hasHistory('quran_final_test', $test->id)) {
                $attempt = $test->attempts->where('status', 'passed')->sortBy([['tested_on', 'asc'], ['id', 'asc']])->first();
                $this->ledger->atHistoricalDate(($test->passed_on ?? $test->created_at)->toDateString(), fn () => $this->ledger->recordQuranFinalTestPoints($test, $attempt ? (float) $attempt->score : null));
            }
        }

        foreach (QuranTest::query()->with(['type', 'enrollment.student', 'student'])->where('enrollment_id', $enrollment->id)->where('status', 'passed')->lazyById(100) as $test) {
            if (! $this->hasHistory('quran_test', $test->id)) {
                $this->ledger->atHistoricalDate(($test->tested_on ?? $test->created_at)->toDateString(), fn () => $this->ledger->recordQuranTestPoints($test));
            }
        }
    }

    private function restoreMemorization(Enrollment $enrollment): void
    {
        if (Str::contains((string) $enrollment->notes, '[legacy_import] memorization_entre')) {
            return;
        }

        $sessions = MemorizationSession::query()->where('enrollment_id', $enrollment->id)
            ->where('entry_type', '!=', 'review')->orderBy('id')->get()
            ->reject(fn ($session) => Str::contains((string) $session->notes, 'Legacy import from Entre records:'))
            ->groupBy(fn ($session) => $session->recorded_on->toDateString());

        foreach ($sessions as $date => $dailySessions) {
            if (PointTransaction::query()->where('source_type', 'memorization_session')->whereIn('source_id', $dailySessions->pluck('id'))->exists()) {
                continue;
            }
            $count = StudentPageAchievement::query()->where('first_enrollment_id', $enrollment->id)
                ->where('student_id', $enrollment->student_id)->whereDate('first_recorded_on', $date)->count();
            if ($count === 0) {
                continue;
            }
            $this->ledger->atHistoricalDate($date, function () use ($enrollment, $date, $count, $dailySessions): void {
                $policy = $this->ledger->resolvePolicy('memorization', 'page', $enrollment->student->grade_level_id, $count, $date);
                if ($policy?->pointType) {
                    $hasRange = $policy->from_value !== null || $policy->to_value !== null;
                    $this->ledger->recordAutomaticPoints($enrollment, 'memorization_session', $dailySessions->last()->id, $policy->pointType, $policy,
                        $hasRange ? $policy->points : $policy->points * $count,
                        __('workflow.memorization.messages.automatic_reward', ['count' => $count]));
                }
            });
        }
    }
}

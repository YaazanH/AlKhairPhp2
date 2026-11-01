<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use Illuminate\Support\Facades\DB;

class MemorizationPointRepairService
{
    public function run(bool $apply, string $asOfDate, string $batch): array
    {
        $report = ['mode' => $apply ? 'apply' : 'preview', 'batch' => $batch, 'as_of_date' => $asOfDate,
            'candidates' => [], 'skipped' => [], 'enrollment_balances' => []];

        PointTransaction::query()->where('source_type', 'memorization_session')->whereNull('voided_at')
            ->orderBy('id')->chunkById(200, function ($transactions) use ($apply, $asOfDate, $batch, &$report): void {
                foreach ($transactions as $candidate) {
                    $source = $candidate->source_id ? MemorizationSession::find($candidate->source_id) : null;
                    if ($source && (int) $source->student_id === (int) $candidate->student_id
                        && (int) $source->enrollment_id === (int) $candidate->enrollment_id) {
                        continue;
                    }
                    if ((int) $candidate->source_id <= 0 || $source) {
                        $report['skipped'][] = ['transaction_id' => $candidate->id, 'reason' => 'ambiguous_source'];

                        continue;
                    }

                    $result = DB::transaction(function () use ($candidate, $apply, $asOfDate, $batch): array {
                        $enrollment = Enrollment::with('group')->find($candidate->enrollment_id);
                        if (! $enrollment || ! $enrollment->group || (int) $enrollment->student_id !== (int) $candidate->student_id) {
                            return ['transaction_id' => $candidate->id, 'reason' => 'ambiguous_ownership'];
                        }

                        // Serialize with course completion and recheck all boundaries before any write.
                        $course = Course::whereKey($enrollment->group->course_id)->lockForUpdate()->first();
                        $group = Group::whereKey($enrollment->group_id)->lockForUpdate()->first();
                        $lockedEnrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->first();
                        if (! $course || ! $group || ! $lockedEnrollment
                            || (int) $group->course_id !== (int) $course->id
                            || (int) $lockedEnrollment->group_id !== (int) $group->id
                            || (int) $lockedEnrollment->student_id !== (int) $candidate->student_id) {
                            return ['transaction_id' => $candidate->id, 'reason' => 'ambiguous_ownership'];
                        }
                        if (! $course->is_active || $course->finished_at || $group->course_finished_at || $lockedEnrollment->course_finished_at
                            || ($course->ends_on && $course->ends_on->toDateString() < $asOfDate)) {
                            return ['transaction_id' => $candidate->id, 'course_id' => $course->id, 'reason' => 'protected_course'];
                        }

                        $transaction = PointTransaction::whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $transaction || $transaction->voided_at) {
                            return ['transaction_id' => $candidate->id, 'reason' => 'already_repaired'];
                        }
                        if ($transaction->source_type !== 'memorization_session' || ! $transaction->source_id
                            || (int) $transaction->student_id !== (int) $lockedEnrollment->student_id
                            || (int) $transaction->enrollment_id !== (int) $lockedEnrollment->id
                            || MemorizationSession::whereKey($transaction->source_id)->exists()) {
                            return ['transaction_id' => $candidate->id, 'reason' => 'source_changed'];
                        }

                        $before = (int) PointTransaction::where('enrollment_id', $lockedEnrollment->id)->effectiveActive()->sum('points');
                        $row = ['transaction_id' => $transaction->id, 'student_id' => $transaction->student_id,
                            'enrollment_id' => $lockedEnrollment->id, 'course_id' => $course->id,
                            'source_id' => $transaction->source_id, 'points' => (int) $transaction->points,
                            'balance_effect' => $course->awards_points ? -(int) $transaction->points : 0,
                            'balance_before' => $before];
                        if ($apply) {
                            $transaction->update(['voided_at' => now(), 'voided_by' => auth()->id(),
                                'void_reason' => 'Deleted memorization repair; batch '.$batch]);
                            // Refresh points only: do not rebuild history, achievements, or current Juz.
                            $after = (int) PointTransaction::where('enrollment_id', $lockedEnrollment->id)->effectiveActive()->sum('points');
                            Enrollment::whereKey($lockedEnrollment->id)->update(['final_points_cached' => $after]);
                        }

                        return $row;
                    });
                    if (isset($result['reason'])) {
                        $report['skipped'][] = $result;

                        continue;
                    }
                    $id = $result['enrollment_id'];
                    $report['enrollment_balances'][$id] ??= ['student_id' => $result['student_id'],
                        'enrollment_id' => $id, 'before' => $result['balance_before'], 'after' => $result['balance_before']];
                    $report['enrollment_balances'][$id]['after'] += $result['balance_effect'];
                    $report['candidates'][] = $result;
                }
            });

        $report['enrollment_balances'] = array_values($report['enrollment_balances']);
        $report['candidate_count'] = count($report['candidates']);
        $report['skipped_count'] = count($report['skipped']);

        return $report;
    }
}

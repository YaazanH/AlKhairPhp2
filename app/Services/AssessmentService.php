<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentScoreBand;

class AssessmentService
{
    public function effectiveBandPoints(AssessmentScoreBand $band): int
    {
        if ($band->points !== null) {
            return (int) $band->points;
        }

        return (int) ($band->pointType?->default_points ?? 0);
    }

    public function resolveScoreBand(Assessment $assessment, ?float $score): ?AssessmentScoreBand
    {
        if ($score === null) {
            return null;
        }

        return AssessmentScoreBand::query()
            ->where('assessment_type_id', $assessment->assessment_type_id)
            ->where('is_active', true)
            ->where('from_mark', '<=', $score)
            ->where('to_mark', '>=', $score)
            ->orderBy('from_mark', 'desc')
            ->first();
    }

    public function statusForScore(Assessment $assessment, ?float $score): string
    {
        if ($score === null) {
            return 'pending';
        }

        if ($assessment->pass_mark !== null) {
            return $score >= (float) $assessment->pass_mark ? 'passed' : 'failed';
        }

        $band = $this->resolveScoreBand($assessment, $score);

        if ($band) {
            return $band->is_fail ? 'failed' : 'passed';
        }

        return 'passed';
    }

    public function syncResultPoints(AssessmentResult $result): void
    {
        $ledger = app(PointLedgerService::class);

        $ledger->voidSourceTransactions('assessment_result', $result->id, __('workflow.assessments.results.messages.void_reason'));

        if (! in_array($result->status, ['passed', 'failed'], true) || $result->score === null) {
            $ledger->syncEnrollmentCaches($result->enrollment->fresh(['student']));

            return;
        }

        $assessment = $result->assessment()->with('type')->firstOrFail();
        $band = $this->resolveScoreBand($assessment, (float) $result->score);
        $points = $band ? $this->effectiveBandPoints($band) : 0;

        if ($band?->pointType && $points !== 0) {
            $ledger->recordAutomaticPoints(
                $result->enrollment,
                'assessment_result',
                $result->id,
                $band->pointType,
                null,
                $points,
                __('workflow.assessments.results.messages.automatic_points', [
                    'type' => $assessment->type?->name,
                    'score' => $result->score,
                ]),
            );
        }

        $ledger->syncEnrollmentCaches($result->enrollment->fresh(['student']));
    }
}

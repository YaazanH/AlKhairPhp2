<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentScoreBand;

class AssessmentService
{
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

        if ($band?->pointType && $band->points !== null && $band->points !== 0) {
            $ledger->recordAutomaticPoints(
                $result->enrollment,
                'assessment_result',
                $result->id,
                $band->pointType,
                null,
                (int) $band->points,
                __('workflow.assessments.results.messages.automatic_points', [
                    'type' => $assessment->type?->name,
                    'score' => $result->score,
                ]),
            );
        }

        $ledger->syncEnrollmentCaches($result->enrollment->fresh(['student']));
    }
}

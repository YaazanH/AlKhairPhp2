<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\QuranTest;
use App\Models\QuranTestType;

class QuranProgressionService
{
    public function validate(Enrollment $enrollment, int $juzId, QuranTestType $testType): ?string
    {
        if ($testType->code === 'final') {
            $passedPartials = QuranTest::query()
                ->where('student_id', $enrollment->student_id)
                ->where('juz_id', $juzId)
                ->whereHas('type', fn ($query) => $query->where('code', 'partial'))
                ->where('status', 'passed')
                ->count();

            if ($passedPartials < 4) {
                return __('workflow.quran_tests.errors.final_requires_partials');
            }
        }

        if ($testType->code === 'awqaf') {
            $passedFinal = QuranTest::query()
                ->where('student_id', $enrollment->student_id)
                ->where('juz_id', $juzId)
                ->whereHas('type', fn ($query) => $query->where('code', 'final'))
                ->where('status', 'passed')
                ->exists();

            if (! $passedFinal) {
                return __('workflow.quran_tests.errors.awqaf_requires_final');
            }
        }

        return null;
    }

    public function nextAttemptNumber(Enrollment $enrollment, int $juzId, int $testTypeId): int
    {
        return QuranTest::query()
            ->where('student_id', $enrollment->student_id)
            ->where('juz_id', $juzId)
            ->where('quran_test_type_id', $testTypeId)
            ->count() + 1;
    }
}

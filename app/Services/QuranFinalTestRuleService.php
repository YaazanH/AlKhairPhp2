<?php

namespace App\Services;

use App\Models\AppSetting;

class QuranFinalTestRuleService
{
    public const GROUP = 'tracking';

    public const FAILED_FROM_KEY = 'quran_final_test_failed_from';
    public const FAILED_TO_KEY = 'quran_final_test_failed_to';
    public const PASSED_FROM_KEY = 'quran_final_test_passed_from';
    public const PASSED_TO_KEY = 'quran_final_test_passed_to';

    public function ranges(): array
    {
        $settings = AppSetting::groupValues(self::GROUP);
        $passingGrade = max(0.0, min(100.0, (float) ($settings->get(self::PASSED_FROM_KEY) ?? 60)));

        return [
            'failed' => [
                'from' => 0.0,
                'to' => max(0.0, $passingGrade - 0.01),
            ],
            'passed' => [
                'from' => $passingGrade,
                'to' => 100.0,
            ],
        ];
    }

    public function statusForScore(float $score): ?string
    {
        if ($score < 0 || $score > 100) {
            return null;
        }

        return $score >= $this->ranges()['passed']['from'] ? 'passed' : 'failed';
    }

    public function store(array $ranges): void
    {
        $this->storePassingGrade((float) $ranges['passed']['from']);
    }

    public function storePassingGrade(float $passingGrade): void
    {
        $passingGrade = max(0.0, min(100.0, $passingGrade));

        // Keep the legacy keys normalized for old integrations while the rule itself
        // is now defined solely by the passing grade.
        AppSetting::storeValue(self::GROUP, self::FAILED_FROM_KEY, 0, 'float');
        AppSetting::storeValue(self::GROUP, self::FAILED_TO_KEY, max(0.0, $passingGrade - 0.01), 'float');
        AppSetting::storeValue(self::GROUP, self::PASSED_FROM_KEY, $passingGrade, 'float');
        AppSetting::storeValue(self::GROUP, self::PASSED_TO_KEY, 100, 'float');
    }
}

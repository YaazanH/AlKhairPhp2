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

        return [
            'failed' => [
                'from' => (float) ($settings->get(self::FAILED_FROM_KEY) ?? 0),
                'to' => (float) ($settings->get(self::FAILED_TO_KEY) ?? 59.99),
            ],
            'passed' => [
                'from' => (float) ($settings->get(self::PASSED_FROM_KEY) ?? 60),
                'to' => (float) ($settings->get(self::PASSED_TO_KEY) ?? 100),
            ],
        ];
    }

    public function statusForScore(float $score): ?string
    {
        $ranges = $this->ranges();

        foreach (['failed', 'passed'] as $status) {
            if ($score >= $ranges[$status]['from'] && $score <= $ranges[$status]['to']) {
                return $status;
            }
        }

        return null;
    }

    public function store(array $ranges): void
    {
        AppSetting::storeValue(self::GROUP, self::FAILED_FROM_KEY, $ranges['failed']['from'], 'float');
        AppSetting::storeValue(self::GROUP, self::FAILED_TO_KEY, $ranges['failed']['to'], 'float');
        AppSetting::storeValue(self::GROUP, self::PASSED_FROM_KEY, $ranges['passed']['from'], 'float');
        AppSetting::storeValue(self::GROUP, self::PASSED_TO_KEY, $ranges['passed']['to'], 'float');
    }
}

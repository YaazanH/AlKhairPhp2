<?php

namespace App\Services;

use App\Models\AppSetting;

class QuranFinalTestRuleService
{
    public const FAILED_FROM_KEY = 'quran_final_test_failed_from';
    public const FAILED_TO_KEY = 'quran_final_test_failed_to';
    public const PASSED_FROM_KEY = 'quran_final_test_passed_from';
    public const PASSED_TO_KEY = 'quran_final_test_passed_to';

    public function ranges(): array
    {
        return [
            'failed' => [
                'from' => $this->floatSetting(self::FAILED_FROM_KEY, 0),
                'to' => $this->floatSetting(self::FAILED_TO_KEY, 59.99),
            ],
            'passed' => [
                'from' => $this->floatSetting(self::PASSED_FROM_KEY, 60),
                'to' => $this->floatSetting(self::PASSED_TO_KEY, 100),
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
        AppSetting::storeValue('tracking', self::FAILED_FROM_KEY, $ranges['failed']['from'], 'number');
        AppSetting::storeValue('tracking', self::FAILED_TO_KEY, $ranges['failed']['to'], 'number');
        AppSetting::storeValue('tracking', self::PASSED_FROM_KEY, $ranges['passed']['from'], 'number');
        AppSetting::storeValue('tracking', self::PASSED_TO_KEY, $ranges['passed']['to'], 'number');
    }

    protected function floatSetting(string $key, float $default): float
    {
        $value = AppSetting::value('tracking', $key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}

<?php

namespace App\Services;

use App\Models\AppSetting;

class QuranPartialTestRuleService
{
    public const GROUP = 'tracking';

    public const FAIL_THRESHOLD_KEY = 'quran_partial_test_fail_threshold';

    public function failThreshold(): int
    {
        $settings = AppSetting::groupValues(self::GROUP);
        $threshold = $settings->get(self::FAIL_THRESHOLD_KEY);

        return is_numeric($threshold) ? max(1, (int) $threshold) : 5;
    }

    public function statusForMistakeCount(int $mistakeCount): string
    {
        return $mistakeCount >= $this->failThreshold()
            ? 'failed'
            : 'passed';
    }

    public function store(int $failThreshold): void
    {
        AppSetting::storeValue(self::GROUP, self::FAIL_THRESHOLD_KEY, $failThreshold, 'integer');
    }
}

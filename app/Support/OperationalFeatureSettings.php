<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Validation\ValidationException;

class OperationalFeatureSettings
{
    public static function memorizationAndSabersEnabled(): bool
    {
        return (bool) (AppSetting::groupValues('general')->get('memorization_saber_entries_enabled') ?? true);
    }

    public static function activitiesEnabled(): bool
    {
        return (bool) (AppSetting::groupValues('general')->get('activity_entries_enabled') ?? true);
    }

    public static function ensureMemorizationAndSabersEnabled(): void
    {
        if (! self::memorizationAndSabersEnabled()) {
            throw ValidationException::withMessages([
                'feature' => __('settings.organization.features.memorization_disabled'),
            ]);
        }
    }

    public static function ensureQuranTestsEnabled(): void
    {
        if (! app(CurrentModuleAccess::class)->enabled('quran_tests')) {
            throw ValidationException::withMessages([
                'feature' => 'module_disabled: quran_tests',
            ]);
        }
    }

    public static function quranTestsRequireMemorizationProgress(): bool
    {
        if (! app(CurrentModuleAccess::class)->enabled('memorization')) {
            return false;
        }

        return (bool) (AppSetting::groupValues('quran_tests')->get('require_memorization_progress') ?? true);
    }

    public static function ensureActivitiesEnabled(): void
    {
        if (! self::activitiesEnabled()) {
            throw ValidationException::withMessages([
                'feature' => __('settings.organization.features.activities_disabled'),
            ]);
        }
    }
}

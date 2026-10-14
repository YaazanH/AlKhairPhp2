<?php

namespace App\Support;

class PlatformFeaturePresentation
{
    public function name(string $code): string
    {
        return trans()->has('platform-features.names.'.$code)
            ? __('platform-features.names.'.$code)
            : config('modules.definitions.'.$code.'.name', $code);
    }

    public function group(string $code): string
    {
        return match ($code) {
            'students', 'parents', 'parent_portal', 'teachers' => 'people',
            'classes', 'student_attendance', 'teacher_attendance', 'memorization', 'quran_tests', 'assessments', 'curriculum' => 'learning',
            'finance', 'student_billing' => 'finance',
            'points_rewards', 'activities' => 'engagement',
            default => 'tools',
        };
    }
}

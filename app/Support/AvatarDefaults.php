<?php

namespace App\Support;

use App\Models\AppSetting;

class AvatarDefaults
{
    protected static ?array $paths = null;

    public static function url(string $type): ?string
    {
        $path = self::path($type);

        return $path ? '/storage/'.ltrim($path, '/') : null;
    }

    public static function path(string $type): ?string
    {
        $paths = self::paths();

        return $paths[$type] ?? $paths['user'] ?? null;
    }

    public static function forget(): void
    {
        self::$paths = null;
    }

    protected static function paths(): array
    {
        if (self::$paths !== null) {
            return self::$paths;
        }

        $settings = AppSetting::groupValues('media');

        self::$paths = [
            'user' => $settings->get('default_user_avatar_path'),
            'student' => $settings->get('default_student_avatar_path'),
            'teacher' => $settings->get('default_teacher_avatar_path'),
            'parent' => $settings->get('default_parent_avatar_path'),
        ];

        return self::$paths;
    }
}

<?php

namespace App\Support;

final class ScheduleTimeSlots
{
    public const TIMES = [
        'morning' => ['08:00', '10:00'],
        'before_noon' => ['10:30', '12:00'],
        'between_noon_afternoon' => ['12:30', '15:00'],
        'between_afternoon_sunset' => ['15:30', '18:00'],
        'between_sunset_night' => ['18:30', '20:00'],
        'after_night' => ['20:30', '22:00'],
    ];

    public static function keys(): array
    {
        return array_keys(self::TIMES);
    }

    public static function options(): array
    {
        return collect(self::keys())
            ->mapWithKeys(fn (string $slot): array => [$slot => __('schedules.group.time_slots.'.$slot)])
            ->all();
    }

    public static function times(string $slot): array
    {
        return self::TIMES[$slot] ?? self::TIMES['morning'];
    }

    public static function closest(?string $time): string
    {
        if (! $time) {
            return 'morning';
        }

        $minutes = self::minutes(substr($time, 0, 5));

        return collect(self::TIMES)
            ->sortBy(fn (array $range): int => abs(self::minutes($range[0]) - $minutes))
            ->keys()
            ->first() ?? 'morning';
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}

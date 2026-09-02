<?php

namespace App\Support;

use Carbon\CarbonInterface;
use IntlDateFormatter;

class ArabicMonthFormatter
{
    private const MONTHS = [
        1 => 'كانون الثاني',
        2 => 'شباط',
        3 => 'آذار',
        4 => 'نيسان',
        5 => 'أيار',
        6 => 'حزيران',
        7 => 'تموز',
        8 => 'آب',
        9 => 'أيلول',
        10 => 'تشرين الأول',
        11 => 'تشرين الثاني',
        12 => 'كانون الأول',
    ];

    private const HIJRI_MONTHS = [
        1 => 'محرم',
        2 => 'صفر',
        3 => 'ربيع الأول',
        4 => 'ربيع الآخر',
        5 => 'جمادى الأولى',
        6 => 'جمادى الآخرة',
        7 => 'رجب',
        8 => 'شعبان',
        9 => 'رمضان',
        10 => 'شوال',
        11 => 'ذو القعدة',
        12 => 'ذو الحجة',
    ];

    public static function monthYear(CarbonInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }

    public static function hijriMonthYear(CarbonInterface $date): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            $formatter = new IntlDateFormatter(
                'ar@calendar=islamic',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                $date->getTimezone()->getName(),
                IntlDateFormatter::TRADITIONAL,
                'MMMM yyyy',
            );
            $formatted = $formatter->format($date->getTimestamp());

            if (is_string($formatted) && trim($formatted) !== '') {
                return trim($formatted);
            }
        }

        [$month, $year] = self::civilHijriMonthAndYear($date);

        return self::HIJRI_MONTHS[$month].' '.$year;
    }

    public static function monthYearWithHijri(CarbonInterface $date): string
    {
        return self::easternArabicNumerals(
            self::hijriMonthYear($date).'هـ - '.self::monthYear($date).' مـ'
        );
    }

    private static function easternArabicNumerals(string $value): string
    {
        return strtr($value, [
            '0' => '٠',
            '1' => '١',
            '2' => '٢',
            '3' => '٣',
            '4' => '٤',
            '5' => '٥',
            '6' => '٦',
            '7' => '٧',
            '8' => '٨',
            '9' => '٩',
        ]);
    }

    /** @return array{0: int, 1: int} */
    private static function civilHijriMonthAndYear(CarbonInterface $date): array
    {
        $gregorianYear = (int) $date->format('Y');
        $gregorianMonth = (int) $date->format('n');
        $gregorianDay = (int) $date->format('j');
        $adjustment = intdiv(14 - $gregorianMonth, 12);
        $year = $gregorianYear + 4800 - $adjustment;
        $month = $gregorianMonth + (12 * $adjustment) - 3;
        $julianDay = $gregorianDay
            + intdiv((153 * $month) + 2, 5)
            + (365 * $year)
            + intdiv($year, 4)
            - intdiv($year, 100)
            + intdiv($year, 400)
            - 32045;
        $remainingDays = $julianDay - 1948440 + 10632;
        $cycle = intdiv($remainingDays - 1, 10631);
        $remainingDays = $remainingDays - (10631 * $cycle) + 354;
        $yearAdjustment = intdiv(10985 - $remainingDays, 5316) * intdiv(50 * $remainingDays, 17719)
            + intdiv($remainingDays, 5670) * intdiv(43 * $remainingDays, 15238);
        $remainingDays = $remainingDays
            - intdiv(30 - $yearAdjustment, 15) * intdiv(17719 * $yearAdjustment, 50)
            - intdiv($yearAdjustment, 16) * intdiv(15238 * $yearAdjustment, 43)
            + 29;
        $hijriMonth = intdiv(24 * $remainingDays, 709);
        $hijriYear = (30 * $cycle) + $yearAdjustment - 30;

        return [$hijriMonth, $hijriYear];
    }
}

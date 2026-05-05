<?php

namespace App\Support;

use Carbon\CarbonInterface;

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

    public static function monthYear(CarbonInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }
}

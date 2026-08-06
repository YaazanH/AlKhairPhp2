<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class PhoneNumberFormatter
{
    public const DEFAULT_REGION = 'SY';

    public static function normalize(mixed $value, string $defaultRegion = self::DEFAULT_REGION): ?string
    {
        $number = self::parse($value, $defaultRegion);

        return $number
            ? PhoneNumberUtil::getInstance()->format($number, PhoneNumberFormat::E164)
            : null;
    }

    public static function normalizeOrOriginal(mixed $value, string $defaultRegion = self::DEFAULT_REGION): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return self::normalize($value, $defaultRegion) ?? trim((string) $value);
    }

    public static function format(mixed $value, string $defaultRegion = self::DEFAULT_REGION): ?string
    {
        $number = self::parse($value, $defaultRegion);

        if (! $number) {
            return blank($value) ? null : trim((string) $value);
        }

        $phoneUtil = PhoneNumberUtil::getInstance();
        $national = $phoneUtil->getNationalSignificantNumber($number);

        if ($number->getCountryCode() === 1 && strlen($national) === 10) {
            return sprintf(
                '+1 (%s) %s-%s',
                substr($national, 0, 3),
                substr($national, 3, 3),
                substr($national, 6, 4),
            );
        }

        return $phoneUtil->format($number, PhoneNumberFormat::INTERNATIONAL);
    }

    /** @return array{region: string, dial_code: string, national_number: string, formatted: string}|null */
    public static function split(mixed $value, string $defaultRegion = self::DEFAULT_REGION): ?array
    {
        $number = self::parse($value, $defaultRegion);

        if (! $number) {
            return null;
        }

        $phoneUtil = PhoneNumberUtil::getInstance();

        return [
            'region' => $phoneUtil->getRegionCodeForNumber($number) ?: strtoupper($defaultRegion),
            'dial_code' => '+'.$number->getCountryCode(),
            'national_number' => $phoneUtil->getNationalSignificantNumber($number),
            'formatted' => self::format($value, $defaultRegion) ?? '',
        ];
    }

    protected static function parse(mixed $value, string $defaultRegion): ?PhoneNumber
    {
        if (! filled($value)) {
            return null;
        }

        $phone = strtr(trim((string) $value), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $phone = preg_replace('/^00/', '+', $phone) ?? $phone;

        if (! preg_match('/\d/', $phone)) {
            return null;
        }

        try {
            return PhoneNumberUtil::getInstance()->parse($phone, strtoupper($defaultRegion));
        } catch (NumberParseException) {
            return null;
        }
    }
}

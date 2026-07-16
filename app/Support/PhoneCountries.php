<?php

namespace App\Support;

use Giggsey\Locale\Locale;
use Illuminate\Support\Collection;
use libphonenumber\PhoneNumberUtil;

class PhoneCountries
{
    public static function options(): Collection
    {
        static $options = [];

        $locale = app()->getLocale();

        if (isset($options[$locale])) {
            return $options[$locale];
        }

        self::ensurePackageAutoloaded();
        $phoneUtil = PhoneNumberUtil::getInstance();

        return $options[$locale] = collect($phoneUtil->getSupportedRegions())
            ->map(function (string $region) use ($locale, $phoneUtil): array {
                $name = Locale::getDisplayRegion('-'.$region, $locale) ?: $region;

                return [
                    'dial_code' => '+'.$phoneUtil->getCountryCodeForRegion($region),
                    'flag' => self::flag($region),
                    'name' => $name,
                    'region' => $region,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    protected static function flag(string $region): string
    {
        return collect(mb_str_split(strtoupper($region)))
            ->map(fn (string $letter): string => mb_chr(127397 + ord($letter)))
            ->implode('');
    }

    protected static function ensurePackageAutoloaded(): void
    {
        if (class_exists(PhoneNumberUtil::class)) {
            return;
        }

        $prefixes = [
            'libphonenumber\\' => base_path('vendor/giggsey/libphonenumber-for-php/src'),
            'Giggsey\\Locale\\' => base_path('vendor/giggsey/locale/src'),
        ];

        spl_autoload_register(static function (string $class) use ($prefixes): void {
            foreach ($prefixes as $prefix => $directory) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $file = $directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }
}

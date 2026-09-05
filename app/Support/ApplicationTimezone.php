<?php

namespace App\Support;

use App\Models\AppSetting;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ApplicationTimezone
{
    public const DEFAULT = 'UTC';

    /**
     * A concise list of world capitals and major cities for the settings UI.
     * The identifiers remain IANA values so daylight-saving changes stay accurate.
     *
     * @var array<int, string>
     */
    private const SELECTABLE_TIMEZONES = [
        'GMT',
        'Pacific/Pago_Pago',
        'Pacific/Honolulu',
        'America/Anchorage',
        'America/Vancouver',
        'America/Los_Angeles',
        'America/Edmonton',
        'America/Denver',
        'America/Phoenix',
        'America/Guatemala',
        'America/Mexico_City',
        'America/Chicago',
        'America/Costa_Rica',
        'America/Panama',
        'America/Bogota',
        'America/Lima',
        'America/Toronto',
        'America/New_York',
        'America/Havana',
        'America/Caracas',
        'America/Halifax',
        'America/Santiago',
        'America/St_Johns',
        'America/Sao_Paulo',
        'America/Buenos_Aires',
        'America/Montevideo',
        'America/Nuuk',
        'Atlantic/Cape_Verde',
        'Europe/Lisbon',
        'Europe/London',
        'Europe/Dublin',
        'Africa/Casablanca',
        'Africa/Algiers',
        'Africa/Tunis',
        'Europe/Madrid',
        'Europe/Paris',
        'Europe/Brussels',
        'Europe/Amsterdam',
        'Europe/Berlin',
        'Europe/Rome',
        'Europe/Zurich',
        'Europe/Vienna',
        'Europe/Prague',
        'Europe/Warsaw',
        'Europe/Stockholm',
        'Europe/Oslo',
        'Europe/Copenhagen',
        'Europe/Belgrade',
        'Europe/Budapest',
        'Africa/Lagos',
        'Africa/Kinshasa',
        'Europe/Athens',
        'Europe/Helsinki',
        'Europe/Bucharest',
        'Europe/Kyiv',
        'Europe/Sofia',
        'Africa/Cairo',
        'Africa/Johannesburg',
        'Africa/Khartoum',
        'Asia/Damascus',
        'Asia/Beirut',
        'Asia/Amman',
        'Asia/Jerusalem',
        'Asia/Baghdad',
        'Asia/Riyadh',
        'Asia/Kuwait',
        'Asia/Qatar',
        'Asia/Bahrain',
        'Asia/Aden',
        'Europe/Istanbul',
        'Europe/Moscow',
        'Africa/Nairobi',
        'Africa/Addis_Ababa',
        'Asia/Tehran',
        'Asia/Dubai',
        'Asia/Muscat',
        'Asia/Baku',
        'Asia/Tbilisi',
        'Asia/Yerevan',
        'Asia/Kabul',
        'Asia/Karachi',
        'Asia/Tashkent',
        'Asia/Kolkata',
        'Asia/Colombo',
        'Asia/Kathmandu',
        'Asia/Dhaka',
        'Asia/Thimphu',
        'Asia/Yangon',
        'Asia/Bangkok',
        'Asia/Jakarta',
        'Asia/Ho_Chi_Minh',
        'Asia/Singapore',
        'Asia/Kuala_Lumpur',
        'Asia/Manila',
        'Asia/Hong_Kong',
        'Asia/Shanghai',
        'Asia/Taipei',
        'Australia/Perth',
        'Asia/Tokyo',
        'Asia/Seoul',
        'Australia/Darwin',
        'Australia/Adelaide',
        'Australia/Brisbane',
        'Australia/Sydney',
        'Australia/Melbourne',
        'Pacific/Port_Moresby',
        'Pacific/Noumea',
        'Pacific/Auckland',
        'Pacific/Fiji',
        'Pacific/Apia',
        'Pacific/Tongatapu',
        'Pacific/Kiritimati',
    ];

    public function applyConfigured(): string
    {
        return $this->apply($this->configured());
    }

    public function apply(?string $timezone): string
    {
        $timezone = $this->normalize($timezone);

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        return $timezone;
    }

    public function configured(): string
    {
        $fallback = $this->normalize((string) config('app.timezone', self::DEFAULT));

        try {
            if (! Schema::hasTable((new AppSetting)->getTable())) {
                return $fallback;
            }

            return $this->normalize(
                AppSetting::query()
                    ->where('group', 'general')
                    ->where('key', 'school_timezone')
                    ->value('value'),
                $fallback,
            );
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array<int, array{value: string, label: string, search: string, offset: int, location: string, utc_offset: string}>
     */
    public function options(?string $locale = null, ?DateTimeImmutable $at = null): array
    {
        $locale ??= app()->getLocale();
        $at ??= new DateTimeImmutable('now');
        $options = collect(self::SELECTABLE_TIMEZONES)
            ->map(function (string $identifier) use ($at, $locale): array {
                $timezone = new DateTimeZone($identifier);
                $offset = $timezone->getOffset($at);
                $location = $this->localizedCityName($identifier, $locale);
                $utcOffset = $this->formatOffset($offset);
                $utc = 'UTC'.$utcOffset;

                return [
                    'value' => $identifier,
                    'label' => sprintf('%s (%s)', $location, $utc),
                    'search' => trim($location.' '.str_replace(['_', '/'], ' ', $identifier)),
                    'offset' => $offset,
                    'location' => $location,
                    'utc_offset' => $utcOffset,
                ];
            })
            ->sort(static fn (array $left, array $right): int => [$left['offset'], $left['label']] <=> [$right['offset'], $right['label']])
            ->values()
            ->all();

        return $options;
    }

    public function normalize(?string $timezone, string $fallback = self::DEFAULT): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return $timezone;
        }

        return in_array($fallback, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            ? $fallback
            : self::DEFAULT;
    }

    private function formatOffset(int $offset): string
    {
        $absoluteOffset = abs($offset);

        return sprintf(
            '%s%02d:%02d',
            $offset < 0 ? '-' : '+',
            intdiv($absoluteOffset, 3600),
            intdiv($absoluteOffset % 3600, 60),
        );
    }

    private function localizedCityName(string $identifier, string $locale): string
    {
        if ($identifier === self::DEFAULT) {
            return str_starts_with($locale, 'ar') ? 'التوقيت العالمي' : 'UTC';
        }

        if ($identifier === 'GMT') {
            return str_starts_with($locale, 'ar') ? 'غرينيتش' : 'GMT';
        }

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $identifier,
                \IntlDateFormatter::GREGORIAN,
                'VVV',
            );
            $name = $formatter->format(new DateTimeImmutable('now', new DateTimeZone($identifier)));

            if (is_string($name) && filled($name) && ! str_contains($name, 'غير معروفة')) {
                return $name;
            }
        }

        return str_replace('_', ' ', str($identifier)->afterLast('/')->toString());
    }
}

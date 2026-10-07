<?php

namespace App\Support;

class AddressFormatter
{
    /** Public place names only; never use another family's residential details as suggestions. */
    public static function locations(): array
    {
        return [
            'دمشق' => [
                'أبو رمانة' => ['ابو رمانة', 'أبو رمانه', 'ابو رمانه'],
                'المهاجرين' => ['مهاجرين', 'المهارجين', 'مهارجين', 'مهاجىين', 'المهاجلرين', 'المهاجري'],
                'المالكي' => ['مالكي'],
                'المزة' => ['مزة'],
                'كفرسوسة' => ['كفر سوسة'],
                'ركن الدين' => [],
                'الجسر الأبيض' => ['الجسر الابيض', 'جسر الابيض', 'جسر الأبيض'],
                'العفيف' => ['عفيف'],
                'الصالحية' => [],
                'الميسات' => ['ميسات'],
                'دمر' => [],
                'مشروع دمر' => [],
                'القصاع' => [],
                'القصور' => [],
                'القابون' => ['قابون'],
                'برزة' => [],
                'الميدان' => [],
                'الشاغور' => [],
                'ساروجة' => [],
                'القنوات' => [],
                'الصناعة' => [],
                'الزاهرة الجديدة' => ['زاهرة الجديدة'],
            ],
        ];
    }

    public static function suggestions(): array
    {
        $suggestions = [];
        foreach (self::locations() as $city => $areas) {
            foreach ($areas as $area => $aliases) {
                $suggestions[] = ['value' => "$city - $area", 'aliases' => $aliases];
            }
        }

        return $suggestions;
    }

    public static function normalize(?string $address): ?string
    {
        $address = self::clean($address ?? '');
        if ($address === '') {
            return null;
        }

        foreach (self::locations() as $city => $areas) {
            $remainder = self::removePrefix($address, $city);
            $hasCity = $remainder !== null;
            $remainder = $hasCity ? $remainder : $address;
            foreach ($areas as $area => $aliases) {
                foreach ([$area, ...$aliases] as $alias) {
                    $details = self::removePrefix($remainder, $alias);
                    if ($details !== null) {
                        return implode(' - ', array_filter([$city, $area, $details], fn ($part) => $part !== ''));
                    }
                }
            }
        }

        // Unknown cities, landmarks and foreign addresses retain all their information.
        return $address;
    }

    public static function hasRecognizedArea(?string $address): bool
    {
        $normalized = self::normalize($address);
        foreach (self::suggestions() as $suggestion) {
            if ($normalized === $suggestion['value'] || str_starts_with($normalized ?? '', $suggestion['value'].' - ')) {
                return true;
            }
        }

        return false;
    }

    private static function clean(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        // Preserve numeric ranges, apartment numbers, slashes and landmarks verbatim.
        return preg_replace('/(?<!\d)\s*[-–—]+\s*(?!\d)|\s+[-–—]+\s+/u', ' - ', $value) ?? $value;
    }

    private static function removePrefix(string $value, string $prefix): ?string
    {
        if (! preg_match('/^'.preg_quote($prefix, '/').'(?:\s*-\s*|\s+|(?=\()|$)(.*)$/u', $value, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }
}

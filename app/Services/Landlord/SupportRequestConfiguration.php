<?php

namespace App\Services\Landlord;

use App\Models\Landlord\SaasPlatformSetting;
use Illuminate\Support\Arr;

class SupportRequestConfiguration
{
    public const REASONS = 'reasons';

    public const PRIORITIES = 'priorities';

    public const IMPACTS = 'impacts';

    public static function defaults(): array
    {
        return [
            self::REASONS => [
                self::option('technical_issue', 'Technical issue', 'مشكلة تقنية'),
                self::option('access_permissions', 'Access or permissions', 'الدخول أو الصلاحيات'),
                self::option('incorrect_data', 'Incorrect or missing data', 'بيانات خاطئة أو مفقودة'),
                self::option('performance', 'Slow performance', 'بطء في الأداء'),
                self::option('other', 'Other', 'سبب آخر'),
            ],
            self::PRIORITIES => [
                self::option('normal', 'Normal', 'عادية'),
                self::option('high', 'High', 'عالية'),
                self::option('critical', 'Critical', 'حرجة'),
            ],
            self::IMPACTS => [
                self::option('individual', 'Only me or one person', 'أنا فقط أو شخص واحد'),
                self::option('several_users', 'A group or several people', 'مجموعة أو عدة أشخاص'),
                self::option('all_users', 'Most or all users', 'معظم المستخدمين أو جميعهم'),
            ],
        ];
    }

    public function raw(): array
    {
        $stored = SaasPlatformSetting::current()->support_request_options;

        return collect(self::defaults())->mapWithKeys(function (array $defaults, string $group) use ($stored): array {
            $items = Arr::get(is_array($stored) ? $stored : [], $group);

            return [$group => $this->normalize(is_array($items) ? $items : $defaults, $defaults)];
        })->all();
    }

    public function options(string $group, bool $enabledOnly = true): array
    {
        return collect($this->raw()[$group] ?? [])
            ->when($enabledOnly, fn ($items) => $items->where('enabled', true))
            ->mapWithKeys(fn (array $item): array => [$item['key'] => $this->label($item)])
            ->all();
    }

    private function normalize(array $items, array $defaults): array
    {
        $normalized = collect($items)->map(function (mixed $item): ?array {
            if (! is_array($item) || blank($item['key'] ?? null)) {
                return null;
            }

            return [
                'key' => (string) $item['key'],
                'label_en' => trim((string) ($item['label_en'] ?? '')),
                'label_ar' => trim((string) ($item['label_ar'] ?? '')),
                'enabled' => (bool) ($item['enabled'] ?? false),
            ];
        })->filter()->values()->all();

        return $normalized === [] ? $defaults : $normalized;
    }

    private function label(array $item): string
    {
        $preferred = app()->isLocale('ar') ? $item['label_ar'] : $item['label_en'];
        $fallback = app()->isLocale('ar') ? $item['label_en'] : $item['label_ar'];

        return $preferred !== '' ? $preferred : ($fallback !== '' ? $fallback : $item['key']);
    }

    private static function option(string $key, string $labelEn, string $labelAr): array
    {
        return ['key' => $key, 'label_en' => $labelEn, 'label_ar' => $labelAr, 'enabled' => true];
    }
}

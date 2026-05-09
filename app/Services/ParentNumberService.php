<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ParentProfile;

class ParentNumberService
{
    public const GROUP = 'general';

    public const PREFIX_KEY = 'parent_number_prefix';

    public const LENGTH_KEY = 'parent_number_length';

    public const DEFAULT_PREFIX = 'P';

    public const DEFAULT_LENGTH = 6;

    public function prefix(): string
    {
        $settings = AppSetting::groupValues(self::GROUP);

        return $settings->has(self::PREFIX_KEY)
            ? trim((string) $settings->get(self::PREFIX_KEY))
            : self::DEFAULT_PREFIX;
    }

    public function length(): int
    {
        $settings = AppSetting::groupValues(self::GROUP);
        $length = $settings->get(self::LENGTH_KEY);

        return is_numeric($length) ? max(0, (int) $length) : self::DEFAULT_LENGTH;
    }

    public function formatForId(int $parentId): string
    {
        $number = (string) $parentId;
        $length = $this->length();

        if ($length > 0) {
            $number = str_pad($number, $length, '0', STR_PAD_LEFT);
        }

        return $this->prefix().$number;
    }

    public function syncParent(ParentProfile $parent): void
    {
        $expectedParentNumber = $this->formatForId((int) $parent->id);

        if ($parent->parent_number !== $expectedParentNumber) {
            $parent->forceFill([
                'parent_number' => $expectedParentNumber,
            ])->saveQuietly();

            $parent->parent_number = $expectedParentNumber;
        }

        $this->syncLinkedUsername($parent);
    }

    public function syncAll(): void
    {
        ParentProfile::query()
            ->with('user')
            ->orderBy('id')
            ->chunkById(200, function ($parents): void {
                foreach ($parents as $parent) {
                    $this->syncParent($parent);
                }
            });
    }

    protected function syncLinkedUsername(ParentProfile $parent): void
    {
        if (! $parent->user_id || blank($parent->parent_number)) {
            return;
        }

        $user = $parent->relationLoaded('user')
            ? $parent->user
            : $parent->user()->first();

        if (! $user) {
            return;
        }

        $user->forceFill([
            'name' => $parent->father_name ?: $user->name,
            'username' => app(ManagedUserService::class)->uniqueUsername($parent->parent_number, $user->name, $user->id),
            'phone' => $parent->father_phone ?: ($parent->mother_phone ?: ($parent->home_phone ?: null)),
        ])->save();
    }
}

<?php

namespace App\Models\Landlord;

class SaasPlatformSetting extends LandlordModel
{
    protected $fillable = [
        'suspended_data_retention_months',
    ];

    protected function casts(): array
    {
        return [
            'suspended_data_retention_months' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'suspended_data_retention_months' => 12,
        ]);
    }
}

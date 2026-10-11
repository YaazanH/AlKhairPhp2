<?php

namespace App\Models\Landlord;

class SaasPlatformSetting extends LandlordModel
{
    protected $fillable = [
        'suspended_data_retention_months',
        'support_request_options',
        'metrics_refresh_minutes',
    ];

    protected function casts(): array
    {
        return [
            'suspended_data_retention_months' => 'integer',
            'support_request_options' => 'array',
            'metrics_refresh_minutes' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'suspended_data_retention_months' => 12,
            'metrics_refresh_minutes' => 5,
        ]);
    }
}

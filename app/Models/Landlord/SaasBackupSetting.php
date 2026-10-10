<?php

namespace App\Models\Landlord;

class SaasBackupSetting extends LandlordModel
{
    protected $fillable = [
        'is_enabled',
        'run_at',
        'retention_count',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'retention_count' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'is_enabled' => true,
            'run_at' => '02:00:00',
            'retention_count' => 30,
        ]);
    }
}

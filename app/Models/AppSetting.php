<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
    ];

    public static function groupValues(string $group): Collection
    {
        return static::query()
            ->where('group', $group)
            ->get()
            ->mapWithKeys(fn (self $setting) => [$setting->key => $setting->castValue()]);
    }

    public static function storeValue(string $group, string $key, mixed $value, string $type = 'string'): self
    {
        return static::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'type' => $type,
                'value' => match ($type) {
                    'array', 'json' => blank($value) ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'boolean' => $value ? '1' : '0',
                    default => blank($value) ? null : (string) $value,
                },
            ],
        );
    }

    public function castValue(): mixed
    {
        return match ($this->type) {
            'array', 'json' => blank($this->value) ? [] : json_decode($this->value, true) ?? [],
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            'integer' => blank($this->value) ? null : (int) $this->value,
            'float' => blank($this->value) ? null : (float) $this->value,
            default => $this->value,
        };
    }
}

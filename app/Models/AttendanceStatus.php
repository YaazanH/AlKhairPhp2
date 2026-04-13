<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'scope',
        'default_points',
        'color',
        'is_present',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_points' => 'integer',
            'is_present' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteMenu extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'title',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'settings' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WebsiteMenuItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}

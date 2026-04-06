<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteMenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_menu_id',
        'parent_id',
        'website_page_id',
        'label',
        'url',
        'sort_order',
        'is_active',
        'open_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'is_active' => 'boolean',
            'open_in_new_tab' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(WebsiteMenu::class, 'website_menu_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'website_page_id');
    }

    public function localizedLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'en');
        $value = $this->label;

        if (is_array($value)) {
            $label = (string) ($value[$locale]
                ?? $value[$fallbackLocale]
                ?? collect($value)->filter(fn (mixed $item) => is_string($item) && filled($item))->first()
                ?? '');

            if (filled($label)) {
                return $label;
            }
        }

        return $this->page?->localizedText('navigation_label', $locale)
            ?: $this->page?->localizedText('title', $locale)
            ?: '';
    }
}

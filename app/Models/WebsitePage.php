<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsitePage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'template',
        'title',
        'navigation_label',
        'excerpt',
        'body',
        'sections',
        'settings',
        'seo_title',
        'seo_description',
        'hero_media_path',
        'is_home',
        'is_published',
        'show_in_navigation',
        'navigation_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'navigation_label' => 'array',
            'excerpt' => 'array',
            'body' => 'array',
            'sections' => 'array',
            'settings' => 'array',
            'seo_title' => 'array',
            'seo_description' => 'array',
            'is_home' => 'boolean',
            'is_published' => 'boolean',
            'show_in_navigation' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function localizedText(string $attribute, ?string $locale = null, string $fallback = ''): string
    {
        $locale ??= app()->getLocale();
        $fallbackLocale = config('app.fallback_locale', 'en');
        $value = $this->getAttribute($attribute);

        if (! is_array($value)) {
            return is_string($value) ? $value : $fallback;
        }

        return (string) ($value[$locale]
            ?? $value[$fallbackLocale]
            ?? collect($value)->filter(fn (mixed $item) => is_string($item) && filled($item))->first()
            ?? $fallback);
    }

    public function localizedSections(?string $locale = null): array
    {
        return $this->translateValue($this->sections ?? [], $locale ?? app()->getLocale());
    }

    protected function translateValue(mixed $value, string $locale): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isLocalizedDictionary($value)) {
            $fallbackLocale = config('app.fallback_locale', 'en');

            return $value[$locale]
                ?? $value[$fallbackLocale]
                ?? collect($value)->filter(fn (mixed $item) => filled($item))->first()
                ?? null;
        }

        return array_map(fn (mixed $item) => $this->translateValue($item, $locale), $value);
    }

    protected function isLocalizedDictionary(array $value): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }

        $supportedLocales = array_keys(config('app.supported_locales', []));

        return collect(array_keys($value))
            ->every(fn (string|int $key) => is_string($key) && in_array($key, $supportedLocales, true));
    }
}

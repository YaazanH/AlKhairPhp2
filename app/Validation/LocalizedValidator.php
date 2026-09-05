<?php

namespace App\Validation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class LocalizedValidator extends Validator
{
    /**
     * Keep internal property names out of Arabic validation messages.
     */
    public function getDisplayableAttribute($attribute): string
    {
        $displayable = parent::getDisplayableAttribute($attribute);

        if ($this->translator->getLocale() !== 'ar' || ! $this->isRawAttributeName($attribute, $displayable)) {
            return $displayable;
        }

        return $this->translatedAttributeSuffix($attribute)
            ?? (string) $this->translator->get('validation.generic_attribute');
    }

    private function isRawAttributeName(string $attribute, string $displayable): bool
    {
        return $displayable === $attribute
            || $displayable === str_replace('_', ' ', Str::snake($attribute));
    }

    private function translatedAttributeSuffix(string $attribute): ?string
    {
        $normalized = collect(explode('.', $attribute))
            ->reject(fn (string $segment): bool => $segment === '*' || ctype_digit($segment))
            ->map(fn (string $segment): string => Str::snake($segment))
            ->implode('_');

        $attributes = Arr::dot((array) $this->translator->get('validation.attributes'));
        $matchingKey = collect(array_keys($attributes))
            ->filter(fn (string $key): bool => ! str_contains($key, '*'))
            ->filter(fn (string $key): bool => $normalized === $key || str_ends_with($normalized, '_'.$key))
            ->sortByDesc(fn (string $key): int => strlen($key))
            ->first();

        return is_string($matchingKey) && is_string($attributes[$matchingKey] ?? null)
            ? $attributes[$matchingKey]
            : null;
    }
}

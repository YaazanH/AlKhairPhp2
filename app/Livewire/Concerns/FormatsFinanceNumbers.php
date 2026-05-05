<?php

namespace App\Livewire\Concerns;

trait FormatsFinanceNumbers
{
    protected function normalizeFinanceNumber(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace([',', ' ', "\u{00A0}", "\u{066C}", "\u{060C}"], '', trim((string) $value));
    }

    protected function normalizeFinanceNumberProperty(string $property): void
    {
        $this->{$property} = $this->normalizeFinanceNumber($this->{$property} ?? '');
    }

    protected function normalizeFinanceNumberArrayValue(string $property, int|string $key): void
    {
        $values = $this->{$property} ?? [];

        if (! is_array($values) || ! array_key_exists($key, $values)) {
            return;
        }

        $values[$key] = $this->normalizeFinanceNumber($values[$key]);
        $this->{$property} = $values;
    }

    protected function formatFinanceNumberForInput(mixed $value, int $decimals = 2, bool $trimTrailingZeros = false): string
    {
        $normalized = $this->normalizeFinanceNumber($value);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return $normalized;
        }

        $formatted = number_format((float) $normalized, $decimals, '.', ',');

        return $trimTrailingZeros ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}

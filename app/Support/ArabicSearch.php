<?php

namespace App\Support;

class ArabicSearch
{
    public static function normalize(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return strtr($normalized, self::replacements());
    }

    public static function normalizeForDuplicate(string $value): string
    {
        $normalized = self::normalize($value);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';
        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_map(function (string $token): string {
            $token = preg_replace('/^\x{0627}\x{0644}/u', '', $token) ?? $token;

            return $token !== '' ? $token : $token;
        }, $tokens);

        return trim(implode(' ', array_filter($tokens, fn (string $token): bool => $token !== '')));
    }

    public static function normalizedSqlExpression(string $expression, string $driver): string
    {
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            return $expression;
        }

        foreach (self::replacements() as $from => $to) {
            $expression = "replace($expression, '$from', '$to')";
        }

        return "trim(replace(replace(replace($expression, '  ', ' '), '  ', ' '), '  ', ' '))";
    }

    public static function concatWithSpaces(array $columns, string $driver): string
    {
        $wrappedColumns = array_map(fn (string $column) => "coalesce($column, '')", $columns);

        return $driver === 'sqlite'
            ? implode(" || ' ' || ", $wrappedColumns)
            : 'concat_ws(\' \', '.implode(', ', $wrappedColumns).')';
    }

    protected static function replacements(): array
    {
        return [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
            'ة' => 'ه',
            'ء' => '',
            'ـ' => '',
            'ً' => '',
            'ٌ' => '',
            'ٍ' => '',
            'َ' => '',
            'ُ' => '',
            'ِ' => '',
            'ّ' => '',
            'ْ' => '',
        ];
    }
}

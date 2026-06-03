<?php

namespace App\Support;

class ArabicSearch
{
    public static function normalize(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return strtr($normalized, self::replacements());
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

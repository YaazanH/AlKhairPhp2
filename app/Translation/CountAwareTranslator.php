<?php

namespace App\Translation;

use Illuminate\Translation\Translator;

/** Let existing __() count labels use the same explicit plural forms as trans_choice(). */
class CountAwareTranslator extends Translator
{
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $locale = $locale ?: $this->getLocale();
        if (array_key_exists('count', $replace)) {
            $line = parent::get($key, [], $locale, $fallback);
            $number = $this->numericCount($replace['count']);
            if ($number !== null && is_string($line) && preg_match('/^(?:\{[^}]+\}|\[[^]]+\])/', $line)) {
                return $this->makeReplacements($this->getSelector()->choose($line, $number, $locale), $replace);
            }
        }

        return parent::get($key, $replace, $locale, $fallback);
    }

    private function numericCount(mixed $value): int|float|null
    {
        if (! is_scalar($value)) {
            return null;
        }
        $number = strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ',' => '', '٬' => '', '٫' => '.',
        ]);

        return is_numeric($number) ? $number + 0 : null;
    }
}

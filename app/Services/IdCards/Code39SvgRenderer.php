<?php

namespace App\Services\IdCards;

use Illuminate\Support\Str;

class Code39SvgRenderer
{
    protected array $patterns = [
        '0' => 'nnnwwnwnn',
        '1' => 'wnnwnnnnw',
        '2' => 'nnwwnnnnw',
        '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw',
        'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn',
        'D' => 'nnnnwwnnw',
        'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw',
        'H' => 'wnnnnwwnn',
        'I' => 'nnwnnwwnn',
        'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww',
        'L' => 'nnwnnnnww',
        'M' => 'wnwnnnnwn',
        'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn',
        'Q' => 'nnnnnnwww',
        'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn',
        'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw',
        'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn',
        'X' => 'nwnnwnnnw',
        'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw',
        '.' => 'wwnnnnwnn',
        ' ' => 'nwwnnnwnn',
        '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn',
        '+' => 'nwnnnwnwn',
        '%' => 'nnnwnwnwn',
        '*' => 'nwnnwnwnn',
    ];

    public function render(string $value, array $options = []): ?string
    {
        $encodedValue = $this->sanitize($value);

        if ($encodedValue === '') {
            return null;
        }

        $width = max((float) ($options['width'] ?? 36), 12);
        $height = max((float) ($options['height'] ?? 12), 8);
        $showText = (bool) ($options['show_text'] ?? true);
        $wideFactor = 2.5;
        $quietZone = 10;

        $sequence = '*'.$encodedValue.'*';
        $units = $quietZone * 2;

        foreach (str_split($sequence) as $character) {
            $pattern = $this->patterns[$character] ?? $this->patterns['*'];

            foreach (str_split($pattern) as $barUnit) {
                $units += $barUnit === 'w' ? $wideFactor : 1;
            }

            $units += 1;
        }

        $moduleWidth = $width / $units;
        $barHeight = $showText ? ($height * 0.72) : $height;
        $textHeight = $showText ? max($height - $barHeight, 2.8) : 0;
        $cursor = $quietZone * $moduleWidth;
        $isBar = true;
        $rects = [];

        foreach (str_split($sequence) as $character) {
            $pattern = $this->patterns[$character] ?? $this->patterns['*'];

            foreach (str_split($pattern) as $barUnit) {
                $segmentWidth = ($barUnit === 'w' ? $wideFactor : 1) * $moduleWidth;

                if ($isBar) {
                    $rects[] = sprintf(
                        '<rect x="%s" y="0" width="%s" height="%s" rx="0.18" ry="0.18" />',
                        $this->format($cursor),
                        $this->format($segmentWidth),
                        $this->format($barHeight),
                    );
                }

                $cursor += $segmentWidth;
                $isBar = ! $isBar;
            }

            $cursor += $moduleWidth;
            $isBar = true;
        }

        $textNode = '';

        if ($showText) {
            $fontSize = max((float) ($options['font_size'] ?? 2.8), 2.4);
            $textNode = sprintf(
                '<text x="%s" y="%s" font-size="%s" text-anchor="middle" font-family="system-ui, sans-serif" fill="currentColor">%s</text>',
                $this->format($width / 2),
                $this->format($barHeight + ($textHeight * 0.8)),
                $this->format($fontSize),
                e($encodedValue),
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="100%%" height="100%%" preserveAspectRatio="none" role="img" aria-label="%3$s" fill="currentColor">%4$s%5$s</svg>',
            $this->format($width),
            $this->format($height),
            e($encodedValue),
            implode('', $rects),
            $textNode,
        );
    }

    protected function sanitize(string $value): string
    {
        $sanitized = Str::upper(trim($value));
        $allowed = implode('', array_keys($this->patterns));
        $allowed = str_replace('*', '', $allowed);

        return preg_replace('/[^'.preg_quote($allowed, '/').']/', '', $sanitized) ?: '';
    }

    protected function format(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}

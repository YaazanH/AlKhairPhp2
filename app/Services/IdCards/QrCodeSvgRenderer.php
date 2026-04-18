<?php

namespace App\Services\IdCards;

class QrCodeSvgRenderer
{
    private const SIZE = 21;
    private const DATA_CODEWORDS = 19;
    private const ECC_CODEWORDS = 7;

    /**
     * Version 1-L QR codes hold up to 17 bytes in byte mode, which is enough
     * for the student_number/id values used by ID cards.
     */
    public function render(string $value, array $options = []): ?string
    {
        $encodedValue = trim($value);

        if ($encodedValue === '' || strlen($encodedValue) > 17) {
            return null;
        }

        $dataCodewords = $this->dataCodewords($encodedValue);
        $codewords = array_merge($dataCodewords, $this->errorCorrectionCodewords($dataCodewords));
        [$modules, $reserved] = $this->baseMatrix();
        $this->drawCodewords($modules, $reserved, $codewords);
        $this->drawFormatBits($modules, $reserved);

        return $this->svg($modules, $encodedValue, $options);
    }

    private function dataCodewords(string $value): array
    {
        $bits = [];
        $bytes = array_values(unpack('C*', $value) ?: []);

        $this->appendBits($bits, 0b0100, 4);
        $this->appendBits($bits, count($bytes), 8);

        foreach ($bytes as $byte) {
            $this->appendBits($bits, $byte, 8);
        }

        $capacity = self::DATA_CODEWORDS * 8;
        $terminatorLength = min(4, max($capacity - count($bits), 0));
        $this->appendBits($bits, 0, $terminatorLength);

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];

        foreach (array_chunk($bits, 8) as $chunk) {
            $codeword = 0;

            foreach ($chunk as $bit) {
                $codeword = ($codeword << 1) | $bit;
            }

            $codewords[] = $codeword;
        }

        for ($pad = 0; count($codewords) < self::DATA_CODEWORDS; $pad++) {
            $codewords[] = $pad % 2 === 0 ? 0xec : 0x11;
        }

        return $codewords;
    }

    private function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private function errorCorrectionCodewords(array $dataCodewords): array
    {
        $generator = [87, 229, 146, 149, 238, 102, 21];
        $remainder = array_fill(0, self::ECC_CODEWORDS, 0);

        foreach ($dataCodewords as $codeword) {
            $factor = $codeword ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;

            foreach ($generator as $index => $coefficient) {
                $remainder[$index] ^= $this->gfMultiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    private function gfMultiply(int $a, int $b): int
    {
        $product = 0;

        for ($i = 0; $i < 8; $i++) {
            if (($b & 1) !== 0) {
                $product ^= $a;
            }

            $carry = ($a & 0x80) !== 0;
            $a = ($a << 1) & 0xff;

            if ($carry) {
                $a ^= 0x1d;
            }

            $b >>= 1;
        }

        return $product;
    }

    private function baseMatrix(): array
    {
        $modules = array_fill(0, self::SIZE, array_fill(0, self::SIZE, null));
        $reserved = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));

        $this->drawFinder($modules, $reserved, 0, 0);
        $this->drawFinder($modules, $reserved, self::SIZE - 7, 0);
        $this->drawFinder($modules, $reserved, 0, self::SIZE - 7);
        $this->drawTiming($modules, $reserved);
        $this->reserveFormatAreas($reserved);
        $this->setFunctionModule($modules, $reserved, 8, self::SIZE - 8, true);

        return [$modules, $reserved];
    }

    private function drawFinder(array &$modules, array &$reserved, int $left, int $top): void
    {
        for ($y = $top - 1; $y <= $top + 7; $y++) {
            for ($x = $left - 1; $x <= $left + 7; $x++) {
                if ($this->inBounds($x, $y)) {
                    $this->setFunctionModule($modules, $reserved, $x, $y, false);
                }
            }
        }

        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 7; $x++) {
                $dark = $x === 0 || $x === 6 || $y === 0 || $y === 6 || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                $this->setFunctionModule($modules, $reserved, $left + $x, $top + $y, $dark);
            }
        }
    }

    private function drawTiming(array &$modules, array &$reserved): void
    {
        for ($i = 8; $i <= self::SIZE - 9; $i++) {
            $this->setFunctionModule($modules, $reserved, $i, 6, $i % 2 === 0);
            $this->setFunctionModule($modules, $reserved, 6, $i, $i % 2 === 0);
        }
    }

    private function reserveFormatAreas(array &$reserved): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $this->reserveModule($reserved, 8, $i);
            $this->reserveModule($reserved, $i, 8);
        }

        for ($i = self::SIZE - 8; $i < self::SIZE; $i++) {
            $this->reserveModule($reserved, 8, $i);
            $this->reserveModule($reserved, $i, 8);
        }
    }

    private function drawCodewords(array &$modules, array $reserved, array $codewords): void
    {
        $bits = [];

        foreach ($codewords as $codeword) {
            $this->appendBits($bits, $codeword, 8);
        }

        $bitIndex = 0;
        $upward = true;

        for ($right = self::SIZE - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }

            for ($vertical = 0; $vertical < self::SIZE; $vertical++) {
                $y = $upward ? self::SIZE - 1 - $vertical : $vertical;

                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;

                    if ($reserved[$y][$x]) {
                        continue;
                    }

                    $bit = $bits[$bitIndex] ?? 0;
                    $bitIndex++;

                    if (($x + $y) % 2 === 0) {
                        $bit ^= 1;
                    }

                    $modules[$y][$x] = $bit === 1;
                }
            }

            $upward = ! $upward;
        }
    }

    private function drawFormatBits(array &$modules, array &$reserved): void
    {
        $bits = $this->formatBits();

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule($modules, $reserved, 8, $i, (($bits >> $i) & 1) !== 0);
        }

        $this->setFunctionModule($modules, $reserved, 8, 7, (($bits >> 6) & 1) !== 0);
        $this->setFunctionModule($modules, $reserved, 8, 8, (($bits >> 7) & 1) !== 0);
        $this->setFunctionModule($modules, $reserved, 7, 8, (($bits >> 8) & 1) !== 0);

        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule($modules, $reserved, 14 - $i, 8, (($bits >> $i) & 1) !== 0);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($modules, $reserved, self::SIZE - 1 - $i, 8, (($bits >> $i) & 1) !== 0);
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule($modules, $reserved, 8, self::SIZE - 15 + $i, (($bits >> $i) & 1) !== 0);
        }

        $this->setFunctionModule($modules, $reserved, 8, self::SIZE - 8, true);
    }

    private function formatBits(): int
    {
        $data = 0b01000; // Error correction L, mask 0.
        $remainder = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if ((($remainder >> $i) & 1) !== 0) {
                $remainder ^= 0x537 << ($i - 10);
            }
        }

        return (($data << 10) | ($remainder & 0x3ff)) ^ 0x5412;
    }

    private function setFunctionModule(array &$modules, array &$reserved, int $x, int $y, bool $dark): void
    {
        if (! $this->inBounds($x, $y)) {
            return;
        }

        $modules[$y][$x] = $dark;
        $reserved[$y][$x] = true;
    }

    private function reserveModule(array &$reserved, int $x, int $y): void
    {
        if ($this->inBounds($x, $y)) {
            $reserved[$y][$x] = true;
        }
    }

    private function inBounds(int $x, int $y): bool
    {
        return $x >= 0 && $x < self::SIZE && $y >= 0 && $y < self::SIZE;
    }

    private function svg(array $modules, string $value, array $options): string
    {
        $width = max((float) ($options['width'] ?? 20), 10);
        $height = max((float) ($options['height'] ?? 20), 10);
        $showText = (bool) ($options['show_text'] ?? true);
        $fontSize = max((float) ($options['font_size'] ?? 2.8), 2.2);
        $textHeight = $showText ? max($fontSize * 1.35, 3.2) : 0;
        $codeHeight = max($height - $textHeight, 6);
        $boxSize = min($width, $codeHeight);
        $moduleSize = $boxSize / (self::SIZE + 8);
        $originX = ($width - $boxSize) / 2 + ($moduleSize * 4);
        $originY = $showText ? ($moduleSize * 4) : (($height - $boxSize) / 2 + ($moduleSize * 4));
        $rects = [];

        for ($y = 0; $y < self::SIZE; $y++) {
            for ($x = 0; $x < self::SIZE; $x++) {
                if (($modules[$y][$x] ?? false) !== true) {
                    continue;
                }

                $rects[] = sprintf(
                    '<rect x="%s" y="%s" width="%s" height="%s" />',
                    $this->format($originX + ($x * $moduleSize)),
                    $this->format($originY + ($y * $moduleSize)),
                    $this->format($moduleSize),
                    $this->format($moduleSize),
                );
            }
        }

        $textNode = '';

        if ($showText) {
            $textNode = sprintf(
                '<text x="%s" y="%s" font-size="%s" text-anchor="middle" font-family="system-ui, sans-serif" fill="currentColor">%s</text>',
                $this->format($width / 2),
                $this->format($height - ($textHeight * 0.25)),
                $this->format($fontSize),
                e($value),
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="100%%" height="100%%" preserveAspectRatio="xMidYMid meet" role="img" data-code-type="qrcode" aria-label="%3$s"><rect width="100%%" height="100%%" fill="#fff" /><g fill="currentColor" shape-rendering="crispEdges">%4$s</g>%5$s</svg>',
            $this->format($width),
            $this->format($height),
            e('QR code '.$value),
            implode('', $rects),
            $textNode,
        );
    }

    private function format(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}

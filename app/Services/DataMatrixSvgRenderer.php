<?php

namespace App\Services;

use InvalidArgumentException;

class DataMatrixSvgRenderer
{
    /** @var array<int, array{size:int,data:int,ecc:int,regions:int}> */
    private const SYMBOLS = [
        ['size' => 10, 'data' => 3, 'ecc' => 5, 'regions' => 1],
        ['size' => 12, 'data' => 5, 'ecc' => 7, 'regions' => 1],
        ['size' => 14, 'data' => 8, 'ecc' => 10, 'regions' => 1],
        ['size' => 16, 'data' => 12, 'ecc' => 12, 'regions' => 1],
        ['size' => 18, 'data' => 18, 'ecc' => 14, 'regions' => 1],
        ['size' => 20, 'data' => 22, 'ecc' => 18, 'regions' => 1],
        ['size' => 22, 'data' => 30, 'ecc' => 20, 'regions' => 1],
        ['size' => 24, 'data' => 36, 'ecc' => 24, 'regions' => 1],
        ['size' => 26, 'data' => 44, 'ecc' => 28, 'regions' => 1],
        ['size' => 32, 'data' => 62, 'ecc' => 36, 'regions' => 2],
        ['size' => 36, 'data' => 86, 'ecc' => 42, 'regions' => 2],
        ['size' => 40, 'data' => 114, 'ecc' => 48, 'regions' => 2],
        ['size' => 44, 'data' => 144, 'ecc' => 56, 'regions' => 2],
        ['size' => 48, 'data' => 174, 'ecc' => 68, 'regions' => 2],
    ];

    /** @var array<int, int> */
    private array $gfExp = [];

    /** @var array<int, int> */
    private array $gfLog = [];

    public function render(string $value): string
    {
        $data = $this->encodeAscii($value);
        $symbol = collect(self::SYMBOLS)->first(fn (array $candidate): bool => count($data) <= $candidate['data']);

        if (! $symbol) {
            throw new InvalidArgumentException('The invoice number is too long for the compact Data Matrix symbol.');
        }

        $data = $this->pad($data, $symbol['data']);
        $codewords = [...$data, ...$this->errorCorrection($data, $symbol['ecc'])];
        $dataSize = $symbol['size'] - ($symbol['regions'] * 2);
        $placed = $this->place($codewords, $dataSize, $dataSize);
        $modules = $this->addFinderPatterns($placed, $symbol['regions']);
        $viewSize = $symbol['size'] + 2;
        $path = '';

        foreach ($modules as $row => $columns) {
            foreach ($columns as $column => $dark) {
                if ($dark) {
                    $path .= 'M'.($column + 1).' '.($row + 1).'h1v1h-1z';
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges"><path d="%2$s" fill="#000"/></svg>',
            $viewSize,
            $path,
        );
    }

    /** @return array<int, int> */
    private function encodeAscii(string $value): array
    {
        $bytes = array_values(unpack('C*', $value) ?: []);
        $encoded = [];

        for ($index = 0, $length = count($bytes); $index < $length; $index++) {
            if ($index + 1 < $length && $bytes[$index] >= 48 && $bytes[$index] <= 57 && $bytes[$index + 1] >= 48 && $bytes[$index + 1] <= 57) {
                $encoded[] = 130 + (($bytes[$index] - 48) * 10) + ($bytes[++$index] - 48);

                continue;
            }

            if ($bytes[$index] > 127) {
                $encoded[] = 235;
                $encoded[] = $bytes[$index] - 127;

                continue;
            }

            $encoded[] = $bytes[$index] + 1;
        }

        return $encoded;
    }

    /** @param array<int, int> $data @return array<int, int> */
    private function pad(array $data, int $capacity): array
    {
        if (count($data) < $capacity) {
            $data[] = 129;
        }

        while (count($data) < $capacity) {
            $position = count($data) + 1;
            $value = 129 + ((149 * $position) % 253) + 1;
            $data[] = $value > 254 ? $value - 254 : $value;
        }

        return $data;
    }

    /** @param array<int, int> $data @return array<int, int> */
    private function errorCorrection(array $data, int $eccCount): array
    {
        $this->initializeGaloisField();
        $generator = [1];

        for ($degree = 1; $degree <= $eccCount; $degree++) {
            $next = array_fill(0, count($generator) + 1, 0);
            $root = $this->gfExp[$degree];
            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= $this->gfMultiply($coefficient, $root);
            }
            $generator = $next;
        }

        $message = [...$data, ...array_fill(0, $eccCount, 0)];
        foreach ($data as $index => $coefficient) {
            if ($coefficient === 0) {
                continue;
            }
            foreach ($generator as $offset => $factor) {
                $message[$index + $offset] ^= $this->gfMultiply($factor, $coefficient);
            }
        }

        return array_slice($message, -$eccCount);
    }

    private function initializeGaloisField(): void
    {
        if ($this->gfExp !== []) {
            return;
        }

        $value = 1;
        for ($index = 0; $index < 255; $index++) {
            $this->gfExp[$index] = $value;
            $this->gfLog[$value] = $index;
            $value <<= 1;
            if (($value & 0x100) !== 0) {
                $value ^= 0x12D;
            }
        }
        for ($index = 255; $index < 512; $index++) {
            $this->gfExp[$index] = $this->gfExp[$index - 255];
        }
    }

    private function gfMultiply(int $left, int $right): int
    {
        return $left === 0 || $right === 0 ? 0 : $this->gfExp[$this->gfLog[$left] + $this->gfLog[$right]];
    }

    /** @param array<int, int> $codewords @return array<int, array<int, bool>> */
    private function place(array $codewords, int $rows, int $columns): array
    {
        $modules = array_fill(0, $rows, array_fill(0, $columns, null));
        $position = 0;
        $row = 4;
        $column = 0;

        do {
            if ($row === $rows && $column === 0) {
                $this->cornerOne($modules, $codewords, $position++, $rows, $columns);
            }
            if ($row === $rows - 2 && $column === 0 && $columns % 4 !== 0) {
                $this->cornerTwo($modules, $codewords, $position++, $rows, $columns);
            }
            if ($row === $rows - 2 && $column === 0 && $columns % 8 === 4) {
                $this->cornerThree($modules, $codewords, $position++, $rows, $columns);
            }
            if ($row === $rows + 4 && $column === 2 && $columns % 8 === 0) {
                $this->cornerFour($modules, $codewords, $position++, $rows, $columns);
            }

            do {
                if ($row < $rows && $column >= 0 && $modules[$row][$column] === null) {
                    $this->utah($modules, $codewords, $position++, $row, $column, $rows, $columns);
                }
                $row -= 2;
                $column += 2;
            } while ($row >= 0 && $column < $columns);
            $row++;
            $column += 3;

            do {
                if ($row >= 0 && $column < $columns && $modules[$row][$column] === null) {
                    $this->utah($modules, $codewords, $position++, $row, $column, $rows, $columns);
                }
                $row += 2;
                $column -= 2;
            } while ($row < $rows && $column >= 0);
            $row += 3;
            $column++;
        } while ($row < $rows || $column < $columns);

        if ($modules[$rows - 1][$columns - 1] === null) {
            $modules[$rows - 1][$columns - 1] = true;
            $modules[$rows - 2][$columns - 2] = true;
        }

        return array_map(fn (array $line): array => array_map(fn ($bit): bool => (bool) $bit, $line), $modules);
    }

    /** @param array<int, array<int, bool|null>> $modules @param array<int, int> $codewords */
    private function module(array &$modules, array $codewords, int $position, int $bit, int $row, int $column, int $rows, int $columns): void
    {
        if ($row < 0) {
            $row += $rows;
            $column += 4 - (($rows + 4) % 8);
        }
        if ($column < 0) {
            $column += $columns;
            $row += 4 - (($columns + 4) % 8);
        }
        $modules[$row][$column] = (($codewords[$position] ?? 0) & (1 << (8 - $bit))) !== 0;
    }

    /** @param array<int, array<int, bool|null>> $m @param array<int, int> $c */
    private function utah(array &$m, array $c, int $p, int $r, int $col, int $rows, int $cols): void
    {
        foreach ([[-2, -2], [-2, -1], [-1, -2], [-1, -1], [-1, 0], [0, -2], [0, -1], [0, 0]] as $index => [$dr, $dc]) {
            $this->module($m, $c, $p, $index + 1, $r + $dr, $col + $dc, $rows, $cols);
        }
    }

    /** @param array<int, array<int, bool|null>> $m @param array<int, int> $c */
    private function cornerOne(array &$m, array $c, int $p, int $rows, int $cols): void
    {
        foreach ([[$rows - 1, 0], [$rows - 1, 1], [$rows - 1, 2], [0, $cols - 2], [0, $cols - 1], [1, $cols - 1], [2, $cols - 1], [3, $cols - 1]] as $i => [$r,$col]) {
            $this->module($m, $c, $p, $i + 1, $r, $col, $rows, $cols);
        }
    }

    /** @param array<int, array<int, bool|null>> $m @param array<int, int> $c */
    private function cornerTwo(array &$m, array $c, int $p, int $rows, int $cols): void
    {
        foreach ([[$rows - 3, 0], [$rows - 2, 0], [$rows - 1, 0], [0, $cols - 4], [0, $cols - 3], [0, $cols - 2], [0, $cols - 1], [1, $cols - 1]] as $i => [$r,$col]) {
            $this->module($m, $c, $p, $i + 1, $r, $col, $rows, $cols);
        }
    }

    /** @param array<int, array<int, bool|null>> $m @param array<int, int> $c */
    private function cornerThree(array &$m, array $c, int $p, int $rows, int $cols): void
    {
        foreach ([[$rows - 3, 0], [$rows - 2, 0], [$rows - 1, 0], [0, $cols - 2], [0, $cols - 1], [1, $cols - 1], [2, $cols - 1], [3, $cols - 1]] as $i => [$r,$col]) {
            $this->module($m, $c, $p, $i + 1, $r, $col, $rows, $cols);
        }
    }

    /** @param array<int, array<int, bool|null>> $m @param array<int, int> $c */
    private function cornerFour(array &$m, array $c, int $p, int $rows, int $cols): void
    {
        foreach ([[$rows - 1, 0], [$rows - 1, $cols - 1], [0, $cols - 3], [0, $cols - 2], [0, $cols - 1], [1, $cols - 3], [1, $cols - 2], [1, $cols - 1]] as $i => [$r,$col]) {
            $this->module($m, $c, $p, $i + 1, $r, $col, $rows, $cols);
        }
    }

    /** @param array<int, array<int, bool>> $data @return array<int, array<int, bool>> */
    private function addFinderPatterns(array $data, int $regions): array
    {
        $dataSize = count($data);
        $regionDataSize = intdiv($dataSize, $regions);
        $symbolSize = $dataSize + ($regions * 2);
        $symbol = array_fill(0, $symbolSize, array_fill(0, $symbolSize, false));

        for ($regionRow = 0; $regionRow < $regions; $regionRow++) {
            for ($regionColumn = 0; $regionColumn < $regions; $regionColumn++) {
                $top = $regionRow * ($regionDataSize + 2);
                $left = $regionColumn * ($regionDataSize + 2);
                for ($offset = 0; $offset < $regionDataSize + 2; $offset++) {
                    $symbol[$top][$left + $offset] = $offset % 2 === 0;
                    $symbol[$top + $regionDataSize + 1][$left + $offset] = true;
                    $symbol[$top + $offset][$left] = true;
                    $symbol[$top + $offset][$left + $regionDataSize + 1] = $offset % 2 === 0;
                }
            }
        }

        for ($row = 0; $row < $dataSize; $row++) {
            for ($column = 0; $column < $dataSize; $column++) {
                $targetRow = intdiv($row, $regionDataSize) * ($regionDataSize + 2) + 1 + ($row % $regionDataSize);
                $targetColumn = intdiv($column, $regionDataSize) * ($regionDataSize + 2) + 1 + ($column % $regionDataSize);
                $symbol[$targetRow][$targetColumn] = $data[$row][$column];
            }
        }

        return $symbol;
    }
}

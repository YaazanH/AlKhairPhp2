<?php

namespace App\Support;

final class ArabicKashidaJustifier
{
    /**
     * Stretch Arabic text to the widest value that does not exceed the target width.
     *
     * @param  callable(string): float  $measure
     */
    public static function toWidth(string $value, float $targetWidth, callable $measure): string
    {
        if ($value === '' || preg_match('/\p{Arabic}/u', $value) !== 1 || $measure($value) >= $targetWidth) {
            return $value;
        }

        $positions = self::joinPositions($value);
        if ($positions === []) {
            return $value;
        }

        $low = 1;
        $high = min(count($positions) * 64, 640);
        $best = $value;

        while ($low <= $high) {
            $count = intdiv($low + $high, 2);
            $candidate = self::insertDistributed($value, $positions, $count);

            if ($measure($candidate) <= $targetWidth) {
                $best = $candidate;
                $low = $count + 1;
            } else {
                $high = $count - 1;
            }
        }

        return $best;
    }

    /** @return list<int> */
    private static function joinPositions(string $value): array
    {
        preg_match_all(
            '/([\x{0626}\x{0628}\x{062A}-\x{062E}\x{0633}-\x{063A}\x{0641}-\x{0647}\x{0649}\x{064A}])(?=[\x{0622}-\x{064A}])/u',
            $value,
            $joins,
            PREG_OFFSET_CAPTURE,
        );

        return array_values(array_map(
            static fn (array $match): int => $match[1] + strlen($match[0]),
            $joins[0] ?? [],
        ));
    }

    /** @param list<int> $positions */
    private static function insertDistributed(string $value, array $positions, int $count): string
    {
        $insertions = [];

        for ($index = 0; $index < $count; $index++) {
            $position = $positions[$index % count($positions)];
            $insertions[$position] = ($insertions[$position] ?? 0) + 1;
        }

        krsort($insertions);
        foreach ($insertions as $position => $amount) {
            $value = substr($value, 0, $position).str_repeat("\u{0640}", $amount).substr($value, $position);
        }

        return $value;
    }
}

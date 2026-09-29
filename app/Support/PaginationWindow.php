<?php

namespace App\Support;

final class PaginationWindow
{
    /** @return array<int, int|string> */
    public static function pages(int $current, int $last, bool $narrow = false): array
    {
        $last = max(1, $last);
        $current = min($last, max(1, $current));

        if ($last <= ($narrow ? 4 : 8)) {
            return range(1, $last);
        }

        if ($narrow) {
            $pages = [1, min($last - 1, max(2, $current)), $last];
        } elseif ($current <= 5) {
            $pages = [...range(1, 5), $last - 1, $last];
        } elseif ($current >= $last - 3) {
            $pages = [1, ...range($last - 4, $last)];
        } else {
            $pages = [1, $current - 1, $current, $current + 1, $last - 1, $last];
        }

        $window = [];
        $previous = 0;
        foreach ($pages as $page) {
            if ($previous && $page > $previous + 1) {
                $window[] = '…';
            }
            $window[] = $page;
            $previous = $page;
        }

        return $window;
    }
}

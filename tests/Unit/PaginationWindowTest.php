<?php

namespace Tests\Unit;

use App\Support\PaginationWindow;
use PHPUnit\Framework\TestCase;

class PaginationWindowTest extends TestCase
{
    public function test_narrow_and_wide_windows_start_with_the_requested_page_links(): void
    {
        $this->assertSame([1, 2, '…', 20], PaginationWindow::pages(1, 20, true));
        $this->assertSame([1, 2, 3, 4, 5, '…', 19, 20], PaginationWindow::pages(1, 20));
        $this->assertSame([1, 2, 3, 4], PaginationWindow::pages(1, 4, true));
    }

    public function test_current_and_boundary_pages_remain_accessible_in_bounded_windows(): void
    {
        foreach ([5, 8, 20, 1000] as $last) {
            foreach ([1, 2, (int) ceil($last / 2), $last - 1, $last] as $current) {
                foreach ([true, false] as $narrow) {
                    $pages = PaginationWindow::pages($current, $last, $narrow);
                    $this->assertContains(1, $pages);
                    $this->assertContains($current, $pages);
                    $this->assertContains($last, $pages);
                    $this->assertLessThanOrEqual($narrow ? 5 : 8, count($pages));
                    $numbers = array_values(array_filter($pages, 'is_int'));
                    $this->assertSame($numbers, array_values(array_unique($numbers)));
                    $sorted = $numbers;
                    sort($sorted);
                    $this->assertSame($sorted, $numbers);
                }
            }
        }
    }
}

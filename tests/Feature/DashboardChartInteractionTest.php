<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardChartInteractionTest extends TestCase
{
    public function test_line_chart_hover_highlights_a_point_without_transforming_it(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

        $this->assertStringContainsString('.dashboard-line-point:hover .dashboard-chart-point,', $styles);
        $this->assertStringContainsString('stroke-width: 3px;', $styles);
        $this->assertStringContainsString('filter: drop-shadow(', $styles);
        $this->assertStringNotContainsString('transform: scale(1.45);', $styles);
        $this->assertStringNotContainsString('dashboard-chart-point origin-center', $dashboard);
    }

    public function test_performance_map_hover_does_not_change_point_geometry(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

        $this->assertStringContainsString('.dashboard-performance-map__point {', $styles);
        $this->assertStringContainsString('margin-bottom: -0.8rem;', $styles);
        $this->assertStringContainsString('margin-left: -0.8rem;', $styles);
        $this->assertStringContainsString('contain: layout style;', $styles);
        $this->assertStringNotContainsString('contain: layout paint style;', $styles);
        $this->assertStringContainsString('aspect-ratio: 1 / 1;', $styles);
        $this->assertStringContainsString('border-radius: 50%;', $styles);
        $this->assertStringContainsString('transition: transform 140ms ease;', $styles);
        $this->assertStringContainsString('transform: scale(1.35);', $styles);
        $this->assertStringContainsString('data-performance-tooltip', $dashboard);
        $this->assertStringContainsString('.dashboard-performance-map__point:hover + .dashboard-performance-map__tooltip,', $styles);
        $this->assertStringContainsString('visibility: hidden;', $styles);
        $this->assertStringNotContainsString('.dashboard-performance-map__point:hover .dashboard-performance-map__tooltip,', $styles);
        $this->assertStringNotContainsString('transform: translate(-50%, 50%);', $styles);
        $this->assertStringNotContainsString('transition: width 150ms ease, height 150ms ease', $styles);
        $this->assertStringNotContainsString('transform: translate(-50%, 0.2rem);', $styles);
    }
}

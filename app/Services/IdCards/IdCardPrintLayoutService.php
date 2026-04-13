<?php

namespace App\Services\IdCards;

use App\Models\IdCardTemplate;
use Illuminate\Support\Collection;

class IdCardPrintLayoutService
{
    public function defaults(): array
    {
        return [
            'page_width_mm' => 210,
            'page_height_mm' => 297,
            'margin_top_mm' => 10,
            'margin_right_mm' => 10,
            'margin_bottom_mm' => 10,
            'margin_left_mm' => 10,
            'gap_x_mm' => 6,
            'gap_y_mm' => 6,
        ];
    }

    public function paginate(IdCardTemplate $template, Collection $students, array $options): array
    {
        return $this->paginateDimensions($template->width_mm, $template->height_mm, $students, $options, [
            'page_too_small' => __('id_cards.print.warnings.page_too_small'),
            'tight_fit' => __('id_cards.print.warnings.tight_fit'),
            'unused_space' => __('id_cards.print.warnings.unused_space'),
        ]);
    }

    public function paginateDimensions(float $itemWidthMm, float $itemHeightMm, Collection $items, array $options, array $warningMessages = []): array
    {
        $config = array_merge($this->defaults(), $options);
        $usableWidth = max($config['page_width_mm'] - $config['margin_left_mm'] - $config['margin_right_mm'], 0);
        $usableHeight = max($config['page_height_mm'] - $config['margin_top_mm'] - $config['margin_bottom_mm'], 0);
        $columns = (int) floor(($usableWidth + $config['gap_x_mm']) / ($itemWidthMm + $config['gap_x_mm']));
        $rows = (int) floor(($usableHeight + $config['gap_y_mm']) / ($itemHeightMm + $config['gap_y_mm']));
        $warnings = [];

        if ($columns < 1 || $rows < 1) {
            return [
                'config' => $config,
                'grid' => [
                    'columns' => 0,
                    'rows' => 0,
                    'cards_per_page' => 0,
                    'remaining_width_mm' => $usableWidth,
                    'remaining_height_mm' => $usableHeight,
                ],
                'warnings' => [$warningMessages['page_too_small'] ?? __('id_cards.print.warnings.page_too_small')],
                'pages' => [],
            ];
        }

        $cardsPerPage = $columns * $rows;
        $remainingWidth = $usableWidth - (($columns * $itemWidthMm) + (($columns - 1) * $config['gap_x_mm']));
        $remainingHeight = $usableHeight - (($rows * $itemHeightMm) + (($rows - 1) * $config['gap_y_mm']));

        if ($remainingWidth < 2 || $remainingHeight < 2) {
            $warnings[] = $warningMessages['tight_fit'] ?? __('id_cards.print.warnings.tight_fit');
        }

        if ($remainingWidth > ($itemWidthMm * 0.75) || $remainingHeight > ($itemHeightMm * 0.75)) {
            $warnings[] = $warningMessages['unused_space'] ?? __('id_cards.print.warnings.unused_space');
        }

        return [
            'config' => $config,
            'grid' => [
                'columns' => $columns,
                'rows' => $rows,
                'cards_per_page' => $cardsPerPage,
                'remaining_width_mm' => round($remainingWidth, 2),
                'remaining_height_mm' => round($remainingHeight, 2),
            ],
            'warnings' => $warnings,
            'pages' => $items
                ->values()
                ->chunk($cardsPerPage)
                ->values()
                ->all(),
        ];
    }
}

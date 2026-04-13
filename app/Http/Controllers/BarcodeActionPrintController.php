<?php

namespace App\Http\Controllers;

use App\Models\BarcodeAction;
use App\Services\BarcodeActions\BarcodeActionCatalogService;
use App\Services\IdCards\IdCardPrintLayoutService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BarcodeActionPrintController extends Controller
{
    public function __construct(
        protected BarcodeActionCatalogService $catalog,
        protected IdCardPrintLayoutService $printLayoutService,
    ) {
    }

    public function preview(Request $request): View
    {
        $validated = $request->validate([
            'action_ids' => ['required', 'array', 'min:1'],
            'action_ids.*' => ['integer', 'exists:barcode_actions,id'],
            'label_width_mm' => ['required', 'numeric', 'min:30', 'max:160'],
            'label_height_mm' => ['required', 'numeric', 'min:18', 'max:100'],
            'page_width_mm' => ['required', 'numeric', 'min:80', 'max:500'],
            'page_height_mm' => ['required', 'numeric', 'min:80', 'max:500'],
            'margin_top_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_right_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_bottom_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_left_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'gap_x_mm' => ['required', 'numeric', 'min:0', 'max:30'],
            'gap_y_mm' => ['required', 'numeric', 'min:0', 'max:30'],
        ]);

        $this->catalog->syncReferenceActions();

        $selectedIds = array_map('intval', $validated['action_ids']);
        $actions = BarcodeAction::query()
            ->with(['attendanceStatus', 'pointType'])
            ->whereIn('id', $selectedIds)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (BarcodeAction $action) => array_search($action->id, $selectedIds, true))
            ->values();

        $layout = $this->printLayoutService->paginateDimensions(
            (float) $validated['label_width_mm'],
            (float) $validated['label_height_mm'],
            $actions,
            $validated,
            [
                'page_too_small' => __('barcodes.print.warnings.page_too_small'),
                'tight_fit' => __('barcodes.print.warnings.tight_fit'),
                'unused_space' => __('barcodes.print.warnings.unused_space'),
            ],
        );

        $pages = collect($layout['pages'])
            ->map(fn ($pageActions) => collect($pageActions)->map(fn (BarcodeAction $action) => [
                'action' => $action,
                'svg' => $this->catalog->renderBarcode($action->code, [
                    'width' => max(((float) $validated['label_width_mm']) - 8, 20),
                    'height' => max(((float) $validated['label_height_mm']) * 0.55, 10),
                    'font_size' => 3,
                    'show_text' => true,
                ]),
            ])->all())
            ->all();

        return view('barcodes.print.preview', [
            'actions' => $actions,
            'label' => [
                'width_mm' => (float) $validated['label_width_mm'],
                'height_mm' => (float) $validated['label_height_mm'],
            ],
            'layout' => $layout,
            'pages' => $pages,
        ]);
    }
}

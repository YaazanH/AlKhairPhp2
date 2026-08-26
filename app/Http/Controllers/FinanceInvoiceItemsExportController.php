<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\XlsxExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceInvoiceItemsExportController extends Controller
{
    public function __invoke(Invoice $invoice, XlsxExportService $xlsx): StreamedResponse
    {
        abort_unless(request()->user()?->can('finance.expense-requests.view') || request()->user()?->can('invoices.view'), 403);
        abort_unless($invoice->invoice_type === 'finance', 404);

        $rows = $invoice->items()
            ->orderBy('line_no')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn ($item, int $index): array => [
                $item->line_no ?: $index + 1,
                $item->item_name,
                $item->quantity,
                $item->unit_price,
                $item->amount,
            ])
            ->all();

        return $xlsx->download(
            'invoice-'.$invoice->id.'-items',
            ['#', 'name', 'qty', 'individual price', 'amount'],
            $rows,
        );
    }
}

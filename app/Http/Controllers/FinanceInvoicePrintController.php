<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Contracts\View\View;

class FinanceInvoicePrintController extends Controller
{
    public function __invoke(Invoice $invoice): View
    {
        abort_unless(request()->user()?->can('finance.expense-requests.view'), 403);
        abort_unless($invoice->invoice_type === 'finance' && $invoice->finalised_at, 404);

        return view('print.finance-invoice-a5', [
            'invoice' => $invoice->load(['items', 'financeRequest.acceptedCurrency', 'finalisedBy']),
        ]);
    }
}

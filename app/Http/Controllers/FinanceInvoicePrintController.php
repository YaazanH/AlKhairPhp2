<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\PdfBrandingService;
use Illuminate\Http\Response;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

class FinanceInvoicePrintController extends Controller
{
    public function __invoke(Invoice $invoice): Response
    {
        abort_unless(request()->user()?->can('finance.expense-requests.view') || request()->user()?->can('invoices.view'), 403);
        abort_unless($invoice->invoice_type === 'finance', 404);

        $invoice->load(['items', 'financeRequest.acceptedCurrency', 'finalisedBy']);
        $fontDirectories = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];
        $mpdf = new Mpdf([
            'format' => 'A5', 'orientation' => 'P', 'mode' => 'utf-8', 'default_font' => 'dubai',
            'fontDir' => array_merge($fontDirectories, [public_path('fonts/dubai')]),
            'fontdata' => $fontData + ['dubai' => ['R' => 'Dubai-Regular.ttf', 'M' => 'Dubai-Medium.ttf', 'B' => 'Dubai-Bold.ttf', 'L' => 'Dubai-Light.ttf', 'useOTL' => 0xFF, 'useKashida' => 75]],
            'margin_top' => 12, 'margin_right' => 12, 'margin_bottom' => 12, 'margin_left' => 12,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML(view('print.finance-invoice-a5', [
            'invoice' => $invoice,
            'isPdf' => true,
            'logoImage' => app(PdfBrandingService::class)->logoSource(),
        ])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-'.$invoice->id.'.pdf"',
        ]);
    }
}

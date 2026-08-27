<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\DataMatrixSvgRenderer;
use App\Services\FinanceReportService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Http\Response;
use Mpdf\Mpdf;

class FinanceInvoicePrintController extends Controller
{
    public function __invoke(Invoice $invoice): Response
    {
        abort_unless(request()->user()?->can('finance.expense-requests.view') || request()->user()?->can('invoices.view'), 403);
        abort_unless($invoice->invoice_type === 'finance', 404);

        $invoice->load(['items', 'invoiceKind', 'financeRequest.acceptedCurrency', 'finalisedBy']);
        $mpdf = new Mpdf(PdfOptions::make([
            'autoLangToFont' => false,
            'autoScriptToLang' => false,
            'format' => 'A5',
            'orientation' => 'P',
            'margin_top' => 0,
            'margin_right' => 10,
            'margin_bottom' => 16,
            'margin_left' => 10,
            'margin_header' => 0,
            'margin_footer' => 0,
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 3,
        ]));
        $mpdf->autoLangToFont = false;
        $mpdf->autoScriptToLang = false;
        $mpdf->autoArabic = true;
        $mpdf->useSubstitutions = true;
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $matrixValue = $invoice->original_invoice_no ?: $invoice->invoice_no;
        $matrixSvg = app(DataMatrixSvgRenderer::class)->render($matrixValue);
        $mpdf->WriteHTML(view('print.finance-invoice-a5', [
            'dataMatrixImage' => 'data:image/svg+xml;base64,'.base64_encode($matrixSvg),
            'invoice' => $invoice,
            'isPdf' => true,
            'logoImage' => app(FinanceReportService::class)->defaultReportLogoPdfSource(),
        ])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.finance_invoice'),
                $invoice->invoice_no ?: $invoice->id,
            ], 'invoice-'.$invoice->id.'.pdf'),
        ]);
    }
}

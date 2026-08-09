<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\Test;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class FinancePdfRegressionTest extends TestCase
{
    #[Test]
    public function a_long_finance_invoice_renders_as_a_compact_multipage_pdf(): void
    {
        $invoice = new Invoice([
            'invoice_no' => 'INV-TEST',
            'original_invoice_no' => 'ORIGINAL-1',
            'invoicer_name' => 'Test issuer',
            'invoice_type' => 'finance',
            'issue_date' => '2026-08-09',
            'subtotal' => 1000,
            'discount' => 0,
            'total' => 1000,
        ]);
        $invoice->setRelation('items', collect(range(1, 80))->map(fn (int $line) => new InvoiceItem([
            'item_name' => 'Invoice line '.$line,
            'quantity' => 1,
            'unit_price' => 12.5,
            'amount' => 12.5,
        ])));
        $invoice->setRelation('financeRequest', null);
        $invoice->setRelation('finalisedBy', null);

        $fontDirectories = (new ConfigVariables())->getDefaults()['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];
        $pdf = new Mpdf([
            'format' => 'A5',
            'mode' => 'utf-8',
            'default_font' => 'dubai',
            'fontDir' => array_merge($fontDirectories, [public_path('fonts/dubai')]),
            'fontdata' => $fontData + ['dubai' => ['R' => 'Dubai-Regular.ttf', 'M' => 'Dubai-Medium.ttf', 'B' => 'Dubai-Bold.ttf']],
        ]);
        $pdf->WriteHTML(view('print.finance-invoice-a5', ['invoice' => $invoice, 'isPdf' => true])->render());
        $binary = $pdf->Output('', 'S');

        $pageCount = (new Mpdf())->setSourceFile(StreamReader::createByString($binary));

        $this->assertGreaterThan(1, $pageCount);
        $this->assertLessThan(10, $pageCount);
        $this->assertStringStartsWith('%PDF-', $binary);
    }
}

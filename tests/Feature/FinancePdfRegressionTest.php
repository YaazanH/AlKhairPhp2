<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\DataMatrixSvgRenderer;
use App\Support\PdfOptions;
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
            'item_name' => 'بند الفاتورة رقم '.$line,
            'quantity' => 1,
            'unit_price' => 12.5,
            'amount' => 12.5,
        ])));
        $invoice->setRelation('financeRequest', null);
        $invoice->setRelation('finalisedBy', new User(['name' => 'Invoice Administrator']));

        app()->setLocale('ar');
        $html = view('print.finance-invoice-a5', ['invoice' => $invoice, 'isPdf' => true])->render();

        $this->assertStringContainsString('@page { margin: 0 10mm 16mm; margin-header: 0; margin-footer: 0;', $html);
        $this->assertStringContainsString('.header { background: #dff1e2; border-bottom: 1px solid #9fc6a8; margin: 0 -10mm; padding: 4mm 10mm; }', $html);
        $this->assertStringContainsString('.meta { margin: 0 0 5mm; }', $html);
        $this->assertStringContainsString('.original-invoice-no { direction: ltr; text-align: right; unicode-bidi: embed; }', $html);
        $this->assertStringContainsString('class="value original-invoice-no" dir="ltr">ORIGINAL-1</td>', $html);
        $this->assertStringContainsString('class="continuation">متابعة</div>', $html);
        $this->assertSame(1, substr_count($html, 'class="continuation"'));
        $this->assertStringNotContainsString(__('finance.fields.invoice_kind'), $html);
        $this->assertLessThan(strpos($html, __('finance.common.date')), strpos($html, __('finance.fields.original_invoice_no')));
        $this->assertStringContainsString('class="closing-layout"', $html);
        $this->assertStringContainsString('class="closing-notes"', $html);
        $this->assertStringNotContainsString('class="notes"', $html);
        $this->assertStringContainsString('<colgroup><col style="width:58%"><col style="width:4%"><col style="width:38%"></colgroup>', $html);
        $this->assertStringNotContainsString('Invoice Administrator', $html);
        $this->assertStringNotContainsString('signature-line', $html);
        $this->assertStringNotContainsString('>'.__('finance.fields.signature').'</div>', $html);

        $invoice->notes = 'فاتورة تجريبية محلية لعرض خمسة وعشرين بنداً.';
        $htmlWithNotes = view('print.finance-invoice-a5', ['invoice' => $invoice, 'isPdf' => true])->render();
        $this->assertStringContainsString('class="closing-notes"', $htmlWithNotes);
        $this->assertStringContainsString('<table class="notes"><tr><td>فاتورة تجريبية محلية لعرض خمسة وعشرين بنداً.</td></tr></table>', $htmlWithNotes);
        $this->assertSame(1, substr_count($htmlWithNotes, 'data:image/png;base64,'));
        $this->assertStringContainsString('width: 48mm;', $htmlWithNotes);
        $this->assertStringContainsString('text-align: justify;', $htmlWithNotes);

        $pdf = new Mpdf(PdfOptions::make([
            'format' => 'A5',
            'mode' => 'utf-8',
            'default_font' => 'dubai',
            'margin_top' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 3,
        ]));
        $pdf->autoArabic = true;
        $pdf->useSubstitutions = true;
        $pdf->SetDirectionality('rtl');
        $pdf->WriteHTML($htmlWithNotes);
        $binary = $pdf->Output('', 'S');

        $pageCount = (new Mpdf)->setSourceFile(StreamReader::createByString($binary));

        $this->assertGreaterThan(1, $pageCount);
        $this->assertLessThan(10, $pageCount);
        $this->assertStringStartsWith('%PDF-', $binary);
    }

    #[Test]
    public function invoice_data_matrix_svg_uses_a_transparent_background(): void
    {
        $svg = app(DataMatrixSvgRenderer::class)->render('ORIGINAL-25');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<path ', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
        $this->assertStringNotContainsString('fill="#fff"', $svg);
    }
}

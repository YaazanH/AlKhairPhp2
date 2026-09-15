<?php

namespace Tests\Unit;

use App\Services\InvoiceDraftParser;
use PHPUnit\Framework\TestCase;

class InvoiceDraftParserTest extends TestCase
{
    public function test_printed_invoice_fields_items_and_totals_are_extracted(): void
    {
        $draft = (new InvoiceDraftParser)->parse(<<<'TEXT'
AL NOUR STATIONERY
Invoice No: INV-2026-18
Date: 06/09/2026
Currency: SYP
Item                   Qty       Unit Price       Amount
Notebooks              2         1,500            3,000
Blue pens              10        100              1,000
Discount: 500
Grand Total: 3,500 SYP
TEXT);

        $this->assertSame('INV-2026-18', $draft['original_invoice_no']);
        $this->assertSame('AL NOUR STATIONERY', $draft['invoice_issuer']);
        $this->assertSame('2026-09-06', $draft['invoice_date']);
        $this->assertSame('SYP', $draft['currency']);
        $this->assertSame('500', $draft['invoice_deduction']);
        $this->assertSame(3500.0, $draft['total']);
        $this->assertSame([
            ['item_name' => 'Notebooks', 'quantity' => '2', 'unit_price' => '1500'],
            ['item_name' => 'Blue pens', 'quantity' => '10', 'unit_price' => '100'],
        ], $draft['invoice_items']);
        $this->assertSame([], $draft['warnings']);
    }

    public function test_arabic_digits_and_reversed_table_columns_are_supported(): void
    {
        $draft = (new InvoiceDraftParser)->parse(<<<'TEXT'
المورد: مكتبة النور
رقم الفاتورة: ١٢٣
التاريخ: ٢٠٢٦/٠٩/٠٦
العملة: SYP
المبلغ        سعر الوحدة        الكمية        البيان
٣٬٠٠٠         ١٬٥٠٠              ٢             دفاتر مدرسية
١٬٠٠٠         ١٠٠                ١٠            أقلام زرقاء
الخصم: ٥٠٠
الإجمالي النهائي: ٣٬٥٠٠
TEXT);

        $this->assertSame('123', $draft['original_invoice_no']);
        $this->assertSame('مكتبة النور', $draft['invoice_issuer']);
        $this->assertSame('2026-09-06', $draft['invoice_date']);
        $this->assertSame('دفاتر مدرسية', $draft['invoice_items'][0]['item_name']);
        $this->assertSame('2', $draft['invoice_items'][0]['quantity']);
        $this->assertSame('1500', $draft['invoice_items'][0]['unit_price']);
        $this->assertSame(3500.0, $draft['total']);
        $this->assertSame([], $draft['warnings']);
    }

    public function test_ambiguous_rows_are_not_invented_and_invalid_dates_are_not_applied(): void
    {
        $draft = (new InvoiceDraftParser)->parse(<<<'TEXT'
Invoice No: X-1
Supplier: Example supplier
Date: 31/02/2026
Item Qty Price Amount
Notebooks 2 100 900
Pens 3 10 30
Grand Total: 930
TEXT);

        $this->assertNull($draft['invoice_date']);
        $this->assertCount(1, $draft['invoice_items']);
        $this->assertSame('Pens', $draft['invoice_items'][0]['item_name']);
        $this->assertContains('unreadable_rows', $draft['warnings']);
        $this->assertContains('total_mismatch', $draft['warnings']);
        $this->assertContains('missing_fields', $draft['warnings']);
    }

    public function test_pdf_arabic_presentation_forms_and_displaced_separators_are_normalized(): void
    {
        $draft = (new InvoiceDraftParser)->parse("اﻟﻤﻮرد :ﻣﻜﺘﺒﺔ اﻟﻨﻮر\nرﻗﻢ اﻟﻔﺎﺗﻮرة١٢٣ :\nاﻟﺘﺎرﻳﺦ٠٦/٠٩/٢٠٢٦ :\nاﻟﺨﺼﻢ٥٠٠ :\nالإجمالي النهائي٣٬٥٠٠ :");

        $this->assertSame('123', $draft['original_invoice_no']);
        $this->assertSame('مكتبة النور', $draft['invoice_issuer']);
        $this->assertSame('2026-09-06', $draft['invoice_date']);
        $this->assertSame('500', $draft['invoice_deduction']);
        $this->assertSame(3500.0, $draft['total']);
        $this->assertNotContains('missing_fields', $draft['warnings']);
    }

    public function test_documents_without_an_item_table_keep_empty_items(): void
    {
        $draft = (new InvoiceDraftParser)->parse("Phone 2 100 200\nDue Date: 12/09/2026\nInvoice Date: 06/09/2026\nTotal: 200");

        $this->assertSame([], $draft['invoice_items']);
        $this->assertSame('2026-09-06', $draft['invoice_date']);
        $this->assertContains('no_items', $draft['warnings']);
        $this->assertNull($draft['invoice_deduction']);
    }
}

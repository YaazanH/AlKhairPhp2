@php
    $printNotes = preg_match('/^(?:Created from withdrawal request|تم إنشاؤها من طلب السحب)/u', (string) $invoice->notes) ? null : $invoice->notes;
    $formatInvoiceNumber = fn (float|int|string|null $value) => preg_replace('/\.00$/', '', number_format((float) ($value ?? 0), 2));
    $formatInvoiceAmount = fn (float|int|string|null $value) => preg_replace('/\.00(?=\s|$)/', '', app(\App\Services\FinanceService::class)->formatCurrencyAmount($value, $invoice->financeRequest?->acceptedCurrency));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { margin: 38mm 10mm 16mm; header: invoiceHeader; footer: invoiceFooter; }
        @page :first { header: invoiceFirstHeader; }
        body { color: #17321f; font-family: dubai, sans-serif; font-size: 9.5pt; margin: 0; }
        .header { background: #dff1e2; border-bottom: 1px solid #9fc6a8; padding: 4mm 10mm; }
        .header table, .footer table, .meta, .items, .totals { border-collapse: collapse; width: 100%; }
        .brand { color: #14532d; font-size: 16pt; font-weight: bold; }
        .brand-logo { padding-left: 2mm; width: 17mm; }
        .brand-logo img { height: auto; max-height: 23mm; max-width: 35mm; width: auto; }
        .invoice-title { color: #166534; font-size: 19pt; font-weight: bold; text-align: end; }
        .invoice-no { color: #50715a; font-size: 9pt; text-align: end; }
        .footer { background: #dff1e2; border-top: 1px solid #9fc6a8; color: #315b3b; padding: 2.5mm 10mm; }
        .footer td { width: 50%; }
        .footer-barcode { direction:ltr; font-family:code39; font-size:18pt; line-height:1; text-align:start; }
        .page { text-align: end; }
        .meta { margin: 5mm 0 5mm; }
        .meta td { border-bottom: 1px solid #d9e7dc; padding: 2.2mm 2mm; }
        .label { color: #5d7663; font-size: 8pt; font-weight: bold; width: 23%; }
        .value { font-weight: bold; width: 27%; }
        .items { page-break-inside: auto; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { background: #e7f3e9; border: 1px solid #a9caae; color: #225b31; font-weight: bold; padding: 2.4mm 1.5mm; text-align: center; }
        .items td { border: 1px solid #c9d9cc; padding: 2.4mm 1.5mm; vertical-align: top; }
        .items tbody tr:nth-child(even) td { background: #f1f7f2; }
        .number { direction: ltr; text-align: center; white-space: nowrap; }
        .totals { margin-top: 4mm; width: 58%; }
        .totals th, .totals td { border-bottom: 1px solid #c9d9cc; padding: 2mm; }
        .totals th { color: #526e59; text-align: start; }
        .totals td { direction:ltr; font-weight:bold; text-align:{{ app()->getLocale() === 'ar' ? 'right' : 'end' }}; }
        .grand th, .grand td { background: #dff1e2; color: #14532d; font-size: 11pt; }
        .notes { color: #526e59; margin-top: 5mm; }
        .signature { margin-top: 18mm; text-align: center; width: 45%; }
        .signature-line { border-top: 1px solid #315b3b; padding-top: 1.5mm; }
        .actions { margin-bottom: 8px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<htmlpageheader name="invoiceFirstHeader">
    <div class="header"><table><tr>@if ($logoImage ?? null)<td class="brand-logo"><img src="{{ $logoImage }}" alt=""></td>@endif<td><div class="brand">جامع الخير</div><div>المهاجرين</div></td><td><div class="invoice-title">فاتورة</div><div class="invoice-no">{{ $invoice->invoice_no }}</div></td></tr></table></div>
</htmlpageheader>
<htmlpageheader name="invoiceHeader">
    <div class="header"><table><tr>@if ($logoImage ?? null)<td class="brand-logo"><img src="{{ $logoImage }}" alt=""></td>@endif<td><div class="brand">جامع الخير</div><div>المهاجرين</div></td><td><div class="invoice-title">فاتورة</div><div class="invoice-no">{{ $invoice->invoice_no }} · متابعة</div></td></tr></table></div>
</htmlpageheader>
<htmlpagefooter name="invoiceFooter">
    <div class="footer"><table><tr><td class="footer-barcode">*{{ $invoice->invoice_no }}*</td><td class="page">Page {PAGENO} / {nbpg}</td></tr></table></div>
</htmlpagefooter>

@unless($isPdf ?? false)<div class="actions"><button type="button" onclick="window.print()">{{ __('finance.actions.print') }}</button></div>@endunless

<table class="meta">
    <tr><td class="label">{{ __('finance.fields.invoice_issuer') }}</td><td class="value">{{ $invoice->invoicer_name ?: '-' }}</td><td class="label">{{ __('finance.common.date') }}</td><td class="value">{{ $invoice->issue_date?->format('d-m-Y') ?: '-' }}</td></tr>
    <tr><td class="label">{{ __('finance.fields.original_invoice_no') }}</td><td class="value">{{ $invoice->original_invoice_no ?: '-' }}</td><td class="label">{{ __('finance.fields.invoice_no') }}</td><td class="value">{{ $invoice->invoice_no }}</td></tr>
</table>

@php($invoicePages = $invoice->items->values()->chunk(14))
@foreach ($invoicePages as $pageItems)
    <table class="items">
        <thead><tr><th style="width:7%">#</th><th>{{ __('finance.fields.item_name') }}</th><th style="width:13%">{{ __('finance.fields.quantity') }}</th><th style="width:20%">{{ __('finance.fields.unit_price') }}</th><th style="width:20%">{{ __('finance.fields.amount') }}</th></tr></thead>
        <tbody>
        @foreach ($pageItems as $itemIndex => $item)
            <tr><td class="number">{{ $itemIndex + 1 }}</td><td>{{ $item->item_name }}</td><td class="number">{{ $formatInvoiceNumber($item->quantity) }}</td><td class="number">{{ $formatInvoiceNumber($item->unit_price) }}</td><td class="number">{{ $formatInvoiceNumber($item->amount) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if (! $loop->last)<pagebreak />@endif
@endforeach

<table class="totals" align="{{ app()->getLocale() === 'ar' ? 'left' : 'right' }}">
    <tr><th>{{ __('finance.fields.subtotal') }}</th><td>{{ $formatInvoiceAmount($invoice->subtotal) }}</td></tr>
    <tr><th>{{ __('finance.fields.deduction') }}</th><td>{{ $formatInvoiceAmount(-(float) $invoice->discount) }}</td></tr>
    <tr class="grand"><th>{{ __('finance.fields.grand_total') }}</th><td>{{ $formatInvoiceAmount($invoice->total) }}</td></tr>
</table>

@if ($printNotes)<div class="notes">{{ $printNotes }}</div>@endif
<div class="signature"><div class="signature-line">{{ __('finance.fields.signature') }}</div><small>{{ $invoice->finalisedBy?->name }}</small></div>
@unless($isPdf ?? false)<script>window.addEventListener('load', () => window.print());</script>@endunless
</body>
</html>

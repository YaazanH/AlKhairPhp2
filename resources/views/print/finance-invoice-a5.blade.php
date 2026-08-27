@php
    $invoiceNotes = trim((string) $invoice->notes);
    $printNotes = $invoiceNotes === '' || preg_match('/^(?:Created from withdrawal request|تم إنشاؤها من طلب السحب)/u', $invoiceNotes) ? null : $invoiceNotes;
    $noteBoxBackground = $printNotes ? app(\App\Services\InvoiceNoteBoxBackgroundRenderer::class)->dataUri() : null;
    $formatInvoiceNumber = fn (float|int|string|null $value) => preg_replace('/\.00$/', '', number_format((float) ($value ?? 0), 2));
    $formatInvoiceAmount = fn (float|int|string|null $value) => preg_replace('/\.00(?=\s|$)/', '', app(\App\Services\FinanceService::class)->formatCurrencyAmount($value, $invoice->financeRequest?->acceptedCurrency));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { margin: 0 10mm 16mm; margin-header: 0; margin-footer: 0; header: invoiceHeader; footer: invoiceFooter; }
        @page :first { header: invoiceFirstHeader; }
        body { color: #17321f; font-family: dubai, sans-serif; font-size: 9.5pt; margin: 0; }
        .header { background: #dff1e2; border-bottom: 1px solid #9fc6a8; margin: 0 -10mm; padding: 4mm 4mm 4mm 10mm; }
        .header table, .footer table, .meta, .items, .totals { border-collapse: collapse; width: 100%; }
        .brand-logo { text-align: right; width: 22%; }
        .brand-logo img { height: auto; max-height: 23mm; max-width: 35mm; width: auto; }
        .invoice-title { color: #166534; font-size: 19pt; font-weight: bold; text-align: center; width: 56%; }
        .invoice-no { color: #50715a; direction:ltr; font-size: 9pt; text-align: left; width: 22%; }
        .continuation { color: #78907e; direction: {{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}; font-size: 7pt; margin-top: .8mm; }
        .footer { background: #dff1e2; border-top: 1px solid #9fc6a8; color: #315b3b; margin: 0 -10mm; padding: 2.5mm 4mm; }
        .footer td { width: 33.33%; }
        .footer-matrix { text-align: right; }
        .footer-matrix img { display: inline-block; height: 5mm; width: 5mm; }
        .page { direction:ltr; text-align:center; }
        .meta { margin: 0 0 5mm; }
        .meta td { border-bottom: 1px solid #d9e7dc; padding: 2.2mm 2mm; }
        .label { color: #5d7663; font-size: 8pt; font-weight: bold; width: 23%; }
        .value { font-weight: bold; width: 27%; }
        .original-invoice-no { direction: ltr; text-align: {{ app()->isLocale('ar') ? 'right' : 'left' }}; unicode-bidi: embed; }
        .items { page-break-inside: auto; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { background: #e7f3e9; border: 1px solid #a9caae; color: #225b31; font-weight: bold; padding: 2.4mm 1.5mm; text-align: center; }
        .items td { border: 1px solid #c9d9cc; padding: 2.4mm 1.5mm; vertical-align: top; }
        .items tbody tr:nth-child(even) td { background: #f1f7f2; }
        .number { direction: ltr; text-align: center; white-space: nowrap; }
        .closing-layout { border-collapse: collapse; margin-top: 4mm; page-break-inside: avoid; table-layout: fixed; width: 100%; }
        .closing-layout td { border: 0; padding: 0; vertical-align: top; }
        .closing-summary { width: 58%; }
        .closing-gap { width: 4%; }
        .closing-notes { text-align: {{ app()->isLocale('ar') ? 'right' : 'left' }}; width: 38%; }
        .totals { margin: 0; width: 100%; }
        .totals th, .totals td { border-bottom: 1px solid #c9d9cc; padding: 2mm; }
        .totals th { color: #526e59; text-align: start; }
        .totals td { direction:ltr; font-weight:bold; text-align:{{ app()->getLocale() === 'ar' ? 'right' : 'end' }}; }
        .grand th, .grand td { background: #dff1e2; color: #14532d; font-size: 11pt; }
        .notes { border-collapse: collapse; margin-{{ app()->isLocale('ar') ? 'left' : 'right' }}: auto; page-break-inside: avoid; table-layout: fixed; width: 48mm; }
        .notes td { background-image: url('{{ $noteBoxBackground }}'); background-image-resize: 6; border: 0; color: #6b5718; line-height: 1.65; padding: 2mm; text-align: justify; }
        .actions { margin-bottom: 8px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<htmlpageheader name="invoiceFirstHeader">
    <div class="header"><table dir="ltr"><tr><td class="invoice-no">{{ $invoice->invoice_no }}</td><td class="invoice-title" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">{{ __('print.invoice.title') }}</td><td class="brand-logo">@if ($logoImage ?? null)<img src="{{ $logoImage }}" alt="">@endif</td></tr></table></div>
</htmlpageheader>
<htmlpageheader name="invoiceHeader">
    <div class="header"><table dir="ltr"><tr><td class="invoice-no">{{ $invoice->invoice_no }}<div class="continuation">{{ __('print.invoice.continued') }}</div></td><td class="invoice-title" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">{{ __('print.invoice.title') }}</td><td class="brand-logo">@if ($logoImage ?? null)<img src="{{ $logoImage }}" alt="">@endif</td></tr></table></div>
</htmlpageheader>
<htmlpagefooter name="invoiceFooter">
    <div class="footer"><table dir="ltr"><tr><td></td><td class="page">{PAGENO} / {nbpg}</td><td class="footer-matrix">@if($dataMatrixImage ?? null)<img src="{{ $dataMatrixImage }}" alt="">@endif</td></tr></table></div>
</htmlpagefooter>

@unless($isPdf ?? false)<div class="actions"><button type="button" onclick="window.print()">{{ __('finance.actions.print') }}</button></div>@endunless

<table class="meta">
    <tr><td class="label">{{ __('finance.fields.invoice_issuer') }}</td><td class="value">{{ $invoice->invoicer_name ?: '-' }}</td><td class="label">{{ __('finance.fields.original_invoice_no') }}</td><td class="value original-invoice-no" dir="ltr">{{ $invoice->original_invoice_no ?: '-' }}</td></tr>
    <tr><td class="label">{{ __('finance.common.date') }}</td><td class="value" colspan="3">{{ $invoice->issue_date?->format('d-m-Y') ?: '-' }}</td></tr>
</table>

<table class="items">
    <thead><tr><th style="width:7%">#</th><th>{{ __('finance.fields.item_name') }}</th><th style="width:13%">{{ __('finance.fields.quantity') }}</th><th style="width:20%">{{ __('finance.fields.unit_price') }}</th><th style="width:20%">{{ __('finance.fields.amount') }}</th></tr></thead>
    <tbody>
    @foreach ($invoice->items as $item)
        <tr><td class="number">{{ $loop->iteration }}</td><td>{{ $item->item_name }}</td><td class="number">{{ $formatInvoiceNumber($item->quantity) }}</td><td class="number">{{ $formatInvoiceNumber($item->unit_price) }}</td><td class="number">{{ $formatInvoiceNumber($item->amount) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="closing-layout" dir="{{ app()->isLocale('ar') ? 'ltr' : 'rtl' }}">
    <colgroup><col style="width:58%"><col style="width:4%"><col style="width:38%"></colgroup>
    <tr>
        <td class="closing-summary">
            <table class="totals" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
                <tr><th>{{ __('finance.fields.subtotal') }}</th><td>{{ $formatInvoiceAmount($invoice->subtotal) }}</td></tr>
                <tr><th>{{ __('finance.fields.deduction') }}</th><td>{{ $formatInvoiceAmount(-(float) $invoice->discount) }}</td></tr>
                <tr class="grand"><th>{{ __('finance.fields.grand_total') }}</th><td>{{ $formatInvoiceAmount($invoice->total) }}</td></tr>
            </table>
        </td>
        <td class="closing-gap"></td>
        <td class="closing-notes" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">@if($printNotes)<table class="notes"><tr><td>{{ $printNotes }}</td></tr></table>@endif</td>
    </tr>
</table>

@unless($isPdf ?? false)<script>window.addEventListener('load', () => window.print());</script>@endunless
</body>
</html>

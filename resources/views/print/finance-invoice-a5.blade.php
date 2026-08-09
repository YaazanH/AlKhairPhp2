<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { margin: 18mm 10mm 16mm; header: invoiceHeader; footer: invoiceFooter; }
        body { color: #17321f; font-family: dubai, sans-serif; font-size: 9.5pt; margin: 0; }
        .header { background: #dff1e2; border-bottom: 1px solid #9fc6a8; padding: 4mm 10mm; }
        .header table, .footer table, .meta, .items, .totals { border-collapse: collapse; width: 100%; }
        .brand { color: #14532d; font-size: 16pt; font-weight: bold; }
        .invoice-title { color: #166534; font-size: 19pt; font-weight: bold; text-align: end; }
        .invoice-no { color: #50715a; font-size: 9pt; text-align: end; }
        .footer { background: #dff1e2; border-top: 1px solid #9fc6a8; color: #315b3b; padding: 2.5mm 10mm; }
        .footer td { width: 50%; }
        .page { text-align: end; }
        .meta { margin: 2mm 0 5mm; }
        .meta td { border-bottom: 1px solid #d9e7dc; padding: 2.2mm 2mm; }
        .label { color: #5d7663; font-size: 8pt; font-weight: bold; width: 23%; }
        .value { font-weight: bold; width: 27%; }
        .items { page-break-inside: auto; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { background: #e7f3e9; border: 1px solid #a9caae; color: #225b31; font-weight: bold; padding: 2.4mm 1.5mm; text-align: center; }
        .items td { border: 1px solid #c9d9cc; padding: 2.4mm 1.5mm; vertical-align: top; }
        .number { direction: ltr; text-align: center; white-space: nowrap; }
        .totals { margin-top: 4mm; width: 58%; }
        .totals th, .totals td { border-bottom: 1px solid #c9d9cc; padding: 2mm; }
        .totals th { color: #526e59; text-align: start; }
        .totals td { direction: ltr; font-weight: bold; text-align: end; }
        .grand th, .grand td { background: #dff1e2; color: #14532d; font-size: 11pt; }
        .notes { color: #526e59; margin-top: 5mm; }
        .signature { margin-top: 13mm; text-align: center; width: 45%; }
        .signature-line { border-top: 1px solid #315b3b; padding-top: 1.5mm; }
        .actions { margin-bottom: 8px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<htmlpageheader name="invoiceHeader">
    <div class="header"><table><tr><td><div class="brand">جامع الخير</div><div>المهاجرين</div></td><td><div class="invoice-title">فاتورة</div><div class="invoice-no">{{ $invoice->invoice_no }}</div></td></tr></table></div>
</htmlpageheader>
<htmlpagefooter name="invoiceFooter">
    <div class="footer"><table><tr><td>{{ $invoice->invoice_no }}</td><td class="page">Page {PAGENO} / {nbpg}</td></tr></table></div>
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
            <tr><td class="number">{{ $itemIndex + 1 }}</td><td>{{ $item->item_name }}</td><td class="number">{{ $item->quantity }}</td><td class="number">{{ number_format((float) $item->unit_price, 2) }}</td><td class="number">{{ number_format((float) $item->amount, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if (! $loop->last)<pagebreak />@endif
@endforeach

<table class="totals" align="{{ app()->getLocale() === 'ar' ? 'left' : 'right' }}">
    <tr><th>{{ __('finance.fields.subtotal') }}</th><td>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($invoice->subtotal, $invoice->financeRequest?->acceptedCurrency) }}</td></tr>
    <tr><th>{{ __('finance.fields.deduction') }}</th><td>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount(-(float) $invoice->discount, $invoice->financeRequest?->acceptedCurrency) }}</td></tr>
    <tr class="grand"><th>{{ __('finance.fields.grand_total') }}</th><td>{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($invoice->total, $invoice->financeRequest?->acceptedCurrency) }}</td></tr>
</table>

@if ($invoice->notes)<div class="notes">{{ $invoice->notes }}</div>@endif
<div class="signature"><div class="signature-line">{{ __('finance.fields.signature') }}</div><small>{{ $invoice->finalisedBy?->name }}</small></div>
@unless($isPdf ?? false)<script>window.addEventListener('load', () => window.print());</script>@endunless
</body>
</html>

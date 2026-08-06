<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { size: A5 portrait; margin: 12mm; }
        @font-face { font-family: Dubai; src: url('{{ asset('fonts/dubai/Dubai-Regular.ttf') }}'); font-weight: 400; }
        @font-face { font-family: Dubai; src: url('{{ asset('fonts/dubai/Dubai-Bold.ttf') }}'); font-weight: 700; }
        body { font-family: Dubai, DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        h1 { margin: 0; text-align: center; font-size: 24px; }
        h2 { margin: 2px 0 12px; text-align: center; font-size: 14px; font-weight: 500; }
        .meta { margin-bottom: 16px; line-height: 1.9; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 7px; text-align: start; }
        th { background: #f3f4f6; }
        .total { margin-top: 12px; text-align: end; font-size: 15px; font-weight: 700; }
        .actions { margin-bottom: 12px; }
        .signature { margin-top: 24px; width: 45%; text-align: center; }
        .signature-line { border-top: 1px solid #111827; padding-top: 4px; }
        .signature small { display: block; color: #6b7280; margin-top: 2px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    @unless($isPdf ?? false)<div class="actions"><button type="button" onclick="window.print()">{{ __('finance.actions.print') }}</button></div>@endunless
    <h1>فاتورة</h1>
    <h2>جامع الخير - المهاجرين</h2>
    <div class="meta">
        <div>{{ __('finance.fields.invoice_issuer') }}: {{ $invoice->invoicer_name }} — {{ __('finance.fields.original_invoice_no') }}: {{ $invoice->original_invoice_no }}</div>
        <div>{{ __('finance.common.date') }}: {{ $invoice->issue_date?->format('d-m-Y') }}</div>
    </div>
    <table>
        <thead><tr><th>#</th><th>{{ __('finance.fields.item_name') }}</th><th>{{ __('finance.fields.quantity') }}</th><th>{{ __('finance.fields.unit_price') }}</th><th>{{ __('finance.fields.amount') }}</th></tr></thead>
        <tbody>@foreach ($invoice->items as $item)<tr><td>{{ $loop->iteration }}</td><td>{{ $item->item_name }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit_price }}</td><td>{{ $item->amount }}</td></tr>@endforeach</tbody>
        <tfoot>
            <tr><th colspan="4">{{ __('finance.fields.subtotal') }}</th><td><bdi dir="ltr">{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($invoice->subtotal, $invoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr>
            <tr><th colspan="4">{{ __('finance.fields.deduction') }}</th><td><bdi dir="ltr">{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount(-(float) $invoice->discount, $invoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr>
            <tr><th colspan="4">{{ __('finance.fields.grand_total') }}</th><td><bdi dir="ltr">{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($invoice->total, $invoice->financeRequest?->acceptedCurrency) }}</bdi></td></tr>
        </tfoot>
    </table>
    <div class="signature"><div class="signature-line">{{ __('finance.fields.signature') }}</div><small>{{ $invoice->finalisedBy?->name }}</small></div>
    @unless($isPdf ?? false)<script>window.addEventListener('load', () => window.print());</script>@endunless
</body>
</html>

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { size: A5 portrait; margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        h1 { margin: 0 0 12px; font-size: 22px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
        .meta div { border: 1px solid #d1d5db; padding: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 7px; text-align: start; }
        th { background: #f3f4f6; }
        .total { margin-top: 12px; text-align: end; font-size: 15px; font-weight: 700; }
        .actions { margin-bottom: 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">{{ __('finance.actions.print') }}</button></div>
    <h1>{{ __('finance.fields.invoice_no') }}: {{ $invoice->invoice_no }}</h1>
    <div class="meta">
        <div>{{ __('finance.fields.original_invoice_no') }}: {{ $invoice->original_invoice_no }}</div>
        <div>{{ __('finance.fields.invoice_issuer') }}: {{ $invoice->invoicer_name }}</div>
        <div>{{ __('finance.common.date') }}: {{ $invoice->issue_date?->format('d-m-Y') }}</div>
        <div>{{ __('finance.fields.request_no') }}: {{ $invoice->financeRequest?->request_no }}</div>
    </div>
    <table>
        <thead><tr><th>{{ __('finance.fields.item_name') }}</th><th>{{ __('finance.fields.quantity') }}</th><th>{{ __('finance.fields.unit_price') }}</th><th>{{ __('finance.fields.amount') }}</th></tr></thead>
        <tbody>@foreach ($invoice->items as $item)<tr><td>{{ $item->item_name }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit_price }}</td><td>{{ $item->amount }}</td></tr>@endforeach</tbody>
    </table>
    <div class="total"><bdi dir="ltr">{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($invoice->total, $invoice->financeRequest?->acceptedCurrency) }}</bdi></div>
</body>
</html>

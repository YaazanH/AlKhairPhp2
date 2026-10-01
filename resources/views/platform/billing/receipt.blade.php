<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; color: #17201a; padding: 20px; }
        .card { border: 1px solid #cbd5d0; padding: 22px; }
        h1 { color: #047857; margin: 0 0 6px; }
        .receipt-number { color: #52525b; margin: 0 0 20px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td:first-child { width: 38%; color: #52525b; }
        .amount { margin: 22px 0 8px; font-size: 26px; color: #047857; font-weight: bold; }
        .note { margin-top: 16px; padding: 12px; background: #f4f4f5; }
        .footer { margin-top: 18px; color: #52525b; font-size: 11px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Subscription payment receipt</h1>
    <p class="receipt-number">{{ $entry->receipt_number }}</p>
    <table>
        <tr><td>Tenant organisation</td><td>{{ $entry->tenant->name }}</td></tr>
        <tr><td>Payment date</td><td>{{ $entry->paid_at?->format('Y-m-d H:i') }}</td></tr>
        <tr><td>Payment method</td><td>{{ str($entry->payment_method)->replace('_', ' ')->title() }}</td></tr>
        <tr><td>Reference / receipt</td><td>{{ $entry->reference }}</td></tr>
        <tr><td>Recorded by</td><td>{{ $entry->recordedBy?->name ?? 'Platform' }}</td></tr>
    </table>
    <p class="amount">{{ number_format($entry->credit_syp) }} {{ $entry->currency }}</p>
    @if(filled($entry->note))<div class="note"><strong>Note</strong><br>{{ $entry->note }}</div>@endif
    <p class="footer">This payment was credited to the tenant prepaid subscription balance. Subscription payments are separate from the tenant organisation's internal finance records.</p>
</div>
</body>
</html>

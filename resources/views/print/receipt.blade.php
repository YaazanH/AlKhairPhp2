@php
    $invoiceTypeLabel = trans()->has('print.invoice.types.'.($payment->invoice?->invoice_type ?? ''))
        ? __('print.invoice.types.'.$payment->invoice?->invoice_type)
        : \Illuminate\Support\Str::headline((string) ($payment->invoice?->invoice_type ?: 'other'));
    $invoiceStatusLabel = trans()->has('print.invoice.statuses.'.($payment->invoice?->status ?? ''))
        ? __('print.invoice.statuses.'.$payment->invoice?->status)
        : __('print.invoice.statuses.unknown');
@endphp

<x-print.layout :title="__('print.receipt.title').' '.($payment->invoice?->invoice_no ?: '')">
    <div class="header">
        <div>
            <h1 class="title">{{ __('print.receipt.title') }}</h1>
            <div class="subtitle">{{ $organization['name'] ?: 'Alkhair' }}</div>
            @if ($organization['address'] || $organization['phone'] || $organization['email'])
                <div class="subtitle">
                    {{ $organization['address'] ?: '' }}
                    @if ($organization['phone'])
                        <span> | {{ $organization['phone'] }}</span>
                    @endif
                    @if ($organization['email'])
                        <span> | {{ $organization['email'] }}</span>
                    @endif
                </div>
            @endif
        </div>
        <div>
            <div class="meta-label">{{ __('print.receipt.receipt_for') }}</div>
            <div class="meta-value">{{ $payment->invoice?->invoice_no ?: __('print.receipt.invoice_payment') }}</div>
            <div class="subtitle">{{ $payment->voided_at ? __('print.receipt.voided_receipt') : __('print.receipt.active_receipt') }}</div>
        </div>
    </div>

    <div class="section meta-grid">
        <div class="meta-card">
            <span class="meta-label">{{ __('print.receipt.received_from') }}</span>
            <div class="meta-value">{{ $payment->invoice?->parentProfile?->father_name ?: __('print.receipt.unknown_parent') }}</div>
            <div class="subtitle">{{ __('print.receipt.invoice_type', ['type' => $invoiceTypeLabel]) }}</div>
        </div>
        <div class="meta-card">
            <span class="meta-label">{{ __('print.receipt.details') }}</span>
            <div class="meta-value">{{ __('print.receipt.date', ['date' => $payment->paid_at?->format('Y-m-d') ?: '-']) }}</div>
            <div class="subtitle">{{ __('print.receipt.method', ['method' => $payment->paymentMethod?->name ?: '-']) }}</div>
            <div class="subtitle">{{ __('print.receipt.reference', ['reference' => $payment->reference_no ?: '-']) }}</div>
        </div>
    </div>

    <div class="section">
        <h2>{{ __('print.receipt.summary') }}</h2>
        <table>
            <tbody>
                <tr>
                    <th>{{ __('print.receipt.headers.amount_received') }}</th>
                    <td>{{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
                <tr>
                    <th>{{ __('print.receipt.headers.collected_by') }}</th>
                    <td>{{ $payment->receivedBy?->name ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('print.receipt.headers.invoice_status') }}</th>
                    <td>{{ $invoiceStatusLabel }}</td>
                </tr>
                @if ($payment->voided_at)
                    <tr>
                        <th>{{ __('print.receipt.headers.void_details') }}</th>
                        <td>{{ __('print.receipt.voided_by', ['date' => $payment->voided_at?->format('Y-m-d H:i'), 'user' => $payment->voidedBy?->name ?: __('print.receipt.unknown_user')]) }}</td>
                    </tr>
                    <tr>
                        <th>{{ __('print.receipt.headers.void_reason') }}</th>
                        <td>{{ $payment->void_reason ?: '-' }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($payment->notes)
        <div class="section">
            <h2>{{ __('print.receipt.notes') }}</h2>
            <div class="note-box">{{ $payment->notes }}</div>
        </div>
    @endif
</x-print.layout>

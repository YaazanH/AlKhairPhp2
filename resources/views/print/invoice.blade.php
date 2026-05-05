@php
    $invoiceStatusLabel = trans()->has('print.invoice.statuses.'.$invoice->status)
        ? __('print.invoice.statuses.'.$invoice->status)
        : __('print.invoice.statuses.unknown');
    $invoiceTypeLabel = $invoice->invoiceKind?->name
        ?: (trans()->has('print.invoice.types.'.$invoice->invoice_type)
            ? __('print.invoice.types.'.$invoice->invoice_type)
            : \Illuminate\Support\Str::headline((string) $invoice->invoice_type));
@endphp

<x-print.layout :title="__('print.invoice.title').' '.$invoice->invoice_no">
    <div class="header">
        <div>
            <h1 class="title">{{ __('print.invoice.title') }}</h1>
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
            <div class="meta-label">{{ __('print.invoice.invoice_no') }}</div>
            <div class="meta-value">{{ $invoice->invoice_no }}</div>
            <div class="subtitle">{{ __('print.invoice.status', ['status' => $invoiceStatusLabel]) }}</div>
        </div>
    </div>

    <div class="section meta-grid">
        <div class="meta-card">
            <span class="meta-label">{{ __('finance.fields.invoicer_name') }}</span>
            <div class="meta-value">{{ $invoice->invoicer_name ?: ($invoice->parentProfile?->father_name ?: '-') }}</div>
            <div class="subtitle">{{ $invoice->financeRequest?->request_no ?: '' }}</div>
        </div>
        <div class="meta-card">
            <span class="meta-label">{{ __('print.invoice.dates') }}</span>
            <div class="meta-value">{{ __('print.invoice.issued', ['date' => $invoice->issue_date?->format('Y-m-d') ?: '-']) }}</div>
            <div class="subtitle">{{ __('print.invoice.due', ['date' => $invoice->due_date?->format('Y-m-d') ?: '-']) }}</div>
            <div class="subtitle">{{ __('print.invoice.type', ['type' => $invoiceTypeLabel]) }}</div>
        </div>
    </div>

    <div class="section">
        <h2>{{ __('print.invoice.items') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('print.invoice.headers.description') }}</th>
                    <th>#</th>
                    <th>{{ __('print.invoice.headers.qty') }}</th>
                    <th>{{ __('print.invoice.headers.unit_price') }}</th>
                    <th>{{ __('print.invoice.headers.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->items as $item)
                    <tr>
                        <td>
                            {{ $item->item_name ?: $item->description }}
                            @if ($item->student)
                                <div class="subtitle">{{ $item->student->first_name }} {{ $item->student->last_name }}</div>
                            @endif
                        </td>
                        <td>{{ $item->line_no ?: $loop->iteration }}</td>
                        <td>{{ number_format((float) $item->quantity, 2) }}</td>
                        <td>{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td>{{ number_format((float) $item->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">{{ __('print.invoice.empty_items') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>{{ __('print.invoice.payment_summary') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('print.invoice.headers.date') }}</th>
                    <th>{{ __('print.invoice.headers.method') }}</th>
                    <th>{{ __('print.invoice.headers.reference') }}</th>
                    <th>{{ __('print.invoice.headers.state') }}</th>
                    <th>{{ __('print.invoice.headers.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at?->format('Y-m-d') ?: '-' }}</td>
                        <td>{{ $payment->paymentMethod?->name ?: '-' }}</td>
                        <td>{{ $payment->reference_no ?: '-' }}</td>
                        <td>{{ __('print.states.'.($payment->voided_at ? 'voided' : 'active')) }}</td>
                        <td>{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">{{ __('print.invoice.empty_payments') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="totals">
            <div class="totals-row"><span>{{ __('print.invoice.totals.subtotal') }}</span><span>{{ number_format((float) $invoice->subtotal, 2) }}</span></div>
            <div class="totals-row"><span>{{ __('print.invoice.totals.discount') }}</span><span>{{ number_format((float) $invoice->discount, 2) }}</span></div>
            <div class="totals-row"><span>{{ __('print.invoice.totals.paid') }}</span><span>{{ number_format($activePaidTotal, 2) }}</span></div>
            <div class="totals-row"><strong>{{ __('print.invoice.totals.balance') }}</strong><strong>{{ number_format(max((float) $invoice->total - $activePaidTotal, 0), 2) }}</strong></div>
        </div>
    </div>

    @if ($invoice->notes)
        <div class="section">
            <h2>{{ __('print.invoice.notes') }}</h2>
            <div class="note-box">{{ $invoice->notes }}</div>
        </div>
    @endif
</x-print.layout>

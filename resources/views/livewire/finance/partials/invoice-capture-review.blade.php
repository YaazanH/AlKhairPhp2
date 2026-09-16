@if ($invoiceCaptureDraft)
    @php($captureCurrency = $finalisingRequest?->postedTransaction?->currency ?? $finalisingRequest?->acceptedCurrency ?? $finalisingRequest?->requestedCurrency)
    <section class="invoice-capture-review" data-invoice-capture-review>
        <p class="mt-1 text-sm opacity-75">{{ __('invoice_capture.review_help') }}</p>
        <div class="mt-4 grid min-w-0 gap-5 lg:grid-cols-2">
            <div x-show="captureUrl" class="min-w-0 lg:sticky lg:top-0 lg:self-start" wire:ignore>
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="text-sm font-medium">{{ __('invoice_capture.original') }}</span>
                    <a x-bind:href="captureUrl" target="_blank" rel="noopener" class="text-sm underline">{{ __('invoice_capture.open_original') }}</a>
                </div>
                <template x-if="captureUrl && captureMime !== 'application/pdf'">
                    <img x-bind:src="captureUrl" alt="{{ __('invoice_capture.original') }}" class="invoice-capture-document rounded-xl border border-white/10 bg-white object-contain">
                </template>
                <template x-if="captureUrl && captureMime === 'application/pdf'">
                    <iframe x-bind:src="captureUrl + '#toolbar=0&navpanes=0&view=FitH'" title="{{ __('invoice_capture.original') }}" class="invoice-capture-document rounded-xl border border-white/10 bg-white"></iframe>
                </template>
            </div>
            <div class="min-w-0 space-y-4">
                @if ($invoiceCaptureDraft['requires_amount_review'] ?? false)
                    <p class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm" data-invoice-capture-correction>{{ __('invoice_capture.correction_help') }}</p>
                @endif
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    @foreach (['original_invoice_no' => __('finance.fields.original_invoice_no'), 'invoice_issuer' => __('finance.fields.invoice_issuer'), 'invoice_date' => __('finance.common.date'), 'currency' => __('invoice_capture.transaction_currency'), 'invoice_deduction' => __('finance.fields.deduction'), 'total' => __('invoice_capture.detected_total')] as $field => $label)
                        @continue(($invoiceCaptureDraft['requires_amount_review'] ?? false) && in_array($field, ['invoice_deduction', 'total'], true))
                        <div class="min-w-0 rounded-xl bg-black/5 p-3 dark:bg-white/5">
                            <dt class="mb-1 opacity-70">{{ $label }}</dt>
                            <dd class="break-words text-start font-medium" @if ($field !== 'invoice_issuer') data-invoice-capture-number @endif @if ($field === 'currency') data-invoice-capture-currency @endif>
                                @if ($field === 'original_invoice_no' && filled($invoiceCaptureDraft[$field] ?? null))
                                    <bdi dir="ltr">{{ \App\Models\Invoice::formatOriginalInvoiceNumber($invoiceCaptureDraft[$field]) ?: '—' }}</bdi>
                                @elseif ($field === 'invoice_date' && filled($invoiceCaptureDraft[$field] ?? null))
                                    <bdi dir="ltr">{{ \Illuminate\Support\Carbon::parse($invoiceCaptureDraft[$field])->format('d-m-Y') }}</bdi>
                                @elseif (in_array($field, ['invoice_deduction', 'total'], true) && isset($invoiceCaptureDraft[$field]))
                                    <bdi dir="ltr">{{ app(\App\Services\FinanceService::class)->formatCurrencyAmount($invoiceCaptureDraft[$field], $captureCurrency) }}</bdi>
                                @else
                                    <bdi>{{ $invoiceCaptureDraft[$field] ?? __('invoice_capture.not_detected') }}</bdi>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
                @foreach ($invoiceCaptureDraft['warnings'] as $warning)
                    @continue($warning === 'total_mismatch')
                    <p class="rounded-lg border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-sm" data-invoice-capture-warning="{{ $warning }}">{{ __('invoice_capture.warnings.'.$warning) }}</p>
                @endforeach
                @if ($invoiceCaptureDraft['notes'] ?? [])
                    <div class="rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-sm">
                        <p class="mb-2 font-medium">{{ __('invoice_capture.notes') }}</p>
                        @foreach ($invoiceCaptureDraft['notes'] as $note)
                            <p class="mt-1 text-start" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">{{ $note }}</p>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @include('livewire.finance.partials.invoice-capture-items')
        @if ($invoiceCaptureDraft['text'] ?? '')
        <details class="mt-4 text-sm">
            <summary class="cursor-pointer font-medium">{{ __('invoice_capture.raw_text') }}</summary>
            <pre class="mt-2 max-h-52 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-black/5 p-3 font-sans dark:bg-white/5" dir="auto">{{ $invoiceCaptureDraft['text'] }}</pre>
        </details>
        @endif
    </section>
@endif

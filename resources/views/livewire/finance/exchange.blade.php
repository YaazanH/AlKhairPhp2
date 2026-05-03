<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceCurrency;
use App\Models\FinanceCurrencyExchange;
use App\Services\FinanceService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $from_cash_box_id = null;
    public ?int $to_cash_box_id = null;
    public ?int $from_currency_id = null;
    public ?int $to_currency_id = null;
    public string $from_amount = '';
    public string $to_amount = '';
    public string $exchange_date = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->authorizePermission('finance.exchange.view');
        $this->exchange_date = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'fromCashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->from_currency_id)->get(),
            'toCashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->to_currency_id)->get(),
            'fromCurrencies' => app(FinanceService::class)->currenciesForCashBox($this->from_cash_box_id)->get(),
            'toCurrencies' => app(FinanceService::class)->currenciesForCashBox($this->to_cash_box_id)->get(),
            'exchanges' => FinanceCurrencyExchange::query()->with(['fromCashBox', 'toCashBox', 'fromCurrency', 'toCurrency', 'enteredBy'])->latest('exchange_date')->latest('id')->limit(50)->get(),
        ];
    }

    public function saveExchange(): void
    {
        $this->authorizePermission('finance.exchange.create');

        $validated = $this->validate([
            'exchange_date' => ['required', 'date'],
            'from_amount' => ['required', 'numeric', 'gt:0'],
            'from_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'from_currency_id' => ['required', 'different:to_currency_id', 'exists:finance_currencies,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'to_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'to_currency_id' => ['required', 'exists:finance_currencies,id'],
        ]);

        $this->calculateToAmount();

        if ((float) $this->to_amount <= 0) {
            $this->addError('to_amount', __('finance.validation.cash_box_currency_mismatch'));

            return;
        }

        app(FinanceService::class)->recordCurrencyExchange(
            app(FinanceService::class)->cashBoxForUser((int) $validated['from_cash_box_id'], auth()->user()),
            FinanceCurrency::query()->findOrFail($validated['from_currency_id']),
            (float) $validated['from_amount'],
            app(FinanceService::class)->cashBoxForUser((int) $validated['to_cash_box_id'], auth()->user()),
            FinanceCurrency::query()->findOrFail($validated['to_currency_id']),
            (float) $this->to_amount,
            $validated['exchange_date'],
            auth()->user(),
            $validated['notes'] ?: null,
        );

        $this->reset(['from_cash_box_id', 'to_cash_box_id', 'from_currency_id', 'to_currency_id', 'from_amount', 'to_amount', 'notes']);
        $this->exchange_date = now()->toDateString();
        session()->flash('status', __('finance.messages.exchange_posted'));
    }

    public function updated($property): void
    {
        if (in_array($property, ['from_amount', 'from_currency_id', 'to_currency_id'], true)) {
            $this->calculateToAmount();
        }

        if ($property === 'from_currency_id' && $this->from_cash_box_id && $this->from_currency_id && ! app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->from_currency_id)->whereKey($this->from_cash_box_id)->exists()) {
            $this->from_cash_box_id = null;
        }

        if ($property === 'to_currency_id' && $this->to_cash_box_id && $this->to_currency_id && ! app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->to_currency_id)->whereKey($this->to_cash_box_id)->exists()) {
            $this->to_cash_box_id = null;
        }

        if ($property === 'from_cash_box_id' && $this->from_cash_box_id && $this->from_currency_id && ! app(FinanceService::class)->currenciesForCashBox($this->from_cash_box_id)->whereKey($this->from_currency_id)->exists()) {
            $this->from_currency_id = app(FinanceService::class)->currenciesForCashBox($this->from_cash_box_id)->value('id');
            $this->calculateToAmount();
        }

        if ($property === 'to_cash_box_id' && $this->to_cash_box_id && $this->to_currency_id && ! app(FinanceService::class)->currenciesForCashBox($this->to_cash_box_id)->whereKey($this->to_currency_id)->exists()) {
            $this->to_currency_id = app(FinanceService::class)->currenciesForCashBox($this->to_cash_box_id)->value('id');
            $this->calculateToAmount();
        }
    }

    protected function calculateToAmount(): void
    {
        $fromAmount = (float) $this->from_amount;
        $fromCurrency = $this->from_currency_id ? FinanceCurrency::query()->find($this->from_currency_id) : null;
        $toCurrency = $this->to_currency_id ? FinanceCurrency::query()->find($this->to_currency_id) : null;

        if ($fromAmount <= 0 || ! $fromCurrency || ! $toCurrency || (float) $toCurrency->rate_to_base <= 0) {
            $this->to_amount = '';

            return;
        }

        $this->to_amount = number_format(($fromAmount * (float) $fromCurrency->rate_to_base) / (float) $toCurrency->rate_to_base, 2, '.', '');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.exchange.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.exchange.subtitle') }}</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif
    @error('amount') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror
    @error('currency_id') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror

    @can('finance.exchange.create')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ __('finance.exchange.new') }}</div>
            <form wire:submit="saveExchange" class="mt-5 grid gap-4 lg:grid-cols-4">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.exchange.from_box') }}</label><select wire:model.live="from_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($fromCashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.exchange.from_currency') }}</label><select wire:model.live="from_currency_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.options.currency') }}</option>@foreach ($fromCurrencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.exchange.from_amount') }}</label><input wire:model.live="from_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.date') }}</label><input wire:model="exchange_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.exchange.to_box') }}</label><select wire:model.live="to_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($toCashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.exchange.to_currency') }}</label><select wire:model.live="to_currency_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.options.currency') }}</option>@foreach ($toCurrencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.exchange.to_amount') }}</label><input wire:model="to_amount" type="number" min="0" step="0.01" readonly class="w-full rounded-xl px-4 py-3 text-sm opacity-75"></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.notes') }}</label><input wire:model="notes" type="text" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                @error('from_currency_id') <div class="lg:col-span-4 text-sm text-red-400">{{ $message }}</div> @enderror
                <div class="lg:col-span-4"><button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.post_exchange') }}</button></div>
            </form>
        </section>
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.exchange.history') }}</div></div></div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.date') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.from') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.to') }}</th><th class="px-5 py-3 text-left">{{ __('finance.exchange.rates') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.user') }}</th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @foreach ($exchanges as $exchange)
                        <tr><td class="px-5 py-3">{{ $exchange->exchange_date?->format('Y-m-d') }}</td><td class="px-5 py-3">{{ number_format((float) $exchange->from_amount, 2) }} {{ $exchange->fromCurrency?->code }} | {{ $exchange->fromCashBox?->name }}</td><td class="px-5 py-3">{{ number_format((float) $exchange->to_amount, 2) }} {{ $exchange->toCurrency?->code }} | {{ $exchange->toCashBox?->name }}</td><td class="px-5 py-3">{{ number_format((float) $exchange->from_rate_to_base, 8) }} / {{ number_format((float) $exchange->to_rate_to_base, 8) }}</td><td class="px-5 py-3">{{ $exchange->enteredBy?->name ?: '-' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>

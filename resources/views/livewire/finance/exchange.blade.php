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
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxes(auth()->user())->get(),
            'currencies' => FinanceCurrency::query()->where('is_active', true)->orderByDesc('is_local')->orderByDesc('is_base')->orderBy('code')->get(),
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
            'to_amount' => ['required', 'numeric', 'gt:0'],
            'to_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'to_currency_id' => ['required', 'exists:finance_currencies,id'],
        ]);

        app(FinanceService::class)->recordCurrencyExchange(
            app(FinanceService::class)->cashBoxForUser((int) $validated['from_cash_box_id'], auth()->user()),
            FinanceCurrency::query()->findOrFail($validated['from_currency_id']),
            (float) $validated['from_amount'],
            app(FinanceService::class)->cashBoxForUser((int) $validated['to_cash_box_id'], auth()->user()),
            FinanceCurrency::query()->findOrFail($validated['to_currency_id']),
            (float) $validated['to_amount'],
            $validated['exchange_date'],
            auth()->user(),
            $validated['notes'] ?: null,
        );

        $this->reset(['from_cash_box_id', 'to_cash_box_id', 'from_currency_id', 'to_currency_id', 'from_amount', 'to_amount', 'notes']);
        $this->exchange_date = now()->toDateString();
        session()->flash('status', 'Currency exchange posted.');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">Exchange</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">Record actual currency conversions. Each exchange saves the rate snapshots at the time of posting.</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif

    @can('finance.exchange.create')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">New exchange</div>
            <form wire:submit="saveExchange" class="mt-5 grid gap-4 lg:grid-cols-4">
                <div><label class="mb-1 block text-sm font-medium">From box</label><select wire:model="from_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Choose box</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">From currency</label><select wire:model="from_currency_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Currency</option>@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">From amount</label><input wire:model="from_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium">Date</label><input wire:model="exchange_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium">To box</label><select wire:model="to_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Choose box</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">To currency</label><select wire:model="to_currency_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Currency</option>@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">To amount</label><input wire:model="to_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                <div><label class="mb-1 block text-sm font-medium">Notes</label><input wire:model="notes" type="text" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                @error('from_currency_id') <div class="lg:col-span-4 text-sm text-red-400">{{ $message }}</div> @enderror
                <div class="lg:col-span-4"><button type="submit" class="pill-link pill-link--accent">Post exchange</button></div>
            </form>
        </section>
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">Exchange history</div></div></div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr><th class="px-5 py-3 text-left">Date</th><th class="px-5 py-3 text-left">From</th><th class="px-5 py-3 text-left">To</th><th class="px-5 py-3 text-left">Rates</th><th class="px-5 py-3 text-left">User</th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @foreach ($exchanges as $exchange)
                        <tr><td class="px-5 py-3">{{ $exchange->exchange_date?->format('Y-m-d') }}</td><td class="px-5 py-3">{{ number_format((float) $exchange->from_amount, 2) }} {{ $exchange->fromCurrency?->code }} | {{ $exchange->fromCashBox?->name }}</td><td class="px-5 py-3">{{ number_format((float) $exchange->to_amount, 2) }} {{ $exchange->toCurrency?->code }} | {{ $exchange->toCashBox?->name }}</td><td class="px-5 py-3">{{ number_format((float) $exchange->from_rate_to_base, 8) }} / {{ number_format((float) $exchange->to_rate_to_base, 8) }}</td><td class="px-5 py-3">{{ $exchange->enteredBy?->name ?: '-' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\FinanceCurrency;
use App\Models\FinanceTransaction;
use App\Services\FinanceService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $adjust_cash_box_id = null;
    public ?int $adjust_currency_id = null;
    public string $adjust_direction = 'in';
    public string $adjust_amount = '';
    public string $adjust_description = '';

    public ?int $transfer_from_cash_box_id = null;
    public ?int $transfer_to_cash_box_id = null;
    public ?int $transfer_currency_id = null;
    public string $transfer_amount = '';
    public string $transfer_date = '';
    public string $transfer_notes = '';

    public function mount(): void
    {
        $this->authorizePermission('finance.cash-box.view');
        $this->transfer_date = now()->toDateString();
        $this->adjust_currency_id = app(FinanceService::class)->localCurrency()->id;
        $this->transfer_currency_id = app(FinanceService::class)->localCurrency()->id;
    }

    public function with(): array
    {
        $cashBoxes = app(FinanceService::class)->accessibleCashBoxes(auth()->user())->get();

        return [
            'balances' => app(FinanceService::class)->cashBoxBalances(auth()->user()),
            'cashBoxes' => $cashBoxes,
            'currencies' => FinanceCurrency::query()->where('is_active', true)->orderByDesc('is_local')->orderByDesc('is_base')->orderBy('code')->get(),
            'recentTransactions' => FinanceTransaction::query()
                ->with(['cashBox', 'currency', 'enteredBy'])
                ->whereIn('cash_box_id', $cashBoxes->pluck('id'))
                ->latest('transaction_date')
                ->latest('id')
                ->limit(25)
                ->get(),
        ];
    }

    public function adjust(): void
    {
        $this->authorizePermission('finance.cash-box.adjust');

        $validated = $this->validate([
            'adjust_amount' => ['required', 'numeric', 'gt:0'],
            'adjust_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'adjust_currency_id' => ['required', 'exists:finance_currencies,id'],
            'adjust_description' => ['required', 'string', 'max:500'],
            'adjust_direction' => ['required', 'in:in,out'],
        ]);

        app(FinanceService::class)->postTransaction([
            'cash_box_id' => app(FinanceService::class)->cashBoxForUser((int) $validated['adjust_cash_box_id'], auth()->user())->id,
            'currency_id' => $validated['adjust_currency_id'],
            'type' => 'manual_adjustment',
            'direction' => $validated['adjust_direction'],
            'amount' => $validated['adjust_amount'],
            'transaction_date' => now()->toDateString(),
            'description' => $validated['adjust_description'],
            'entered_by' => auth()->id(),
        ]);

        $this->reset(['adjust_cash_box_id', 'adjust_amount', 'adjust_description']);
        session()->flash('status', 'Cash box adjustment posted.');
    }

    public function transfer(): void
    {
        $this->authorizePermission('finance.cash-box.transfer');

        $validated = $this->validate([
            'transfer_amount' => ['required', 'numeric', 'gt:0'],
            'transfer_currency_id' => ['required', 'exists:finance_currencies,id'],
            'transfer_date' => ['required', 'date'],
            'transfer_from_cash_box_id' => ['required', 'different:transfer_to_cash_box_id', 'exists:finance_cash_boxes,id'],
            'transfer_notes' => ['nullable', 'string', 'max:500'],
            'transfer_to_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
        ]);

        app(FinanceService::class)->recordCashBoxTransfer(
            app(FinanceService::class)->cashBoxForUser((int) $validated['transfer_from_cash_box_id'], auth()->user()),
            app(FinanceService::class)->cashBoxForUser((int) $validated['transfer_to_cash_box_id'], auth()->user()),
            FinanceCurrency::query()->findOrFail($validated['transfer_currency_id']),
            (float) $validated['transfer_amount'],
            $validated['transfer_date'],
            auth()->user(),
            $validated['transfer_notes'] ?: null,
        );

        $this->reset(['transfer_from_cash_box_id', 'transfer_to_cash_box_id', 'transfer_amount', 'transfer_notes']);
        $this->transfer_date = now()->toDateString();
        session()->flash('status', 'Cash box transfer posted.');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">Cash Box</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">View balances by cash box and currency. Local equivalents use the current exchange settings.</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($balances as $boxBalance)
            <article class="stat-card">
                <div class="kpi-label">{{ $boxBalance['cash_box']->name }}</div>
                <div class="metric-value mt-3">{{ number_format($boxBalance['local_total'], 2) }}</div>
                <div class="mt-3 space-y-1 text-sm text-neutral-300">
                    @foreach ($boxBalance['currencies'] as $row)
                        <div class="flex justify-between gap-3"><span>{{ $row['currency']->code }}</span><span>{{ number_format($row['balance'], 2) }}</span></div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        @can('finance.cash-box.adjust')
            <div class="surface-panel p-5 lg:p-6">
                <div class="admin-section-card__title">Manual adjustment</div>
                <form wire:submit="adjust" class="mt-5 grid gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block text-sm font-medium">Cash box</label><select wire:model="adjust_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Choose box</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('adjust_cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">Currency</label><select wire:model="adjust_currency_id" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                    <div><label class="mb-1 block text-sm font-medium">Direction</label><select wire:model="adjust_direction" class="w-full rounded-xl px-4 py-3 text-sm"><option value="in">In</option><option value="out">Out</option></select></div>
                    <div><label class="mb-1 block text-sm font-medium">Amount</label><input wire:model="adjust_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">@error('adjust_amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                    <div class="md:col-span-2"><label class="mb-1 block text-sm font-medium">Description</label><input wire:model="adjust_description" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('adjust_description') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                    <div class="md:col-span-2"><button type="submit" class="pill-link pill-link--accent">Post adjustment</button></div>
                </form>
            </div>
        @endcan

        @can('finance.cash-box.transfer')
            <div class="surface-panel p-5 lg:p-6">
                <div class="admin-section-card__title">Transfer between boxes</div>
                <form wire:submit="transfer" class="mt-5 grid gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block text-sm font-medium">From</label><select wire:model="transfer_from_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Choose box</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('transfer_from_cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">To</label><select wire:model="transfer_to_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">Choose box</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select></div>
                    <div><label class="mb-1 block text-sm font-medium">Currency</label><select wire:model="transfer_currency_id" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                    <div><label class="mb-1 block text-sm font-medium">Amount</label><input wire:model="transfer_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                    <div><label class="mb-1 block text-sm font-medium">Date</label><input wire:model="transfer_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                    <div><label class="mb-1 block text-sm font-medium">Notes</label><input wire:model="transfer_notes" type="text" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                    <div class="md:col-span-2"><button type="submit" class="pill-link pill-link--accent">Post transfer</button></div>
                </form>
            </div>
        @endcan
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">Recent cash-box transactions</div></div></div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">Date</th><th class="px-5 py-3 text-left">Box</th><th class="px-5 py-3 text-left">Type</th><th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">User</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($recentTransactions as $transaction)<tr><td class="px-5 py-3">{{ $transaction->transaction_date?->format('Y-m-d') }}</td><td class="px-5 py-3">{{ $transaction->cashBox?->name }}</td><td class="px-5 py-3">{{ str_replace('_', ' ', $transaction->type) }}</td><td class="px-5 py-3">{{ number_format((float) $transaction->signed_amount, 2) }} {{ $transaction->currency?->code }}</td><td class="px-5 py-3">{{ $transaction->enteredBy?->name ?: '-' }}</td></tr>@endforeach</tbody></table></div>
    </section>
</div>

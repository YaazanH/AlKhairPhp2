<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Livewire\Concerns\HandlesFinanceRequestMaintenance;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceRequest;
use App\Services\FinanceService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;
    use HandlesFinanceRequestMaintenance;
    use WithPagination;

    public string $request_type = 'revenue';

    public string $amount = '';

    public string $request_date = '';

    public ?int $currency_id = null;

    public ?int $cash_box_id = null;

    public ?int $finance_category_id = null;

    public string $counterparty_name = '';

    public string $requested_reason = '';

    public array $review_amounts = [];

    public array $review_cash_boxes = [];

    public array $review_dates = [];

    public array $review_notes = [];

    public int $perPage = 15;

    public bool $showCreateModal = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.revenue-requests.view');
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
    }

    public function with(): array
    {
        $canReview = auth()->user()?->can('finance.revenue-requests.review') ?? false;
        $canChooseCashBox = auth()->user()?->can('finance.entries.update') ?? false;
        $financeService = app(FinanceService::class);

        return [
            'cashBoxes' => $financeService->accessibleCashBoxes(auth()->user())->get(),
            'cashBoxesByCurrency' => FinanceCurrency::query()
                ->where('is_active', true)
                ->pluck('id')
                ->mapWithKeys(fn ($currencyId) => [(int) $currencyId => $financeService->accessibleCashBoxesForCurrency(auth()->user(), (int) $currencyId)->get()])
                ->all(),
            'currencies' => $financeService->currenciesForCashBox($canChooseCashBox ? $this->cash_box_id : null)
                ->when($canChooseCashBox && ! $this->cash_box_id, fn ($query) => $query->whereRaw('1 = 0'))
                ->get(),
            'revenueCategories' => FinanceCategory::query()
                ->where('is_active', true)
                ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])
                ->orderBy('name')
                ->get(),
            'selectedRevenueCategory' => FinanceCategory::query()->find($this->finance_category_id),
            'requests' => FinanceRequest::query()
                ->with(['activity', 'cashBox', 'category', 'postedTransaction', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency'])
                ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])
                ->where('status', FinanceRequest::STATUS_ACCEPTED)
                ->whereHas('postedTransaction')
                ->when(! $canReview, fn ($query) => $query->where('requested_by', auth()->id()))
                ->latest()
                ->paginate($this->perPage),
        ];
    }

    public function submitRequest(): void
    {
        $this->authorizePermission('finance.revenue-requests.create');

        $canReview = auth()->user()?->can('finance.revenue-requests.review') ?? false;
        $this->normalizeFinanceNumberProperty('amount');
        if (auth()->user()?->can('finance.entries.update') && blank($this->request_date)) {
            $this->request_date = now()->toDateString();
        }

        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_box_id' => ['nullable', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'exists:finance_currencies,id'],
            'finance_category_id' => [
                'required',
                Rule::exists('finance_categories', 'id')
                    ->where('is_active', true)
                    ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN]),
            ],
            'counterparty_name' => [FinanceCategory::query()->whereKey($this->finance_category_id)->where('is_donation', true)->exists() ? 'required' : 'nullable', 'string', 'max:255'],
            'request_date' => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
            'requested_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $category = FinanceCategory::query()
            ->whereKey($validated['finance_category_id'])
            ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])
            ->firstOrFail();
        $requestType = $category->type;
        $requestedReason = trim((string) ($validated['requested_reason'] ?? ''));

        if ($category->is_donation && $requestedReason === '') {
            $requestedReason = $this->donationDefaultReason();
        }

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber($requestType),
            'type' => $requestType,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => $validated['currency_id'],
            'requested_amount' => $validated['amount'],
            'finance_category_id' => $category->id,
            'counterparty_name' => $category->is_donation ? ($validated['counterparty_name'] ?: null) : null,
            'requested_by' => auth()->id(),
            'requested_reason' => $requestedReason !== '' ? $requestedReason : null,
        ]);

        if ($canReview) {
            $postingCashBox = $validated['cash_box_id']
                ? app(FinanceService::class)->cashBoxForUser((int) $validated['cash_box_id'], auth()->user())
                : app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), (int) $validated['currency_id']);

            if (! $postingCashBox || ! app(FinanceService::class)->currenciesForCashBox($postingCashBox->id)->whereKey($validated['currency_id'])->exists()) {
                $request->delete();
                $this->addError('currency_id', __('finance.validation.cash_box_currency_mismatch'));

                return;
            }

            try {
                app(FinanceService::class)->acceptRequest(
                    $request,
                    (float) $validated['amount'],
                    $postingCashBox,
                    auth()->user(),
                    'Auto-posted by finance management.',
                    null,
                    auth()->user()?->can('finance.entries.update') ? $validated['request_date'] : null,
                );
            } catch (ValidationException $exception) {
                $request->delete();
                $this->addError('amount', $this->firstValidationMessage($exception));

                return;
            }
        }

        $this->resetCreateForm();
        $this->showCreateModal = false;
        session()->flash('status', $canReview ? __('finance.messages.revenue_posted') : __('finance.messages.revenue_sent'));
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('finance.revenue-requests.create');

        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = false;
    }

    public function updatedCashBoxId(): void
    {
        if (! $this->cash_box_id) {
            $this->currency_id = null;

            return;
        }

        $currencies = app(FinanceService::class)->currenciesForCashBox($this->cash_box_id);

        if (! $this->currency_id || ! (clone $currencies)->whereKey($this->currency_id)->exists()) {
            $this->currency_id = (clone $currencies)->value('id');
        }
    }

    public function updatedCurrencyId(): void
    {
        if ($this->cash_box_id && $this->currency_id && ! app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->whereKey($this->currency_id)->exists()) {
            $this->currency_id = app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->value('id');
        }
    }

    public function updatedRequestType(): void
    {
        $this->finance_category_id = $this->defaultCategoryIdForType($this->request_type);
    }

    public function updatedFinanceCategoryId(): void
    {
        $category = FinanceCategory::query()
            ->whereKey($this->finance_category_id)
            ->first();
        $categoryType = $category?->type;

        if (in_array($categoryType, [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN], true)) {
            $this->request_type = $categoryType;
        }

        if ($category?->is_donation) {
            if (blank($this->requested_reason)) {
                $this->requested_reason = $this->donationDefaultReason();
            }
        } else {
            $this->counterparty_name = '';

            if ($this->requested_reason === $this->donationDefaultReason()) {
                $this->requested_reason = '';
            }
        }
    }

    public function accept(int $requestId): void
    {
        $this->authorizePermission('finance.revenue-requests.review');

        $request = FinanceRequest::query()->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])->findOrFail($requestId);
        $this->normalizeFinanceNumberArrayValue('review_amounts', $requestId);
        if (auth()->user()?->can('finance.entries.update') && blank($this->review_dates[$requestId] ?? null)) {
            $this->review_dates[$requestId] = now()->toDateString();
        }

        $this->validate([
            "review_amounts.{$requestId}" => ['nullable', 'numeric', 'gt:0'],
            "review_cash_boxes.{$requestId}" => ['required', 'exists:finance_cash_boxes,id'],
            "review_dates.{$requestId}" => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
        ]);

        $reviewAmount = $this->review_amounts[$requestId] ?? null;

        try {
            app(FinanceService::class)->acceptRequest(
                $request,
                (float) (($reviewAmount === null || $reviewAmount === '') ? $request->requested_amount : $reviewAmount),
                app(FinanceService::class)->cashBoxForUser((int) $this->review_cash_boxes[$requestId], auth()->user()),
                auth()->user(),
                $this->review_notes[$requestId] ?? null,
                null,
                auth()->user()?->can('finance.entries.update') ? ($this->review_dates[$requestId] ?? null) : null,
            );
        } catch (ValidationException $exception) {
            $this->addError("review_amounts.{$requestId}", $this->firstValidationMessage($exception));

            return;
        }

        session()->flash('status', __('finance.messages.revenue_accepted'));
    }

    public function decline(int $requestId): void
    {
        $this->authorizePermission('finance.revenue-requests.review');

        app(FinanceService::class)->declineRequest(FinanceRequest::query()->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])->findOrFail($requestId), auth()->user(), $this->review_notes[$requestId] ?? null);
        session()->flash('status', __('finance.messages.revenue_declined'));
    }

    protected function resetCreateForm(): void
    {
        $this->request_type = 'revenue';
        $this->amount = '';
        $this->request_date = now()->toDateString();
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
        $this->cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $this->currency_id)?->id;
        $this->finance_category_id = app(FinanceService::class)->defaultRevenueCategoryId();
        $this->counterparty_name = '';
        $this->requested_reason = '';
        $this->updatedFinanceCategoryId();
        $this->resetValidation();
    }

    protected function donationDefaultReason(): string
    {
        return __('finance.descriptions.donation_income_default', [], 'ar');
    }

    protected function defaultCategoryIdForType(string $type): ?int
    {
        return FinanceCategory::query()
            ->where('is_active', true)
            ->where('type', $type)
            ->orderBy('name')
            ->value('id');
    }

    protected function financeRequestMaintenanceTypes(): array
    {
        return [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN];
    }

    protected function firstValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (is_array($messages) && isset($messages[0])) {
                return (string) $messages[0];
            }
        }

        return $exception->getMessage();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.revenue_requests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.revenue_requests.subtitle') }}</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif
    @error('amount') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror
    @error('currency_id') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror

    <x-admin.modal
        :show="$showCreateModal"
        :title="__('finance.revenue_requests.new')"
        :description="__('finance.revenue_requests.subtitle')"
        close-method="closeCreateModal"
        max-width="3xl"
    >
        <form wire:submit="submitRequest" class="space-y-4" data-finance-entry-create-form>
            <div class="finance-entry-form__row grid gap-4 lg:grid-cols-2" data-finance-entry-primary-row>
                <div data-finance-entry-type><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.revenue_kind') }}</label><select wire:model.live="finance_category_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_category') }}</option>@foreach ($revenueCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('finance_category_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                @can('finance.entries.update')<div data-finance-entry-date><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.entry_date') }}</label><input wire:model="request_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error('request_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
            </div>
            @if ($selectedRevenueCategory?->is_donation)<div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.revenue_name') }}</label><input wire:model="counterparty_name" type="text" placeholder="{{ __('finance.fields.revenue_name') }}" class="w-full rounded-xl px-4 py-3 text-sm">@error('counterparty_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endif
            <div class="finance-entry-form__row grid gap-4 lg:grid-cols-2" data-finance-entry-value-row>
                @can('finance.entries.update')<div data-finance-entry-fund><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label><select wire:model.live="cash_box_id" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
                <div data-finance-entry-amount data-donor-row-compact="{{ $selectedRevenueCategory?->is_donation ? 'true' : 'false' }}"><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.amount') }}</label><x-finance.amount-input amount-model="amount" currency-model="currency_id" :currencies="$currencies" />@error('amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror @error('currency_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            </div>
            <div data-finance-entry-description><label class="mb-1 block text-sm font-medium">{{ __('finance.common.description') }}</label><textarea wire:model="requested_reason" rows="2" placeholder="{{ __('finance.common.description') }}" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>@error('requested_reason') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                <button
                    type="submit"
                    class="admin-icon-button admin-icon-button--accent admin-modal-action-button"
                    title="{{ __('crud.common.actions.save') }}"
                    aria-label="{{ __('crud.common.actions.save') }}"
                    data-income-request-save
                >
                    <x-admin-action-icon name="save" class="admin-modal-action__icon" />
                </button>
            </div>
        </form>
    </x-admin.modal>

    <section class="surface-table"><div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.revenue_requests.title') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($requests->total())]) }}</div></div>@can('finance.revenue-requests.create')<x-add-action-button wire:click="openCreateModal" :label="__('finance.revenue_requests.new')" />@endcan</div><div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.income_no') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.category') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.description') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.amount') }}</th><th class="admin-actions-column px-5 py-3 text-center">{{ __('finance.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($requests as $request)<tr><td class="px-5 py-3"><div class="font-semibold text-white">{{ $request->request_no }}</div><div class="text-xs text-neutral-500">{{ \App\Support\DateDisplay::html($request->postedTransaction?->transaction_date?->format('d-m-Y')) }} · {{ $request->reviewedBy?->name ?: '-' }}</div></td><td class="px-5 py-3">{{ $request->category?->name ?: '-' }}</td><td class="px-5 py-3"><div>{{ $request->requested_reason ?: '-' }}</div>@if ($request->category?->is_donation && $request->counterparty_name)<div class="text-xs text-neutral-500">{{ $request->maskedCounterpartyName() }}</div>@endif</td><td class="px-5 py-3"><bdi dir="ltr" class="font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($request->accepted_amount, $request->acceptedCurrency) }}</bdi></td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end">@if ($request->category?->is_donation)<a href="{{ route('finance.requests.print', ['financeRequest' => $request, 'pdf' => 1]) }}" target="_blank" rel="noopener" class="admin-icon-button" title="{{ __('finance.actions.print') }}" aria-label="{{ __('finance.actions.print') }}" data-income-direct-print data-keep-visible-table-action><x-admin-action-icon name="print" /></a>@endif</div></td></tr>@empty<tr><td colspan="5" class="px-5 py-10 text-center text-neutral-500">{{ __('finance.empty.no_revenue') }}</td></tr>@endforelse</tbody></table></div>@if ($requests->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $requests->links() }}</div>@endif</section>
</div>

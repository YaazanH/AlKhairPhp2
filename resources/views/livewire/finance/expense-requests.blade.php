<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Livewire\Concerns\HandlesFinanceRequestMaintenance;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\FinanceCurrency;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestAttachment;
use App\Services\FinanceService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;
    use HandlesFinanceRequestMaintenance;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public string $amount = '';
    public string $request_date = '';
    public ?int $currency_id = null;
    public ?int $cash_box_id = null;
    public ?int $finance_pull_request_kind_id = null;
    public string $requested_reason = '';
    public array $attachments = [];
    public array $review_amounts = [];
    public array $review_cash_boxes = [];
    public array $review_dates = [];
    public array $review_notes = [];
    public int $perPage = 15;
    public bool $showCreateModal = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.expense-requests.view');
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
    }

    public function with(): array
    {
        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;

        return [
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->currency_id)->get(),
            'cashBoxesByCurrency' => FinanceCurrency::query()
                ->where('is_active', true)
                ->pluck('id')
                ->mapWithKeys(fn ($currencyId) => [(int) $currencyId => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), (int) $currencyId)->get()])
                ->all(),
            'currencies' => app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->get(),
            'pullKinds' => FinancePullRequestKind::query()->where('is_active', true)->orderBy('mode')->orderBy('name')->get(),
            'requests' => FinanceRequest::query()
                ->with(['activity', 'cashBox', 'category', 'pullRequestKind', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency', 'attachments'])
                ->where(function ($query): void {
                    $query
                        ->where('type', FinanceRequest::TYPE_EXPENSE)
                        ->orWhere(function ($nested): void {
                            $nested
                                ->where('type', FinanceRequest::TYPE_PULL)
                                ->whereIn('status', [FinanceRequest::STATUS_ACCEPTED, FinanceRequest::STATUS_SETTLED]);
                        });
                })
                ->when(! $canReview, fn ($query) => $query->where(function ($builder) {
                    $builder
                        ->where('requested_by', auth()->id())
                        ->when(auth()->user()?->teacherProfile?->id, fn ($nested) => $nested->orWhere('teacher_id', auth()->user()->teacherProfile->id));
                }))
                ->latest()
                ->paginate($this->perPage),
        ];
    }

    public function submitRequest(): void
    {
        $this->authorizePermission('finance.expense-requests.create');

        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;
        $this->finance_pull_request_kind_id ??= app(FinanceService::class)->defaultPullRequestKindId();
        $this->normalizeFinanceNumberProperty('amount');
        if (auth()->user()?->can('finance.entries.update') && blank($this->request_date)) {
            $this->request_date = now()->toDateString();
        }

        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'attachments' => ['array'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,webp,pdf'],
            'cash_box_id' => [$canReview ? 'required' : 'nullable', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'exists:finance_currencies,id'],
            'finance_pull_request_kind_id' => ['required', 'exists:finance_pull_request_kinds,id'],
            'request_date' => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
            'requested_reason' => ['required', 'string', 'max:2000'],
        ]);

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $validated['finance_pull_request_kind_id'],
            'requested_currency_id' => $validated['currency_id'],
            'requested_amount' => $validated['amount'],
            'finance_category_id' => null,
            'requested_by' => auth()->id(),
            'requested_reason' => $validated['requested_reason'],
        ]);

        $this->storeAttachments($request);

        if ($canReview) {
            try {
                app(FinanceService::class)->acceptRequest(
                    $request,
                    (float) $validated['amount'],
                    app(FinanceService::class)->cashBoxForUser((int) $validated['cash_box_id'], auth()->user()),
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
        session()->flash('status', $canReview ? __('finance.messages.expense_posted') : __('finance.messages.expense_sent'));
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('finance.expense-requests.create');

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
        if ($this->cash_box_id && $this->currency_id && ! app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->whereKey($this->currency_id)->exists()) {
            $this->currency_id = app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->value('id');
        }
    }

    public function updatedCurrencyId(): void
    {
        if ($this->cash_box_id && $this->currency_id && ! app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->currency_id)->whereKey($this->cash_box_id)->exists()) {
            $this->cash_box_id = null;
        }
    }

    public function accept(int $requestId): void
    {
        $this->authorizePermission('finance.expense-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_EXPENSE)->findOrFail($requestId);
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

        session()->flash('status', __('finance.messages.expense_accepted'));
    }

    public function decline(int $requestId): void
    {
        $this->authorizePermission('finance.expense-requests.review');

        app(FinanceService::class)->declineRequest(FinanceRequest::query()->where('type', FinanceRequest::TYPE_EXPENSE)->findOrFail($requestId), auth()->user(), $this->review_notes[$requestId] ?? null);
        session()->flash('status', __('finance.messages.expense_declined'));
    }

    protected function storeAttachments(FinanceRequest $request): void
    {
        foreach ($this->attachments as $upload) {
            $path = $upload->store('finance/requests/'.$request->id, 'public');

            FinanceRequestAttachment::query()->create([
                'finance_request_id' => $request->id,
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    protected function resetCreateForm(): void
    {
        $this->amount = '';
        $this->request_date = now()->toDateString();
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
        $this->cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $this->currency_id)?->id;
        $this->finance_pull_request_kind_id = app(FinanceService::class)->defaultPullRequestKindId();
        $this->requested_reason = '';
        $this->attachments = [];

        $this->resetValidation();
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

    protected function financeRequestMaintenanceTypes(): array
    {
        return [FinanceRequest::TYPE_EXPENSE, FinanceRequest::TYPE_PULL];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.expense_requests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.expense_requests.subtitle') }}</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif
    @error('amount') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror
    @error('currency_id') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror

    <x-admin.modal
        :show="$showCreateModal"
        :title="__('finance.expense_requests.new')"
        :description="__('finance.expense_requests.subtitle')"
        close-method="closeCreateModal"
        max-width="5xl"
    >
        <form wire:submit="submitRequest" class="grid gap-4 lg:grid-cols-3">
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.pull_kind') }}</label><select wire:model="finance_pull_request_kind_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_pull_kind') }}</option>@foreach ($pullKinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }} - {{ __('finance.pull_modes.'.$kind->mode) }}</option>@endforeach</select>@error('finance_pull_request_kind_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.amount') }}</label><input wire:model="amount" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">@error('amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.currency') }}</label><select wire:model="currency_id" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
            @can('finance.entries.update')<div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.entry_date') }}</label><input wire:model="request_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error('request_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
            @can('finance.expense-requests.review')<div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label><select wire:model="cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
            <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.description') }}</label><textarea wire:model="requested_reason" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>@error('requested_reason') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.attachments') }}</label><input wire:model="attachments" type="file" multiple class="w-full rounded-xl px-4 py-3 text-sm">@error('attachments.*') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div class="lg:col-span-3 flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                <x-admin.create-and-new-button click="saveAndNew('submitRequest', 'openCreateModal')" />
                <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.save_expense') }}</button>
            </div>
        </form>
    </x-admin.modal>

    @include('livewire.finance.partials.requests-table', ['requests' => $requests, 'cashBoxes' => $cashBoxes, 'cashBoxesByCurrency' => $cashBoxesByCurrency, 'reviewPermission' => 'finance.expense-requests.review', 'createPermission' => 'finance.expense-requests.create', 'createMethod' => 'openCreateModal', 'createLabel' => __('finance.expense_requests.new'), 'recordLabel' => __('finance.fields.expense'), 'emptyLabel' => __('finance.empty.no_expenses')])
    @include('livewire.finance.partials.request-maintenance-modals')
</div>

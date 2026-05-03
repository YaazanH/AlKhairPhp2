<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Activity;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestAttachment;
use App\Models\Teacher;
use App\Services\FinanceService;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithFileUploads;
    use WithPagination;

    public string $amount = '';
    public ?int $currency_id = null;
    public ?int $cash_box_id = null;
    public ?int $activity_id = null;
    public ?int $teacher_id = null;
    public ?int $finance_category_id = null;
    public string $requested_reason = '';
    public array $attachments = [];
    public array $review_amounts = [];
    public array $review_cash_boxes = [];
    public array $review_notes = [];
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorizePermission('finance.expense-requests.view');
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
    }

    public function with(): array
    {
        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;

        return [
            'activities' => Activity::query()->orderByDesc('activity_date')->orderBy('title')->get(),
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $this->currency_id)->get(),
            'cashBoxesByCurrency' => FinanceCurrency::query()
                ->where('is_active', true)
                ->pluck('id')
                ->mapWithKeys(fn ($currencyId) => [(int) $currencyId => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), (int) $currencyId)->get()])
                ->all(),
            'categories' => FinanceCategory::query()->where('is_active', true)->whereIn('type', ['expense', 'management'])->orderBy('name')->get(),
            'currencies' => app(FinanceService::class)->currenciesForCashBox($this->cash_box_id)->get(),
            'requests' => FinanceRequest::query()
                ->with(['activity', 'cashBox', 'category', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency', 'attachments'])
                ->where('type', FinanceRequest::TYPE_EXPENSE)
                ->when(! $canReview, fn ($query) => $query->where('requested_by', auth()->id()))
                ->latest()
                ->paginate($this->perPage),
            'teachers' => Teacher::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(),
        ];
    }

    public function submitRequest(): void
    {
        $this->authorizePermission('finance.expense-requests.create');

        $canReview = auth()->user()?->can('finance.expense-requests.review') ?? false;
        $validated = $this->validate([
            'activity_id' => ['nullable', 'exists:activities,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'attachments' => ['array'],
            'attachments.*' => ['file', 'max:4096', 'mimes:jpg,jpeg,png,webp,pdf'],
            'cash_box_id' => [$canReview ? 'required' : 'nullable', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'exists:finance_currencies,id'],
            'finance_category_id' => ['nullable', 'exists:finance_categories,id'],
            'requested_reason' => ['required', 'string', 'max:2000'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
        ]);

        if (! $validated['activity_id'] && ! $validated['teacher_id'] && ! $validated['finance_category_id']) {
            $this->addError('finance_category_id', __('finance.validation.request_target_required'));

            return;
        }

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => $validated['currency_id'],
            'requested_amount' => $validated['amount'],
            'activity_id' => $validated['activity_id'] ?: null,
            'teacher_id' => $validated['teacher_id'] ?: null,
            'finance_category_id' => $validated['finance_category_id'] ?: null,
            'requested_by' => auth()->id(),
            'requested_reason' => $validated['requested_reason'],
        ]);

        $this->storeAttachments($request);

        if ($canReview) {
            app(FinanceService::class)->acceptRequest(
                $request,
                (float) $validated['amount'],
                app(FinanceService::class)->cashBoxForUser((int) $validated['cash_box_id'], auth()->user()),
                auth()->user(),
                'Auto-posted by finance management.',
            );
        }

        $this->reset(['amount', 'cash_box_id', 'activity_id', 'teacher_id', 'finance_category_id', 'requested_reason', 'attachments']);
        $this->currency_id = app(FinanceService::class)->localCurrency()->id;
        session()->flash('status', $canReview ? __('finance.messages.expense_posted') : __('finance.messages.expense_sent'));
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

        $this->validate([
            "review_amounts.{$requestId}" => ['nullable', 'numeric', 'gt:0'],
            "review_cash_boxes.{$requestId}" => ['required', 'exists:finance_cash_boxes,id'],
        ]);

        app(FinanceService::class)->acceptRequest(
            $request,
            (float) ($this->review_amounts[$requestId] ?? $request->requested_amount),
            app(FinanceService::class)->cashBoxForUser((int) $this->review_cash_boxes[$requestId], auth()->user()),
            auth()->user(),
            $this->review_notes[$requestId] ?? null,
        );

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

    @can('finance.expense-requests.create')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ __('finance.expense_requests.new') }}</div>
            <form wire:submit="submitRequest" class="mt-5 grid gap-4 lg:grid-cols-3">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.amount') }}</label><input wire:model="amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">@error('amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.currency') }}</label><select wire:model="currency_id" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</select></div>
                @can('finance.expense-requests.review')<div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label><select wire:model="cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>@endcan
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.activity') }}</label><select wire:model="activity_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.options.no_activity') }}</option>@foreach ($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->title }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.teacher') }}</label><select wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.options.no_teacher') }}</option>@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.category') }}</label><select wire:model="finance_category_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_category') }}</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('finance_category_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.description') }}</label><textarea wire:model="requested_reason" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>@error('requested_reason') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div class="lg:col-span-3"><label class="mb-1 block text-sm font-medium">{{ __('finance.common.attachments') }}</label><input wire:model="attachments" type="file" multiple class="w-full rounded-xl px-4 py-3 text-sm">@error('attachments.*') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div class="lg:col-span-3"><button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.save_expense') }}</button></div>
            </form>
        </section>
    @endcan

    @include('livewire.finance.partials.requests-table', ['requests' => $requests, 'cashBoxes' => $cashBoxes, 'cashBoxesByCurrency' => $cashBoxesByCurrency, 'reviewPermission' => 'finance.expense-requests.review'])
</div>

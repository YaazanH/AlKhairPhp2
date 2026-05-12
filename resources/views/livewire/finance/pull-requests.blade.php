<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Livewire\Concerns\HandlesFinanceRequestMaintenance;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\AppSetting;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\Invoice;
use App\Models\Teacher;
use App\Services\FinanceService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;
    use HandlesFinanceRequestMaintenance;
    use SupportsCreateAndNew;
    use WithPagination;

    public string $requested_amount = '';
    public string $requested_count = '';
    public string $request_date = '';
    public ?int $finance_pull_request_kind_id = null;
    public ?int $cash_box_id = null;
    public ?int $teacher_id = null;
    public string $requested_reason = '';
    public bool $accepted_terms = false;
    public array $review_amounts = [];
    public array $review_cash_boxes = [];
    public array $review_counts = [];
    public array $review_dates = [];
    public array $review_notes = [];
    public array $settlement_counts = [];
    public array $settlement_remaining_amounts = [];
    public int $perPage = 15;
    public bool $showCreateModal = false;
    public bool $showTermsModal = false;
    public ?int $reviewingRequestId = null;
    public ?int $settlingRequestId = null;

    public function mount(): void
    {
        $this->authorizePermission('finance.pull-requests.view');
    }

    public function with(): array
    {
        $localCurrency = app(FinanceService::class)->localCurrency();
        $canReview = auth()->user()?->can('finance.pull-requests.review') ?? false;
        $kinds = FinancePullRequestKind::query()->where('is_active', true)->orderBy('mode')->orderBy('name')->get();

        if (! $this->finance_pull_request_kind_id && $kinds->isNotEmpty()) {
            $this->finance_pull_request_kind_id = app(FinanceService::class)->defaultPullRequestKindId() ?: $kinds->first()->id;
        }

        return [
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $localCurrency->id)->get(),
            'localCurrency' => $localCurrency,
            'reviewRequest' => $this->reviewingRequestId
                ? FinanceRequest::query()->with(['cashBox', 'pullRequestKind', 'requestedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency'])->where('type', FinanceRequest::TYPE_PULL)->find($this->reviewingRequestId)
                : null,
            'pullKinds' => $kinds,
            'selectedPullKind' => $kinds->firstWhere('id', (int) $this->finance_pull_request_kind_id),
            'settlementRequest' => $this->settlingRequestId
                ? FinanceRequest::query()->with(['cashBox', 'pullRequestKind', 'requestedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency'])->where('type', FinanceRequest::TYPE_PULL)->find($this->settlingRequestId)
                : null,
            'requests' => FinanceRequest::query()
                ->with(['cashBox', 'invoice', 'pullRequestKind', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency'])
                ->where('type', FinanceRequest::TYPE_PULL)
                ->when(! $canReview, fn ($query) => $query->where(function ($builder) {
                    $builder
                        ->where('requested_by', auth()->id())
                        ->when(auth()->user()?->teacherProfile?->id, fn ($nested) => $nested->orWhere('teacher_id', auth()->user()->teacherProfile->id));
                }))
                ->latest()
                ->paginate($this->perPage),
            'teachers' => Teacher::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(),
            'terms' => (string) (AppSetting::groupValues('finance')->get('request_terms') ?: ''),
        ];
    }

    public function submitRequest(): void
    {
        $this->authorizePermission('finance.pull-requests.create');

        $terms = (string) (AppSetting::groupValues('finance')->get('request_terms') ?: '');
        $canReview = auth()->user()?->can('finance.pull-requests.review') ?? false;
        $this->finance_pull_request_kind_id ??= app(FinanceService::class)->defaultPullRequestKindId();
        $kind = FinancePullRequestKind::query()->where('is_active', true)->findOrFail((int) $this->finance_pull_request_kind_id);
        $this->normalizeFinanceNumberProperty('requested_amount');
        $this->normalizeFinanceNumberProperty('requested_count');
        if (auth()->user()?->can('finance.entries.update') && blank($this->request_date)) {
            $this->request_date = now()->toDateString();
        }

        $rules = [
            'cash_box_id' => [$canReview ? 'required' : 'nullable', 'exists:finance_cash_boxes,id'],
            'finance_pull_request_kind_id' => ['required', 'exists:finance_pull_request_kinds,id'],
            'request_date' => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
            'requested_amount' => ['required', 'numeric', 'gt:0'],
            'requested_count' => [$kind->mode === FinancePullRequestKind::MODE_COUNT ? 'required' : 'nullable', 'integer', 'min:1'],
            'requested_reason' => ['nullable', 'string'],
            'teacher_id' => [$canReview ? 'required' : 'nullable', 'exists:teachers,id'],
        ];

        if ($terms !== '') {
            $rules['accepted_terms'] = ['accepted'];
        }

        $validated = $this->validate($rules);
        $currency = app(FinanceService::class)->localCurrency();

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => $validated['requested_amount'],
            'requested_count' => $kind->mode === FinancePullRequestKind::MODE_COUNT ? (int) $validated['requested_count'] : null,
            'teacher_id' => $canReview ? (int) $validated['teacher_id'] : auth()->user()?->teacherProfile?->id,
            'requested_by' => auth()->id(),
            'requested_reason' => $validated['requested_reason'] ?: null,
            'terms_snapshot' => $terms ?: null,
            'terms_accepted_at' => $terms !== '' ? now() : null,
            'terms_accepted_by' => $terms !== '' ? auth()->id() : null,
        ]);

        if ($canReview) {
            try {
                app(FinanceService::class)->acceptRequest(
                    $request,
                    (float) $validated['requested_amount'],
                    app(FinanceService::class)->cashBoxForUser((int) $validated['cash_box_id'], auth()->user()),
                    auth()->user(),
                    'Auto-posted by finance management.',
                    $kind->mode === FinancePullRequestKind::MODE_COUNT ? (int) $validated['requested_count'] : null,
                    auth()->user()?->can('finance.entries.update') ? $validated['request_date'] : null,
                );
            } catch (ValidationException $exception) {
                $request->delete();
                $this->addError('requested_amount', $this->firstValidationMessage($exception));

                return;
            }
        }

        $this->resetCreateForm();
        $this->showCreateModal = false;
        session()->flash('status', $canReview ? __('finance.messages.pull_posted') : __('finance.messages.pull_sent'));
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('finance.pull-requests.create');

        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = false;
    }

    public function openReviewModal(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()
            ->with('pullRequestKind')
            ->where('type', FinanceRequest::TYPE_PULL)
            ->findOrFail($requestId);

        abort_unless($request->status === FinanceRequest::STATUS_PENDING, 404);

        $this->reviewingRequestId = $request->id;
        $this->review_amounts[$request->id] = $this->formatFinanceNumberForInput($this->review_amounts[$request->id] ?? $request->requested_amount);
        $this->review_cash_boxes[$request->id] = $this->review_cash_boxes[$request->id] ?? ($request->cash_box_id ?: '');
        $this->review_dates[$request->id] = $this->review_dates[$request->id] ?? now()->toDateString();
        $this->review_notes[$request->id] = $this->review_notes[$request->id] ?? '';

        if ($request->pullRequestKind?->mode === FinancePullRequestKind::MODE_COUNT) {
            $this->review_counts[$request->id] = $this->formatFinanceNumberForInput($this->review_counts[$request->id] ?? $request->requested_count, 0, true);
        }

        $this->resetValidation();
    }

    public function closeReviewModal(): void
    {
        $this->reviewingRequestId = null;
        $this->resetValidation();
    }

    public function openSettlementModal(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()
            ->with('pullRequestKind')
            ->where('type', FinanceRequest::TYPE_PULL)
            ->findOrFail($requestId);

        abort_unless($request->status === FinanceRequest::STATUS_ACCEPTED && $request->pullRequestKind?->mode === FinancePullRequestKind::MODE_COUNT, 404);

        $this->settlingRequestId = $request->id;
        $this->settlement_counts[$request->id] = $this->formatFinanceNumberForInput($this->settlement_counts[$request->id] ?? ($request->accepted_count ?: $request->requested_count), 0, true);
        $this->settlement_remaining_amounts[$request->id] = $this->formatFinanceNumberForInput($this->settlement_remaining_amounts[$request->id] ?? '0');
        $this->resetValidation();
    }

    public function closeSettlementModal(): void
    {
        $this->settlingRequestId = null;
        $this->resetValidation();
    }

    public function accept(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->findOrFail($requestId);
        $request->loadMissing('pullRequestKind');
        $this->normalizeFinanceNumberArrayValue('review_amounts', $requestId);
        $this->normalizeFinanceNumberArrayValue('review_counts', $requestId);
        if (auth()->user()?->can('finance.entries.update') && blank($this->review_dates[$requestId] ?? null)) {
            $this->review_dates[$requestId] = now()->toDateString();
        }

        $cashBoxId = (int) ($this->review_cash_boxes[$requestId] ?? 0);

        $rules = [
            "review_amounts.{$requestId}" => ['nullable', 'numeric', 'gt:0'],
            "review_cash_boxes.{$requestId}" => ['required', 'exists:finance_cash_boxes,id'],
            "review_dates.{$requestId}" => [auth()->user()?->can('finance.entries.update') ? 'required' : 'nullable', 'date'],
        ];

        if ($request->pullRequestKind?->mode === FinancePullRequestKind::MODE_COUNT) {
            $rules["review_counts.{$requestId}"] = ['nullable', 'integer', 'min:1'];
        }

        $this->validate($rules);

        $reviewAmount = $this->review_amounts[$requestId] ?? null;
        $reviewCount = $this->review_counts[$requestId] ?? null;
        $amount = $reviewAmount === null || $reviewAmount === ''
            ? (float) $request->requested_amount
            : (float) $reviewAmount;
        $count = $request->pullRequestKind?->mode === FinancePullRequestKind::MODE_COUNT
            ? (int) (($reviewCount === null || $reviewCount === '') ? $request->requested_count : $reviewCount)
            : null;

        try {
            app(FinanceService::class)->acceptRequest(
                $request,
                $amount,
                app(FinanceService::class)->cashBoxForUser($cashBoxId, auth()->user()),
                auth()->user(),
                $this->review_notes[$requestId] ?? null,
                $count,
                auth()->user()?->can('finance.entries.update') ? ($this->review_dates[$requestId] ?? null) : null,
            );
        } catch (ValidationException $exception) {
            $this->addError("review_amounts.{$requestId}", $this->firstValidationMessage($exception));

            return;
        }

        unset($this->review_amounts[$requestId], $this->review_counts[$requestId], $this->review_cash_boxes[$requestId], $this->review_dates[$requestId], $this->review_notes[$requestId]);
        $this->reviewingRequestId = null;
        session()->flash('status', __('finance.messages.pull_accepted'));
    }

    public function decline(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->findOrFail($requestId);
        app(FinanceService::class)->declineRequest($request, auth()->user(), $this->review_notes[$requestId] ?? null);
        unset($this->review_amounts[$requestId], $this->review_counts[$requestId], $this->review_cash_boxes[$requestId], $this->review_dates[$requestId], $this->review_notes[$requestId]);
        $this->reviewingRequestId = null;
        session()->flash('status', __('finance.messages.pull_declined'));
    }

    public function settleCount(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->findOrFail($requestId);
        $this->normalizeFinanceNumberArrayValue('settlement_counts', $requestId);
        $this->normalizeFinanceNumberArrayValue('settlement_remaining_amounts', $requestId);

        $this->validate([
            "settlement_counts.{$requestId}" => ['required', 'integer', 'min:0'],
            "settlement_remaining_amounts.{$requestId}" => ['required', 'numeric', 'min:0'],
        ]);

        app(FinanceService::class)->settleCountPullRequest(
            $request,
            (int) $this->settlement_counts[$requestId],
            (float) $this->settlement_remaining_amounts[$requestId],
            auth()->user(),
        );

        unset($this->settlement_counts[$requestId], $this->settlement_remaining_amounts[$requestId]);
        $this->settlingRequestId = null;
        session()->flash('status', __('finance.messages.pull_settled'));
    }

    public function insertInvoice(int $requestId): void
    {
        $this->authorizePermission('invoices.create');

        $request = FinanceRequest::query()
            ->with(['invoice', 'pullRequestKind', 'requestedBy', 'teacher'])
            ->where('type', FinanceRequest::TYPE_PULL)
            ->findOrFail($requestId);

        if ($request->invoice) {
            $this->redirectRoute('invoices.payments', ['invoice' => $request->invoice], navigate: true);

            return;
        }

        abort_unless($request->status === FinanceRequest::STATUS_ACCEPTED && $request->pullRequestKind?->mode === FinancePullRequestKind::MODE_INVOICE, 404);

        $invoice = Invoice::query()->create([
            'invoice_no' => app(FinanceService::class)->nextInvoiceNumber(),
            'invoicer_name' => $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : ($request->requestedBy?->name ?: $request->request_no),
            'invoice_type' => 'finance',
            'finance_invoice_kind_id' => app(FinanceService::class)->defaultInvoiceKindId(),
            'finance_request_id' => $request->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'discount' => 0,
            'notes' => __('finance.descriptions.invoice_from_pull', ['request' => $request->request_no]),
        ]);

        $request->update(['invoice_id' => $invoice->id]);

        $this->redirectRoute('invoices.payments', ['invoice' => $invoice], navigate: true);
    }

    protected function resetCreateForm(): void
    {
        $localCurrency = app(FinanceService::class)->localCurrency();

        $this->requested_amount = '';
        $this->requested_count = '';
        $this->request_date = now()->toDateString();
        $this->cash_box_id = app(FinanceService::class)->defaultCashBoxForUser(auth()->user(), $localCurrency->id)?->id;
        $this->finance_pull_request_kind_id = app(FinanceService::class)->defaultPullRequestKindId();
        $this->teacher_id = null;
        $this->requested_reason = '';
        $this->accepted_terms = false;
        $this->showTermsModal = false;

        $this->resetValidation();
    }

    public function openTermsModal(): void
    {
        $this->showTermsModal = true;
    }

    public function closeTermsModal(): void
    {
        $this->showTermsModal = false;
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
        return [FinanceRequest::TYPE_PULL];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.pull_requests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.pull_requests.subtitle') }}</p>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif
    @error('amount') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror
    @error('currency_id') <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ $message }}</div> @enderror

    <x-admin.modal
        :show="$showCreateModal"
        :title="__('finance.pull_requests.new')"
        :description="__('finance.pull_requests.subtitle')"
        close-method="closeCreateModal"
        max-width="5xl"
    >
        <form wire:submit="submitRequest" class="grid gap-4 lg:grid-cols-4">
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.pull_kind') }}</label><select wire:model.live="finance_pull_request_kind_id" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($pullKinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }} - {{ __('finance.pull_modes.'.$kind->mode) }}</option>@endforeach</select>@error('finance_pull_request_kind_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.amount') }} ({{ $localCurrency->code }})</label><input wire:model="requested_amount" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">@error('requested_amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            @can('finance.entries.update')
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.entry_date') }}</label><input wire:model="request_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error('request_date') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            @endcan
            @if ($selectedPullKind?->mode === 'count')
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.people_count') }}</label><input wire:model="requested_count" type="text" inputmode="numeric" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">@error('requested_count') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            @endif
            @can('finance.pull-requests.review')
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.teacher') }}</label><select wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_teacher') }}</option>@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select>@error('teacher_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label><select wire:model="cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            @endcan
            <div class="lg:col-span-4"><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.reason') }}</label><textarea wire:model="requested_reason" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea></div>
            @if ($terms !== '')
                <div class="lg:col-span-4 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm">
                    <label class="flex flex-wrap items-center gap-3">
                        <input wire:model="accepted_terms" type="checkbox" class="rounded">
                        <span>{{ __('finance.pull_requests.terms_agree_prefix') }}</span>
                        <button type="button" wire:click="openTermsModal" class="font-semibold text-emerald-200 underline decoration-emerald-300/40 underline-offset-4 hover:text-white">{{ __('finance.pull_requests.terms_button') }}</button>
                    </label>
                </div>
                @error('accepted_terms') <div class="lg:col-span-4 text-sm text-red-400">{{ $message }}</div> @enderror
            @endif
            <div class="lg:col-span-4 flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                <x-admin.create-and-new-button click="saveAndNew('submitRequest', 'openCreateModal')" />
                <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.submit_request') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showTermsModal"
        :title="__('finance.pull_requests.terms_title')"
        close-method="closeTermsModal"
        max-width="3xl"
    >
        <div class="whitespace-pre-line rounded-2xl border border-white/10 bg-white/[0.03] p-5 text-sm leading-7 text-neutral-200">{{ $terms }}</div>
        <div class="mt-4 flex justify-end">
            <button type="button" wire:click="closeTermsModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
        </div>
    </x-admin.modal>

    @if ($reviewRequest)
        <x-admin.modal
            :show="true"
            :title="__('finance.pull_requests.review_title', ['request' => $reviewRequest->request_no])"
            :description="__('finance.pull_requests.review_subtitle')"
            close-method="closeReviewModal"
            max-width="5xl"
        >
            @php($reviewIsCount = $reviewRequest->pullRequestKind?->mode === \App\Models\FinancePullRequestKind::MODE_COUNT)

            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-500">{{ __('finance.fields.requested') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($reviewRequest->requested_amount, $reviewRequest->requestedCurrency) }}</div>
                    @if ($reviewIsCount)
                        <div class="mt-1 text-sm text-neutral-400">{{ __('finance.fields.people_count') }}: {{ number_format((float) $reviewRequest->requested_count) }}</div>
                    @endif
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-500">{{ __('finance.fields.requester') }}</div>
                    <div class="mt-2 text-lg font-semibold text-white">{{ $reviewRequest->requestedBy?->name ?: '-' }}</div>
                    <div class="mt-1 text-sm text-neutral-400">{{ $reviewRequest->teacher ? trim($reviewRequest->teacher->first_name.' '.$reviewRequest->teacher->last_name) : '-' }}</div>
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-500">{{ __('finance.fields.pull_kind') }}</div>
                    <div class="mt-2 text-lg font-semibold text-white">{{ $reviewRequest->pullRequestKind?->name ?: '-' }}</div>
                    <div class="mt-1 text-sm text-neutral-400">{{ $reviewRequest->pullRequestKind ? __('finance.pull_modes.'.$reviewRequest->pullRequestKind->mode) : '-' }}</div>
                </div>
            </div>

            @if ($reviewRequest->requested_reason)
                <div class="mt-4 rounded-3xl border border-emerald-300/15 bg-emerald-500/[0.06] p-4 text-sm leading-6 text-neutral-200">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200/80">{{ __('finance.fields.reason') }}</div>
                    {{ $reviewRequest->requested_reason }}
                </div>
            @endif

            <form wire:submit="accept({{ $reviewRequest->id }})" class="mt-5 grid gap-4 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.accepted') }}</label>
                    <input wire:model="review_amounts.{{ $reviewRequest->id }}" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                    @error("review_amounts.{$reviewRequest->id}") <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                @if ($reviewIsCount)
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.people_count') }}</label>
                        <input wire:model="review_counts.{{ $reviewRequest->id }}" type="text" inputmode="numeric" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                        @error("review_counts.{$reviewRequest->id}") <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                @endif
                @can('finance.entries.update')
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.entry_date') }}</label>
                        <input wire:model="review_dates.{{ $reviewRequest->id }}" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error("review_dates.{$reviewRequest->id}") <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                @endcan
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label>
                    <select wire:model="review_cash_boxes.{{ $reviewRequest->id }}" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('finance.actions.choose_box') }}</option>
                        @foreach ($cashBoxes as $box)
                            <option value="{{ $box->id }}">{{ $box->name }}</option>
                        @endforeach
                    </select>
                    @error("review_cash_boxes.{$reviewRequest->id}") <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div class="{{ $reviewIsCount ? '' : 'lg:col-span-2' }}">
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.common.notes') }}</label>
                    <input wire:model="review_notes.{{ $reviewRequest->id }}" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>
                <div class="lg:col-span-4 flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="closeReviewModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                    <button type="button" wire:click="decline({{ $reviewRequest->id }})" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('finance.actions.decline') }}</button>
                    <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.accept') }}</button>
                </div>
            </form>
        </x-admin.modal>
    @endif

    @if ($settlementRequest)
        <x-admin.modal
            :show="true"
            :title="__('finance.pull_requests.settlement_title', ['request' => $settlementRequest->request_no])"
            :description="__('finance.pull_requests.settlement_subtitle')"
            close-method="closeSettlementModal"
            max-width="4xl"
        >
            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-500">{{ __('finance.fields.accepted') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ app(FinanceService::class)->formatCurrencyAmount($settlementRequest->accepted_amount, $settlementRequest->acceptedCurrency) }}</div>
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-500">{{ __('finance.fields.people_count') }}</div>
                    <div class="mt-2 text-2xl font-semibold text-white">{{ number_format((float) ($settlementRequest->accepted_count ?: $settlementRequest->requested_count)) }}</div>
                    <div class="mt-1 text-sm text-neutral-400">{{ __('finance.pull_requests.approved_count_hint') }}</div>
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-500">{{ __('finance.fields.cash_box') }}</div>
                    <div class="mt-2 text-lg font-semibold text-white">{{ $settlementRequest->cashBox?->name ?: '-' }}</div>
                    <div class="mt-1 text-sm text-neutral-400">{{ __('finance.pull_requests.return_hint') }}</div>
                </div>
            </div>

            <form wire:submit="settleCount({{ $settlementRequest->id }})" class="mt-5 grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.final_count') }}</label>
                    <input wire:model="settlement_counts.{{ $settlementRequest->id }}" type="text" inputmode="numeric" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                    @error("settlement_counts.{$settlementRequest->id}") <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.remaining_amount') }}</label>
                    <input wire:model="settlement_remaining_amounts.{{ $settlementRequest->id }}" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                    @error("settlement_remaining_amounts.{$settlementRequest->id}") <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div class="md:col-span-2 flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="closeSettlementModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
                    <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.finish_cycle') }}</button>
                </div>
            </form>
        </x-admin.modal>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.pull_requests.ledger') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($requests->total())]) }}</div>
            </div>
            @can('finance.pull-requests.create')
                <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('finance.pull_requests.new') }}</button>
            @endcan
        </div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.request') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.requester') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.pull_kind') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.amounts') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.status') }}</th><th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($requests as $request)
                        <tr>
                            <td class="px-5 py-3"><div class="font-medium text-white">{{ $request->request_no }}</div><div class="text-xs text-neutral-500">{{ $request->created_at?->format('Y-m-d H:i') }}</div></td>
                            <td class="px-5 py-3"><div>{{ $request->requestedBy?->name ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : '-' }}</div></td>
                            <td class="px-5 py-3"><div>{{ $request->pullRequestKind?->name ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $request->pullRequestKind ? __('finance.pull_modes.'.$request->pullRequestKind->mode) : '-' }}</div></td>
                            <td class="px-5 py-3">
                                @if ($request->accepted_amount !== null)
                                    <div class="text-base font-semibold text-white">{{ __('finance.fields.accepted') }}: {{ app(FinanceService::class)->formatCurrencyAmount($request->accepted_amount, $request->acceptedCurrency) }}</div>
                                    <div class="mt-1 text-xs text-neutral-500">{{ __('finance.fields.requested') }}: {{ app(FinanceService::class)->formatCurrencyAmount($request->requested_amount, $request->requestedCurrency) }}</div>
                                @else
                                    <div class="text-base font-semibold text-white">{{ __('finance.fields.requested') }}: {{ app(FinanceService::class)->formatCurrencyAmount($request->requested_amount, $request->requestedCurrency) }}</div>
                                    <div class="mt-1 text-xs text-neutral-500">{{ __('finance.fields.accepted') }}: -</div>
                                @endif
                                @if($request->requested_count)<div class="text-xs text-neutral-500">{{ __('finance.fields.people_count') }}: {{ number_format((float) ($request->accepted_count ?: $request->requested_count)) }}</div>@endif
                            </td>
                            <td class="px-5 py-3"><span class="status-chip {{ in_array($request->status, ['accepted', 'settled'], true) ? 'status-chip--emerald' : ($request->status === 'declined' ? 'status-chip--rose' : 'status-chip--slate') }}">{{ __('finance.statuses.'.$request->status) }}</span></td>
                            <td class="px-5 py-3">
                                <div class="flex min-w-[13rem] flex-col items-end gap-2">
                                    @if ($request->status === 'pending')
                                        @can('finance.pull-requests.review')
                                            <div class="text-right text-xs leading-5 text-neutral-400">{{ __('finance.pull_requests.pending_review_hint') }}</div>
                                            <button type="button" wire:click="openReviewModal({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.review_request') }}</button>
                                        @else
                                            <span class="text-xs text-neutral-500">-</span>
                                        @endcan
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('finance.entries.update')
                                                <button type="button" wire:click="openFinanceRequestEditModal({{ $request->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit_entry') }}</button>
                                            @endcan
                                            @can('finance.entries.delete')
                                                <button type="button" wire:click="openFinanceRequestDeleteModal({{ $request->id }})" class="pill-link pill-link--compact pill-link--danger">{{ __('finance.actions.delete') }}</button>
                                            @endcan
                                        </div>
                                    @else
                                        @if (in_array($request->status, ['accepted', 'settled'], true))
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <a href="{{ route('finance.requests.print', $request) }}" target="_blank" class="pill-link pill-link--compact">{{ __('finance.actions.print') }}</a>
                                                <a href="{{ route('finance.requests.print', ['financeRequest' => $request, 'choose' => 1]) }}" target="_blank" class="pill-link pill-link--compact">{{ __('finance.actions.choose_print_template') }}</a>
                                            </div>
                                        @endif

                                        <div class="flex flex-wrap justify-end gap-2">
                                            @can('finance.entries.update')
                                                <button type="button" wire:click="openFinanceRequestEditModal({{ $request->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit_entry') }}</button>
                                            @endcan
                                            @can('finance.entries.delete')
                                                <button type="button" wire:click="openFinanceRequestDeleteModal({{ $request->id }})" class="pill-link pill-link--compact pill-link--danger">{{ __('finance.actions.delete') }}</button>
                                            @endcan
                                        </div>

                                        @can('finance.pull-requests.review')
                                            @if ($request->status === 'accepted' && $request->pullRequestKind?->mode === 'count')
                                                <div class="text-right text-xs leading-5 text-amber-100/80">{{ __('finance.pull_requests.needs_settlement_hint') }}</div>
                                                <button type="button" wire:click="openSettlementModal({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.finish_cycle') }}</button>
                                            @elseif ($request->status === 'accepted' && $request->pullRequestKind?->mode === 'invoice')
                                                <button type="button" wire:click="insertInvoice({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ $request->invoice ? __('finance.actions.open_invoice') : __('finance.actions.insert_invoice') }}</button>
                                            @elseif ($request->status === 'settled')
                                                <div class="text-right text-xs leading-5 text-emerald-100/75">{{ __('finance.pull_requests.settled_hint') }}</div>
                                            @endif
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('finance.empty.no_pull_requests') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($requests->hasPages()) <div class="border-t border-white/8 px-5 py-4">{{ $requests->links() }}</div> @endif
    </section>
    @include('livewire.finance.partials.request-maintenance-modals')
</div>

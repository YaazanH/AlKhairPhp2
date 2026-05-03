<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Activity;
use App\Models\AppSetting;
use App\Models\FinanceRequest;
use App\Models\Teacher;
use App\Services\FinanceService;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithPagination;

    public string $requested_amount = '';
    public ?int $activity_id = null;
    public ?int $cash_box_id = null;
    public ?int $teacher_id = null;
    public string $requested_reason = '';
    public bool $accepted_terms = false;
    public array $review_amounts = [];
    public array $review_cash_boxes = [];
    public array $review_notes = [];
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorizePermission('finance.pull-requests.view');
    }

    public function with(): array
    {
        $localCurrency = app(FinanceService::class)->localCurrency();
        $canReview = auth()->user()?->can('finance.pull-requests.review') ?? false;

        return [
            'activities' => Activity::query()->whereIn('status', ['planned', 'active'])->orderByDesc('activity_date')->orderBy('title')->get(),
            'cashBoxes' => app(FinanceService::class)->accessibleCashBoxesForCurrency(auth()->user(), $localCurrency->id)->get(),
            'localCurrency' => $localCurrency,
            'requests' => FinanceRequest::query()
                ->with(['activity', 'cashBox', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency'])
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

        $rules = [
            'activity_id' => ['nullable', 'exists:activities,id'],
            'cash_box_id' => [$canReview ? 'required' : 'nullable', 'exists:finance_cash_boxes,id'],
            'requested_amount' => ['required', 'numeric', 'gt:0'],
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
            'requested_currency_id' => $currency->id,
            'requested_amount' => $validated['requested_amount'],
            'activity_id' => $validated['activity_id'] ?: null,
            'teacher_id' => $canReview ? (int) $validated['teacher_id'] : auth()->user()?->teacherProfile?->id,
            'requested_by' => auth()->id(),
            'requested_reason' => $validated['requested_reason'] ?: null,
            'terms_snapshot' => $terms ?: null,
            'terms_accepted_at' => $terms !== '' ? now() : null,
            'terms_accepted_by' => $terms !== '' ? auth()->id() : null,
        ]);

        if ($canReview) {
            app(FinanceService::class)->acceptRequest(
                $request,
                (float) $validated['requested_amount'],
                app(FinanceService::class)->cashBoxForUser((int) $validated['cash_box_id'], auth()->user()),
                auth()->user(),
                'Auto-posted by finance management.',
            );
        }

        $this->reset(['requested_amount', 'activity_id', 'cash_box_id', 'teacher_id', 'requested_reason', 'accepted_terms']);
        session()->flash('status', $canReview ? __('finance.messages.pull_posted') : __('finance.messages.pull_sent'));
    }

    public function accept(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->findOrFail($requestId);
        $amount = (float) ($this->review_amounts[$requestId] ?? $request->requested_amount);
        $cashBoxId = (int) ($this->review_cash_boxes[$requestId] ?? 0);

        $this->validate([
            "review_amounts.{$requestId}" => ['nullable', 'numeric', 'gt:0'],
            "review_cash_boxes.{$requestId}" => ['required', 'exists:finance_cash_boxes,id'],
        ]);

        app(FinanceService::class)->acceptRequest(
            $request,
            $amount,
            app(FinanceService::class)->cashBoxForUser($cashBoxId, auth()->user()),
            auth()->user(),
            $this->review_notes[$requestId] ?? null,
        );
        session()->flash('status', __('finance.messages.pull_accepted'));
    }

    public function decline(int $requestId): void
    {
        $this->authorizePermission('finance.pull-requests.review');

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->findOrFail($requestId);
        app(FinanceService::class)->declineRequest($request, auth()->user(), $this->review_notes[$requestId] ?? null);
        session()->flash('status', __('finance.messages.pull_declined'));
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

    @can('finance.pull-requests.create')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ __('finance.pull_requests.new') }}</div>
            <form wire:submit="submitRequest" class="mt-5 grid gap-4 lg:grid-cols-4">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.amount') }} ({{ $localCurrency->code }})</label><input wire:model="requested_amount" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">@error('requested_amount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.activity') }}</label><select wire:model="activity_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.options.no_activity') }}</option>@foreach ($activities as $activity)<option value="{{ $activity->id }}">{{ $activity->title }} | {{ $activity->activity_date?->format('Y-m-d') }}</option>@endforeach</select></div>
                @can('finance.pull-requests.review')
                    <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.teacher') }}</label><select wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_teacher') }}</option>@foreach ($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>@endforeach</select>@error('teacher_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.cash_box') }}</label><select wire:model="cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm"><option value="">{{ __('finance.actions.choose_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>@error('cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                @endcan
                <div class="lg:col-span-4"><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.reason') }}</label><textarea wire:model="requested_reason" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea></div>
                @if ($terms !== '')
                    <label class="lg:col-span-4 flex items-start gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm"><input wire:model="accepted_terms" type="checkbox" class="mt-1 rounded"><span>{{ $terms }}</span></label>
                    @error('accepted_terms') <div class="lg:col-span-4 text-sm text-red-400">{{ $message }}</div> @enderror
                @endif
                <div class="lg:col-span-4"><button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.submit_request') }}</button></div>
            </form>
        </section>
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('finance.pull_requests.ledger') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($requests->total())]) }}</div></div></div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.request') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.requester') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.activity') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.amounts') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.status') }}</th><th class="px-5 py-3 text-right">{{ __('finance.actions.accept') }}</th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($requests as $request)
                        <tr>
                            <td class="px-5 py-3"><div class="font-medium text-white">{{ $request->request_no }}</div><div class="text-xs text-neutral-500">{{ $request->created_at?->format('Y-m-d H:i') }}</div></td>
                            <td class="px-5 py-3"><div>{{ $request->requestedBy?->name ?: '-' }}</div><div class="text-xs text-neutral-500">{{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : '-' }}</div></td>
                            <td class="px-5 py-3">{{ $request->activity?->title ?: '-' }}</td>
                            <td class="px-5 py-3"><div>{{ __('finance.fields.requested') }}: {{ number_format((float) $request->requested_amount, 2) }} {{ $request->requestedCurrency?->code }}</div><div class="text-xs text-neutral-500">{{ __('finance.fields.accepted') }}: {{ $request->accepted_amount !== null ? number_format((float) $request->accepted_amount, 2).' '.$request->acceptedCurrency?->code : '-' }}</div></td>
                            <td class="px-5 py-3"><span class="status-chip {{ $request->status === 'accepted' ? 'status-chip--emerald' : ($request->status === 'declined' ? 'status-chip--rose' : 'status-chip--slate') }}">{{ ucfirst($request->status) }}</span></td>
                            <td class="px-5 py-3">
                                <div class="admin-action-cluster admin-action-cluster--end">
                                    @if ($request->status === 'accepted')
                                        <a href="{{ route('finance.requests.print', $request) }}" target="_blank" class="pill-link pill-link--compact">{{ __('finance.actions.print') }}</a>
                                    @endif
                                    @can('finance.pull-requests.review')
                                        @if ($request->status === 'pending')
                                            <input wire:model="review_amounts.{{ $request->id }}" type="number" min="0" step="0.01" placeholder="{{ number_format((float) $request->requested_amount, 2, '.', '') }}" class="w-28 rounded-xl px-3 py-2 text-sm">
                                            <select wire:model="review_cash_boxes.{{ $request->id }}" class="w-36 rounded-xl px-3 py-2 text-sm"><option value="">{{ __('finance.fields.cash_box') }}</option>@foreach ($cashBoxes as $box)<option value="{{ $box->id }}">{{ $box->name }}</option>@endforeach</select>
                                            <input wire:model="review_notes.{{ $request->id }}" type="text" placeholder="{{ __('finance.common.notes') }}" class="w-40 rounded-xl px-3 py-2 text-sm">
                                            <button type="button" wire:click="accept({{ $request->id }})" class="pill-link pill-link--compact pill-link--accent">{{ __('finance.actions.accept') }}</button>
                                            <button type="button" wire:click="decline({{ $request->id }})" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.decline') }}</button>
                                        @endif
                                    @endcan
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
</div>

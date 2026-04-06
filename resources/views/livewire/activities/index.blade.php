<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Activity;
use App\Models\Group;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $editingId = null;
    public string $title = '';
    public string $description = '';
    public string $activity_date = '';
    public ?int $group_id = null;
    public string $fee_amount = '';
    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorizePermission('activities.view');

        $this->activity_date = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'activities' => Activity::query()
                ->with(['group.course'])
                ->withCount(['registrations', 'expenses'])
                ->orderByDesc('activity_date')
                ->orderBy('title')
                ->get(),
            'groups' => Group::query()
                ->with(['course', 'academicYear'])
                ->orderBy('name')
                ->get(),
            'totals' => [
                'all' => Activity::count(),
                'active' => Activity::where('is_active', true)->count(),
                'expected' => Activity::sum('expected_revenue_cached'),
                'collected' => Activity::sum('collected_revenue_cached'),
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'activity_date' => ['required', 'date'],
            'group_id' => ['nullable', 'exists:groups,id'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'activities.update' : 'activities.create');

        $validated = $this->validate();

        Activity::query()->updateOrCreate(
            ['id' => $this->editingId],
            [
                'title' => $validated['title'],
                'description' => $validated['description'] ?: null,
                'activity_date' => $validated['activity_date'],
                'group_id' => $validated['group_id'] ?: null,
                'fee_amount' => $validated['fee_amount'] !== '' ? $validated['fee_amount'] : null,
                'is_active' => $validated['is_active'],
            ],
        );

        session()->flash(
            'status',
            $this->editingId ? __('activities.index.messages.updated') : __('activities.index.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $activityId): void
    {
        $this->authorizePermission('activities.update');

        $activity = Activity::query()->findOrFail($activityId);

        $this->editingId = $activity->id;
        $this->title = $activity->title;
        $this->description = $activity->description ?? '';
        $this->activity_date = $activity->activity_date?->format('Y-m-d') ?? '';
        $this->group_id = $activity->group_id;
        $this->fee_amount = $activity->fee_amount !== null ? number_format((float) $activity->fee_amount, 2, '.', '') : '';
        $this->is_active = $activity->is_active;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->title = '';
        $this->description = '';
        $this->activity_date = now()->toDateString();
        $this->group_id = null;
        $this->fee_amount = '';
        $this->is_active = true;

        $this->resetValidation();
    }

    public function delete(int $activityId): void
    {
        $this->authorizePermission('activities.delete');

        $activity = Activity::query()
            ->withCount(['registrations', 'expenses'])
            ->findOrFail($activityId);

        if ($activity->registrations_count > 0 || $activity->expenses_count > 0) {
            $this->addError('delete', __('activities.index.errors.delete_linked'));

            return;
        }

        $activity->delete();

        if ($this->editingId === $activityId) {
            $this->cancel();
        }

        session()->flash('status', __('activities.index.messages.deleted'));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('activities.index.heading') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('activities.index.subheading') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.index.stats.all') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['all']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.index.stats.active') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['active']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.index.stats.expected') }}</div>
            <div class="metric-value mt-3">{{ number_format((float) $totals['expected'], 2) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('activities.index.stats.collected') }}</div>
            <div class="metric-value mt-3">{{ number_format((float) $totals['collected'], 2) }}</div>
        </article>
    </section>

    <div class="grid gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">
        <section class="surface-panel p-5 lg:p-6">
            @if (auth()->user()->can('activities.create') || auth()->user()->can('activities.update'))
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-white">{{ $editingId ? __('activities.index.form.edit_title') : __('activities.index.form.create_title') }}</h2>
                    <p class="text-sm text-neutral-400">{{ __('activities.index.form.help') }}</p>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label for="activity-title" class="mb-1 block text-sm font-medium">{{ __('activities.index.form.fields.title') }}</label>
                        <input id="activity-title" wire:model="title" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('title')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="activity-date" class="mb-1 block text-sm font-medium">{{ __('activities.index.form.fields.activity_date') }}</label>
                            <input id="activity-date" wire:model="activity_date" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('activity_date')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="activity-fee" class="mb-1 block text-sm font-medium">{{ __('activities.index.form.fields.fee_amount') }}</label>
                            <input id="activity-fee" wire:model="fee_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('fee_amount')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="activity-group" class="mb-1 block text-sm font-medium">{{ __('activities.index.form.fields.group') }}</label>
                        <select id="activity-group" wire:model="group_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('activities.index.form.placeholders.group') }}</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                            @endforeach
                        </select>
                        @error('group_id')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="activity-description" class="mb-1 block text-sm font-medium">{{ __('activities.index.form.fields.description') }}</label>
                        <textarea id="activity-description" wire:model="description" rows="4" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        @error('description')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('activities.index.form.active_flag') }}</span>
                    </label>

                    @error('delete')
                        <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="flex items-center gap-3">
                        <button type="submit" class="pill-link pill-link--accent">
                            {{ $editingId ? __('activities.index.form.update_submit') : __('activities.index.form.create_submit') }}
                        </button>

                        @if ($editingId)
                            <button type="button" wire:click="cancel" class="pill-link">
                                {{ __('activities.common.actions.cancel') }}
                            </button>
                        @endif
                    </div>
                </form>
            @else
                <div class="admin-empty-state">
                    <h2 class="text-lg font-semibold text-white">{{ __('activities.index.read_only.title') }}</h2>
                    <p class="mt-2 text-sm text-neutral-400">{{ __('activities.index.read_only.body') }}</p>
                </div>
            @endif
        </section>

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('activities.index.table.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($activities->count())]) }}</div>
                </div>
            </div>

            @if ($activities->isEmpty())
                <div class="admin-empty-state">{{ __('activities.index.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-left font-medium">{{ __('activities.index.table.headers.activity') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('activities.index.table.headers.date') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('activities.index.table.headers.registrations') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('activities.index.table.headers.financials') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('activities.index.table.headers.status') }}</th>
                                @if (auth()->user()->can('activities.finance.view') || auth()->user()->can('activities.update') || auth()->user()->can('activities.delete'))
                                    <th class="px-5 py-3 text-right font-medium">{{ __('activities.index.table.headers.actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($activities as $activity)
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="font-medium">{{ $activity->title }}</div>
                                        <div class="text-xs text-neutral-500">
                                            {{ $activity->group?->name ?: __('activities.common.general_activity') }}
                                            @if ($activity->group?->course)
                                                | {{ $activity->group->course->name }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">{{ $activity->activity_date?->format('Y-m-d') }}</td>
                                    <td class="px-5 py-3">{{ number_format($activity->registrations_count) }}</td>
                                    <td class="px-5 py-3">
                                        <div>{{ __('activities.index.table.financials.expected', ['amount' => number_format((float) $activity->expected_revenue_cached, 2)]) }}</div>
                                        <div class="text-xs text-neutral-500">{{ __('activities.index.table.financials.breakdown', ['collected' => number_format((float) $activity->collected_revenue_cached, 2), 'expenses' => number_format((float) $activity->expense_total_cached, 2)]) }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="status-chip {{ $activity->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">
                                            {{ __('activities.common.states.'.($activity->is_active ? 'active' : 'inactive')) }}
                                        </span>
                                    </td>
                                    @if (auth()->user()->can('activities.finance.view') || auth()->user()->can('activities.update') || auth()->user()->can('activities.delete'))
                                        <td class="px-5 py-3">
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                @can('activities.finance.view')
                                                    <a href="{{ route('activities.finance', $activity) }}" wire:navigate class="pill-link pill-link--compact">
                                                        {{ __('activities.common.actions.finance') }}
                                                    </a>
                                                @endcan
                                                @can('activities.update')
                                                    <button type="button" wire:click="edit({{ $activity->id }})" class="pill-link pill-link--compact">
                                                        {{ __('activities.common.actions.edit') }}
                                                    </button>
                                                @endcan
                                                @can('activities.delete')
                                                    <button type="button" wire:click="delete({{ $activity->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                        {{ __('activities.common.actions.delete') }}
                                                    </button>
                                                @endcan
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>

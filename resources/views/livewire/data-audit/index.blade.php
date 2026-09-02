<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity as AuditActivity;

new class extends Component {
    use AuthorizesPermissions;
    use WithPagination;

    public string $search = '';
    public string $eventFilter = 'all';
    public string $moduleFilter = 'all';
    public string $fromDate = '';
    public string $toDate = '';
    public ?int $selectedActivityId = null;
    public int $perPage = 20;

    public function mount(): void
    {
        $this->authorizePermission('data-audit.view');
    }

    public function with(): array
    {
        $query = AuditActivity::query()
            ->inLog('data-audit')
            ->with('causer')
            ->when(filled($this->search), function (Builder $query): void {
                $query->where(function (Builder $builder): void {
                    $builder->where('description', 'like', '%'.$this->search.'%')
                        ->orWhere('properties', 'like', '%'.$this->search.'%')
                        ->orWhereHas('causer', fn (Builder $causer) => $causer
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('email', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->eventFilter !== 'all', fn (Builder $query) => $query->where('event', $this->eventFilter))
            ->when($this->moduleFilter !== 'all', fn (Builder $query) => $query->where('subject_type', $this->moduleFilter))
            ->when(filled($this->fromDate), fn (Builder $query) => $query->whereDate('created_at', '>=', $this->fromDate))
            ->when(filled($this->toDate), fn (Builder $query) => $query->whereDate('created_at', '<=', $this->toDate))
            ->latest('id');

        return [
            'activities' => $query->paginate($this->perPage),
            'selectedActivity' => $this->selectedActivityId
                ? AuditActivity::query()->inLog('data-audit')->with('causer')->find($this->selectedActivityId)
                : null,
            'modules' => AuditActivity::query()->inLog('data-audit')->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ];
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedEventFilter(): void { $this->resetPage(); }
    public function updatedModuleFilter(): void { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->eventFilter = 'all';
        $this->moduleFilter = 'all';
        $this->fromDate = '';
        $this->toDate = '';
        $this->resetPage();
    }

    public function viewActivity(int $activityId): void
    {
        $this->selectedActivityId = AuditActivity::query()->inLog('data-audit')->findOrFail($activityId)->id;
    }

    public function closeDetails(): void
    {
        $this->selectedActivityId = null;
    }

    public function moduleLabel(?string $subjectType): string
    {
        $name = class_basename((string) $subjectType);
        $key = 'data_governance.audit.modules.'.$name;

        return __($key) === $key ? Str::headline($name) : __($key);
    }

    public function eventLabel(?string $event): string
    {
        $key = 'data_governance.audit.events.'.($event ?: 'updated');

        return __($key);
    }

    public function changedFields(AuditActivity $activity): array
    {
        $before = $activity->getProperty('before', []);
        $after = $activity->getProperty('after', []);
        $fields = array_values(array_unique([...array_keys(is_array($before) ? $before : []), ...array_keys(is_array($after) ? $after : [])]));

        return $activity->event === 'deleted'
            ? array_values(array_filter($fields, fn (string $field): bool => $field !== 'deleted_at'))
            : $fields;
    }

    public function auditFieldLabel(string $field): string
    {
        $key = 'data_governance.quality.record_fields.'.$field;
        $label = __($key);

        return $label === $key ? Str::headline($field) : $label;
    }

    public function deletionTimestamp(AuditActivity $activity): string
    {
        $timestamp = Arr::get($activity->getProperty('before', []), 'deleted_at');

        return filled($timestamp) ? (string) $timestamp : ($activity->created_at?->format('Y-m-d H:i:s') ?? '—');
    }

    public function auditValue(mixed $value): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? '✓' : '—';
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';

        return (string) $value;
    }
}; ?>

<div class="page-stack" data-data-audit-page>
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('data_governance.audit.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('data_governance.audit.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('data_governance.audit.subtitle') }}</p>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('data_governance.audit.table_title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field"><label class="sr-only" for="data-audit-search">{{ __('crud.common.filters.search') }}</label><input id="data-audit-search" wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('data_governance.audit.search_placeholder') }}"></div>
                <div class="admin-filter-field"><label class="sr-only" for="data-audit-event">{{ __('data_governance.audit.all_events') }}</label><select id="data-audit-event" wire:model.live="eventFilter"><option value="all">{{ __('data_governance.audit.all_events') }}</option>@foreach (['created','updated','deleted','restored'] as $event)<option value="{{ $event }}">{{ __('data_governance.audit.events.'.$event) }}</option>@endforeach</select></div>
                <div class="admin-filter-field"><label class="sr-only" for="data-audit-module">{{ __('data_governance.audit.all_modules') }}</label><select id="data-audit-module" wire:model.live="moduleFilter"><option value="all">{{ __('data_governance.audit.all_modules') }}</option>@foreach ($modules as $module)<option value="{{ $module }}">{{ $this->moduleLabel($module) }}</option>@endforeach</select></div>
                <div class="admin-filter-field"><label class="sr-only" for="audit-from-date">{{ __('data_governance.audit.from_date') }}</label><input id="audit-from-date" wire:model.live="fromDate" type="date" title="{{ __('data_governance.audit.from_date') }}"></div>
                <div class="admin-filter-field"><label class="sr-only" for="audit-to-date">{{ __('data_governance.audit.to_date') }}</label><input id="audit-to-date" wire:model.live="toDate" type="date" title="{{ __('data_governance.audit.to_date') }}"></div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr><th class="w-16 px-5 py-4 text-center">#</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.time') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.actor') }}</th><th class="px-5 py-4 text-center">{{ __('data_governance.audit.event') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.record') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.audit.module') }}</th><th class="admin-actions-column px-5 py-4 text-center">{{ __('data_governance.audit.details') }}</th></tr></thead>
                <tbody class="divide-y divide-white/10">
                @forelse ($activities as $activity)
                    <tr>
                        <td class="px-5 py-4 text-center tabular-nums" data-data-audit-sequence>{{ $activities->total() - (($activities->firstItem() - 1) + $loop->index) }}</td>
                        <td class="px-5 py-4 whitespace-nowrap text-neutral-300"><span dir="ltr">{{ $activity->created_at?->format('Y-m-d H:i') }}</span></td>
                        <td class="px-5 py-4"><div class="font-semibold text-white">{{ $activity->causer?->name ?? __('data_governance.audit.system') }}</div></td>
                        <td class="px-5 py-4 text-center"><span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-neutral-100">{{ $this->eventLabel($activity->event) }}</span></td>
                        <td class="px-5 py-4"><div class="font-medium text-neutral-100">{{ $activity->getProperty('subject_label', $this->moduleLabel($activity->subject_type)) }}</div></td>
                        <td class="px-5 py-4 text-neutral-300">{{ $this->moduleLabel($activity->subject_type) }}</td>
                        <td class="px-5 py-4 text-center"><button type="button" wire:click="viewActivity({{ $activity->id }})" class="admin-icon-button" title="{{ __('data_governance.audit.view') }}" aria-label="{{ __('data_governance.audit.view') }}" data-data-audit-view-action><x-admin-action-icon name="past" /></button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-neutral-400">{{ __('data_governance.audit.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($activities->hasPages()) <div class="px-5 py-4">{{ $activities->links() }}</div> @endif
    </section>

    <x-admin.modal :show="(bool) $selectedActivity" :title="__('data_governance.audit.details_title')" :description="$selectedActivity?->getProperty('subject_label', '') ?? ''" close-method="closeDetails" max-width="4xl">
        @if ($selectedActivity)
            <div class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.actor') }}</div><div class="mt-1 text-sm text-white">{{ $selectedActivity->causer?->name ?? __('data_governance.audit.system') }}</div></div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.event') }}</div><div class="mt-1 text-sm text-white">{{ $this->eventLabel($selectedActivity->event) }}</div></div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.time') }}</div><div class="mt-1 text-sm text-white" dir="ltr">{{ $selectedActivity->created_at?->format('Y-m-d H:i:s') }}</div></div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-3"><div class="text-xs text-neutral-500">{{ __('data_governance.audit.ip_address') }}</div><div class="mt-1 text-sm text-white" dir="ltr">{{ $selectedActivity->getProperty('ip_address', '—') }}</div></div>
                </div>
                @php($changedFields = $this->changedFields($selectedActivity))
                <section class="surface-table settings-record-table" data-settings-record-table data-data-audit-changes-table>
                    <div class="table-scroll-region">
                        <table class="table-fixed text-sm">
                            <colgroup>
                                <col class="w-[16%]">
                                <col class="w-[42%]">
                                <col class="w-[42%]">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="px-5 py-4 text-start">{{ __('data_governance.audit.field') }}</th>
                                    <th class="px-5 py-4 text-start">{{ __('data_governance.audit.before') }}</th>
                                    <th class="px-5 py-4 text-start">{{ __('data_governance.audit.after') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                @foreach ($changedFields as $field)
                                    <tr>
                                        <td class="align-top px-5 py-4 font-medium text-neutral-200">{{ $this->auditFieldLabel($field) }}</td>
                                        <td class="align-top break-words px-5 py-4 text-neutral-400 [overflow-wrap:anywhere]">{{ $this->auditValue(Arr::get($selectedActivity->getProperty('before', []), $field)) }}</td>
                                        @if ($selectedActivity->event === 'deleted')
                                            @if ($loop->first)
                                                <td rowspan="{{ count($changedFields) }}" class="align-middle px-5 py-4 text-center font-medium text-red-200">
                                                    <x-admin-action-icon name="delete" class="mx-auto mb-3 h-8 w-8" data-data-audit-deleted-icon />
                                                    <div>{{ __('data_governance.audit.record_deleted') }}</div>
                                                    <div class="mt-2 text-sm font-normal text-neutral-400" dir="ltr">{{ $this->deletionTimestamp($selectedActivity) }}</div>
                                                </td>
                                            @endif
                                        @else
                                            <td class="align-top break-words px-5 py-4 text-white [overflow-wrap:anywhere]">{{ $this->auditValue(Arr::get($selectedActivity->getProperty('after', []), $field)) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="closeDetails" class="pill-link pill-link--accent">{{ __('crud.common.actions.close') }}</button></div>
            </div>
        @endif
    </x-admin.modal>
</div>

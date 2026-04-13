<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Group;
use App\Models\GroupSchedule;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Group $currentGroup;
    public ?int $editingScheduleId = null;
    public string $day_of_week = '6';
    public string $starts_at = '';
    public string $ends_at = '';
    public string $room_name = '';
    public bool $is_active = true;

    public function mount(Group $group): void
    {
        $this->authorizePermission('groups.view');

        $this->currentGroup = Group::query()
            ->with(['course', 'academicYear', 'teacher', 'assistantTeacher'])
            ->findOrFail($group->id);

        $this->authorizeScopedGroupAccess($this->currentGroup);
    }

    public function with(): array
    {
        return [
            'groupRecord' => $this->currentGroup->fresh(['course', 'academicYear', 'teacher', 'assistantTeacher']),
            'schedules' => GroupSchedule::query()
                ->where('group_id', $this->currentGroup->id)
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get(),
            'days' => $this->dayOptions(),
        ];
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'starts_at' => [
                'required',
                'date_format:H:i',
                Rule::unique('group_schedules', 'starts_at')
                    ->where(fn ($query) => $query
                        ->where('group_id', $this->currentGroup->id)
                        ->where('day_of_week', $this->day_of_week))
                    ->ignore($this->editingScheduleId),
            ],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'room_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->authorizePermission('group-schedules.manage');

        $validated = $this->validate();
        $validated['group_id'] = $this->currentGroup->id;
        $validated['room_name'] = $validated['room_name'] ?: null;

        GroupSchedule::query()->updateOrCreate(
            ['id' => $this->editingScheduleId],
            $validated,
        );

        session()->flash(
            'status',
            $this->editingScheduleId ? __('schedules.group.messages.updated') : __('schedules.group.messages.created'),
        );

        $this->cancel();
    }

    public function edit(int $scheduleId): void
    {
        $this->authorizePermission('group-schedules.manage');

        $schedule = GroupSchedule::query()
            ->where('group_id', $this->currentGroup->id)
            ->findOrFail($scheduleId);

        $this->editingScheduleId = $schedule->id;
        $this->day_of_week = (string) $schedule->day_of_week;
        $this->starts_at = $schedule->starts_at?->format('H:i') ?? '';
        $this->ends_at = $schedule->ends_at?->format('H:i') ?? '';
        $this->room_name = $schedule->room_name ?? '';
        $this->is_active = $schedule->is_active;

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingScheduleId = null;
        $this->day_of_week = '6';
        $this->starts_at = '';
        $this->ends_at = '';
        $this->room_name = '';
        $this->is_active = true;

        $this->resetValidation();
    }

    public function delete(int $scheduleId): void
    {
        $this->authorizePermission('group-schedules.manage');

        $schedule = GroupSchedule::query()
            ->where('group_id', $this->currentGroup->id)
            ->findOrFail($scheduleId);

        $schedule->delete();

        if ($this->editingScheduleId === $scheduleId) {
            $this->cancel();
        }

        session()->flash('status', __('schedules.group.messages.deleted'));
    }

    protected function dayOptions(): array
    {
        return [
            0 => __('schedules.group.days.0'),
            1 => __('schedules.group.days.1'),
            2 => __('schedules.group.days.2'),
            3 => __('schedules.group.days.3'),
            4 => __('schedules.group.days.4'),
            5 => __('schedules.group.days.5'),
            6 => __('schedules.group.days.6'),
        ];
    }
}; ?>

@php
    $teacherName = $groupRecord->teacher ? $groupRecord->teacher->first_name.' '.$groupRecord->teacher->last_name : __('schedules.group.profile.no_teacher');
    $activeSchedulesCount = $schedules->where('is_active', true)->count();
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('groups.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('schedules.group.back') }}</a>
                <div class="eyebrow mt-4">{{ __('ui.nav.academics') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('schedules.group.heading') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('schedules.group.subheading') }}</p>
            </div>

            <div class="surface-panel px-5 py-4">
                <div class="text-sm font-semibold text-white">{{ $groupRecord->name }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ $groupRecord->course?->name ?: __('schedules.group.profile.no_course') }} | {{ $groupRecord->academicYear?->name ?: __('schedules.group.profile.no_year') }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ $teacherName }}</div>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('schedules.group.table.title') }}</div>
            <div class="metric-value mt-3">{{ number_format($schedules->count()) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('crud.common.status_options.active') }}</div>
            <div class="metric-value mt-3">{{ number_format($activeSchedulesCount) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('schedules.group.profile.no_teacher') }}</div>
            <div class="mt-4 text-lg font-semibold text-white">{{ $teacherName }}</div>
        </article>
    </section>

    <div class="grid gap-6 xl:grid-cols-[26rem_minmax(0,1fr)]">
        <section class="surface-panel p-5 lg:p-6">
            @if (auth()->user()->can('group-schedules.manage'))
                <div class="admin-section-card__header">
                    <div class="admin-section-card__title">{{ $editingScheduleId ? __('schedules.group.form.edit_title') : __('schedules.group.form.create_title') }}</div>
                    <p class="admin-section-card__copy">{{ __('schedules.group.form.help') }}</p>
                </div>

                <form wire:submit="save" class="mt-5 space-y-4">
                    <div>
                        <label for="schedule-day" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.day') }}</label>
                        <select id="schedule-day" wire:model="day_of_week" class="w-full rounded-xl px-4 py-3 text-sm">
                            @foreach ($days as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('day_of_week')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="schedule-starts-at" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.starts_at') }}</label>
                            <input id="schedule-starts-at" wire:model="starts_at" type="time" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('starts_at')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="schedule-ends-at" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.ends_at') }}</label>
                            <input id="schedule-ends-at" wire:model="ends_at" type="time" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('ends_at')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="schedule-room-name" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.room_name') }}</label>
                        <input id="schedule-room-name" wire:model="room_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('room_name')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="is_active" type="checkbox" class="rounded">
                        <span>{{ __('schedules.group.form.active_flag') }}</span>
                    </label>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="pill-link pill-link--accent">
                            {{ $editingScheduleId ? __('schedules.group.form.update_submit') : __('schedules.group.form.create_submit') }}
                        </button>

                        @if ($editingScheduleId)
                            <button type="button" wire:click="cancel" class="pill-link">
                                {{ __('crud.common.actions.cancel') }}
                            </button>
                        @endif
                    </div>
                </form>
            @else
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('schedules.group.read_only.title') }}</div>
                    <p class="mt-2 text-neutral-300">{{ __('schedules.group.read_only.body') }}</p>
                </div>
            @endif
        </section>

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('schedules.group.table.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($schedules->count())]) }}</div>
                </div>
            </div>

            @if ($schedules->isEmpty())
                <div class="admin-empty-state">
                    {{ __('schedules.group.table.empty') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('schedules.group.table.headers.day') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('schedules.group.table.headers.time') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('schedules.group.table.headers.room') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('schedules.group.table.headers.status') }}</th>
                                @if (auth()->user()->can('group-schedules.manage'))
                                    <th class="px-5 py-4 text-right lg:px-6">{{ __('schedules.group.table.headers.actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($schedules as $schedule)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">{{ $days[$schedule->day_of_week] ?? $schedule->day_of_week }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $schedule->starts_at?->format('H:i') }} - {{ $schedule->ends_at?->format('H:i') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $schedule->room_name ?: '-' }}</td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <span class="status-chip {{ $schedule->is_active ? 'status-chip--emerald' : 'status-chip--slate' }}">
                                            {{ $schedule->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}
                                        </span>
                                    </td>
                                    @if (auth()->user()->can('group-schedules.manage'))
                                        <td class="px-5 py-4 lg:px-6">
                                            <div class="admin-action-cluster admin-action-cluster--end">
                                                <button type="button" wire:click="edit({{ $schedule->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.edit') }}
                                                </button>
                                                <button type="button" wire:click="delete({{ $schedule->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                    {{ __('crud.common.actions.delete') }}
                                                </button>
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

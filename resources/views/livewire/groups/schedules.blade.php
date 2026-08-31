<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Support\ScheduleTimeSlots;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Group $currentGroup;
    public ?int $editingScheduleId = null;
    public string $day_of_week = '';
    public string $time_slot = '';

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
                ->orderBy('time_slot')
                ->get(),
            'days' => $this->dayOptions(),
            'timeSlots' => ScheduleTimeSlots::options(),
        ];
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'time_slot' => [
                'required',
                Rule::in(ScheduleTimeSlots::keys()),
                Rule::unique('group_schedules', 'time_slot')
                    ->where(fn ($query) => $query
                        ->where('group_id', $this->currentGroup->id)
                        ->where('day_of_week', $this->day_of_week))
                    ->ignore($this->editingScheduleId),
            ],
        ];
    }

    public function save(): void
    {
        $this->authorizePermission('group-schedules.manage');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $validated = $this->validate();
        [$startsAt, $endsAt] = ScheduleTimeSlots::times($validated['time_slot']);
        $validated = [
            'group_id' => $this->currentGroup->id,
            'day_of_week' => $validated['day_of_week'],
            'time_slot' => $validated['time_slot'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'room_name' => null,
            'is_active' => true,
        ];

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

    public function updatedDayOfWeek(): void
    {
        $this->addScheduleWhenComplete();
    }

    public function updatedTimeSlot(): void
    {
        $this->addScheduleWhenComplete();
    }

    protected function addScheduleWhenComplete(): void
    {
        if ($this->editingScheduleId !== null || $this->day_of_week === '' || $this->time_slot === '') {
            return;
        }

        $this->save();
    }

    public function edit(int $scheduleId): void
    {
        $this->authorizePermission('group-schedules.manage');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $schedule = GroupSchedule::query()
            ->where('group_id', $this->currentGroup->id)
            ->findOrFail($scheduleId);

        $this->editingScheduleId = $schedule->id;
        $this->day_of_week = (string) $schedule->day_of_week;
        $this->time_slot = $schedule->time_slot ?: ScheduleTimeSlots::closest($schedule->starts_at?->format('H:i'));

        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingScheduleId = null;
        $this->day_of_week = '';
        $this->time_slot = '';

        $this->resetValidation();
    }

    public function delete(int $scheduleId): void
    {
        $this->authorizePermission('group-schedules.manage');
        if (! $this->ensureGroupIsEditable()) {
            return;
        }

        $schedule = GroupSchedule::query()
            ->where('group_id', $this->currentGroup->id)
            ->findOrFail($scheduleId);

        if (GroupSchedule::query()->where('group_id', $this->currentGroup->id)->count() <= 1) {
            $this->addError('scheduleRows', __('schedules.errors.required'));
            return;
        }

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

    protected function ensureGroupIsEditable(): bool
    {
        $group = $this->currentGroup->fresh('course');

        if (! $group->course_finished_at && ($group->course?->is_active ?? true)) {
            return true;
        }

        $this->addError('group', __('crud.groups.errors.course_archived'));

        return false;
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
                <x-back-link :href="route('groups.index')" navigate />
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

    @error('group')
        <div class="flash-error px-4 py-3 text-sm">{{ $message }}</div>
    @enderror

    <section class="surface-table settings-record-table overflow-visible" data-searchable-select-table-surface>
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('schedules.group.table.title') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($schedules->count())]) }}</div></div></div>
        <div class="overflow-visible">
            <table class="w-full table-fixed text-sm">
                <thead><tr><th class="px-5 py-4 lg:px-6">{{ __('schedules.group.table.headers.day') }}</th><th class="px-5 py-4 lg:px-6">{{ __('schedules.group.form.fields.timing') }}</th>@if(auth()->user()->can('group-schedules.manage') && ! $groupRecord->course_finished_at && ($groupRecord->course?->is_active ?? true))<th class="admin-actions-column w-32 px-2 py-4 text-center">{{ __('schedules.group.table.headers.actions') }}</th>@endif</tr></thead>
                <tbody>
                    @foreach($schedules as $schedule)
                        <tr wire:key="group-schedule-{{ $schedule->id }}-{{ $editingScheduleId === $schedule->id ? 'edit' : 'view' }}" data-group-schedule-row>
                            @if($editingScheduleId === $schedule->id)
                                <td class="px-5 py-4 lg:px-6"><select wire:model="day_of_week" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" class="h-11 w-full rounded-xl px-3"><option value="">{{ __('schedules.group.form.placeholders.day') }}</option>@foreach($days as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('day_of_week')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                <td class="px-5 py-4 lg:px-6"><select wire:model="time_slot" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" class="h-11 w-full rounded-xl px-3"><option value="">{{ __('schedules.group.form.placeholders.timing') }}</option>@foreach($timeSlots as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('time_slot')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td>
                                <td class="px-2 py-4"><div class="flex flex-nowrap items-center justify-center gap-2"><button type="button" wire:click="save" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.common.actions.update') }}" aria-label="{{ __('crud.common.actions.update') }}" data-group-schedule-update><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg></button><button type="button" wire:click="delete({{ $schedule->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="admin-icon-button admin-icon-button--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-group-schedule-delete><x-icons.trash class="size-5" /></button></div></td>
                            @else
                                <td class="px-5 py-4 lg:px-6">{{ $days[$schedule->day_of_week] ?? $schedule->day_of_week }}</td><td class="px-5 py-4 lg:px-6">{{ $timeSlots[$schedule->time_slot ?: \App\Support\ScheduleTimeSlots::closest($schedule->starts_at?->format('H:i'))] }}</td>@if(auth()->user()->can('group-schedules.manage') && ! $groupRecord->course_finished_at && ($groupRecord->course?->is_active ?? true))<td class="px-2 py-4"><div class="flex flex-nowrap items-center justify-center gap-2"><button type="button" wire:click="edit({{ $schedule->id }})" class="admin-icon-button" title="{{ __('crud.common.actions.edit') }}" aria-label="{{ __('crud.common.actions.edit') }}" data-group-schedule-edit><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/></svg></button></div></td>@endif
                            @endif
                        </tr>
                    @endforeach
                    @if(auth()->user()->can('group-schedules.manage') && ! $groupRecord->course_finished_at && ($groupRecord->course?->is_active ?? true))
                        @if($editingScheduleId === null)<tr class="schedule-add-row"><td class="px-5 py-4 lg:px-6"><select wire:model.live="day_of_week" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" class="h-11 w-full rounded-xl px-3"><option value="">{{ __('schedules.group.form.placeholders.day') }}</option>@foreach($days as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('day_of_week')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td><td class="px-5 py-4 lg:px-6"><select wire:model.live="time_slot" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" class="h-11 w-full rounded-xl px-3"><option value="">{{ __('schedules.group.form.placeholders.timing') }}</option>@foreach($timeSlots as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('time_slot')<div class="mt-1 text-xs text-red-400">{{ $message }}</div>@enderror</td><td class="px-2 py-4"></td></tr>@endif
                    @endif
                </tbody>
            </table>
        </div>
        @error('scheduleRows')<div class="px-5 pb-4 text-sm text-red-400">{{ $message }}</div>@enderror
    </section>
</div>

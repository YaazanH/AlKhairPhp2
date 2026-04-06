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

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="{{ route('groups.index') }}" wire:navigate class="text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:hover:text-white">{{ __('schedules.group.back') }}</a>
            <flux:heading size="xl" class="mt-2">{{ __('schedules.group.heading') }}</flux:heading>
            <flux:subheading>{{ __('schedules.group.subheading') }}</flux:subheading>
        </div>

        <div class="rounded-2xl border border-neutral-200 bg-white px-5 py-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium">{{ $groupRecord->name }}</div>
            <div class="mt-1 text-sm text-neutral-500">{{ $groupRecord->course?->name ?: __('schedules.group.profile.no_course') }} | {{ $groupRecord->academicYear?->name ?: __('schedules.group.profile.no_year') }}</div>
            <div class="mt-1 text-sm text-neutral-500">{{ $groupRecord->teacher ? $groupRecord->teacher->first_name.' '.$groupRecord->teacher->last_name : __('schedules.group.profile.no_teacher') }}</div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">
        <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            @if (auth()->user()->can('group-schedules.manage'))
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ $editingScheduleId ? __('schedules.group.form.edit_title') : __('schedules.group.form.create_title') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('schedules.group.form.help') }}</p>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label for="schedule-day" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.day') }}</label>
                        <select id="schedule-day" wire:model="day_of_week" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @foreach ($days as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('day_of_week')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="schedule-starts-at" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.starts_at') }}</label>
                            <input id="schedule-starts-at" wire:model="starts_at" type="time" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('starts_at')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="schedule-ends-at" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.ends_at') }}</label>
                            <input id="schedule-ends-at" wire:model="ends_at" type="time" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('ends_at')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="schedule-room-name" class="mb-1 block text-sm font-medium">{{ __('schedules.group.form.fields.room_name') }}</label>
                        <input id="schedule-room-name" wire:model="room_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('room_name')
                            <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('schedules.group.form.active_flag') }}</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                            {{ $editingScheduleId ? __('schedules.group.form.update_submit') : __('schedules.group.form.create_submit') }}
                        </button>

                        @if ($editingScheduleId)
                            <button type="button" wire:click="cancel" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">
                                {{ __('crud.common.actions.cancel') }}
                            </button>
                        @endif
                    </div>
                </form>
            @else
                <div>
                    <h2 class="text-lg font-semibold">{{ __('schedules.group.read_only.title') }}</h2>
                    <p class="mt-2 text-sm text-neutral-500">{{ __('schedules.group.read_only.body') }}</p>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">
                {{ __('schedules.group.table.title') }}
            </div>

            @if ($schedules->isEmpty())
                <div class="px-5 py-10 text-sm text-neutral-500">
                    {{ __('schedules.group.table.empty') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                            <tr>
                                <th class="px-5 py-3 text-left font-medium">{{ __('schedules.group.table.headers.day') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('schedules.group.table.headers.time') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('schedules.group.table.headers.room') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('schedules.group.table.headers.status') }}</th>
                                @if (auth()->user()->can('group-schedules.manage'))
                                    <th class="px-5 py-3 text-right font-medium">{{ __('schedules.group.table.headers.actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($schedules as $schedule)
                                <tr>
                                    <td class="px-5 py-3">{{ $days[$schedule->day_of_week] ?? $schedule->day_of_week }}</td>
                                    <td class="px-5 py-3">{{ $schedule->starts_at?->format('H:i') }} - {{ $schedule->ends_at?->format('H:i') }}</td>
                                    <td class="px-5 py-3">{{ $schedule->room_name ?: '-' }}</td>
                                    <td class="px-5 py-3">{{ $schedule->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.inactive') }}</td>
                                    @if (auth()->user()->can('group-schedules.manage'))
                                        <td class="px-5 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="edit({{ $schedule->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">
                                                    {{ __('crud.common.actions.edit') }}
                                                </button>
                                                <button type="button" wire:click="delete({{ $schedule->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">
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

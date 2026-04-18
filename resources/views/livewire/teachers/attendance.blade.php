<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Models\TeacherAttendanceRecord;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $attendanceDayId = null;
    public string $attendance_date = '';
    public string $day_status = 'open';
    public string $notes = '';
    public array $selected_statuses = [];

    public function mount(): void
    {
        $this->authorizePermission('attendance.teacher.view');

        $this->attendance_date = now()->toDateString();
        $this->loadDay();
    }

    public function with(): array
    {
        return [
            'statuses' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['teacher', 'both'])
                ->orderBy('name')
                ->get(),
            'teachers' => $this->scopeTeachersQuery(
                Teacher::query()
                    ->with('jobTitle')
                    ->where('is_helping', true)
                    ->whereIn('status', ['active', 'inactive'])
                    ->orderBy('first_name')
                    ->orderBy('last_name')
            )->get(),
        ];
    }

    public function updatedAttendanceDate(): void
    {
        $this->loadDay();
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        $validated = $this->validate([
            'attendance_date' => ['required', 'date'],
            'day_status' => ['required', 'in:open,closed'],
            'notes' => ['nullable', 'string'],
            'selected_statuses' => ['array'],
            'selected_statuses.*' => ['nullable', 'exists:attendance_statuses,id'],
        ]);

        foreach (array_keys(array_filter($validated['selected_statuses'])) as $teacherId) {
            $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail((int) $teacherId));
        }

        $selectedTeacherIds = collect(array_keys(array_filter($validated['selected_statuses'])))
            ->map(fn ($teacherId) => (int) $teacherId)
            ->values();
        $allowedTeacherIds = $this->scopeTeachersQuery(
            Teacher::query()
                ->where('is_helping', true)
                ->whereIn('status', ['active', 'inactive'])
        )->pluck('id');

        if ($selectedTeacherIds->diff($allowedTeacherIds)->isNotEmpty()) {
            $this->addError('selected_statuses', __('workflow.teacher_attendance.errors.teacher_not_helping'));

            return;
        }

        $day = TeacherAttendanceDay::query()
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->first();

        if (! $day) {
            $day = TeacherAttendanceDay::query()->create([
                'attendance_date' => $validated['attendance_date'],
                'created_by' => auth()->id(),
            ]);
        }

        $day->update([
            'status' => $validated['day_status'],
            'notes' => $validated['notes'] ?: null,
        ]);

        foreach (array_filter($validated['selected_statuses']) as $teacherId => $statusId) {
            TeacherAttendanceRecord::query()->updateOrCreate(
                [
                    'teacher_attendance_day_id' => $day->id,
                    'teacher_id' => (int) $teacherId,
                ],
                [
                    'attendance_status_id' => $statusId,
                ],
            );
        }

        $this->attendanceDayId = $day->id;

        session()->flash('status', __('workflow.teacher_attendance.messages.saved'));
    }

    protected function loadDay(): void
    {
        $day = TeacherAttendanceDay::query()
            ->with('records')
            ->whereDate('attendance_date', $this->attendance_date)
            ->first();

        $this->attendanceDayId = $day?->id;
        $this->day_status = $day?->status ?? 'open';
        $this->notes = $day?->notes ?? '';
        $allowedTeacherIds = $this->scopeTeachersQuery(
            Teacher::query()
                ->where('is_helping', true)
                ->whereIn('status', ['active', 'inactive'])
        )->pluck('id')->all();
        $this->selected_statuses = $day
            ? $day->records
                ->whereIn('teacher_id', $allowedTeacherIds)
                ->mapWithKeys(fn (TeacherAttendanceRecord $record) => [$record->teacher_id => $record->attendance_status_id])
                ->toArray()
            : [];
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div>
        <flux:heading size="xl">{{ __('workflow.teacher_attendance.title') }}</flux:heading>
        <flux:subheading>{{ __('workflow.teacher_attendance.subtitle') }}</flux:subheading>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="grid gap-4 lg:grid-cols-[14rem_10rem_minmax(0,1fr)]">
            <div>
                <label for="teacher-attendance-date" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.attendance_date') }}</label>
                <input id="teacher-attendance-date" wire:model.live="attendance_date" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('attendance_date')
                    <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="teacher-attendance-status" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.day_status') }}</label>
                <select id="teacher-attendance-status" wire:model="day_status" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                    <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                </select>
            </div>

            <div>
                <label for="teacher-attendance-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.notes') }}</label>
                <input id="teacher-attendance-notes" wire:model="notes" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
            </div>
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">
            {{ __('workflow.teacher_attendance.table.title') }}
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                    <tr>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.teacher_attendance.table.headers.teacher') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.teacher_attendance.table.headers.job_title') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.teacher_attendance.table.headers.status') }}</th>
                        <th class="px-5 py-3 text-left font-medium">{{ __('workflow.teacher_attendance.table.headers.attendance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($teachers as $teacher)
                        <tr>
                            <td class="px-5 py-3">{{ $teacher->first_name }} {{ $teacher->last_name }}</td>
                            <td class="px-5 py-3">{{ $teacher->jobTitle?->name ?: ($teacher->job_title ?: __('workflow.common.not_available')) }}</td>
                            <td class="px-5 py-3">{{ __('crud.common.status_options.' . $teacher->status) }}</td>
                            <td class="px-5 py-3">
                                <select
                                    wire:model="selected_statuses.{{ $teacher->id }}"
                                    @disabled(! auth()->user()->can('attendance.teacher.take'))
                                    class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <option value="">{{ __('workflow.teacher_attendance.table.not_marked') }}</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->id }}">{{ $status->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('workflow.teacher_attendance.table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('attendance.teacher.take')
        @error('selected_statuses')
            <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
        @enderror
        <div class="flex justify-end">
            <button wire:click="saveAttendance" type="button" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                {{ __('workflow.common.actions.save_teacher_attendance') }}
            </button>
        </div>
    @endcan
</div>

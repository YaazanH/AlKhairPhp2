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

    public function deleteDay(): void
    {
        $this->authorizePermission('attendance.teacher.take');

        if (! $this->attendanceDayId) {
            return;
        }

        $day = TeacherAttendanceDay::query()->findOrFail($this->attendanceDayId);
        $day->records()->delete();
        $day->delete();

        $this->loadDay();

        session()->flash('status', __('workflow.teacher_attendance.messages.deleted'));
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

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.teacher_attendance.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.teacher_attendance.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.teacher_attendance.table.title') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.teacher_attendance.stats.helping_teachers', ['count' => number_format($teachers->count())]) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.teacher_attendance.form.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.teacher_attendance.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                <label for="teacher-attendance-date" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.attendance_date') }}</label>
                    <input id="teacher-attendance-date" wire:model.live="attendance_date" type="date">
                @error('attendance_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

                <div class="admin-filter-field">
                <label for="teacher-attendance-status" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.day_status') }}</label>
                    <select id="teacher-attendance-status" wire:model="day_status" data-searchable="false">
                    <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                    <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                </select>
            </div>

                <div class="admin-filter-field">
                <label for="teacher-attendance-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.teacher_attendance.form.notes') }}</label>
                    <input id="teacher-attendance-notes" wire:model="notes" type="text">
                </div>

                <div class="admin-toolbar__actions">
                    @can('attendance.teacher.take')
                        <button wire:click="saveAttendance" type="button" class="pill-link pill-link--accent">
                            {{ __('workflow.common.actions.save_teacher_attendance') }}
                        </button>
                        @if ($attendanceDayId)
                            <button wire:click="deleteDay" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" type="button" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                {{ __('crud.common.actions.delete') }}
                            </button>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    </section>

    @can('attendance.teacher.take')
        @error('selected_statuses')
            <div class="rounded-xl border border-red-500/25 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
        @enderror
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.teacher_attendance.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('workflow.teacher_attendance.table.summary', ['count' => number_format($teachers->count())]) }}</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead>
                    <tr>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.teacher') }}</th>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.job_title') }}</th>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.status') }}</th>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.teacher_attendance.table.headers.attendance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @forelse ($teachers as $teacher)
                        <tr>
                            <td class="px-5 py-4 lg:px-6">
                                <div class="student-inline">
                                    <x-teacher-avatar :teacher="$teacher" size="sm" />
                                    <div class="student-inline__body">
                                        <div class="student-inline__name">{{ $teacher->first_name }} {{ $teacher->last_name }}</div>
                                        <div class="student-inline__meta">{{ $teacher->phone }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $teacher->jobTitle?->name ?: ($teacher->job_title ?: __('workflow.common.not_available')) }}</td>
                            <td class="px-5 py-4 lg:px-6">
                                <span class="{{ $teacher->status === 'active' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                    {{ __('crud.common.status_options.' . $teacher->status) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 lg:px-6">
                                <select
                                    wire:model="selected_statuses.{{ $teacher->id }}"
                                    @disabled(! auth()->user()->can('attendance.teacher.take'))
                                    data-searchable="false"
                                    class="w-full rounded-xl px-4 py-3 text-sm"
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
                            <td colspan="4" class="admin-empty-state">{{ __('workflow.teacher_attendance.table.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

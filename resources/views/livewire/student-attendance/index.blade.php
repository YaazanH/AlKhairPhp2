<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\StudentAttendanceDay;
use App\Services\PointLedgerService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public string $attendance_date = '';
    public string $day_status = 'open';
    public string $default_attendance_status_id = '';
    public string $notes = '';
    public string $search = '';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizePermission('attendance.student.view');
        $this->attendance_date = now()->toDateString();
    }

    public function with(): array
    {
        $daysQuery = $this->scopeStudentAttendanceDaysQuery(
            StudentAttendanceDay::query()->with([
                'groupAttendanceDays' => fn ($query) => $this->scopeGroupAttendanceDaysQuery(
                    $query->withCount('records')
                ),
            ])
        )
            ->when(filled($this->search), fn (Builder $query) => $query->whereDate('attendance_date', $this->search))
            ->when(
                in_array($this->statusFilter, ['open', 'closed'], true),
                fn (Builder $query) => $query->where('status', $this->statusFilter)
            )
            ->latest('attendance_date')
            ->latest('id');

        $activeGroupCount = $this->scopeGroupsQuery(
            Group::query()->where('is_active', true)
        )->count();
        $scheduledGroupCount = filled($this->attendance_date)
            ? $this->scheduledGroupsForDate($this->attendance_date)->count()
            : 0;

        return [
            'days' => $daysQuery->paginate($this->perPage),
            'filteredCount' => (clone $daysQuery)->count(),
            'stats' => [
                'days' => $this->scopeStudentAttendanceDaysQuery(StudentAttendanceDay::query())->count(),
                'groups' => $this->scopeGroupAttendanceDaysQuery(\App\Models\GroupAttendanceDay::query())->count(),
                'open' => $this->scopeStudentAttendanceDaysQuery(StudentAttendanceDay::query()->where('status', 'open'))->count(),
            ],
            'activeGroupCount' => $activeGroupCount,
            'scheduledGroupCount' => $scheduledGroupCount,
            'defaultStatusOptions' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderByDesc('is_default')
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('attendance.student.take');

        $this->attendance_date = now()->toDateString();
        $this->day_status = 'open';
        $this->default_attendance_status_id = (string) ($this->defaultStudentAttendanceStatusId() ?? '');
        $this->notes = '';
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function closeCreateModal(): void
    {
        $this->showFormModal = false;
        $this->resetValidation();
    }

    public function saveDay()
    {
        $this->authorizePermission('attendance.student.take');

        if (! AttendanceStatus::query()->where('is_active', true)->whereIn('scope', ['student', 'both'])->exists()) {
            $this->addError('default_attendance_status_id', __('workflow.student_attendance.days.form.no_default_status'));

            return null;
        }

        $validated = $this->validate([
            'attendance_date' => ['required', 'date'],
            'day_status' => ['required', 'in:open,closed'],
            'default_attendance_status_id' => [
                'required',
                'integer',
                Rule::exists('attendance_statuses', 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('scope', ['student', 'both'])),
            ],
            'notes' => ['nullable', 'string'],
        ]);

        $groups = $this->scheduledGroupsForDate($validated['attendance_date']);

        $day = app(StudentAttendanceDayService::class)->createOrSyncDay(
            $validated['attendance_date'],
            $groups,
            auth()->user(),
            $validated['notes'] ?: null,
            $validated['day_status'],
            (int) $validated['default_attendance_status_id'],
        );

        session()->flash('status', __('workflow.student_attendance.days.messages.created'));

        $this->closeCreateModal();

        return redirect()->route('student-attendance.show', $day);
    }

    public function deleteDay(int $dayId): void
    {
        $this->authorizePermission('attendance.student.take');

        $day = $this->scopeStudentAttendanceDaysQuery(
            StudentAttendanceDay::query()->with(['groupAttendanceDays.records.enrollment.student'])
        )->findOrFail($dayId);

        $enrollmentIds = collect();

        DB::transaction(function () use ($day, &$enrollmentIds): void {
            foreach ($day->groupAttendanceDays as $groupDay) {
                foreach ($groupDay->records as $record) {
                    $enrollmentIds->push($record->enrollment_id);
                    app(PointLedgerService::class)->voidSourceTransactions(
                        'student_attendance_record',
                        $record->id,
                        __('workflow.student_attendance.messages.deleted_void_reason'),
                    );
                }

                $groupDay->records()->delete();
                $groupDay->delete();
            }

            $day->delete();
        });

        Enrollment::query()
            ->with('student')
            ->whereKey($enrollmentIds->filter()->unique()->values())
            ->get()
            ->each(fn (Enrollment $enrollment) => app(PointLedgerService::class)->syncEnrollmentCaches($enrollment));

        session()->flash('status', __('workflow.student_attendance.days.messages.deleted'));
    }

    protected function defaultStudentAttendanceStatusId(): ?int
    {
        return AttendanceStatus::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->value('id') ?? AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->value('id');
    }

    protected function scheduledGroupsForDate(string $attendanceDate)
    {
        try {
            $dayOfWeek = Carbon::parse($attendanceDate)->dayOfWeek;
        } catch (\Throwable) {
            return collect();
        }

        return $this->scopeGroupsQuery(
            Group::query()
                ->with(['course', 'teacher'])
                ->where('is_active', true)
                ->whereHas('schedules', fn ($scheduleQuery) => $scheduleQuery
                    ->where('is_active', true)
                    ->where('day_of_week', $dayOfWeek))
                ->orderBy('name')
        )->get();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.days.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_attendance.days.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.student_attendance.days.stats.days') }}: {{ number_format($stats['days']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.student_attendance.days.stats.groups') }}: {{ number_format($stats['groups']) }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.days.stats.open') }}: {{ number_format($stats['open']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.student_attendance.days.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.student_attendance.days.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="student-attendance-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="student-attendance-search" wire:model.live.debounce.300ms="search" type="text" placeholder="YYYY-MM-DD">
                </div>

                <div class="admin-filter-field">
                    <label for="student-attendance-status">{{ __('workflow.student_attendance.days.form.status') }}</label>
                    <select id="student-attendance-status" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                        <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('attendance.student.take')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.student_attendance.days.create') }}</button>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.days.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($days->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.days.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.groups') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.marked') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_attendance.days.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.student_attendance.days.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($days as $day)
                            @php
                                $groupCount = $day->groupAttendanceDays->count();
                                $markedCount = $day->groupAttendanceDays->sum('records_count');
                            @endphp
                            <tr>
                                <td class="px-5 py-4 text-white lg:px-6">
                                    <div class="font-semibold">{{ $day->attendance_date?->format('Y-m-d') }}</div>
                                    <div class="mt-1 text-xs text-neutral-500">{{ $day->notes ?: __('workflow.common.not_available') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($groupCount) }} / {{ number_format($activeGroupCount) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($markedCount) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="{{ $day->status === 'closed' ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                        {{ __('workflow.common.day_status.'.$day->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('student-attendance.show', $day) }}" wire:navigate class="pill-link pill-link--compact">
                                            {{ __('workflow.student_attendance.days.table.view') }}
                                        </a>
                                        @can('attendance.student.take')
                                            <button type="button" wire:click="deleteDay({{ $day->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                {{ __('crud.common.actions.delete') }}
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($days->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $days->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="__('workflow.student_attendance.days.form.title')"
        :description="__('workflow.student_attendance.days.form.help')"
        close-method="closeCreateModal"
        max-width="4xl"
    >
        <form wire:submit="saveDay" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="attendance-day-date" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.attendance_date') }}</label>
                    <input id="attendance-day-date" wire:model.live="attendance_date" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('attendance_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="attendance-day-status" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.status') }}</label>
                    <select id="attendance-day-status" wire:model="day_status" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="open">{{ __('workflow.common.day_status.open') }}</option>
                        <option value="closed">{{ __('workflow.common.day_status.closed') }}</option>
                    </select>
                    @error('day_status')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

                <div>
                    <label for="attendance-day-default-status" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.default_status') }}</label>
                <select id="attendance-day-default-status" wire:model="default_attendance_status_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('workflow.student_attendance.days.form.no_default_status') }}</option>
                    @foreach ($defaultStatusOptions as $status)
                        <option value="{{ $status->id }}">{{ $status->name }}{{ $status->is_default ? ' - '.__('settings.tracking.labels.default_attendance_status') : '' }}</option>
                    @endforeach
                </select>
                <div class="mt-1 text-xs text-neutral-400">{{ __('workflow.student_attendance.days.form.default_status_help') }}</div>
                @error('default_attendance_status_id')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="soft-callout p-4 text-sm">
                {{ __('workflow.student_attendance.days.form.scheduled_groups_help', ['count' => number_format($scheduledGroupCount)]) }}
            </div>

            <div>
                <label for="attendance-day-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.days.form.notes') }}</label>
                <textarea id="attendance-day-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.student_attendance.days.create') }}</button>
                <button type="button" wire:click="closeCreateModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>

<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AttendanceStatus;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Services\AccessScopeService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesPermissions;
    use WithPagination;

    public StudentAttendanceDay $studentAttendanceDay;

    public string $search = '';

    public function mount(StudentAttendanceDay $studentAttendanceDay): void
    {
        $this->authorizePermission('attendance.student.view');
        abort_unless($studentAttendanceDay->scope === 'center', 404);
        abort_unless(app(AccessScopeService::class)->canAccessStudentAttendanceDay(auth()->user(), $studentAttendanceDay), 403);
        $this->studentAttendanceDay = $studentAttendanceDay;
    }

    public function with(): array
    {
        $records = $this->studentAttendanceDay->centerRecords()->with('status')->get()->keyBy('student_id');

        return [
            'records' => $records,
            'students' => Student::query()
                ->where('status', 'active')
                ->when(filled($this->search), fn (Builder $query) => $query->where(fn (Builder $searchQuery) => $searchQuery
                    ->where('first_name', 'like', '%'.$this->search.'%')
                    ->orWhere('last_name', 'like', '%'.$this->search.'%')
                    ->orWhere('student_number', 'like', '%'.$this->search.'%')))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->paginate(25),
            'statusOptions' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setStatus(int $studentId, int $statusId): void
    {
        $this->authorizePermission('attendance.student.take');
        $student = Student::query()->where('status', 'active')->findOrFail($studentId);
        $status = AttendanceStatus::query()->whereKey($statusId)->where('is_active', true)->whereIn('scope', ['student', 'both'])->firstOrFail();
        app(StudentAttendanceDayService::class)->recordStudentStatus($this->studentAttendanceDay, $student, $status);
    }

    public function toggleDayStatus(): void
    {
        $this->authorizePermission('attendance.student.take');
        $status = $this->studentAttendanceDay->status === 'open' ? 'closed' : 'open';
        $this->studentAttendanceDay = app(StudentAttendanceDayService::class)->setDayStatus($this->studentAttendanceDay, $status);
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('modules.center_attendance.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('modules.center_attendance.title') }}</h1>
        <p class="mt-3 text-neutral-200">{{ $studentAttendanceDay->attendance_date?->format('d-m-Y') }}</p>
        @can('attendance.student.take')
            <button type="button" wire:click="toggleDayStatus" class="pill-link mt-5">
                {{ $studentAttendanceDay->status === 'open' ? __('modules.center_attendance.close_day') : __('modules.center_attendance.reopen_day') }}
            </button>
        @endcan
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('modules.center_attendance.students') }}</div>
            <div class="admin-filter-field">
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('crud.common.search') }}" class="rounded-xl px-4 py-3 text-sm">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr>
                    <th class="px-5 py-4 text-left">{{ __('modules.center_attendance.student') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('modules.center_attendance.status') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-white/6">
                    @foreach($students as $student)
                        @php($record = $records->get($student->id))
                        <tr wire:key="center-attendance-student-{{ $student->id }}">
                            <td class="px-5 py-4 font-semibold text-white">{{ $student->full_name }}</td>
                            <td class="px-5 py-4">
                                <select
                                    wire:change="setStatus({{ $student->id }}, $event.target.value)"
                                    @disabled($studentAttendanceDay->status === 'closed' || ! auth()->user()->can('attendance.student.take'))
                                    class="w-full max-w-xs rounded-xl px-4 py-2 text-sm"
                                >
                                    <option value="">{{ __('modules.center_attendance.select_status') }}</option>
                                    @foreach($statusOptions as $status)
                                        <option value="{{ $status->id }}" @selected($record?->attendance_status_id === $status->id)>{{ $status->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($students->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $students->links() }}</div>@endif
    </section>
</div>

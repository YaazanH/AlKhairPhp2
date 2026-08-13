<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Services\BarcodeActions\BarcodeActionCatalogService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public StudentAttendanceDay $currentDay;

    public string $selected_status_id = '';

    public string $scan_value = '';

    public string $scan_feedback = '';

    public string $scan_feedback_type = 'info';

    public string $search = '';

    public string $sortField = 'student';

    public string $sortDirection = 'asc';

    protected array $sortableFields = [
        'attendance',
        'group',
        'student',
    ];

    public function mount(StudentAttendanceDay $studentAttendanceDay): void
    {
        $this->authorizePermission('attendance.student.view');

        $this->currentDay = StudentAttendanceDay::query()
            ->with(['course', 'groupAttendanceDays.group'])
            ->findOrFail($studentAttendanceDay->id);

        $this->authorizeScopedStudentAttendanceDayAccess($this->currentDay);
        $this->selected_status_id = (string) ($this->defaultStudentAttendanceStatusId() ?? '');
    }

    public function with(): array
    {
        $day = $this->currentDay->fresh(['course', 'groupAttendanceDays.group']);
        $groupIds = $day->groupAttendanceDays->pluck('group_id')->filter()->values();
        $records = StudentAttendanceRecord::query()
            ->with('status')
            ->whereIn('group_attendance_day_id', $day->groupAttendanceDays->pluck('id'))
            ->get()
            ->keyBy('enrollment_id');

        $enrollments = Enrollment::query()
            ->with(['group.course', 'student.parentProfile'])
            ->whereIn('group_id', $groupIds)
            ->where('status', 'active')
            ->when(filled($this->search), fn (Builder $query) => $this->applyQuickStudentSearch($query, $this->search))
            ->orderBy(
                Student::query()
                    ->select('first_name')
                    ->whereColumn('students.id', 'enrollments.student_id')
                    ->limit(1),
            )
            ->orderBy(
                Student::query()
                    ->select('last_name')
                    ->whereColumn('students.id', 'enrollments.student_id')
                    ->limit(1),
            )
            ->get();
        $enrollments = $this->sortedEnrollments($enrollments, $records);

        return [
            'dayRecord' => $day,
            'enrollments' => $enrollments,
            'isDayClosed' => $day->status === 'closed',
            'markedCount' => $records->count(),
            'recordsByEnrollment' => $records,
            'statuses' => AttendanceStatus::query()
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderByDesc('is_default')
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function markEnrollment(int $enrollmentId): bool
    {
        $this->authorizePermission('attendance.student.take');

        if ($this->currentDay->fresh()->status === 'closed') {
            $this->addError('scan_value', __('workflow.student_attendance.messages.closed_day_locked'));
            $this->setScanFeedback(__('workflow.student_attendance.messages.closed_day_locked'), 'error');

            return false;
        }

        if (blank($this->selected_status_id)) {
            $this->addError('selected_status_id', __('workflow.student_attendance.quick.errors.select_status_required'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.select_status_required'), 'error');

            return false;
        }

        $groupIds = $this->currentDay->groupAttendanceDays()->pluck('group_id');
        $enrollment = Enrollment::query()
            ->with(['student', 'group'])
            ->whereKey($enrollmentId)
            ->whereIn('group_id', $groupIds)
            ->where('status', 'active')
            ->firstOrFail();

        $this->authorizeScopedEnrollmentAccess($enrollment);

        $status = AttendanceStatus::query()
            ->whereKey((int) $this->selected_status_id)
            ->where('is_active', true)
            ->whereIn('scope', ['student', 'both'])
            ->first();

        if (! $status) {
            $this->addError('selected_status_id', __('workflow.student_attendance.quick.errors.select_status_required'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.select_status_required'), 'error');

            return false;
        }

        try {
            app(StudentAttendanceDayService::class)->recordEnrollmentStatus($this->currentDay, $enrollment, $status);
        } catch (InvalidArgumentException $exception) {
            $this->addError('scan_value', $exception->getMessage());
            $this->setScanFeedback($exception->getMessage(), 'error');

            return false;
        }

        $this->resetErrorBag('scan_value');
        $this->resetErrorBag('selected_status_id');
        $message = __('workflow.student_attendance.quick.messages.marked', [
            'student' => $enrollment->student?->full_name,
            'status' => $status->name,
        ]);
        $this->setScanFeedback($message, 'success');
        session()->flash('status', __('workflow.student_attendance.quick.messages.marked', [
            'student' => $enrollment->student?->full_name,
            'status' => $status->name,
        ]));

        return true;
    }

    public function scanStudent(): void
    {
        $this->authorizePermission('attendance.student.take');

        $value = trim($this->scan_value);

        if ($value === '') {
            $this->addError('scan_value', __('workflow.student_attendance.quick.errors.empty_scan'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.empty_scan'), 'error');

            return;
        }

        $studentNumber = app(BarcodeActionCatalogService::class)->studentNumberFromBarcode($value);

        if (! $studentNumber) {
            $this->addError('scan_value', __('workflow.student_attendance.quick.errors.unknown_scan'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.unknown_scan'), 'error');

            return;
        }

        $groupIds = $this->currentDay->groupAttendanceDays()->pluck('group_id');
        $enrollments = Enrollment::query()
            ->with(['student', 'group'])
            ->whereIn('group_id', $groupIds)
            ->where('status', 'active')
            ->whereHas('student', fn (Builder $query) => $query
                ->where('student_number', $studentNumber)
                ->orWhere('id', (int) $studentNumber))
            ->get();

        if ($enrollments->isEmpty()) {
            $this->addError('scan_value', __('workflow.student_attendance.quick.errors.student_not_in_day'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.student_not_in_day'), 'error');

            return;
        }

        if ($enrollments->count() > 1) {
            $this->addError('scan_value', __('workflow.student_attendance.quick.errors.multiple_enrollments'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.multiple_enrollments'), 'error');

            return;
        }

        if ($this->markEnrollment($enrollments->first()->id)) {
            $this->dispatch('quick-attendance-scan-succeeded', message: $this->scan_feedback);
            $this->scan_value = '';
        }
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = in_array($field, ['group', 'student'], true) ? 'asc' : 'desc';
    }

    protected function setScanFeedback(string $message, string $type = 'info'): void
    {
        $this->scan_feedback = $message;
        $this->scan_feedback_type = in_array($type, ['success', 'error', 'info'], true) ? $type : 'info';
    }

    protected function sortedEnrollments($enrollments, $records)
    {
        $field = in_array($this->sortField, $this->sortableFields, true)
            ? $this->sortField
            : 'student';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return $enrollments
            ->sort(function (Enrollment $left, Enrollment $right) use ($field, $direction, $records): int {
                $comparison = match ($field) {
                    'attendance' => strnatcasecmp(
                        (string) ($records->get($left->id)?->status?->name ?? ''),
                        (string) ($records->get($right->id)?->status?->name ?? ''),
                    ),
                    'group' => strnatcasecmp((string) ($left->group?->name ?? ''), (string) ($right->group?->name ?? '')),
                    default => strnatcasecmp((string) ($left->student?->full_name ?? ''), (string) ($right->student?->full_name ?? '')),
                };

                if ($comparison === 0) {
                    $comparison = strnatcasecmp((string) ($left->student?->full_name ?? ''), (string) ($right->student?->full_name ?? ''));
                }

                return $direction === 'desc' ? -$comparison : $comparison;
            })
            ->values();
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    protected function applyQuickStudentSearch(Builder $query, string $search): void
    {
        $normalizedSearch = '%'.$this->normalizeArabicSearch($search).'%';
        $rawSearch = '%'.trim($search).'%';

        $query->where(function (Builder $builder) use ($normalizedSearch, $rawSearch): void {
            $builder
                ->whereHas('student', function (Builder $studentQuery) use ($normalizedSearch, $rawSearch): void {
                    $normalizedFullName = $this->normalizedSqlExpression($this->sqlConcatWithSpaces(['first_name', 'last_name']));
                    $normalizedFirstName = $this->normalizedSqlExpression('coalesce(first_name, \'\')');
                    $normalizedLastName = $this->normalizedSqlExpression('coalesce(last_name, \'\')');

                    $studentQuery
                        ->whereRaw($normalizedFirstName.' like ?', [$normalizedSearch])
                        ->orWhereRaw($normalizedLastName.' like ?', [$normalizedSearch])
                        ->orWhereRaw($normalizedFullName.' like ?', [$normalizedSearch])
                        ->orWhere('student_number', 'like', $rawSearch);
                });
        });
    }

    protected function normalizeArabicSearch(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return strtr($normalized, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
            'ة' => 'ه',
            'ء' => '',
            'ـ' => '',
            'ً' => '',
            'ٌ' => '',
            'ٍ' => '',
            'َ' => '',
            'ُ' => '',
            'ِ' => '',
            'ّ' => '',
            'ْ' => '',
        ]);
    }

    protected function normalizedSqlExpression(string $expression): string
    {
        foreach ([
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ى' => 'ي',
            'ة' => 'ه',
            'ء' => '',
            'ـ' => '',
            'ً' => '',
            'ٌ' => '',
            'ٍ' => '',
            'َ' => '',
            'ُ' => '',
            'ِ' => '',
            'ّ' => '',
            'ْ' => '',
        ] as $from => $to) {
            $expression = "replace($expression, '$from', '$to')";
        }

        return "trim(replace(replace(replace($expression, '  ', ' '), '  ', ' '), '  ', ' '))";
    }

    protected function sqlConcatWithSpaces(array $columns): string
    {
        $wrappedColumns = array_map(fn (string $column) => "coalesce($column, '')", $columns);

        return DB::connection()->getDriverName() === 'sqlite'
            ? implode(" || ' ' || ", $wrappedColumns)
            : 'concat_ws(\' \', '.implode(', ', $wrappedColumns).')';
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.student_attendance') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.quick.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_attendance.quick.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $dayRecord->attendance_date?->format('d-m-Y') }}</span>
            <span class="badge-soft badge-soft--emerald">{{ $dayRecord->course?->name ?: __('workflow.common.no_course') }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.day_details.stats.groups') }}: {{ number_format($dayRecord->groupAttendanceDays->count()) }}</span>
            <span class="badge-soft">{{ __('workflow.student_attendance.day_details.stats.marked') }}: {{ number_format($markedCount) }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('student-attendance.show', $dayRecord) }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.student_attendance.marking.back') }}</a>
    </div>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if ($isDayClosed)
        <div class="soft-callout p-4 text-sm text-amber-100">
            {{ __('workflow.student_attendance.messages.closed_day_locked') }}
        </div>
    @endif

    <section
        class="surface-panel p-5 lg:p-6"
        id="quick-attendance-scanner"
        data-quick-attendance-scanner
        data-camera-idle="{{ __('workflow.student_attendance.quick.camera_idle') }}"
        data-camera-running="{{ __('workflow.student_attendance.quick.camera_running') }}"
        data-camera-detected="{{ __('workflow.student_attendance.quick.camera_detected') }}"
        data-camera-not-supported="{{ __('workflow.student_attendance.quick.camera_not_supported') }}"
        data-camera-error="{{ __('workflow.student_attendance.quick.camera_error') }}"
    >
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.student_attendance.quick.scanner_title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.student_attendance.quick.scanner_help') }}</p>
            </div>
            <div class="admin-toolbar__actions">
                <button type="button" class="pill-link pill-link--accent" data-quick-attendance-start @disabled($isDayClosed)>
                    {{ __('workflow.student_attendance.quick.start_camera') }}
                </button>
                <button type="button" class="pill-link" data-quick-attendance-stop>
                    {{ __('workflow.student_attendance.quick.stop_camera') }}
                </button>
            </div>
        </div>

        <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="overflow-hidden rounded-2xl border border-white/10 bg-black/40" wire:ignore>
                <video data-quick-attendance-video class="aspect-video w-full object-cover" muted playsinline></video>
            </div>
            <div class="space-y-4">
                <div>
                    <label for="quick-attendance-status" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.quick.status') }}</label>
                    <select id="quick-attendance-status" wire:model="selected_status_id" class="w-full rounded-xl px-4 py-3 text-sm" data-searchable="false" @disabled($isDayClosed)>
                        <option value="">{{ __('workflow.student_attendance.quick.select_status') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->id }}">{{ $status->name }}{{ $status->is_default ? ' - '.__('settings.tracking.labels.default_attendance_status') : '' }}</option>
                        @endforeach
                    </select>
                    @error('selected_status_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="soft-callout p-4 text-sm {{ $scan_feedback_type === 'success' ? 'text-emerald-100' : ($scan_feedback_type === 'error' ? 'text-red-100' : '') }}" data-quick-attendance-message>
                    {{ $scan_feedback ?: __('workflow.student_attendance.quick.camera_idle') }}
                </div>

                <div>
                    <label for="quick-attendance-scan" class="mb-1 block text-sm font-medium">{{ __('workflow.student_attendance.quick.scan_input') }}</label>
                    <input id="quick-attendance-scan" wire:model="scan_value" wire:keydown.enter="scanStudent" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('workflow.student_attendance.quick.scan_placeholder') }}" @disabled($isDayClosed)>
                    @error('scan_value')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
                <button type="button" id="quick-attendance-submit-scan" wire:click="scanStudent" class="pill-link pill-link--accent w-full justify-center" @disabled($isDayClosed)>
                    {{ __('workflow.student_attendance.quick.apply_scan') }}
                </button>
            </div>
        </div>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div>
            <label for="quick-attendance-search" class="mb-1 block text-sm font-medium">{{ __('crud.common.filters.search') }}</label>
            <input id="quick-attendance-search" wire:model.live.debounce.250ms="search" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('workflow.student_attendance.quick.search_placeholder') }}">
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.quick.list_title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($enrollments->count())]) }}</div>
            </div>
        </div>

        @if ($enrollments->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_attendance.quick.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('workflow.student_attendance.table.headers.student') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('student'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('group')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('workflow.student_attendance.day_details.table.headers.group') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('group'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('attendance')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('workflow.student_attendance.table.headers.attendance') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('attendance'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.student_attendance.day_details.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($enrollments as $enrollment)
                            @php($record = $recordsByEnrollment->get($enrollment->id))
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline">
                                        <x-student-avatar :student="$enrollment->student" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $enrollment->student?->full_name }}</div>
                                            <div class="text-xs text-neutral-500">{{ $enrollment->student?->student_number ?: $enrollment->student_id }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->group?->name }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($record?->status)
                                        <span class="status-chip status-chip--emerald">{{ $record->status->name }}</span>
                                    @else
                                        <span class="status-chip status-chip--slate">{{ __('workflow.student_attendance.table.not_marked') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex justify-end">
                                        <button type="button" wire:click="markEnrollment({{ $enrollment->id }})" class="pill-link pill-link--compact" @disabled($isDayClosed || ! auth()->user()->can('attendance.student.take'))>
                                            {{ __('workflow.student_attendance.quick.mark_action') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

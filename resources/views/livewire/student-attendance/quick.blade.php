<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Services\BarcodeActions\BarcodeActionCatalogService;
use App\Services\StudentAttendanceDayService;
use Illuminate\Database\Eloquent\Builder;
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

    public string $manual_enrollment_id = '';

    public function mount(StudentAttendanceDay $studentAttendanceDay): void
    {
        $this->authorizePermission('attendance.student.view');

        $this->currentDay = StudentAttendanceDay::query()
            ->with(['course', 'groupAttendanceDays.group'])
            ->whereNull('course_finished_at')
            ->findOrFail($studentAttendanceDay->id);

        $this->authorizeScopedStudentAttendanceDayAccess($this->currentDay);
        $this->currentDay = app(StudentAttendanceDayService::class)->fillMissingStatuses(
            $this->currentDay,
            null,
            auth()->user(),
        );
        $this->selected_status_id = (string) ($this->defaultStudentAttendanceStatusId() ?? '');
    }

    public function with(): array
    {
        $day = $this->currentDay->fresh(['course', 'groupAttendanceDays.group']);
        $records = $this->scopeStudentAttendanceRecordsQuery(StudentAttendanceRecord::query())
            ->with(['status', 'enrollment.student', 'enrollment.group'])
            ->whereIn('group_attendance_day_id', $day->groupAttendanceDays->pluck('id'))
            ->whereNotNull('quick_attendance_added_at')
            ->orderByDesc('quick_attendance_added_at')
            ->orderByDesc('id')
            ->get();
        $enrollments = $this->activeCourseEnrollments()
            ->with(['group', 'student'])
            ->get()
            ->sortBy(fn (Enrollment $enrollment) => $enrollment->student?->full_name)
            ->values();

        return [
            'dayRecord' => $day,
            'enrollments' => $enrollments,
            'isDayClosed' => $day->status === 'closed' || $day->course_finished_at !== null,
            'addedRecords' => $records,
            'statuses' => AttendanceStatus::query()
                ->where('is_active', true)
                ->where('is_present', true)
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

        $enrollment = $this->activeCourseEnrollments()
            ->with(['student', 'group'])
            ->findOrFail($enrollmentId);

        $this->authorizeScopedEnrollmentAccess($enrollment);

        $status = AttendanceStatus::query()
            ->whereKey((int) $this->selected_status_id)
            ->where('is_active', true)
            ->where('is_present', true)
            ->whereIn('scope', ['student', 'both'])
            ->first();

        if (! $status) {
            $this->addError('selected_status_id', __('workflow.student_attendance.quick.errors.select_status_required'));
            $this->setScanFeedback(__('workflow.student_attendance.quick.errors.select_status_required'), 'error');

            return false;
        }

        try {
            app(StudentAttendanceDayService::class)->recordQuickEnrollmentStatus($this->currentDay, $enrollment, $status);
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

        $enrollments = $this->activeCourseEnrollments()
            ->with(['student', 'group'])
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

    public function addManualStudent(): void
    {
        $this->authorizePermission('attendance.student.take');
        $this->validate([
            'manual_enrollment_id' => ['required', 'integer'],
        ], ['manual_enrollment_id.required' => __('workflow.student_attendance.quick.errors.select_student_required')]);

        if ($this->markEnrollment((int) $this->manual_enrollment_id)) {
            $this->reset('manual_enrollment_id');
            $this->resetErrorBag('manual_enrollment_id');
        }
    }

    public function removeEnrollment(int $enrollmentId): void
    {
        $this->authorizePermission('attendance.student.take');
        $enrollment = $this->scopeEnrollmentsQuery(Enrollment::query())
            ->whereHas('group', fn (Builder $query) => $query->where('course_id', $this->currentDay->course_id))
            ->findOrFail($enrollmentId);
        $this->authorizeScopedEnrollmentAccess($enrollment);

        try {
            app(StudentAttendanceDayService::class)->undoQuickEnrollmentStatus($this->currentDay, $enrollment);
        } catch (InvalidArgumentException $exception) {
            $this->addError('scan_value', $exception->getMessage());
            $this->setScanFeedback($exception->getMessage(), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->setScanFeedback(__('workflow.student_attendance.quick.messages.undone', ['student' => $enrollment->student?->full_name]), 'success');
    }

    protected function activeCourseEnrollments(): Builder
    {
        return $this->scopeEnrollmentsQuery(Enrollment::query())
            ->where('status', 'active')
            ->whereHas('group', fn (Builder $query) => $query->where('course_id', $this->currentDay->course_id));
    }

    protected function setScanFeedback(string $message, string $type = 'info'): void
    {
        $this->scan_feedback = $message;
        $this->scan_feedback_type = in_array($type, ['success', 'error', 'info'], true) ? $type : 'info';
    }

    protected function defaultStudentAttendanceStatusId(): ?int
    {
        return AttendanceStatus::query()
            ->where('code', 'present')
            ->where('is_active', true)
            ->where('is_present', true)
            ->whereIn('scope', ['student', 'both'])
            ->value('id') ?? AttendanceStatus::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->where('is_present', true)
            ->whereIn('scope', ['student', 'both'])
            ->value('id') ?? AttendanceStatus::query()
            ->where('is_active', true)
            ->where('is_present', true)
            ->whereIn('scope', ['student', 'both'])
            ->orderByDesc('is_present')
            ->orderBy('name')
            ->value('id');
    }
}; ?>

<div class="page-stack attendance-scan-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
            <div>
                <x-back-link :href="route('attendance.show', ['type' => 'students', 'day' => $dayRecord->id])" navigate />
                <div class="eyebrow mt-4">{{ __('ui.nav.student_attendance') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_attendance.quick.title') }}</h1>
            </div>
            <div class="shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner" data-quick-attendance-day-date-metric>
                <div class="text-xs text-neutral-300">{{ __('workflow.student_attendance.form.attendance_date') }}</div>
                <bdi dir="ltr" class="mt-1 block text-lg font-semibold text-emerald-100">{{ \App\Support\DateDisplay::html($dayRecord->attendance_date?->format('d-m-Y') ?: __('workflow.common.not_available')) }}</bdi>
            </div>
        </div>
    </section>

    @if ($isDayClosed)
        <div class="soft-callout p-4 text-sm text-amber-100">
            {{ __('workflow.student_attendance.messages.closed_day_locked') }}
        </div>
    @endif

    <section
        class="surface-panel attendance-scanner"
        id="quick-attendance-scanner"
        data-quick-attendance-scanner
        data-camera-idle=""
        data-camera-running="{{ __('workflow.student_attendance.quick.camera_running') }}"
        data-camera-detected="{{ __('workflow.student_attendance.quick.camera_detected') }}"
        data-camera-not-supported="{{ __('workflow.student_attendance.quick.camera_not_supported') }}"
        data-camera-error="{{ __('workflow.student_attendance.quick.camera_error') }}"
    >
        <div class="attendance-scanner__status">
            <label for="quick-attendance-status">{{ __('workflow.student_attendance.quick.status') }}</label>
            <select id="quick-attendance-status" wire:model="selected_status_id" data-search-input="false" data-dropdown-search="false" class="attendance-text-menu" @disabled($isDayClosed)>
                @foreach ($statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </select>
        </div>
        @error('selected_status_id')
            <div class="text-sm text-red-400">{{ $message }}</div>
        @enderror

        <div class="attendance-scanner__camera" data-camera-state="idle" data-quick-attendance-camera wire:ignore>
            <video data-quick-attendance-video autoplay muted playsinline webkit-playsinline></video>
            <div class="attendance-scanner__frame" aria-hidden="true">
                <span class="attendance-scanner__corner attendance-scanner__corner--top-left"></span>
                <span class="attendance-scanner__corner attendance-scanner__corner--top-right"></span>
                <span class="attendance-scanner__corner attendance-scanner__corner--bottom-left"></span>
                <span class="attendance-scanner__corner attendance-scanner__corner--bottom-right"></span>
            </div>
            <div class="attendance-scanner__camera-actions">
                <button type="button" class="attendance-scanner__camera-button attendance-scanner__camera-button--start" data-quick-attendance-start title="{{ __('workflow.student_attendance.quick.start_camera') }}" aria-label="{{ __('workflow.student_attendance.quick.start_camera') }}" @disabled($isDayClosed)>
                    <x-admin-action-icon name="camera" />
                </button>
                <button type="button" class="attendance-scanner__camera-button attendance-scanner__camera-button--stop" data-quick-attendance-stop title="{{ __('workflow.student_attendance.quick.stop_camera') }}" aria-label="{{ __('workflow.student_attendance.quick.stop_camera') }}">
                    <x-admin-action-icon name="camera-off" />
                </button>
            </div>
        </div>

        <p class="attendance-scanner__feedback" data-feedback-type="{{ $scan_feedback_type }}" data-quick-attendance-message role="status" aria-live="polite" aria-atomic="true">{{ $scan_feedback }}</p>

        <details wire:ignore.self class="attendance-scanner__manual" @if ($errors->has('manual_enrollment_id')) open @endif>
            <summary>{{ __('workflow.student_attendance.quick.manual_entry') }}</summary>
            <div class="attendance-scanner__manual-field">
                <label for="quick-attendance-student" class="sr-only">{{ __('workflow.student_attendance.quick.select_student') }}</label>
                <select id="quick-attendance-student" wire:model="manual_enrollment_id" data-search-input="true" @disabled($isDayClosed)>
                    <option value="">{{ __('workflow.student_attendance.quick.select_student') }}</option>
                    @foreach ($enrollments as $enrollment)
                        <option value="{{ $enrollment->id }}">{{ $enrollment->student?->full_name }} — {{ $enrollment->group?->name }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="addManualStudent" wire:loading.attr="disabled" class="admin-icon-button admin-icon-button--accent" title="{{ __('workflow.student_attendance.quick.mark_action') }}" aria-label="{{ __('workflow.student_attendance.quick.mark_action') }}" @disabled($isDayClosed || ! auth()->user()->can('attendance.student.take'))>
                    <x-admin-action-icon name="add" />
                </button>
            </div>
            @error('manual_enrollment_id')
                <div class="mt-2 text-sm text-red-400">{{ $message }}</div>
            @enderror
        </details>
    </section>

    <section class="surface-table attendance-scan-list">
        <div class="admin-grid-meta">
            <div class="admin-grid-meta__title">{{ __('workflow.student_attendance.quick.list_title') }}</div>
            <div class="admin-grid-meta__summary">{{ trans_choice('workflow.student_attendance.table.present_students', $addedRecords->count(), ['count' => \Illuminate\Support\Number::format($addedRecords->count(), locale: app()->getLocale() === 'ar' ? 'ar-u-nu-arab' : app()->getLocale())]) }}</div>
        </div>
        <div class="attendance-scan-list__table-wrap">
            <table class="attendance-scan-list__table">
                <colgroup>
                    <col class="attendance-scan-list__student-column">
                    <col class="attendance-scan-list__status-column">
                    <col class="attendance-scan-list__action-column">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">{{ __('workflow.student_attendance.table.headers.student') }}</th>
                        <th scope="col">{{ __('workflow.student_attendance.table.headers.attendance') }}</th>
                        <th scope="col" class="admin-actions-column attendance-scan-list__action-cell text-center">{{ __('crud.common.actions.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($addedRecords as $record)
                        <tr wire:key="quick-attendance-record-{{ $record->id }}" data-quick-attendance-record="{{ $record->enrollment_id }}">
                            <td>
                                <div class="attendance-scan-list__identity">
                                    <div>{{ $record->enrollment?->student?->full_name }}</div>
                                    <div class="attendance-scan-list__group">{{ $record->enrollment?->group?->name }}</div>
                                </div>
                            </td>
                            <td><span class="status-chip status-chip--emerald">{{ $record->status?->name ?: '—' }}</span></td>
                            <td class="attendance-scan-list__action-cell">
                                <button type="button" wire:click="removeEnrollment({{ $record->enrollment_id }})" wire:loading.attr="disabled" class="admin-icon-button admin-icon-button--danger" title="{{ __('workflow.student_attendance.quick.undo_attendance') }}" aria-label="{{ __('workflow.student_attendance.quick.undo_attendance') }}: {{ $record->enrollment?->student?->full_name }}" @disabled($isDayClosed || ! auth()->user()->can('attendance.student.take'))>
                                    <x-admin-action-icon name="minus" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="admin-empty-state">{{ __('workflow.student_attendance.quick.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

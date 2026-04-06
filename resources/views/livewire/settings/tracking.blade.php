<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Assessment;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\StudentAttendanceRecord;
use App\Models\TeacherAttendanceRecord;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $attendance_status_editing_id = null;
    public string $attendance_status_name = '';
    public string $attendance_status_code = '';
    public string $attendance_status_scope = 'both';
    public string $attendance_status_default_points = '0';
    public string $attendance_status_color = '';
    public bool $attendance_status_is_present = false;
    public bool $attendance_status_is_active = true;

    public ?int $assessment_type_editing_id = null;
    public string $assessment_type_name = '';
    public string $assessment_type_code = '';
    public bool $assessment_type_is_scored = true;
    public bool $assessment_type_is_active = true;

    public ?int $quran_test_type_editing_id = null;
    public string $quran_test_type_name = '';
    public string $quran_test_type_code = '';
    public string $quran_test_type_sort_order = '0';
    public bool $quran_test_type_is_active = true;

    public function mount(): void
    {
        $this->authorizePermission('settings.manage');
    }

    public function attendanceStatusRules(): array
    {
        return [
            'attendance_status_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('attendance_statuses', 'code')->ignore($this->attendance_status_editing_id),
            ],
            'attendance_status_color' => ['nullable', 'string', 'max:32'],
            'attendance_status_default_points' => ['required', 'integer'],
            'attendance_status_is_active' => ['boolean'],
            'attendance_status_is_present' => ['boolean'],
            'attendance_status_name' => ['required', 'string', 'max:255'],
            'attendance_status_scope' => ['required', Rule::in(['student', 'teacher', 'both'])],
        ];
    }

    public function assessmentTypeRules(): array
    {
        return [
            'assessment_type_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('assessment_types', 'code')->ignore($this->assessment_type_editing_id),
            ],
            'assessment_type_is_active' => ['boolean'],
            'assessment_type_is_scored' => ['boolean'],
            'assessment_type_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function deleteAssessmentType(int $assessmentTypeId): void
    {
        $this->authorizePermission('settings.manage');

        $assessmentType = AssessmentType::query()->findOrFail($assessmentTypeId);

        if (Assessment::query()->where('assessment_type_id', $assessmentType->id)->exists() || AssessmentScoreBand::query()->where('assessment_type_id', $assessmentType->id)->exists()) {
            $this->addError('assessmentTypeDelete', __('settings.tracking.errors.assessment_type_delete_linked'));

            return;
        }

        $assessmentType->delete();

        if ($this->assessment_type_editing_id === $assessmentTypeId) {
            $this->cancelAssessmentType();
        }

        session()->flash('status', __('settings.tracking.messages.assessment_type_deleted'));
    }

    public function deleteAttendanceStatus(int $attendanceStatusId): void
    {
        $this->authorizePermission('settings.manage');

        $attendanceStatus = AttendanceStatus::query()->findOrFail($attendanceStatusId);

        if (StudentAttendanceRecord::query()->where('attendance_status_id', $attendanceStatus->id)->exists() || TeacherAttendanceRecord::query()->where('attendance_status_id', $attendanceStatus->id)->exists()) {
            $this->addError('attendanceStatusDelete', __('settings.tracking.errors.attendance_status_delete_linked'));

            return;
        }

        $attendanceStatus->delete();

        if ($this->attendance_status_editing_id === $attendanceStatusId) {
            $this->cancelAttendanceStatus();
        }

        session()->flash('status', __('settings.tracking.messages.attendance_status_deleted'));
    }

    public function deleteQuranTestType(int $quranTestTypeId): void
    {
        $this->authorizePermission('settings.manage');

        $quranTestType = QuranTestType::query()->findOrFail($quranTestTypeId);

        if (QuranTest::query()->where('quran_test_type_id', $quranTestType->id)->exists()) {
            $this->addError('quranTestTypeDelete', __('settings.tracking.errors.quran_test_type_delete_linked'));

            return;
        }

        $quranTestType->delete();

        if ($this->quran_test_type_editing_id === $quranTestTypeId) {
            $this->cancelQuranTestType();
        }

        session()->flash('status', __('settings.tracking.messages.quran_test_type_deleted'));
    }

    public function editAssessmentType(int $assessmentTypeId): void
    {
        $this->authorizePermission('settings.manage');

        $assessmentType = AssessmentType::query()->findOrFail($assessmentTypeId);

        $this->assessment_type_editing_id = $assessmentType->id;
        $this->assessment_type_name = $assessmentType->name;
        $this->assessment_type_code = $assessmentType->code;
        $this->assessment_type_is_scored = $assessmentType->is_scored;
        $this->assessment_type_is_active = $assessmentType->is_active;

        $this->resetValidation();
    }

    public function editAttendanceStatus(int $attendanceStatusId): void
    {
        $this->authorizePermission('settings.manage');

        $attendanceStatus = AttendanceStatus::query()->findOrFail($attendanceStatusId);

        $this->attendance_status_editing_id = $attendanceStatus->id;
        $this->attendance_status_name = $attendanceStatus->name;
        $this->attendance_status_code = $attendanceStatus->code;
        $this->attendance_status_scope = $attendanceStatus->scope;
        $this->attendance_status_default_points = (string) $attendanceStatus->default_points;
        $this->attendance_status_color = $attendanceStatus->color ?? '';
        $this->attendance_status_is_present = $attendanceStatus->is_present;
        $this->attendance_status_is_active = $attendanceStatus->is_active;

        $this->resetValidation();
    }

    public function editQuranTestType(int $quranTestTypeId): void
    {
        $this->authorizePermission('settings.manage');

        $quranTestType = QuranTestType::query()->findOrFail($quranTestTypeId);

        $this->quran_test_type_editing_id = $quranTestType->id;
        $this->quran_test_type_name = $quranTestType->name;
        $this->quran_test_type_code = $quranTestType->code;
        $this->quran_test_type_sort_order = (string) $quranTestType->sort_order;
        $this->quran_test_type_is_active = $quranTestType->is_active;

        $this->resetValidation();
    }

    public function quranTestTypeRules(): array
    {
        return [
            'quran_test_type_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('quran_test_types', 'code')->ignore($this->quran_test_type_editing_id),
            ],
            'quran_test_type_is_active' => ['boolean'],
            'quran_test_type_name' => ['required', 'string', 'max:255'],
            'quran_test_type_sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function saveAssessmentType(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->assessmentTypeRules());

        AssessmentType::query()->updateOrCreate(
            ['id' => $this->assessment_type_editing_id],
            [
                'code' => $validated['assessment_type_code'],
                'is_active' => $validated['assessment_type_is_active'],
                'is_scored' => $validated['assessment_type_is_scored'],
                'name' => $validated['assessment_type_name'],
            ],
        );

        session()->flash(
            'status',
            $this->assessment_type_editing_id
                ? __('settings.tracking.messages.assessment_type_updated')
                : __('settings.tracking.messages.assessment_type_created'),
        );
        $this->cancelAssessmentType();
    }

    public function saveAttendanceStatus(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->attendanceStatusRules());

        AttendanceStatus::query()->updateOrCreate(
            ['id' => $this->attendance_status_editing_id],
            [
                'code' => $validated['attendance_status_code'],
                'color' => blank($validated['attendance_status_color']) ? null : $validated['attendance_status_color'],
                'default_points' => (int) $validated['attendance_status_default_points'],
                'is_active' => $validated['attendance_status_is_active'],
                'is_present' => $validated['attendance_status_is_present'],
                'name' => $validated['attendance_status_name'],
                'scope' => $validated['attendance_status_scope'],
            ],
        );

        session()->flash(
            'status',
            $this->attendance_status_editing_id
                ? __('settings.tracking.messages.attendance_status_updated')
                : __('settings.tracking.messages.attendance_status_created'),
        );
        $this->cancelAttendanceStatus();
    }

    public function saveQuranTestType(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->quranTestTypeRules());

        QuranTestType::query()->updateOrCreate(
            ['id' => $this->quran_test_type_editing_id],
            [
                'code' => $validated['quran_test_type_code'],
                'is_active' => $validated['quran_test_type_is_active'],
                'name' => $validated['quran_test_type_name'],
                'sort_order' => (int) $validated['quran_test_type_sort_order'],
            ],
        );

        session()->flash(
            'status',
            $this->quran_test_type_editing_id
                ? __('settings.tracking.messages.quran_test_type_updated')
                : __('settings.tracking.messages.quran_test_type_created'),
        );
        $this->cancelQuranTestType();
    }

    public function with(): array
    {
        return [
            'assessmentTypes' => AssessmentType::query()->withCount('assessments')->orderBy('name')->get(),
            'attendanceStatuses' => AttendanceStatus::query()->orderBy('scope')->orderBy('name')->get(),
            'quranTestTypes' => QuranTestType::query()->orderBy('sort_order')->orderBy('name')->get(),
            'totals' => [
                'assessment_types' => AssessmentType::count(),
                'attendance_statuses' => AttendanceStatus::count(),
                'quran_test_types' => QuranTestType::count(),
            ],
        ];
    }

    protected function cancelAssessmentType(): void
    {
        $this->assessment_type_editing_id = null;
        $this->assessment_type_name = '';
        $this->assessment_type_code = '';
        $this->assessment_type_is_scored = true;
        $this->assessment_type_is_active = true;
        $this->resetValidation();
    }

    protected function cancelAttendanceStatus(): void
    {
        $this->attendance_status_editing_id = null;
        $this->attendance_status_name = '';
        $this->attendance_status_code = '';
        $this->attendance_status_scope = 'both';
        $this->attendance_status_default_points = '0';
        $this->attendance_status_color = '';
        $this->attendance_status_is_present = false;
        $this->attendance_status_is_active = true;
        $this->resetValidation();
    }

    protected function cancelQuranTestType(): void
    {
        $this->quran_test_type_editing_id = null;
        $this->quran_test_type_name = '';
        $this->quran_test_type_code = '';
        $this->quran_test_type_sort_order = '0';
        $this->quran_test_type_is_active = true;
        $this->resetValidation();
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div>
        <flux:heading size="xl">{{ __('settings.tracking.title') }}</flux:heading>
        <flux:subheading>{{ __('settings.tracking.subtitle') }}</flux:subheading>
    </div>

    <x-settings.admin-nav />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.tracking.stats.attendance_statuses') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['attendance_statuses']) }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.tracking.stats.assessment_types') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['assessment_types']) }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.tracking.stats.quran_test_types') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['quran_test_types']) }}</div></div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $attendance_status_editing_id ? __('settings.tracking.sections.attendance_status.edit') : __('settings.tracking.sections.attendance_status.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.tracking.sections.attendance_status.copy') }}</p></div>
                <form wire:submit="saveAttendanceStatus" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.name') }}</label><input wire:model="attendance_status_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('attendance_status_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.code') }}</label><input wire:model="attendance_status_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('attendance_status_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.scope') }}</label><select wire:model="attendance_status_scope" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="both">{{ __('settings.tracking.scopes.both') }}</option><option value="student">{{ __('settings.tracking.scopes.student') }}</option><option value="teacher">{{ __('settings.tracking.scopes.teacher') }}</option></select>@error('attendance_status_scope') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.default_points') }}</label><input wire:model="attendance_status_default_points" type="number" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('attendance_status_default_points') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.color') }}</label><input wire:model="attendance_status_color" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('attendance_status_color') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="attendance_status_is_present" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.tracking.fields.counts_as_present') }}</span></label>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="attendance_status_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.tracking.fields.is_active') }}</span></label>
                    @error('attendanceStatusDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $attendance_status_editing_id ? __('settings.tracking.actions.update_status') : __('settings.tracking.actions.create_status') }}</button>@if ($attendance_status_editing_id)<button type="button" wire:click="cancelAttendanceStatus" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $assessment_type_editing_id ? __('settings.tracking.sections.assessment_type.edit') : __('settings.tracking.sections.assessment_type.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.tracking.sections.assessment_type.copy') }}</p></div>
                <form wire:submit="saveAssessmentType" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.name') }}</label><input wire:model="assessment_type_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('assessment_type_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.code') }}</label><input wire:model="assessment_type_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('assessment_type_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="assessment_type_is_scored" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.tracking.fields.is_scored') }}</span></label>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="assessment_type_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.tracking.fields.is_active') }}</span></label>
                    @error('assessmentTypeDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $assessment_type_editing_id ? __('settings.tracking.actions.update_type') : __('settings.tracking.actions.create_type') }}</button>@if ($assessment_type_editing_id)<button type="button" wire:click="cancelAssessmentType" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $quran_test_type_editing_id ? __('settings.tracking.sections.quran_test_type.edit') : __('settings.tracking.sections.quran_test_type.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.tracking.sections.quran_test_type.copy') }}</p></div>
                <form wire:submit="saveQuranTestType" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.name') }}</label><input wire:model="quran_test_type_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('quran_test_type_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.code') }}</label><input wire:model="quran_test_type_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('quran_test_type_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.tracking.fields.sort_order') }}</label><input wire:model="quran_test_type_sort_order" type="number" min="0" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('quran_test_type_sort_order') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    </div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="quran_test_type_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.tracking.fields.is_active') }}</span></label>
                    @error('quranTestTypeDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $quran_test_type_editing_id ? __('settings.tracking.actions.update_type') : __('settings.tracking.actions.create_type') }}</button>@if ($quran_test_type_editing_id)<button type="button" wire:click="cancelQuranTestType" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>
        </section>

        <section class="space-y-6">
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">{{ __('settings.tracking.sections.attendance_status.table') }}</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.scope') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.default_points') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.tracking.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($attendanceStatuses as $attendanceStatus)
                                <tr>
                                    <td class="px-5 py-3"><div class="font-medium">{{ $attendanceStatus->name }}</div><div class="text-xs text-neutral-500">{{ $attendanceStatus->is_present ? __('settings.tracking.labels.present_status') : __('settings.tracking.labels.non_present_status') }}</div></td>
                                    <td class="px-5 py-3">{{ $attendanceStatus->code }}</td>
                                    <td class="px-5 py-3">{{ __('settings.tracking.scopes.'.$attendanceStatus->scope) }}</td>
                                    <td class="px-5 py-3">{{ $attendanceStatus->default_points }}</td>
                                    <td class="px-5 py-3">{{ $attendanceStatus->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editAttendanceStatus({{ $attendanceStatus->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deleteAttendanceStatus({{ $attendanceStatus->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">{{ __('settings.tracking.sections.assessment_type.table') }}</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.type') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.assessments') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.tracking.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($assessmentTypes as $assessmentType)
                                <tr>
                                    <td class="px-5 py-3"><div class="font-medium">{{ $assessmentType->name }}</div><div class="text-xs text-neutral-500">{{ $assessmentType->is_scored ? __('settings.tracking.labels.scored') : __('settings.tracking.labels.unscored') }}</div></td>
                                    <td class="px-5 py-3">{{ $assessmentType->code }}</td>
                                    <td class="px-5 py-3">{{ $assessmentType->assessments_count }}</td>
                                    <td class="px-5 py-3">{{ $assessmentType->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editAssessmentType({{ $assessmentType->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deleteAssessmentType({{ $assessmentType->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">{{ __('settings.tracking.sections.quran_test_type.table') }}</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.type') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.sort') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.tracking.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.tracking.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($quranTestTypes as $quranTestType)
                                <tr>
                                    <td class="px-5 py-3 font-medium">{{ $quranTestType->name }}</td>
                                    <td class="px-5 py-3">{{ $quranTestType->code }}</td>
                                    <td class="px-5 py-3">{{ $quranTestType->sort_order }}</td>
                                    <td class="px-5 py-3">{{ $quranTestType->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editQuranTestType({{ $quranTestType->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deleteQuranTestType({{ $quranTestType->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

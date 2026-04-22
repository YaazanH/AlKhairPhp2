<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\GradeLevel;
use App\Models\StudentGender;
use App\Models\TeacherJobTitle;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use SupportsCreateAndNew;

    public string $school_name = '';
    public string $school_phone = '';
    public string $school_email = '';
    public string $email_domain = '';
    public string $school_address = '';
    public string $school_timezone = '';
    public string $school_currency = '';
    public bool $showOrganizationModal = false;

    public ?int $academic_year_editing_id = null;
    public string $academic_year_name = '';
    public string $academic_year_starts_on = '';
    public string $academic_year_ends_on = '';
    public bool $academic_year_is_current = false;
    public bool $academic_year_is_active = true;
    public bool $showAcademicYearModal = false;

    public ?int $grade_level_editing_id = null;
    public string $grade_level_name = '';
    public string $grade_level_sort_order = '0';
    public bool $grade_level_is_active = true;
    public bool $showGradeLevelModal = false;

    public ?int $teacher_job_title_editing_id = null;
    public string $teacher_job_title_name = '';
    public string $teacher_job_title_sort_order = '0';
    public bool $teacher_job_title_is_active = true;
    public bool $showTeacherJobTitleModal = false;

    public ?int $student_gender_editing_id = null;
    public string $student_gender_code = '';
    public string $student_gender_name = '';
    public string $student_gender_sort_order = '0';
    public bool $student_gender_is_active = true;
    public bool $student_gender_is_default = false;
    public bool $showStudentGenderModal = false;

    public function mount(): void
    {
        $this->authorizePermission('settings.manage');
        $this->loadOrganizationSettings();
    }

    public function with(): array
    {
        return [
            'academicYears' => AcademicYear::query()
                ->withCount('groups')
                ->orderByDesc('is_current')
                ->orderByDesc('starts_on')
                ->get(),
            'gradeLevels' => GradeLevel::query()
                ->withCount(['groups', 'students', 'pointPolicies'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'teacherJobTitles' => TeacherJobTitle::query()
                ->withCount('teachers')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'studentGenders' => StudentGender::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'totals' => [
                'academic_years' => AcademicYear::count(),
                'active_grade_levels' => GradeLevel::query()->where('is_active', true)->count(),
                'grade_levels' => GradeLevel::count(),
                'teacher_job_titles' => TeacherJobTitle::count(),
                'student_genders' => StudentGender::count(),
            ],
        ];
    }

    public function academicYearRules(): array
    {
        return [
            'academic_year_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('academic_years', 'name')->ignore($this->academic_year_editing_id),
            ],
            'academic_year_starts_on' => ['required', 'date'],
            'academic_year_ends_on' => ['required', 'date', 'after_or_equal:academic_year_starts_on'],
            'academic_year_is_current' => ['boolean'],
            'academic_year_is_active' => ['boolean'],
        ];
    }

    public function deleteAcademicYear(int $academicYearId): void
    {
        $this->authorizePermission('settings.manage');

        $academicYear = AcademicYear::query()->withCount('groups')->findOrFail($academicYearId);

        if ($academicYear->groups_count > 0) {
            $this->addError('academicYearDelete', __('settings.organization.errors.academic_year_delete_linked'));

            return;
        }

        $academicYear->delete();

        if ($this->academic_year_editing_id === $academicYearId) {
            $this->cancelAcademicYear();
        }

        session()->flash('status', __('settings.organization.messages.academic_year_deleted'));
    }

    public function deleteGradeLevel(int $gradeLevelId): void
    {
        $this->authorizePermission('settings.manage');

        $gradeLevel = GradeLevel::query()
            ->withCount(['groups', 'students', 'pointPolicies'])
            ->findOrFail($gradeLevelId);

        if ($gradeLevel->groups_count > 0 || $gradeLevel->students_count > 0 || $gradeLevel->point_policies_count > 0) {
            $this->addError('gradeLevelDelete', __('settings.organization.errors.grade_level_delete_linked'));

            return;
        }

        $gradeLevel->delete();

        if ($this->grade_level_editing_id === $gradeLevelId) {
            $this->cancelGradeLevel();
        }

        session()->flash('status', __('settings.organization.messages.grade_level_deleted'));
    }

    public function deleteTeacherJobTitle(int $teacherJobTitleId): void
    {
        $this->authorizePermission('settings.manage');

        $teacherJobTitle = TeacherJobTitle::query()
            ->withCount('teachers')
            ->findOrFail($teacherJobTitleId);

        if ($teacherJobTitle->teachers_count > 0) {
            $this->addError('teacherJobTitleDelete', __('settings.organization.errors.teacher_job_title_delete_linked'));

            return;
        }

        $teacherJobTitle->delete();

        if ($this->teacher_job_title_editing_id === $teacherJobTitleId) {
            $this->cancelTeacherJobTitle();
        }

        session()->flash('status', __('settings.organization.messages.teacher_job_title_deleted'));
    }

    public function deleteStudentGender(int $studentGenderId): void
    {
        $this->authorizePermission('settings.manage');

        $studentGender = StudentGender::query()->findOrFail($studentGenderId);

        if (\App\Models\Student::query()->where('gender', $studentGender->code)->exists()) {
            $this->addError('studentGenderDelete', __('settings.organization.errors.student_gender_delete_linked'));

            return;
        }

        $studentGender->delete();
        $this->ensureDefaultStudentGender();

        if ($this->student_gender_editing_id === $studentGenderId) {
            $this->cancelStudentGender();
        }

        session()->flash('status', __('settings.organization.messages.student_gender_deleted'));
    }

    public function openOrganizationModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->showOrganizationModal = true;
        $this->resetValidation();
    }

    public function closeOrganizationModal(): void
    {
        $this->showOrganizationModal = false;
        $this->resetValidation();
    }

    public function openAcademicYearModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelAcademicYear();
        $this->showAcademicYearModal = true;
    }

    public function closeAcademicYearModal(): void
    {
        $this->cancelAcademicYear();
    }

    public function openGradeLevelModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelGradeLevel();
        $this->showGradeLevelModal = true;
    }

    public function closeGradeLevelModal(): void
    {
        $this->cancelGradeLevel();
    }

    public function openTeacherJobTitleModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelTeacherJobTitle();
        $this->showTeacherJobTitleModal = true;
    }

    public function closeTeacherJobTitleModal(): void
    {
        $this->cancelTeacherJobTitle();
    }

    public function openStudentGenderModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelStudentGender();
        $this->showStudentGenderModal = true;
    }

    public function closeStudentGenderModal(): void
    {
        $this->cancelStudentGender();
    }

    public function editAcademicYear(int $academicYearId): void
    {
        $this->authorizePermission('settings.manage');

        $academicYear = AcademicYear::query()->findOrFail($academicYearId);

        $this->academic_year_editing_id = $academicYear->id;
        $this->academic_year_name = $academicYear->name;
        $this->academic_year_starts_on = $academicYear->starts_on?->format('Y-m-d') ?? '';
        $this->academic_year_ends_on = $academicYear->ends_on?->format('Y-m-d') ?? '';
        $this->academic_year_is_current = $academicYear->is_current;
        $this->academic_year_is_active = $academicYear->is_active;
        $this->showAcademicYearModal = true;

        $this->resetValidation();
    }

    public function editGradeLevel(int $gradeLevelId): void
    {
        $this->authorizePermission('settings.manage');

        $gradeLevel = GradeLevel::query()->findOrFail($gradeLevelId);

        $this->grade_level_editing_id = $gradeLevel->id;
        $this->grade_level_name = $gradeLevel->name;
        $this->grade_level_sort_order = (string) $gradeLevel->sort_order;
        $this->grade_level_is_active = $gradeLevel->is_active;
        $this->showGradeLevelModal = true;

        $this->resetValidation();
    }

    public function editTeacherJobTitle(int $teacherJobTitleId): void
    {
        $this->authorizePermission('settings.manage');

        $teacherJobTitle = TeacherJobTitle::query()->findOrFail($teacherJobTitleId);

        $this->teacher_job_title_editing_id = $teacherJobTitle->id;
        $this->teacher_job_title_name = $teacherJobTitle->name;
        $this->teacher_job_title_sort_order = (string) $teacherJobTitle->sort_order;
        $this->teacher_job_title_is_active = $teacherJobTitle->is_active;
        $this->showTeacherJobTitleModal = true;

        $this->resetValidation();
    }

    public function editStudentGender(int $studentGenderId): void
    {
        $this->authorizePermission('settings.manage');

        $studentGender = StudentGender::query()->findOrFail($studentGenderId);

        $this->student_gender_editing_id = $studentGender->id;
        $this->student_gender_code = $studentGender->code;
        $this->student_gender_name = $studentGender->name;
        $this->student_gender_sort_order = (string) $studentGender->sort_order;
        $this->student_gender_is_active = $studentGender->is_active;
        $this->student_gender_is_default = $studentGender->is_default;
        $this->showStudentGenderModal = true;

        $this->resetValidation();
    }

    public function gradeLevelRules(): array
    {
        return [
            'grade_level_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('grade_levels', 'name')->ignore($this->grade_level_editing_id),
            ],
            'grade_level_sort_order' => ['required', 'integer', 'min:0'],
            'grade_level_is_active' => ['boolean'],
        ];
    }

    public function teacherJobTitleRules(): array
    {
        return [
            'teacher_job_title_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teacher_job_titles', 'name')->ignore($this->teacher_job_title_editing_id),
            ],
            'teacher_job_title_sort_order' => ['required', 'integer', 'min:0'],
            'teacher_job_title_is_active' => ['boolean'],
        ];
    }

    public function studentGenderRules(): array
    {
        return [
            'student_gender_code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('student_genders', 'code')->ignore($this->student_gender_editing_id),
            ],
            'student_gender_name' => ['required', 'string', 'max:255'],
            'student_gender_sort_order' => ['required', 'integer', 'min:0'],
            'student_gender_is_active' => ['boolean'],
            'student_gender_is_default' => ['boolean'],
        ];
    }

    public function saveAcademicYear(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->academicYearRules());

        $academicYear = AcademicYear::query()->updateOrCreate(
            ['id' => $this->academic_year_editing_id],
            [
                'ends_on' => $validated['academic_year_ends_on'],
                'is_active' => $validated['academic_year_is_active'],
                'is_current' => $validated['academic_year_is_current'],
                'name' => $validated['academic_year_name'],
                'starts_on' => $validated['academic_year_starts_on'],
            ],
        );

        if ($academicYear->is_current) {
            AcademicYear::query()
                ->whereKeyNot($academicYear->id)
                ->update(['is_current' => false]);
        }

        session()->flash(
            'status',
            $this->academic_year_editing_id
                ? __('settings.organization.messages.academic_year_updated')
                : __('settings.organization.messages.academic_year_created'),
        );
        $this->cancelAcademicYear();
    }

    public function saveGradeLevel(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->gradeLevelRules());

        GradeLevel::query()->updateOrCreate(
            ['id' => $this->grade_level_editing_id],
            [
                'is_active' => $validated['grade_level_is_active'],
                'name' => $validated['grade_level_name'],
                'sort_order' => (int) $validated['grade_level_sort_order'],
            ],
        );

        session()->flash(
            'status',
            $this->grade_level_editing_id
                ? __('settings.organization.messages.grade_level_updated')
                : __('settings.organization.messages.grade_level_created'),
        );
        $this->cancelGradeLevel();
    }

    public function saveTeacherJobTitle(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->teacherJobTitleRules());

        TeacherJobTitle::query()->updateOrCreate(
            ['id' => $this->teacher_job_title_editing_id],
            [
                'is_active' => $validated['teacher_job_title_is_active'],
                'name' => $validated['teacher_job_title_name'],
                'sort_order' => (int) $validated['teacher_job_title_sort_order'],
            ],
        );

        session()->flash(
            'status',
            $this->teacher_job_title_editing_id
                ? __('settings.organization.messages.teacher_job_title_updated')
                : __('settings.organization.messages.teacher_job_title_created'),
        );
        $this->cancelTeacherJobTitle();
    }

    public function saveStudentGender(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->studentGenderRules());

        if ($validated['student_gender_is_default'] && ! $validated['student_gender_is_active']) {
            $this->addError('student_gender_is_default', __('settings.organization.errors.default_student_gender_requires_active'));

            return;
        }

        $studentGender = StudentGender::query()->updateOrCreate(
            ['id' => $this->student_gender_editing_id],
            [
                'code' => Str::of($validated['student_gender_code'])->lower()->toString(),
                'is_active' => $validated['student_gender_is_active'],
                'is_default' => $validated['student_gender_is_default'],
                'name' => $validated['student_gender_name'],
                'sort_order' => (int) $validated['student_gender_sort_order'],
            ],
        );

        if ($studentGender->is_default) {
            StudentGender::query()
                ->whereKeyNot($studentGender->id)
                ->update(['is_default' => false]);
        }

        $this->ensureDefaultStudentGender();

        session()->flash(
            'status',
            $this->student_gender_editing_id
                ? __('settings.organization.messages.student_gender_updated')
                : __('settings.organization.messages.student_gender_created'),
        );
        $this->cancelStudentGender();
    }

    public function saveOrganizationSettings(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate([
            'school_address' => ['nullable', 'string'],
            'school_currency' => ['required', 'string', 'max:10'],
            'school_email' => ['nullable', 'email', 'max:255'],
            'email_domain' => ['required', 'string', 'max:255', 'regex:/^(?!-)[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            'school_name' => ['required', 'string', 'max:255'],
            'school_phone' => ['nullable', 'string', 'max:50'],
            'school_timezone' => ['required', 'string', 'max:100'],
        ]);

        foreach ([
            'school_name' => ['group' => 'general', 'type' => 'string'],
            'school_phone' => ['group' => 'general', 'type' => 'string'],
            'school_email' => ['group' => 'general', 'type' => 'string'],
            'email_domain' => ['group' => 'general', 'type' => 'string'],
            'school_address' => ['group' => 'general', 'type' => 'string'],
            'school_timezone' => ['group' => 'general', 'type' => 'string'],
            'school_currency' => ['group' => 'general', 'type' => 'string'],
        ] as $key => $config) {
            AppSetting::query()->updateOrCreate(
                ['group' => $config['group'], 'key' => $key],
                ['type' => $config['type'], 'value' => blank($validated[$key]) ? null : $validated[$key]],
            );
        }

        session()->flash('status', __('settings.organization.messages.settings_saved'));
        $this->showOrganizationModal = false;
    }

    protected function cancelAcademicYear(): void
    {
        $this->academic_year_editing_id = null;
        $this->academic_year_name = '';
        $this->academic_year_starts_on = '';
        $this->academic_year_ends_on = '';
        $this->academic_year_is_current = false;
        $this->academic_year_is_active = true;
        $this->showAcademicYearModal = false;
        $this->resetValidation();
    }

    protected function cancelGradeLevel(): void
    {
        $this->grade_level_editing_id = null;
        $this->grade_level_name = '';
        $this->grade_level_sort_order = '0';
        $this->grade_level_is_active = true;
        $this->showGradeLevelModal = false;
        $this->resetValidation();
    }

    protected function cancelTeacherJobTitle(): void
    {
        $this->teacher_job_title_editing_id = null;
        $this->teacher_job_title_name = '';
        $this->teacher_job_title_sort_order = '0';
        $this->teacher_job_title_is_active = true;
        $this->showTeacherJobTitleModal = false;
        $this->resetValidation();
    }

    protected function cancelStudentGender(): void
    {
        $this->student_gender_editing_id = null;
        $this->student_gender_code = '';
        $this->student_gender_name = '';
        $this->student_gender_sort_order = '0';
        $this->student_gender_is_active = true;
        $this->student_gender_is_default = false;
        $this->showStudentGenderModal = false;
        $this->resetValidation();
    }

    protected function ensureDefaultStudentGender(): void
    {
        if (StudentGender::query()->where('is_active', true)->where('is_default', true)->exists()) {
            return;
        }

        $fallback = StudentGender::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        if (! $fallback) {
            return;
        }

        StudentGender::query()->whereKeyNot($fallback->id)->update(['is_default' => false]);
        $fallback->update(['is_default' => true]);
    }

    protected function loadOrganizationSettings(): void
    {
        $settings = AppSetting::query()
            ->where('group', 'general')
            ->whereIn('key', ['school_name', 'school_phone', 'school_email', 'email_domain', 'school_address', 'school_timezone', 'school_currency'])
            ->pluck('value', 'key');

        $this->school_name = (string) ($settings['school_name'] ?? 'Alkhair');
        $this->school_phone = (string) ($settings['school_phone'] ?? '');
        $this->school_email = (string) ($settings['school_email'] ?? '');
        $this->email_domain = (string) ($settings['email_domain'] ?? 'alkhair.local');
        $this->school_address = (string) ($settings['school_address'] ?? '');
        $this->school_timezone = (string) ($settings['school_timezone'] ?? config('app.timezone', 'UTC'));
        $this->school_currency = (string) ($settings['school_currency'] ?? 'USD');
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.organization.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.organization.subtitle') }}</p>
    </section>

    <x-settings.admin-nav />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-5">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.organization.stats.academic_years') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format($totals['academic_years']) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.organization.stats.grade_levels') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format($totals['grade_levels']) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.organization.stats.active_grade_levels') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format($totals['active_grade_levels']) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.organization.stats.teacher_job_titles') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format($totals['teacher_job_titles']) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.organization.stats.student_genders') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format($totals['student_genders']) }}</div>
        </div>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('settings.organization.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('settings.organization.subtitle') }}</p>
            </div>
            <div class="admin-toolbar__actions">
                <button type="button" wire:click="openOrganizationModal" class="pill-link">{{ __('settings.organization.actions.save_settings') }}</button>
            </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('settings.organization.fields.school_name') }}</div>
                <div class="mt-2 truncate text-sm font-semibold text-white">{{ $school_name ?: __('crud.common.not_available') }}</div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('settings.organization.fields.school_email') }}</div>
                <div class="mt-2 truncate text-sm font-semibold text-white">{{ $school_email ?: __('crud.common.not_available') }}</div>
            </div>
            <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200">{{ __('settings.organization.fields.email_domain') }}</div>
                <div class="mt-2 truncate font-mono text-sm font-semibold text-white">{{ $email_domain ?: 'alkhair.local' }}</div>
                <p class="mt-2 text-xs leading-5 text-emerald-100/80">{{ __('settings.organization.fields.email_domain_help') }}</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('settings.organization.fields.school_timezone') }}</div>
                <div class="mt-2 truncate text-sm font-semibold text-white">{{ $school_timezone ?: __('crud.common.not_available') }}</div>
            </div>
        </div>
    </section>

    <div class="space-y-6">
        <section class="hidden">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ __('settings.organization.sections.profile.title') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('settings.organization.sections.profile.copy') }}</p>
                </div>

                <form wire:submit="saveOrganizationSettings" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_name') }}</label>
                        <input wire:model="school_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('school_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_phone') }}</label>
                            <input wire:model="school_phone" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('school_phone') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_email') }}</label>
                            <input wire:model="school_email" type="email" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('school_email') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.email_domain') }}</label>
                            <input wire:model="email_domain" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm lowercase dark:border-neutral-700 dark:bg-neutral-900" placeholder="alkhair.org">
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.email_domain_help') }}</p>
                            @error('email_domain') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_timezone') }}</label>
                            <input wire:model="school_timezone" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('school_timezone') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_currency') }}</label>
                            <input wire:model="school_currency" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900">
                            @error('school_currency') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_address') }}</label>
                        <textarea wire:model="school_address" rows="3" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        @error('school_address') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ __('settings.organization.actions.save_settings') }}</button>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ $academic_year_editing_id ? __('settings.organization.sections.academic_year.edit') : __('settings.organization.sections.academic_year.create') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('settings.organization.sections.academic_year.copy') }}</p>
                </div>

                <form wire:submit="saveAcademicYear" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                        <input wire:model="academic_year_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('academic_year_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.starts_on') }}</label>
                            <input wire:model="academic_year_starts_on" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('academic_year_starts_on') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.ends_on') }}</label>
                            <input wire:model="academic_year_ends_on" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('academic_year_ends_on') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="academic_year_is_current" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('settings.organization.fields.current_academic_year') }}</span>
                    </label>
                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="academic_year_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('settings.organization.fields.is_active') }}</span>
                    </label>
                    @error('academicYearDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3">
                        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $academic_year_editing_id ? __('settings.organization.actions.update_year') : __('settings.organization.actions.create_year') }}</button>
                        @if ($academic_year_editing_id)
                            <button type="button" wire:click="cancelAcademicYear" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ $grade_level_editing_id ? __('settings.organization.sections.grade_level.edit') : __('settings.organization.sections.grade_level.create') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('settings.organization.sections.grade_level.copy') }}</p>
                </div>

                <form wire:submit="saveGradeLevel" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                        <input wire:model="grade_level_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('grade_level_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.sort_order') }}</label>
                        <input wire:model="grade_level_sort_order" type="number" min="0" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('grade_level_sort_order') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="grade_level_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('settings.organization.fields.is_active') }}</span>
                    </label>
                    @error('gradeLevelDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3">
                        <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $grade_level_editing_id ? __('settings.organization.actions.update_grade') : __('settings.organization.actions.create_grade') }}</button>
                        @if ($grade_level_editing_id)
                            <button type="button" wire:click="cancelGradeLevel" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            </div>
        </section>

        <section class="space-y-6">
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.organization.sections.academic_year.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.academic_year.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openAcademicYearModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_year') }}</button>
                </div>
                @if ($academicYears->isEmpty())
                    <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.academic_year.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.dates') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.groups') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach ($academicYears as $academicYear)
                                    <tr>
                                        <td class="px-5 py-3">
                                            <div class="font-medium">{{ $academicYear->name }}</div>
                                            <div class="text-xs text-neutral-500">{{ $academicYear->is_current ? __('settings.organization.labels.current_year') : __('settings.organization.labels.not_current') }}</div>
                                        </td>
                                        <td class="px-5 py-3">{{ __('settings.organization.labels.date_range', ['start' => $academicYear->starts_on?->format('Y-m-d'), 'end' => $academicYear->ends_on?->format('Y-m-d')]) }}</td>
                                        <td class="px-5 py-3">{{ $academicYear->groups_count }}</td>
                                        <td class="px-5 py-3">{{ $academicYear->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                        <td class="px-5 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="editAcademicYear({{ $academicYear->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="deleteAcademicYear({{ $academicYear->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.organization.sections.grade_level.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.grade_level.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openGradeLevelModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_grade') }}</button>
                </div>
                @if ($gradeLevels->isEmpty())
                    <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.grade_level.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.sort') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.usage') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach ($gradeLevels as $gradeLevel)
                                    <tr>
                                        <td class="px-5 py-3 font-medium">{{ $gradeLevel->name }}</td>
                                        <td class="px-5 py-3">{{ $gradeLevel->sort_order }}</td>
                                        <td class="px-5 py-3">{{ __('settings.organization.labels.grade_level_usage', ['groups' => $gradeLevel->groups_count, 'students' => $gradeLevel->students_count, 'policies' => $gradeLevel->point_policies_count]) }}</td>
                                        <td class="px-5 py-3">{{ $gradeLevel->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                        <td class="px-5 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="editGradeLevel({{ $gradeLevel->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="deleteGradeLevel({{ $gradeLevel->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.organization.sections.teacher_job_title.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.teacher_job_title.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openTeacherJobTitleModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_teacher_job_title') }}</button>
                </div>
                @if ($teacherJobTitles->isEmpty())
                    <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.teacher_job_title.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.sort') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.teachers') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach ($teacherJobTitles as $teacherJobTitle)
                                    <tr>
                                        <td class="px-5 py-3 font-medium">{{ $teacherJobTitle->name }}</td>
                                        <td class="px-5 py-3">{{ $teacherJobTitle->sort_order }}</td>
                                        <td class="px-5 py-3">{{ number_format($teacherJobTitle->teachers_count) }}</td>
                                        <td class="px-5 py-3">{{ $teacherJobTitle->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                        <td class="px-5 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="editTeacherJobTitle({{ $teacherJobTitle->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="deleteTeacherJobTitle({{ $teacherJobTitle->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.organization.sections.student_gender.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.student_gender.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openStudentGenderModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_student_gender') }}</button>
                </div>
                @if ($studentGenders->isEmpty())
                    <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.student_gender.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.sort') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.default') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach ($studentGenders as $studentGender)
                                    <tr>
                                        <td class="px-5 py-3 font-medium">{{ $studentGender->name }}</td>
                                        <td class="px-5 py-3 font-mono">{{ $studentGender->code }}</td>
                                        <td class="px-5 py-3">{{ $studentGender->sort_order }}</td>
                                        <td class="px-5 py-3">
                                            @if ($studentGender->is_default)
                                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">{{ __('settings.organization.labels.default_student_gender') }}</span>
                                            @else
                                                <span class="text-neutral-400">-</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3">{{ $studentGender->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                        <td class="px-5 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="editStudentGender({{ $studentGender->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="deleteStudentGender({{ $studentGender->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    </div>

    <x-admin.modal :show="$showOrganizationModal" :title="__('settings.organization.sections.profile.title')" :description="__('settings.organization.sections.profile.copy')" close-method="closeOrganizationModal" max-width="4xl">
        <form wire:submit="saveOrganizationSettings" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_name') }}</label>
                <input wire:model="school_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('school_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_phone') }}</label>
                    <input wire:model="school_phone" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    @error('school_phone') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_email') }}</label>
                    <input wire:model="school_email" type="email" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    @error('school_email') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.email_domain') }}</label>
                    <input wire:model="email_domain" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm lowercase dark:border-neutral-700 dark:bg-neutral-900" placeholder="alkhair.org">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.email_domain_help') }}</p>
                    @error('email_domain') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_timezone') }}</label>
                    <input wire:model="school_timezone" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    @error('school_timezone') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_currency') }}</label>
                    <input wire:model="school_currency" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900">
                    @error('school_currency') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.school_address') }}</label>
                <textarea wire:model="school_address" rows="3" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                @error('school_address') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeOrganizationModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('settings.organization.actions.save_settings') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showAcademicYearModal" :title="$academic_year_editing_id ? __('settings.organization.sections.academic_year.edit') : __('settings.organization.sections.academic_year.create')" :description="__('settings.organization.sections.academic_year.copy')" close-method="closeAcademicYearModal" max-width="3xl">
        <form wire:submit="saveAcademicYear" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="academic_year_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('academic_year_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.starts_on') }}</label>
                    <input wire:model="academic_year_starts_on" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    @error('academic_year_starts_on') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.ends_on') }}</label>
                    <input wire:model="academic_year_ends_on" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    @error('academic_year_ends_on') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="academic_year_is_current" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.current_academic_year') }}</span></label>
            <label class="flex items-center gap-3 text-sm"><input wire:model="academic_year_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            @error('academicYearDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeAcademicYearModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $academic_year_editing_id ? __('settings.organization.actions.update_year') : __('settings.organization.actions.create_year') }}</button>
                <x-admin.create-and-new-button :show="! $academic_year_editing_id" click="saveAndNew('saveAcademicYear', 'openAcademicYearModal')" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showGradeLevelModal" :title="$grade_level_editing_id ? __('settings.organization.sections.grade_level.edit') : __('settings.organization.sections.grade_level.create')" :description="__('settings.organization.sections.grade_level.copy')" close-method="closeGradeLevelModal" max-width="3xl">
        <form wire:submit="saveGradeLevel" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="grade_level_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('grade_level_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.sort_order') }}</label>
                <input wire:model="grade_level_sort_order" type="number" min="0" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('grade_level_sort_order') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="grade_level_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            @error('gradeLevelDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeGradeLevelModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $grade_level_editing_id ? __('settings.organization.actions.update_grade') : __('settings.organization.actions.create_grade') }}</button>
                <x-admin.create-and-new-button :show="! $grade_level_editing_id" click="saveAndNew('saveGradeLevel', 'openGradeLevelModal')" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showTeacherJobTitleModal" :title="$teacher_job_title_editing_id ? __('settings.organization.sections.teacher_job_title.edit') : __('settings.organization.sections.teacher_job_title.create')" :description="__('settings.organization.sections.teacher_job_title.copy')" close-method="closeTeacherJobTitleModal" max-width="3xl">
        <form wire:submit="saveTeacherJobTitle" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="teacher_job_title_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('teacher_job_title_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.sort_order') }}</label>
                <input wire:model="teacher_job_title_sort_order" type="number" min="0" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('teacher_job_title_sort_order') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="teacher_job_title_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            @error('teacherJobTitleDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeTeacherJobTitleModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $teacher_job_title_editing_id ? __('settings.organization.actions.update_teacher_job_title') : __('settings.organization.actions.create_teacher_job_title') }}</button>
                <x-admin.create-and-new-button :show="! $teacher_job_title_editing_id" click="saveAndNew('saveTeacherJobTitle', 'openTeacherJobTitleModal')" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showStudentGenderModal" :title="$student_gender_editing_id ? __('settings.organization.sections.student_gender.edit') : __('settings.organization.sections.student_gender.create')" :description="__('settings.organization.sections.student_gender.copy')" close-method="closeStudentGenderModal" max-width="3xl">
        <form wire:submit="saveStudentGender" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="student_gender_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('student_gender_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.code') }}</label>
                <input wire:model="student_gender_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm lowercase dark:border-neutral-700 dark:bg-neutral-900">
                @error('student_gender_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.sort_order') }}</label>
                <input wire:model="student_gender_sort_order" type="number" min="0" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('student_gender_sort_order') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="student_gender_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            <label class="flex items-center gap-3 text-sm"><input wire:model="student_gender_is_default" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.default_student_gender') }}</span></label>
            @error('student_gender_is_default') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            @error('studentGenderDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeStudentGenderModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $student_gender_editing_id ? __('settings.organization.actions.update_student_gender') : __('settings.organization.actions.create_student_gender') }}</button>
                <x-admin.create-and-new-button :show="! $student_gender_editing_id" click="saveAndNew('saveStudentGender', 'openStudentGenderModal')" />
            </div>
        </form>
    </x-admin.modal>
</div>

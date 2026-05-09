<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\ActivityExpense;
use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\ExpenseCategory;
use App\Models\FatherJob;
use App\Models\GradeLevel;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGender;
use App\Services\ParentNumberService;
use App\Services\StudentGradePromotionService;
use App\Services\StudentNumberService;
use App\Support\AvatarDefaults;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public string $school_name = '';
    public string $school_phone = '';
    public string $school_email = '';
    public string $email_domain = '';
    public string $student_number_prefix = '';
    public string $student_number_length = '0';
    public string $parent_number_prefix = 'P';
    public string $parent_number_length = '6';
    public string $school_address = '';
    public string $school_timezone = '';
    public string $school_currency = '';
    public string $default_user_avatar_path = '';
    public string $default_student_avatar_path = '';
    public string $default_teacher_avatar_path = '';
    public string $default_parent_avatar_path = '';
    public $default_user_avatar_upload = null;
    public $default_student_avatar_upload = null;
    public $default_teacher_avatar_upload = null;
    public $default_parent_avatar_upload = null;
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

    public ?int $school_reference_editing_id = null;
    public string $school_reference_name = '';
    public bool $school_reference_is_active = true;
    public bool $showSchoolReferenceModal = false;

    public ?int $father_job_editing_id = null;
    public string $father_job_name = '';
    public bool $father_job_is_active = true;
    public bool $showFatherJobModal = false;

    public ?int $expense_category_editing_id = null;
    public string $expense_category_name = '';
    public string $expense_category_code = '';
    public bool $expense_category_is_active = true;
    public bool $showExpenseCategoryModal = false;

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
                ->paginate(10, ['*'], 'academic_years_page'),
            'gradeLevels' => GradeLevel::query()
                ->withCount(['groups', 'students', 'pointPolicies'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(10, ['*'], 'grade_levels_page'),
            'schoolReferences' => School::query()->orderBy('name')->paginate(10, ['*'], 'schools_page'),
            'fatherJobs' => FatherJob::query()->orderBy('name')->paginate(10, ['*'], 'father_jobs_page'),
            'expenseCategories' => ExpenseCategory::query()->orderBy('name')->paginate(10, ['*'], 'expense_categories_page'),
            'studentGenders' => StudentGender::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(10, ['*'], 'student_genders_page'),
            'totals' => [
                'academic_years' => AcademicYear::count(),
                'active_grade_levels' => GradeLevel::query()->where('is_active', true)->count(),
                'father_jobs' => FatherJob::count(),
                'expense_categories' => ExpenseCategory::count(),
                'grade_levels' => GradeLevel::count(),
                'schools' => School::count(),
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
        $this->resetPage('academic_years_page');

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
        $this->resetPage('grade_levels_page');

        if ($this->grade_level_editing_id === $gradeLevelId) {
            $this->cancelGradeLevel();
        }

        session()->flash('status', __('settings.organization.messages.grade_level_deleted'));
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
        $this->resetPage('student_genders_page');
        $this->ensureDefaultStudentGender();

        if ($this->student_gender_editing_id === $studentGenderId) {
            $this->cancelStudentGender();
        }

        session()->flash('status', __('settings.organization.messages.student_gender_deleted'));
    }

    public function openSchoolReferenceModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelSchoolReference();
        $this->showSchoolReferenceModal = true;
    }

    public function editSchoolReference(int $schoolId): void
    {
        $this->authorizePermission('settings.manage');

        $school = School::query()->findOrFail($schoolId);
        $this->school_reference_editing_id = $school->id;
        $this->school_reference_name = $school->name;
        $this->school_reference_is_active = $school->is_active;
        $this->showSchoolReferenceModal = true;
        $this->resetValidation();
    }

    public function saveSchoolReference(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate([
            'school_reference_name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')->ignore($this->school_reference_editing_id)],
            'school_reference_is_active' => ['boolean'],
        ]);

        School::query()->updateOrCreate(
            ['id' => $this->school_reference_editing_id],
            [
                'name' => trim($validated['school_reference_name']),
                'is_active' => (bool) $validated['school_reference_is_active'],
            ],
        );

        session()->flash('status', $this->school_reference_editing_id ? __('settings.organization.messages.school_updated') : __('settings.organization.messages.school_created'));
        $this->cancelSchoolReference();
    }

    public function deleteSchoolReference(int $schoolId): void
    {
        $this->authorizePermission('settings.manage');

        $school = School::query()->findOrFail($schoolId);

        if (Student::query()->where('school_name', $school->name)->exists()) {
            $this->addError('schoolReferenceDelete', __('settings.organization.errors.school_delete_linked'));

            return;
        }

        $school->delete();
        $this->resetPage('schools_page');
        session()->flash('status', __('settings.organization.messages.school_deleted'));
    }

    public function cancelSchoolReference(): void
    {
        $this->school_reference_editing_id = null;
        $this->school_reference_name = '';
        $this->school_reference_is_active = true;
        $this->showSchoolReferenceModal = false;
        $this->resetValidation();
    }

    public function openFatherJobModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelFatherJob();
        $this->showFatherJobModal = true;
    }

    public function editFatherJob(int $fatherJobId): void
    {
        $this->authorizePermission('settings.manage');

        $fatherJob = FatherJob::query()->findOrFail($fatherJobId);
        $this->father_job_editing_id = $fatherJob->id;
        $this->father_job_name = $fatherJob->name;
        $this->father_job_is_active = $fatherJob->is_active;
        $this->showFatherJobModal = true;
        $this->resetValidation();
    }

    public function saveFatherJob(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate([
            'father_job_name' => ['required', 'string', 'max:255', Rule::unique('father_jobs', 'name')->ignore($this->father_job_editing_id)],
            'father_job_is_active' => ['boolean'],
        ]);

        FatherJob::query()->updateOrCreate(
            ['id' => $this->father_job_editing_id],
            [
                'name' => trim($validated['father_job_name']),
                'is_active' => (bool) $validated['father_job_is_active'],
            ],
        );

        session()->flash('status', $this->father_job_editing_id ? __('settings.organization.messages.father_job_updated') : __('settings.organization.messages.father_job_created'));
        $this->cancelFatherJob();
    }

    public function deleteFatherJob(int $fatherJobId): void
    {
        $this->authorizePermission('settings.manage');

        $fatherJob = FatherJob::query()->findOrFail($fatherJobId);

        if (ParentProfile::query()->where('father_work', $fatherJob->name)->exists()) {
            $this->addError('fatherJobDelete', __('settings.organization.errors.father_job_delete_linked'));

            return;
        }

        $fatherJob->delete();
        $this->resetPage('father_jobs_page');
        session()->flash('status', __('settings.organization.messages.father_job_deleted'));
    }

    public function cancelFatherJob(): void
    {
        $this->father_job_editing_id = null;
        $this->father_job_name = '';
        $this->father_job_is_active = true;
        $this->showFatherJobModal = false;
        $this->resetValidation();
    }

    public function openExpenseCategoryModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelExpenseCategory();
        $this->showExpenseCategoryModal = true;
    }

    public function editExpenseCategory(int $expenseCategoryId): void
    {
        $this->authorizePermission('settings.manage');

        $expenseCategory = ExpenseCategory::query()->findOrFail($expenseCategoryId);
        $this->expense_category_editing_id = $expenseCategory->id;
        $this->expense_category_name = $expenseCategory->name;
        $this->expense_category_code = $expenseCategory->code;
        $this->expense_category_is_active = $expenseCategory->is_active;
        $this->showExpenseCategoryModal = true;
        $this->resetValidation();
    }

    public function saveExpenseCategory(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate([
            'expense_category_code' => ['required', 'string', 'max:50', Rule::unique('expense_categories', 'code')->ignore($this->expense_category_editing_id)],
            'expense_category_is_active' => ['boolean'],
            'expense_category_name' => ['required', 'string', 'max:255'],
        ]);

        ExpenseCategory::query()->updateOrCreate(
            ['id' => $this->expense_category_editing_id],
            [
                'code' => Str::of($validated['expense_category_code'])->lower()->toString(),
                'is_active' => (bool) $validated['expense_category_is_active'],
                'name' => trim($validated['expense_category_name']),
            ],
        );

        session()->flash('status', $this->expense_category_editing_id ? __('settings.organization.messages.expense_category_updated') : __('settings.organization.messages.expense_category_created'));
        $this->cancelExpenseCategory();
    }

    public function deleteExpenseCategory(int $expenseCategoryId): void
    {
        $this->authorizePermission('settings.manage');

        $expenseCategory = ExpenseCategory::query()->findOrFail($expenseCategoryId);

        if (ActivityExpense::query()->where('expense_category_id', $expenseCategory->id)->exists()) {
            $this->addError('expenseCategoryDelete', __('settings.organization.errors.expense_category_delete_linked'));

            return;
        }

        $expenseCategory->delete();
        $this->resetPage('expense_categories_page');
        session()->flash('status', __('settings.organization.messages.expense_category_deleted'));
    }

    public function cancelExpenseCategory(): void
    {
        $this->expense_category_editing_id = null;
        $this->expense_category_name = '';
        $this->expense_category_code = '';
        $this->expense_category_is_active = true;
        $this->showExpenseCategoryModal = false;
        $this->resetValidation();
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

    public function promoteStudentsToNextGrade(): void
    {
        $this->authorizePermission('students.promote-grade-levels');

        $summary = app(StudentGradePromotionService::class)->promoteAll();

        if (($summary['active_grade_levels'] ?? 0) < 2) {
            $this->addError('studentPromotion', __('settings.organization.errors.student_promotion_requires_multiple_active_grades'));

            return;
        }

        $this->resetErrorBag('studentPromotion');

        session()->flash('status', __('settings.organization.messages.students_promoted', [
            'promoted' => number_format((int) $summary['promoted']),
            'retained' => number_format((int) $summary['retained']),
            'unassigned' => number_format((int) $summary['unassigned']),
        ]));
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
            'student_number_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]*$/'],
            'student_number_length' => ['required', 'integer', 'min:0', 'max:12'],
            'parent_number_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]*$/'],
            'parent_number_length' => ['required', 'integer', 'min:0', 'max:12'],
            'school_name' => ['required', 'string', 'max:255'],
            'school_phone' => ['nullable', 'string', 'max:50'],
            'school_timezone' => ['required', 'string', 'max:100'],
            'default_user_avatar_upload' => ['nullable', 'image', 'max:2048'],
            'default_student_avatar_upload' => ['nullable', 'image', 'max:2048'],
            'default_teacher_avatar_upload' => ['nullable', 'image', 'max:2048'],
            'default_parent_avatar_upload' => ['nullable', 'image', 'max:2048'],
        ]);

        $generalSettings = AppSetting::groupValues('general');
        $studentNumberPrefix = trim((string) ($validated['student_number_prefix'] ?? ''));
        $studentNumberLength = (int) $validated['student_number_length'];
        $parentNumberPrefix = trim((string) ($validated['parent_number_prefix'] ?? ''));
        $parentNumberLength = (int) $validated['parent_number_length'];
        $validated['student_number_prefix'] = $studentNumberPrefix;
        $validated['student_number_length'] = $studentNumberLength;
        $validated['parent_number_prefix'] = $parentNumberPrefix;
        $validated['parent_number_length'] = $parentNumberLength;
        $studentNumberFormatChanged = $studentNumberPrefix !== trim((string) ($generalSettings->get('student_number_prefix') ?? ''))
            || $studentNumberLength !== (is_numeric($generalSettings->get('student_number_length')) ? (int) $generalSettings->get('student_number_length') : 0);
        $currentParentNumberPrefix = $generalSettings->has('parent_number_prefix')
            ? trim((string) $generalSettings->get('parent_number_prefix'))
            : ParentNumberService::DEFAULT_PREFIX;
        $parentNumberFormatChanged = $parentNumberPrefix !== $currentParentNumberPrefix
            || $parentNumberLength !== (is_numeric($generalSettings->get('parent_number_length')) ? (int) $generalSettings->get('parent_number_length') : ParentNumberService::DEFAULT_LENGTH);

        foreach ([
            'school_name' => ['group' => 'general', 'type' => 'string'],
            'school_phone' => ['group' => 'general', 'type' => 'string'],
            'school_email' => ['group' => 'general', 'type' => 'string'],
            'email_domain' => ['group' => 'general', 'type' => 'string'],
            'student_number_prefix' => ['group' => 'general', 'type' => 'string'],
            'student_number_length' => ['group' => 'general', 'type' => 'integer'],
            'parent_number_prefix' => ['group' => 'general', 'type' => 'string'],
            'parent_number_length' => ['group' => 'general', 'type' => 'integer'],
            'school_address' => ['group' => 'general', 'type' => 'string'],
            'school_timezone' => ['group' => 'general', 'type' => 'string'],
            'school_currency' => ['group' => 'general', 'type' => 'string'],
        ] as $key => $config) {
            AppSetting::query()->updateOrCreate(
                ['group' => $config['group'], 'key' => $key],
                ['type' => $config['type'], 'value' => blank($validated[$key]) ? null : $validated[$key]],
            );
        }

        foreach ([
            'user' => 'default_user_avatar',
            'student' => 'default_student_avatar',
            'teacher' => 'default_teacher_avatar',
            'parent' => 'default_parent_avatar',
        ] as $type => $property) {
            $uploadProperty = $property.'_upload';
            $pathProperty = $property.'_path';

            if (! $this->{$uploadProperty}) {
                continue;
            }

            if ($this->{$pathProperty}) {
                Storage::disk('public')->delete($this->{$pathProperty});
            }

            $this->{$pathProperty} = $this->{$uploadProperty}->store('settings/default-avatars/'.$type, 'public');
            AppSetting::storeValue('media', $pathProperty, $this->{$pathProperty});
            $this->reset($uploadProperty);
        }

        AvatarDefaults::forget();

        if ($studentNumberFormatChanged) {
            app(StudentNumberService::class)->syncAll();
        }

        if ($parentNumberFormatChanged) {
            app(ParentNumberService::class)->syncAll();
        }

        session()->flash('status', __('settings.organization.messages.settings_saved'));
        $this->showOrganizationModal = false;
    }

    public function removeDefaultAvatar(string $type): void
    {
        $this->authorizePermission('settings.manage');

        if (! in_array($type, ['user', 'student', 'teacher', 'parent'], true)) {
            return;
        }

        $pathProperty = 'default_'.$type.'_avatar_path';

        if ($this->{$pathProperty}) {
            Storage::disk('public')->delete($this->{$pathProperty});
        }

        $this->{$pathProperty} = '';
        AppSetting::storeValue('media', $pathProperty, null);
        AvatarDefaults::forget();

        session()->flash('status', __('settings.organization.messages.default_avatar_removed'));
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
            ->whereIn('key', ['school_name', 'school_phone', 'school_email', 'email_domain', 'student_number_prefix', 'student_number_length', 'parent_number_prefix', 'parent_number_length', 'school_address', 'school_timezone', 'school_currency'])
            ->pluck('value', 'key');
        $media = AppSetting::groupValues('media');

        $this->school_name = (string) ($settings['school_name'] ?? 'Alkhair');
        $this->school_phone = (string) ($settings['school_phone'] ?? '');
        $this->school_email = (string) ($settings['school_email'] ?? '');
        $this->email_domain = (string) ($settings['email_domain'] ?? 'alkhair.local');
        $this->student_number_prefix = (string) ($settings['student_number_prefix'] ?? '');
        $this->student_number_length = (string) ($settings['student_number_length'] ?? '0');
        $this->parent_number_prefix = $settings->has('parent_number_prefix')
            ? (string) $settings['parent_number_prefix']
            : ParentNumberService::DEFAULT_PREFIX;
        $this->parent_number_length = (string) ($settings['parent_number_length'] ?? ParentNumberService::DEFAULT_LENGTH);
        $this->school_address = (string) ($settings['school_address'] ?? '');
        $this->school_timezone = (string) ($settings['school_timezone'] ?? config('app.timezone', 'UTC'));
        $this->school_currency = (string) ($settings['school_currency'] ?? 'USD');
        $this->default_user_avatar_path = (string) ($media->get('default_user_avatar_path') ?? '');
        $this->default_student_avatar_path = (string) ($media->get('default_student_avatar_path') ?? '');
        $this->default_teacher_avatar_path = (string) ($media->get('default_teacher_avatar_path') ?? '');
        $this->default_parent_avatar_path = (string) ($media->get('default_parent_avatar_path') ?? '');
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.organization.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.organization.subtitle') }}</p>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.organization" />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-4">
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

        @php
            $studentNumberDigits = max(0, (int) $student_number_length);
            $studentNumberPreviewOne = ($student_number_prefix ?: '').($studentNumberDigits > 0 ? str_pad('1', $studentNumberDigits, '0', STR_PAD_LEFT) : '1');
            $studentNumberPreviewHundredTwenty = ($student_number_prefix ?: '').($studentNumberDigits > 0 ? str_pad('120', $studentNumberDigits, '0', STR_PAD_LEFT) : '120');
            $parentNumberDigits = max(0, (int) $parent_number_length);
            $parentNumberPreviewOne = ($parent_number_prefix ?: '').($parentNumberDigits > 0 ? str_pad('1', $parentNumberDigits, '0', STR_PAD_LEFT) : '1');
            $parentNumberPreviewHundredTwenty = ($parent_number_prefix ?: '').($parentNumberDigits > 0 ? str_pad('120', $parentNumberDigits, '0', STR_PAD_LEFT) : '120');
        @endphp

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
            <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-200">{{ __('settings.organization.fields.student_number_preview') }}</div>
                <div class="mt-2 font-mono text-sm font-semibold text-white">{{ $studentNumberPreviewOne }}</div>
                <p class="mt-2 text-xs leading-5 text-sky-100/80">{{ $studentNumberPreviewHundredTwenty }}</p>
            </div>
            <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-200">{{ __('settings.organization.fields.parent_number_preview') }}</div>
                <div class="mt-2 font-mono text-sm font-semibold text-white">{{ $parentNumberPreviewOne }}</div>
                <p class="mt-2 text-xs leading-5 text-amber-100/80">{{ $parentNumberPreviewHundredTwenty }}</p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'user' => $default_user_avatar_path,
                'student' => $default_student_avatar_path,
                'teacher' => $default_teacher_avatar_path,
                'parent' => $default_parent_avatar_path,
            ] as $type => $path)
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center gap-3">
                        <span class="student-avatar student-avatar--md">
                            @if ($path)
                                <img src="{{ asset('storage/'.ltrim($path, '/')) }}" alt="{{ __('settings.organization.default_avatars.'.$type) }}" class="student-avatar__image">
                            @else
                                <span class="student-avatar__fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(__('settings.organization.default_avatars.'.$type), 0, 1)) }}</span>
                            @endif
                        </span>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">{{ __('settings.organization.default_avatars.'.$type) }}</div>
                            <div class="mt-1 text-sm font-semibold text-white">{{ $path ? __('settings.organization.labels.default_avatar_set') : __('settings.organization.labels.default_avatar_missing') }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
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
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.student_number_prefix') }}</label>
                            <input wire:model="student_number_prefix" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900" placeholder="S">
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.student_number_prefix_help') }}</p>
                            @error('student_number_prefix') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.student_number_length') }}</label>
                            <input wire:model="student_number_length" type="number" min="0" max="12" step="1" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="6">
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.student_number_length_help') }}</p>
                            @error('student_number_length') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.parent_number_prefix') }}</label>
                            <input wire:model="parent_number_prefix" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900" placeholder="P">
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.parent_number_prefix_help') }}</p>
                            @error('parent_number_prefix') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.parent_number_length') }}</label>
                            <input wire:model="parent_number_length" type="number" min="0" max="12" step="1" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="6">
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.parent_number_length_help') }}</p>
                            @error('parent_number_length') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
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
                    @if ($academicYears->hasPages())
                        <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">
                            {{ $academicYears->links() }}
                        </div>
                    @endif
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
                    @if ($gradeLevels->hasPages())
                        <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">
                            {{ $gradeLevels->links() }}
                        </div>
                    @endif
                @endif
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                        <div>
                            <div class="text-sm font-medium">{{ __('settings.organization.sections.school_reference.table') }}</div>
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.school_reference.copy') }}</p>
                        </div>
                        <button type="button" wire:click="openSchoolReferenceModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_school') }}</button>
                    </div>
                    @error('schoolReferenceDelete') <div class="px-5 pt-4 text-sm text-red-600">{{ $message }}</div> @enderror
                    @if ($schoolReferences->isEmpty())
                        <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.school_reference.empty') }}</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                                <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                    @foreach ($schoolReferences as $schoolReference)
                                        <tr>
                                            <td class="px-5 py-3 font-medium">{{ $schoolReference->name }}</td>
                                            <td class="px-5 py-3">{{ $schoolReference->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                            <td class="px-5 py-3">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" wire:click="editSchoolReference({{ $schoolReference->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                    <button type="button" wire:click="deleteSchoolReference({{ $schoolReference->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($schoolReferences->hasPages())
                            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">
                                {{ $schoolReferences->links() }}
                            </div>
                        @endif
                    @endif
                </div>

                <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                        <div>
                            <div class="text-sm font-medium">{{ __('settings.organization.sections.father_job.table') }}</div>
                            <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.father_job.copy') }}</p>
                        </div>
                        <button type="button" wire:click="openFatherJobModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_father_job') }}</button>
                    </div>
                    @error('fatherJobDelete') <div class="px-5 pt-4 text-sm text-red-600">{{ $message }}</div> @enderror
                    @if ($fatherJobs->isEmpty())
                        <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.father_job.empty') }}</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                                <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                    @foreach ($fatherJobs as $fatherJob)
                                        <tr>
                                            <td class="px-5 py-3 font-medium">{{ $fatherJob->name }}</td>
                                            <td class="px-5 py-3">{{ $fatherJob->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                            <td class="px-5 py-3">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" wire:click="editFatherJob({{ $fatherJob->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                    <button type="button" wire:click="deleteFatherJob({{ $fatherJob->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($fatherJobs->hasPages())
                            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">
                                {{ $fatherJobs->links() }}
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.organization.sections.activity_expense_category.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.activity_expense_category.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openExpenseCategoryModal" class="pill-link pill-link--accent">{{ __('settings.organization.actions.create_expense_category') }}</button>
                </div>
                @error('expenseCategoryDelete') <div class="px-5 pt-4 text-sm text-red-600">{{ $message }}</div> @enderror
                @if ($expenseCategories->isEmpty())
                    <div class="px-5 py-10 text-sm text-neutral-500">{{ __('settings.organization.sections.activity_expense_category.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.name') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.organization.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.organization.table.actions') }}</th></tr></thead>
                            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @foreach ($expenseCategories as $expenseCategory)
                                    <tr>
                                        <td class="px-5 py-3 font-medium">{{ $expenseCategory->name }}</td>
                                        <td class="px-5 py-3 font-mono">{{ $expenseCategory->code }}</td>
                                        <td class="px-5 py-3">{{ $expenseCategory->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                        <td class="px-5 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" wire:click="editExpenseCategory({{ $expenseCategory->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="deleteExpenseCategory({{ $expenseCategory->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($expenseCategories->hasPages())
                        <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">
                            {{ $expenseCategories->links() }}
                        </div>
                    @endif
                @endif
            </div>

            @can('students.promote-grade-levels')
                <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                    <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                        <div class="text-sm font-medium">{{ __('settings.organization.sections.student_promotion.title') }}</div>
                        <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.sections.student_promotion.copy') }}</p>
                    </div>
                    <div class="space-y-4 px-5 py-4">
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm leading-7 text-neutral-300">
                            {{ __('settings.organization.sections.student_promotion.note') }}
                        </div>
                        @error('studentPromotion')
                            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div>
                        @enderror
                        <div class="flex flex-wrap justify-end gap-3">
                            <button
                                type="button"
                                wire:click="promoteStudentsToNextGrade"
                                wire:confirm="{{ __('settings.organization.actions.promote_students_confirm') }}"
                                class="pill-link pill-link--accent"
                            >
                                {{ __('settings.organization.actions.promote_students') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endcan

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
                    @if ($studentGenders->hasPages())
                        <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-700">
                            {{ $studentGenders->links() }}
                        </div>
                    @endif
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
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.student_number_prefix') }}</label>
                    <input wire:model="student_number_prefix" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900" placeholder="S">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.student_number_prefix_help') }}</p>
                    @error('student_number_prefix') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.student_number_length') }}</label>
                    <input wire:model="student_number_length" type="number" min="0" max="12" step="1" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="6">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.student_number_length_help') }}</p>
                    @error('student_number_length') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.parent_number_prefix') }}</label>
                    <input wire:model="parent_number_prefix" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900" placeholder="P">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.parent_number_prefix_help') }}</p>
                    @error('parent_number_prefix') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.parent_number_length') }}</label>
                    <input wire:model="parent_number_length" type="number" min="0" max="12" step="1" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="6">
                    <p class="mt-1 text-xs text-neutral-500">{{ __('settings.organization.fields.parent_number_length_help') }}</p>
                    @error('parent_number_length') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
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
            <section class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="mb-4">
                    <div class="text-sm font-semibold text-white">{{ __('settings.organization.sections.default_avatars.title') }}</div>
                    <p class="mt-1 text-xs leading-5 text-neutral-400">{{ __('settings.organization.sections.default_avatars.copy') }}</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ([
                        'user' => ['path' => $default_user_avatar_path, 'upload' => 'default_user_avatar_upload'],
                        'student' => ['path' => $default_student_avatar_path, 'upload' => 'default_student_avatar_upload'],
                        'teacher' => ['path' => $default_teacher_avatar_path, 'upload' => 'default_teacher_avatar_upload'],
                        'parent' => ['path' => $default_parent_avatar_path, 'upload' => 'default_parent_avatar_upload'],
                    ] as $type => $avatar)
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex items-center gap-3">
                                <span class="student-avatar student-avatar--lg">
                                    @if ($this->{$avatar['upload']})
                                        <img src="{{ $this->{$avatar['upload']}->temporaryUrl() }}" alt="{{ __('settings.organization.default_avatars.'.$type) }}" class="student-avatar__image">
                                    @elseif ($avatar['path'])
                                        <img src="{{ asset('storage/'.ltrim($avatar['path'], '/')) }}" alt="{{ __('settings.organization.default_avatars.'.$type) }}" class="student-avatar__image">
                                    @else
                                        <span class="student-avatar__fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(__('settings.organization.default_avatars.'.$type), 0, 1)) }}</span>
                                    @endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.default_avatars.'.$type) }}</label>
                                    <input wire:model="{{ $avatar['upload'] }}" type="file" accept="image/*" class="block w-full text-sm text-neutral-300">
                                    @error($avatar['upload']) <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            @if ($avatar['path'])
                                <button type="button" wire:click="removeDefaultAvatar('{{ $type }}')" class="pill-link pill-link--compact pill-link--danger mt-3">
                                    {{ __('settings.organization.actions.remove_default_avatar') }}
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
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

    <x-admin.modal :show="$showSchoolReferenceModal" :title="$school_reference_editing_id ? __('settings.organization.sections.school_reference.edit') : __('settings.organization.sections.school_reference.create')" :description="__('settings.organization.sections.school_reference.copy')" close-method="cancelSchoolReference" max-width="2xl">
        <form wire:submit="saveSchoolReference" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="school_reference_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('school_reference_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="school_reference_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="cancelSchoolReference" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $school_reference_editing_id ? __('settings.organization.actions.update_school') : __('settings.organization.actions.create_school') }}</button>
                <x-admin.create-and-new-button :show="! $school_reference_editing_id" click="saveAndNew('saveSchoolReference', 'openSchoolReferenceModal')" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showFatherJobModal" :title="$father_job_editing_id ? __('settings.organization.sections.father_job.edit') : __('settings.organization.sections.father_job.create')" :description="__('settings.organization.sections.father_job.copy')" close-method="cancelFatherJob" max-width="2xl">
        <form wire:submit="saveFatherJob" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="father_job_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('father_job_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="father_job_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="cancelFatherJob" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $father_job_editing_id ? __('settings.organization.actions.update_father_job') : __('settings.organization.actions.create_father_job') }}</button>
                <x-admin.create-and-new-button :show="! $father_job_editing_id" click="saveAndNew('saveFatherJob', 'openFatherJobModal')" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showExpenseCategoryModal" :title="$expense_category_editing_id ? __('settings.organization.sections.activity_expense_category.edit') : __('settings.organization.sections.activity_expense_category.create')" :description="__('settings.organization.sections.activity_expense_category.copy')" close-method="cancelExpenseCategory" max-width="2xl">
        <form wire:submit="saveExpenseCategory" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.name') }}</label>
                <input wire:model="expense_category_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('expense_category_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.organization.fields.code') }}</label>
                <input wire:model="expense_category_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm lowercase dark:border-neutral-700 dark:bg-neutral-900">
                @error('expense_category_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="expense_category_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.organization.fields.is_active') }}</span></label>
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="cancelExpenseCategory" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $expense_category_editing_id ? __('settings.organization.actions.update_expense_category') : __('settings.organization.actions.create_expense_category') }}</button>
                <x-admin.create-and-new-button :show="! $expense_category_editing_id" click="saveAndNew('saveExpenseCategory', 'openExpenseCategoryModal')" />
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

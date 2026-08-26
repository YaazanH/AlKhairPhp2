<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\FatherJob;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\QuranJuz;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGender;
use App\Models\User;
use App\Services\ManagedUserService;
use App\Services\MemorizationService;
use App\Services\QuranFinalTestService;
use App\Services\QuranPartialTestService;
use App\Services\StudentNumberService;
use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithFileUploads;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $parent_id = null;
    public string $first_name = '';
    public string $last_name = '';
    public string $student_phone = '';
    public string $birth_date = '';
    public string $gender = '';
    public string $school_name = '';
    public ?int $grade_level_id = null;
    public ?int $enrollment_group_id = null;
    public ?int $quran_current_juz_id = null;
    public string $quran_current_juz_number = '';
    public bool $quran_current_juz_locked = false;
    public array $external_memorized_juz_ids = [];
    public string $external_memorized_juz_input = '';
    public string $photo_path = '';
    public $quick_photo_upload = null;
    public string $status = 'active';
    public string $joined_at = '';
    public string $notes = '';
    public ?int $accountStudentId = null;
    public string $account_username = '';
    public string $account_email = '';
    public string $account_password = '';
    public bool $account_is_active = true;
    public ?string $issued_password = null;
    public string $search = '';
    public string $statusFilter = 'all';
    public string $sortField = 'status';
    public string $sortDirection = 'asc';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showAccountModal = false;
    public bool $showBulkStatusModal = false;
    public bool $showDuplicateStudentModal = false;
    public bool $showExternalTestModal = false;
    public ?int $external_test_juz_id = null;
    public string $external_test_type = 'partial';
    public ?int $duplicateStudentId = null;
    public bool $showQuickParentForm = false;
    public string $quick_parent_father_name = '';
    public string $quick_parent_father_work = '';
    public string $quick_parent_new_father_work = '';
    public string $quick_parent_father_phone = '';
    public string $quick_parent_mother_name = '';
    public string $quick_parent_mother_phone = '';
    public string $quick_parent_home_phone = '';
    public string $quick_parent_address = '';
    public string $new_school_name = '';
    public string $bulk_status_action = 'deactivate';
    public string $bulk_scope = 'all';
    public string $bulk_student_number_from = '';
    public string $bulk_student_number_to = '';
    public ?int $bulk_course_id = null;
    public ?int $bulk_group_id = null;
    public bool $bulk_sync_accounts = true;
    public bool $enrollment_group_auto = true;
    public bool $syncing_enrollment_group_id = false;

    protected array $sortableFields = [
        'enrollments',
        'grade',
        'juz',
        'parent',
        'status',
        'student',
        'student_number',
    ];

    public function mount(): void
    {
        $this->authorizePermission('students.view');
    }

    public function with(): array
    {
        $currentAcademicYearId = AcademicYear::query()
            ->where('is_current', true)
            ->value('id');

        $filteredQuery = $this->scopeStudentsQuery(Student::query())
            ->with(['parentProfile', 'gradeLevel', 'quranCurrentJuz'])
            ->withCount('enrollments')
            ->when(filled($this->search), fn (Builder $query) => $this->applyStudentSearch($query, $this->search))
            ->when(in_array($this->statusFilter, ['active', 'inactive', 'graduated', 'blocked'], true), fn ($query) => $query->where('status', $this->statusFilter));
        $this->applyStudentSort($filteredQuery);

        $filteredCount = (clone $filteredQuery)->count();

        return [
            'students' => $filteredQuery->paginate($this->perPage),
            'parents' => $this->scopeParentsQuery(
                ParentProfile::query()
                    ->with(['students' => fn ($query) => $query->select('id', 'parent_id', 'last_name')->orderBy('last_name')])
                    ->where('is_active', true)
            )->orderBy('father_name')->get(['id', 'father_name', 'mother_name', 'father_phone', 'mother_phone', 'home_phone']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'enrollmentGroups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with([
                        'academicYear:id,name',
                        'course:id,name',
                        'gradeLevel:id,name',
                    ])
                    ->where('is_active', true)
            )
                ->when($currentAcademicYearId, fn (Builder $query) => $query->orderByRaw('case when academic_year_id = ? then 0 else 1 end', [$currentAcademicYearId]))
                ->orderBy('name')
                ->get(['id', 'name', 'academic_year_id', 'course_id', 'grade_level_id']),
            'fatherJobs' => FatherJob::query()->where('is_active', true)->orderBy('name')->get(['name']),
            'juzs' => QuranJuz::query()->orderBy('juz_number')->get(['id', 'juz_number', 'from_page', 'to_page']),
            'schools' => School::query()->where('is_active', true)->orderBy('name')->get(['name']),
            'filteredCount' => $filteredCount,
            'statuses' => ['active', 'inactive', 'graduated', 'blocked'],
            'bulkCourses' => Course::query()
                ->where('is_active', true)
                ->whereIn('id', $this->scopeGroupsQuery(
                    Group::query()
                        ->where('is_active', true)
                        ->select('course_id')
                )->pluck('course_id')->filter()->unique()->all())
                ->orderBy('name')
                ->get(['id', 'name']),
            'bulkGroups' => $this->scopeGroupsQuery(
                Group::query()
                    ->where('is_active', true)
                    ->when($this->bulk_course_id, fn (Builder $query) => $query->where('course_id', $this->bulk_course_id))
            )
                ->orderBy('name')
                ->get(['id', 'name', 'course_id']),
            'bulkStatusPreview' => $this->showBulkStatusModal ? $this->bulkStatusPreview() : ['profiles' => 0, 'accounts' => 0],
            'duplicateStudent' => $this->showDuplicateStudentModal && $this->duplicateStudentId
                ? $this->scopeStudentsQuery(
                    Student::query()->with([
                        'user',
                        'parentProfile',
                        'gradeLevel',
                        'quranCurrentJuz',
                        'enrollments' => fn ($query) => $query
                            ->with('group.course')
                            ->orderByRaw("case when status = 'active' then 0 else 1 end")
                            ->orderByDesc('enrolled_at')
                            ->orderByDesc('id'),
                    ])
                )->find($this->duplicateStudentId)
                : null,
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

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = in_array($field, ['student', 'student_number', 'parent', 'grade', 'status'], true) ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function updatedGradeLevelId(): void
    {
        if (! $this->showFormModal || $this->editingId || ! $this->enrollment_group_auto) {
            return;
        }

        $this->syncDefaultEnrollmentGroup();
    }

    public function updatedBirthDate(): void
    {
        if (! $this->showFormModal) {
            return;
        }

        $this->syncGradeLevelFromBirthYear();
    }

    public function addExternalMemorizedJuz(): void
    {
        $this->external_memorized_juz_input = trim($this->external_memorized_juz_input);

        if ($this->external_memorized_juz_input === '') {
            return;
        }

        $validated = $this->validate([
            'external_memorized_juz_input' => ['required', 'integer', 'between:1,30', 'exists:quran_juzs,juz_number'],
        ], $this->juzNumberValidationMessages(), [
            'external_memorized_juz_input' => __('crud.students.form.fields.external_memorized_juzs'),
        ]);

        $juzId = QuranJuz::query()
            ->where('juz_number', (int) $validated['external_memorized_juz_input'])
            ->value('id');

        if ($juzId && ! in_array((int) $juzId, array_map('intval', $this->external_memorized_juz_ids), true)) {
            $this->external_memorized_juz_ids[] = (int) $juzId;
        }

        $this->external_memorized_juz_input = '';
        $this->resetValidation(['external_memorized_juz_input', 'external_memorized_juz_ids']);
    }

    public function removeExternalMemorizedJuz(int $juzId): void
    {
        $this->external_memorized_juz_ids = array_values(array_filter(
            $this->external_memorized_juz_ids,
            fn ($selectedJuzId) => (int) $selectedJuzId !== $juzId,
        ));

        $this->resetValidation(['external_memorized_juz_input', 'external_memorized_juz_ids']);
    }

    public function commitCurrentJuz(): void
    {
        $this->quran_current_juz_number = trim($this->quran_current_juz_number);

        if ($this->quran_current_juz_number === '') {
            $this->quran_current_juz_id = null;
            $this->quran_current_juz_locked = false;
            $this->resetValidation(['quran_current_juz_number', 'quran_current_juz_id']);

            return;
        }

        $validated = $this->validate([
            'quran_current_juz_number' => ['required', 'integer', 'between:1,30', 'exists:quran_juzs,juz_number'],
        ], $this->juzNumberValidationMessages(), [
            'quran_current_juz_number' => __('crud.students.form.fields.current_juz'),
        ]);

        $juz = QuranJuz::query()
            ->where('juz_number', (int) $validated['quran_current_juz_number'])
            ->firstOrFail(['id', 'juz_number']);

        $this->quran_current_juz_id = $juz->id;
        $this->quran_current_juz_number = (string) $juz->juz_number;
        $this->quran_current_juz_locked = true;
        $this->resetValidation(['quran_current_juz_number', 'quran_current_juz_id']);
    }

    public function clearCurrentJuz(): void
    {
        $this->quran_current_juz_id = null;
        $this->quran_current_juz_number = '';
        $this->quran_current_juz_locked = false;
        $this->resetValidation(['quran_current_juz_number', 'quran_current_juz_id']);
        $this->dispatch('focus-current-juz');
    }

    public function updatedEnrollmentGroupId(): void
    {
        if ($this->syncing_enrollment_group_id) {
            $this->syncing_enrollment_group_id = false;

            return;
        }

        if ($this->editingId) {
            return;
        }

        $this->enrollment_group_auto = false;
    }

    public function updatedBulkScope(): void
    {
        $this->bulk_student_number_from = '';
        $this->bulk_student_number_to = '';
        $this->bulk_course_id = null;
        $this->bulk_group_id = null;
        $this->resetValidation([
            'bulk_scope',
            'bulk_student_number_from',
            'bulk_student_number_to',
            'bulk_course_id',
            'bulk_group_id',
            'bulk_status',
        ]);
    }

    public function updatedBulkCourseId(): void
    {
        if (! $this->bulk_course_id) {
            $this->bulk_group_id = null;

            return;
        }

        $groupCourseId = Group::query()->whereKey($this->bulk_group_id)->value('course_id');

        if ($groupCourseId !== $this->bulk_course_id) {
            $this->bulk_group_id = null;
        }
    }

    public function openBulkStatusModal(): void
    {
        $this->authorizePermission('students.update');

        $this->bulk_status_action = 'deactivate';
        $this->bulk_scope = 'all';
        $this->bulk_student_number_from = '';
        $this->bulk_student_number_to = '';
        $this->bulk_course_id = null;
        $this->bulk_group_id = null;
        $this->bulk_sync_accounts = true;
        $this->showBulkStatusModal = true;

        $this->resetValidation([
            'bulk_scope',
            'bulk_student_number_from',
            'bulk_student_number_to',
            'bulk_course_id',
            'bulk_group_id',
            'bulk_status',
        ]);
    }

    public function closeBulkStatusModal(): void
    {
        $this->showBulkStatusModal = false;
        $this->bulk_status_action = 'deactivate';
        $this->bulk_scope = 'all';
        $this->bulk_student_number_from = '';
        $this->bulk_student_number_to = '';
        $this->bulk_course_id = null;
        $this->bulk_group_id = null;
        $this->bulk_sync_accounts = true;

        $this->resetValidation([
            'bulk_scope',
            'bulk_student_number_from',
            'bulk_student_number_to',
            'bulk_course_id',
            'bulk_group_id',
            'bulk_status',
        ]);
    }

    public function applyBulkStatus(): void
    {
        $this->authorizePermission('students.update');

        $targets = $this->targetStudentIdsForBulkStatus();
        $studentCount = count($targets);

        if ($studentCount === 0) {
            $this->addError('bulk_status', __('crud.students.bulk_status.errors.no_targets'));

            return;
        }

        $accountIds = [];

        if ($this->bulk_sync_accounts) {
            $accountIds = Student::query()
                ->whereIn('id', $targets)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all();
        }

        DB::transaction(function () use ($targets, $accountIds): void {
            Student::query()
                ->whereIn('id', $targets)
                ->update([
                    'status' => $this->bulk_status_action === 'activate' ? 'active' : 'inactive',
                    'updated_at' => now(),
                ]);

            if ($this->bulk_sync_accounts && $accountIds !== []) {
                User::query()
                    ->whereIn('id', $accountIds)
                    ->update([
                        'is_active' => $this->bulk_status_action === 'activate',
                        'updated_at' => now(),
                    ]);
            }
        });

        session()->flash('status', __('crud.students.bulk_status.messages.updated', [
            'count' => number_format($studentCount),
            'status' => __('crud.common.status_options.'.($this->bulk_status_action === 'activate' ? 'active' : 'inactive')),
        ]));

        $this->closeBulkStatusModal();
    }

    public function rules(?int $ignoredUserId = null): array
    {
        return [
            'parent_id' => ['nullable', 'exists:parents,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'student_phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($ignoredUserId ?? $this->linkedUserId())],
            'birth_date' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $this->isValidBirthYearValue((string) $value)) {
                    $fail(__('validation.date', ['attribute' => __('crud.students.form.fields.birth_year')]));
                }
            }],
            'gender' => ['nullable', Rule::exists('student_genders', 'code')],
            'school_name' => ['nullable', 'string', 'max:255'],
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
            'enrollment_group_id' => ['nullable', 'exists:groups,id'],
            'quran_current_juz_id' => ['nullable', 'exists:quran_juzs,id'],
            'quran_current_juz_number' => ['nullable', 'integer', 'between:1,30', 'exists:quran_juzs,juz_number'],
            'external_memorized_juz_ids' => ['array'],
            'external_memorized_juz_ids.*' => ['integer', 'distinct', 'exists:quran_juzs,id'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive,graduated,blocked'],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function juzNumberValidationMessages(): array
    {
        $message = __('crud.students.errors.juz_number_range');

        return [
            'quran_current_juz_number.integer' => $message,
            'quran_current_juz_number.between' => $message,
            'quran_current_juz_number.exists' => $message,
            'external_memorized_juz_input.integer' => $message,
            'external_memorized_juz_input.between' => $message,
            'external_memorized_juz_input.exists' => $message,
        ];
    }

    public function accountRules(): array
    {
        return [
            'account_username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->linkedUserId())],
            'account_email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->linkedUserId())],
            'account_password' => ['nullable', 'string', 'min:8'],
            'account_is_active' => ['boolean'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('students.create');

        $this->cancel();
        $this->syncDefaultEnrollmentGroup();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $isEditing = $this->editingId !== null;

        if (! $this->grade_level_id) {
            $this->syncGradeLevelFromBirthYear();
        }

        $this->quran_current_juz_id = filled($this->quran_current_juz_number)
            ? QuranJuz::query()->where('juz_number', (int) $this->quran_current_juz_number)->value('id')
            : null;

        $this->authorizePermission($isEditing ? 'students.update' : 'students.create');

        if ($isEditing) {
            $this->authorizeScopedStudentAccess(Student::query()->findOrFail($this->editingId));
        }

        $duplicate = ! $isEditing ? $this->findDuplicateStudent([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date,
        ]) : null;

        if ($duplicate) {
            $this->authorizeScopedStudentAccess($duplicate);

            if ($duplicate->status === 'active') {
                $this->showActiveDuplicateStudent($duplicate);

                return;
            }

            $this->authorizePermission('students.update');
        }

        $this->student_phone = PhoneNumberFormatter::normalize($this->student_phone) ?? '';
        $validated = $this->validate($this->rules($duplicate?->user_id), $this->juzNumberValidationMessages());
        if (filled($validated['parent_id'] ?? null)) {
            $this->authorizeScopedParentAccess(ParentProfile::query()->findOrFail($validated['parent_id']));
        }

        if ($isEditing) {
            $editingDuplicate = $this->findDuplicateStudent($validated);

            if ($editingDuplicate) {
                $this->addError('first_name', __('crud.students.errors.duplicate_profile', [
                    'name' => $editingDuplicate->full_name,
                    'number' => $editingDuplicate->student_number ?: $editingDuplicate->id,
                ]));

                return;
            }
        }

        if (! $isEditing) {
            $duplicate = $this->findDuplicateStudent($validated);

            if ($duplicate) {
                $this->authorizeScopedStudentAccess($duplicate);

                if ($duplicate->status === 'active') {
                    $this->showActiveDuplicateStudent($duplicate);

                    return;
                }

                $this->authorizePermission('students.update');
            }
        }

        $targetStudentId = $this->editingId ?? $duplicate?->id;
        $isUpdatingExisting = $targetStudentId !== null;

        $selectedGroupId = ! $isUpdatingExisting && filled($validated['enrollment_group_id'] ?? null)
            ? (int) $validated['enrollment_group_id']
            : null;

        $selectedGroup = null;

        if ($selectedGroupId) {
            $selectedGroup = Group::query()->findOrFail($selectedGroupId);
            $this->authorizeScopedGroupAccess($selectedGroup);
        }

        $studentPhone = filled($validated['student_phone'] ?? null) ? trim((string) $validated['student_phone']) : null;
        unset($validated['student_phone']);
        unset($validated['enrollment_group_id']);
        $externalMemorizedJuzIds = array_map('intval', $validated['external_memorized_juz_ids'] ?? []);
        unset($validated['external_memorized_juz_ids']);
        unset($validated['quran_current_juz_number']);
        $validated['birth_date'] = $this->normalizeBirthYearValue((string) $validated['birth_date']);
        $validated['gender'] = $validated['gender'] ?: null;
        $validated['parent_id'] = $validated['parent_id'] ?: null;
        $validated['grade_level_id'] = $validated['grade_level_id'] ?: null;
        $validated['quran_current_juz_id'] = $validated['quran_current_juz_id'] ?: null;
        $validated['photo_path'] = $validated['photo_path'] ?: null;
        $validated['joined_at'] = $isUpdatingExisting
            ? ($validated['joined_at'] ?: null)
            : ($validated['joined_at'] ?: now()->toDateString());
        $payload = DB::transaction(function () use ($externalMemorizedJuzIds, $isUpdatingExisting, $selectedGroup, $studentPhone, $targetStudentId, $validated): array {
            $student = Student::query()->updateOrCreate(
                ['id' => $targetStudentId],
                $validated,
            );
            $student->refresh();

            $result = app(ManagedUserService::class)->syncLinkedUser(
                $student->user,
                [
                    'name' => $student->full_name,
                    'username' => $student->student_number ?: null,
                    'phone' => $studentPhone,
                    'is_active' => ! in_array($validated['status'], ['inactive', 'blocked'], true),
                ],
                'student',
            );

            $student->user()->associate($result['user']);
            $student->save();
            $juzSyncChanges = $student->externalMemorizedJuzs()->sync($externalMemorizedJuzIds);

            if ($juzSyncChanges['attached'] !== [] || $juzSyncChanges['detached'] !== [] || $juzSyncChanges['updated'] !== []) {
                app(MemorizationService::class)->rebuildStudentAchievementsAndPoints($student);
            }

            if (! $isUpdatingExisting && $selectedGroup && ! Enrollment::query()
                ->where('student_id', $student->id)
                ->where('group_id', $selectedGroup->id)
                ->exists()) {
                Enrollment::query()->create([
                    'student_id' => $student->id,
                    'group_id' => $selectedGroup->id,
                    'enrolled_at' => $validated['joined_at'],
                    'status' => 'active',
                    'left_at' => null,
                    'notes' => null,
                ]);
            }

            return [
                'credentials' => $result['credentials'],
            ];
        });

        if ($payload['credentials']['password']) {
            session()->flash('generated_credentials', $payload['credentials']);
        }

        session()->flash(
            'status',
            $isUpdatingExisting ? __('crud.students.messages.updated') : __('crud.students.messages.created'),
        );

        $this->cancel();
    }

    public function openQuickParentForm(): void
    {
        abort_unless($this->canPermission('parents.create') || $this->canPermission('parents.update'), 403);

        $this->showQuickParentForm = true;
        $parent = $this->parent_id ? ParentProfile::query()->find($this->parent_id) : null;
        $this->quick_parent_father_name = $parent?->father_name ?? '';
        $this->quick_parent_father_work = $parent?->father_work ?? '';
        $this->quick_parent_new_father_work = '';
        $this->quick_parent_father_phone = $parent?->father_phone ?? '';
        $this->quick_parent_mother_name = $parent?->mother_name ?? '';
        $this->quick_parent_mother_phone = $parent?->mother_phone ?? '';
        $this->quick_parent_home_phone = $parent?->home_phone ?? '';
        $this->quick_parent_address = $parent?->address ?? '';
        $this->resetValidation([
            'quick_parent_father_name',
            'quick_parent_father_work',
            'quick_parent_new_father_work',
            'quick_parent_father_phone',
            'quick_parent_mother_name',
            'quick_parent_mother_phone',
            'quick_parent_home_phone',
            'quick_parent_address',
        ]);
    }

    public function closeQuickParentForm(): void
    {
        $this->showQuickParentForm = false;
        $this->quick_parent_father_name = '';
        $this->quick_parent_father_work = '';
        $this->quick_parent_new_father_work = '';
        $this->quick_parent_father_phone = '';
        $this->quick_parent_mother_name = '';
        $this->quick_parent_mother_phone = '';
        $this->quick_parent_home_phone = '';
        $this->quick_parent_address = '';
        $this->resetValidation([
            'quick_parent_father_name',
            'quick_parent_father_work',
            'quick_parent_new_father_work',
            'quick_parent_father_phone',
            'quick_parent_mother_name',
            'quick_parent_mother_phone',
            'quick_parent_home_phone',
            'quick_parent_address',
        ]);
    }

    public function removeParentRelationship(): void
    {
        $this->authorizePermission('students.update');
        abort_unless($this->editingId !== null, 404);

        $this->parent_id = null;
        $this->closeQuickParentForm();
        $this->resetValidation('parent_id');
    }

    public function clearSelectedParent(): void
    {
        $this->authorizePermission($this->editingId ? 'students.update' : 'students.create');
        $this->parent_id = null;
        $this->closeQuickParentForm();
        $this->resetValidation('parent_id');
    }

    public function saveQuickParent(): void
    {
        $updatingParent = $this->editingId && $this->parent_id;

        if (! $this->editingId) {
            $duplicate = $this->findDuplicateStudent([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'birth_date' => $this->birth_date,
            ]);

            if ($duplicate?->status === 'active') {
                $this->authorizeScopedStudentAccess($duplicate);
                $this->showActiveDuplicateStudent($duplicate);

                return;
            }
        }

        $this->authorizePermission($updatingParent ? 'parents.update' : 'parents.create');

        foreach (['quick_parent_father_phone', 'quick_parent_mother_phone', 'quick_parent_home_phone'] as $phoneField) {
            $this->{$phoneField} = PhoneNumberFormatter::normalize($this->{$phoneField}) ?? '';
        }

        $validated = $this->validate([
            'quick_parent_father_name' => ['required', 'string', 'max:255'],
            'quick_parent_father_work' => ['nullable', 'string', 'max:255'],
            'quick_parent_new_father_work' => ['nullable', 'string', 'max:255'],
            'quick_parent_father_phone' => ['nullable', 'string', 'max:30'],
            'quick_parent_mother_name' => ['nullable', 'string', 'max:255'],
            'quick_parent_mother_phone' => ['nullable', 'string', 'max:30'],
            'quick_parent_home_phone' => ['nullable', 'string', 'max:30'],
            'quick_parent_address' => ['nullable', 'string', 'max:255'],
        ], [], [
            'quick_parent_father_name' => __('crud.parents.form.fields.father_name'),
            'quick_parent_father_work' => __('crud.parents.form.fields.father_work'),
            'quick_parent_new_father_work' => __('crud.parents.form.fields.father_work'),
            'quick_parent_father_phone' => __('crud.parents.form.fields.father_phone'),
            'quick_parent_mother_name' => __('crud.parents.form.fields.mother_name'),
            'quick_parent_mother_phone' => __('crud.parents.form.fields.mother_phone'),
            'quick_parent_home_phone' => __('crud.parents.form.fields.home_phone'),
            'quick_parent_address' => __('crud.parents.form.fields.address'),
        ]);

        if (! $updatingParent && $duplicate = $this->findDuplicateParent([
            'father_name' => $validated['quick_parent_father_name'],
            'father_phone' => $validated['quick_parent_father_phone'],
            'mother_name' => $validated['quick_parent_mother_name'],
            'mother_phone' => $validated['quick_parent_mother_phone'],
            'home_phone' => $validated['quick_parent_home_phone'],
        ])) {
            $this->addError('quick_parent_father_name', __('crud.parents.errors.duplicate_profile', [
                'name' => $duplicate->father_name,
                'number' => $duplicate->parent_number ?: $duplicate->id,
            ]));

            return;
        }

        $parent = ParentProfile::query()->updateOrCreate(['id' => $updatingParent ? $this->parent_id : null], [
            'father_name' => $validated['quick_parent_father_name'],
            'father_work' => ($validated['quick_parent_new_father_work'] ?: $validated['quick_parent_father_work']) ?: null,
            'father_phone' => $validated['quick_parent_father_phone'] ?: null,
            'mother_name' => $validated['quick_parent_mother_name'] ?: null,
            'mother_phone' => $validated['quick_parent_mother_phone'] ?: null,
            'home_phone' => $validated['quick_parent_home_phone'] ?: null,
            'address' => $validated['quick_parent_address'] ?: null,
            'is_active' => true,
        ]);

        $result = app(ManagedUserService::class)->syncLinkedUser(
            $parent->user,
            [
                'name' => $parent->father_name,
                'username' => $parent->parent_number ?: null,
                'phone' => $parent->father_phone ?: ($parent->mother_phone ?: $parent->home_phone),
                'is_active' => true,
            ],
            'parent',
        );

        $parent->user()->associate($result['user']);
        $parent->save();

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        $this->parent_id = $parent->id;
        session()->flash('status', __('crud.students.messages.parent_shortcut_created', ['name' => $parent->father_name]));
        $this->closeQuickParentForm();
    }

    public function edit(int $studentId): void
    {
        $this->authorizePermission('students.update');

        $student = Student::query()->with('externalMemorizedJuzs')->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        $this->editingId = $student->id;
        $this->parent_id = $student->parent_id;
        $this->first_name = $student->first_name;
        $this->last_name = $student->last_name;
        $this->student_phone = $student->user?->phone ?? '';
        $this->birth_date = $student->birth_date?->format('Y') ?? '';
        $this->gender = $student->gender ?? '';
        $this->school_name = $student->school_name ?? '';
        $this->grade_level_id = $student->grade_level_id;
        $this->enrollment_group_id = null;
        $this->quran_current_juz_id = $student->quran_current_juz_id;
        $this->quran_current_juz_number = (string) ($student->quranCurrentJuz?->juz_number ?? '');
        $this->quran_current_juz_locked = filled($this->quran_current_juz_number);
        $this->external_memorized_juz_ids = $student->externalMemorizedJuzs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->external_memorized_juz_input = '';
        $this->photo_path = $student->photo_path ?? '';
        $this->status = $student->status;
        $this->joined_at = $student->joined_at?->format('Y-m-d') ?? '';
        $this->notes = $student->notes ?? '';
        $this->enrollment_group_auto = false;
        $this->syncing_enrollment_group_id = false;
        $this->showFormModal = true;

        $this->resetValidation();
    }

    public function closeDuplicateStudentModal(): void
    {
        $this->showDuplicateStudentModal = false;
        $this->duplicateStudentId = null;
    }

    public function openAccountModal(int $studentId): void
    {
        $this->authorizePermission('students.update');

        $student = Student::query()->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        $this->accountStudentId = $student->id;
        $this->account_username = $student->user?->username ?? ($student->student_number ?? '');
        $this->account_email = $student->user?->email ?? '';
        $this->account_password = '';
        $this->account_is_active = $student->user?->is_active ?? ! in_array($student->status, ['inactive', 'blocked'], true);
        $this->issued_password = $student->user?->issued_password;
        $this->showAccountModal = true;

        $this->resetValidation([
            'account_username',
            'account_email',
            'account_password',
            'account_is_active',
        ]);
    }

    public function generateAccountPassword(): void
    {
        $this->authorizePermission('students.update');

        $this->account_password = app(ManagedUserService::class)->generatePassword();
    }

    public function saveAccount(): void
    {
        $this->authorizePermission('students.update');

        $student = Student::query()->findOrFail($this->accountStudentId);
        $this->authorizeScopedStudentAccess($student);

        $validated = $this->validate($this->accountRules());
        $result = app(ManagedUserService::class)->syncLinkedUser(
            $student->user,
            [
                'name' => $student->full_name,
                'username' => $validated['account_username'] ?: ($student->student_number ?: null),
                'email' => $validated['account_email'] ?: null,
                'phone' => null,
                'password' => $validated['account_password'] ?: null,
                'is_active' => (bool) $validated['account_is_active'],
            ],
            'student',
        );

        $student->user()->associate($result['user']);
        $student->save();

        $this->account_username = $result['user']->username ?? '';
        $this->account_email = $result['user']->email ?? '';
        $this->account_password = '';
        $this->account_is_active = $result['user']->is_active;
        $this->issued_password = $result['user']->issued_password;

        if ($result['credentials']['password']) {
            session()->flash('generated_credentials', $result['credentials']);
        }

        session()->flash('status', __('access.profile_accounts.messages.saved'));
    }

    public function closeAccountModal(): void
    {
        $this->accountStudentId = null;
        $this->account_username = '';
        $this->account_email = '';
        $this->account_password = '';
        $this->account_is_active = true;
        $this->issued_password = null;
        $this->showAccountModal = false;

        $this->resetValidation([
            'account_username',
            'account_email',
            'account_password',
            'account_is_active',
        ]);
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->parent_id = null;
        $this->first_name = '';
        $this->last_name = '';
        $this->student_phone = '';
        $this->birth_date = '';
        $this->gender = $this->defaultGenderCode();
        $this->school_name = '';
        $this->grade_level_id = null;
        $this->enrollment_group_id = null;
        $this->quran_current_juz_id = null;
        $this->quran_current_juz_number = '';
        $this->quran_current_juz_locked = false;
        $this->external_memorized_juz_ids = [];
        $this->external_memorized_juz_input = '';
        $this->photo_path = '';
        $this->quick_photo_upload = null;
        $this->status = 'active';
        $this->joined_at = '';
        $this->notes = '';
        $this->showFormModal = false;
        $this->showExternalTestModal = false;
        $this->external_test_juz_id = null;
        $this->external_test_type = 'partial';
        $this->showQuickParentForm = false;
        $this->quick_parent_father_name = '';
        $this->quick_parent_father_work = '';
        $this->quick_parent_new_father_work = '';
        $this->quick_parent_father_phone = '';
        $this->quick_parent_mother_name = '';
        $this->quick_parent_mother_phone = '';
        $this->quick_parent_home_phone = '';
        $this->quick_parent_address = '';
        $this->new_school_name = '';
        $this->enrollment_group_auto = true;
        $this->syncing_enrollment_group_id = false;

        $this->resetValidation();
    }

    protected function syncDefaultEnrollmentGroup(): void
    {
        $defaultGroupId = $this->defaultEnrollmentGroupIdForGrade($this->grade_level_id);

        if ($this->enrollment_group_id === $defaultGroupId) {
            $this->syncing_enrollment_group_id = false;

            return;
        }

        $this->syncing_enrollment_group_id = true;
        $this->enrollment_group_id = $defaultGroupId;
    }

    protected function syncGradeLevelFromBirthYear(): void
    {
        $birthDate = $this->normalizeBirthYearValue($this->birth_date);

        if (! $birthDate) {
            $this->grade_level_id = null;

            if (! $this->editingId && $this->enrollment_group_auto) {
                $this->syncDefaultEnrollmentGroup();
            }

            return;
        }

        $academicYear = AcademicYear::query()
            ->where('is_current', true)
            ->orderByDesc('starts_on')
            ->first(['starts_on']);
        $referenceYear = (int) ($academicYear?->starts_on?->format('Y') ?: now()->format('Y'));
        $age = $referenceYear - (int) substr($birthDate, 0, 4);
        $sortOrder = match (true) {
            $age <= 4 => 1,
            $age === 5 => 2,
            $age >= 6 && $age <= 17 => $age + 5,
            $age >= 18 => 30,
            default => null,
        };

        $this->grade_level_id = $sortOrder === null
            ? null
            : GradeLevel::query()
                ->where('is_active', true)
                ->where('sort_order', $sortOrder)
                ->value('id');

        if (! $this->editingId && $this->enrollment_group_auto) {
            $this->syncDefaultEnrollmentGroup();
        }
    }

    protected function defaultEnrollmentGroupIdForGrade(?int $gradeLevelId): ?int
    {
        if (! $gradeLevelId) {
            return null;
        }

        $baseQuery = $this->scopeGroupsQuery(
            Group::query()
                ->where('is_active', true)
                ->where('grade_level_id', $gradeLevelId)
        );

        $currentAcademicYearId = AcademicYear::query()
            ->where('is_current', true)
            ->value('id');

        if ($currentAcademicYearId) {
            $currentGroupId = (clone $baseQuery)
                ->where('academic_year_id', $currentAcademicYearId)
                ->orderBy('name')
                ->value('id');

            if ($currentGroupId) {
                return (int) $currentGroupId;
            }
        }

        $groupId = (clone $baseQuery)
            ->orderByDesc('academic_year_id')
            ->orderBy('name')
            ->value('id');

        return $groupId ? (int) $groupId : null;
    }

    public function createSchoolShortcut(): void
    {
        $this->authorizePermission('students.create');
        $this->new_school_name = trim($this->school_name);

        $existingSchool = School::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($this->new_school_name)])
            ->first();
        if ($existingSchool) {
            $existingSchool->update(['is_active' => true]);
            $this->school_name = $existingSchool->name;
            $this->new_school_name = '';
            return;
        }

        $validated = $this->validate([
            'new_school_name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')],
        ], [], [
            'new_school_name' => __('crud.students.form.fields.school'),
        ]);

        $school = School::query()->create([
            'name' => trim($validated['new_school_name']),
            'is_active' => true,
        ]);

        $this->school_name = $school->name;
        $this->new_school_name = '';
        $this->resetValidation('new_school_name');
    }

    public function createQuickFatherJobShortcut(): void
    {
        $this->authorizePermission('parents.create');
        $this->quick_parent_new_father_work = trim($this->quick_parent_father_work);

        $existingJob = FatherJob::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($this->quick_parent_new_father_work)])
            ->first();
        if ($existingJob) {
            $existingJob->update(['is_active' => true]);
            $this->quick_parent_father_work = $existingJob->name;
            $this->quick_parent_new_father_work = '';
            return;
        }

        $validated = $this->validate([
            'quick_parent_new_father_work' => ['required', 'string', 'max:255', Rule::unique('father_jobs', 'name')],
        ], [], [
            'quick_parent_new_father_work' => __('crud.parents.form.fields.father_work'),
        ]);

        $job = FatherJob::query()->create([
            'name' => trim($validated['quick_parent_new_father_work']),
            'is_active' => true,
        ]);

        $this->quick_parent_father_work = $job->name;
        $this->quick_parent_new_father_work = '';
        $this->resetValidation('quick_parent_new_father_work');
    }

    public function uploadStudentPhoto(int $studentId): void
    {
        $this->authorizePermission('students.photo.update');
        $student = Student::query()->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        $validated = $this->validate([
            'quick_photo_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.image_max_kb')],
        ]);
        $path = $validated['quick_photo_upload']->store('students/photos/'.$student->id, 'public');
        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
        }
        $student->update(['photo_path' => $path]);
        $this->reset('quick_photo_upload');
        session()->flash('status', __('media.student_files.messages.photo_updated'));
    }

    public function openExternalTestModal(): void
    {
        $this->authorizePermission('students.update');
        abort_unless($this->editingId, 404);
        $this->external_test_juz_id = count($this->external_memorized_juz_ids) === 1
            ? (int) $this->external_memorized_juz_ids[0]
            : null;
        $this->external_test_type = 'partial';
        $this->showExternalTestModal = true;
        $this->resetValidation(['external_test_juz_id', 'external_test_type']);
    }

    public function closeExternalTestModal(): void
    {
        $this->showExternalTestModal = false;
        $this->external_test_juz_id = null;
        $this->external_test_type = 'partial';
        $this->resetValidation(['external_test_juz_id', 'external_test_type']);
    }

    public function createExternalMemorizationTest(): void
    {
        abort_unless($this->editingId, 404);
        $validated = $this->validate([
            'external_test_juz_id' => ['required', 'integer', Rule::in(array_map('intval', $this->external_memorized_juz_ids))],
            'external_test_type' => ['required', Rule::in(['partial', 'final'])],
        ]);
        $permission = $validated['external_test_type'] === 'partial' ? 'quran-partial-tests.record' : 'quran-final-tests.record';
        $this->authorizePermission($permission);

        $student = Student::query()->with('externalMemorizedJuzs')->findOrFail($this->editingId);
        $this->authorizeScopedStudentAccess($student);
        $enrollment = $student->enrollments()->with(['student.externalMemorizedJuzs', 'group.course'])
            ->where('status', 'active')->latest('enrolled_at')->latest('id')->first();
        if (! $enrollment) {
            $this->addError('external_test_juz_id', __('workflow.memorization.errors.no_active_enrollment'));
            return;
        }
        $juz = QuranJuz::query()->findOrFail((int) $validated['external_test_juz_id']);

        try {
            $test = $validated['external_test_type'] === 'partial'
                ? app(QuranPartialTestService::class)->createForExternalMemorization($enrollment, $juz)
                : app(QuranFinalTestService::class)->createForExternalMemorization($enrollment, $juz);
        } catch (\LogicException $exception) {
            $this->addError('external_test_juz_id', $exception->getMessage());
            return;
        }

        $route = $validated['external_test_type'] === 'partial' ? 'quran-partial-tests.show' : 'quran-final-tests.show';
        $this->closeExternalTestModal();
        $this->cancel();
        $this->redirect(route($route, $test), navigate: true);
    }

    protected function defaultGenderCode(): string
    {
        $defaultGenderCode = StudentGender::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('code');

        if ($defaultGenderCode) {
            return (string) $defaultGenderCode;
        }

        return (string) (StudentGender::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('code') ?? '');
    }

    protected function applyStudentSearch(Builder $query, string $search): void
    {
        $normalizedSearch = '%'.$this->normalizeArabicSearch($search).'%';
        $rawSearch = '%'.trim($search).'%';
        $normalizedFullName = $this->normalizedSqlExpression($this->sqlConcatWithSpaces(['first_name', 'last_name']));
        $normalizedFirstName = $this->normalizedSqlExpression('coalesce(first_name, \'\')');
        $normalizedLastName = $this->normalizedSqlExpression('coalesce(last_name, \'\')');

        $query->where(function (Builder $builder) use (
            $normalizedFirstName,
            $normalizedFullName,
            $normalizedLastName,
            $normalizedSearch,
            $rawSearch
        ): void {
            $builder
                ->whereRaw($normalizedFirstName.' like ?', [$normalizedSearch])
                ->orWhereRaw($normalizedLastName.' like ?', [$normalizedSearch])
                ->orWhereRaw($normalizedFullName.' like ?', [$normalizedSearch])
                ->orWhere('student_number', 'like', $rawSearch);
        });
    }

    protected function applyStudentSort(Builder $query): void
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortField) {
            'enrollments' => $query->orderBy('enrollments_count', $direction),
            'grade' => $query->orderBy(
                GradeLevel::query()
                    ->select('name')
                    ->whereColumn('grade_levels.id', 'students.grade_level_id')
                    ->limit(1),
                $direction,
            ),
            'juz' => $query->orderBy(
                QuranJuz::query()
                    ->select('juz_number')
                    ->whereColumn('quran_juzs.id', 'students.quran_current_juz_id')
                    ->limit(1),
                $direction,
            ),
            'parent' => $query->orderBy(
                ParentProfile::query()
                    ->select('father_name')
                    ->whereColumn('parents.id', 'students.parent_id')
                    ->limit(1),
                $direction,
            ),
            'status' => $query->orderByRaw(
                "case status when 'active' then 1 when 'inactive' then 2 when 'graduated' then 3 when 'blocked' then 4 else 5 end {$direction}",
            ),
            'student_number' => $query->orderBy('student_number', $direction),
            default => $query
                ->orderBy('first_name', $direction)
                ->orderBy('last_name', $direction),
        };

        if ($this->sortField !== 'student') {
            $query->orderBy('first_name')->orderBy('last_name');
        }

        $query->orderBy('id');
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
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

    protected function bulkStatusPreview(): array
    {
        $targets = $this->targetStudentIdsForBulkStatus(false);

        if ($targets === []) {
            return ['profiles' => 0, 'accounts' => 0];
        }

        $accounts = $this->bulk_sync_accounts
            ? User::query()
                ->whereIn('id', Student::query()->whereIn('id', $targets)->whereNotNull('user_id')->pluck('user_id'))
                ->where('is_active', $this->bulk_status_action !== 'activate')
                ->count()
            : 0;

        return [
            'profiles' => count($targets),
            'accounts' => $accounts,
        ];
    }

    protected function targetStudentIdsForBulkStatus(bool $withValidation = true): array
    {
        $query = $this->bulkStatusStudentQuery($withValidation);

        if (! $query) {
            return [];
        }

        return $query->pluck('id')->all();
    }

    protected function bulkStatusStudentQuery(bool $withValidation = true): ?Builder
    {
        $query = $this->scopeStudentsQuery(Student::query());

        if ($this->bulk_scope === 'student_number_range') {
            [$from, $to] = $this->studentNumberRangeBounds($withValidation);

            if ($from === null && $to === null) {
                return null;
            }

            if ($from !== null) {
                $query->where('id', '>=', $from);
            }

            if ($to !== null) {
                $query->where('id', '<=', $to);
            }
        } elseif ($this->bulk_scope === 'course') {
            if (! $this->bulk_course_id) {
                if ($withValidation) {
                    $this->addError('bulk_course_id', __('crud.students.bulk_status.errors.course_required'));
                }

                return null;
            }

            $query->whereHas('enrollments', function (Builder $enrollmentQuery): void {
                $enrollmentQuery
                    ->where('status', 'active')
                    ->whereHas('group', fn (Builder $groupQuery) => $groupQuery->where('course_id', $this->bulk_course_id));
            });
        } elseif ($this->bulk_scope === 'group') {
            if (! $this->bulk_group_id) {
                if ($withValidation) {
                    $this->addError('bulk_group_id', __('crud.students.bulk_status.errors.group_required'));
                }

                return null;
            }

            $query->whereHas('enrollments', fn (Builder $enrollmentQuery) => $enrollmentQuery
                ->where('status', 'active')
                ->where('group_id', $this->bulk_group_id));
        }

        return $query->where('status', $this->bulk_status_action === 'activate' ? 'inactive' : 'active');
    }

    protected function studentNumberRangeBounds(bool $withValidation = true): array
    {
        $from = $this->parseStudentNumberInput($this->bulk_student_number_from);
        $to = $this->parseStudentNumberInput($this->bulk_student_number_to);

        if ($from === null && $to === null) {
            if ($withValidation) {
                $this->addError('bulk_student_number_from', __('crud.students.bulk_status.errors.number_range_required'));
            }

            return [null, null];
        }

        if ((filled($this->bulk_student_number_from) && $from === null) || (filled($this->bulk_student_number_to) && $to === null)) {
            if ($withValidation) {
                $this->addError('bulk_student_number_from', __('crud.students.bulk_status.errors.invalid_number_range'));
            }

            return [null, null];
        }

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    protected function parseStudentNumberInput(string $value): ?int
    {
        return app(StudentNumberService::class)->parseInputToId($value);
    }

    public function delete(int $studentId): void
    {
        $this->authorizePermission('students.delete');

        $student = Student::query()
            ->with('user')
            ->withCount(['enrollments', 'memorizationSessions', 'pageAchievements'])
            ->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);

        if ($student->enrollments_count > 0) {
            $this->addError('delete', __('crud.students.errors.delete_linked'));

            return;
        }

        if (($student->memorization_sessions_count + $student->page_achievements_count) > 0) {
            $this->addError('delete', __('crud.students.errors.delete_memorization'));

            return;
        }

        $linkedUser = $student->user;
        $student->delete();
        $linkedUser?->delete();

        if ($this->editingId === $studentId) {
            $this->cancel();
        }

        session()->flash('status', __('crud.students.messages.deleted'));
    }

    protected function isValidBirthYearValue(string $value): bool
    {
        return $this->normalizeBirthYearValue($value) !== null;
    }

    protected function normalizeBirthYearValue(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}$/', $value) === 1) {
            $year = (int) $value;

            return $year >= 1900 && $year <= ((int) now()->format('Y') + 1)
                ? $value.'-01-01'
                : null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            [$year, $month, $day] = array_map('intval', explode('-', $value));

            return checkdate($month, $day, $year) ? $value : null;
        }

        return null;
    }

    protected function findDuplicateStudent(array $validated): ?Student
    {
        $firstName = ArabicSearch::normalizeForDuplicate((string) ($validated['first_name'] ?? ''));
        $lastName = ArabicSearch::normalizeForDuplicate((string) ($validated['last_name'] ?? ''));
        $birthDate = $this->normalizeBirthYearValue((string) ($validated['birth_date'] ?? ''));
        $birthYear = $birthDate ? (int) substr($birthDate, 0, 4) : null;

        if ($firstName === '' || $lastName === '' || ! $birthYear) {
            return null;
        }

        return $this->scopeStudentsQuery(
            Student::query()
                ->when($this->editingId, fn (Builder $query) => $query->whereKeyNot($this->editingId))
                ->whereYear('birth_date', $birthYear)
                ->orderByDesc('id')
        )
            ->get()
            ->first(function (Student $student) use ($firstName, $lastName): bool {
                return ArabicSearch::normalizeForDuplicate($student->first_name) === $firstName
                    && ArabicSearch::normalizeForDuplicate($student->last_name) === $lastName;
            });
    }

    protected function showActiveDuplicateStudent(Student $student): void
    {
        $this->duplicateStudentId = $student->id;
        $this->showDuplicateStudentModal = true;
    }

    protected function findDuplicateParent(array $validated): ?ParentProfile
    {
        $fatherName = ArabicSearch::normalizeForDuplicate((string) ($validated['father_name'] ?? ''));
        $motherName = ArabicSearch::normalizeForDuplicate((string) ($validated['mother_name'] ?? ''));
        $phones = collect([
            $validated['father_phone'] ?? null,
            $validated['mother_phone'] ?? null,
            $validated['home_phone'] ?? null,
        ])
            ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone) ?: null)
            ->filter()
            ->values();

        if ($fatherName === '' && $phones->isEmpty()) {
            return null;
        }

        return $this->scopeParentsQuery(
            ParentProfile::query()
                ->orderByDesc('id')
        )
            ->get()
            ->first(function (ParentProfile $parent) use ($fatherName, $motherName, $phones): bool {
                $parentPhones = collect([$parent->father_phone, $parent->mother_phone, $parent->home_phone])
                    ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone) ?: null)
                    ->filter();

                $phoneMatches = $phones->isNotEmpty() && $phones->intersect($parentPhones)->isNotEmpty();
                $fatherMatches = $fatherName !== '' && ArabicSearch::normalizeForDuplicate($parent->father_name) === $fatherName;
                $motherMatches = $motherName !== '' && ArabicSearch::normalizeForDuplicate((string) $parent->mother_name) === $motherName;

                return $phoneMatches || ($fatherMatches && $motherName !== '' && $motherMatches);
            });
    }

    protected function linkedUserId(): ?int
    {
        $profileId = $this->accountStudentId ?? $this->editingId;

        return $profileId
            ? Student::query()->whereKey($profileId)->value('user_id')
            : null;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('crud.students.hero.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('crud.students.hero.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('crud.students.hero.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('generated_credentials'))
        <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            {{ __('access.profile_accounts.messages.credentials', session('generated_credentials')) }}
        </div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('crud.students.table.title') }}</div>
            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label class="sr-only" for="student-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="student-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.students.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="student-status-filter">{{ __('crud.common.filters.status') }}</label>
                    <select id="student-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        @foreach ($statuses as $studentStatus)
                            <option value="{{ $studentStatus }}">{{ __('crud.common.status_options.'.$studentStatus) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('students.create')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.create') }}</button>
                    @endcan
                    <a href="{{ route('students.export', ['search' => $search, 'status' => $statusFilter]) }}" class="pill-link">{{ __('crud.common.actions.export') }}</a>
                </div>
            </div>
        </div>

        @error('delete')
            <div class="px-6 pt-4 text-sm text-red-300">{{ $message }}</div>
        @enderror

        @if ($students->isEmpty())
            <div class="admin-empty-state">{{ __('crud.students.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.student') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('student'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('student_number')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.student_number') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('student_number'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('parent')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.parent') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('parent'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('grade')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.grade') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('grade'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('juz')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.juz') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('juz'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('enrollments')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.enrollments') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('enrollments'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    <span>{{ __('crud.students.table.headers.status') }}</span>
                                    @if ($sortIndicator = $this->sortIndicator('status'))
                                        <span aria-hidden="true">{{ $sortIndicator }}</span>
                                    @endif
                                </button>
                            </th>
                            @if (auth()->user()->can('students.view') || auth()->user()->can('students.update') || auth()->user()->can('students.delete'))
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.students.table.headers.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($students as $student)
                            @php
                                $studentStatusClass = match ($student->status) {
                                    'active' => 'status-chip status-chip--emerald',
                                    'graduated' => 'status-chip status-chip--gold',
                                    'blocked' => 'status-chip status-chip--rose',
                                    default => 'status-chip status-chip--slate',
                                };
                            @endphp
                            <tr>
                                  <td class="px-5 py-4 lg:px-6">
                                      <div class="student-inline">
                                          <x-student-avatar :student="$student" size="sm" />
                                          <div class="student-inline__body">
                                              <div class="student-inline__name">{{ $student->full_name }}</div>
                                              <div class="student-inline__meta">{{ $student->school_name ?: __('crud.students.table.no_school') }}</div>
                                          </div>
                                      </div>
                                  </td>
                                  <td class="px-5 py-4 font-mono text-white lg:px-6">{{ $student->student_number ?: $student->id }}</td>
                                   <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $student->parentProfile?->father_name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $student->gradeLevel?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $student->quranCurrentJuz ? __('crud.students.labels.juz_number', ['number' => $student->quranCurrentJuz->juz_number]) : __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $student->enrollments_count }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $studentStatusClass }}">{{ __('crud.common.status_options.'.$student->status) }}</span></td>
                                @if (auth()->user()->can('students.view') || auth()->user()->can('students.update') || auth()->user()->can('students.delete'))
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex flex-nowrap justify-end gap-2 whitespace-nowrap">
                                            @can('students.update')
                                                <button type="button" wire:click="openAccountModal({{ $student->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.account') }}
                                                </button>
                                            @endcan
                                            @can('students.photo.update')
                                                <label class="pill-link pill-link--compact cursor-pointer">
                                                    {{ __('media.student_files.photo.upload') }}
                                                    <input wire:model="quick_photo_upload" wire:change="uploadStudentPhoto({{ $student->id }})" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
                                                </label>
                                            @endcan
                                            @can('students.update')
                                                <button type="button" wire:click="edit({{ $student->id }})" class="pill-link pill-link--compact">
                                                    {{ __('crud.common.actions.edit') }}
                                                </button>
                                            @endcan
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($students->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $students->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showBulkStatusModal"
        :title="__('crud.students.bulk_status.title')"
        :description="__('crud.students.bulk_status.description')"
        close-method="closeBulkStatusModal"
        max-width="4xl"
    >
        <form wire:submit="applyBulkStatus" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.action') }}</label>
                    <select wire:model.live="bulk_status_action" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="deactivate">{{ __('crud.common.actions.deactivate') }}</option>
                        <option value="activate">{{ __('crud.common.actions.activate') }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.scope') }}</label>
                    <select wire:model.live="bulk_scope" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="all">{{ __('crud.students.bulk_status.scopes.all') }}</option>
                        <option value="student_number_range">{{ __('crud.students.bulk_status.scopes.student_number_range') }}</option>
                        <option value="course">{{ __('crud.students.bulk_status.scopes.course') }}</option>
                        <option value="group">{{ __('crud.students.bulk_status.scopes.group') }}</option>
                    </select>
                </div>
            </div>

            @if ($bulk_scope === 'student_number_range')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.number_from') }}</label>
                        <input wire:model.live="bulk_student_number_from" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.students.bulk_status.placeholders.number_from') }}">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.number_to') }}</label>
                        <input wire:model.live="bulk_student_number_to" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.students.bulk_status.placeholders.number_to') }}">
                    </div>
                </div>
            @elseif ($bulk_scope === 'course')
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.course') }}</label>
                    <select wire:model.live="bulk_course_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.common.filters.all_courses') }}</option>
                        @foreach ($bulkCourses as $bulkCourse)
                            <option value="{{ $bulkCourse->id }}">{{ $bulkCourse->name }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif ($bulk_scope === 'group')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.course') }}</label>
                        <select wire:model.live="bulk_course_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('crud.common.filters.all_courses') }}</option>
                            @foreach ($bulkCourses as $bulkCourse)
                                <option value="{{ $bulkCourse->id }}">{{ $bulkCourse->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.students.bulk_status.fields.group') }}</label>
                        <select wire:model.live="bulk_group_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('crud.common.filters.all_groups') }}</option>
                            @foreach ($bulkGroups as $bulkGroup)
                                <option value="{{ $bulkGroup->id }}">{{ $bulkGroup->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif

            <label class="flex items-center gap-3 text-sm">
                <input wire:model="bulk_sync_accounts" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                <span>{{ __('crud.students.bulk_status.fields.sync_accounts') }}</span>
            </label>

            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-neutral-200">
                <div>{{ __('crud.students.bulk_status.preview.profiles', ['count' => number_format($bulkStatusPreview['profiles'])]) }}</div>
                <div class="mt-1 text-neutral-400">{{ __('crud.students.bulk_status.preview.accounts', ['count' => number_format($bulkStatusPreview['accounts'])]) }}</div>
                <div class="mt-2 text-xs text-neutral-500">{{ __('crud.students.bulk_status.help') }}</div>
            </div>

            @error('bulk_status')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror
            @error('bulk_student_number_from')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror
            @error('bulk_course_id')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror
            @error('bulk_group_id')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror

            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="closeBulkStatusModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('crud.common.actions.bulk_status') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showDuplicateStudentModal"
        :title="__('crud.students.duplicate_active.title')"
        :description="__('crud.students.duplicate_active.description')"
        close-method="closeDuplicateStudentModal"
        max-width="3xl"
    >
        @if ($duplicateStudent)
            @php
                $duplicateEnrollment = $duplicateStudent->enrollments->first();
                $duplicateFields = [
                    __('crud.students.form.fields.student_number') => ($duplicateStudent->student_number ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.first_name') => $duplicateStudent->first_name,
                    __('crud.students.form.fields.last_name') => $duplicateStudent->last_name,
                    __('crud.students.form.fields.phone') => ($duplicateStudent->user?->phone ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.birth_year') => ($duplicateStudent->birth_date?->format('Y') ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.gender') => ($duplicateStudent->gender ? __('crud.common.gender_options.'.$duplicateStudent->gender) : __('crud.common.not_available')),
                    __('crud.students.form.fields.parent') => ($duplicateStudent->parentProfile?->father_name ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.school') => ($duplicateStudent->school_name ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.grade_level') => ($duplicateStudent->gradeLevel?->name ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.group') => ($duplicateEnrollment?->group?->name ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.current_juz') => ($duplicateStudent->quranCurrentJuz?->juz_number ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.status') => __('crud.common.status_options.'.$duplicateStudent->status),
                    __('crud.students.form.fields.joined_at') => ($duplicateStudent->joined_at?->format('d-m-Y') ?: __('crud.common.not_available')),
                    __('crud.students.form.fields.notes') => ($duplicateStudent->notes ?: __('crud.common.not_available')),
                ];
            @endphp
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($duplicateFields as $label => $value)
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3">
                        <div class="kpi-label">{{ $label }}</div>
                        <div class="mt-2 text-sm font-semibold text-white">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="closeDuplicateStudentModal" class="pill-link pill-link--accent">{{ __('crud.common.actions.close') }}</button>
            </div>
        @endif
    </x-admin.modal>

    <x-admin.modal
        :show="$showFormModal"
        :title="$editingId ? __('crud.students.form.edit_title') : __('crud.students.form.create_title')"
        :description="__('crud.students.form.help')"
        close-method="cancel"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-3" data-student-identity-row>
                <div>
                    <label for="student-first-name" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.first_name') }}</label>
                    <input id="student-first-name" wire:model="first_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('first_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-last-name" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.last_name') }}</label>
                    <input id="student-last-name" wire:model="last_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('last_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-phone" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.phone') }}</label>
                    <div class="flex items-center gap-2">
                        <div class="min-w-0 flex-1"><x-phone-input id="student-phone" model="student_phone" :value="$student_phone" /></div>
                        @if ($editingId)
                            <a href="{{ route('students.files', $editingId) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.media') }}</a>
                        @endif
                    </div>
                    @error('student_phone')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @php
                $connectedParent = $parent_id ? $parents->firstWhere('id', (int) $parent_id) : null;
            @endphp
            @unless ($showQuickParentForm || $parent_id)
                <div data-student-parent-row>
                    <label for="student-parent" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.parent') }}</label>
                    <div class="flex items-center gap-2">
                        <select
                            id="student-parent"
                            wire:model.live="parent_id"
                            data-search-hint-target="student-last-name"
                            data-search-input="true"
                            data-open-on-focus="true"
                            data-hide-placeholder-option="true"
                            data-search-placeholder="{{ __('crud.students.form.placeholders.select_parent') }}"
                            class="min-w-0 flex-1 rounded-xl px-4 py-3 text-sm"
                        >
                            <option value="">{{ __('crud.students.form.placeholders.select_parent') }}</option>
                            @foreach ($parents as $parent)
                                @php
                                    $studentLastNames = $parent->students->pluck('last_name')->filter()->unique()->values();
                                    $parentSearch = collect([
                                        $parent->father_name,
                                        $parent->mother_name,
                                        $parent->father_phone,
                                        $parent->mother_phone,
                                        $parent->home_phone,
                                        $studentLastNames->implode(' '),
                                    ])->filter()->implode(' ');
                                @endphp
                                <option value="{{ $parent->id }}" data-search="{{ $parentSearch }}">
                                    {{ $parent->father_name }}
                                </option>
                            @endforeach
                        </select>
                        @can('parents.create')
                            <button type="button" wire:click="openQuickParentForm" class="pill-link pill-link--compact shrink-0">
                                +
                            </button>
                        @endcan
                    </div>
                    @error('parent_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endunless

            @if (! $editingId && $parent_id && ! $showQuickParentForm)
                <div data-student-parent-locked>
                    <label class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.parent') }}</label>
                    <div class="flex h-[2.875rem] w-full items-center rounded-xl border border-white/10 bg-black/10 px-4 text-sm">
                        <span>{{ $connectedParent?->father_name ?: __('crud.common.not_available') }}</span>
                        <button type="button" wire:click="clearSelectedParent" class="ms-auto inline-flex size-7 shrink-0 items-center justify-center rounded-full text-lg leading-none text-white/65 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('crud.common.actions.delete') }}">×</button>
                    </div>
                </div>
            @endif

            @if ($editingId && $parent_id)
                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-white">{{ __('crud.students.form.parent_shortcut.edit_title') }}</div>
                            <div class="mt-1 text-sm text-neutral-400">{{ $connectedParent?->father_name }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @can('parents.update')
                                <button type="button" wire:click="{{ $showQuickParentForm ? 'closeQuickParentForm' : 'openQuickParentForm' }}" class="pill-link pill-link--compact">
                                    {{ $showQuickParentForm ? __('crud.students.form.parent_shortcut.cancel') : __('crud.common.actions.edit') }}
                                </button>
                            @endcan
                            <button type="button" wire:click="removeParentRelationship" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact pill-link--danger">
                                {{ __('crud.students.form.parent_shortcut.remove_relationship') }}
                            </button>
                        </div>
                    </div>
                    @unless ($showQuickParentForm)
                        <p class="mt-3 text-sm text-neutral-400">{{ __('crud.students.form.parent_shortcut.edit_help') }}</p>
                    @endunless
                </div>
            @endif

            @if ($showQuickParentForm)
                <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold text-white">{{ $editingId && $parent_id ? __('crud.students.form.parent_shortcut.edit_title') : __('crud.students.form.parent_shortcut.title') }}</div>
                        <button type="button" wire:click="closeQuickParentForm" class="grid size-8 shrink-0 place-items-center rounded-full border border-white/10 text-lg text-neutral-300 transition hover:border-white/20 hover:bg-white/5 hover:text-white" aria-label="{{ __('crud.common.actions.close') }}">&times;</button>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_name') }}</label>
                            <input wire:model="quick_parent_father_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_father_name')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_phone') }}</label>
                            <x-phone-input model="quick_parent_father_phone" :value="$quick_parent_father_phone" />
                            @error('quick_parent_father_phone')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.father_work') }}</label>
                            <div class="flex gap-2">
                                <input wire:model.live.debounce.300ms="quick_parent_father_work" list="father-job-options" class="min-w-0 flex-1 rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.parents.form.placeholders.new_father_work') }}">
                                @if (filled($quick_parent_father_work) && ! $fatherJobs->contains(fn ($job) => strcasecmp($job->name, trim($quick_parent_father_work)) === 0))
                                    <button type="button" wire:click="createQuickFatherJobShortcut" class="pill-link pill-link--compact" title="{{ __('crud.common.actions.create') }}" aria-label="{{ __('crud.common.actions.create') }}">+</button>
                                @endif
                            </div>
                            <datalist id="father-job-options">
                                @foreach ($fatherJobs as $fatherJob)
                                    <option value="{{ $fatherJob->name }}">{{ $fatherJob->name }}</option>
                                @endforeach
                            </datalist>
                            @error('quick_parent_father_work')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                            @error('quick_parent_new_father_work')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.home_phone') }}</label>
                            <x-phone-input model="quick_parent_home_phone" :value="$quick_parent_home_phone" />
                            @error('quick_parent_home_phone')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.mother_name') }}</label>
                            <input wire:model="quick_parent_mother_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('quick_parent_mother_name')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.mother_phone') }}</label>
                            <x-phone-input model="quick_parent_mother_phone" :value="$quick_parent_mother_phone" />
                            @error('quick_parent_mother_phone')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="mb-1 block text-sm font-medium">{{ __('crud.parents.form.fields.address') }}</label>
                        <input wire:model="quick_parent_address" type="text" placeholder="{{ __('crud.parents.form.placeholders.address') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('quick_parent_address')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="saveQuickParent" class="pill-link pill-link--accent">
                            {{ __('crud.students.form.parent_shortcut.save') }}
                        </button>
                    </div>
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="student-birth-date" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.birth_year') }}</label>
                    <input id="student-birth-date" wire:model.live.debounce.350ms="birth_date" type="number" min="1900" max="{{ now()->format('Y') + 1 }}" step="1" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('birth_date')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-grade-level" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.grade_level') }}</label>
                    <select id="student-grade-level" wire:model="grade_level_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.students.form.placeholders.select_grade') }}</option>
                    @foreach ($gradeLevels as $gradeLevel)
                        <option value="{{ $gradeLevel->id }}">{{ $gradeLevel->name }}</option>
                    @endforeach
                    </select>
                    @error('grade_level_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="student-school" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.school') }}</label>
                    <div class="flex gap-2">
                        <input id="student-school" wire:model.live.debounce.300ms="school_name" list="student-school-options" class="min-w-0 flex-1 rounded-xl px-4 py-3 text-sm" placeholder="{{ __('crud.students.form.placeholders.select_school') }}">
                        @if (filled($school_name) && ! $schools->contains(fn ($school) => strcasecmp($school->name, trim($school_name)) === 0))
                            <button type="button" wire:click="createSchoolShortcut" class="pill-link pill-link--compact" title="{{ __('crud.students.form.add_new_school') }}" aria-label="{{ __('crud.students.form.add_new_school') }}">+</button>
                        @endif
                    </div>
                    <datalist id="student-school-options">
                        @foreach ($schools as $school)
                            <option value="{{ $school->name }}">{{ $school->name }}</option>
                        @endforeach
                    </datalist>
                    @error('school_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    @error('new_school_name')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2" data-student-juz-row>
                <div>
                    <label for="student-juz" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.current_juz') }}</label>
                    @if ($quran_current_juz_locked)
                        @php
                            $displayCurrentJuzNumber = app()->getLocale() === 'ar'
                                ? strtr($quran_current_juz_number, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'])
                                : $quran_current_juz_number;
                        @endphp
                        <div wire:key="student-current-juz-locked" class="flex h-[2.875rem] w-full items-center rounded-xl border border-white/10 bg-black/10 px-4 text-sm" data-current-juz-locked>
                            <span>{{ __('crud.students.labels.juz_number', ['number' => $displayCurrentJuzNumber]) }}</span>
                            <button type="button" wire:click="clearCurrentJuz" class="ms-auto inline-flex size-7 shrink-0 items-center justify-center rounded-full text-lg leading-none text-white/65 transition hover:bg-white/10 hover:text-white" aria-label="{{ __('crud.common.actions.delete') }}">×</button>
                        </div>
                    @else
                        <input id="student-juz" wire:key="student-current-juz-input" wire:model="quran_current_juz_number" wire:blur="commitCurrentJuz" wire:keydown.enter.prevent="commitCurrentJuz" x-on:focus-current-juz.window="$nextTick(() => $el.focus())" type="number" inputmode="numeric" min="1" max="30" step="1" class="h-[2.875rem] w-full rounded-xl px-4 py-0 text-sm" placeholder="{{ __('crud.students.form.placeholders.select_juz') }}" data-current-juz-input>
                    @endif
                    @error('quran_current_juz_number')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    @error('quran_current_juz_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="min-w-0">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <label for="student-external-juz" class="block text-sm font-medium">{{ __('crud.students.form.fields.external_memorized_juzs') }}</label>
                        @if ($editingId && $external_memorized_juz_ids !== [] && (auth()->user()->can('quran-partial-tests.record') || auth()->user()->can('quran-final-tests.record')))
                            <button type="button" wire:click="openExternalTestModal" class="pill-link pill-link--compact" title="{{ __('crud.students.external_tests.add') }}" aria-label="{{ __('crud.students.external_tests.add') }}">+</button>
                        @endif
                    </div>
                    <div class="flex min-h-[2.875rem] w-full flex-wrap items-center gap-2 rounded-xl border border-white/10 bg-black/10 px-3 py-1.5 focus-within:border-emerald-400/45 focus-within:ring-2 focus-within:ring-emerald-400/10" data-memorized-juz-input>
                        @foreach (collect($juzs)->whereIn('id', array_map('intval', $external_memorized_juz_ids)) as $juz)
                            <span wire:key="student-memorized-juz-{{ $juz->id }}" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300/20 bg-emerald-500/12 px-2 py-1 text-xs font-medium text-emerald-100">
                                {{ __('crud.students.labels.juz_number', ['number' => $juz->juz_number]) }}
                                <button type="button" wire:click="removeExternalMemorizedJuz({{ $juz->id }})" class="inline-flex size-4 items-center justify-center rounded-full text-sm leading-none text-emerald-200 hover:bg-white/10 hover:text-white" aria-label="{{ __('crud.common.actions.delete') }}">×</button>
                            </span>
                        @endforeach
                        <input id="student-external-juz" wire:model="external_memorized_juz_input" wire:keydown.tab="addExternalMemorizedJuz" wire:keydown.enter.prevent="addExternalMemorizedJuz" type="text" inputmode="numeric" autocomplete="off" class="min-w-28 flex-1 border-0 bg-transparent px-1 py-1 text-sm outline-none ring-0 focus:border-0 focus:ring-0" placeholder="{{ $external_memorized_juz_ids === [] ? __('crud.students.form.placeholders.enter_memorized_juz') : '' }}">
                    </div>
                    @error('external_memorized_juz_input')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    @error('external_memorized_juz_ids.*')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if (! $editingId)
                <div data-student-enrollment-group-field>
                    <label for="student-enrollment-group" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.group') }}</label>
                    <select id="student-enrollment-group" wire:model="enrollment_group_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('crud.students.form.placeholders.select_group') }}</option>
                        @foreach ($enrollmentGroups as $group)
                            <option value="{{ $group->id }}">
                                {{ $group->name }}
                                @if ($group->course)
                                    - {{ $group->course->name }}
                                @endif
                                @if ($group->gradeLevel)
                                    - {{ $group->gradeLevel->name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('enrollment_group_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            @if ($editingId)
                <div>
                    <label for="student-status" class="mb-1 block text-sm font-medium">{{ __('crud.students.form.fields.status') }}</label>
                    <select id="student-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                        @foreach ($statuses as $studentStatus)
                            <option value="{{ $studentStatus }}">{{ __('crud.common.status_options.'.$studentStatus) }}</option>
                        @endforeach
                    </select>
                    @error('status')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                @if ($editingId)
                    @can('students.delete')
                        <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                            {{ __('crud.common.actions.delete') }}
                        </button>
                    @endcan
                @endif
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $editingId ? __('crud.students.form.update_submit') : __('crud.students.form.create_submit') }}
                </button>
                <x-admin.create-and-new-button :show="! $editingId" />
                <button type="button" wire:click="cancel" class="pill-link">
                    {{ __('crud.common.actions.close') }}
                </button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showExternalTestModal" :title="__('crud.students.external_tests.title')" close-method="closeExternalTestModal" max-width="xl">
        <form wire:submit="createExternalMemorizationTest" class="space-y-4">
            <div class="admin-form-field">
                <label for="external-test-juz">{{ __('crud.students.external_tests.juz') }}</label>
                <select id="external-test-juz" wire:model="external_test_juz_id" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('crud.students.external_tests.select_juz') }}</option>
                    @foreach (collect($juzs)->whereIn('id', array_map('intval', $external_memorized_juz_ids)) as $juz)
                        <option value="{{ $juz->id }}">{{ __('crud.students.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                    @endforeach
                </select>
                @error('external_test_juz_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div class="admin-form-field">
                <label for="external-test-type">{{ __('crud.students.external_tests.type') }}</label>
                <select id="external-test-type" wire:model="external_test_type" class="w-full rounded-xl px-4 py-3 text-sm">
                    @can('quran-partial-tests.record')<option value="partial">{{ __('crud.students.external_tests.partial') }}</option>@endcan
                    @can('quran-final-tests.record')<option value="final">{{ __('crud.students.external_tests.final') }}</option>@endcan
                </select>
                @error('external_test_type') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeExternalTestModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('crud.students.external_tests.create') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal
        :show="$showAccountModal"
        :title="__('access.profile_accounts.title')"
        :description="__('access.profile_accounts.description')"
        close-method="closeAccountModal"
        max-width="4xl"
    >
        <form wire:submit="saveAccount" class="space-y-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-white">{{ __('access.profile_accounts.sections.identity') }}</div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.username') }}</label>
                        <input wire:model="account_username" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('account_username')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.username') }}</div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.email') }}</label>
                        <input wire:model="account_email" type="email" readonly class="w-full rounded-xl px-4 py-3 text-sm opacity-75">
                        @error('account_email')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                        <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.email') }}</div>
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-3 text-sm">
                    <input wire:model="account_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                    <span>{{ __('access.profile_accounts.fields.is_active') }}</span>
                </label>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-white">{{ __('access.profile_accounts.sections.password') }}</div>
                <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('access.profile_accounts.help.issued_password') }}</p>

                <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_auto]">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.issued_password') }}</label>
                        <input type="text" readonly value="{{ $issued_password ?: __('access.profile_accounts.empty.issued_password') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>

                    <div class="flex items-end">
                        <button type="button" wire:click="generateAccountPassword" class="pill-link pill-link--compact">{{ __('access.profile_accounts.actions.generate_password') }}</button>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium">{{ __('access.profile_accounts.fields.password') }}</label>
                    <input wire:model="account_password" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('account_password')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                    <div class="mt-1 text-xs text-neutral-500">{{ __('access.profile_accounts.help.password') }}</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('access.profile_accounts.actions.save') }}</button>
                <button type="button" wire:click="closeAccountModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>

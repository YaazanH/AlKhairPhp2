<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\QuranProgressionService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public ?int $selectedStudentId = null;
    public ?int $selectedEnrollmentId = null;
    public ?int $teacher_id = null;
    public ?int $juz_id = null;
    public ?int $quran_test_type_id = null;
    public string $tested_on = '';
    public string $score = '';
    public string $status = 'passed';
    public string $notes = '';
    public string $search = '';
    public string $statusFilter = 'all';
    public string $testTypeFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizePermission('quran-tests.view');
        $this->resetForm();
    }

    public function with(): array
    {
        $testTypes = QuranTestType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $testsQuery = $this->scopeQuranTestsQuery(
            QuranTest::query()->with([
                'student.parentProfile',
                'enrollment.group.course',
                'teacher',
                'juz',
                'type',
            ])
        )
            ->when(filled($this->search), function (Builder $query) {
                $search = '%'.$this->search.'%';

                $query->where(function (Builder $builder) use ($search) {
                    $builder
                        ->whereHas('student', function (Builder $studentQuery) use ($search) {
                            $studentQuery
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        })
                        ->orWhereHas('enrollment.group', fn (Builder $groupQuery) => $groupQuery->where('name', 'like', $search))
                        ->orWhereHas('teacher', function (Builder $teacherQuery) use ($search) {
                            $teacherQuery
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search);
                        })
                        ->orWhere('notes', 'like', $search);
                });
            })
            ->when(
                in_array($this->statusFilter, ['passed', 'failed', 'cancelled'], true),
                fn (Builder $query) => $query->where('status', $this->statusFilter)
            )
            ->when(
                $this->testTypeFilter !== 'all',
                fn (Builder $query) => $query->where('quran_test_type_id', $this->testTypeFilter)
            )
            ->latest('tested_on')
            ->latest('id');

        $studentOptions = $this->scopeStudentsQuery(
            Student::query()
                ->with(['parentProfile', 'quranCurrentJuz'])
                ->whereHas('enrollments', function (Builder $query) {
                    $this->scopeEnrollmentsQuery($query)->where('status', 'active');
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return [
            'tests' => $testsQuery->paginate($this->perPage),
            'filteredCount' => (clone $testsQuery)->count(),
            'studentOptions' => $studentOptions,
            'enrollmentOptions' => $this->availableEnrollmentsQuery()
                ->with(['group.course'])
                ->orderByDesc('enrolled_at')
                ->orderByDesc('id')
                ->get(),
            'teachers' => $this->currentTeacher()
                ? collect()
                : $this->scopeTeachersQuery(
                    Teacher::query()
                        ->whereIn('status', ['active', 'inactive'])
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                )->get(),
            'juzs' => QuranJuz::query()->orderBy('juz_number')->get(),
            'testTypes' => $testTypes,
            'currentTeacher' => $this->currentTeacher(),
            'stats' => [
                'students' => $studentOptions->count(),
                'tests' => $this->scopeQuranTestsQuery(QuranTest::query())->count(),
                'passed' => $this->scopeQuranTestsQuery(QuranTest::query()->where('status', 'passed'))->count(),
            ],
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

    public function updatedTestTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedStudentId(): void
    {
        $enrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->selectedEnrollmentId = count($enrollmentIds) === 1
            ? $enrollmentIds[0]
            : null;

        $student = $this->selectedStudentId
            ? $this->scopeStudentsQuery(Student::query())->find($this->selectedStudentId)
            : null;

        $this->juz_id = $student?->quran_current_juz_id;

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
            'juz_id',
            'quran_test_type_id',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('quran-tests.record');

        $this->resetForm();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->resetForm();
        $this->showFormModal = false;
    }

    public function save(): void
    {
        $this->authorizePermission('quran-tests.record');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'teacher_id' => [$this->currentTeacher() ? 'nullable' : 'required', 'exists:teachers,id'],
            'juz_id' => ['required', 'exists:quran_juzs,id'],
            'quran_test_type_id' => ['required', 'exists:quran_test_types,id'],
            'tested_on' => ['required', 'date'],
            'score' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['required', 'in:passed,failed,cancelled'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'selectedStudentId' => __('workflow.quran_tests.workbench.form.student'),
            'selectedEnrollmentId' => __('workflow.quran_tests.workbench.form.group'),
        ]);

        $student = $this->scopeStudentsQuery(Student::query())->findOrFail($validated['selectedStudentId']);
        $this->authorizeScopedStudentAccess($student);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.quran_tests.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            if (count($availableEnrollmentIds) > 1) {
                $this->addError('selectedEnrollmentId', __('workflow.quran_tests.errors.select_group'));

                return;
            }

            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
        }

        abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['student', 'group.teacher'])
        )->findOrFail((int) $validated['selectedEnrollmentId']);

        $teacherId = $this->currentTeacher()?->id ?: (filled($validated['teacher_id'] ?? null) ? (int) $validated['teacher_id'] : null);

        if (! $teacherId) {
            $this->addError('teacher_id', __('validation.required', ['attribute' => __('workflow.quran_tests.form.teacher')]));

            return;
        }

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $testType = QuranTestType::query()->findOrFail($validated['quran_test_type_id']);
        $progression = app(QuranProgressionService::class)->validate($enrollment, (int) $validated['juz_id'], $testType);

        if ($progression && ! auth()->user()->can('quran-tests.override-progression')) {
            $this->addError('quran_test_type_id', $progression);

            return;
        }

        QuranTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacherId,
            'juz_id' => (int) $validated['juz_id'],
            'quran_test_type_id' => (int) $validated['quran_test_type_id'],
            'tested_on' => $validated['tested_on'],
            'score' => $validated['score'] !== '' ? $validated['score'] : null,
            'status' => $validated['status'],
            'attempt_no' => app(QuranProgressionService::class)->nextAttemptNumber(
                $enrollment,
                (int) $validated['juz_id'],
                (int) $validated['quran_test_type_id'],
            ),
            'notes' => $validated['notes'] ?: null,
        ]);

        session()->flash('status', __('workflow.quran_tests.messages.saved'));

        $this->closeFormModal();
    }

    public function resetForm(): void
    {
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->teacher_id = $this->currentTeacher()?->id;
        $this->juz_id = null;
        $this->quran_test_type_id = QuranTestType::query()->where('code', 'partial')->value('id');
        $this->tested_on = now()->toDateString();
        $this->score = '';
        $this->status = 'passed';
        $this->notes = '';

        $this->resetValidation();
    }

    protected function availableEnrollmentsQuery(): Builder
    {
        return $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->where('status', 'active')
                ->when($this->selectedStudentId, fn (Builder $query) => $query->where('student_id', $this->selectedStudentId))
                ->when(! $this->selectedStudentId, fn (Builder $query) => $query->whereRaw('1 = 0'))
        );
    }

    protected function currentTeacher(): ?Teacher
    {
        return auth()->user()?->teacherProfile;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_tests.workbench.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.quran_tests.workbench.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.quran_tests.workbench.stats.students') }}: {{ number_format($stats['students']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_tests.workbench.stats.tests') }}: {{ number_format($stats['tests']) }}</span>
            <span class="badge-soft">{{ __('workflow.quran_tests.workbench.stats.passed') }}: {{ number_format($stats['passed']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.quran_tests.workbench.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.quran_tests.workbench.form.help') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="quran-tests-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="quran-tests-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="quran-tests-status-filter">{{ __('workflow.quran_tests.workbench.filters.status') }}</label>
                    <select id="quran-tests-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('workflow.quran_tests.workbench.filters.all_statuses') }}</option>
                        <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                        <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                        <option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option>
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="quran-tests-type-filter">{{ __('workflow.quran_tests.workbench.filters.test_type') }}</label>
                    <select id="quran-tests-type-filter" wire:model.live="testTypeFilter">
                        <option value="all">{{ __('workflow.quran_tests.workbench.filters.all_types') }}</option>
                        @foreach ($testTypes as $testType)
                            <option value="{{ $testType->id }}">{{ $testType->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('quran-tests.record')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.quran_tests.workbench.create') }}</button>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.quran_tests.workbench.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($tests->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.quran_tests.workbench.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.juz') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.type') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.score') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.teacher') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($tests as $test)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    @if ($test->student)
                                        <div class="student-inline">
                                            <x-student-avatar :student="$test->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name">{{ $test->student->first_name }} {{ $test->student->last_name }}</div>
                                                <div class="student-inline__meta">{{ $test->student->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-white">{{ __('crud.common.not_available') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="font-medium text-white">{{ $test->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $test->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->tested_on?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.common.labels.juz_number', ['number' => $test->juz?->juz_number ?: __('workflow.common.not_available')]) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->type?->name ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->score !== null ? $test->score : __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.common.result_status.'.$test->status) }}</span></td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->teacher?->first_name }} {{ $test->teacher?->last_name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($tests->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $tests->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal
        :show="$showFormModal"
        :title="__('workflow.quran_tests.workbench.form.title')"
        :description="__('workflow.quran_tests.workbench.form.help')"
        close-method="closeFormModal"
        max-width="5xl"
    >
        <form wire:submit="save" class="space-y-4">
            @if ($currentTeacher)
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('workflow.quran_tests.workbench.teacher_badge', ['name' => $currentTeacher->first_name.' '.$currentTeacher->last_name]) }}</div>
                    <div class="mt-2 text-neutral-300">{{ __('workflow.quran_tests.workbench.form.teacher_locked') }}</div>
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="quran-workbench-student" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.workbench.form.student') }}</label>
                    <select id="quran-workbench-student" wire:model.live="selectedStudentId" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.quran_tests.workbench.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">
                                {{ $student->first_name }} {{ $student->last_name }}
                                @if ($student->parentProfile?->father_name)
                                    - {{ $student->parentProfile->father_name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('selectedStudentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="quran-workbench-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.workbench.form.group') }}</label>
                    <select id="quran-workbench-enrollment" wire:model="selectedEnrollmentId" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($enrollmentOptions->isEmpty())>
                        <option value="">{{ __('workflow.quran_tests.workbench.form.select_group') }}</option>
                        @foreach ($enrollmentOptions as $enrollment)
                            <option value="{{ $enrollment->id }}">
                                {{ $enrollment->group?->name ?: __('workflow.common.no_group') }}
                                @if ($enrollment->group?->course?->name)
                                    - {{ $enrollment->group->course->name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @if ($selectedStudentId && $enrollmentOptions->count() === 1)
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_tests.workbench.form.group_auto') }}</div>
                    @endif
                    @error('selectedEnrollmentId')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @if (! $currentTeacher)
                <div>
                    <label for="quran-workbench-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.teacher') }}</label>
                    <select id="quran-workbench-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.quran_tests.form.select_teacher') }}</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                        @endforeach
                    </select>
                    @error('teacher_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="quran-workbench-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.juz') }}</label>
                    <select id="quran-workbench-juz" wire:model="juz_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.quran_tests.form.select_juz') }}</option>
                        @foreach ($juzs as $juz)
                            <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                        @endforeach
                    </select>
                    @error('juz_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="quran-workbench-type" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.test_type') }}</label>
                    <select id="quran-workbench-type" wire:model="quran_test_type_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.quran_tests.form.select_type') }}</option>
                        @foreach ($testTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </select>
                    @error('quran_test_type_id')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="quran-workbench-date" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label>
                    <input id="quran-workbench-date" wire:model="tested_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="quran-workbench-score" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label>
                    <input id="quran-workbench-score" wire:model="score" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('score')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="quran-workbench-status" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.result_status') }}</label>
                    <select id="quran-workbench-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                        <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                        <option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option>
                    </select>
                    @error('status')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div>
                <label for="quran-workbench-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label>
                <textarea id="quran-workbench-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.common.actions.save_quran_test') }}</button>
                <button type="button" wire:click="closeFormModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>

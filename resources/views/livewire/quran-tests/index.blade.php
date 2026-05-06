<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\PointLedgerService;
use App\Services\QuranProgressionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $selectedStudentId = null;
    public ?int $selectedEnrollmentId = null;
    public ?int $juz_id = null;
    public string $tested_on = '';
    public string $score = '';
    public string $status = 'passed';
    public string $notes = '';
    public string $search = '';
    public string $statusFilter = 'all';
    public string $juzFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;

    public function mount(): void
    {
        $this->authorizeAnyPermission(['quran-awqaf-tests.view', 'quran-tests.view']);
        $this->resetForm();
    }

    public function with(): array
    {
        $testsQuery = $this->scopeQuranTestsQuery(
            QuranTest::query()->with([
                'student.parentProfile',
                'enrollment.group.course',
                'teacher',
                'juz',
                'type',
            ])
        )
            ->whereHas('type', fn (Builder $query) => $query->where('code', 'awqaf'))
            ->when(filled($this->search), function (Builder $query) {
                $search = '%'.$this->search.'%';

                $query->where(function (Builder $builder) use ($search) {
                    $builder
                        ->whereHas('student', function (Builder $studentQuery) use ($search) {
                            $studentQuery
                                ->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('student_number', 'like', $search);
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
                $this->juzFilter !== 'all' && filled($this->juzFilter),
                fn (Builder $query) => $query->where('juz_id', (int) $this->juzFilter)
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
            'juzOptions' => QuranJuz::query()->orderBy('juz_number')->get(),
            'eligibleJuzs' => $this->eligibleJuzsForStudentId($this->selectedStudentId),
            'stats' => [
                'students' => $studentOptions->count(),
                'tests' => $this->scopeQuranTestsQuery(QuranTest::query())
                    ->whereHas('type', fn (Builder $query) => $query->where('code', 'awqaf'))
                    ->count(),
                'passed' => $this->scopeQuranTestsQuery(QuranTest::query()->where('status', 'passed'))
                    ->whereHas('type', fn (Builder $query) => $query->where('code', 'awqaf'))
                    ->count(),
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

    public function updatedJuzFilter(): void
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

        $this->juz_id = $student
            ? $this->firstEligibleJuzIdForStudentId($student->id)
            : null;

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
            'juz_id',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizeAnyPermission(['quran-awqaf-tests.record', 'quran-tests.record']);

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
        $this->authorizeAnyPermission(['quran-awqaf-tests.record', 'quran-tests.record']);

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'juz_id' => ['required', 'exists:quran_juzs,id'],
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

        $teacherId = $this->resolvedTeacherId($enrollment);

        if (! $teacherId) {
            $this->addError('selectedEnrollmentId', __('workflow.quran_tests.errors.no_teacher_available'));

            return;
        }

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $testType = QuranTestType::query()->where('code', 'awqaf')->where('is_active', true)->firstOrFail();
        $progression = app(QuranProgressionService::class)->validate($enrollment, (int) $validated['juz_id'], $testType);

        if ($progression && ! $this->canAnyPermission(['quran-awqaf-tests.override-progression', 'quran-tests.override-progression'])) {
            $this->addError('juz_id', $progression);

            return;
        }

        $test = QuranTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacherId,
            'juz_id' => (int) $validated['juz_id'],
            'quran_test_type_id' => $testType->id,
            'tested_on' => $validated['tested_on'],
            'score' => $validated['score'] !== '' ? $validated['score'] : null,
            'status' => $validated['status'],
            'attempt_no' => app(QuranProgressionService::class)->nextAttemptNumber(
                $enrollment,
                (int) $validated['juz_id'],
                $testType->id,
            ),
            'notes' => $validated['notes'] ?: null,
        ]);

        app(PointLedgerService::class)->recordQuranTestPoints($test->fresh(['enrollment.student', 'student.gradeLevel', 'type']));

        session()->flash('status', __('workflow.quran_tests.messages.saved'));
        $this->closeFormModal();
    }

    public function delete(int $testId): void
    {
        $this->authorizePermission('quran-awqaf-tests.delete');

        $test = $this->scopeQuranTestsQuery(
            QuranTest::query()->with(['enrollment.student', 'type'])
        )
            ->whereHas('type', fn (Builder $query) => $query->where('code', 'awqaf'))
            ->findOrFail($testId);

        $this->authorizeScopedStudentAccess($test->student);

        DB::transaction(function () use ($test): void {
            $ledger = app(PointLedgerService::class);
            $ledger->voidSourceTransactions('quran_test', $test->id, __('workflow.quran_tests.messages.deleted_void_reason'));

            $enrollment = $test->enrollment;
            $test->delete();

            if ($enrollment) {
                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
            }
        });

        session()->flash('status', __('workflow.quran_tests.messages.deleted'));
    }

    public function resetForm(): void
    {
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->juz_id = null;
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

    protected function eligibleJuzsForStudentId(?int $studentId)
    {
        if (! $studentId) {
            return collect();
        }

        $eligibleJuzIds = app(QuranProgressionService::class)->eligibleAwqafJuzIdsForStudent($studentId);

        if ($eligibleJuzIds->isEmpty()) {
            return collect();
        }

        return QuranJuz::query()
            ->whereIn('id', $eligibleJuzIds)
            ->orderBy('juz_number')
            ->get();
    }

    protected function firstEligibleJuzIdForStudentId(?int $studentId): ?int
    {
        return $this->eligibleJuzsForStudentId($studentId)->first()?->id;
    }

    protected function resolvedTeacherId(Enrollment $enrollment): ?int
    {
        return $this->currentTeacher()?->id ?: $enrollment->group?->teacher_id;
    }

    protected function currentTeacher(): ?Teacher
    {
        return $this->linkedTeacherForPermission('quran-awqaf-tests.record-linked-teacher')
            ?: $this->linkedTeacherForPermission('quran-tests.record-linked-teacher');
    }

    protected function authorizeAnyPermission(array $permissions): void
    {
        abort_unless($this->canAnyPermission($permissions), 403);
    }

    protected function canAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (auth()->user()?->can($permission)) {
                return true;
            }
        }

        return false;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking_quran') }}</div>
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
                    <label for="quran-tests-juz-filter">{{ __('workflow.quran_tests.workbench.filters.juz') }}</label>
                    <select id="quran-tests-juz-filter" wire:model.live="juzFilter">
                        <option value="all">{{ __('workflow.quran_tests.workbench.filters.all_juzs') }}</option>
                        @foreach ($juzOptions as $juzOption)
                            <option value="{{ $juzOption->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juzOption->juz_number]) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @canany(['quran-awqaf-tests.record', 'quran-tests.record'])
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.quran_tests.workbench.create') }}</button>
                    @endcanany
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
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.score') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.workbench.table.headers.teacher') }}</th>
                            @can('quran-awqaf-tests.delete')
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('crud.common.actions.actions') }}</th>
                            @endcan
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
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->score !== null ? $test->score : __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.common.result_status.'.$test->status) }}</span></td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->teacher?->first_name }} {{ $test->teacher?->last_name }}</td>
                                @can('quran-awqaf-tests.delete')
                                    <td class="px-5 py-4 text-right lg:px-6">
                                        <button type="button" wire:click="delete({{ $test->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                    </td>
                                @endcan
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
        <form wire:submit="save" class="space-y-4" data-searchable-refresh>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="quran-workbench-student" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.workbench.form.student') }}</label>
                    <select
                        id="quran-workbench-student"
                        wire:key="quran-workbench-student-select"
                        wire:model.live="selectedStudentId"
                        data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                    >
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
                    @error('selectedStudentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label for="quran-workbench-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.workbench.form.group') }}</label>
                    <select
                        id="quran-workbench-enrollment"
                        wire:key="quran-workbench-enrollment-select-{{ $selectedStudentId ?: 'blank' }}"
                        wire:model="selectedEnrollmentId"
                        data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                        @disabled($enrollmentOptions->isEmpty())
                    >
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
                    @error('selectedEnrollmentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="quran-workbench-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.juz') }}</label>
                    <select id="quran-workbench-juz" wire:model="juz_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.quran_tests.form.select_juz') }}</option>
                        @foreach ($eligibleJuzs as $juz)
                            <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                        @endforeach
                    </select>
                    @if ($selectedStudentId && $eligibleJuzs->isEmpty())
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_tests.form.no_eligible_juzs') }}</div>
                    @endif
                    @error('juz_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label for="quran-workbench-date" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label>
                    <input id="quran-workbench-date" wire:model="tested_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label for="quran-workbench-score" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label>
                    <input id="quran-workbench-score" wire:model="score" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('score') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="quran-workbench-status" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.result_status') }}</label>
                    <select id="quran-workbench-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                        <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                        <option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option>
                    </select>
                    @error('status') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label for="quran-workbench-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label>
                    <textarea id="quran-workbench-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                    @error('notes') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.common.actions.save_quran_test') }}</button>
                <x-admin.create-and-new-button />
                <button type="button" wire:click="closeFormModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>
</div>

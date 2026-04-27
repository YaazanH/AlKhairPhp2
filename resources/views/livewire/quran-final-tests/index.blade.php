<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Services\QuranFinalTestService;
use Illuminate\Database\Eloquent\Builder;
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
    public string $search = '';
    public string $statusFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showOpenTestWarningModal = false;
    public ?int $existingFinalTestId = null;
    public array $existingFinalTestSummary = [];

    public function mount(): void
    {
        $this->authorizePermission('quran-final-tests.view');
        $this->resetForm();
    }

    public function with(): array
    {
        $testsQuery = $this->scopeQuranFinalTestsQuery(
            QuranFinalTest::query()->with([
                'student.parentProfile',
                'enrollment.group.course',
                'juz',
                'attempts.teacher',
            ])
        )
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
                        ->orWhereHas('juz', fn (Builder $juzQuery) => $juzQuery->where('juz_number', 'like', $search));
                });
            })
            ->when(
                in_array($this->statusFilter, ['in_progress', 'passed'], true),
                fn (Builder $query) => $query->where('status', $this->statusFilter)
            )
            ->latest('passed_on')
            ->latest('id');

        $studentOptions = $this->scopeStudentsQuery(
            Student::query()
                ->with('parentProfile')
                ->whereHas('enrollments', function (Builder $query) {
                    $this->scopeEnrollmentsQuery($query)->where('status', 'active');
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $selectedStudent = $this->selectedStudentId
            ? $this->scopeStudentsQuery(Student::query())->find($this->selectedStudentId)
            : null;

        $eligibleJuzIds = $selectedStudent
            ? app(QuranFinalTestService::class)->eligibleJuzIdsForStudent($selectedStudent)->all()
            : [];

        return [
            'finalTests' => $testsQuery->paginate($this->perPage),
            'filteredCount' => (clone $testsQuery)->count(),
            'studentOptions' => $studentOptions,
            'enrollmentOptions' => $this->availableEnrollmentsQuery()
                ->with(['group.course'])
                ->orderByDesc('enrolled_at')
                ->orderByDesc('id')
                ->get(),
            'eligibleJuzs' => empty($eligibleJuzIds)
                ? collect()
                : QuranJuz::query()->whereIn('id', $eligibleJuzIds)->orderBy('juz_number')->get(),
            'stats' => [
                'tests' => $this->scopeQuranFinalTestsQuery(QuranFinalTest::query())->count(),
                'in_progress' => $this->scopeQuranFinalTestsQuery(QuranFinalTest::query()->where('status', 'in_progress'))->count(),
                'passed' => $this->scopeQuranFinalTestsQuery(QuranFinalTest::query()->where('status', 'passed'))->count(),
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

    public function updatedSelectedStudentId(): void
    {
        $enrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->selectedEnrollmentId = count($enrollmentIds) === 1
            ? $enrollmentIds[0]
            : null;

        $this->juz_id = null;

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
            'juz_id',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('quran-final-tests.record');

        $this->resetForm();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->resetForm();
        $this->showFormModal = false;
    }

    public function closeOpenTestWarningModal(): void
    {
        $this->showOpenTestWarningModal = false;
        $this->existingFinalTestId = null;
        $this->existingFinalTestSummary = [];
    }

    public function openExistingTest(): void
    {
        $this->authorizePermission('quran-final-tests.record');

        if (! $this->existingFinalTestId) {
            $this->closeOpenTestWarningModal();

            return;
        }

        $finalTest = $this->scopeQuranFinalTestsQuery(
            QuranFinalTest::query()->with('enrollment')
        )->findOrFail($this->existingFinalTestId);

        $this->closeOpenTestWarningModal();
        $this->closeFormModal();

        $this->redirect(route('quran-final-tests.show', $finalTest), navigate: true);
    }

    public function save(): void
    {
        $this->authorizePermission('quran-final-tests.record');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'juz_id' => ['required', 'exists:quran_juzs,id'],
        ], [], [
            'selectedStudentId' => __('workflow.quran_final_tests.form.student'),
            'selectedEnrollmentId' => __('workflow.quran_final_tests.form.group'),
        ]);

        $student = $this->scopeStudentsQuery(Student::query())->findOrFail($validated['selectedStudentId']);
        $this->authorizeScopedStudentAccess($student);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.quran_final_tests.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            if (count($availableEnrollmentIds) > 1) {
                $this->addError('selectedEnrollmentId', __('workflow.quran_final_tests.errors.select_group'));

                return;
            }

            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
        }

        abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

        $existingFinalTest = app(QuranFinalTestService::class)->existingTestForStudentAndJuz(
            (int) $validated['selectedStudentId'],
            (int) $validated['juz_id'],
        );

        if ($existingFinalTest && $existingFinalTest->status !== 'passed') {
            $this->existingFinalTestId = $existingFinalTest->id;
            $this->existingFinalTestSummary = [
                'attempts' => $existingFinalTest->attempts->count(),
                'group' => $existingFinalTest->enrollment?->group?->name ?: __('workflow.common.no_group'),
                'juz_number' => $existingFinalTest->juz?->juz_number,
                'last_tested_on' => optional($existingFinalTest->attempts->last()?->tested_on)->format('Y-m-d'),
            ];
            $this->showOpenTestWarningModal = true;

            return;
        }

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['student', 'group.course'])
        )->findOrFail((int) $validated['selectedEnrollmentId']);

        try {
            $finalTest = app(QuranFinalTestService::class)->create(
                $enrollment,
                QuranJuz::query()->findOrFail((int) $validated['juz_id']),
            );
        } catch (\LogicException $exception) {
            $this->addError('juz_id', $exception->getMessage());

            return;
        }

        session()->flash('status', __('workflow.quran_final_tests.messages.created'));

        $this->closeFormModal();
        $this->redirect(route('quran-final-tests.show', $finalTest), navigate: true);
    }

    public function resetForm(): void
    {
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->juz_id = null;
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking_quran') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_final_tests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.quran_final_tests.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.quran_final_tests.stats.tests') }}: {{ number_format($stats['tests']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_final_tests.stats.passed') }}: {{ number_format($stats['passed']) }}</span>
            <span class="badge-soft">{{ __('workflow.quran_final_tests.stats.in_progress') }}: {{ number_format($stats['in_progress']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.quran_final_tests.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.quran_final_tests.table.copy') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="final-tests-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="final-tests-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="final-tests-status-filter">{{ __('workflow.quran_final_tests.filters.status') }}</label>
                    <select id="final-tests-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('workflow.quran_final_tests.filters.all_statuses') }}</option>
                        <option value="in_progress">{{ __('workflow.quran_final_tests.statuses.in_progress') }}</option>
                        <option value="passed">{{ __('workflow.quran_final_tests.statuses.passed') }}</option>
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('quran-final-tests.record')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.quran_final_tests.actions.create') }}</button>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.quran_final_tests.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($finalTests->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.quran_final_tests.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.table.headers.juz') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.table.headers.attempts') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_final_tests.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.quran_final_tests.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($finalTests as $finalTest)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline">
                                        <x-student-avatar :student="$finalTest->student" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $finalTest->student?->first_name }} {{ $finalTest->student?->last_name }}</div>
                                            <div class="student-inline__meta">{{ $finalTest->student?->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="font-medium text-white">{{ $finalTest->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $finalTest->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.common.labels.juz_number', ['number' => $finalTest->juz?->juz_number ?: __('workflow.common.not_available')]) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($finalTest->attempts->count()) }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.quran_final_tests.statuses.'.$finalTest->status) }}</span></td>
                                <td class="px-5 py-4 text-right lg:px-6"><a href="{{ route('quran-final-tests.show', $finalTest) }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.quran_final_tests.actions.open') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($finalTests->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $finalTests->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal :show="$showFormModal" :title="__('workflow.quran_final_tests.form.title')" :description="__('workflow.quran_final_tests.form.help')" close-method="closeFormModal" max-width="4xl">
        <form wire:submit="save" class="space-y-4" data-searchable-refresh>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="final-test-student" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_final_tests.form.student') }}</label>
                    <select
                        id="final-test-student"
                        wire:key="final-test-student-select"
                        wire:model.live="selectedStudentId"
                        data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                    >
                        <option value="">{{ __('workflow.quran_final_tests.form.select_student') }}</option>
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
                    <label for="final-test-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_final_tests.form.group') }}</label>
                    <select
                        id="final-test-enrollment"
                        wire:key="final-test-enrollment-select-{{ $selectedStudentId ?: 'blank' }}"
                        wire:model="selectedEnrollmentId"
                        data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                        @disabled($enrollmentOptions->isEmpty())
                    >
                        <option value="">{{ __('workflow.quran_final_tests.form.select_group') }}</option>
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
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_final_tests.form.group_auto') }}</div>
                    @endif
                    @error('selectedEnrollmentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label for="final-test-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_final_tests.form.juz') }}</label>
                <select
                    id="final-test-juz"
                    wire:key="final-test-juz-select-{{ $selectedStudentId ?: 'blank' }}-{{ $selectedEnrollmentId ?: 'blank' }}"
                    wire:model="juz_id"
                    data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                    class="w-full rounded-xl px-4 py-3 text-sm"
                >
                    <option value="">{{ __('workflow.quran_final_tests.form.select_juz') }}</option>
                    @foreach ($eligibleJuzs as $juz)
                        <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                    @endforeach
                </select>
                @if ($selectedStudentId && $eligibleJuzs->isEmpty())
                    <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_final_tests.form.no_eligible_juzs') }}</div>
                @endif
                @error('juz_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.quran_final_tests.actions.create') }}</button>
                <x-admin.create-and-new-button />
                <button type="button" wire:click="closeFormModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showOpenTestWarningModal" :title="__('workflow.quran_final_tests.warnings.open_cycle_title')" :description="__('workflow.quran_final_tests.warnings.open_cycle_copy')" close-method="closeOpenTestWarningModal" max-width="3xl">
        <div class="space-y-4">
            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                <div class="text-sm font-semibold text-white">{{ __('workflow.common.labels.juz_number', ['number' => $existingFinalTestSummary['juz_number'] ?? __('workflow.common.not_available')]) }}</div>
                <div class="mt-1 text-sm text-neutral-300">{{ $existingFinalTestSummary['group'] ?? __('workflow.common.no_group') }}</div>
                <div class="mt-2 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.quran_final_tests.warnings.attempts_progress', ['count' => $existingFinalTestSummary['attempts'] ?? 0]) }}</div>
                @if (! empty($existingFinalTestSummary['last_tested_on']))
                    <div class="mt-2 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.quran_final_tests.warnings.last_tested_on', ['date' => $existingFinalTestSummary['last_tested_on']]) }}</div>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeOpenTestWarningModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="button" wire:click="openExistingTest" class="pill-link pill-link--accent">{{ __('workflow.quran_final_tests.warnings.open_existing') }}</button>
            </div>
        </div>
    </x-admin.modal>
</div>

<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Services\QuranPartialTestService;
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
    public string $juzFilter = 'all';
    public int $perPage = 15;
    public bool $showFormModal = false;
    public bool $showOpenTestWarningModal = false;
    public array $openTestWarnings = [];
    public ?int $pendingCreateStudentId = null;
    public ?int $pendingCreateEnrollmentId = null;
    public ?int $pendingCreateJuzId = null;

    public function mount(): void
    {
        $this->authorizePermission('quran-partial-tests.view');
        $this->resetForm();
    }

    public function with(): array
    {
        $testsQuery = $this->scopeQuranPartialTestsQuery(
            QuranPartialTest::query()->with([
                'student.parentProfile',
                'enrollment.group.course',
                'juz',
                'parts.attempts',
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
            ->when(
                $this->juzFilter !== 'all' && filled($this->juzFilter),
                fn (Builder $query) => $query->where('juz_id', (int) $this->juzFilter)
            )
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
            ? $this->scopeStudentsQuery(Student::query()->with('pageAchievements'))->find($this->selectedStudentId)
            : null;

        $eligibleJuzIds = $selectedStudent
            ? app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($selectedStudent)->all()
            : [];

        return [
            'partialTests' => $testsQuery->paginate($this->perPage),
            'filteredCount' => (clone $testsQuery)->count(),
            'studentOptions' => $studentOptions,
            'enrollmentOptions' => $this->availableEnrollmentsQuery()
                ->with(['group.course'])
                ->orderByDesc('enrolled_at')
                ->orderByDesc('id')
                ->get(),
            'juzOptions' => QuranJuz::query()->orderBy('juz_number')->get(),
            'eligibleJuzs' => empty($eligibleJuzIds)
                ? collect()
                : QuranJuz::query()->whereIn('id', $eligibleJuzIds)->orderBy('juz_number')->get(),
            'stats' => [
                'tests' => $this->scopeQuranPartialTestsQuery(QuranPartialTest::query())->count(),
                'in_progress' => $this->scopeQuranPartialTestsQuery(QuranPartialTest::query()->where('status', 'in_progress'))->count(),
                'passed' => $this->scopeQuranPartialTestsQuery(QuranPartialTest::query()->where('status', 'passed'))->count(),
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

        $this->juz_id = null;

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
            'juz_id',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('quran-partial-tests.record');

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
        $this->resetPendingCreateWarning();
    }

    public function confirmOpenTestWarningCreate(): void
    {
        $this->authorizePermission('quran-partial-tests.record');

        if (! $this->pendingCreateStudentId || ! $this->pendingCreateEnrollmentId || ! $this->pendingCreateJuzId) {
            $this->resetPendingCreateWarning();

            return;
        }

        $student = $this->scopeStudentsQuery(Student::query()->with('pageAchievements'))->findOrFail($this->pendingCreateStudentId);
        $this->authorizeScopedStudentAccess($student);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['student.pageAchievements', 'group.course'])
        )->findOrFail($this->pendingCreateEnrollmentId);

        $this->attemptCreatePartialTest($student, $enrollment, $this->pendingCreateJuzId, true);
    }

    public function save(): void
    {
        $this->authorizePermission('quran-partial-tests.record');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'juz_id' => ['required', 'exists:quran_juzs,id'],
        ], [], [
            'selectedStudentId' => __('workflow.quran_partial_tests.form.student'),
            'selectedEnrollmentId' => __('workflow.quran_partial_tests.form.group'),
        ]);

        $student = $this->scopeStudentsQuery(Student::query()->with('pageAchievements'))->findOrFail($validated['selectedStudentId']);
        $this->authorizeScopedStudentAccess($student);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.quran_partial_tests.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            if (count($availableEnrollmentIds) > 1) {
                $this->addError('selectedEnrollmentId', __('workflow.quran_partial_tests.errors.select_group'));

                return;
            }

            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
        }

        abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['student.pageAchievements', 'group.course'])
        )->findOrFail((int) $validated['selectedEnrollmentId']);

        $this->attemptCreatePartialTest($student, $enrollment, (int) $validated['juz_id']);
    }

    protected function attemptCreatePartialTest(Student $student, Enrollment $enrollment, int $juzId, bool $ignoreOpenTestWarning = false): void
    {
        if (! $ignoreOpenTestWarning) {
            $openTests = app(QuranPartialTestService::class)
                ->inProgressTestsForStudent($student)
                ->values();

            if ($openTests->isNotEmpty()) {
                $this->pendingCreateStudentId = $student->id;
                $this->pendingCreateEnrollmentId = $enrollment->id;
                $this->pendingCreateJuzId = $juzId;
                $this->openTestWarnings = $openTests
                    ->map(fn (QuranPartialTest $partialTest) => [
                        'group' => $partialTest->enrollment?->group?->name ?: __('workflow.common.no_group'),
                        'course' => $partialTest->enrollment?->group?->course?->name ?: __('workflow.common.no_course'),
                        'juz_number' => $partialTest->juz?->juz_number,
                        'parts_passed' => $partialTest->parts->where('status', 'passed')->count(),
                    ])
                    ->all();
                $this->showOpenTestWarningModal = true;

                return;
            }
        }

        try {
            $partialTest = app(QuranPartialTestService::class)->create(
                $enrollment,
                QuranJuz::query()->findOrFail($juzId),
            );
        } catch (\LogicException $exception) {
            $this->addError('juz_id', $exception->getMessage());

            return;
        }

        $this->resetPendingCreateWarning();
        session()->flash('status', __('workflow.quran_partial_tests.messages.created'));

        $this->closeFormModal();

        $this->redirect(route('quran-partial-tests.show', $partialTest), navigate: true);
    }

    public function resetForm(): void
    {
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->juz_id = null;
        $this->resetValidation();
    }

    protected function resetPendingCreateWarning(): void
    {
        $this->showOpenTestWarningModal = false;
        $this->openTestWarnings = [];
        $this->pendingCreateStudentId = null;
        $this->pendingCreateEnrollmentId = null;
        $this->pendingCreateJuzId = null;
        $this->resetValidation('openTestWarning');
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
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_partial_tests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.quran_partial_tests.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ __('workflow.quran_partial_tests.stats.tests') }}: {{ number_format($stats['tests']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.quran_partial_tests.stats.passed') }}: {{ number_format($stats['passed']) }}</span>
            <span class="badge-soft">{{ __('workflow.quran_partial_tests.stats.in_progress') }}: {{ number_format($stats['in_progress']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.quran_partial_tests.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.quran_partial_tests.table.copy') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="partial-tests-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="partial-tests-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label for="partial-tests-status-filter">{{ __('workflow.quran_partial_tests.filters.status') }}</label>
                    <select id="partial-tests-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('workflow.quran_partial_tests.filters.all_statuses') }}</option>
                        <option value="in_progress">{{ __('workflow.quran_partial_tests.statuses.in_progress') }}</option>
                        <option value="passed">{{ __('workflow.quran_partial_tests.statuses.passed') }}</option>
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label for="partial-tests-juz-filter">{{ __('workflow.quran_partial_tests.filters.juz') }}</label>
                    <select id="partial-tests-juz-filter" wire:model.live="juzFilter">
                        <option value="all">{{ __('workflow.quran_partial_tests.filters.all_juzs') }}</option>
                        @foreach ($juzOptions as $juzOption)
                            <option value="{{ $juzOption->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juzOption->juz_number]) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-toolbar__actions">
                    @can('quran-partial-tests.record')
                        <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.quran_partial_tests.actions.create') }}</button>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.quran_partial_tests.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
            </div>
        </div>

        @if ($partialTests->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.quran_partial_tests.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.student') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.juz') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.parts') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.status') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($partialTests as $partialTest)
                            <tr>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="student-inline">
                                        <x-student-avatar :student="$partialTest->student" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $partialTest->student?->first_name }} {{ $partialTest->student?->last_name }}</div>
                                            <div class="student-inline__meta">{{ $partialTest->student?->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="font-medium text-white">{{ $partialTest->enrollment?->group?->name ?: __('workflow.common.no_group') }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ $partialTest->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.common.labels.juz_number', ['number' => $partialTest->juz?->juz_number ?: __('workflow.common.not_available')]) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $partialTest->parts->where('status', 'passed')->count() }} / 4</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.quran_partial_tests.statuses.'.$partialTest->status) }}</span></td>
                                <td class="px-5 py-4 text-right lg:px-6"><a href="{{ route('quran-partial-tests.show', $partialTest) }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.quran_partial_tests.actions.open') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($partialTests->hasPages())
                <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                    {{ $partialTests->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal :show="$showFormModal" :title="__('workflow.quran_partial_tests.form.title')" :description="__('workflow.quran_partial_tests.form.help')" close-method="closeFormModal" max-width="4xl">
        <form wire:submit="save" class="space-y-4" data-searchable-refresh>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="partial-test-student" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.form.student') }}</label>
                    <select
                        id="partial-test-student"
                        wire:key="partial-test-student-select"
                        wire:model.live="selectedStudentId"
                        data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                    >
                        <option value="">{{ __('workflow.quran_partial_tests.form.select_student') }}</option>
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
                    <label for="partial-test-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.form.group') }}</label>
                    <select
                        id="partial-test-enrollment"
                        wire:key="partial-test-enrollment-select-{{ $selectedStudentId ?: 'blank' }}"
                        wire:model="selectedEnrollmentId"
                        data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                        @disabled($enrollmentOptions->isEmpty())
                    >
                        <option value="">{{ __('workflow.quran_partial_tests.form.select_group') }}</option>
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
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_partial_tests.form.group_auto') }}</div>
                    @endif
                    @error('selectedEnrollmentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label for="partial-test-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.form.juz') }}</label>
                <select
                    id="partial-test-juz"
                    wire:key="partial-test-juz-select-{{ $selectedStudentId ?: 'blank' }}-{{ $selectedEnrollmentId ?: 'blank' }}"
                    wire:model="juz_id"
                    data-search-placeholder="{{ __('crud.common.filters.search_placeholder') }}"
                    class="w-full rounded-xl px-4 py-3 text-sm"
                >
                    <option value="">{{ __('workflow.quran_partial_tests.form.select_juz') }}</option>
                    @foreach ($eligibleJuzs as $juz)
                        <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                    @endforeach
                </select>
                @if ($selectedStudentId && $eligibleJuzs->isEmpty())
                    <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_partial_tests.form.no_eligible_juzs') }}</div>
                @endif
                @error('juz_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.quran_partial_tests.actions.create') }}</button>
                <x-admin.create-and-new-button />
                <button type="button" wire:click="closeFormModal" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showOpenTestWarningModal" :title="__('workflow.quran_partial_tests.warnings.open_cycle_title')" :description="__('workflow.quran_partial_tests.warnings.open_cycle_copy')" close-method="closeOpenTestWarningModal" max-width="3xl">
        <div class="space-y-4">
            <div class="space-y-3">
                @foreach ($openTestWarnings as $warning)
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                        <div class="text-sm font-semibold text-white">{{ __('workflow.common.labels.juz_number', ['number' => $warning['juz_number']]) }}</div>
                        <div class="mt-1 text-sm text-neutral-300">{{ $warning['group'] }} @if ($warning['course']) · {{ $warning['course'] }} @endif</div>
                        <div class="mt-2 text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.quran_partial_tests.warnings.parts_progress', ['count' => $warning['parts_passed']]) }}</div>
                    </div>
                @endforeach
            </div>

            @error('openTestWarning')
                <div class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $message }}</div>
            @enderror

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeOpenTestWarningModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="button" wire:click="confirmOpenTestWarningCreate" class="pill-link pill-link--accent">{{ __('workflow.quran_partial_tests.warnings.create_anyway') }}</button>
            </div>
        </div>
    </x-admin.modal>
</div>

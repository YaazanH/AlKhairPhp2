<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Enrollment;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranPartialTestAttempt;
use App\Models\Student;
use App\Services\PointLedgerService;
use App\Services\QuranPartialTestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
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

    public string $sortField = 'last_tested_on';

    public string $sortDirection = 'desc';

    public int $perPage = 15;

    public bool $showFormModal = false;

    public bool $showOpenTestWarningModal = false;

    public array $openTestWarnings = [];

    public ?int $existingPartialTestId = null;

    public ?int $pendingCreateStudentId = null;

    public ?int $pendingCreateEnrollmentId = null;

    public ?int $pendingCreateJuzId = null;

    protected array $sortableFields = [
        'juz',
        'last_tested_on',
        'status',
        'student',
    ];

    public function mount(): void
    {
        $this->authorizePermission('quran-partial-tests.view');
        $this->resetForm();
    }

    public function with(): array
    {
        $testsQuery = $this->quranPartialTestsQuery(
            QuranPartialTest::query()
                ->addSelect([
                    'last_tested_on' => QuranPartialTestAttempt::query()
                        ->select('tested_on')
                        ->join('quran_partial_test_parts', 'quran_partial_test_parts.id', '=', 'quran_partial_test_attempts.quran_partial_test_part_id')
                        ->whereColumn('quran_partial_test_parts.quran_partial_test_id', 'quran_partial_tests.id')
                        ->orderByDesc('quran_partial_test_attempts.tested_on')
                        ->orderByDesc('quran_partial_test_attempts.id')
                        ->limit(1),
                ])
                ->with([
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
            );
        $this->applyPartialTestSort($testsQuery);

        $studentOptions = $this->quranStudentsQuery(
            Student::query()
                ->with('parentProfile')
                ->whereHas('enrollments', function (Builder $query) {
                    $this->quranEnrollmentsQuery($query)->where('status', 'active');
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $selectedStudent = $this->selectedStudentId
            ? $this->quranStudentsQuery(Student::query()->with('pageAchievements'))->find($this->selectedStudentId)
            : null;

        $eligibleJuzIds = $selectedStudent
            ? app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($selectedStudent)->all()
            : [];

        return [
            'partialTests' => $testsQuery->paginate($this->perPage),
            'filteredCount' => (clone $testsQuery)->count(),
            'studentOptions' => $studentOptions,
            'juzOptions' => QuranJuz::query()->orderBy('juz_number')->get(),
            'eligibleJuzs' => empty($eligibleJuzIds)
                ? collect()
                : QuranJuz::query()->whereIn('id', $eligibleJuzIds)->orderBy('juz_number')->get(),
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

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = $field === 'student' ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function updatedSelectedStudentId(): void
    {
        $this->selectedEnrollmentId = $this->availableEnrollmentsQuery()
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->value('id');

        $student = $this->selectedStudentId
            ? $this->quranStudentsQuery(Student::query()->with('pageAchievements'))->find($this->selectedStudentId)
            : null;
        $eligibleJuzIds = $student
            ? app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($student)
            : collect();

        $this->juz_id = $eligibleJuzIds->count() === 1 ? (int) $eligibleJuzIds->first() : null;

        $this->resetValidation([
            'selectedStudentId',
            'selectedEnrollmentId',
            'juz_id',
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('quran-partial-tests.record');
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

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
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        if (! $this->pendingCreateStudentId || ! $this->pendingCreateEnrollmentId || ! $this->pendingCreateJuzId) {
            $this->resetPendingCreateWarning();

            return;
        }

        $student = $this->quranStudentsQuery(Student::query()->with('pageAchievements'))
            ->findOrFail($this->pendingCreateStudentId);
        $enrollment = $this->quranEnrollmentsQuery(
            Enrollment::query()->with(['student.pageAchievements', 'group.course'])
        )->where('student_id', $student->id)->findOrFail($this->pendingCreateEnrollmentId);
        $juzId = $this->pendingCreateJuzId;

        $this->resetPendingCreateWarning();
        $this->attemptCreatePartialTest($student, $enrollment, $juzId, true);
    }

    public function openExistingTest(): void
    {
        $this->authorizePermission('quran-partial-tests.record');

        if (! $this->existingPartialTestId) {
            $this->resetPendingCreateWarning();

            return;
        }

        $partialTest = $this->quranPartialTestsQuery(
            QuranPartialTest::query()->with('enrollment')
        )->findOrFail($this->existingPartialTestId);

        $this->resetPendingCreateWarning();
        $this->closeFormModal();

        $this->redirect(route('quran-partial-tests.show', $partialTest), navigate: true);
    }

    public function save(): void
    {
        $this->authorizePermission('quran-partial-tests.record');
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'juz_id' => ['required', 'exists:quran_juzs,id'],
        ], [], [
            'selectedStudentId' => __('workflow.quran_partial_tests.form.student'),
        ]);

        $student = $this->quranStudentsQuery(Student::query()->with('pageAchievements'))->findOrFail($validated['selectedStudentId']);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.quran_partial_tests.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
        }

        abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

        $enrollment = $this->quranEnrollmentsQuery(
            Enrollment::query()->with(['student.pageAchievements', 'group.course'])
        )->findOrFail((int) $validated['selectedEnrollmentId']);

        $this->attemptCreatePartialTest($student, $enrollment, (int) $validated['juz_id']);
    }

    public function delete(int $partialTestId): void
    {
        $this->authorizePermission('quran-partial-tests.delete');

        $partialTest = $this->quranPartialTestsQuery(
            QuranPartialTest::query()->with(['enrollment.student', 'parts'])
        )->findOrFail($partialTestId);

        DB::transaction(function () use ($partialTest): void {
            $ledger = app(PointLedgerService::class);
            $reason = __('workflow.quran_partial_tests.messages.deleted_void_reason');

            foreach ($partialTest->parts as $part) {
                $ledger->voidSourceTransactions('quran_partial_test_part', $part->id, $reason);
            }

            $ledger->voidSourceTransactions('quran_partial_test', $partialTest->id, $reason);

            $enrollment = $partialTest->enrollment;
            $partialTest->delete();

            if ($enrollment) {
                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));
            }
        });

        session()->flash('status', __('workflow.quran_partial_tests.messages.deleted'));
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
                $this->existingPartialTestId = $openTests->first()->id;
                $this->openTestWarnings = $openTests
                    ->map(fn (QuranPartialTest $partialTest) => [
                        'id' => $partialTest->id,
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
                $ignoreOpenTestWarning,
            );
        } catch (LogicException $exception) {
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
        $this->existingPartialTestId = null;
        $this->pendingCreateStudentId = null;
        $this->pendingCreateEnrollmentId = null;
        $this->pendingCreateJuzId = null;
        $this->resetValidation('openTestWarning');
    }

    protected function availableEnrollmentsQuery(): Builder
    {
        return $this->quranEnrollmentsQuery(
            Enrollment::query()
                ->where('status', 'active')
                ->when($this->selectedStudentId, fn (Builder $query) => $query->where('student_id', $this->selectedStudentId))
                ->when(! $this->selectedStudentId, fn (Builder $query) => $query->whereRaw('1 = 0'))
        );
    }

    protected function quranStudentsQuery(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function quranEnrollmentsQuery(Builder $query): Builder
    {
        return $query->whereHas('group.course', fn (Builder $courseQuery) => $courseQuery->where('is_active', true));
    }

    protected function quranPartialTestsQuery(Builder $query): Builder
    {
        return $query;
    }

    protected function applyPartialTestSort(Builder $query): void
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortField) {
            'juz' => $query
                ->orderBy(
                    QuranJuz::query()
                        ->select('juz_number')
                        ->whereColumn('quran_juzs.id', 'quran_partial_tests.juz_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            'status' => $query->orderBy('status', $direction)->orderByDesc('id'),
            'last_tested_on' => $query->orderBy('last_tested_on', $direction)->orderByDesc('id'),
            'created_at' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            default => $query
                ->orderBy(
                    Student::query()
                        ->select('first_name')
                        ->whereColumn('students.id', 'quran_partial_tests.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy(
                    Student::query()
                        ->select('last_name')
                        ->whereColumn('students.id', 'quran_partial_tests.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
        };
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking_quran') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_partial_tests.title') }}</h1>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('workflow.quran_partial_tests.table.title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field">
                    <label class="sr-only" for="partial-tests-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="partial-tests-search" wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="partial-tests-status-filter">{{ __('workflow.quran_partial_tests.filters.status') }}</label>
                    <select id="partial-tests-status-filter" wire:model.live="statusFilter">
                        <option value="all">{{ __('workflow.quran_partial_tests.filters.all_statuses') }}</option>
                        <option value="in_progress">{{ __('workflow.quran_partial_tests.statuses.in_progress') }}</option>
                        <option value="passed">{{ __('workflow.quran_partial_tests.statuses.passed') }}</option>
                    </select>
                </div>

                <div class="admin-filter-field">
                    <label class="sr-only" for="partial-tests-juz-filter">{{ __('workflow.quran_partial_tests.filters.juz') }}</label>
                    <select id="partial-tests-juz-filter" wire:model.live="juzFilter">
                        <option value="all">{{ __('workflow.quran_partial_tests.filters.all_juzs') }}</option>
                        @foreach ($juzOptions as $juzOption)
                            <option value="{{ $juzOption->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juzOption->juz_number]) }}</option>
                        @endforeach
                    </select>
                </div>

                </div>
        </div>

        @if ($partialTests->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.quran_partial_tests.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.quran_partial_tests.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('crud.common.filters.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('juz')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.quran_partial_tests.table.headers.juz') }} <span>{{ $this->sortIndicator('juz') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_partial_tests.table.headers.parts') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('last_tested_on')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.quran_partial_tests.table.headers.last_tested_on') }} <span>{{ $this->sortIndicator('last_tested_on') }}</span>
                                </button>
                            </th>
                            <th class="px-5 py-4 text-left lg:px-6">
                                <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                    {{ __('workflow.quran_partial_tests.table.headers.status') }} <span>{{ $this->sortIndicator('status') }}</span>
                                </button>
                            </th>
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
                                            <div class="student-inline__name">{{ trim(($partialTest->student?->first_name ?? '').' '.($partialTest->student?->last_name ?? '')) }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div class="whitespace-nowrap font-medium text-white">{{ $partialTest->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.common.labels.juz_number', ['number' => $partialTest->juz?->juz_number ?: __('workflow.common.not_available')]) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6"><span dir="ltr" class="inline-block">{{ $partialTest->parts->where('status', 'passed')->count() }} / 4</span></td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $partialTest->last_tested_on?->format('d-m-Y') ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.quran_partial_tests.statuses.'.$partialTest->status) }}</span></td>
                                <td class="px-5 py-4 text-right lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('quran-partial-tests.show', $partialTest) }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.quran_partial_tests.actions.open') }}</a>
                                    </div>
                                </td>
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

    <x-admin.modal :show="$showFormModal" :title="__('workflow.quran_partial_tests.form.title')" close-method="closeFormModal" max-width="2xl">
        <form wire:submit="save" class="space-y-3" data-searchable-refresh>
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label for="partial-test-student" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.form.student') }}</label>
                    <select
                        id="partial-test-student"
                        wire:key="partial-test-student-select"
                        wire:model.live="selectedStudentId"
                        data-search-input="true"
                        data-open-on-focus="true"
                        data-hide-placeholder-option="true"
                        data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}"
                        class="w-full rounded-xl px-4 py-3 text-sm"
                    >
                        <option value="">{{ __('workflow.quran_partial_tests.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">
                                {{ trim($student->first_name.' '.$student->last_name) }}
                            </option>
                        @endforeach
                    </select>
                    @error('selectedStudentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label for="partial-test-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_partial_tests.form.juz') }}</label>
                    @if ($eligibleJuzs->count() > 1)
                        <select
                            id="partial-test-juz"
                            wire:key="partial-test-juz-select-{{ $selectedStudentId ?: 'blank' }}-{{ $selectedEnrollmentId ?: 'blank' }}"
                            wire:model="juz_id"
                            class="w-full rounded-xl px-4 py-3 text-sm"
                        >
                            <option value="">{{ __('workflow.quran_partial_tests.form.select_juz') }}</option>
                            @foreach ($eligibleJuzs as $juz)
                                <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                            @endforeach
                        </select>
                    @else
                        <div id="partial-test-juz" class="quick-saber-readonly h-12 min-h-12">
                            {{ $eligibleJuzs->isNotEmpty() ? __('workflow.common.labels.juz_number', ['number' => $eligibleJuzs->first()->juz_number]) : __('workflow.quran_partial_tests.form.no_eligible_juzs') }}
                        </div>
                    @endif
                    @error('juz_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
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
                <button type="button" wire:click="openExistingTest" class="pill-link pill-link--accent">{{ __('workflow.quran_partial_tests.warnings.open_existing') }}</button>
            </div>
        </div>
    </x-admin.modal>
</div>

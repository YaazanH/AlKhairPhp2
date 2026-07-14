<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\AwqafSubject;
use App\Models\AwqafSubjectTest;
use App\Models\Enrollment;
use App\Models\Student;
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
    public ?int $awqaf_subject_id = null;
    public string $tested_on = '';
    public string $score = '';
    public string $status = 'passed';
    public string $notes = '';
    public string $search = '';
    public string $statusFilter = 'all';
    public string $subjectFilter = 'all';
    public string $sortField = 'tested_on';
    public string $sortDirection = 'desc';
    public int $perPage = 15;
    public bool $showFormModal = false;

    protected array $sortableFields = [
        'score',
        'status',
        'student',
        'subject',
        'tested_on',
    ];

    public function mount(): void
    {
        $this->authorizePermission('awqaf-subject-tests.view');
        $this->resetForm();
    }

    public function with(): array
    {
        $testsQuery = $this->scopeAwqafSubjectTestsQuery(
            AwqafSubjectTest::query()->with([
                'student.parentProfile',
                'enrollment.group.course',
                'subject',
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
                        ->orWhereHas('subject', fn (Builder $subjectQuery) => $subjectQuery->where('name', 'like', $search)->orWhere('code', 'like', $search))
                        ->orWhereHas('enrollment.group', fn (Builder $groupQuery) => $groupQuery->where('name', 'like', $search))
                        ->orWhere('notes', 'like', $search);
                });
            })
            ->when(
                in_array($this->statusFilter, ['passed', 'failed'], true),
                fn (Builder $query) => $query->where('status', $this->statusFilter)
            )
            ->when(
                $this->subjectFilter !== 'all' && filled($this->subjectFilter),
                fn (Builder $query) => $query->where('awqaf_subject_id', (int) $this->subjectFilter)
            );
        $this->applySort($testsQuery);

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

        $subjectOptions = AwqafSubject::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
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
            'subjectOptions' => $subjectOptions,
            'stats' => [
                'tests' => $this->scopeAwqafSubjectTestsQuery(AwqafSubjectTest::query())->count(),
                'passed' => $this->scopeAwqafSubjectTestsQuery(AwqafSubjectTest::query()->where('status', 'passed'))->count(),
                'students' => $studentOptions->count(),
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

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedStudentId(): void
    {
        $enrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->selectedEnrollmentId = count($enrollmentIds) === 1 ? $enrollmentIds[0] : null;
        $this->resetValidation(['selectedStudentId', 'selectedEnrollmentId']);
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
            $this->sortDirection = in_array($field, ['student', 'subject', 'status'], true) ? 'asc' : 'desc';
        }

        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorizePermission('awqaf-subject-tests.record');
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
        $this->authorizePermission('awqaf-subject-tests.record');

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'awqaf_subject_id' => ['required', 'exists:awqaf_subjects,id'],
            'tested_on' => ['required', 'date'],
            'score' => ['nullable', 'numeric', 'between:0,1000'],
            'status' => ['required', 'in:passed,failed'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'selectedStudentId' => __('workflow.awqaf_subject_tests.form.student'),
            'selectedEnrollmentId' => __('workflow.awqaf_subject_tests.form.group'),
            'awqaf_subject_id' => __('workflow.awqaf_subject_tests.form.subject'),
        ]);

        AwqafSubject::query()->where('is_active', true)->findOrFail((int) $validated['awqaf_subject_id']);

        $availableEnrollmentIds = $this->availableEnrollmentsQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($availableEnrollmentIds === []) {
            $this->addError('selectedStudentId', __('workflow.awqaf_subject_tests.errors.no_active_enrollment'));

            return;
        }

        if (! $validated['selectedEnrollmentId']) {
            if (count($availableEnrollmentIds) > 1) {
                $this->addError('selectedEnrollmentId', __('workflow.awqaf_subject_tests.errors.select_group'));

                return;
            }

            $validated['selectedEnrollmentId'] = $availableEnrollmentIds[0];
            $this->selectedEnrollmentId = $validated['selectedEnrollmentId'];
        }

        abort_unless(in_array((int) $validated['selectedEnrollmentId'], $availableEnrollmentIds, true), 403);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with('student')
        )->findOrFail((int) $validated['selectedEnrollmentId']);

        AwqafSubjectTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'awqaf_subject_id' => (int) $validated['awqaf_subject_id'],
            'tested_on' => $validated['tested_on'],
            'score' => $validated['score'] !== '' ? $validated['score'] : null,
            'status' => $validated['status'],
            'created_by' => auth()->id(),
            'notes' => $validated['notes'] ?: null,
        ]);

        session()->flash('status', __('workflow.awqaf_subject_tests.messages.created'));
        $this->closeFormModal();
    }

    public function delete(int $testId): void
    {
        $this->authorizePermission('awqaf-subject-tests.delete');

        $test = $this->scopeAwqafSubjectTestsQuery(AwqafSubjectTest::query())->findOrFail($testId);
        $test->delete();

        session()->flash('status', __('workflow.awqaf_subject_tests.messages.deleted'));
    }

    public function resetForm(): void
    {
        $this->selectedStudentId = null;
        $this->selectedEnrollmentId = null;
        $this->awqaf_subject_id = null;
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

    protected function applySort(Builder $query): void
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortField) {
            'score' => $query->orderBy('score', $direction)->orderByDesc('id'),
            'status' => $query->orderBy('status', $direction)->orderByDesc('id'),
            'student' => $query
                ->orderBy(
                    Student::query()
                        ->select('first_name')
                        ->whereColumn('students.id', 'awqaf_subject_tests.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy(
                    Student::query()
                        ->select('last_name')
                        ->whereColumn('students.id', 'awqaf_subject_tests.student_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            'subject' => $query
                ->orderBy(
                    AwqafSubject::query()
                        ->select('name')
                        ->whereColumn('awqaf_subjects.id', 'awqaf_subject_tests.awqaf_subject_id')
                        ->limit(1),
                    $direction,
                )
                ->orderByDesc('id'),
            default => $query->orderBy('tested_on', $direction)->orderBy('id', $direction),
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
        <div class="eyebrow">{{ __('workflow.awqaf_subject_tests.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.awqaf_subject_tests.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.awqaf_subject_tests.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3 text-sm">
            <span class="badge-soft">{{ __('workflow.awqaf_subject_tests.stats.tests') }}: {{ number_format($stats['tests']) }}</span>
            <span class="badge-soft badge-soft--emerald">{{ __('workflow.awqaf_subject_tests.stats.passed') }}: {{ number_format($stats['passed']) }}</span>
            <span class="badge-soft">{{ __('workflow.awqaf_subject_tests.stats.students') }}: {{ number_format($stats['students']) }}</span>
        </div>
    </section>

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.awqaf_subject_tests.table.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.awqaf_subject_tests.table.copy') }}</p>
            </div>
            <div class="admin-toolbar__actions">
                <label class="admin-filter">
                    <span>{{ __('workflow.common.search') }}</span>
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('workflow.awqaf_subject_tests.filters.search_placeholder') }}">
                </label>
                <label class="admin-filter">
                    <span>{{ __('workflow.awqaf_subject_tests.filters.subject') }}</span>
                    <select wire:model.live="subjectFilter">
                        <option value="all">{{ __('workflow.awqaf_subject_tests.filters.all_subjects') }}</option>
                        @foreach ($subjectOptions as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="admin-filter">
                    <span>{{ __('workflow.awqaf_subject_tests.filters.status') }}</span>
                    <select wire:model.live="statusFilter">
                        <option value="all">{{ __('workflow.awqaf_subject_tests.filters.all_statuses') }}</option>
                        <option value="passed">{{ __('workflow.awqaf_subject_tests.statuses.passed') }}</option>
                        <option value="failed">{{ __('workflow.awqaf_subject_tests.statuses.failed') }}</option>
                    </select>
                </label>
                @can('awqaf-subject-tests.record')
                    <button type="button" wire:click="openCreateModal" class="pill-link pill-link--accent">{{ __('workflow.awqaf_subject_tests.actions.create') }}</button>
                @endcan
            </div>
        </div>
    </section>

    <section class="surface-panel overflow-hidden">
        <div class="admin-grid-meta px-5 py-4 lg:px-6">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.awqaf_subject_tests.table.title') }}</div>
                <p class="admin-grid-meta__subtitle">{{ __('workflow.common.filtered_count', ['count' => number_format($filteredCount)]) }}</p>
            </div>
        </div>

        @if ($tests->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.awqaf_subject_tests.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-neutral-400">
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6"><button type="button" wire:click="sortBy('student')" class="inline-flex items-center gap-2 font-medium text-inherit">{{ __('workflow.awqaf_subject_tests.table.headers.student') }} <span>{{ $this->sortIndicator('student') }}</span></button></th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.awqaf_subject_tests.table.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6"><button type="button" wire:click="sortBy('subject')" class="inline-flex items-center gap-2 font-medium text-inherit">{{ __('workflow.awqaf_subject_tests.table.headers.subject') }} <span>{{ $this->sortIndicator('subject') }}</span></button></th>
                            <th class="px-5 py-4 text-left lg:px-6"><button type="button" wire:click="sortBy('tested_on')" class="inline-flex items-center gap-2 font-medium text-inherit">{{ __('workflow.awqaf_subject_tests.table.headers.tested_on') }} <span>{{ $this->sortIndicator('tested_on') }}</span></button></th>
                            <th class="px-5 py-4 text-left lg:px-6"><button type="button" wire:click="sortBy('score')" class="inline-flex items-center gap-2 font-medium text-inherit">{{ __('workflow.awqaf_subject_tests.table.headers.score') }} <span>{{ $this->sortIndicator('score') }}</span></button></th>
                            <th class="px-5 py-4 text-left lg:px-6"><button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-2 font-medium text-inherit">{{ __('workflow.awqaf_subject_tests.table.headers.status') }} <span>{{ $this->sortIndicator('status') }}</span></button></th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.awqaf_subject_tests.table.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($tests as $test)
                            <tr>
                                <td class="px-5 py-4 text-white lg:px-6">
                                    <div class="font-semibold">{{ trim($test->student?->first_name.' '.$test->student?->last_name) }}</div>
                                    <div class="mt-1 text-xs text-neutral-500">{{ $test->student?->student_number ?: __('workflow.common.not_available') }}</div>
                                </td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    <div>{{ $test->enrollment?->group?->name ?: __('workflow.common.not_available') }}</div>
                                    <div class="mt-1 text-xs text-neutral-500">{{ $test->enrollment?->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                                </td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $test->subject?->name ?: __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->tested_on?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->score !== null ? number_format((float) $test->score, 2) : __('workflow.common.not_available') }}</td>
                                <td class="px-5 py-4 lg:px-6"><span class="status-chip {{ $test->status === 'passed' ? 'status-chip--emerald' : 'status-chip--slate' }}">{{ __('workflow.awqaf_subject_tests.statuses.'.$test->status) }}</span></td>
                                <td class="px-5 py-4 text-right lg:px-6">
                                    @can('awqaf-subject-tests.delete')
                                        <button type="button" wire:click="delete({{ $test->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($tests->hasPages())
                <div class="border-t border-white/10 px-5 py-4 lg:px-6">
                    {{ $tests->links() }}
                </div>
            @endif
        @endif
    </section>

    <x-admin.modal :show="$showFormModal" :title="__('workflow.awqaf_subject_tests.form.title')" :description="__('workflow.awqaf_subject_tests.form.help')" close-method="closeFormModal" max-width="4xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="awqaf-subject-test-student" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.student') }}</label>
                    <select id="awqaf-subject-test-student" wire:model.live="selectedStudentId" class="searchable-select w-full rounded-xl px-4 py-3 text-sm" data-search-placeholder="{{ __('workflow.awqaf_subject_tests.form.search_student') }}">
                        <option value="">{{ __('workflow.awqaf_subject_tests.form.select_student') }}</option>
                        @foreach ($studentOptions as $student)
                            <option value="{{ $student->id }}">{{ trim($student->first_name.' '.$student->last_name) }} @if($student->student_number) - {{ $student->student_number }} @endif</option>
                        @endforeach
                    </select>
                    @error('selectedStudentId') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="awqaf-subject-test-enrollment" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.group') }}</label>
                    <select id="awqaf-subject-test-enrollment" wire:model="selectedEnrollmentId" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.awqaf_subject_tests.form.select_group') }}</option>
                        @foreach ($enrollmentOptions as $enrollment)
                            <option value="{{ $enrollment->id }}">{{ $enrollment->group?->name }} @if($enrollment->group?->course) - {{ $enrollment->group->course->name }} @endif</option>
                        @endforeach
                    </select>
                    @if ($selectedEnrollmentId)
                        <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.awqaf_subject_tests.form.group_auto') }}</div>
                    @endif
                    @error('selectedEnrollmentId') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="awqaf-subject-test-subject" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.subject') }}</label>
                    <select id="awqaf-subject-test-subject" wire:model="awqaf_subject_id" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('workflow.awqaf_subject_tests.form.select_subject') }}</option>
                        @foreach ($subjectOptions as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                    @error('awqaf_subject_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="awqaf-subject-test-date" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.tested_on') }}</label>
                    <input id="awqaf-subject-test-date" wire:model="tested_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('tested_on') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="awqaf-subject-test-score" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.score') }}</label>
                    <input id="awqaf-subject-test-score" wire:model="score" type="number" min="0" max="1000" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('score') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="awqaf-subject-test-status" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.status') }}</label>
                    <select id="awqaf-subject-test-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="passed">{{ __('workflow.awqaf_subject_tests.statuses.passed') }}</option>
                        <option value="failed">{{ __('workflow.awqaf_subject_tests.statuses.failed') }}</option>
                    </select>
                    @error('status') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <label for="awqaf-subject-test-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.awqaf_subject_tests.form.notes') }}</label>
                <textarea id="awqaf-subject-test-notes" wire:model="notes" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('notes') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeFormModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.awqaf_subject_tests.actions.save') }}</button>
                <x-admin.create-and-new-button click="saveAndNew('save', 'openCreateModal')" />
            </div>
        </form>
    </x-admin.modal>
</div>

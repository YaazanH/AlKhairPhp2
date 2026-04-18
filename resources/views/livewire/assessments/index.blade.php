<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Group;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use SupportsCreateAndNew;
    use WithPagination;

    public ?int $editingId = null;
    public ?int $group_id = null;
    public array $group_ids = [];
    public string $group_scope = 'single';
    public ?int $assessment_type_id = null;
    public string $title = '';
    public string $description = '';
    public string $scheduled_at = '';
    public string $due_at = '';
    public bool $due_at_manually_changed = false;
    public string $total_mark = '';
    public string $pass_mark = '';
    public bool $is_active = true;
    public string $courseFilter = 'all';
    public int $perPage = 15;
    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorizePermission('assessments.view');
    }

    public function with(): array
    {
        $groupQuery = $this->scopeGroupsQuery(
            Group::query()->with(['course', 'academicYear'])->orderBy('name')
        );
        $availableGroups = (clone $groupQuery)->get();
        $courseIds = $availableGroups->pluck('course_id')->filter()->unique()->values();
        $assessmentQuery = $this->scopeAssessmentsQuery(
            Assessment::query()
                ->with(['group.course', 'groups.course', 'type'])
                ->withCount('results')
                ->when($this->courseFilter !== 'all', function ($query) {
                    $query->where(function ($builder) {
                        $builder
                            ->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter))
                            ->orWhereHas('groups', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter));
                    });
                })
                ->latest('scheduled_at')
                ->latest('id')
        );

        $filteredCount = (clone $assessmentQuery)->count();
        $assessmentIds = (clone $assessmentQuery)->pluck('id');

        return [
            'groups' => $availableGroups,
            'courses' => Course::query()
                ->whereIn('id', $courseIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'types' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(),
            'assessments' => $assessmentQuery->paginate($this->perPage),
            'totals' => [
                'all' => (clone $assessmentQuery)->count(),
                'active' => (clone $assessmentQuery)->where('is_active', true)->count(),
                'passed_students' => $assessmentIds->isEmpty()
                    ? 0
                    : AssessmentResult::query()
                        ->whereIn('assessment_id', $assessmentIds)
                        ->where('status', 'passed')
                        ->distinct('student_id')
                        ->count('student_id'),
            ],
            'filteredCount' => $filteredCount,
        ];
    }

    public function updatedCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatedScheduledAt(): void
    {
        if ($this->due_at_manually_changed) {
            return;
        }

        $this->due_at = $this->defaultDueAtFor($this->scheduled_at);
    }

    public function updatedDueAt(): void
    {
        $this->due_at_manually_changed = true;
    }

    public function rules(): array
    {
        $rules = [
            'group_scope' => ['required', 'in:single,multiple,all'],
            'assessment_type_id' => ['required', 'exists:assessment_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'total_mark' => ['nullable', 'numeric', 'gt:0'],
            'pass_mark' => ['nullable', 'numeric', 'min:0', 'lte:total_mark'],
            'is_active' => ['boolean'],
        ];

        if ($this->group_scope === 'single') {
            $rules['group_id'] = ['required', 'exists:groups,id'];
        }

        if ($this->group_scope === 'multiple') {
            $rules['group_ids'] = ['required', 'array', 'min:1'];
            $rules['group_ids.*'] = ['integer', 'exists:groups,id'];
        }

        return $rules;
    }

    public function create(): void
    {
        $this->authorizePermission('assessments.create');

        $this->cancel(closeForm: false);
        $this->scheduled_at = now()->format('Y-m-d\TH:i');
        $this->due_at = now()->addDays(7)->format('Y-m-d\TH:i');
        $this->due_at_manually_changed = false;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'assessments.update' : 'assessments.create');

        $validated = $this->validate();
        $groupIds = $this->selectedGroupIds();

        if ($groupIds === []) {
            $this->addError('group_id', __('workflow.assessments.index.errors.no_groups_selected'));

            return;
        }

        $groups = Group::query()->whereIn('id', $groupIds)->get()->keyBy('id');

        foreach ($groupIds as $groupId) {
            $this->authorizeTeacherGroupAccess($groups->get($groupId) ?? Group::query()->findOrFail($groupId));
        }

        $payload = [
            'group_id' => $groupIds[0],
            'assessment_type_id' => $validated['assessment_type_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'scheduled_at' => $validated['scheduled_at'] ?: null,
            'due_at' => $validated['due_at'] ?: null,
            'total_mark' => $validated['total_mark'] !== '' ? $validated['total_mark'] : null,
            'pass_mark' => $validated['pass_mark'] !== '' ? $validated['pass_mark'] : null,
            'is_active' => $validated['is_active'],
        ];

        $assessment = $this->editingId
            ? tap(Assessment::query()->findOrFail($this->editingId))->update($payload)
            : Assessment::query()->create($payload + ['created_by' => auth()->id()]);

        $this->syncAssessmentGroups($assessment, $groupIds);

        session()->flash('status', $this->editingId ? __('workflow.assessments.index.messages.updated') : __('workflow.assessments.index.messages.created'));
        $this->cancel();
    }

    public function edit(int $assessmentId): void
    {
        $this->authorizePermission('assessments.update');

        $assessment = Assessment::query()->with(['group', 'groups'])->findOrFail($assessmentId);
        $this->authorizeTeacherAssessmentAccess($assessment);

        $groupIds = $assessment->groups->pluck('id')->all();
        if ($groupIds === [] && $assessment->group_id) {
            $groupIds = [(int) $assessment->group_id];
        }

        $this->editingId = $assessment->id;
        $this->group_id = $groupIds[0] ?? $assessment->group_id;
        $this->group_ids = array_map('strval', $groupIds);
        $this->group_scope = count($groupIds) > 1 ? 'multiple' : 'single';
        $this->assessment_type_id = $assessment->assessment_type_id;
        $this->title = $assessment->title;
        $this->description = $assessment->description ?? '';
        $this->scheduled_at = $assessment->scheduled_at?->format('Y-m-d\TH:i') ?? '';
        $this->due_at = $assessment->due_at?->format('Y-m-d\TH:i') ?? '';
        $this->due_at_manually_changed = true;
        $this->total_mark = $assessment->total_mark !== null ? number_format((float) $assessment->total_mark, 2, '.', '') : '';
        $this->pass_mark = $assessment->pass_mark !== null ? number_format((float) $assessment->pass_mark, 2, '.', '') : '';
        $this->is_active = $assessment->is_active;
        $this->showForm = true;

        $this->resetValidation();
    }

    public function cancel(bool $closeForm = true): void
    {
        $this->editingId = null;
        $this->group_id = null;
        $this->group_ids = [];
        $this->group_scope = 'single';
        $this->assessment_type_id = null;
        $this->title = '';
        $this->description = '';
        $this->scheduled_at = '';
        $this->due_at = '';
        $this->due_at_manually_changed = false;
        $this->total_mark = '';
        $this->pass_mark = '';
        $this->is_active = true;

        if ($closeForm) {
            $this->showForm = false;
        }

        $this->resetValidation();
    }

    public function delete(int $assessmentId): void
    {
        $this->authorizePermission('assessments.delete');

        $assessment = Assessment::query()->withCount('results')->with('group')->findOrFail($assessmentId);
        $this->authorizeTeacherAssessmentAccess($assessment);

        if ($assessment->results_count > 0) {
            $this->addError('delete', __('workflow.assessments.index.errors.delete_results'));

            return;
        }

        $assessment->delete();

        if ($this->editingId === $assessmentId) {
            $this->cancel();
        }

        session()->flash('status', __('workflow.assessments.index.messages.deleted'));
    }

    protected function defaultDueAtFor(string $scheduledAt): string
    {
        if (blank($scheduledAt)) {
            return '';
        }

        try {
            return Carbon::parse($scheduledAt)->addDays(7)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    protected function selectedGroupIds(): array
    {
        if ($this->group_scope === 'single') {
            return $this->group_id ? [(int) $this->group_id] : [];
        }

        if ($this->group_scope === 'multiple') {
            return collect($this->group_ids)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return $this->scopeGroupsQuery(Group::query())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function syncAssessmentGroups(Assessment $assessment, array $groupIds): void
    {
        $assessment->groupDetails()
            ->whereNotIn('group_id', $groupIds)
            ->delete();

        foreach ($groupIds as $groupId) {
            $assessment->groupDetails()->updateOrCreate(
                ['group_id' => $groupId],
                ['group_id' => $groupId],
            );
        }
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="eyebrow">{{ __('ui.nav.assessments') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.assessments.index.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.assessments.index.subtitle') }}</p>
            </div>

            @can('assessment-score-bands.view')
                <a href="{{ route('assessments.bands') }}" class="pill-link">
                    {{ __('workflow.common.actions.score_bands') }}
                </a>
            @endcan
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.index.stats.all') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['all']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.index.stats.active') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['active']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.assessments.index.stats.passed_students') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['passed_students']) }}</div>
        </article>
    </section>

    <div class="space-y-6">
        @if ($showForm)
        <section class="admin-modal" role="dialog" aria-modal="true">
            <div class="admin-modal__backdrop" wire:click="cancel"></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--3xl">
                    <div class="admin-modal__header">
                        <div>
                            <div class="admin-modal__title">{{ $editingId ? __('workflow.assessments.index.form.edit_title') : __('workflow.assessments.index.form.create_title') }}</div>
                            <p class="admin-modal__description">{{ __('workflow.assessments.index.form.help') }}</p>
                        </div>
                        <button type="button" wire:click="cancel" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                    </div>
                    <div class="admin-modal__body">
            @if (auth()->user()->can('assessments.create') || auth()->user()->can('assessments.update'))
                <div class="mb-4 md:hidden">
                    <h2 class="text-lg font-semibold text-white">{{ $editingId ? __('workflow.assessments.index.form.edit_title') : __('workflow.assessments.index.form.create_title') }}</h2>
                    <p class="text-sm text-neutral-400">{{ __('workflow.assessments.index.form.help') }}</p>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.group_scope') }}</label>
                            <select wire:model.live="group_scope" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="single">{{ __('workflow.assessments.index.form.group_scope_options.single') }}</option>
                                <option value="multiple">{{ __('workflow.assessments.index.form.group_scope_options.multiple') }}</option>
                                <option value="all">{{ __('workflow.assessments.index.form.group_scope_options.all') }}</option>
                            </select>
                            @error('group_scope') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>

                        @if ($group_scope === 'single')
                            <div>
                                <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.group') }}</label>
                                <select wire:model="group_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    <option value="">{{ __('workflow.assessments.index.form.select_group') }}</option>
                                    @foreach ($groups as $group)
                                        <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                                    @endforeach
                                </select>
                                @error('group_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </div>
                        @elseif ($group_scope === 'multiple')
                            <div>
                                <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.groups') }}</label>
                                <select wire:model="group_ids" multiple size="6" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                    @foreach ($groups as $group)
                                        <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                                    @endforeach
                                </select>
                                <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.assessments.index.form.multiple_groups_help') }}</div>
                                @error('group_ids') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                            </div>
                        @else
                            <div class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-neutral-300">
                                {{ __('workflow.assessments.index.form.all_groups_help', ['count' => number_format($groups->count())]) }}
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.assessment_type') }}</label>
                        <select wire:model="assessment_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('workflow.assessments.index.form.select_type') }}</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('assessment_type_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.title') }}</label>
                        <input wire:model="title" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('title') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.scheduled_at') }}</label>
                            <input wire:model="scheduled_at" type="datetime-local" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('scheduled_at') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.due_at') }}</label>
                            <input wire:model="due_at" type="datetime-local" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('due_at') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.total_mark') }}</label>
                            <input wire:model="total_mark" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('total_mark') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.pass_mark') }}</label>
                            <input wire:model="pass_mark" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('pass_mark') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.description') }}</label>
                        <textarea wire:model="description" rows="4" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        @error('description') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <label class="flex items-center gap-3 text-sm">
                        <input wire:model="is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900">
                        <span>{{ __('workflow.assessments.index.form.active_assessment') }}</span>
                    </label>

                    @error('delete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror

                    <div class="flex gap-3">
                        <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('workflow.assessments.index.form.update_submit') : __('workflow.assessments.index.form.create_submit') }}</button>
                        <x-admin.create-and-new-button :show="! $editingId" click="saveAndNew('save', 'create')" />
                        @if ($editingId)
                            <button type="button" wire:click="cancel" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            @else
                <div class="admin-empty-state">{{ __('workflow.assessments.index.read_only') }}</div>
            @endif
                    </div>
                </div>
            </div>
        </section>
        @endif

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.assessments.index.table.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
                </div>
                <div class="admin-toolbar__controls">
                    <div class="admin-filter-field">
                        <label for="assessment-course-filter">{{ __('workflow.assessments.index.filters.course') }}</label>
                        <select id="assessment-course-filter" wire:model.live="courseFilter">
                            <option value="all">{{ __('workflow.assessments.index.filters.all_courses') }}</option>
                            @foreach ($courses as $course)
                                <option value="{{ $course->id }}">{{ $course->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @can('assessments.create')
                        <button type="button" wire:click="create" class="pill-link pill-link--accent">
                            {{ __('workflow.assessments.index.form.create_title') }}
                        </button>
                    @endcan
                </div>
            </div>

            @if ($assessments->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.assessments.index.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.assessment') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.schedule') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.marks') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.results') }}</th>
                                <th class="px-5 py-3 text-right font-medium">{{ __('workflow.assessments.index.table.headers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($assessments as $assessment)
                                @php
                                    $assessmentGroups = $assessment->groups->isNotEmpty()
                                        ? $assessment->groups
                                        : collect([$assessment->group])->filter();
                                @endphp
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="font-medium">{{ $assessment->title }}</div>
                                        <div class="text-xs text-neutral-500">
                                            {{ $assessment->type?->name ?: __('workflow.common.not_available') }} |
                                            {{ $assessmentGroups->pluck('name')->take(2)->implode(', ') ?: __('workflow.common.not_available') }}
                                            @if ($assessmentGroups->count() > 2)
                                                {{ __('workflow.assessments.index.table.more_groups', ['count' => $assessmentGroups->count() - 2]) }}
                                            @endif
                                        </div>
                                        <div class="mt-1 text-xs text-neutral-500">
                                            {{ $assessmentGroups->pluck('course.name')->filter()->unique()->implode(', ') ?: __('workflow.common.not_available') }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div>{{ $assessment->scheduled_at?->format('Y-m-d H:i') ?: __('workflow.common.not_available') }}</div>
                                        <div class="text-xs text-neutral-500">{{ __('workflow.common.labels.due', ['value' => $assessment->due_at?->format('Y-m-d H:i') ?: __('workflow.common.not_available')]) }}</div>
                                    </td>
                                    <td class="px-5 py-3">
                                        <div>{{ __('workflow.common.labels.total', ['value' => $assessment->total_mark !== null ? number_format((float) $assessment->total_mark, 2) : __('workflow.common.not_available')]) }}</div>
                                        <div class="text-xs text-neutral-500">{{ __('workflow.common.labels.pass', ['value' => $assessment->pass_mark !== null ? number_format((float) $assessment->pass_mark, 2) : __('workflow.common.not_available')]) }}</div>
                                    </td>
                                    <td class="px-5 py-3">{{ number_format($assessment->results_count) }}</td>
                                    <td class="px-5 py-3">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            @can('assessment-results.view')
                                                <a href="{{ route('assessments.results', $assessment) }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.common.actions.results') }}</a>
                                            @endcan
                                            @can('assessments.update')
                                                <button type="button" wire:click="edit({{ $assessment->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                            @endcan
                                            @can('assessments.delete')
                                                <button type="button" wire:click="delete({{ $assessment->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($assessments->hasPages())
                    <div class="border-t border-white/8 px-5 py-4 lg:px-6">
                        {{ $assessments->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</div>

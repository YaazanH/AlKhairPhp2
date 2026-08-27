<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Group;
use App\Services\AssessmentService;
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

    public ?int $editingId = null;

    public ?int $group_id = null;

    public array $group_ids = [];

    public string $group_scope = 'all';

    public ?int $assessment_type_id = null;

    public string $title = '';

    public string $description = '';

    public string $due_at = '';

    public string $total_mark = '';

    public string $pass_mark = '';

    public string $groupCourseFilter = 'all';

    public string $courseFilter = 'all';

    public string $statusFilter = 'active';

    public int $perPage = 15;

    public bool $showForm = false;

    public bool $showGroupPicker = false;

    public bool $returnToResults = false;

    public function mount(): void
    {
        $this->authorizePermission('assessments.view');
        $defaultCourseId = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->courseFilter = (string) ($defaultCourseId ?? 'all');
        $this->groupCourseFilter = (string) ($defaultCourseId ?? 'all');
        if (request()->filled('edit')) {
            $this->returnToResults = request('return_to') === 'results';
            $this->edit((int) request('edit'));
        }
    }

    public function with(): array
    {
        $groupQuery = $this->scopeGroupsQuery(
            Group::query()
                ->with(['course', 'academicYear'])
                ->where('is_active', true)
                ->whereHas('course', fn ($query) => $query->where('is_active', true))
                ->orderBy('name')
        );
        $allAvailableGroups = (clone $groupQuery)->get();
        $availableGroups = $allAvailableGroups
            ->when($this->groupCourseFilter !== 'all', fn ($groups) => $groups->where('course_id', (int) $this->groupCourseFilter))
            ->values();
        $courseIds = $allAvailableGroups->pluck('course_id')->filter()->unique()->values();
        $assessmentQuery = $this->visibleAssessmentsQuery(
            Assessment::query()
                ->with(['group.course', 'groups.course', 'type'])
                ->withCount('results')
                ->withAvg('results', 'score')
                ->when($this->statusFilter === 'active', fn ($query) => $query->where('is_active', true))
                ->when($this->courseFilter !== 'all', function ($query) {
                    $query->where(function ($builder) {
                        $builder
                            ->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter))
                            ->orWhereHas('groups', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter));
                    });
                })
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('due_at')
                ->latest('id')
        );

        $filteredCount = (clone $assessmentQuery)->count();

        return [
            'groups' => $availableGroups,
            'courses' => Course::query()
                ->where('is_active', true)
                ->whereIn('id', $courseIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'types' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(),
            'assessments' => $assessmentQuery->paginate($this->perPage),
            'filteredCount' => $filteredCount,
            'editingHasResults' => $this->editingId
                ? AssessmentResult::query()->where('assessment_id', $this->editingId)->exists()
                : false,
        ];
    }

    public function updatedCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedGroupCourseFilter(): void
    {
        $this->group_id = null;
        $this->group_ids = [];
        $this->resetValidation(['group_id', 'group_ids']);
    }

    public function updatedGroupScope(): void
    {
        $this->group_id = null;
        $this->group_ids = [];
        $this->showGroupPicker = false;
        $this->resetValidation(['group_id', 'group_ids']);
    }

    public function openGroupPicker(): void
    {
        if ($this->group_scope !== 'all') {
            $this->showGroupPicker = true;
        }
    }

    public function updatedAssessmentTypeId(): void
    {
        $this->syncMarksFromScoreBands();
    }

    public function toggleGroup(int $groupId): void
    {
        if (! in_array($this->group_scope, ['single', 'multiple'], true)) {
            return;
        }

        $group = $this->scopeGroupsQuery(Group::query())
            ->when($this->groupCourseFilter !== 'all', fn ($query) => $query->where('course_id', (int) $this->groupCourseFilter))
            ->findOrFail($groupId);
        $this->authorizeTeacherGroupAccess($group);

        if ($this->group_scope === 'single') {
            $this->group_id = $groupId;
            $this->group_ids = [(string) $groupId];
            $this->showGroupPicker = false;
            $this->resetValidation(['group_id', 'group_ids']);

            return;
        }

        $selected = collect($this->group_ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        $this->group_ids = ($selected->contains($groupId)
            ? $selected->reject(fn ($id) => $id === $groupId)
            : $selected->push($groupId))
            ->sort()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $this->resetValidation('group_ids');
    }

    public function rules(): array
    {
        $rules = [
            'groupCourseFilter' => ['required', 'integer', 'exists:courses,id'],
            'group_scope' => ['required', 'in:single,multiple,all'],
            'assessment_type_id' => ['required', 'exists:assessment_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
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
        $this->returnToResults = false;
        $defaultCourseId = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->groupCourseFilter = (string) ($defaultCourseId ?? '');
        $this->group_scope = 'all';
        $this->due_at = '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission($this->editingId ? 'assessments.update' : 'assessments.create');

        $validated = $this->validate();
        $groupIds = $this->selectedGroupIds();
        $marks = app(AssessmentService::class)->markRangeForType((int) $validated['assessment_type_id']);

        if ($groupIds === []) {
            $this->addError('group_id', __('workflow.assessments.index.errors.no_groups_selected'));

            return;
        }

        $groups = Group::query()->whereIn('id', $groupIds)->get()->keyBy('id');

        if ($groups->pluck('course_id')->filter()->unique()->count() !== 1
            || (int) $groups->first()?->course_id !== (int) $validated['groupCourseFilter']) {
            $this->addError('groupCourseFilter', __('workflow.assessments.index.errors.single_course_required'));

            return;
        }

        foreach ($groupIds as $groupId) {
            $this->authorizeTeacherGroupAccess($groups->get($groupId) ?? Group::query()->findOrFail($groupId));
        }

        $payload = [
            'group_id' => $groupIds[0],
            'group_scope' => $validated['group_scope'],
            'assessment_type_id' => $validated['assessment_type_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?: null,
            'due_at' => $validated['due_at'] ?: null,
            'total_mark' => $marks['total_mark'],
            'pass_mark' => $marks['pass_mark'],
            'is_active' => true,
        ];

        $assessment = DB::transaction(function () use ($payload, $groupIds): Assessment {
            $assessment = $this->editingId
                ? tap($this->visibleAssessmentsQuery(Assessment::query())->findOrFail($this->editingId))->update($payload)
                : Assessment::query()->create($payload + ['created_by' => auth()->id()]);

            $this->syncAssessmentGroups($assessment, $groupIds);

            return $assessment;
        });

        $returnToResults = $this->returnToResults;
        session()->flash('status', $this->editingId ? __('workflow.assessments.index.messages.updated') : __('workflow.assessments.index.messages.created'));
        $this->cancel();

        if ($returnToResults) {
            $this->redirect(route('assessments.results', $assessment), navigate: true);
        }
    }

    public function edit(int $assessmentId): void
    {
        $this->authorizePermission('assessments.update');

        $assessment = $this->visibleAssessmentsQuery(
            Assessment::query()->with(['group', 'groups'])
        )->findOrFail($assessmentId);
        $this->authorizeTeacherAssessmentAccess($assessment);

        $groupIds = $assessment->groups->pluck('id')->all();
        if ($groupIds === [] && $assessment->group_id) {
            $groupIds = [(int) $assessment->group_id];
        }

        $this->editingId = $assessment->id;
        $this->group_id = $groupIds[0] ?? $assessment->group_id;
        $this->group_ids = array_map('strval', $groupIds);
        $this->group_scope = in_array($assessment->group_scope, ['single', 'multiple', 'all'], true)
            ? $assessment->group_scope
            : (count($groupIds) > 1 ? 'multiple' : 'single');
        $courseIds = $assessment->groups->pluck('course_id')->filter()->unique();
        $this->groupCourseFilter = (string) ($courseIds->first() ?? $assessment->group?->course_id ?? '');
        $this->group_ids = $assessment->groups
            ->where('course_id', (int) $this->groupCourseFilter)
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $this->group_id = $this->group_ids !== [] ? (int) $this->group_ids[0] : $assessment->group_id;
        $this->assessment_type_id = $assessment->assessment_type_id;
        $this->title = $assessment->title;
        $this->description = $assessment->description ?? '';
        $this->due_at = $assessment->due_at?->format('Y-m-d') ?? '';
        $this->syncMarksFromScoreBands();
        $this->showForm = true;

        $this->resetValidation();
    }

    public function cancel(bool $closeForm = true): void
    {
        $this->editingId = null;
        $this->group_id = null;
        $this->group_ids = [];
        $this->group_scope = 'all';
        $this->assessment_type_id = null;
        $this->title = '';
        $this->description = '';
        $this->due_at = '';
        $this->total_mark = '';
        $this->pass_mark = '';
        $this->showGroupPicker = false;
        $this->groupCourseFilter = (string) (Course::query()->where('is_default', true)->where('is_active', true)->value('id') ?? 'all');

        if ($closeForm) {
            $this->showForm = false;
        }

        $this->resetValidation();
    }

    public function closeForm(): void
    {
        $assessmentId = $this->editingId;
        $returnToResults = $this->returnToResults;

        $this->cancel();
        $this->returnToResults = false;

        if ($returnToResults && $assessmentId) {
            $this->redirect(route('assessments.results', $assessmentId), navigate: true);
        }
    }

    public function delete(int $assessmentId): void
    {
        $this->authorizePermission('assessments.delete');

        $assessment = $this->visibleAssessmentsQuery(
            Assessment::query()->with('group')->withCount('results')
        )->findOrFail($assessmentId);
        $this->authorizeTeacherAssessmentAccess($assessment);

        if ($assessment->results_count > 0) {
            $this->addError('delete', __('workflow.assessments.index.errors.delete_results'));

            return;
        }

        DB::transaction(function () use ($assessment): void {
            $assessment->groupDetails()->delete();
            $assessment->delete();
        });

        if ($this->editingId === $assessmentId) {
            $this->cancel();
        }

        session()->flash('status', __('workflow.assessments.index.messages.deleted'));
    }

    protected function visibleAssessmentsQuery(Builder $query): Builder
    {
        return $this->scopeAssessmentsQuery($query)
            ->whereNull('course_finished_at')
            ->whereDoesntHave('group.course', fn (Builder $courseQuery) => $courseQuery->whereNotNull('finished_at'))
            ->whereDoesntHave('groups.course', fn (Builder $courseQuery) => $courseQuery->whereNotNull('finished_at'));
    }

    protected function syncMarksFromScoreBands(): void
    {
        $marks = app(AssessmentService::class)->markRangeForType($this->assessment_type_id);
        $this->total_mark = $this->formatDerivedMark($marks['total_mark']);
        $this->pass_mark = $this->formatDerivedMark($marks['pass_mark']);
    }

    protected function formatDerivedMark(?float $mark): string
    {
        return $mark === null ? '' : rtrim(rtrim(number_format($mark, 2, '.', ''), '0'), '.');
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
            ->where('is_active', true)
            ->whereHas('course', fn ($query) => $query->where('is_active', true))
            ->when($this->groupCourseFilter !== 'all', fn ($query) => $query->where('course_id', (int) $this->groupCourseFilter))
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

        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="space-y-6">
        @if ($showForm)
        <section class="admin-modal" role="dialog" aria-modal="true">
            <div class="admin-modal__backdrop" wire:click="closeForm"></div>
            <div class="admin-modal__viewport">
                <div class="admin-modal__dialog admin-modal__dialog--3xl">
                    <div class="admin-modal__header">
                        <div>
                            <div class="admin-modal__title">{{ $editingId ? __('workflow.assessments.index.form.edit_title') : __('workflow.assessments.index.form.create_title') }}</div>
                        </div>
                        <button type="button" wire:click="closeForm" class="admin-modal__close" aria-label="{{ __('crud.common.actions.cancel') }}">×</button>
                    </div>
                    <div class="admin-modal__body">
            @if (auth()->user()->can('assessments.create') || auth()->user()->can('assessments.update'))
                <form wire:submit.prevent="save" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.title') }}</label>
                            <input wire:model="title" type="text" class="h-11 w-full rounded-lg border border-neutral-300 px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('title') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.due_at') }}</label>
                            <input wire:model="due_at" type="date" class="h-11 w-full rounded-lg px-3 text-sm">
                            @error('due_at')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_9rem_9rem] md:items-end">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.assessment_type') }}</label><select wire:model.live="assessment_type_id" class="h-11 w-full rounded-lg px-3 text-sm"><option value="">{{ __('workflow.assessments.index.form.select_type') }}</option>@foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>@error('assessment_type_id')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.pass_mark') }}</label><div class="flex h-11 items-center rounded-lg border border-white/10 px-3 text-sm font-semibold text-white">{{ $pass_mark !== '' ? $pass_mark : '—' }}</div></div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.total_mark') }}</label><div class="flex h-11 items-center rounded-lg border border-white/10 px-3 text-sm font-semibold text-white">{{ $total_mark !== '' ? $total_mark : '—' }}</div></div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label for="assessment-group-course" class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.course') }}</label><select id="assessment-group-course" wire:model.live="groupCourseFilter" class="w-full rounded-lg px-3 py-2 text-sm"><option value="">{{ __('crud.common.select') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select>@error('groupCourseFilter')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('workflow.assessments.index.form.groups') }}</label>
                            <div class="flex gap-2">
                                <select wire:model.live="group_scope" class="min-w-0 flex-1 rounded-lg px-3 py-2 text-sm"><option value="single">{{ __('workflow.assessments.index.form.group_scope_options.single') }}</option><option value="multiple">{{ __('workflow.assessments.index.form.group_scope_options.multiple') }}</option><option value="all">{{ __('workflow.assessments.index.form.group_scope_options.all') }}</option></select>
                                @if ($group_scope !== 'all')
                                    <button type="button" wire:click="openGroupPicker" class="pill-link px-4" aria-label="{{ __('workflow.assessments.index.form.groups') }}">…</button>
                                @endif
                            </div>
                            @error('group_id')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror
                            @error('group_ids')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    @error('delete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror

                    <div class="flex gap-3">
                        <button type="submit" class="pill-link pill-link--accent">{{ $editingId ? __('workflow.assessments.index.form.update_submit') : __('workflow.assessments.index.form.create_submit') }}</button>
                        <x-admin.create-and-new-button :show="! $editingId" click="saveAndNew('save', 'create')" />
                        @if ($editingId)
                            @can('assessments.delete')
                                <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" @disabled($editingHasResults) class="pill-link border-red-400/25 text-red-200 disabled:cursor-not-allowed disabled:opacity-40">{{ __('crud.common.actions.delete') }}</button>
                            @endcan
                        @endif
                    </div>
                </form>
                @if ($showGroupPicker)
                    <div class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                        <button type="button" wire:click="$set('showGroupPicker', false)" class="absolute inset-0 bg-black/70" aria-label="{{ __('crud.common.actions.close') }}"></button>
                        <div class="relative max-h-[75vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-white/10 bg-neutral-950 p-5 shadow-2xl">
                            <div class="mb-4 flex items-center justify-between gap-3"><h3 class="text-lg font-semibold text-white">{{ __('workflow.assessments.index.form.groups') }}</h3><button type="button" wire:click="$set('showGroupPicker', false)" class="admin-modal__close">×</button></div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($groups as $group)
                                    @php
                                        $isSelected = $group_scope === 'single'
                                            ? (int) $group_id === $group->id
                                            : in_array((string) $group->id, array_map('strval', $group_ids), true);
                                    @endphp
                                    <button type="button" wire:click="toggleGroup({{ $group->id }})" class="flex items-center gap-3 rounded-xl border px-3 py-3 text-start {{ $isSelected ? 'border-emerald-400/40 bg-emerald-500/10 text-white' : 'border-white/10 text-neutral-300' }}">
                                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded border {{ $isSelected ? 'border-emerald-400 bg-emerald-500' : 'border-white/20' }}">{{ $isSelected ? '✓' : '' }}</span>
                                        <span><span class="block font-medium">{{ $group->name }}</span><span class="text-xs text-neutral-500">{{ $group->course?->name }}</span></span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @else
                <div class="admin-empty-state">{{ __('workflow.assessments.index.read_only') }}</div>
            @endif
                    </div>
                </div>
            </div>
        </section>
        @endif

        <section class="surface-table mobile-records-surface">
            <div class="admin-grid-meta admin-grid-meta--controls">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.assessments.index.table.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($filteredCount)]) }}</div>
                </div>
                <div class="admin-toolbar__controls">
                    <div class="admin-filter-field">
                        <label class="sr-only" for="assessment-course-filter">{{ __('workflow.assessments.index.filters.course') }}</label>
                        <select id="assessment-course-filter" wire:model.live="courseFilter">
                            <option value="all">{{ __('workflow.assessments.index.filters.all_courses') }}</option>
                            @foreach ($courses as $course)
                                <option value="{{ $course->id }}">{{ $course->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="admin-filter-field">
                        <label class="sr-only" for="assessment-status-filter">{{ __('workflow.assessments.index.table.headers.status') }}</label>
                        <select id="assessment-status-filter" wire:model.live="statusFilter">
                            <option value="active">{{ __('crud.common.status_options.active') }}</option>
                            <option value="all">{{ __('crud.common.filters.all_statuses') }}</option>
                        </select>
                    </div>
                    @can('assessments.create')
                        <button type="button" wire:click="create" class="pill-link pill-link--accent inline-flex min-w-40 justify-center text-center">
                            {{ __('workflow.assessments.index.form.create_title') }}
                        </button>
                    @endcan
                </div>
            </div>

            @if ($assessments->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.assessments.index.table.empty') }}</div>
            @else
                <div class="assessment-index-table-scroll overflow-x-auto">
                    <table class="w-full text-sm assessment-index-table">
                        <thead>
                            <tr>
                                <th class="w-[4%] px-2 py-3 text-center font-medium">#</th>
                                <th class="w-[20%] px-4 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.assessment') }}</th>
                                <th class="w-[18%] px-4 py-3 text-left font-medium">{{ __('workflow.assessments.index.form.course') }}</th>
                                <th class="w-[12%] px-3 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.schedule') }}</th>
                                <th class="w-[9%] px-2 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.results') }}</th>
                                <th class="w-[10%] px-3 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.average') }}</th>
                                <th class="w-[10%] px-2 py-3 text-left font-medium">{{ __('workflow.assessments.index.table.headers.status') }}</th>
                                <th class="w-[17%] px-3 py-3 text-end font-medium">{{ __('workflow.assessments.index.table.headers.actions') }}</th>
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
                                    <td class="px-2 py-3 text-center text-neutral-400">{{ $assessments->firstItem() + $loop->index }}</td>
                                    <td class="px-4 py-3">
                                        <div class="truncate whitespace-nowrap font-medium" title="{{ $assessment->title }}">{{ $assessment->title }}</div>
                                        <div class="text-xs text-neutral-500">
                                            {{ $assessment->type?->name ?: __('workflow.common.not_available') }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3"><div class="truncate" title="{{ $assessmentGroups->pluck('course.name')->filter()->unique()->implode(', ') }}">{{ $assessmentGroups->pluck('course.name')->filter()->unique()->implode(', ') ?: __('workflow.common.not_available') }}</div></td>
                                    <td class="px-5 py-3">
                                        <div>{{ $assessment->due_at?->format('d-m-Y') ?: __('workflow.common.not_available') }}</div>
                                    </td>
                                    <td class="px-5 py-3">{{ number_format($assessment->results_count) }}</td>
                                    <td class="px-3 py-3">
                                        @if ($assessment->results_avg_score !== null)
                                            <span dir="ltr">{{ number_format((float) $assessment->results_avg_score, 0) }}%</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-2 py-3"><span class="{{ $assessment->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">{{ $assessment->is_active ? __('crud.common.status_options.active') : ($assessment->course_finished_at ? __('crud.common.status_options.finished') : __('crud.common.status_options.inactive')) }}</span></td>
                                    <td class="px-5 py-3 text-end">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            @can('assessment-results.view')
                                                <a href="{{ route('assessments.results', $assessment) }}" wire:navigate class="pill-link pill-link--compact">{{ app()->isLocale('ar') ? 'فتح' : 'Open' }}</a>
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

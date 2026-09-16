<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\DataQualityResolution;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Services\DataQualityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithPagination;

    public string $search = '';
    public string $typeFilter = 'all';
    public string $severityFilter = 'all';
    public ?string $selectedIssueKey = null;
    public string $editableType = '';
    public array $editableRecords = [];
    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorizePermission('data-quality.view');
    }

    public function render(): mixed
    {
        return parent::render()->title(__('data_governance.quality.title'));
    }

    public function refreshTable(): void
    {
        $this->authorizePermission('data-quality.view');
    }

    public function with(): array
    {
        $allIssues = app(DataQualityService::class)->issues();
        $activeIssues = $allIssues->where('status', 'open')->values();
        $filtered = $activeIssues
            ->when($this->typeFilter !== 'all', fn (Collection $issues) => $issues->where('type', $this->typeFilter))
            ->when($this->severityFilter !== 'all', fn (Collection $issues) => $issues->where('severity', $this->severityFilter))
            ->when(filled($this->search), function (Collection $issues): Collection {
                $needle = Str::lower($this->search);

                return $issues->filter(fn (array $issue): bool => Str::contains(Str::lower(implode(' ', [
                    $issue['title'], $issue['reason'], ...$issue['records'],
                ])), $needle));
            })
            ->values();

        $page = $this->getPage();
        $issues = new LengthAwarePaginator(
            $filtered->forPage($page, $this->perPage)->values(),
            $filtered->count(),
            $this->perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page'],
        );

        return [
            'issues' => $issues,
            'selectedIssue' => $this->selectedIssueKey
                ? $activeIssues->firstWhere('key', $this->selectedIssueKey)
                : null,
            'highPriorityCount' => $activeIssues->where('severity', 'high')->count(),
            'types' => $activeIssues->pluck('type')->unique()->values(),
        ];
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedSeverityFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = 'all';
        $this->severityFilter = 'all';
        $this->resetPage();
    }

    public function review(string $issueKey): void
    {
        $issue = app(DataQualityService::class)->issues()
            ->where('status', 'open')
            ->firstWhere('key', $issueKey);
        abort_unless($issue, 404);

        $this->selectedIssueKey = $issueKey;
        $this->loadEditableRecords($issue);
    }

    public function closeReview(): void
    {
        $this->selectedIssueKey = null;
        $this->editableType = '';
        $this->editableRecords = [];
        $this->resetValidation();
    }

    public function autosaveRecord(int $index): void
    {
        $this->authorizePermission('data-quality.resolve');
        abort_unless(isset($this->editableRecords[$index]), 404);

        $saved = DB::transaction(function (): bool {
            return match ($this->editableType) {
                'student' => $this->saveStudentEdits(),
                'parent' => $this->saveParentEdits(),
                'enrollment' => $this->saveEnrollmentEdits(),
                'group' => $this->saveGroupEdits(),
                default => abort(422),
            };
        });

        if (! $saved) {
            return;
        }

        session()->flash('status', __('data_governance.quality.messages.edits_saved'));
        $this->closeReview();
    }

    public function saveAndResolveParentContact(): void
    {
        $this->authorizePermission('data-quality.resolve');

        $issue = app(DataQualityService::class)->issues()->firstWhere('key', $this->selectedIssueKey);
        abort_unless($issue && $issue['type'] === 'missing_parent_contact' && $this->editableType === 'parent', 404);

        $saved = DB::transaction(function () use ($issue): bool {
            if (! $this->saveParentEdits()) {
                return false;
            }

            DataQualityResolution::query()->updateOrCreate(
                ['issue_key' => $issue['key']],
                [
                    'issue_type' => $issue['type'],
                    'status' => 'resolved',
                    'resolved_by' => auth()->id(),
                    'notes' => null,
                    'resolved_at' => now(),
                ],
            );

            return true;
        });

        if (! $saved) {
            return;
        }

        session()->flash('status', __('data_governance.quality.messages.resolved'));
        $this->closeReview();
    }

    public function deleteRecord(int $recordId): void
    {
        $this->authorizePermission('data-quality.resolve');

        $issue = app(DataQualityService::class)->issues()->firstWhere('key', $this->selectedIssueKey);
        abort_unless(
            $issue
            && (str_starts_with($issue['type'], 'duplicate_') || $issue['type'] === 'overlapping_enrollment')
            && in_array($recordId, $issue['entity_ids'], true),
            404,
        );

        DB::transaction(function () use ($recordId): void {
            match ($this->editableType) {
                'student' => $this->deleteStudentRecord($recordId),
                'parent' => $this->deleteParentRecord($recordId),
                'enrollment' => $this->deleteEnrollmentRecord($recordId),
                default => abort(422),
            };
        });

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        session()->flash('status', __('data_governance.quality.messages.record_deleted'));
        $this->closeReview();
    }

    public function decide(string $status): void
    {
        $this->authorizePermission('data-quality.resolve');

        abort_unless(in_array($status, ['resolved', 'not_duplicate'], true), 422);
        $issue = app(DataQualityService::class)->issues()->firstWhere('key', $this->selectedIssueKey);
        abort_unless($issue, 404);

        DataQualityResolution::query()->updateOrCreate(
            ['issue_key' => $issue['key']],
            [
                'issue_type' => $issue['type'],
                'status' => $status,
                'resolved_by' => auth()->id(),
                'notes' => null,
                'resolved_at' => now(),
            ],
        );

        session()->flash('status', __('data_governance.quality.messages.'.$status));
        $this->closeReview();
    }

    protected function loadEditableRecords(array $issue): void
    {
        $this->editableType = $issue['entity_type'];
        $ids = $issue['entity_ids'];

        $this->editableRecords = match ($this->editableType) {
            'student' => Student::query()
                ->with(['parentProfile:id,father_name,parent_number', 'user:id,name,email,phone', 'gradeLevel:id,name', 'quranCurrentJuz:id,juz_number'])
                ->withCount(['enrollments', 'memorizationSessions', 'pageAchievements'])
                ->whereIn('id', $ids)->get()->map(fn (Student $student): array => [
                'id' => $student->id,
                'label' => trim($student->first_name.' '.$student->last_name),
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'birth_date' => $student->birth_date?->format('Y-m-d') ?? '',
                'school_name' => $student->school_name ?? '',
                'status' => $student->status,
                'joined_at' => $student->joined_at?->format('Y-m-d') ?? '',
                'details' => $this->recordDetails($student, [
                    'parent_name' => $student->parentProfile?->father_name,
                    'parent_number' => $student->parentProfile?->parent_number,
                    'user_name' => $student->user?->name,
                    'user_email' => $student->user?->email,
                    'user_phone' => $student->user?->phone,
                    'grade_level' => $student->gradeLevel?->name,
                    'quran_current_juz' => $student->quranCurrentJuz?->juz_number,
                    'page_achievements_count' => $student->page_achievements_count,
                ], [
                    'enrollments_count' => $student->enrollments_count,
                    'memorization_sessions_count' => $student->memorization_sessions_count,
                ]),
            ])->all(),
            'parent' => ParentProfile::query()->with('user:id,name,email,phone')->withCount(['students', 'invoices'])->whereIn('id', $ids)->get()->map(fn (ParentProfile $parent): array => [
                'id' => $parent->id,
                'label' => $parent->father_name,
                'father_name' => $parent->father_name,
                'father_work' => $parent->father_work ?? '',
                'father_phone' => $parent->father_phone ?? '',
                'mother_name' => $parent->mother_name ?? '',
                'mother_phone' => $parent->mother_phone ?? '',
                'home_phone' => $parent->home_phone ?? '',
                'address' => $parent->address ?? '',
                'is_active' => (bool) $parent->is_active,
                'has_user' => (bool) $parent->user_id,
                'email' => $parent->user?->email ?? '',
                'details' => $this->recordDetails($parent, [
                    'user_name' => $parent->user?->name,
                    'user_email' => $parent->user?->email,
                    'user_phone' => $parent->user?->phone,
                    'students_count' => $parent->students_count,
                    'invoices_count' => $parent->invoices_count,
                ]),
            ])->all(),
            'enrollment' => Enrollment::query()->with(['student:id,first_name,last_name', 'group:id,name'])->whereIn('id', $ids)->get()->map(fn (Enrollment $enrollment): array => [
                'id' => $enrollment->id,
                'label' => trim($enrollment->student?->first_name.' '.$enrollment->student?->last_name).' · '.($enrollment->group?->name ?? '—'),
                'enrolled_at' => $enrollment->enrolled_at?->format('Y-m-d') ?? '',
                'status' => $enrollment->status,
                'left_at' => $enrollment->left_at?->format('Y-m-d') ?? '',
                'details' => $this->recordDetails($enrollment, [
                    'student_name' => trim($enrollment->student?->first_name.' '.$enrollment->student?->last_name),
                    'group_name' => $enrollment->group?->name,
                ]),
            ])->all(),
            'group' => Group::query()->with(['course:id,name', 'academicYear:id,name', 'teacher:id,first_name,last_name', 'assistantTeacher:id,first_name,last_name', 'gradeLevel:id,name', 'curriculum:id,name'])->withCount(['enrollments as active_enrollments_count' => fn ($query) => $query->where('status', 'active')])->whereIn('id', $ids)->get()->map(fn (Group $group): array => [
                'id' => $group->id,
                'label' => $group->name,
                'name' => $group->name,
                'capacity' => $group->capacity,
                'starts_on' => $group->starts_on?->format('Y-m-d') ?? '',
                'ends_on' => $group->ends_on?->format('Y-m-d') ?? '',
                'monthly_fee' => $group->monthly_fee ?? '',
                'is_active' => (bool) $group->is_active,
                'active_enrollments_count' => $group->active_enrollments_count,
                'details' => $this->recordDetails($group, [
                    'course_name' => $group->course?->name,
                    'academic_year' => $group->academicYear?->name,
                    'teacher_name' => trim($group->teacher?->first_name.' '.$group->teacher?->last_name),
                    'assistant_teacher_name' => trim($group->assistantTeacher?->first_name.' '.$group->assistantTeacher?->last_name),
                    'grade_level' => $group->gradeLevel?->name,
                    'curriculum_name' => $group->curriculum?->name,
                    'active_enrollments_count' => $group->active_enrollments_count,
                ]),
            ])->all(),
            default => [],
        };
    }

    protected function saveStudentEdits(): bool
    {
        $validated = $this->validate([
            'editableRecords.*.id' => ['required', 'integer', 'exists:students,id'],
            'editableRecords.*.first_name' => ['required', 'string', 'max:255'],
            'editableRecords.*.last_name' => ['required', 'string', 'max:255'],
            'editableRecords.*.birth_date' => ['required', 'date'],
            'editableRecords.*.school_name' => ['nullable', 'string', 'max:255'],
            'editableRecords.*.status' => ['required', 'in:active,inactive'],
            'editableRecords.*.joined_at' => ['nullable', 'date'],
        ]);

        $saved = false;

        foreach ($validated['editableRecords'] as $record) {
            foreach (['school_name', 'joined_at'] as $field) {
                $record[$field] = filled($record[$field] ?? null) ? $record[$field] : null;
            }

            $student = Student::query()->findOrFail($record['id']);
            $student->fill(collect($record)->except('id')->all());

            if ($student->isDirty()) {
                $student->save();
                $saved = true;
            }
        }

        return $saved;
    }

    protected function saveParentEdits(): bool
    {
        $validated = $this->validate([
            'editableRecords.*.id' => ['required', 'integer', 'exists:parents,id'],
            'editableRecords.*.father_name' => ['required', 'string', 'max:255'],
            'editableRecords.*.father_work' => ['nullable', 'string', 'max:255'],
            'editableRecords.*.father_phone' => ['nullable', 'string', 'max:50'],
            'editableRecords.*.mother_name' => ['nullable', 'string', 'max:255'],
            'editableRecords.*.mother_phone' => ['nullable', 'string', 'max:50'],
            'editableRecords.*.home_phone' => ['nullable', 'string', 'max:50'],
            'editableRecords.*.address' => ['nullable', 'string', 'max:255'],
            'editableRecords.*.is_active' => ['required', 'boolean'],
            'editableRecords.*.email' => ['nullable', 'email', 'max:255'],
        ]);

        $saved = false;

        foreach ($validated['editableRecords'] as $record) {
            foreach (['father_work', 'father_phone', 'mother_name', 'mother_phone', 'home_phone', 'address'] as $field) {
                $record[$field] = filled($record[$field] ?? null) ? $record[$field] : null;
            }

            $parent = ParentProfile::query()->with('user')->findOrFail($record['id']);
            $parent->fill(collect($record)->only(['father_name', 'father_work', 'father_phone', 'mother_name', 'mother_phone', 'home_phone', 'address', 'is_active'])->all());

            if ($parent->isDirty()) {
                $parent->save();
                $saved = true;
            }

            if ($parent->user) {
                $parent->user->fill(['email' => filled($record['email'] ?? null) ? trim($record['email']) : null]);

                if ($parent->user->isDirty()) {
                    $parent->user->save();
                    $saved = true;
                }
            }
        }

        return $saved;
    }

    protected function saveEnrollmentEdits(): bool
    {
        $validated = $this->validate([
            'editableRecords.*.id' => ['required', 'integer', 'exists:enrollments,id'],
            'editableRecords.*.enrolled_at' => ['required', 'date'],
            'editableRecords.*.status' => ['required', 'in:active,completed,cancelled'],
            'editableRecords.*.left_at' => ['nullable', 'date'],
        ]);

        $saved = false;

        foreach ($validated['editableRecords'] as $record) {
            $enrollment = Enrollment::query()->findOrFail($record['id']);
            $enrollment->fill([
                'enrolled_at' => $record['enrolled_at'],
                'status' => $record['status'],
                'left_at' => $record['status'] === 'active' ? null : ($record['left_at'] ?: $enrollment->left_at ?: now()->toDateString()),
            ]);

            if ($enrollment->isDirty()) {
                $enrollment->save();
                $saved = true;
            }
        }

        return $saved;
    }

    protected function saveGroupEdits(): bool
    {
        $validated = $this->validate([
            'editableRecords.*.id' => ['required', 'integer', 'exists:groups,id'],
            'editableRecords.*.name' => ['required', 'string', 'max:255'],
            'editableRecords.*.capacity' => ['required', 'integer', 'min:0'],
            'editableRecords.*.starts_on' => ['nullable', 'date'],
            'editableRecords.*.ends_on' => ['nullable', 'date', 'after_or_equal:editableRecords.*.starts_on'],
            'editableRecords.*.monthly_fee' => ['nullable', 'numeric', 'min:0'],
            'editableRecords.*.is_active' => ['required', 'boolean'],
        ]);

        $saved = false;

        foreach ($validated['editableRecords'] as $record) {
            foreach (['starts_on', 'ends_on', 'monthly_fee'] as $field) {
                $record[$field] = filled($record[$field] ?? null) ? $record[$field] : null;
            }

            $group = Group::query()->findOrFail($record['id']);
            $group->fill(collect($record)->except('id')->all());

            if ($group->isDirty()) {
                $group->save();
                $saved = true;
            }
        }

        return $saved;
    }

    protected function deleteStudentRecord(int $recordId): void
    {
        $this->authorizePermission('students.delete');
        $student = Student::query()->with('user')->withCount(['enrollments', 'memorizationSessions', 'pageAchievements'])->findOrFail($recordId);

        if ($student->enrollments_count > 0 || ($student->memorization_sessions_count + $student->page_achievements_count) > 0) {
            $this->addError('delete', __('data_governance.quality.errors.student_linked'));
            return;
        }

        $student->delete();
        $student->user?->delete();
    }

    protected function deleteParentRecord(int $recordId): void
    {
        $this->authorizePermission('parents.delete');
        $parent = ParentProfile::query()->with('user')->withCount('students')->findOrFail($recordId);

        if ($parent->students_count > 0) {
            $this->addError('delete', __('data_governance.quality.errors.parent_linked'));
            return;
        }

        $parent->delete();
        $parent->user?->delete();
    }

    protected function deleteEnrollmentRecord(int $recordId): void
    {
        $this->authorizePermission('enrollments.delete');
        Enrollment::query()->findOrFail($recordId)->delete();
    }

    protected function recordDetails(Model $model, array $extra = [], array $leading = []): array
    {
        $values = array_merge($leading, collect($model->getAttributes())->except(['deleted_at', 'notes'])->all(), $extra);

        return collect($values)->map(function (mixed $value, string $field): array {
            $isDate = in_array($field, [
                'birth_date',
                'joined_at',
                'enrolled_at',
                'left_at',
                'starts_on',
                'ends_on',
                'course_finished_at',
                'course_finished_previous_left_at',
                'created_at',
                'updated_at',
            ], true);

            if (in_array($field, ['is_active', 'course_finished_was_active'], true) && $value !== null) {
                $value = (bool) $value
                    ? __('crud.common.status_options.active')
                    : __('crud.common.status_options.inactive');
            }

            if ($isDate && filled($value)) {
                try {
                    $value = Carbon::parse($value)->format('d-m-Y');
                } catch (\Throwable) {
                    // Keep unexpected legacy values visible instead of hiding the field.
                }
            }

            return [
                'field' => __('data_governance.quality.record_fields.'.$field),
                'value' => filled($value) || $value === 0 ? (string) $value : '—',
                'direction' => str_contains($field, 'phone') || $isDate ? 'ltr' : 'auto',
            ];
        })->values()->all();
    }

    public function reopen(): void
    {
        $this->authorizePermission('data-quality.resolve');
        DataQualityResolution::query()->where('issue_key', $this->selectedIssueKey)->delete();
        session()->flash('status', __('data_governance.quality.messages.reopened'));
        $this->closeReview();
    }
}; ?>

<div class="page-stack" wire:init="refreshTable" data-data-quality-page data-data-quality-refresh-on-open>
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
            <div>
                <div class="eyebrow">{{ __('data_governance.quality.eyebrow') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('data_governance.quality.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('data_governance.quality.subtitle') }}</p>
            </div>
            <div class="shrink-0 rounded-2xl border border-red-300/30 bg-red-500/15 px-5 py-3 text-center shadow-inner" data-data-quality-high-priority>
                <div class="text-xs text-red-700 dark:text-red-100">{{ __('data_governance.quality.high_priority') }}</div>
                <bdi dir="ltr" class="mt-1 block text-lg font-semibold text-red-700 dark:text-red-100">{{ number_format($highPriorityCount) }}</bdi>
            </div>
        </div>
    </section>

    @if (session('status')) <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div> @endif

    <section class="surface-table">
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div class="admin-grid-meta__title">{{ __('data_governance.quality.table_title') }}</div>
            <div class="admin-toolbar__controls admin-toolbar__controls--compact">
                <div class="admin-filter-field">
                    <label class="sr-only" for="data-quality-search">{{ __('crud.common.filters.search') }}</label>
                    <input id="data-quality-search" wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('data_governance.quality.search_placeholder') }}">
                </div>
                <div class="admin-filter-field">
                    <label class="sr-only" for="data-quality-type">{{ __('data_governance.quality.all_types') }}</label>
                    <select id="data-quality-type" wire:model.live="typeFilter"><option value="all">{{ __('data_governance.quality.all_types') }}</option>@foreach ($types as $type)<option value="{{ $type }}">{{ __('data_governance.quality.types.'.$type) }}</option>@endforeach</select>
                </div>
                <div class="admin-filter-field">
                    <label class="sr-only" for="data-quality-severity">{{ __('data_governance.quality.all_severities') }}</label>
                    <select id="data-quality-severity" wire:model.live="severityFilter"><option value="all">{{ __('data_governance.quality.all_severities') }}</option>@foreach (['high','medium','low'] as $severity)<option value="{{ $severity }}">{{ __('data_governance.quality.'.$severity) }}</option>@endforeach</select>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead><tr><th class="w-16 px-5 py-4 text-center">#</th><th class="px-5 py-4 text-start">{{ __('data_governance.quality.issue') }}</th><th class="px-5 py-4 text-start">{{ __('data_governance.quality.records') }}</th><th class="px-5 py-4 text-center">{{ __('data_governance.quality.priority') }}</th><th class="px-5 py-4 text-center">{{ __('data_governance.quality.status') }}</th><th class="admin-actions-column px-5 py-4 text-center">{{ __('data_governance.quality.actions') }}</th></tr></thead>
                <tbody class="divide-y divide-white/10">
                @forelse ($issues as $issue)
                    <tr>
                        <td class="px-5 py-4 text-center tabular-nums text-neutral-400">{{ $issues->firstItem() + $loop->index }}</td>
                        <td class="px-5 py-4"><div class="font-semibold text-white">{{ $issue['title'] }}</div><div class="mt-1 text-xs leading-5 text-neutral-400">{{ $issue['reason'] }}</div></td>
                        <td class="px-5 py-4 text-neutral-300">@foreach ($issue['records'] as $record)<div @class(['mt-1' => !$loop->first])>{{ $record }}</div>@endforeach</td>
                        <td class="px-5 py-4 text-center"><span @class(['rounded-full border px-3 py-1 text-xs font-semibold','border-red-400/30 bg-red-500/10 text-red-100' => $issue['severity']==='high','border-amber-400/30 bg-amber-500/10 text-amber-100' => $issue['severity']==='medium','border-neutral-500/30 bg-neutral-500/10 text-neutral-300' => $issue['severity']==='low'])>{{ __('data_governance.quality.'.$issue['severity']) }}</span></td>
                        <td class="px-5 py-4 text-center"><span @class(['rounded-full border px-3 py-1 text-xs font-semibold','border-emerald-400/30 bg-emerald-500/10 text-emerald-100' => $issue['status'] === 'open','border-white/10 bg-white/5 text-neutral-200' => $issue['status'] !== 'open'])>{{ __('data_governance.quality.'.$issue['status']) }}</span></td>
                        <td class="px-5 py-4 text-center"><button type="button" wire:click="review('{{ $issue['key'] }}')" class="admin-icon-button" title="{{ __('data_governance.quality.review') }}" aria-label="{{ __('data_governance.quality.review') }}" data-data-quality-review-action><x-admin-action-icon name="review" /></button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-neutral-400">{{ __('data_governance.quality.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($issues->hasPages()) <div class="px-5 py-4">{{ $issues->links() }}</div> @endif
    </section>

    <x-admin.modal :show="(bool) $selectedIssue" :title="__('data_governance.quality.review_title')" :description="$selectedIssue['title'] ?? ''" close-method="closeReview" max-width="3xl">
        @if ($selectedIssue)
            <div class="space-y-5">
                <div><div class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ __('data_governance.quality.reason') }}</div><div class="mt-2 text-neutral-100">{{ $selectedIssue['reason'] }}</div></div>
                <div class="space-y-4" data-data-quality-edit-records>
                    @foreach ($editableRecords as $index => $record)
                        @if ($selectedIssue['type'] === 'missing_parent_contact' && $editableType === 'parent')
                            <div wire:key="data-quality-direct-parent-{{ $record['id'] }}" data-data-quality-direct-parent-editor>
                                @include('livewire.data-quality.partials.parent-editor', ['record' => $record, 'index' => $index, 'manualSave' => true])
                            </div>
                            @continue
                        @endif

                        <details class="admin-collapsible" wire:key="data-quality-edit-record-{{ $editableType }}-{{ $record['id'] }}" data-data-quality-record-panel>
                            <summary class="admin-collapsible__summary"><span>{{ $record['label'] }}</span><span class="admin-collapsible__count">#{{ $record['id'] }}</span></summary>
                            <div class="space-y-5 pt-2">
                                <div class="overflow-x-auto rounded-xl border border-white/10" data-data-quality-record-details>
                                    <table class="w-full text-sm">
                                        <tbody class="divide-y divide-white/10">
                                            @foreach ($record['details'] as $detail)
                                                <tr><th class="w-2/5 px-4 py-2 text-start font-medium text-neutral-400">{{ $detail['field'] }}</th><td class="px-4 py-2 text-start text-neutral-100 break-words"><bdi dir="{{ $detail['direction'] }}" @class(['whitespace-nowrap' => $detail['direction'] === 'ltr'])>{{ $detail['value'] }}</bdi></td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                @if (! str_starts_with($selectedIssue['type'], 'duplicate_') && $editableType === 'student')
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.first_name') }}</label><input wire:model="editableRecords.{{ $index }}.first_name" wire:change="autosaveRecord({{ $index }})" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error("editableRecords.$index.first_name")<div class="mt-1 text-xs text-red-300">{{ $message }}</div>@enderror</div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.last_name') }}</label><input wire:model="editableRecords.{{ $index }}.last_name" wire:change="autosaveRecord({{ $index }})" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error("editableRecords.$index.last_name")<div class="mt-1 text-xs text-red-300">{{ $message }}</div>@enderror</div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.birth_date') }}</label><input wire:model="editableRecords.{{ $index }}.birth_date" wire:change="autosaveRecord({{ $index }})" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error("editableRecords.$index.birth_date")<div class="mt-1 text-xs text-red-300">{{ $message }}</div>@enderror</div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.school_name') }}</label><input wire:model="editableRecords.{{ $index }}.school_name" wire:change="autosaveRecord({{ $index }})" type="text" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.status') }}</label><select wire:model="editableRecords.{{ $index }}.status" wire:change="autosaveRecord({{ $index }})" class="w-full rounded-xl px-4 py-3 text-sm"><option value="active">{{ __('crud.common.status_options.active') }}</option><option value="inactive">{{ __('crud.common.status_options.inactive') }}</option></select></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.joined_at') }}</label><input wire:model="editableRecords.{{ $index }}.joined_at" wire:change="autosaveRecord({{ $index }})" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                    </div>
                                @elseif (! str_starts_with($selectedIssue['type'], 'duplicate_') && $editableType === 'parent')
                                    @include('livewire.data-quality.partials.parent-editor', ['record' => $record, 'index' => $index])
                                @elseif ($editableType === 'enrollment')
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.enrolled_at') }}</label><input wire:model="editableRecords.{{ $index }}.enrolled_at" wire:change="autosaveRecord({{ $index }})" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.status') }}</label><select wire:model="editableRecords.{{ $index }}.status" wire:change="autosaveRecord({{ $index }})" class="w-full rounded-xl px-4 py-3 text-sm"><option value="active">{{ __('crud.common.status_options.active') }}</option><option value="completed">{{ __('crud.common.status_options.completed') }}</option><option value="cancelled">{{ __('crud.common.status_options.cancelled') }}</option></select></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.left_at') }}</label><input wire:model="editableRecords.{{ $index }}.left_at" wire:change="autosaveRecord({{ $index }})" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                    </div>
                                @elseif ($editableType === 'group')
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.name') }}</label><input wire:model="editableRecords.{{ $index }}.name" wire:change="autosaveRecord({{ $index }})" type="text" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.capacity') }} · {{ __('data_governance.quality.fields.active_enrollments') }}: {{ $record['active_enrollments_count'] }}</label><input wire:model="editableRecords.{{ $index }}.capacity" wire:change="autosaveRecord({{ $index }})" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.starts_on') }}</label><input wire:model="editableRecords.{{ $index }}.starts_on" wire:change="autosaveRecord({{ $index }})" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.ends_on') }}</label><input wire:model="editableRecords.{{ $index }}.ends_on" wire:change="autosaveRecord({{ $index }})" type="date" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.monthly_fee') }}</label><input wire:model="editableRecords.{{ $index }}.monthly_fee" wire:change="autosaveRecord({{ $index }})" type="number" min="0" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                                        <div><label class="mb-1 block text-sm">{{ __('data_governance.quality.fields.status') }}</label><select wire:model="editableRecords.{{ $index }}.is_active" wire:change="autosaveRecord({{ $index }})" class="w-full rounded-xl px-4 py-3 text-sm"><option value="1">{{ __('crud.common.status_options.active') }}</option><option value="0">{{ __('crud.common.status_options.inactive') }}</option></select></div>
                                    </div>
                                @endif

                                @php
                                    $deletePermission = match ($editableType) {
                                        'student' => 'students.delete',
                                        'parent' => 'parents.delete',
                                        'enrollment' => 'enrollments.delete',
                                        default => null,
                                    };
                                    $editPermission = match ($editableType) {
                                        'student' => 'students.update',
                                        'parent' => 'parents.update',
                                        default => null,
                                    };
                                    $editUrl = match ($editableType) {
                                        'student' => route('students.index', ['edit' => $record['id'], 'quality_issue' => $selectedIssue['key']]),
                                        'parent' => route('parents.index', ['edit' => $record['id'], 'quality_issue' => $selectedIssue['key']]),
                                        default => null,
                                    };
                                @endphp
                                @if (str_starts_with($selectedIssue['type'], 'duplicate_'))
                                    <div class="admin-action-cluster admin-action-cluster--end" data-data-quality-duplicate-record-actions>
                                        @if ($editPermission && $editUrl && auth()->user()->can($editPermission))
                                            <x-edit-action-button :href="$editUrl" wire:navigate :label="__('crud.common.actions.edit')" data-data-quality-edit-record />
                                        @endif
                                        @if ($deletePermission && auth()->user()->can($deletePermission))
                                            <x-delete-action-button wire:click="deleteRecord({{ $record['id'] }})" wire:confirm="{{ __('data_governance.quality.confirm_delete') }}" :label="__('crud.common.actions.delete')" data-data-quality-delete-record />
                                        @endif
                                    </div>
                                @elseif ($deletePermission && $selectedIssue['type'] === 'overlapping_enrollment' && auth()->user()->can($deletePermission))
                                    <div class="flex justify-end"><x-delete-action-button wire:click="deleteRecord({{ $record['id'] }})" wire:confirm="{{ __('data_governance.quality.confirm_delete') }}" :label="__('crud.common.actions.delete')" data-data-quality-delete-record /></div>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
                @error('delete')<div class="text-sm text-red-300">{{ $message }}</div>@enderror
                <div class="admin-action-cluster admin-action-cluster--end">
                    <button type="button" wire:click="closeReview" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                    @can('data-quality.resolve')
                        @if ($selectedIssue['status'] === 'open')
                            @if ($selectedIssue['type'] === 'missing_parent_contact')
                                <button type="button" wire:click="saveAndResolveParentContact" class="admin-icon-button admin-icon-button--accent" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-data-quality-resolve-save-action><x-admin-action-icon name="save" /></button>
                            @elseif (str_starts_with($selectedIssue['type'], 'duplicate_'))
                                <button type="button" wire:click="decide('not_duplicate')" class="pill-link" data-data-quality-not-duplicate-text-action>{{ __('data_governance.quality.mark_not_duplicate') }}</button>
                            @endif
                        @else
                            <button type="button" wire:click="reopen" class="pill-link pill-link--accent">{{ __('data_governance.quality.reopen') }}</button>
                        @endif
                    @endcan
                </div>
            </div>
        @endif
    </x-admin.modal>
</div>

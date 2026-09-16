<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AssessmentType;
use App\Models\GradeLevel;
use App\Services\CourseCompletionRuleService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public string $required_passed_final_tests = '1';
    public string $required_memorized_pages = '0';
    public string $final_rule_operator = 'and';
    public string $required_passed_quizzes = '1';
    public string $retain_percentage = '50';
    public string $minimum_points = '0';
    public array $assessment_type_requirements = [];
    public array $final_test_grade_ids = [];
    public array $additional_final_rules = [];
    public array $assessment_grade_ids = [];
    public array $assessment_rule_grade_ids = [];
    public bool $showGradeRuleModal = false;
    public bool $showAssessmentTypeModal = false;
    public array $enabled_assessment_type_ids = [];
    public array $assessment_type_selections = [];
    public ?int $assessmentRuleTypeId = null;
    public string $gradeRuleTarget = 'final';
    public int $gradeRuleRowIndex = 0;
    public array $gradeRuleOriginalGradeIds = [];
    public array $gradeRuleSelectedGradeIds = [];
    public string $academic_year_id = '';
    public string $course_id = '';
    public string $group_id = '';
    public string $enrollment_status = 'active';
    public ?array $apply_summary = null;

    public function mount(): void
    {
        $this->authorizePermission('course-completion-rules.manage');
        $this->loadSettings();
    }

    public function with(): array
    {
        $service = app(CourseCompletionRuleService::class);

        return [
            'assessmentTypes' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            ...$service->options(),
            'groups' => $service->groups([
                'academic_year_id' => $this->academic_year_id,
                'course_id' => $this->course_id,
                'group_id' => null,
                'enrollment_status' => $this->enrollment_status,
            ]),
        ];
    }

    public function applyRules(): void
    {
        $this->authorizePermission('course-completion-rules.manage');

        if ($this->academic_year_id === '' && $this->course_id === '' && $this->group_id === '') {
            $this->addError('scope', __('settings.course_completion.errors.filter_required'));
            return;
        }

        $this->apply_summary = app(CourseCompletionRuleService::class)->apply([
            'academic_year_id' => $this->academic_year_id,
            'course_id' => $this->course_id,
            'group_id' => $this->group_id,
            'enrollment_status' => $this->enrollment_status,
        ], auth()->user());

        session()->flash('status', __('settings.course_completion.messages.rules_applied', $this->apply_summary));
    }

    public function saveRules(): void
    {
        $this->authorizePermission('course-completion-rules.manage');

        $validated = $this->validate([
            'required_passed_final_tests' => ['required', 'integer', 'min:0'],
            'required_memorized_pages' => ['required', 'integer', 'min:0'],
            'final_rule_operator' => ['required', 'in:and,or'],
            'required_passed_quizzes' => ['required', 'integer', 'min:0'],
            'assessment_type_requirements' => ['nullable', 'array'],
            'assessment_type_requirements.*' => ['nullable', 'integer', 'min:0'],
            'enabled_assessment_type_ids' => ['array'],
            'enabled_assessment_type_ids.*' => ['integer', 'exists:assessment_types,id'],
            'final_test_grade_ids' => ['array'],
            'final_test_grade_ids.*' => ['integer', 'exists:grade_levels,id'],
            'additional_final_rules' => ['array'],
            'additional_final_rules.*.required_passed_final_tests' => ['required', 'integer', 'min:0'],
            'additional_final_rules.*.required_memorized_pages' => ['required', 'integer', 'min:0'],
            'additional_final_rules.*.final_rule_operator' => ['required', 'in:and,or'],
            'additional_final_rules.*.grade_ids' => ['required', 'array', 'min:1'],
            'additional_final_rules.*.grade_ids.*' => ['integer', 'exists:grade_levels,id'],
            'assessment_grade_ids' => ['array'],
            'assessment_grade_ids.*' => ['integer', 'exists:grade_levels,id'],
            'assessment_rule_grade_ids' => ['array'],
            'assessment_rule_grade_ids.*' => ['array'],
            'assessment_rule_grade_ids.*.*' => ['integer', 'exists:grade_levels,id'],
            'retain_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'minimum_points' => ['required', 'integer', 'min:0'],
        ]);

        $validated['assessment_type_requirements'] = collect($validated['assessment_type_requirements'] ?? [])
            ->only($validated['enabled_assessment_type_ids'] ?? [])
            ->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => max(0, (int) $value)])
            ->all();
        $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        if ($quizTypeId) {
            $validated['required_passed_quizzes'] = $validated['assessment_type_requirements'][(int) $quizTypeId] ?? 0;
        }

        $validated['assessment_rule_grade_ids'] = collect($validated['assessment_rule_grade_ids'] ?? [])
            ->only($validated['enabled_assessment_type_ids'] ?? [])
            ->map(fn (array $gradeIds): array => collect($gradeIds)->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->all();

        $validated['final_rule_rows'] = collect([[
            'required_passed_final_tests' => $validated['required_passed_final_tests'],
            'required_memorized_pages' => $validated['required_memorized_pages'],
            'final_rule_operator' => $validated['final_rule_operator'],
            'grade_ids' => $validated['final_test_grade_ids'],
        ], ...($validated['additional_final_rules'] ?? [])])
            ->map(fn (array $rule): array => [
                'required_passed_final_tests' => max(0, (int) $rule['required_passed_final_tests']),
                'required_memorized_pages' => max(0, (int) $rule['required_memorized_pages']),
                'final_rule_operator' => $rule['final_rule_operator'],
                'grade_ids' => collect($rule['grade_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all(),
            ])
            ->all();

        app(CourseCompletionRuleService::class)->saveSettings($validated);

        session()->flash('status', __('settings.course_completion.messages.rules_saved'));
    }


    protected function loadSettings(): void
    {
        $settings = app(CourseCompletionRuleService::class)->settings();

        $finalRules = $settings['final_rule_rows'];
        $primaryFinalRule = $finalRules[0];
        $this->required_passed_final_tests = (string) $primaryFinalRule['required_passed_final_tests'];
        $this->required_memorized_pages = (string) $primaryFinalRule['required_memorized_pages'];
        $this->final_rule_operator = $primaryFinalRule['final_rule_operator'];
        $this->required_passed_quizzes = (string) $settings['required_passed_quizzes'];
        $this->retain_percentage = (string) $settings['retain_percentage'];
        $this->minimum_points = (string) $settings['minimum_points'];
        $this->final_test_grade_ids = $primaryFinalRule['grade_ids'];
        $this->additional_final_rules = collect(array_slice($finalRules, 1))
            ->map(fn (array $rule): array => [
                'required_passed_final_tests' => (string) $rule['required_passed_final_tests'],
                'required_memorized_pages' => (string) $rule['required_memorized_pages'],
                'final_rule_operator' => $rule['final_rule_operator'],
                'grade_ids' => $rule['grade_ids'],
            ])
            ->values()
            ->all();
        $this->assessment_grade_ids = $settings['assessment_grade_ids'];
        $this->assessment_rule_grade_ids = $settings['assessment_rule_grade_ids'];

        $storedRequirements = $settings['assessment_type_requirements'] ?? [];
        $this->assessment_type_requirements = AssessmentType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id'])
            ->mapWithKeys(fn (AssessmentType $assessmentType): array => [
                $assessmentType->id => (string) ($storedRequirements[$assessmentType->id] ?? 0),
            ])
            ->all();
        $this->enabled_assessment_type_ids = collect($this->assessment_type_requirements)->filter(fn ($value) => (int) $value > 0)->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function openGradeRule(string $target, int $rowIndex = 0): void
    {
        $this->gradeRuleTarget = $target === 'assessment' ? 'assessment' : 'final';
        $this->gradeRuleRowIndex = $this->gradeRuleTarget === 'assessment' ? -1 : max(0, $rowIndex);

        if ($this->gradeRuleTarget === 'assessment') {
            $this->assessmentRuleTypeId = $rowIndex > 0 ? $rowIndex : null;
            $this->gradeRuleOriginalGradeIds = $this->assessmentRuleTypeId
                ? ($this->assessment_rule_grade_ids[$this->assessmentRuleTypeId] ?? $this->assessment_grade_ids)
                : $this->assessment_grade_ids;

            if ($this->gradeRuleOriginalGradeIds === []) {
                $this->gradeRuleOriginalGradeIds = $this->activeGradeIds();
            }
        } else {
            $this->assessmentRuleTypeId = null;
            $gradeIds = $this->finalRuleGradeIds($this->gradeRuleRowIndex);

            if ($gradeIds === []) {
                $gradeIds = $this->activeGradeIds();
                $this->setFinalRuleGradeIds($this->gradeRuleRowIndex, $gradeIds);
            }

            $this->gradeRuleOriginalGradeIds = $gradeIds;
        }

        $this->gradeRuleSelectedGradeIds = $this->gradeRuleOriginalGradeIds;

        $this->showGradeRuleModal = true;
    }

    public function saveGradeRuleModal(): void
    {
        $activeGradeIds = GradeLevel::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
        $selectedGradeIds = collect($this->gradeRuleSelectedGradeIds)
            ->map(fn ($id) => (int) $id)
            ->intersect($activeGradeIds)
            ->unique()
            ->values()
            ->all();

        if ($this->gradeRuleTarget === 'assessment') {
            if ($this->assessmentRuleTypeId) {
                $this->assessment_rule_grade_ids[$this->assessmentRuleTypeId] = $selectedGradeIds;
            } else {
                $this->assessment_grade_ids = $selectedGradeIds;
            }
        } else {
            if ($selectedGradeIds === []) {
                $this->addError('gradeRuleSelectedGradeIds', __('validation.required', [
                    'attribute' => __('settings.course_completion.labels.grades'),
                ]));

                return;
            }

            $rules = collect([$this->finalRuleAt(0), ...$this->additional_final_rules])->values()->all();
            $currentRuleIndex = min($this->gradeRuleRowIndex, count($rules) - 1);
            $currentRule = $rules[$currentRuleIndex] ?? $rules[0];
            $originalGradeIds = collect($currentRule['grade_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
            $removedGradeIds = $originalGradeIds->diff($selectedGradeIds)->values()->all();
            $reconciledRules = $rules;

            foreach ($reconciledRules as $index => &$rule) {
                if ($index === $currentRuleIndex) {
                    $rule['grade_ids'] = $selectedGradeIds;

                    continue;
                }

                $rule['grade_ids'] = collect($rule['grade_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->diff($selectedGradeIds)
                    ->unique()
                    ->values()
                    ->all();
            }
            unset($rule);

            if ($currentRuleIndex === 0 && $removedGradeIds !== []) {
                $splitRule = $currentRule;
                $splitRule['grade_ids'] = $removedGradeIds;
                array_splice($reconciledRules, 1, 0, [$splitRule]);
            } elseif ($currentRuleIndex > 0 && $removedGradeIds !== []) {
                $reconciledRules[0]['grade_ids'] = collect([
                    ...($reconciledRules[0]['grade_ids'] ?? []),
                    ...$removedGradeIds,
                ])->map(fn ($id) => (int) $id)->unique()->values()->all();
            }

            $primaryRule = array_shift($reconciledRules);
            $this->required_passed_final_tests = (string) $primaryRule['required_passed_final_tests'];
            $this->required_memorized_pages = (string) $primaryRule['required_memorized_pages'];
            $this->final_rule_operator = $primaryRule['final_rule_operator'];
            $this->final_test_grade_ids = $primaryRule['grade_ids'];
            $this->additional_final_rules = collect($reconciledRules)
                ->filter(fn (array $rule): bool => ($rule['grade_ids'] ?? []) !== [])
                ->map(fn (array $rule): array => [
                    'required_passed_final_tests' => (string) $rule['required_passed_final_tests'],
                    'required_memorized_pages' => (string) $rule['required_memorized_pages'],
                    'final_rule_operator' => $rule['final_rule_operator'],
                    'grade_ids' => $rule['grade_ids'],
                ])
                ->values()
                ->all();
        }

        $this->showGradeRuleModal = false;
        $this->gradeRuleOriginalGradeIds = [];
        $this->gradeRuleSelectedGradeIds = [];
        $this->assessmentRuleTypeId = null;
        $this->resetErrorBag('gradeRuleSelectedGradeIds');
    }

    public function openAssessmentTypeModal(): void
    {
        $this->assessment_type_selections = [];
        $this->showAssessmentTypeModal = true;
    }

    public function closeAssessmentTypeModal(): void
    {
        $this->assessment_type_selections = [];
        $this->showAssessmentTypeModal = false;
    }

    public function toggleAssessmentTypeSelection(int $assessmentTypeId): void
    {
        if (in_array($assessmentTypeId, $this->enabled_assessment_type_ids, true)
            || ! AssessmentType::query()->whereKey($assessmentTypeId)->where('is_active', true)->exists()) {
            return;
        }

        if (in_array($assessmentTypeId, $this->assessment_type_selections, true)) {
            $this->assessment_type_selections = collect($this->assessment_type_selections)
                ->reject(fn ($id) => (int) $id === $assessmentTypeId)
                ->values()
                ->all();

            return;
        }

        $this->assessment_type_selections[] = $assessmentTypeId;
    }

    public function addSelectedAssessmentType(): void
    {
        $assessmentTypeIds = AssessmentType::query()
            ->whereIn('id', $this->assessment_type_selections)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $this->enabled_assessment_type_ids, true))
            ->values()
            ->all();

        if ($assessmentTypeIds === []) {
            return;
        }

        $this->enabled_assessment_type_ids = collect([...$this->enabled_assessment_type_ids, ...$assessmentTypeIds])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($assessmentTypeIds as $assessmentTypeId) {
            $this->assessment_type_requirements[$assessmentTypeId] ??= '0';
            $this->assessment_rule_grade_ids[$assessmentTypeId] = $this->activeGradeIds();
        }

        $this->closeAssessmentTypeModal();
    }

    public function removeAssessmentRule(): void
    {
        if (! $this->assessmentRuleTypeId) {
            return;
        }

        $assessmentTypeId = $this->assessmentRuleTypeId;
        $this->enabled_assessment_type_ids = collect($this->enabled_assessment_type_ids)
            ->reject(fn ($id) => (int) $id === $assessmentTypeId)
            ->values()
            ->all();
        unset($this->assessment_type_requirements[$assessmentTypeId], $this->assessment_rule_grade_ids[$assessmentTypeId]);

        $this->showGradeRuleModal = false;
        $this->gradeRuleOriginalGradeIds = [];
        $this->gradeRuleSelectedGradeIds = [];
        $this->assessmentRuleTypeId = null;
    }

    public function deleteFinalRule(int $rowIndex): void
    {
        if ($rowIndex < 1 || ! isset($this->additional_final_rules[$rowIndex - 1])) {
            return;
        }

        $mainGradeIds = $this->final_test_grade_ids === [] ? $this->activeGradeIds() : $this->final_test_grade_ids;
        $deletedGradeIds = $this->additional_final_rules[$rowIndex - 1]['grade_ids'] ?? [];
        $this->final_test_grade_ids = collect([...$mainGradeIds, ...$deletedGradeIds])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        array_splice($this->additional_final_rules, $rowIndex - 1, 1);
        $this->showGradeRuleModal = false;
        $this->gradeRuleRowIndex = 0;
        $this->gradeRuleOriginalGradeIds = [];
        $this->gradeRuleSelectedGradeIds = [];
        $this->resetErrorBag('gradeRuleSelectedGradeIds');
    }

    protected function activeGradeIds(): array
    {
        return GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function finalRuleGradeIds(int $rowIndex): array
    {
        return $rowIndex === 0
            ? $this->final_test_grade_ids
            : ($this->additional_final_rules[$rowIndex - 1]['grade_ids'] ?? []);
    }

    protected function setFinalRuleGradeIds(int $rowIndex, array $gradeIds): void
    {
        if ($rowIndex === 0) {
            $this->final_test_grade_ids = $gradeIds;

            return;
        }

        if (isset($this->additional_final_rules[$rowIndex - 1])) {
            $this->additional_final_rules[$rowIndex - 1]['grade_ids'] = $gradeIds;
        }
    }

    protected function finalRuleAt(int $rowIndex): array
    {
        if ($rowIndex === 0) {
            return [
                'required_passed_final_tests' => $this->required_passed_final_tests,
                'required_memorized_pages' => $this->required_memorized_pages,
                'final_rule_operator' => $this->final_rule_operator,
                'grade_ids' => $this->final_test_grade_ids,
            ];
        }

        return $this->additional_final_rules[$rowIndex - 1];
    }

}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('ui.common.settings') }}</h1>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.course-completion" />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6">
        <section class="surface-panel settings-dark-surface p-5 lg:p-6" data-settings-dark-surface="course-completion-rules">
            <div class="admin-toolbar"><div class="admin-toolbar__title">{{ __('settings.course_completion.sections.rules.title') }}</div></div>

            <form wire:submit="saveRules" class="mt-5 space-y-4">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    @php
                        $finalRuleRows = collect([[
                            'required_passed_final_tests' => $required_passed_final_tests,
                            'required_memorized_pages' => $required_memorized_pages,
                            'final_rule_operator' => $final_rule_operator,
                            'grade_ids' => $final_test_grade_ids,
                        ]])->concat($additional_final_rules)->values();
                    @endphp

                    <div class="space-y-3">
                        @foreach ($finalRuleRows as $ruleIndex => $finalRule)
                            @php
                                $testsModel = $ruleIndex === 0 ? 'required_passed_final_tests' : 'additional_final_rules.'.($ruleIndex - 1).'.required_passed_final_tests';
                                $operatorModel = $ruleIndex === 0 ? 'final_rule_operator' : 'additional_final_rules.'.($ruleIndex - 1).'.final_rule_operator';
                                $pagesModel = $ruleIndex === 0 ? 'required_memorized_pages' : 'additional_final_rules.'.($ruleIndex - 1).'.required_memorized_pages';
                            @endphp
                            <div wire:key="course-completion-final-rule-{{ $ruleIndex }}" class="course-completion-rule-row grid gap-3 md:grid-cols-[minmax(0,1fr)_4.5rem_minmax(0,1fr)_3.125rem] md:items-end" data-course-completion-final-rule-row>
                                <div>
                                    @if ($ruleIndex === 0)<label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.required_passed_final_tests') }}</label>@endif
                                    <input wire:model="{{ $testsModel }}" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                                    @if ($errors->has($testsModel)) <div class="mt-1 text-sm text-red-400">{{ $errors->first($testsModel) }}</div> @endif
                                </div>
                                <div>
                                    <select wire:model="{{ $operatorModel }}" class="course-completion-operator w-full rounded-xl px-4 py-3" data-clearable="false" data-search-selection-required="true" data-show-chevron="false" data-search-placeholder=""><option value="and">{{ __('settings.course_completion.options.and') }}</option><option value="or">{{ __('settings.course_completion.options.or') }}</option></select>
                                    @if ($errors->has($operatorModel)) <div class="mt-1 text-sm text-red-400">{{ $errors->first($operatorModel) }}</div> @endif
                                </div>
                                <div>
                                    @if ($ruleIndex === 0)<label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.required_memorized_pages') }}</label>@endif
                                    <input wire:model="{{ $pagesModel }}" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                                    @if ($errors->has($pagesModel)) <div class="mt-1 text-sm text-red-400">{{ $errors->first($pagesModel) }}</div> @endif
                                </div>
                                <button type="button" wire:click="openGradeRule('final', {{ $ruleIndex }})" class="course-completion-grade-button" aria-label="{{ __('settings.course_completion.labels.choose_grades') }}" data-course-completion-grade-button>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75v-1.5a3.75 3.75 0 0 0-3.75-3.75h-4.5a3.75 3.75 0 0 0-3.75 3.75v1.5M10.5 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM15.75 10.9a2.65 2.65 0 1 0 0-5.3M17.25 13.75a3.5 3.5 0 0 1 3 3.46v1.54" />
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-end"><x-add-action-button wire:click="openAssessmentTypeModal" :label="__('settings.course_completion.actions.add_assessment_type')" :accent="false" /></div>

                    <div class="mt-4 space-y-3">
                        @forelse ($assessmentTypes->whereIn('id', $enabled_assessment_type_ids) as $assessmentType)
                            <div wire:key="course-completion-assessment-rule-{{ $assessmentType->id }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_3.125rem] sm:items-end" data-course-completion-assessment-rule>
                                <div>
                                    <label class="mb-1 block text-sm font-medium">{{ $assessmentType->name }}</label>
                                    <input wire:model="assessment_type_requirements.{{ $assessmentType->id }}" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                                    @error('assessment_type_requirements.'.$assessmentType->id) <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                                </div>
                                <button type="button" wire:click="openGradeRule('assessment', {{ $assessmentType->id }})" class="course-completion-grade-button" aria-label="{{ __('settings.course_completion.labels.choose_grades') }}" data-course-completion-grade-button>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75v-1.5a3.75 3.75 0 0 0-3.75-3.75h-4.5a3.75 3.75 0 0 0-3.75 3.75v1.5M10.5 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM15.75 10.9a2.65 2.65 0 1 0 0-5.3M17.25 13.75a3.5 3.5 0 0 1 3 3.46v1.54" />
                                    </svg>
                                </button>
                            </div>
                        @empty
                            <div class="admin-empty-state admin-empty-state--compact">{{ __('settings.course_completion.labels.no_assessment_types') }}</div>
                        @endforelse
                    </div>
                </div>

                <section class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.retain_percentage') }}</label><div class="flex"><input wire:model="retain_percentage" type="number" min="0" max="100" class="min-w-0 flex-1 rounded-s-xl px-4 py-3 text-sm"><span class="flex items-center rounded-e-xl border border-s-0 border-white/10 px-4">%</span></div></div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.minimum_points') }}</label><div class="flex"><input wire:model="minimum_points" type="number" min="0" class="min-w-0 flex-1 rounded-s-xl px-4 py-3 text-sm"><span class="flex items-center rounded-e-xl border border-s-0 border-white/10 px-4">{{ __('settings.course_completion.labels.point_unit') }}</span></div></div>
                    </div>
                </section>

                <div class="flex justify-start" data-course-completion-save-actions>
                    <x-admin.save-button :label="__('settings.course_completion.actions.save_rules')" data-course-completion-save-action />
                </div>
            </form>
        </section>

    </div>

    <x-admin.modal :show="$showGradeRuleModal" :title="__('settings.course_completion.labels.choose_grades')" max-width="2xl">
        @php
            $gradeSelectionCreatesRule = $gradeRuleTarget === 'final'
                && $gradeRuleRowIndex === 0
                && collect($gradeRuleOriginalGradeIds)->diff($gradeRuleSelectedGradeIds)->isNotEmpty();
        @endphp
        <x-slot:header-actions>
            <button type="button" wire:click="saveGradeRuleModal" class="admin-modal__close" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-course-completion-grade-save data-course-completion-grade-action="{{ $gradeSelectionCreatesRule ? 'add' : 'save' }}">
                @if ($gradeSelectionCreatesRule)
                    <span aria-hidden="true">+</span>
                @else
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3.75h11.25L19.5 7v13.25H5V3.75Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 3.75v5.5h8v-5.5M8.25 20.25v-6.5h8v6.5" />
                    </svg>
                @endif
            </button>
            @if ($gradeRuleTarget === 'assessment' && $assessmentRuleTypeId)
                <button type="button" wire:click="removeAssessmentRule" class="admin-modal__close admin-modal__close--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-course-completion-assessment-delete>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 7.5h15M9.25 7.5V5.25h5.5V7.5m-8.5 0 .75 12h10l.75-12M10 11v5m4-5v5" /></svg>
                </button>
            @elseif ($gradeRuleTarget === 'final' && $gradeRuleRowIndex > 0)
                <button type="button" wire:click="deleteFinalRule({{ $gradeRuleRowIndex }})" class="admin-modal__close admin-modal__close--danger" title="{{ __('crud.common.actions.delete') }}" aria-label="{{ __('crud.common.actions.delete') }}" data-course-completion-rule-delete>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 7.5h15M9.25 7.5V5.25h5.5V7.5m-8.5 0 .75 12h10l.75-12M10 11v5m4-5v5" /></svg>
                </button>
            @endif
        </x-slot:header-actions>
        @error('gradeRuleSelectedGradeIds')<div class="mb-3 text-sm text-red-400">{{ $message }}</div>@enderror
        <div class="grid gap-3 sm:grid-cols-2">@foreach($gradeLevels as $gradeLevel)<label class="flex items-center gap-3 rounded-xl border border-white/10 p-3"><input type="checkbox" value="{{ $gradeLevel->id }}" wire:model.live="gradeRuleSelectedGradeIds" class="rounded"><span>{{ $gradeLevel->name }}</span></label>@endforeach</div>
    </x-admin.modal>

    <x-admin.modal :show="$showAssessmentTypeModal" :title="__('settings.course_completion.actions.add_assessment_type')" :close-method="$assessment_type_selections === [] ? 'closeAssessmentTypeModal' : null" max-width="2xl">
        <x-slot:header-actions>
            @if ($assessment_type_selections !== [])
                <button type="button" wire:click="addSelectedAssessmentType" class="admin-modal__close" title="{{ __('settings.course_completion.actions.add_assessment_type') }}" aria-label="{{ __('settings.course_completion.actions.add_assessment_type') }}" data-course-completion-assessment-add>+</button>
            @endif
        </x-slot:header-actions>
        @php($availableAssessmentTypes = $assessmentTypes->whereNotIn('id', $enabled_assessment_type_ids))
        <div class="assessment-type-selector-grid">
            @forelse($availableAssessmentTypes as $assessmentType)
                <button type="button" wire:click="toggleAssessmentTypeSelection({{ $assessmentType->id }})" class="assessment-type-selector-card {{ in_array($assessmentType->id, $assessment_type_selections, true) ? 'is-selected' : '' }}" data-course-completion-assessment-choice>
                    <span>{{ $assessmentType->name }}</span>
                </button>
            @empty
                <div class="admin-empty-state admin-empty-state--compact">{{ __('settings.course_completion.labels.no_assessment_types') }}</div>
            @endforelse
        </div>
    </x-admin.modal>
</div>

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
    public string $required_present_attendance = '1';
    public string $retain_percentage = '50';
    public string $minimum_points = '0';
    public array $assessment_type_requirements = [];
    public array $final_test_grade_ids = [];
    public array $assessment_grade_ids = [];
    public bool $showGradeRuleModal = false;
    public bool $showAssessmentTypeModal = false;
    public array $enabled_assessment_type_ids = [];
    public string $gradeRuleTarget = 'final';
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
            'assessment_grade_ids' => ['array'],
            'assessment_grade_ids.*' => ['integer', 'exists:grade_levels,id'],
            'required_present_attendance' => ['required', 'integer', 'min:0'],
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

        app(CourseCompletionRuleService::class)->saveSettings($validated);

        session()->flash('status', __('settings.course_completion.messages.rules_saved'));
    }


    protected function loadSettings(): void
    {
        $settings = app(CourseCompletionRuleService::class)->settings();

        $this->required_passed_final_tests = (string) $settings['required_passed_final_tests'];
        $this->required_memorized_pages = (string) $settings['required_memorized_pages'];
        $this->final_rule_operator = $settings['final_rule_operator'];
        $this->required_passed_quizzes = (string) $settings['required_passed_quizzes'];
        $this->required_present_attendance = (string) $settings['required_present_attendance'];
        $this->retain_percentage = (string) $settings['retain_percentage'];
        $this->minimum_points = (string) $settings['minimum_points'];
        $this->final_test_grade_ids = $settings['final_test_grade_ids'];
        $this->assessment_grade_ids = $settings['assessment_grade_ids'];

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

    public function openGradeRule(string $target): void
    {
        $this->gradeRuleTarget = $target === 'assessment' ? 'assessment' : 'final';
        $this->showGradeRuleModal = true;
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
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar"><div class="admin-toolbar__title">{{ __('settings.course_completion.sections.rules.title') }}</div></div>

            <form wire:submit="saveRules" class="mt-5 space-y-4">
                <div class="grid gap-4 md:grid-cols-[1fr_8rem_1fr]">
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2"><label class="block text-sm font-medium">{{ __('settings.course_completion.fields.required_passed_final_tests') }}</label><button type="button" wire:click="openGradeRule('final')" class="pill-link pill-link--compact" aria-label="{{ __('settings.course_completion.labels.choose_grades') }}">…</button></div>
                        <input wire:model="required_passed_final_tests" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('required_passed_final_tests') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.final_rule_operator') }}</label>
                        <select wire:model="final_rule_operator" class="w-full rounded-xl px-4 py-3"><option value="and">{{ __('settings.course_completion.options.and') }}</option><option value="or">{{ __('settings.course_completion.options.or') }}</option></select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.required_memorized_pages') }}</label>
                        <input wire:model="required_memorized_pages" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.required_present_attendance') }}</label>
                    <input wire:model="required_present_attendance" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-2"><div class="text-sm font-semibold text-white">{{ __('settings.course_completion.fields.assessment_type_requirements') }}</div><div class="flex gap-2"><button type="button" wire:click="$set('showAssessmentTypeModal', true)" class="pill-link pill-link--compact" aria-label="{{ __('settings.course_completion.actions.add_assessment_type') }}">+</button><button type="button" wire:click="openGradeRule('assessment')" class="pill-link pill-link--compact" aria-label="{{ __('settings.course_completion.labels.choose_grades') }}">…</button></div></div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        @forelse ($assessmentTypes->whereIn('id', $enabled_assessment_type_ids) as $assessmentType)
                            <div>
                                <label class="mb-1 block text-sm font-medium">{{ $assessmentType->name }}</label>
                                <input wire:model="assessment_type_requirements.{{ $assessmentType->id }}" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                                @error('assessment_type_requirements.'.$assessmentType->id) <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            </div>
                        @empty
                            <div class="text-sm text-neutral-400">{{ __('settings.course_completion.labels.no_assessment_types') }}</div>
                        @endforelse
                    </div>
                </div>

                <section class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">{{ __('settings.course_completion.sections.final_points.title') }}</div>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.retain_percentage') }}</label><div class="flex"><input wire:model="retain_percentage" type="number" min="0" max="100" class="min-w-0 flex-1 rounded-s-xl px-4 py-3 text-sm"><span class="flex items-center rounded-e-xl border border-s-0 border-white/10 px-4">%</span></div></div>
                        <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.minimum_points') }}</label><input wire:model="minimum_points" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm"></div>
                    </div>
                </section>

                <div class="flex justify-end">
                    <button type="submit" class="pill-link pill-link--accent">{{ __('settings.course_completion.actions.save_rules') }}</button>
                </div>
            </form>
        </section>

        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar"><div class="admin-toolbar__title">{{ __('settings.course_completion.sections.apply.title') }}</div></div>
            <form wire:submit="applyRules" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5 xl:items-end">
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.academic_year') }}</label><select wire:model.live="academic_year_id" class="w-full rounded-xl px-4 py-3"><option value="">{{ __('settings.course_completion.options.all_academic_years') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.course') }}</label><select wire:model.live="course_id" class="w-full rounded-xl px-4 py-3"><option value="">{{ __('settings.course_completion.options.all_courses') }}</option>@foreach($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.group') }}</label><select wire:model="group_id" class="w-full rounded-xl px-4 py-3"><option value="">{{ __('settings.course_completion.options.all_groups') }}</option>@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.enrollment_status') }}</label><select wire:model="enrollment_status" class="w-full rounded-xl px-4 py-3">@foreach(['active','completed','inactive','cancelled','all'] as $status)<option value="{{ $status }}">{{ __('settings.course_completion.statuses.'.$status) }}</option>@endforeach</select></div>
                <button type="submit" wire:confirm="{{ __('settings.course_completion.actions.apply_confirm') }}" class="pill-link pill-link--accent">{{ __('settings.course_completion.actions.apply_rules') }}</button>
            </form>
            @error('scope')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
        </section>

    </div>

    <x-admin.modal :show="$showGradeRuleModal" :title="__('settings.course_completion.labels.choose_grades')" close-method="$set('showGradeRuleModal', false)" max-width="2xl">
        <div class="grid gap-3 sm:grid-cols-2">@foreach($gradeLevels as $gradeLevel)<label class="flex items-center gap-3 rounded-xl border border-white/10 p-3"><input type="checkbox" value="{{ $gradeLevel->id }}" wire:model="{{ $gradeRuleTarget === 'final' ? 'final_test_grade_ids' : 'assessment_grade_ids' }}" class="rounded"><span>{{ $gradeLevel->name }}</span></label>@endforeach</div>
        <div class="mt-5 flex justify-end"><button type="button" wire:click="$set('showGradeRuleModal', false)" class="pill-link pill-link--accent">{{ __('crud.common.actions.close') }}</button></div>
    </x-admin.modal>

    <x-admin.modal :show="$showAssessmentTypeModal" :title="__('settings.course_completion.actions.add_assessment_type')" close-method="$set('showAssessmentTypeModal', false)" max-width="2xl">
        <div class="grid gap-3 sm:grid-cols-2">@foreach($assessmentTypes as $assessmentType)<label class="flex items-center gap-3 rounded-xl border border-white/10 p-3"><input type="checkbox" value="{{ $assessmentType->id }}" wire:model="enabled_assessment_type_ids" class="rounded"><span>{{ $assessmentType->name }}</span></label>@endforeach</div>
        <div class="mt-5 flex justify-end"><button type="button" wire:click="$set('showAssessmentTypeModal', false)" class="pill-link pill-link--accent">{{ __('crud.common.actions.close') }}</button></div>
    </x-admin.modal>
</div>

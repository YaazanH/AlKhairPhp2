<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AssessmentType;
use App\Services\CourseCompletionRuleService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public string $required_passed_final_tests = '1';
    public string $required_passed_quizzes = '1';
    public string $required_present_attendance = '1';
    public string $retain_percentage = '50';
    public string $minimum_points = '0';
    public array $assessment_type_requirements = [];

    public function mount(): void
    {
        $this->authorizePermission('course-completion-rules.manage');
        $this->loadSettings();
    }

    public function with(): array
    {
        return [
            'assessmentTypes' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }

    public function saveRules(): void
    {
        $this->authorizePermission('course-completion-rules.manage');

        $validated = $this->validate([
            'required_passed_final_tests' => ['required', 'integer', 'min:0'],
            'required_passed_quizzes' => ['required', 'integer', 'min:0'],
            'assessment_type_requirements' => ['nullable', 'array'],
            'assessment_type_requirements.*' => ['nullable', 'integer', 'min:0'],
            'required_present_attendance' => ['required', 'integer', 'min:0'],
            'retain_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'minimum_points' => ['required', 'integer', 'min:0'],
        ]);

        $validated['assessment_type_requirements'] = collect($validated['assessment_type_requirements'] ?? [])
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
        $this->required_passed_quizzes = (string) $settings['required_passed_quizzes'];
        $this->required_present_attendance = (string) $settings['required_present_attendance'];
        $this->retain_percentage = (string) $settings['retain_percentage'];
        $this->minimum_points = (string) $settings['minimum_points'];

        $storedRequirements = $settings['assessment_type_requirements'] ?? [];
        $this->assessment_type_requirements = AssessmentType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id'])
            ->mapWithKeys(fn (AssessmentType $assessmentType): array => [
                $assessmentType->id => (string) ($storedRequirements[$assessmentType->id] ?? 0),
            ])
            ->all();
    }

}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.course_completion.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.course_completion.subtitle') }}</p>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.course-completion" />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-5">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.course_completion.fields.required_passed_final_tests') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format((int) $required_passed_final_tests) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.course_completion.fields.assessment_type_requirements') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format(collect($assessment_type_requirements)->filter(fn ($value) => (int) $value > 0)->count()) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.course_completion.fields.required_present_attendance') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format((int) $required_present_attendance) }}</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.course_completion.fields.retain_percentage') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format((int) $retain_percentage) }}%</div>
        </div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="text-sm text-neutral-500">{{ __('settings.course_completion.fields.minimum_points') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ number_format((int) $minimum_points) }}</div>
        </div>
    </div>

    <div class="grid gap-6">
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('settings.course_completion.sections.rules.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('settings.course_completion.sections.rules.copy') }}</p>
                </div>
            </div>

            <form wire:submit="saveRules" class="mt-5 space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.required_passed_final_tests') }}</label>
                        <input wire:model="required_passed_final_tests" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('required_passed_final_tests') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.required_present_attendance') }}</label>
                        <input wire:model="required_present_attendance" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('required_present_attendance') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.retain_percentage') }}</label>
                        <input wire:model="retain_percentage" type="number" min="0" max="100" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('retain_percentage') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.course_completion.fields.minimum_points') }}</label>
                        <input wire:model="minimum_points" type="number" min="0" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('minimum_points') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-sm font-semibold text-white">{{ __('settings.course_completion.fields.assessment_type_requirements') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('settings.course_completion.labels.assessment_type_requirements_help') }}</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        @forelse ($assessmentTypes as $assessmentType)
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

                <div class="flex justify-end">
                    <button type="submit" class="pill-link pill-link--accent">{{ __('settings.course_completion.actions.save_rules') }}</button>
                </div>
            </form>
        </section>

    </div>
</div>

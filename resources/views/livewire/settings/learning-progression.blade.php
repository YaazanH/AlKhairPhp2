<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Assessment;
use App\Models\CurriculumLesson;
use App\Models\Group;
use App\Models\LearningProgressionLevel;
use App\Services\LearningProgressionService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public bool $partial_test_enabled = true;
    public bool $partial_test_required_for_final = true;
    public bool $final_test_enabled = true;
    public bool $final_test_required_for_awqaf = true;
    public bool $awqaf_test_enabled = true;
    public bool $locked = false;
    public bool $configured = false;
    public string $profile = LearningProgressionService::PROFILE_QURAN;
    public bool $showLevelModal = false;
    public ?int $editingLevelId = null;
    public string $levelName = '';
    public string $levelDescription = '';
    public string $attendanceThreshold = '80';
    public ?int $finalAssessmentId = null;
    public string $passingScore = '';
    public array $groupIds = [];
    public array $lessonIds = [];

    public function mount(): void
    {
        $this->authorizePermission('learning-progression.manage');
        $this->loadSettings();
    }

    public function saveQuran(): void
    {
        $this->authorizePermission('learning-progression.manage');

        $validated = $this->validate([
            'partial_test_enabled' => ['boolean'],
            'partial_test_required_for_final' => ['boolean'],
            'final_test_enabled' => ['boolean'],
            'final_test_required_for_awqaf' => ['boolean'],
            'awqaf_test_enabled' => ['boolean'],
        ]);

        try {
            app(LearningProgressionService::class)->storeQuranSettings($validated);
        } catch (LogicException $exception) {
            $this->addError('progression', $exception->getMessage());

            return;
        }

        $this->loadSettings();
        session()->flash('status', __('learning_progression.saved'));
    }

    public function save(): void
    {
        $this->saveQuran();
    }

    public function updatedProfile(string $profile): void
    {
        $this->authorizePermission('learning-progression.manage');

        try {
            app(LearningProgressionService::class)->selectProfile($profile);
        } catch (LogicException $exception) {
            $this->addError('profile', $exception->getMessage());
        }

        $this->loadSettings();
    }

    public function createLevel(): void
    {
        $this->resetLevelForm();
        $this->showLevelModal = true;
    }

    public function editLevel(int $levelId): void
    {
        $level = LearningProgressionLevel::query()->with(['groups:id', 'lessons:id'])->findOrFail($levelId);
        $this->editingLevelId = $level->id;
        $this->levelName = $level->name;
        $this->levelDescription = $level->description ?? '';
        $this->attendanceThreshold = (string) $level->attendance_threshold;
        $this->finalAssessmentId = $level->final_assessment_id;
        $this->passingScore = (string) $level->passing_score;
        $this->groupIds = $level->groups->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $this->lessonIds = $level->lessons->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $this->showLevelModal = true;
    }

    public function saveLevel(): void
    {
        $this->authorizePermission('learning-progression.manage');
        $validated = $this->validate([
            'levelName' => ['required', 'string', 'max:255', 'unique:learning_progression_levels,name,'.($this->editingLevelId ?? 'NULL')],
            'levelDescription' => ['nullable', 'string', 'max:2000'],
            'attendanceThreshold' => ['required', 'numeric', 'between:0,100'],
            'finalAssessmentId' => ['required', 'integer', 'exists:assessments,id'],
            'passingScore' => ['required', 'numeric', 'min:0'],
            'groupIds' => ['required', 'array', 'min:1'],
            'groupIds.*' => ['integer', 'exists:groups,id'],
            'lessonIds' => ['required', 'array', 'min:1'],
            'lessonIds.*' => ['integer', 'exists:curriculum_lessons,id'],
        ], attributes: __('learning_progression.attributes'));

        $level = $this->editingLevelId
            ? LearningProgressionLevel::query()->findOrFail($this->editingLevelId)
            : null;

        try {
            app(LearningProgressionService::class)->storeLevel([
                'name' => $validated['levelName'],
                'description' => $validated['levelDescription'],
                'attendance_threshold' => $validated['attendanceThreshold'],
                'final_assessment_id' => $validated['finalAssessmentId'],
                'passing_score' => $validated['passingScore'],
                'group_ids' => $validated['groupIds'],
                'lesson_ids' => $validated['lessonIds'],
            ], $level);
        } catch (LogicException $exception) {
            $this->addError('level', $exception->getMessage());

            return;
        }

        $this->showLevelModal = false;
        $this->loadSettings();
        session()->flash('status', __('learning_progression.levels.saved'));
    }

    public function deleteLevel(int $levelId): void
    {
        $this->authorizePermission('learning-progression.manage');
        app(LearningProgressionService::class)->deleteLevel(LearningProgressionLevel::query()->findOrFail($levelId));
        $this->loadSettings();
        session()->flash('status', __('learning_progression.levels.deleted'));
    }

    public function moveLevel(int $levelId, string $direction): void
    {
        $this->authorizePermission('learning-progression.manage');
        app(LearningProgressionService::class)->moveLevel(LearningProgressionLevel::query()->findOrFail($levelId), $direction);
    }

    public function closeLevelModal(): void
    {
        $this->showLevelModal = false;
        $this->resetValidation();
    }

    public function with(): array
    {
        $usedAssessmentIds = LearningProgressionLevel::query()
            ->when($this->editingLevelId, fn ($query) => $query->whereKeyNot($this->editingLevelId))
            ->pluck('final_assessment_id');

        return [
            'levels' => LearningProgressionLevel::query()->with(['groups.course', 'lessons.subject.definition', 'finalAssessment'])->orderBy('sort_order')->orderBy('id')->get(),
            'groups' => Group::query()->with(['course', 'curriculum'])->where('is_active', true)->whereNotNull('curriculum_id')->orderBy('name')->get(),
            'lessons' => CurriculumLesson::query()->with(['subject.curriculum', 'subject.definition'])->orderBy('name')->get(),
            'assessments' => Assessment::query()->with('groups:id,name')->where('is_active', true)->whereNotIn('id', $usedAssessmentIds)->orderBy('title')->get(),
        ];
    }

    public function updatedPartialTestEnabled(bool $enabled): void
    {
        if (! $enabled) {
            $this->partial_test_required_for_final = false;
        }
    }

    public function updatedFinalTestEnabled(bool $enabled): void
    {
        if (! $enabled) {
            $this->partial_test_required_for_final = false;
            $this->final_test_required_for_awqaf = false;
        }
    }

    public function updatedAwqafTestEnabled(bool $enabled): void
    {
        if (! $enabled) {
            $this->final_test_required_for_awqaf = false;
        }
    }

    protected function loadSettings(): void
    {
        $service = app(LearningProgressionService::class);
        $settings = $service->settings();

        $this->partial_test_enabled = $settings['partial_test_enabled'];
        $this->partial_test_required_for_final = $settings['partial_test_required_for_final'];
        $this->final_test_enabled = $settings['final_test_enabled'];
        $this->final_test_required_for_awqaf = $settings['final_test_required_for_awqaf'];
        $this->awqaf_test_enabled = $settings['awqaf_test_enabled'];
        $this->configured = $settings['configured'];
        $this->profile = $settings['profile'];
        $this->locked = $service->isLocked();
    }

    protected function resetLevelForm(): void
    {
        $this->editingLevelId = null;
        $this->levelName = '';
        $this->levelDescription = '';
        $this->attendanceThreshold = '80';
        $this->finalAssessmentId = null;
        $this->passingScore = '';
        $this->groupIds = [];
        $this->lessonIds = [];
        $this->resetValidation();
    }
};
?>

<div class="page-stack">
    <x-settings.admin-nav section="dashboard" current="settings.learning-progression" />

    <section class="surface-panel overflow-hidden">
        <div class="border-b border-neutral-200 p-5 dark:border-neutral-800 lg:p-6">
            <div class="eyebrow">{{ __('learning_progression.eyebrow') }}</div>
            <h1 class="font-display mt-2 text-2xl font-semibold text-neutral-950 dark:text-white">{{ __('learning_progression.title') }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-7 text-neutral-600 dark:text-neutral-300">{{ __('learning_progression.subtitle') }}</p>
        </div>

        <div class="space-y-6 p-5 lg:p-6">
            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">{{ session('status') }}</div>
            @endif

            @if ($locked)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm leading-6 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    <strong>{{ __('learning_progression.locked_title') }}</strong>
                    <div class="mt-1">{{ __('learning_progression.locked_copy') }}</div>
                </div>
            @elseif (! $configured)
                <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-4 text-sm leading-6 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">{{ __('learning_progression.first_setup') }}</div>
            @endif

            @error('progression') <div class="text-sm text-red-600">{{ $message }}</div> @enderror

            <fieldset @disabled($locked)>
                <legend class="mb-3 text-sm font-semibold text-neutral-950 dark:text-white">{{ __('learning_progression.profile_choice') }}</legend>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ([\App\Services\LearningProgressionService::PROFILE_QURAN => 'quran', \App\Services\LearningProgressionService::PROFILE_LESSON_LEVEL => 'lesson_level'] as $value => $label)
                        <label class="flex cursor-pointer gap-3 rounded-2xl border p-4 transition {{ $profile === $value ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30' : 'border-neutral-200 dark:border-neutral-800' }}">
                            <input wire:model.live="profile" type="radio" value="{{ $value }}" class="mt-1 border-neutral-300 text-emerald-600">
                            <span><span class="block font-semibold">{{ __('learning_progression.profiles.'.$label.'.title') }}</span><span class="mt-1 block text-sm leading-6 text-neutral-500 dark:text-neutral-400">{{ __('learning_progression.profiles.'.$label.'.copy') }}</span></span>
                        </label>
                    @endforeach
                </div>
                @error('profile') <div class="mt-2 text-sm text-red-600">{{ $message }}</div> @enderror
            </fieldset>

            @if ($profile === \App\Services\LearningProgressionService::PROFILE_QURAN)
                <form wire:submit="saveQuran" class="space-y-6">
                    <fieldset @disabled($locked) class="space-y-4">
                        <legend class="mb-4 text-base font-semibold text-neutral-950 dark:text-white">{{ __('learning_progression.quran_profile') }}</legend>

                        <div class="grid gap-4 lg:grid-cols-3">
                    <article class="rounded-2xl border border-neutral-200 p-4 dark:border-neutral-800">
                        <label class="flex items-start gap-3">
                            <input wire:model.live="partial_test_enabled" type="checkbox" class="mt-1 rounded border-neutral-300 text-emerald-600">
                            <span><span class="block font-semibold">{{ __('learning_progression.tests.partial') }}</span><span class="mt-1 block text-sm text-neutral-500">{{ __('learning_progression.partial_copy') }}</span></span>
                        </label>
                        <label class="mt-4 flex items-start gap-3 border-t border-neutral-200 pt-4 text-sm dark:border-neutral-800">
                            <input wire:model="partial_test_required_for_final" type="checkbox" @disabled(! $partial_test_enabled || ! $final_test_enabled) class="mt-1 rounded border-neutral-300 text-emerald-600">
                            <span>{{ __('learning_progression.require_partial') }}</span>
                        </label>
                        @error('partial_test_required_for_final') <div class="mt-2 text-sm text-red-600">{{ $message }}</div> @enderror
                    </article>

                    <article class="rounded-2xl border border-neutral-200 p-4 dark:border-neutral-800">
                        <label class="flex items-start gap-3">
                            <input wire:model.live="final_test_enabled" type="checkbox" class="mt-1 rounded border-neutral-300 text-emerald-600">
                            <span><span class="block font-semibold">{{ __('learning_progression.tests.final') }}</span><span class="mt-1 block text-sm text-neutral-500">{{ __('learning_progression.final_copy') }}</span></span>
                        </label>
                        <label class="mt-4 flex items-start gap-3 border-t border-neutral-200 pt-4 text-sm dark:border-neutral-800">
                            <input wire:model="final_test_required_for_awqaf" type="checkbox" @disabled(! $final_test_enabled || ! $awqaf_test_enabled) class="mt-1 rounded border-neutral-300 text-emerald-600">
                            <span>{{ __('learning_progression.require_final') }}</span>
                        </label>
                        @error('final_test_required_for_awqaf') <div class="mt-2 text-sm text-red-600">{{ $message }}</div> @enderror
                    </article>

                    <article class="rounded-2xl border border-neutral-200 p-4 dark:border-neutral-800">
                        <label class="flex items-start gap-3">
                            <input wire:model.live="awqaf_test_enabled" type="checkbox" class="mt-1 rounded border-neutral-300 text-emerald-600">
                            <span><span class="block font-semibold">{{ __('learning_progression.tests.awqaf') }}</span><span class="mt-1 block text-sm text-neutral-500">{{ __('learning_progression.awqaf_copy') }}</span></span>
                        </label>
                    </article>
                        </div>

                        @error('partial_test_enabled') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
                    </fieldset>

                    @unless($locked)
                        <div class="flex justify-end border-t border-neutral-200 pt-5 dark:border-neutral-800">
                            <x-admin.save-button :label="__('learning_progression.save')" />
                        </div>
                    @endunless
                </form>
            @else
                <section class="space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-neutral-950 dark:text-white">{{ __('learning_progression.levels.title') }}</h2>
                            <p class="mt-1 max-w-3xl text-sm leading-6 text-neutral-500 dark:text-neutral-400">{{ __('learning_progression.levels.copy') }}</p>
                        </div>
                        @unless($locked)
                            <button type="button" wire:click="createLevel" class="pill-link pill-link--accent">{{ __('learning_progression.levels.add') }}</button>
                        @endunless
                    </div>

                    @forelse ($levels as $index => $level)
                        <article wire:key="progression-level-{{ $level->id }}" class="rounded-2xl border border-neutral-200 p-4 dark:border-neutral-800">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('learning_progression.levels.position', ['position' => $index + 1]) }}</div>
                                    <h3 class="mt-1 text-lg font-semibold text-neutral-950 dark:text-white">{{ $level->name }}</h3>
                                    @if ($level->description)<p class="mt-1 text-sm text-neutral-500">{{ $level->description }}</p>@endif
                                </div>
                                @unless($locked)
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" wire:click="moveLevel({{ $level->id }}, 'up')" @disabled($loop->first) class="pill-link disabled:opacity-40">{{ __('learning_progression.levels.up') }}</button>
                                        <button type="button" wire:click="moveLevel({{ $level->id }}, 'down')" @disabled($loop->last) class="pill-link disabled:opacity-40">{{ __('learning_progression.levels.down') }}</button>
                                        <button type="button" wire:click="editLevel({{ $level->id }})" class="pill-link">{{ __('crud.common.actions.edit') }}</button>
                                        <button type="button" wire:click="deleteLevel({{ $level->id }})" wire:confirm="{{ __('learning_progression.levels.delete_confirm') }}" class="pill-link text-red-600">{{ __('crud.common.actions.delete') }}</button>
                                    </div>
                                @endunless
                            </div>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                <div><dt class="text-neutral-500">{{ __('learning_progression.levels.attendance') }}</dt><dd class="font-semibold">{{ number_format((float) $level->attendance_threshold, 0) }}%</dd></div>
                                <div><dt class="text-neutral-500">{{ __('learning_progression.levels.assessment') }}</dt><dd class="font-semibold">{{ $level->finalAssessment->title }}</dd></div>
                                <div><dt class="text-neutral-500">{{ __('learning_progression.levels.passing_score') }}</dt><dd class="font-semibold">{{ number_format((float) $level->passing_score, 2) }}</dd></div>
                                <div><dt class="text-neutral-500">{{ __('learning_progression.levels.requirements') }}</dt><dd class="font-semibold">{{ trans_choice('learning_progression.levels.group_count', $level->groups->count(), ['count' => $level->groups->count()]) }} · {{ trans_choice('learning_progression.levels.lesson_count', $level->lessons->count(), ['count' => $level->lessons->count()]) }}</dd></div>
                            </dl>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-neutral-300 p-8 text-center dark:border-neutral-700">
                            <div class="font-semibold text-neutral-950 dark:text-white">{{ __('learning_progression.levels.empty_title') }}</div>
                            <p class="mt-2 text-sm text-neutral-500">{{ __('learning_progression.levels.empty_copy') }}</p>
                        </div>
                    @endforelse
                </section>
            @endif
        </div>
    </section>

    <x-admin.modal :show="$showLevelModal" :title="$editingLevelId ? __('learning_progression.levels.edit') : __('learning_progression.levels.add')" close-method="closeLevelModal" max-width="5xl">
        <form wire:submit="saveLevel" class="space-y-5">
            @error('level') <div class="text-sm text-red-600">{{ $message }}</div> @enderror
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('learning_progression.levels.name') }}</label><input wire:model="levelName" class="w-full rounded-xl" type="text">@error('levelName')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('learning_progression.levels.attendance') }}</label><input wire:model="attendanceThreshold" class="w-full rounded-xl" type="number" min="0" max="100" step="0.01">@error('attendanceThreshold')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
            </div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('learning_progression.levels.description') }}</label><textarea wire:model="levelDescription" class="w-full rounded-xl" rows="2"></textarea>@error('levelDescription')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('learning_progression.levels.assessment') }}</label><select wire:model="finalAssessmentId" class="w-full rounded-xl"><option value="">{{ __('crud.common.select') }}</option>@foreach($assessments as $assessment)<option value="{{ $assessment->id }}">{{ $assessment->title }}@if($assessment->total_mark !== null) ({{ $assessment->total_mark }})@endif</option>@endforeach</select>@error('finalAssessmentId')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('learning_progression.levels.passing_score') }}</label><input wire:model="passingScore" class="w-full rounded-xl" type="number" min="0" step="0.01">@error('passingScore')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
            </div>
            <div>
                <div class="mb-2 text-sm font-medium">{{ __('learning_progression.levels.groups') }}</div>
                <div class="grid max-h-48 gap-2 overflow-y-auto rounded-xl border border-neutral-200 p-3 dark:border-neutral-800 sm:grid-cols-2">@forelse($groups as $group)<label class="flex gap-2 rounded-lg p-2 hover:bg-neutral-50 dark:hover:bg-neutral-900"><input wire:model="groupIds" type="checkbox" value="{{ $group->id }}" class="mt-1 rounded text-emerald-600"><span class="text-sm"><span class="block font-medium">{{ $group->name }}</span><span class="text-neutral-500">{{ $group->course?->name }} · {{ $group->curriculum?->name }}</span></span></label>@empty<p class="text-sm text-neutral-500">{{ __('learning_progression.levels.no_groups') }}</p>@endforelse</div>
                @error('groupIds')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror @error('groupIds.*')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror
            </div>
            <div>
                <div class="mb-2 text-sm font-medium">{{ __('learning_progression.levels.lessons') }}</div>
                <div class="grid max-h-56 gap-2 overflow-y-auto rounded-xl border border-neutral-200 p-3 dark:border-neutral-800 sm:grid-cols-2">@forelse($lessons as $lesson)<label class="flex gap-2 rounded-lg p-2 hover:bg-neutral-50 dark:hover:bg-neutral-900"><input wire:model="lessonIds" type="checkbox" value="{{ $lesson->id }}" class="mt-1 rounded text-emerald-600"><span class="text-sm"><span class="block font-medium">{{ $lesson->name }}</span><span class="text-neutral-500">{{ $lesson->subject?->curriculum?->name }} · {{ $lesson->subject?->definition?->name }}</span></span></label>@empty<p class="text-sm text-neutral-500">{{ __('learning_progression.levels.no_lessons') }}</p>@endforelse</div>
                @error('lessonIds')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror @error('lessonIds.*')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror
            </div>
            <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-neutral-800"><x-admin.save-button :label="__('learning_progression.levels.save')" /></div>
        </form>
    </x-admin.modal>
</div>

<?php

use App\Livewire\Concerns\AuthorizesPermissions;
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

    public function mount(): void
    {
        $this->authorizePermission('learning-progression.manage');
        $this->loadSettings();
    }

    public function save(): void
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
        $this->locked = $service->isLocked();
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

        <form wire:submit="save" class="space-y-6 p-5 lg:p-6">
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
    </section>
</div>

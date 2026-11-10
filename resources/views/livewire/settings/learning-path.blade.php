<?php

use App\Services\Landlord\CurrentModuleAccess;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        abort_unless(
            auth()->user()?->can('learning-progression.manage')
            || auth()->user()?->can('settings.manage'),
            403,
        );
    }

    public function with(): array
    {
        $access = app(CurrentModuleAccess::class);
        $canManageSettings = (bool) auth()->user()?->can('settings.manage');

        return [
            'showProgression' => (bool) auth()->user()?->can('learning-progression.manage'),
            'showReferenceTypes' => $canManageSettings && (
                $access->enabled('student_attendance')
                || $access->enabled('teacher_attendance')
                || $access->enabled('assessments')
            ),
            'showPoints' => $canManageSettings && $access->enabled('points_rewards'),
        ];
    }
};
?>

@php
    $availableSections = array_values(array_filter([
        $showProgression ? 'progression' : null,
        $showReferenceTypes ? 'reference-types' : null,
        $showPoints ? 'points' : null,
    ]));
    $requestedSection = request()->query('section');
    $initialSection = in_array($requestedSection, $availableSections, true)
        ? $requestedSection
        : ($availableSections[0] ?? null);
@endphp

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('learning_progression.title') }}</h1>
        <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('learning_progression.subtitle') }}</p>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.learning-progression" />

    <div
        class="space-y-3"
        x-data="{ openSection: @js($initialSection) }"
        data-learning-path-accordion
    >
        @if ($showProgression)
            <section class="surface-panel settings-dark-surface overflow-hidden" data-learning-path-section="progression">
                <button
                    type="button"
                    class="admin-toolbar flex w-full items-center justify-between gap-4 p-5 text-start lg:p-6"
                    x-on:click="openSection = openSection === 'progression' ? null : 'progression'"
                    x-bind:aria-expanded="(openSection === 'progression').toString()"
                    aria-controls="learning-path-progression"
                >
                    <span>
                        <span class="admin-toolbar__title block">{{ __('learning_progression.sections.progression.title') }}</span>
                        <span class="admin-toolbar__subtitle mt-1 block">{{ __('learning_progression.sections.progression.copy') }}</span>
                    </span>
                    <svg class="size-5 shrink-0 text-neutral-400 transition-transform duration-200" x-bind:class="openSection === 'progression' && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 7 6 6 6-6" /></svg>
                </button>
                <div id="learning-path-progression" x-show="openSection === 'progression'" x-cloak class="border-t border-white/10 p-5 lg:p-6">
                    <livewire:settings.learning-progression :embedded="true" />
                </div>
            </section>
        @endif

        @if ($showReferenceTypes)
            <section class="surface-panel settings-dark-surface overflow-hidden" data-learning-path-section="reference-types">
                <button
                    type="button"
                    class="admin-toolbar flex w-full items-center justify-between gap-4 p-5 text-start lg:p-6"
                    x-on:click="openSection = openSection === 'reference-types' ? null : 'reference-types'"
                    x-bind:aria-expanded="(openSection === 'reference-types').toString()"
                    aria-controls="learning-path-reference-types"
                >
                    <span>
                        <span class="admin-toolbar__title block">{{ __('learning_progression.sections.reference_types.title') }}</span>
                        <span class="admin-toolbar__subtitle mt-1 block">{{ __('learning_progression.sections.reference_types.copy') }}</span>
                    </span>
                    <svg class="size-5 shrink-0 text-neutral-400 transition-transform duration-200" x-bind:class="openSection === 'reference-types' && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 7 6 6 6-6" /></svg>
                </button>
                <div id="learning-path-reference-types" x-show="openSection === 'reference-types'" x-cloak class="border-t border-white/10 p-5 lg:p-6">
                    <livewire:settings.tracking section="references" :embedded="true" />
                </div>
            </section>
        @endif

        @if ($showPoints)
            <section class="surface-panel settings-dark-surface overflow-hidden" data-learning-path-section="points">
                <button
                    type="button"
                    class="admin-toolbar flex w-full items-center justify-between gap-4 p-5 text-start lg:p-6"
                    x-on:click="openSection = openSection === 'points' ? null : 'points'"
                    x-bind:aria-expanded="(openSection === 'points').toString()"
                    aria-controls="learning-path-points"
                >
                    <span>
                        <span class="admin-toolbar__title block">{{ __('learning_progression.sections.points.title') }}</span>
                        <span class="admin-toolbar__subtitle mt-1 block">{{ __('learning_progression.sections.points.copy') }}</span>
                    </span>
                    <svg class="size-5 shrink-0 text-neutral-400 transition-transform duration-200" x-bind:class="openSection === 'points' && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 7 6 6 6-6" /></svg>
                </button>
                <div id="learning-path-points" x-show="openSection === 'points'" x-cloak class="border-t border-white/10 p-5 lg:p-6">
                    <livewire:settings.points :embedded="true" />
                </div>
            </section>
        @endif
    </div>
</div>

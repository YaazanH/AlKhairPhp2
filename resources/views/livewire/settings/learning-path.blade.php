<?php

use App\Services\Landlord\CurrentModuleAccess;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        abort_unless(
            auth()->user()?->can('learning-progression.manage')
            || auth()->user()?->can('settings.manage')
            || auth()->user()?->can('course-completion-rules.manage'),
            403,
        );
    }

    public function with(): array
    {
        $access = app(CurrentModuleAccess::class);
        $canManageSettings = (bool) auth()->user()?->can('settings.manage');

        return [
            'showProgression' => (bool) auth()->user()?->can('learning-progression.manage'),
            'showCompletion' => (bool) auth()->user()?->can('course-completion-rules.manage'),
            'showAttendance' => $canManageSettings && ($access->enabled('student_attendance') || $access->enabled('teacher_attendance')),
            'showAssessments' => $canManageSettings && $access->enabled('assessments'),
            'showPoints' => $canManageSettings && $access->enabled('points_rewards'),
        ];
    }
};
?>

@php
    $sections = array_filter([
        'points' => $showPoints,
        'attendance' => $showAttendance,
        'assessments' => $showAssessments,
        'progression' => $showProgression,
        'completion' => $showCompletion,
    ]);
    $requestedSection = request()->query('section');
    $initialSection = is_string($requestedSection) && array_key_exists($requestedSection, $sections)
        ? $requestedSection
        : array_key_first($sections);
@endphp

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('learning_progression.title') }}</h1>
    </section>
    <x-settings.admin-nav section="dashboard" current="settings.learning-progression" />
    <div class="space-y-3" x-data="{ openSection: @js($initialSection) }" data-learning-path-accordion>
        @foreach ($sections as $section => $visible)
            <section class="surface-panel learning-path-section" data-learning-path-section="{{ $section }}" wire:key="learning-path-{{ $section }}">
                <h2>
                    <button type="button" class="learning-path-section__toggle"
                        x-on:click="openSection = openSection === '{{ $section }}' ? null : '{{ $section }}'"
                        x-bind:aria-expanded="(openSection === '{{ $section }}').toString()"
                        aria-controls="learning-path-{{ $section }}">
                        <span>
                            <span class="learning-path-section__title">{{ $section === 'completion' ? __('settings.navigation.completion.title') : __('learning_progression.sections.'.$section.'.title') }}</span>
                            <span class="learning-path-section__copy">{{ $section === 'completion' ? __('settings.course_completion.subtitle') : __('learning_progression.sections.'.$section.'.copy') }}</span>
                        </span>
                        <svg class="size-5 shrink-0" x-bind:class="openSection === '{{ $section }}' && 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4 7 6 6 6-6" /></svg>
                    </button>
                </h2>
                <div id="learning-path-{{ $section }}" x-show="openSection === '{{ $section }}'" x-cloak class="learning-path-section__body">
                    @if ($section === 'points')
                        <livewire:settings.points :embedded="true" />
                    @elseif ($section === 'attendance')
                        <livewire:settings.tracking section="attendance" :embedded="true" :key="'learning-path-attendance'" />
                    @elseif ($section === 'assessments')
                        <livewire:settings.tracking section="assessments" :embedded="true" :key="'learning-path-assessments'" />
                    @elseif ($section === 'progression')
                        <livewire:settings.learning-progression :embedded="true" />
                    @elseif ($section === 'completion')
                        <livewire:settings.course-completion :embedded="true" />
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>

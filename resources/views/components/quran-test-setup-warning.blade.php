@if (app(\App\Services\LearningProgressionService::class)->configurationRequired())
    <div role="alert" class="rounded-2xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-100" data-quran-test-setup-warning>
        <p>{{ __('learning_progression.errors.not_configured') }}</p>
        @can('learning-progression.manage')
            <a href="{{ route('settings.learning-progression') }}" wire:navigate class="mt-2 inline-block font-semibold underline">{{ __('learning_progression.navigation') }}</a>
        @endcan
    </div>
@endif

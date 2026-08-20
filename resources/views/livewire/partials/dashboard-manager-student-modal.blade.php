<x-admin.modal :show="$selectedManagerStudentId" :title="__('dashboard.manager.analytics.student_highlights')" close-method="closeManagerStudent" max-width="3xl">
    @if ($selectedManagerStudent ?? null)
        <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-start">
            <x-student-avatar :student="$selectedManagerStudent['student']" size="lg" class="dashboard-leaderboard__avatar shrink-0" />
            <div><h3 class="text-2xl font-semibold text-white">{{ $selectedManagerStudent['student']->full_name }}</h3><p class="mt-1 text-sm text-neutral-400">{{ $defaultCourse?->name }}</p></div>
        </div>
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="stat-card"><div class="kpi-label">{{ __('dashboard.manager.analytics.points') }}</div><div class="metric-value mt-4">{{ number_format($selectedManagerStudent['points']) }}</div></div>
            <div class="stat-card"><div class="kpi-label">{{ __('dashboard.manager.analytics.memorized_pages') }}</div><div class="metric-value mt-4">{{ number_format($selectedManagerStudent['pages']) }}</div></div>
            <div class="stat-card"><div class="kpi-label">{{ __('dashboard.manager.analytics.final_tests') }}</div><div class="metric-value mt-4">{{ number_format($selectedManagerStudent['final_tests']) }}</div></div>
        </div>
    @endif
</x-admin.modal>

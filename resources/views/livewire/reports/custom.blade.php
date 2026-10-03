<?php

use App\Services\ReportDashboardService;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        abort_unless(
            app(ReportDashboardService::class)->landingRouteNameFor(auth()->user()) !== null,
            403,
        );
    }

    public function with(): array
    {
        return [
            'reports' => app(ReportDashboardService::class)->reportsFor(auth()->user()),
        ];
    }
}; ?>

<div class="page-stack" data-custom-reports-page>
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <div class="eyebrow">{{ __('report_designer.custom_page.eyebrow') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('report_designer.custom_page.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('report_designer.custom_page.copy') }}</p>
            </div>
            <a href="{{ route('dashboard') }}" class="pill-link">{{ __('report_designer.actions.back_dashboard') }}</a>
        </div>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        @if($reports->isEmpty())
            <div class="rounded-2xl border border-dashed border-white/10 px-4 py-12 text-center text-sm leading-6 text-neutral-400">
                {{ __('report_designer.custom_page.empty') }}
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($reports as $report)
                    <a href="{{ route('reports.designer.show', $report) }}" class="group rounded-2xl border border-white/10 bg-white/[0.03] p-5 transition hover:border-emerald-300/30 hover:bg-white/[0.06]" data-custom-report="{{ $report->id }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-semibold text-white">{{ $report->name }}</h2>
                                @if(filled($report->description))
                                    <p class="mt-2 line-clamp-3 text-sm leading-6 text-neutral-400">{{ $report->description }}</p>
                                @endif
                            </div>
                            <span class="admin-icon-button shrink-0" title="{{ __('report_designer.custom_page.open') }}" aria-hidden="true">
                                <x-admin-action-icon name="open" />
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</div>

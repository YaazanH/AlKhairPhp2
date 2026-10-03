<x-layouts.app>
    <div class="page-stack" data-shared-report>
        <section class="page-hero p-6 lg:p-8">
            <div class="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <div class="eyebrow">{{ $source['label'] }}</div>
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ $definition->name }}</h1>
                    @if(filled($definition->description))<p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ $definition->description }}</p>@endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('reports.designer.export.xlsx', $definition) }}" class="pill-link">{{ __('report_designer.actions.export_xlsx') }}</a>
                    <a href="{{ route('reports.designer.export.pdf', $definition) }}" target="_blank" rel="noopener" class="pill-link">{{ __('report_designer.actions.export_pdf') }}</a>
                    <a href="{{ route('dashboard') }}" class="pill-link">{{ __('report_designer.actions.back_dashboard') }}</a>
                </div>
            </div>
        </section>

        @if($result['calculations'] !== [])
            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($result['calculations'] as $calculation)
                    <article class="stat-card p-5">
                        <div class="kpi-label">{{ $calculation['label'] }}</div>
                        <div class="metric-value mt-3">{{ is_numeric($calculation['value']) ? number_format((float) $calculation['value'], 2) : '—' }}</div>
                    </article>
                @endforeach
            </section>
        @endif

        @if($result['grouping'])
            <section class="surface-table">
                <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                    <h2 class="font-display text-2xl text-white">{{ __('report_designer.grouping.title', ['field' => $result['grouping']['label']]) }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-content text-sm">
                        <thead><tr>@foreach($result['grouping']['columns'] as $column)<th class="px-5 py-4 text-start">{{ $column }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-white/6">
                        @foreach($result['grouping']['rows'] as $row)
                            <tr>@foreach(array_keys($result['grouping']['columns']) as $key)<td class="px-5 py-4">{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}</td>@endforeach</tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <h2 class="font-display text-2xl text-white">{{ __('report_designer.exports.details') }}</h2>
                <p class="mt-2 text-sm text-neutral-400">{{ __('report_designer.preview.summary', ['shown' => count($result['rows']), 'total' => $result['total']]) }}</p>
            </div>
            @if($result['rows'] === [])
                <div class="px-6 py-14 text-center text-sm text-neutral-400">{{ __('report_designer.preview.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table-content text-sm">
                        <thead><tr>@foreach($result['columns'] as $column)<th class="px-5 py-4 text-start">{{ $column['label'] }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-white/6">
                        @foreach($result['rows'] as $row)
                            <tr>@foreach(array_keys($result['columns']) as $key)<td class="px-5 py-4">{{ filled($row[$key] ?? null) ? $row[$key] : '—' }}</td>@endforeach</tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>

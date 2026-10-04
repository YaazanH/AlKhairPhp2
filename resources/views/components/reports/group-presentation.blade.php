@props(['grouping', 'presentation' => [], 'compact' => false])

@php
    $type = (string) data_get($presentation, 'type', \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE);
    $density = (string) data_get($presentation, 'density', 'comfortable');
    $allRows = collect($grouping['rows'] ?? [])->values();
    $chartLimit = $compact ? 5 : 9;
    $rows = $type === \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE
        ? $allRows->take($compact ? 6 : PHP_INT_MAX)->values()
        : $allRows->take($chartLimit)->values();
    $remainingCount = $type === \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE
        ? 0
        : (int) $allRows->skip($chartLimit)->sum('record_count');
    if ($remainingCount > 0) {
        $rows->push(['group' => __('report_designer.presentation.other'), 'record_count' => $remainingCount]);
    }
    $maximum = max(1, (int) $rows->max('record_count'));
    $total = max(0, (int) $allRows->sum('record_count'));
    $colors = $rows->map(fn ($row, int $index): string => sprintf('hsl(%.1f 48%% 58%%)', fmod(24 + ($index * 137.508), 360)));
    $cursor = 0.0;
    $segments = [];
    if ($total > 0) {
        foreach ($rows as $index => $row) {
            $next = $cursor + (((int) $row['record_count'] / $total) * 100);
            $segments[] = $colors[$index].' '.round($cursor, 3).'% '.round($next, 3).'%';
            $cursor = $next;
        }
    }
@endphp

<div {{ $attributes->class('report-presentation') }} data-report-presentation="{{ $type }}">
    @if($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-white/10 px-4 py-8 text-center text-sm text-neutral-400">
            {{ __('report_designer.presentation.empty') }}
        </div>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_BAR)
        <div class="grid gap-3" role="img" aria-label="{{ __('report_designer.presentation.chart_aria', ['group' => $grouping['label']]) }}">
            @foreach($rows as $index => $row)
                <div class="grid grid-cols-[minmax(7rem,0.8fr)_minmax(8rem,2fr)_auto] items-center gap-3">
                    <div class="truncate text-xs text-neutral-300" title="{{ $row['group'] }}">{{ $row['group'] }}</div>
                    <div class="h-3 overflow-hidden rounded-full bg-white/8">
                        <div class="h-full min-w-1 rounded-full" style="width: {{ max(2, ((int) $row['record_count'] / $maximum) * 100) }}%; background: {{ $colors[$index] }}"></div>
                    </div>
                    <div class="text-end text-xs font-semibold text-white">{{ number_format((int) $row['record_count']) }}</div>
                </div>
            @endforeach
        </div>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_LOLLIPOP)
        <div class="grid gap-4" role="img" aria-label="{{ __('report_designer.presentation.chart_aria', ['group' => $grouping['label']]) }}">
            @foreach($rows as $index => $row)
                @php($width = max(4, ((int) $row['record_count'] / $maximum) * 100))
                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="truncate text-neutral-300" title="{{ $row['group'] }}">{{ $row['group'] }}</span>
                        <span class="shrink-0 font-semibold text-white">{{ number_format((int) $row['record_count']) }}</span>
                    </div>
                    <div class="relative h-4" aria-hidden="true">
                        <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-white/10"></div>
                        <div class="absolute start-0 top-1/2 h-0.5 -translate-y-1/2 rounded-full" style="width: {{ $width }}%; background: {{ $colors[$index] }}"></div>
                        <span class="absolute top-1/2 size-3 -translate-y-1/2 rounded-full border-2 border-neutral-950 shadow-[0_0_0_2px_rgba(255,255,255,0.08)]" style="inset-inline-start: calc({{ $width }}% - 0.375rem); background: {{ $colors[$index] }}"></span>
                    </div>
                </div>
            @endforeach
        </div>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_DONUT && $total > 0)
        <div class="grid items-center gap-5 sm:grid-cols-[11rem_minmax(0,1fr)]">
            <div class="relative mx-auto grid size-40 place-items-center rounded-full" style="background: conic-gradient({{ implode(', ', $segments) }})" role="img" aria-label="{{ __('report_designer.presentation.chart_aria', ['group' => $grouping['label']]) }}">
                <div class="grid size-24 place-items-center rounded-full bg-neutral-950/90 text-center">
                    <div>
                        <div class="text-2xl font-semibold text-white">{{ number_format($total) }}</div>
                        <div class="mt-1 text-[0.65rem] text-neutral-400">{{ __('report_designer.presentation.records') }}</div>
                    </div>
                </div>
            </div>
            <div class="grid gap-2">
                @foreach($rows as $index => $row)
                    <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 text-xs">
                        <span class="size-2.5 rounded-full" style="background: {{ $colors[$index] }}"></span>
                        <span class="truncate text-neutral-300" title="{{ $row['group'] }}">{{ $row['group'] }}</span>
                        <span class="font-semibold text-white">{{ number_format((int) $row['record_count']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-white/10">
            <table class="min-w-full {{ $density === 'compact' ? 'text-xs' : 'text-sm' }}">
                <thead><tr>
                    @foreach ($grouping['columns'] as $column)
                        <th class="{{ $density === 'compact' ? 'px-3 py-2' : 'px-4 py-3' }} text-start">{{ $column }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-white/5">
                            @foreach (array_keys($grouping['columns']) as $columnKey)
                                <td class="{{ $density === 'compact' ? 'px-3 py-2' : 'px-4 py-3' }} text-neutral-200">{{ is_float($row[$columnKey]) ? number_format($row[$columnKey], 2) : $row[$columnKey] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

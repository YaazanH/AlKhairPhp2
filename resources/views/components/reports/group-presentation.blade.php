@props(['grouping', 'presentation' => [], 'compact' => false])

@php
    $type = (string) data_get($presentation, 'type', \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE);
    $density = (string) data_get($presentation, 'density', 'comfortable');
    $allRows = collect($grouping['rows'] ?? [])->values();
    $chartLimit = $compact ? 5 : 9;
    $isTable = $type === \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE;
    $isLine = $type === \App\Services\ReportDesignerCatalog::PRESENTATION_LINE;
    $rows = match (true) {
        $isTable => $allRows->take($compact ? 6 : PHP_INT_MAX)->values(),
        $isLine => $allRows->take(-$chartLimit)->values(),
        default => $allRows->take($chartLimit)->values(),
    };
    $remainingCount = $isTable || $isLine ? 0 : (int) $allRows->skip($chartLimit)->sum('record_count');
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
    $lineX = fn (int $index): float => app()->isLocale('ar')
        ? 420 - ($index * (384 / max($rows->count() - 1, 1)))
        : 36 + ($index * (384 / max($rows->count() - 1, 1)));
    $lineY = fn (int $value): float => 164 - (($value / $maximum) * 132);
    $linePoints = $rows->map(fn ($row, int $index): string => $lineX($index).','.$lineY((int) $row['record_count']))->implode(' ');
    $lineDecimals = $maximum < 4 ? 1 : 0;
    $lineLabel = function (string $value): string {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $value;
        }

        return app()->isLocale('ar') ? substr($value, 8, 2).'-'.substr($value, 5, 2) : substr($value, 5, 5);
    };
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
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_LINE)
        <svg viewBox="0 0 456 208" dir="ltr" class="h-auto w-full overflow-visible" role="img" aria-label="{{ __('report_designer.presentation.chart_aria', ['group' => $grouping['label']]) }}">
            <line x1="36" y1="164" x2="420" y2="164" stroke="rgba(255,255,255,.22)" stroke-width="1.5" />
            @foreach(range(0, 4) as $tick)
                @php($gridY = 164 - (($tick / 4) * 132))
                <line x1="36" y1="{{ $gridY }}" x2="420" y2="{{ $gridY }}" stroke="rgba(255,255,255,.08)" stroke-width="1" />
                <text x="28" y="{{ $gridY + 3 }}" text-anchor="end" fill="#a3a3a3" font-size="9">{{ number_format(($maximum / 4) * $tick, $lineDecimals) }}</text>
            @endforeach
            <polyline points="{{ $linePoints }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
            @foreach($rows as $index => $row)
                <g tabindex="0">
                    <circle cx="{{ $lineX($index) }}" cy="{{ $lineY((int) $row['record_count']) }}" r="5" fill="#38bdf8" stroke="#0a0a0a" stroke-width="2" />
                    <title>{{ $row['group'] }}: {{ number_format((int) $row['record_count']) }}</title>
                    <text x="{{ $lineX($index) }}" y="190" text-anchor="middle" fill="#a3a3a3" font-size="9">{{ $lineLabel((string) $row['group']) }}</text>
                </g>
            @endforeach
        </svg>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_TREEMAP)
        <div class="flex min-h-64 flex-wrap content-stretch gap-2" role="img" aria-label="{{ __('report_designer.presentation.chart_aria', ['group' => $grouping['label']]) }}">
            @foreach($rows as $index => $row)
                @php($share = ((int) $row['record_count'] / max(1, $rows->sum('record_count'))) * 100)
                @php($basis = max($compact ? 34 : 24, $share))
                <div class="flex min-w-32 flex-col justify-between overflow-hidden rounded-2xl border p-4" style="flex: {{ max(1, (int) $row['record_count']) }} 1 {{ $basis }}%; min-height: {{ $compact ? 6 : max(7, 6 + ($share / 12)) }}rem; border-color: {{ $colors[$index] }}; background: color-mix(in srgb, {{ $colors[$index] }} 18%, transparent)">
                    <div class="truncate text-sm font-semibold text-white" title="{{ $row['group'] }}">{{ $row['group'] }}</div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <span class="text-3xl font-semibold text-white">{{ number_format((int) $row['record_count']) }}</span>
                        <span class="rounded-full bg-black/20 px-2 py-1 text-xs text-neutral-200">{{ number_format($share, 1) }}%</span>
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

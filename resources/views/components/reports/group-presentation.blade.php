@props(['grouping', 'presentation' => [], 'compact' => false])

@php
    $type = (string) data_get($presentation, 'type', \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE);
    $density = (string) data_get($presentation, 'density', 'comfortable');
    $allRows = collect($grouping['rows'] ?? [])->values();
    $chartLimit = $compact ? 5 : 9;
    $isTable = $type === \App\Services\ReportDesignerCatalog::PRESENTATION_TABLE;
    $isLine = $type === \App\Services\ReportDesignerCatalog::PRESENTATION_LINE;
    $isHotbar = $type === 'hotbar';
    $rows = match (true) {
        $isTable => $allRows->take($compact ? 6 : PHP_INT_MAX)->values(),
        $isLine => $allRows->take(-$chartLimit)->values(),
        $isHotbar => $allRows->take($chartLimit)->values(),
        default => $allRows->take($chartLimit)->values(),
    };
    $metricKey = (string) data_get($presentation, 'metric', 'record_count');
    if (! array_key_exists($metricKey, $grouping['columns'] ?? [])) {
        $metricKey = 'record_count';
    }
    $metricLabel = (string) data_get($grouping, 'columns.'.$metricKey, __('report_designer.calculations.record_count'));
    $chartAria = __('report_designer.presentation.chart_metric_aria', ['metric' => $metricLabel, 'group' => $grouping['label']]);
    $remainingCount = $isTable || $isLine || $isHotbar ? 0 : (int) $allRows->skip($chartLimit)->sum('record_count');
    $remainingMetric = $isTable || $isLine || $isHotbar ? 0.0 : (float) $allRows->skip($chartLimit)->sum($metricKey);
    if ($remainingCount > 0) {
        $other = ['group' => __('report_designer.presentation.other'), 'record_count' => $remainingCount];
        $other[$metricKey] = $metricKey === 'record_count' ? $remainingCount : $remainingMetric;
        $rows->push($other);
    }
    $metricValue = fn (array $row): float => max(0, (float) ($row[$metricKey] ?? 0));
    $formatMetric = fn (mixed $value): string => number_format((float) $value, floor(abs((float) $value)) === abs((float) $value) ? 0 : 2);
    $maximum = max(1.0, (float) $rows->max(fn (array $row): float => $metricValue($row)));
    $total = max(0.0, (float) $allRows->sum(fn (array $row): float => $metricValue($row)));
    $colors = $rows->map(fn ($row, int $index): string => sprintf('hsl(%.1f 48%% 58%%)', fmod(24 + ($index * 137.508), 360)));
    $cursor = 0.0;
    $segments = [];
    if ($total > 0) {
        foreach ($rows as $index => $row) {
            $next = $cursor + (($metricValue($row) / $total) * 100);
            $segments[] = $colors[$index].' '.round($cursor, 3).'% '.round($next, 3).'%';
            $cursor = $next;
        }
    }
    $lineX = fn (int $index): float => app()->isLocale('ar')
        ? 420 - ($index * (384 / max($rows->count() - 1, 1)))
        : 36 + ($index * (384 / max($rows->count() - 1, 1)));
    $lineY = fn (float $value): float => 164 - (($value / $maximum) * 132);
    $linePoints = $rows->map(fn ($row, int $index): string => $lineX($index).','.$lineY($metricValue($row)))->implode(' ');
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
        <div class="grid gap-3" role="img" aria-label="{{ $chartAria }}">
            @foreach($rows as $index => $row)
                <div class="grid grid-cols-[minmax(7rem,0.8fr)_minmax(8rem,2fr)_auto] items-center gap-3">
                    <div class="truncate text-xs text-neutral-300" title="{{ $row['group'] }}">{{ $row['group'] }}</div>
                    <div class="h-3 overflow-hidden rounded-full bg-white/8">
                        <div class="h-full min-w-1 rounded-full" style="width: {{ max(2, ($metricValue($row) / $maximum) * 100) }}%; background: {{ $colors[$index] }}"></div>
                    </div>
                    <div class="text-end text-xs font-semibold text-white">{{ $formatMetric($row[$metricKey] ?? 0) }}</div>
                </div>
            @endforeach
        </div>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_LOLLIPOP)
        <div class="grid gap-4" role="img" aria-label="{{ $chartAria }}">
            @foreach($rows as $index => $row)
                @php
                    $width = max(4, ($metricValue($row) / $maximum) * 100);
                @endphp
                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="truncate text-neutral-300" title="{{ $row['group'] }}">{{ $row['group'] }}</span>
                        <span class="shrink-0 font-semibold text-white">{{ $formatMetric($row[$metricKey] ?? 0) }}</span>
                    </div>
                    <div class="relative h-4" aria-hidden="true">
                        <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-white/10"></div>
                        <div class="absolute start-0 top-1/2 h-0.5 -translate-y-1/2 rounded-full" style="width: {{ $width }}%; background: {{ $colors[$index] }}"></div>
                        <span class="absolute top-1/2 size-3 -translate-y-1/2 rounded-full border-2 border-neutral-950 shadow-[0_0_0_2px_rgba(255,255,255,0.08)]" style="inset-inline-start: calc({{ $width }}% - 0.375rem); background: {{ $colors[$index] }}"></span>
                    </div>
                </div>
            @endforeach
        </div>
    @elseif($isHotbar)
        @php
            $totalMetricKey = (string) data_get($presentation, 'total_metric');
        @endphp
        <div class="dashboard-curriculum-hotbars grid gap-x-10 gap-y-[0.875rem] {{ $compact ? '' : 'md:grid-cols-2' }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}" data-dashboard-curriculum-hotbars role="img" aria-label="{{ $chartAria }}">
            @foreach($rows as $row)
                @php
                    $percentage = min(100, $metricValue($row));
                    $peerPercentages = $allRows
                        ->reject(fn (array $peer): bool => $peer['group'] === $row['group'])
                        ->map(fn (array $peer): float => min(100, $metricValue($peer)));
                    $peerAverage = $peerPercentages->isEmpty() ? $percentage : (float) $peerPercentages->average();
                    $percentageGap = max(0, $peerAverage - $percentage);
                    $totalLessons = max(0, (int) ($row[$totalMetricKey] ?? 0));
                    $lessonsBehind = max(0, (int) ceil((($percentageGap / 100) * $totalLessons) - 0.00001));
                    $tone = $percentageGap > 15 ? 'danger' : ($percentageGap > 5 ? 'warning' : 'success');
                @endphp
                <div class="dashboard-curriculum-hotbar" data-dashboard-curriculum-hotbar data-dashboard-curriculum-name-gap="{{ __('report_designer.fields.group_name') }}" data-progress-tone="{{ $tone }}" data-lessons-behind="{{ $lessonsBehind }}">
                    <div class="dashboard-curriculum-hotbar__identity">
                        <span class="dashboard-curriculum-hotbar__group" title="{{ $row['group'] }}">{{ $row['group'] }}</span>
                        <div class="record-person-name dashboard-curriculum-hotbar__teacher">{{ __('curricula.progress.completed', ['percent' => number_format($percentage, 1)]) }}</div>
                    </div>
                    <div class="dashboard-curriculum-hotbar__track">
                        <span class="dashboard-curriculum-hotbar__fill dashboard-curriculum-hotbar__fill--{{ $tone }}" style="width: {{ $percentage }}%" aria-hidden="true"></span>
                        <span
                            class="dashboard-curriculum-hotbar__marker dashboard-curriculum-hotbar__marker--{{ $tone }} dashboard-lollipop-attendance"
                            style="inset-inline-start: min(calc(100% - 1rem), max(0px, calc({{ $percentage }}% - .5rem)))"
                            tabindex="0"
                            role="img"
                            aria-label="{{ number_format($percentage, 1) }}% / {{ trans_choice('dashboard.manager.analytics.curriculum_lessons_behind', $lessonsBehind, ['count' => number_format($lessonsBehind)]) }}"
                        >
                            <span class="dashboard-lollipop-attendance__tooltip dashboard-curriculum-hotbar__tooltip">
                                <strong>{{ number_format($percentage, 1) }}%</strong>
                                <span>{{ trans_choice('dashboard.manager.analytics.curriculum_lessons_behind', $lessonsBehind, ['count' => number_format($lessonsBehind)]) }}</span>
                            </span>
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_LINE)
        <svg viewBox="0 0 456 208" dir="ltr" class="h-auto w-full overflow-visible" role="img" aria-label="{{ $chartAria }}">
            <line x1="36" y1="164" x2="420" y2="164" stroke="rgba(255,255,255,.22)" stroke-width="1.5" />
            @foreach(range(0, 4) as $tick)
                @php
                    $gridY = 164 - (($tick / 4) * 132);
                @endphp
                <line x1="36" y1="{{ $gridY }}" x2="420" y2="{{ $gridY }}" stroke="rgba(255,255,255,.08)" stroke-width="1" />
                <text x="28" y="{{ $gridY + 3 }}" text-anchor="end" fill="#a3a3a3" font-size="9">{{ number_format(($maximum / 4) * $tick, $lineDecimals) }}</text>
            @endforeach
            <polyline points="{{ $linePoints }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
            @foreach($rows as $index => $row)
                <g tabindex="0">
                    <circle cx="{{ $lineX($index) }}" cy="{{ $lineY($metricValue($row)) }}" r="5" fill="#38bdf8" stroke="#0a0a0a" stroke-width="2" />
                    <title>{{ $row['group'] }}: {{ $formatMetric($row[$metricKey] ?? 0) }}</title>
                    <text x="{{ $lineX($index) }}" y="190" text-anchor="middle" fill="#a3a3a3" font-size="9">{{ $lineLabel((string) $row['group']) }}</text>
                </g>
            @endforeach
        </svg>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_TREEMAP)
        <div class="flex min-h-64 flex-wrap content-stretch gap-2" role="img" aria-label="{{ $chartAria }}">
            @foreach($rows as $index => $row)
                @php
                    $share = ($metricValue($row) / max(1, $rows->sum(fn (array $item): float => $metricValue($item)))) * 100;
                    $basis = max($compact ? 34 : 24, $share);
                @endphp
                <div class="flex min-w-32 flex-col justify-between overflow-hidden rounded-2xl border p-4" style="flex: {{ max(1, $metricValue($row)) }} 1 {{ $basis }}%; min-height: {{ $compact ? 6 : max(7, 6 + ($share / 12)) }}rem; border-color: {{ $colors[$index] }}; background: color-mix(in srgb, {{ $colors[$index] }} 18%, transparent)">
                    <div class="truncate text-sm font-semibold text-white" title="{{ $row['group'] }}">{{ $row['group'] }}</div>
                    <div class="mt-4 flex items-end justify-between gap-3">
                        <span class="text-3xl font-semibold text-white">{{ $formatMetric($row[$metricKey] ?? 0) }}</span>
                        <span class="rounded-full bg-black/20 px-2 py-1 text-xs text-neutral-200">{{ number_format($share, 1) }}%</span>
                    </div>
                </div>
            @endforeach
        </div>
    @elseif($type === \App\Services\ReportDesignerCatalog::PRESENTATION_DONUT && $total > 0)
        <div class="grid items-center gap-5 sm:grid-cols-[11rem_minmax(0,1fr)]">
            <div class="relative mx-auto grid size-40 place-items-center rounded-full" style="background: conic-gradient({{ implode(', ', $segments) }})" role="img" aria-label="{{ $chartAria }}">
                <div class="grid size-24 place-items-center rounded-full bg-neutral-950/90 text-center">
                    <div>
                        <div class="text-2xl font-semibold text-white">{{ $formatMetric($total) }}</div>
                        <div class="mt-1 line-clamp-2 px-1 text-[0.6rem] leading-3 text-neutral-400">{{ $metricLabel }}</div>
                    </div>
                </div>
            </div>
            <div class="grid gap-2">
                @foreach($rows as $index => $row)
                    <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 text-xs">
                        <span class="size-2.5 rounded-full" style="background: {{ $colors[$index] }}"></span>
                        <span class="truncate text-neutral-300" title="{{ $row['group'] }}">{{ $row['group'] }}</span>
                        <span class="font-semibold text-white">{{ $formatMetric($row[$metricKey] ?? 0) }}</span>
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

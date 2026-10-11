@props([
    'title',
    'description' => null,
    'points' => [],
    'series' => [],
    'format' => 'number',
    'suffix' => '',
])

@php
    $width = 720;
    $height = 240;
    $padding = 34;
    $values = collect($series)
        ->flatMap(fn (array $item) => collect($points)->pluck($item['key']))
        ->filter(fn ($value) => is_numeric($value));
    $maximum = $format === 'percent' ? 100.0 : max(1.0, (float) ($values->max() ?? 1));
    $hasTrend = $values->count() >= 2 && count($points) >= 2;
    $formatValue = static function ($value) use ($format, $suffix): string {
        if (! is_numeric($value)) {
            return '—';
        }

        if ($format === 'bytes') {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $unit = $value > 0 ? min((int) floor(log($value, 1024)), count($units) - 1) : 0;

            return number_format($value / (1024 ** $unit), $unit === 0 ? 0 : 1).' '.$units[$unit];
        }

        return number_format((float) $value, $format === 'number' ? 0 : 1).$suffix;
    };
    $coordinate = static function (int $index, $value) use ($points, $width, $height, $padding, $maximum): array {
        $x = $padding + ($index * (($width - ($padding * 2)) / max(1, count($points) - 1)));
        $y = ($height - $padding) - (((float) $value / $maximum) * ($height - ($padding * 2)));

        return [round($x, 2), round($y, 2)];
    };
@endphp

<article class="rounded-2xl border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" data-platform-metric-chart>
    <div>
        <h3 class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $title }}</h3>
        @if($description)
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>

    @if($hasTrend)
        <div class="mt-4 flex flex-wrap gap-4 text-xs text-zinc-600 dark:text-zinc-300">
            @foreach($series as $item)
                <span class="inline-flex items-center gap-2"><i class="size-2.5 rounded-full" style="background: {{ $item['colour'] }}"></i>{{ $item['label'] }}</span>
            @endforeach
        </div>

        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="mt-4 h-auto w-full overflow-visible text-zinc-500" role="img" aria-label="{{ $title }}" dir="ltr">
            @foreach([0, .5, 1] as $step)
                @php
                    $gridY = $padding + (($height - ($padding * 2)) * $step);
                @endphp
                <line x1="{{ $padding }}" x2="{{ $width - $padding }}" y1="{{ $gridY }}" y2="{{ $gridY }}" stroke="currentColor" stroke-opacity=".15" />
            @endforeach

            @foreach($series as $item)
                @php
                    $started = false;
                    $path = collect($points)
                        ->map(function (array $point, int $index) use ($item, $coordinate, &$started): ?string {
                            $value = $point[$item['key']] ?? null;
                            if (! is_numeric($value)) {
                                return null;
                            }
                            [$x, $y] = $coordinate($index, $value);
                            $command = $started ? 'L' : 'M';
                            $started = true;

                            return $command.$x.','.$y;
                        })
                        ->filter()
                        ->implode(' ');
                @endphp
                <path d="{{ $path }}" fill="none" stroke="{{ $item['colour'] }}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                @foreach($points as $index => $point)
                    @if(is_numeric($point[$item['key']] ?? null))
                        @php
                            [$x, $y] = $coordinate($index, $point[$item['key']]);
                        @endphp
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="{{ $item['colour'] }}">
                            <title>{{ $item['label'] }} · {{ $point['label'] }} · {{ $formatValue($point[$item['key']]) }}</title>
                        </circle>
                    @endif
                @endforeach
            @endforeach

            <text x="{{ $padding }}" y="{{ $height - 7 }}" fill="currentColor" font-size="12">{{ $points[0]['label'] }}</text>
            <text x="{{ $width - $padding }}" y="{{ $height - 7 }}" fill="currentColor" font-size="12" text-anchor="end">{{ $points[array_key_last($points)]['label'] }}</text>
        </svg>
    @else
        <div class="mt-4 rounded-xl bg-zinc-50 px-4 py-8 text-center text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            {{ __('platform.ui.metrics.history_collecting') }}
        </div>
    @endif
</article>

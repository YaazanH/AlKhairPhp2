<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AcademicYear;
use App\Services\MemorizationRankingService;
use Carbon\Carbon;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public mixed $academic_year_id = null;
    public string $first_date_from = '';
    public string $first_date_to = '';
    public string $second_date_from = '';
    public string $second_date_to = '';

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
        $this->academic_year_id = $this->currentAcademicYearId();

        ['first_from' => $firstFrom, 'first_to' => $firstTo, 'second_from' => $secondFrom, 'second_to' => $secondTo] = $this->defaultRanges();

        $this->first_date_from = $firstFrom;
        $this->first_date_to = $firstTo;
        $this->second_date_from = $secondFrom;
        $this->second_date_to = $secondTo;
    }

    public function clearFilters(): void
    {
        $this->academic_year_id = $this->currentAcademicYearId();

        ['first_from' => $firstFrom, 'first_to' => $firstTo, 'second_from' => $secondFrom, 'second_to' => $secondTo] = $this->defaultRanges();

        $this->first_date_from = $firstFrom;
        $this->first_date_to = $firstTo;
        $this->second_date_from = $secondFrom;
        $this->second_date_to = $secondTo;
    }

    public function with(): array
    {
        $this->normalizeFilters();

        return [
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->get(['id', 'name']),
            'comparison' => app(MemorizationRankingService::class)->compareGroups($this->filters()),
        ];
    }

    protected function filters(): array
    {
        $this->normalizeFilters();

        return [
            'academic_year_id' => $this->academic_year_id,
            'first_date_from' => $this->first_date_from,
            'first_date_to' => $this->first_date_to,
            'second_date_from' => $this->second_date_from,
            'second_date_to' => $this->second_date_to,
        ];
    }

    protected function normalizeFilters(): void
    {
        $this->academic_year_id = $this->normalizeSelectValue($this->academic_year_id);

        if ($this->first_date_from !== '' && $this->first_date_to !== '' && $this->first_date_from > $this->first_date_to) {
            [$this->first_date_from, $this->first_date_to] = [$this->first_date_to, $this->first_date_from];
        }

        if ($this->second_date_from !== '' && $this->second_date_to !== '' && $this->second_date_from > $this->second_date_to) {
            [$this->second_date_from, $this->second_date_to] = [$this->second_date_to, $this->second_date_from];
        }
    }

    protected function normalizeSelectValue(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = collect($value)
                ->filter(fn ($item) => $item !== null && $item !== '')
                ->first();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function currentAcademicYearId(): ?int
    {
        return AcademicYear::query()
            ->where('is_current', true)
            ->where('is_active', true)
            ->value('id');
    }

    protected function defaultRanges(): array
    {
        $secondTo = now()->startOfDay();
        $secondFrom = $secondTo->copy()->subDays(29);
        $firstTo = $secondFrom->copy()->subDay();
        $firstFrom = $firstTo->copy()->subDays(29);

        return [
            'first_from' => $firstFrom->toDateString(),
            'first_to' => $firstTo->toDateString(),
            'second_from' => $secondFrom->toDateString(),
            'second_to' => $secondTo->toDateString(),
        ];
    }
}; ?>

@php
    $movementMeta = [
        'up' => ['class' => 'ranking-movement-badge--up', 'icon' => '▲', 'label' => __('reports.rankings.movement.up')],
        'down' => ['class' => 'ranking-movement-badge--down', 'icon' => '▼', 'label' => __('reports.rankings.movement.down')],
        'same' => ['class' => 'ranking-movement-badge--same', 'icon' => '—', 'label' => __('reports.rankings.movement.same')],
        'new' => ['class' => 'ranking-movement-badge--new', 'icon' => '✦', 'label' => __('reports.rankings.movement.new')],
        'dropped' => ['class' => 'ranking-movement-badge--dropped', 'icon' => '•', 'label' => __('reports.rankings.movement.dropped')],
    ];

    $firstLeader = $comparison['first_range']['leader'] ?? null;
    $secondLeader = $comparison['second_range']['leader'] ?? null;
    $biggestImprover = $comparison['summary']['biggest_improver'] ?? null;
    $biggestDecline = $comparison['summary']['biggest_decline'] ?? null;

    $summaryCards = [
        [
            'label' => __('reports.rankings.summary.first_leader'),
            'value' => $firstLeader['entity_name'] ?? __('reports.rankings.summary.no_data'),
            'hint' => $firstLeader
                ? __('reports.rankings.summary.pages_and_sessions', ['pages' => number_format($firstLeader['pages']), 'sessions' => number_format($firstLeader['sessions'])])
                : __('reports.rankings.summary.no_rows'),
        ],
        [
            'label' => __('reports.rankings.summary.second_leader'),
            'value' => $secondLeader['entity_name'] ?? __('reports.rankings.summary.no_data'),
            'hint' => $secondLeader
                ? __('reports.rankings.summary.pages_and_sessions', ['pages' => number_format($secondLeader['pages']), 'sessions' => number_format($secondLeader['sessions'])])
                : __('reports.rankings.summary.no_rows'),
        ],
        [
            'label' => __('reports.rankings.summary.biggest_improver'),
            'value' => $biggestImprover['entity_name'] ?? __('reports.rankings.summary.no_data'),
            'hint' => $biggestImprover
                ? __('reports.rankings.summary.rank_shift', ['count' => number_format($biggestImprover['movement_steps'] ?? 0)])
                : __('reports.rankings.summary.no_rows'),
        ],
        [
            'label' => __('reports.rankings.summary.biggest_decline'),
            'value' => $biggestDecline['entity_name'] ?? __('reports.rankings.summary.no_data'),
            'hint' => $biggestDecline
                ? __('reports.rankings.summary.rank_shift', ['count' => number_format($biggestDecline['movement_steps'] ?? 0)])
                : __('reports.rankings.summary.no_rows'),
        ],
    ];

    $rangeLabels = [
        'first' => Carbon::parse($comparison['first_range']['date_from'])->format('Y-m-d').' → '.Carbon::parse($comparison['first_range']['date_to'])->format('Y-m-d'),
        'second' => Carbon::parse($comparison['second_range']['date_from'])->format('Y-m-d').' → '.Carbon::parse($comparison['second_range']['date_to'])->format('Y-m-d'),
    ];
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-end">
            <div>
                <div class="eyebrow">{{ __('reports.rankings.groups.eyebrow') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('reports.rankings.groups.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">
                    {{ __('reports.rankings.groups.subtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap gap-3 xl:justify-end">
                <span class="badge-soft ranking-legend-pill">{{ __('reports.rankings.legend.first_range', ['range' => $rangeLabels['first']]) }}</span>
                <span class="badge-soft badge-soft--emerald ranking-legend-pill">{{ __('reports.rankings.legend.second_range', ['range' => $rangeLabels['second']]) }}</span>
            </div>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <section class="surface-panel report-panel min-w-0 p-5 lg:p-6">
            <div class="mb-5">
                <div class="eyebrow">{{ __('reports.rankings.filters.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.rankings.filters.title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.rankings.filters.subtitle') }}</p>
            </div>

            <div class="grid gap-4">
                <div>
                    <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.academic_year') }}</label>
                    <select wire:model.live="academic_year_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_academic_years') }}</option>
                        @foreach ($academicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                    <div class="kpi-label">{{ __('reports.rankings.filters.first_range') }}</div>
                    <div class="mt-3 grid gap-3">
                        <div>
                            <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_from') }}</label>
                            <input wire:model.blur="first_date_from" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_to') }}</label>
                            <input wire:model.blur="first_date_to" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                    <div class="kpi-label">{{ __('reports.rankings.filters.second_range') }}</div>
                    <div class="mt-3 grid gap-3">
                        <div>
                            <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_from') }}</label>
                            <input wire:model.blur="second_date_from" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_to') }}</label>
                            <input wire:model.blur="second_date_to" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex justify-between gap-3">
                <a href="{{ route('reports.index') }}" class="pill-link">{{ __('reports.rankings.filters.back_to_reports') }}</a>
                <button type="button" wire:click="clearFilters" class="pill-link">
                    {{ __('reports.filters.clear') }}
                </button>
            </div>
        </section>

        <div class="grid gap-6">
            <section class="grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
                @foreach ($summaryCards as $card)
                    <article class="surface-panel report-kpi-card p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="kpi-label report-kpi-label">{{ $card['label'] }}</div>
                            <span class="badge-soft report-kpi-index {{ $loop->even ? 'badge-soft--emerald' : '' }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        </div>
                        <div class="report-kpi-value mt-5 text-2xl font-semibold">{{ $card['value'] }}</div>
                        <p class="report-kpi-hint mt-3 text-xs leading-5">{{ $card['hint'] }}</p>
                    </article>
                @endforeach
            </section>

            <section class="surface-panel report-panel min-w-0 p-5 lg:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="eyebrow">{{ __('reports.rankings.legend.eyebrow') }}</div>
                        <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.rankings.legend.title') }}</h2>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @foreach (['up', 'down', 'same', 'new', 'dropped'] as $state)
                            <span class="ranking-movement-badge {{ $movementMeta[$state]['class'] }}">
                                <span aria-hidden="true">{{ $movementMeta[$state]['icon'] }}</span>
                                {{ __('reports.rankings.movement.'.$state) }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ($comparison['summary']['movement_counts'] as $state => $count)
                        <div class="rounded-2xl border border-white/8 bg-white/4 px-4 py-3 text-sm">
                            <div class="text-neutral-200">{{ __('reports.rankings.movement.'.$state) }}</div>
                            <div class="mt-2 text-xl font-semibold text-white">{{ number_format($count) }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="surface-table">
                <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                    <div class="eyebrow">{{ __('reports.rankings.table.eyebrow') }}</div>
                    <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.rankings.groups.table_title') }}</h2>
                </div>

                @if (empty($comparison['rows']))
                    <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.rankings.table.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.rankings.table.current_rank') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.rankings.table.entity') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.rankings.table.first_range') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.rankings.table.second_range') }}</th>
                                    <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.rankings.table.movement') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($comparison['rows'] as $row)
                                    @php($movement = $movementMeta[$row['movement_state']])
                                    <tr>
                                        <td class="px-5 py-4 lg:px-6">
                                            <div class="font-semibold text-white">{{ $row['second_rank'] ? '#'.$row['second_rank'] : '—' }}</div>
                                            @if ($row['first_rank'])
                                                <div class="text-xs text-neutral-400">{{ __('reports.rankings.table.was_ranked', ['rank' => $row['first_rank']]) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 lg:px-6">
                                            <div class="font-medium text-white">{{ $row['entity_name'] }}</div>
                                        </td>
                                        <td class="px-5 py-4 lg:px-6">
                                            @if ($row['first_rank'])
                                                <div class="font-medium text-white">#{{ $row['first_rank'] }}</div>
                                                <div class="text-xs text-neutral-300">{{ __('reports.rankings.table.pages_and_sessions', ['pages' => number_format($row['first_pages']), 'sessions' => number_format($row['first_sessions'])]) }}</div>
                                            @else
                                                <div class="text-neutral-400">{{ __('reports.rankings.table.no_activity') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 lg:px-6">
                                            @if ($row['second_rank'])
                                                <div class="font-medium text-white">#{{ $row['second_rank'] }}</div>
                                                <div class="text-xs text-neutral-300">{{ __('reports.rankings.table.pages_and_sessions', ['pages' => number_format($row['second_pages']), 'sessions' => number_format($row['second_sessions'])]) }}</div>
                                            @else
                                                <div class="text-neutral-400">{{ __('reports.rankings.table.no_activity') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 lg:px-6">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="ranking-movement-badge {{ $movement['class'] }}">
                                                    <span aria-hidden="true">{{ $movement['icon'] }}</span>
                                                    @if ($row['movement_state'] === 'up')
                                                        {{ __('reports.rankings.movement.up_steps', ['count' => number_format($row['movement_steps'])]) }}
                                                    @elseif ($row['movement_state'] === 'down')
                                                        {{ __('reports.rankings.movement.down_steps', ['count' => number_format($row['movement_steps'])]) }}
                                                    @else
                                                        {{ $movement['label'] }}
                                                    @endif
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>

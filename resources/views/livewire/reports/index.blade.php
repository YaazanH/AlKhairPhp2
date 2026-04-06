<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Services\ReportingService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $academic_year_id = null;
    public ?int $group_id = null;
    public string $date_from = '';
    public string $date_to = '';

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
    }

    public function updatedAcademicYearId(): void
    {
        if (! $this->group_id) {
            return;
        }

        $groupExists = $this->scopeGroupsQuery(Group::query())
            ->whereKey($this->group_id)
            ->when($this->academic_year_id, fn ($query) => $query->where('academic_year_id', $this->academic_year_id))
            ->exists();

        if (! $groupExists) {
            $this->group_id = null;
        }
    }

    public function clearFilters(): void
    {
        $this->academic_year_id = null;
        $this->group_id = null;
        $this->date_from = '';
        $this->date_to = '';
    }

    public function with(): array
    {
        return [
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->get(['id', 'name']),
            'groups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with(['course', 'academicYear'])
                    ->when($this->academic_year_id, fn ($query) => $query->where('academic_year_id', $this->academic_year_id))
                    ->orderBy('name')
            )->get(),
            'report' => app(ReportingService::class)->overview($this->filters()),
        ];
    }

    protected function filters(): array
    {
        return [
            'academic_year_id' => $this->academic_year_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'group_id' => $this->group_id,
        ];
    }
}; ?>

@php
    $scopePills = [
        $academic_year_id ? __('reports.scope_pills.academic_filtered') : __('reports.scope_pills.all_academic_years'),
        $group_id ? __('reports.scope_pills.single_group') : __('reports.scope_pills.all_groups'),
        ($date_from || $date_to) ? __('reports.scope_pills.custom_date_range') : __('reports.scope_pills.all_dates'),
    ];

    $headlineCards = [
        ['label' => __('reports.headline.students_in_scope.label'), 'value' => number_format($report['headline']['students_in_scope']), 'hint' => __('reports.headline.students_in_scope.hint')],
        ['label' => __('reports.headline.active_enrollments.label'), 'value' => number_format($report['headline']['active_enrollments']), 'hint' => __('reports.headline.active_enrollments.hint')],
        ['label' => __('reports.headline.memorized_pages.label'), 'value' => number_format($report['headline']['memorized_pages']), 'hint' => __('reports.headline.memorized_pages.hint')],
        ['label' => __('reports.headline.net_points.label'), 'value' => number_format($report['headline']['net_points']), 'hint' => __('reports.headline.net_points.hint')],
        ['label' => __('reports.headline.invoiced_amount.label'), 'value' => number_format($report['headline']['invoiced_amount'], 2), 'hint' => __('reports.headline.invoiced_amount.hint')],
        ['label' => __('reports.headline.cash_collected.label'), 'value' => number_format($report['headline']['cash_collected'], 2), 'hint' => __('reports.headline.cash_collected.hint')],
    ];
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_23rem] xl:items-start">
            <div>
                <div class="eyebrow">{{ __('reports.hero.eyebrow') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('reports.hero.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">
                    {{ __('reports.hero.subtitle') }}
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    @foreach ($scopePills as $pill)
                        <span class="badge-soft {{ $loop->even ? 'badge-soft--emerald' : '' }}">{{ $pill }}</span>
                    @endforeach
                </div>
            </div>

            <aside class="surface-panel surface-panel--soft p-5 lg:p-6">
                <div class="eyebrow">{{ __('reports.financial_readout.title') }}</div>
                <div class="mt-5 space-y-3 text-sm">
                    <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                        <span class="text-neutral-300">{{ __('reports.financial_readout.invoice_billed') }}</span>
                        <span class="font-semibold text-white">{{ number_format($report['finance']['invoice_billed'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                        <span class="text-neutral-300">{{ __('reports.financial_readout.invoice_collected') }}</span>
                        <span class="font-semibold text-white">{{ number_format($report['finance']['invoice_collected'], 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                        <span class="text-neutral-300">{{ __('reports.financial_readout.activity_net') }}</span>
                        <span class="font-semibold text-white">{{ number_format($report['finance']['activity_net'], 2) }}</span>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_24rem]">
        <section class="surface-panel p-6">
            <div class="mb-5">
                <div class="eyebrow">{{ __('reports.filters.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.filters.title') }}</h2>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('reports.filters.subtitle') }}</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-4">
                <div>
                    <label class="mb-2 block text-sm font-medium text-neutral-200">{{ __('reports.filters.academic_year') }}</label>
                    <select wire:model.live="academic_year_id" class="w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_academic_years') }}</option>
                        @foreach ($academicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-neutral-200">{{ __('reports.filters.group') }}</label>
                    <select wire:model.live="group_id" class="w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_groups') }}</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-neutral-200">{{ __('reports.filters.date_from') }}</label>
                    <input wire:model.live="date_from" type="date" class="w-full rounded-xl px-3 py-2.5 text-sm">
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-neutral-200">{{ __('reports.filters.date_to') }}</label>
                    <input wire:model.live="date_to" type="date" class="w-full rounded-xl px-3 py-2.5 text-sm">
                </div>
            </div>

            <div class="mt-5 flex justify-end">
                <button type="button" wire:click="clearFilters" class="pill-link">
                    {{ __('reports.filters.clear') }}
                </button>
            </div>
        </section>

        <section class="surface-panel p-6">
            <div class="eyebrow">{{ __('reports.exports.eyebrow') }}</div>
            <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.exports.title') }}</h2>
            <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.exports.subtitle') }}</p>

            <div class="mt-5 grid gap-3">
                <a href="{{ route('reports.exports.attendance', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link pill-link--accent">
                    {{ __('reports.exports.attendance') }}
                </a>
                <a href="{{ route('reports.exports.memorization', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link">
                    {{ __('reports.exports.memorization') }}
                </a>
                <a href="{{ route('reports.exports.points', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link">
                    {{ __('reports.exports.points') }}
                </a>
                <a href="{{ route('reports.exports.assessments', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link">
                    {{ __('reports.exports.assessments') }}
                </a>
            </div>
        </section>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($headlineCards as $card)
            <article class="stat-card">
                <div class="flex items-start justify-between gap-4">
                    <div class="kpi-label">{{ $card['label'] }}</div>
                    <span class="badge-soft {{ $loop->even ? 'badge-soft--emerald' : '' }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                </div>
                <div class="metric-value mt-6">{{ $card['value'] }}</div>
                <p class="mt-4 max-w-xs text-sm leading-6 text-neutral-300">{{ $card['hint'] }}</p>
            </article>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="surface-panel p-5 lg:p-6">
            <div class="mb-4">
                <div class="eyebrow">{{ __('reports.attendance.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.attendance.title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.attendance.days_recorded', ['count' => number_format($report['attendance']['days_recorded'])]) }}</p>
            </div>

            <div class="space-y-3">
                @foreach ($report['attendance']['breakdown'] as $status)
                    <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3 text-sm">
                        <span class="text-neutral-200">{{ $status['name'] }}</span>
                        <span class="font-semibold text-white">{{ number_format($status['count']) }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="surface-panel p-5 lg:p-6">
            <div class="mb-4">
                <div class="eyebrow">{{ __('reports.assessments.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.assessments.title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.assessments.subtitle') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                    <div class="kpi-label">{{ __('reports.assessments.results_recorded') }}</div>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ number_format($report['assessments']['results_recorded']) }}</div>
                </div>
                <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                    <div class="kpi-label">{{ __('reports.assessments.average_score') }}</div>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ number_format($report['assessments']['average_score'], 2) }}</div>
                </div>
                <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                    <div class="kpi-label">{{ __('reports.assessments.passed') }}</div>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ number_format($report['assessments']['passed']) }}</div>
                </div>
                <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                    <div class="kpi-label">{{ __('reports.assessments.failed') }}</div>
                    <div class="mt-3 text-2xl font-semibold text-white">{{ number_format($report['assessments']['failed']) }}</div>
                </div>
            </div>
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <div class="eyebrow">{{ __('reports.leaderboard.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.leaderboard.points_title') }}</h2>
            </div>

            @if (empty($report['points_leaderboard']))
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.leaderboard.points_empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.student') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.net_points') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.transactions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($report['points_leaderboard'] as $row)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">{{ $row['student_name'] ?: __('reports.leaderboard.unknown_student') }}</td>
                                    <td class="px-5 py-4 text-white lg:px-6">{{ number_format($row['net_points']) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($row['transactions']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <div class="eyebrow">{{ __('reports.leaderboard.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.leaderboard.memorization_title') }}</h2>
            </div>

            @if (empty($report['memorization_leaderboard']))
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.leaderboard.memorization_empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.student') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.pages') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.sessions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($report['memorization_leaderboard'] as $row)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">{{ $row['student_name'] ?: __('reports.leaderboard.unknown_student') }}</td>
                                    <td class="px-5 py-4 text-white lg:px-6">{{ number_format($row['pages']) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($row['sessions']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="surface-panel p-5 lg:p-6">
            <div class="mb-4">
                <div class="eyebrow">{{ __('reports.finance.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.finance.title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.finance.subtitle') }}</p>
            </div>

            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                    <span class="text-neutral-300">{{ __('reports.finance.invoice_billed') }}</span>
                    <span class="font-semibold text-white">{{ number_format($report['finance']['invoice_billed'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                    <span class="text-neutral-300">{{ __('reports.finance.invoice_collected') }}</span>
                    <span class="font-semibold text-white">{{ number_format($report['finance']['invoice_collected'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                    <span class="text-neutral-300">{{ __('reports.finance.activity_expected') }}</span>
                    <span class="font-semibold text-white">{{ number_format($report['finance']['activity_expected'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                    <span class="text-neutral-300">{{ __('reports.finance.activity_collected') }}</span>
                    <span class="font-semibold text-white">{{ number_format($report['finance']['activity_collected'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                    <span class="text-neutral-300">{{ __('reports.finance.activity_expenses') }}</span>
                    <span class="font-semibold text-white">{{ number_format($report['finance']['activity_expenses'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-2xl border border-white/8 bg-white/4 px-4 py-3">
                    <span class="text-neutral-300">{{ __('reports.finance.activity_net') }}</span>
                    <span class="font-semibold text-white">{{ number_format($report['finance']['activity_net'], 2) }}</span>
                </div>
            </div>
        </section>

        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <div class="eyebrow">{{ __('reports.outstanding.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.outstanding.title') }}</h2>
            </div>

            @if (empty($report['outstanding_invoices']))
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.outstanding.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.outstanding.headers.invoice') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.outstanding.headers.parent') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.outstanding.headers.issue_date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.outstanding.headers.status') }}</th>
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('reports.outstanding.headers.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($report['outstanding_invoices'] as $invoice)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">{{ $invoice['invoice_no'] }}</td>
                                    <td class="px-5 py-4 lg:px-6">{{ $invoice['parent_name'] }}</td>
                                    <td class="px-5 py-4 lg:px-6">{{ $invoice['issue_date'] ?: '-' }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ trans()->has('print.invoice.statuses.'.$invoice['status']) ? __('print.invoice.statuses.'.$invoice['status']) : __('print.invoice.statuses.unknown') }}</td>
                                    <td class="px-5 py-4 text-right text-white lg:px-6">{{ number_format($invoice['balance'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>

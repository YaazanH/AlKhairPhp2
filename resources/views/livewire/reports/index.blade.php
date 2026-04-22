<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AcademicYear;
use App\Models\AssessmentType;
use App\Models\Group;
use App\Services\ReportingService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public mixed $academic_year_id = null;
    public mixed $assessment_type_id = null;
    public mixed $group_id = null;
    public string $date_from = '';
    public string $date_to = '';

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
    }

    public function updatedAcademicYearId(): void
    {
        $this->normalizeFilters();

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
        $this->assessment_type_id = null;
        $this->group_id = null;
        $this->date_from = '';
        $this->date_to = '';
    }

    public function with(): array
    {
        $this->normalizeFilters();

        return [
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->get(['id', 'name']),
            'assessmentTypes' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
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
        $this->normalizeFilters();

        return [
            'academic_year_id' => $this->academic_year_id,
            'assessment_type_id' => $this->assessment_type_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'group_id' => $this->group_id,
        ];
    }

    protected function normalizeFilters(): void
    {
        $this->academic_year_id = $this->normalizeSelectValue($this->academic_year_id);
        $this->assessment_type_id = $this->normalizeSelectValue($this->assessment_type_id);
        $this->group_id = $this->normalizeSelectValue($this->group_id);
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
}; ?>

@php
    $scopePills = [
        $academic_year_id ? __('reports.scope_pills.academic_filtered') : __('reports.scope_pills.all_academic_years'),
        $group_id ? __('reports.scope_pills.single_group') : __('reports.scope_pills.all_groups'),
        $assessment_type_id ? __('reports.scope_pills.assessment_type_filtered') : __('reports.scope_pills.all_assessment_types'),
        ($date_from || $date_to) ? __('reports.scope_pills.custom_date_range') : __('reports.scope_pills.all_dates'),
    ];

    $headlineCards = [
        ['label' => __('reports.headline.active_enrollments.label'), 'value' => number_format($report['headline']['active_enrollments']), 'hint' => __('reports.headline.active_enrollments.hint')],
        ['label' => __('reports.headline.memorized_pages.label'), 'value' => number_format($report['headline']['memorized_pages']), 'hint' => __('reports.headline.memorized_pages.hint')],
        ['label' => __('reports.headline.net_points.label'), 'value' => number_format($report['headline']['net_points']), 'hint' => __('reports.headline.net_points.hint')],
    ];
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)] xl:items-start">
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

        </div>
    </section>

    <div class="reports-overview-grid grid items-stretch gap-6 xl:grid-cols-3">
        <section class="surface-panel report-panel report-panel--filters min-w-0 p-5 lg:p-6">
            <div class="mb-5">
                <div class="eyebrow">{{ __('reports.filters.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.filters.title') }}</h2>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('reports.filters.subtitle') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.academic_year') }}</label>
                    <select wire:model.live="academic_year_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_academic_years') }}</option>
                        @foreach ($academicYears as $academicYear)
                            <option value="{{ $academicYear->id }}">{{ $academicYear->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.group') }}</label>
                    <select wire:model.live="group_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_groups') }}</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_from') }}</label>
                    <input wire:model.live="date_from" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                </div>

                <div>
                    <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_to') }}</label>
                    <input wire:model.live="date_to" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                </div>
            </div>

            <div class="mt-5 flex justify-end">
                <button type="button" wire:click="clearFilters" class="pill-link">
                    {{ __('reports.filters.clear') }}
                </button>
            </div>
        </section>

        <section class="report-kpi-stack grid min-w-0 gap-3">
            @foreach ($headlineCards as $card)
                <article class="surface-panel report-kpi-card p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="kpi-label report-kpi-label">{{ $card['label'] }}</div>
                        <span class="badge-soft report-kpi-index {{ $loop->even ? 'badge-soft--emerald' : '' }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <div class="metric-value report-kpi-value mt-5">{{ $card['value'] }}</div>
                    <p class="report-kpi-hint mt-3 text-xs leading-5">{{ $card['hint'] }}</p>
                </article>
            @endforeach
        </section>

        <section class="surface-panel report-panel report-panel--exports min-w-0 p-6">
            <div class="eyebrow">{{ __('reports.exports.eyebrow') }}</div>
            <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.exports.title') }}</h2>
            <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.exports.subtitle') }}</p>

            <div class="report-export-list mt-5 grid gap-3">
                <a href="{{ route('reports.exports.attendance', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link pill-link--accent report-export-link">
                    {{ __('reports.exports.attendance') }}
                </a>
                <a href="{{ route('reports.exports.memorization', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">
                    {{ __('reports.exports.memorization') }}
                </a>
                <a href="{{ route('reports.exports.points', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">
                    {{ __('reports.exports.points') }}
                </a>
                <a href="{{ route('reports.exports.assessments', ['academic_year_id' => $academic_year_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">
                    {{ __('reports.exports.assessments') }}
                </a>
            </div>
        </section>
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
            <div class="mb-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_16rem] md:items-end">
                <div>
                    <div class="eyebrow">{{ __('reports.assessments.eyebrow') }}</div>
                    <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.assessments.title') }}</h2>
                    <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.assessments.subtitle') }}</p>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-neutral-200">{{ __('reports.filters.assessment_type') }}</label>
                    <select wire:model.live="assessment_type_id" class="w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_assessment_types') }}</option>
                        @foreach ($assessmentTypes as $assessmentType)
                            <option value="{{ $assessmentType->id }}">{{ $assessmentType->name }}</option>
                        @endforeach
                    </select>
                </div>
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
</div>

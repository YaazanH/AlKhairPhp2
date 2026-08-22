<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Group;
use App\Services\ReportingService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public mixed $course_id = null;
    public mixed $assessment_type_id = null;
    public mixed $group_id = null;
    public string $date_from = '';
    public string $date_to = '';

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
    }

    public function updatedCourseId(): void
    {
        $this->normalizeFilters();

        if (! $this->group_id) {
            return;
        }

        $groupExists = $this->scopeGroupsQuery(Group::query())
            ->whereKey($this->group_id)
            ->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))
            ->exists();

        if (! $groupExists) {
            $this->group_id = null;
        }
    }

    public function clearFilters(): void
    {
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->assessment_type_id = null;
        $this->group_id = null;
        $this->date_from = '';
        $this->date_to = '';
    }

    public function with(): array
    {
        $this->normalizeFilters();

        return [
            'courses' => Course::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'assessmentTypes' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'groups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with(['course', 'academicYear'])
                    ->where('is_active', true)
                    ->whereHas('course', fn ($query) => $query->where('is_active', true))
                    ->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))
                    ->orderBy('name')
            )->get(),
            'report' => app(ReportingService::class)->overview($this->filters()),
        ];
    }

    protected function filters(): array
    {
        $this->normalizeFilters();

        return [
            'academic_year_id' => null,
            'course_id' => $this->course_id,
            'assessment_type_id' => $this->assessment_type_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'group_id' => $this->group_id,
        ];
    }

    protected function normalizeFilters(): void
    {
        $this->course_id = $this->normalizeSelectValue($this->course_id);
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
    $headlineCards = [
        ['label' => __('reports.headline.active_enrollments.label'), 'value' => number_format($report['headline']['active_enrollments'])],
        ['label' => __('reports.headline.memorized_pages.label'), 'value' => number_format($report['headline']['memorized_pages'])],
        ['label' => __('reports.headline.net_points.label'), 'value' => number_format($report['headline']['net_points'])],
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

            </div>

        </div>
</section>

    <div class="reports-overview-grid grid items-stretch gap-6 xl:grid-cols-3">
        <section class="surface-panel report-panel report-panel--filters min-w-0 p-5 lg:p-6 xl:col-span-3">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto] xl:items-end">
                <div>
                    <select wire:model.live="course_id" aria-label="{{ __('reports.filters.course') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"><option value="">{{ __('reports.filters.all_courses') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select>
                </div>
                <div>
                    <select wire:model.live="group_id" aria-label="{{ __('reports.filters.group') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"><option value="">{{ __('reports.filters.all_groups') }}</option>@foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>@endforeach</select>
                </div>
                <div>
                    <input wire:model.live="date_from" type="date" aria-label="{{ __('reports.filters.date_from') }}" data-date-placeholder="{{ __('reports.filters.date_from') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                </div>
                <div>
                    <input wire:model.live="date_to" type="date" aria-label="{{ __('reports.filters.date_to') }}" data-date-placeholder="{{ __('reports.filters.date_to') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                </div>
                <button type="button" wire:click="clearFilters" class="pill-link whitespace-nowrap">
                    {{ __('reports.filters.clear') }}
                </button>
            </div>
        </section>

        <section class="report-kpi-stack grid min-w-0 gap-3 md:grid-cols-3 xl:col-span-3">
            @foreach ($headlineCards as $card)
                <article class="stat-card p-5">
                    <div class="kpi-label">{{ $card['label'] }}</div>
                    <div class="metric-value mt-3">{{ $card['value'] }}</div>
                </article>
            @endforeach
        </section>

    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="surface-panel p-5 lg:p-6">
            <div class="mb-4 flex items-center justify-between gap-4">
                <h2 class="font-display text-2xl text-white">{{ __('reports.attendance.eyebrow') }}</h2>
                <div class="report-attendance-average flex h-12 w-fit shrink-0 items-center justify-between gap-3 rounded-xl border border-white/8 bg-white/4 px-4 text-center">
                    <div class="kpi-label">{{ __('reports.attendance.average_present') }}</div>
                    <div class="text-xl font-semibold text-white">{{ number_format($report['attendance']['average_present_per_day']) }}</div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach ($report['attendance']['breakdown'] as $status)
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-4">
                        <div class="kpi-label">{{ $status['name'] }}</div>
                        <div class="mt-3 text-2xl font-semibold text-white">{{ number_format($status['count']) }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="surface-panel p-5 lg:p-6">
            <div class="mb-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_16rem] sm:items-center">
                <div>
                    <h2 class="font-display text-2xl text-white">{{ __('reports.assessments.eyebrow') }}</h2>
                </div>

                <div>
                    <select wire:model.live="assessment_type_id" aria-label="{{ __('reports.filters.assessment_type') }}" class="h-12 min-h-12 w-full box-border rounded-xl px-3 text-sm">
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
                <h2 class="font-display text-2xl text-white">{{ __('reports.leaderboard.points_title') }}</h2>
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
                <h2 class="font-display text-2xl text-white">{{ __('reports.leaderboard.memorization_title') }}</h2>
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

    <div class="grid gap-3 lg:grid-cols-2">
        <a href="{{ route('reports.student-activity-summary') }}" class="surface-panel report-panel report-nav-card flex min-w-0 items-center justify-between gap-4 p-4">
            <h2 class="font-display text-xl text-white">{{ __('reports.navigation.student_activity_title') }}</h2>
            <span class="pill-link pill-link--compact report-nav-card__cta inline-flex shrink-0">{{ __('reports.navigation.open') }}</span>
        </a>

        <a href="{{ route('reports.rankings') }}" class="surface-panel report-panel report-nav-card flex min-w-0 items-center justify-between gap-4 p-4">
            <h2 class="font-display text-xl text-white">{{ __('reports.rankings.combined_title') }}</h2>
            <span class="pill-link pill-link--compact report-nav-card__cta inline-flex shrink-0">{{ __('reports.navigation.open') }}</span>
        </a>
    </div>

    <section class="surface-panel report-panel report-panel--exports min-w-0 p-6">
        <h2 class="font-display text-2xl text-white">{{ __('reports.exports.title') }}</h2>

        <div class="report-export-list mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <a href="{{ route('reports.exports.attendance', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link pill-link--accent report-export-link">{{ __('reports.exports.attendance') }}</a>
            <a href="{{ route('reports.exports.memorization', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.memorization') }}</a>
            <a href="{{ route('reports.exports.points', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.points') }}</a>
            <a href="{{ route('reports.exports.student-activity-summary', ['course_id' => $course_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.student_activity.export') }}</a>
            <a href="{{ route('reports.exports.assessments', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.assessments') }}</a>
        </div>
    </section>
</div>

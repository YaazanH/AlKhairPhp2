<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Group;
use App\Services\ReportingService;
use App\Services\Landlord\CurrentModuleAccess;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public mixed $course_id = null;
    public mixed $assessment_type_id = null;
    public mixed $group_id = null;
    public string $date_from = '';
    public string $date_to = '';
    public bool $classesEnabled = true;
    public bool $studentAttendanceEnabled = true;

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
        $this->classesEnabled = app(CurrentModuleAccess::class)->enabled('classes');
        $this->studentAttendanceEnabled = app(CurrentModuleAccess::class)->enabled('student_attendance');
        $this->course_id = $this->classesEnabled ? Course::query()->where('is_default', true)->where('is_active', true)->value('id') : null;
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
        $this->course_id = $this->classesEnabled ? Course::query()->where('is_default', true)->where('is_active', true)->value('id') : null;
        $this->assessment_type_id = null;
        $this->group_id = null;
        $this->date_from = '';
        $this->date_to = '';
    }

    public function with(): array
    {
        $this->normalizeFilters();

        return [
            'courses' => $this->classesEnabled ? Course::query()->visibleInReportFilters()->orderByDesc('is_active')->orderByDesc('is_default')->orderByDesc('starts_on')->orderBy('name')->get(['id', 'name']) : collect(),
            'assessmentTypes' => AssessmentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'groups' => $this->classesEnabled ? $this->scopeGroupsQuery(
                Group::query()
                    ->with(['course', 'academicYear'])
                    ->visibleInReportFilters()
                    ->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))
                    ->orderByDesc('is_active')
                    ->orderBy('name')
            )->get() : collect(),
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
    $headlineCards = array_values(array_filter([
        $classesEnabled ? ['label' => __('reports.headline.active_enrollments.label'), 'value' => number_format($report['headline']['active_enrollments'])] : null,
        ['label' => __('reports.headline.memorized_pages.label'), 'value' => number_format($report['headline']['memorized_pages'])],
        ['label' => __('reports.headline.net_points.label'), 'value' => number_format($report['headline']['net_points'])],
    ]));
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)] xl:items-start">
            <div>
                <div class="eyebrow">{{ __('reports.hero.eyebrow') }}</div>
                <div class="flex items-center justify-between gap-4">
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('reports.hero.title') }}</h1>
                    <button type="button" class="admin-icon-button reports-mobile-filter-trigger" data-mobile-table-filter-open data-mobile-table-filter-target="reports-overview-filters" aria-controls="reports-overview-filters" aria-expanded="false" title="{{ __('crud.common.filters.search') }}" aria-label="{{ __('crud.common.filters.search') }}">
                        <x-admin-action-icon name="search" />
                    </button>
                </div>

            </div>

        </div>
</section>

    <div class="reports-overview-grid grid items-stretch gap-6 xl:grid-cols-3">
        <section class="surface-panel report-panel report-panel--filters min-w-0 p-5 lg:p-6 xl:col-span-3">
            <div id="reports-overview-filters" data-mobile-table-filter-controls class="date-control-peer-group report-filter-grid grid gap-4 md:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto] xl:items-end">
                @if($classesEnabled)<div class="admin-filter-field min-w-0">
                    <select wire:model.live="course_id" aria-label="{{ __('reports.filters.course') }}" data-record-label="course"><option value="">{{ __('reports.filters.all_courses') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select>
                </div>
                <div class="admin-filter-field min-w-0">
                    <select wire:model.live="group_id" aria-label="{{ __('reports.filters.group') }}"><option value="">{{ __('reports.filters.all_groups') }}</option>@foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select>
                </div>@endif
                <div class="admin-filter-field min-w-0">
                    <input wire:model.live="date_from" type="date" aria-label="{{ __('reports.filters.date_from') }}" data-date-placeholder="{{ __('reports.filters.date_from') }}" class="date-control--match-select">
                </div>
                <div class="admin-filter-field min-w-0">
                    <input wire:model.live="date_to" type="date" aria-label="{{ __('reports.filters.date_to') }}" data-date-placeholder="{{ __('reports.filters.date_to') }}" class="date-control--match-select">
                </div>
                <div class="admin-filter-field min-w-0">
                    <x-clear-filter-button wire:click="clearFilters" :label="__('reports.filters.clear')" />
                </div>
            </div>
        </section>

        <section class="report-kpi-stack mobile-compact-highlights grid min-w-0 gap-3 md:grid-cols-3 xl:col-span-3">
            @foreach ($headlineCards as $card)
                <article class="stat-card p-5">
                    <div class="kpi-label">{{ $card['label'] }}</div>
                    <div class="metric-value mt-3">{{ $card['value'] }}</div>
                </article>
            @endforeach
        </section>

    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        @if($studentAttendanceEnabled)<section class="surface-panel p-5 lg:p-6">
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
        </section>@endif

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
        <section class="surface-table report-leaderboard-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <h2 class="font-display text-2xl text-white">{{ __('reports.leaderboard.points_title') }}</h2>
            </div>

            @if (empty($report['points_leaderboard']))
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.leaderboard.points_empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table-content text-sm">
                        <thead>
                            <tr>
                                <th data-table-number-column scope="col" class="w-12 whitespace-nowrap px-3 py-4 text-center">#</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.student') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.net_points') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.transactions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($report['points_leaderboard'] as $row)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-4 text-center text-neutral-300" data-row-number>{{ $loop->iteration }}</td>
                                    <td class="record-person-name px-5 py-4 lg:px-6">{{ $row['student_name'] ?: __('reports.leaderboard.unknown_student') }}</td>
                                    <td class="px-5 py-4 text-white lg:px-6">{{ number_format($row['net_points']) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($row['transactions']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="surface-table report-leaderboard-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                <h2 class="font-display text-2xl text-white">{{ __('reports.leaderboard.memorization_title') }}</h2>
            </div>

            @if (empty($report['memorization_leaderboard']))
                <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.leaderboard.memorization_empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table-content text-sm">
                        <thead>
                            <tr>
                                <th data-table-number-column scope="col" class="w-12 whitespace-nowrap px-3 py-4 text-center">#</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.student') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.pages') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('reports.leaderboard.headers.sessions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($report['memorization_leaderboard'] as $row)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-4 text-center text-neutral-300" data-row-number>{{ $loop->iteration }}</td>
                                    <td class="record-person-name px-5 py-4 lg:px-6">{{ $row['student_name'] ?: __('reports.leaderboard.unknown_student') }}</td>
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
        @can('report-designer.view')
            <a href="{{ route('reports.designer') }}" class="surface-panel report-panel report-nav-card flex min-w-0 items-center justify-between gap-4 p-4 lg:col-span-2">
                <div>
                    <div class="eyebrow">{{ __('report_designer.eyebrow') }}</div>
                    <h2 class="font-display mt-2 text-xl text-white">{{ __('report_designer.title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('report_designer.subtitle') }}</p>
                </div>
                <span class="admin-icon-button report-nav-card__cta shrink-0" title="{{ __('reports.navigation.open') }}" aria-hidden="true" data-report-nav-open-icon>
                    <x-admin-action-icon name="open" />
                </span>
            </a>
        @endcan

        <a href="{{ route('reports.student-activity-summary') }}" class="surface-panel report-panel report-nav-card flex min-w-0 items-center justify-between gap-4 p-4">
            <h2 class="font-display text-xl text-white">{{ __('reports.navigation.student_activity_title') }}</h2>
            <span class="admin-icon-button report-nav-card__cta shrink-0" title="{{ __('reports.navigation.open') }}" aria-hidden="true" data-report-nav-open-icon>
                <x-admin-action-icon name="open" />
            </span>
        </a>

        <a href="{{ route('reports.rankings') }}" class="surface-panel report-panel report-nav-card flex min-w-0 items-center justify-between gap-4 p-4">
            <h2 class="font-display text-xl text-white">{{ __('reports.rankings.combined_title') }}</h2>
            <span class="admin-icon-button report-nav-card__cta shrink-0" title="{{ __('reports.navigation.open') }}" aria-hidden="true" data-report-nav-open-icon>
                <x-admin-action-icon name="open" />
            </span>
        </a>
    </div>

    <section class="surface-panel report-panel report-panel--exports min-w-0 p-6">
        <h2 class="font-display text-2xl text-white">{{ __('reports.exports.title') }}</h2>

        <div class="report-export-list mt-4">
            <a href="{{ route('reports.exports.attendance', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.attendance') }}</a>
            <a href="{{ route('reports.exports.memorization', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.memorization') }}</a>
            <a href="{{ route('reports.exports.points', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.points') }}</a>
            <a href="{{ route('reports.exports.student-activity-summary', ['course_id' => $course_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.student_activity.export') }}</a>
            <a href="{{ route('reports.exports.assessments', ['course_id' => $course_id, 'group_id' => $group_id, 'assessment_type_id' => $assessment_type_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link report-export-link">{{ __('reports.exports.assessments') }}</a>
        </div>
    </section>
</div>

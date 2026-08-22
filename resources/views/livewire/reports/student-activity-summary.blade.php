<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Course;
use App\Models\Group;
use App\Services\ReportingService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public mixed $course_id = null;

    public mixed $group_id = null;

    public string $date_from = '';

    public string $date_to = '';

    public string $sortField = 'student_name';

    public string $sortDirection = 'asc';

    protected array $sortableFields = [
        'group',
        'attended_days',
        'memorized_pages',
        'passed_final_tests',
        'points',
        'student_name',
    ];

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
    }

    public function updatedCourseId(): void
    {
        $this->resetPage();
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

    public function updatedGroupId(): void
    {
        $this->resetPage();
        $this->normalizeFilters();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->group_id = null;
        $this->date_from = '';
        $this->date_to = '';
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
            $this->resetPage();

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = $field === 'student_name' || $field === 'group' ? 'asc' : 'desc';
        $this->resetPage();
    }

    public function with(): array
    {
        $this->normalizeFilters();

        $reporting = app(ReportingService::class);
        $allRows = $this->sortedRows($reporting->studentActivitySummary($this->filters()));
        $page = max(1, $this->getPage());
        $rows = new LengthAwarePaginator(
            collect($allRows)->forPage($page, 15)->values(),
            count($allRows),
            15,
            $page,
            ['pageName' => 'page']
        );

        return [
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'groups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with(['course', 'academicYear'])
                    ->where('is_active', true)
                    ->whereHas('course', fn ($query) => $query->where('is_active', true))
                    ->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))
                    ->orderBy('name')
            )->get(),
            'rows' => $rows,
            'totals' => [
                'average_attendance' => $reporting->studentActivityAverageAttendance($this->filters()),
                'memorized_pages' => collect($allRows)->sum('memorized_pages'),
                'passed_final_tests' => collect($allRows)->sum('passed_final_tests'),
                'points' => collect($allRows)->sum('points'),
                'students' => count($allRows),
            ],
        ];
    }

    protected function filters(): array
    {
        $this->normalizeFilters();

        return [
            'course_id' => $this->course_id,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'group_id' => $this->group_id,
        ];
    }

    protected function sortedRows(array $rows): array
    {
        $field = in_array($this->sortField, $this->sortableFields, true)
            ? $this->sortField
            : 'student_name';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return collect($rows)
            ->sort(function (array $left, array $right) use ($field, $direction): int {
                $comparison = match ($field) {
                    'attended_days', 'memorized_pages', 'passed_final_tests', 'points' => ($left[$field] ?? 0) <=> ($right[$field] ?? 0),
                    default => strnatcasecmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? '')),
                };

                if ($comparison === 0) {
                    $comparison = strnatcasecmp((string) ($left['student_name'] ?? ''), (string) ($right['student_name'] ?? ''));
                }

                return $direction === 'desc' ? -$comparison : $comparison;
            })
            ->values()
            ->all();
    }

    protected function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    protected function normalizeFilters(): void
    {
        $this->course_id = $this->normalizeSelectValue($this->course_id);
        $this->group_id = $this->normalizeSelectValue($this->group_id);

        if ($this->date_from !== '' && $this->date_to !== '' && $this->date_from > $this->date_to) {
            [$this->date_from, $this->date_to] = [$this->date_to, $this->date_from];
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

}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_16rem] xl:items-end">
            <div>
                <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('reports.student_activity.title') }}</h1>
            </div>

            <div class="flex xl:justify-end">
                <a href="{{ route('reports.index') }}" class="pill-link">{{ __('reports.rankings.filters.back_to_reports') }}</a>
            </div>
        </div>
    </section>

    <div class="grid gap-6">
        <section class="order-2 surface-panel report-panel min-w-0 p-5 lg:p-6">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(9rem,1fr)_minmax(9rem,1fr)_minmax(8rem,.8fr)_minmax(8rem,.8fr)_auto_auto] xl:items-center">
                <div>
                    <select wire:model.live="course_id" aria-label="{{ __('reports.filters.course') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_courses') }}</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <select wire:model.live="group_id" aria-label="{{ __('reports.filters.group') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                        <option value="">{{ __('reports.filters.all_groups') }}</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <input wire:model.live="date_from" type="date" aria-label="{{ __('reports.filters.date_from') }}" data-date-placeholder="{{ __('reports.filters.date_from') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                </div>

                <div>
                    <input wire:model.live="date_to" type="date" aria-label="{{ __('reports.filters.date_to') }}" data-date-placeholder="{{ __('reports.filters.date_to') }}" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                </div>
                <button type="button" wire:click="clearFilters" class="pill-link h-[3.125rem] justify-center whitespace-nowrap">
                    {{ __('reports.filters.clear') }}
                </button>
                <a href="{{ route('reports.exports.student-activity-summary', ['course_id' => $course_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link pill-link--accent h-[3.125rem] items-center justify-center whitespace-nowrap">
                    {{ __('reports.student_activity.export') }}
                </a>
            </div>
        </section>

        <div class="contents">
            <section class="order-1 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <article class="surface-panel report-kpi-card p-5">
                    <div class="kpi-label report-kpi-label">{{ __('reports.student_activity.students') }}</div>
                    <div class="metric-value report-kpi-value mt-5">{{ number_format($totals['students']) }}</div>
                </article>
                <article class="surface-panel report-kpi-card p-5">
                    <div class="kpi-label report-kpi-label">{{ __('reports.student_activity.memorized_pages') }}</div>
                    <div class="metric-value report-kpi-value mt-5">{{ number_format($totals['memorized_pages']) }}</div>
                </article>
                <article class="surface-panel report-kpi-card p-5">
                    <div class="kpi-label report-kpi-label">{{ __('reports.student_activity.passed_final_tests') }}</div>
                    <div class="metric-value report-kpi-value mt-5">{{ number_format($totals['passed_final_tests']) }}</div>
                </article>
                <article class="surface-panel report-kpi-card p-5">
                    <div class="kpi-label report-kpi-label">{{ __('reports.student_activity.average_attendance') }}</div>
                    <div class="metric-value report-kpi-value mt-5">{{ number_format($totals['average_attendance'], 1) }}</div>
                </article>
                <article class="surface-panel report-kpi-card p-5">
                    <div class="kpi-label report-kpi-label">{{ __('reports.student_activity.points') }}</div>
                    <div class="metric-value report-kpi-value mt-5">{{ number_format($totals['points']) }}</div>
                </article>
            </section>

            <section class="order-3 surface-table">
                <div class="soft-keyline border-b px-5 py-5 lg:px-6">
                    <h2 class="font-display text-2xl text-white">{{ __('reports.student_activity.table_title') }}</h2>
                </div>

                @if ($rows->isEmpty())
                    <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.student_activity.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full table-fixed text-sm">
                            <thead>
                                <tr>
                                    <th class="w-1/6 px-5 py-4 text-left lg:px-6">
                                        <button type="button" wire:click="sortBy('student_name')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                            <span>{{ __('reports.student_activity.headers.student') }}</span>
                                            @if ($sortIndicator = $this->sortIndicator('student_name'))
                                                <span aria-hidden="true">{{ $sortIndicator }}</span>
                                            @endif
                                        </button>
                                    </th>
                                    <th class="w-1/6 px-5 py-4 text-left lg:px-6">
                                        <button type="button" wire:click="sortBy('memorized_pages')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                            <span>{{ __('reports.student_activity.headers.memorized_pages') }}</span>
                                            @if ($sortIndicator = $this->sortIndicator('memorized_pages'))
                                                <span aria-hidden="true">{{ $sortIndicator }}</span>
                                            @endif
                                        </button>
                                    </th>
                                    <th class="w-1/6 px-5 py-4 text-left lg:px-6">
                                        <button type="button" wire:click="sortBy('passed_final_tests')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                            <span>{{ __('reports.student_activity.headers.passed_final_tests') }}</span>
                                            @if ($sortIndicator = $this->sortIndicator('passed_final_tests'))
                                                <span aria-hidden="true">{{ $sortIndicator }}</span>
                                            @endif
                                        </button>
                                    </th>
                                    <th class="w-1/6 px-5 py-4 text-left lg:px-6">
                                        <button type="button" wire:click="sortBy('attended_days')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                            <span>{{ __('reports.student_activity.headers.attended_days') }}</span>
                                            @if ($sortIndicator = $this->sortIndicator('attended_days'))
                                                <span aria-hidden="true">{{ $sortIndicator }}</span>
                                            @endif
                                        </button>
                                    </th>
                                    <th class="w-1/6 px-5 py-4 text-left lg:px-6">
                                        <button type="button" wire:click="sortBy('points')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                            <span>{{ __('reports.student_activity.headers.points') }}</span>
                                            @if ($sortIndicator = $this->sortIndicator('points'))
                                                <span aria-hidden="true">{{ $sortIndicator }}</span>
                                            @endif
                                        </button>
                                    </th>
                                    <th class="w-1/6 px-5 py-4 text-left lg:px-6">
                                        <button type="button" wire:click="sortBy('group')" class="inline-flex items-center gap-2 font-medium text-inherit">
                                            <span>{{ __('reports.student_activity.headers.group') }}</span>
                                            @if ($sortIndicator = $this->sortIndicator('group'))
                                                <span aria-hidden="true">{{ $sortIndicator }}</span>
                                            @endif
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="px-5 py-4 font-medium text-white lg:px-6">{{ $row['student_name'] ?: __('reports.leaderboard.unknown_student') }}</td>
                                        <td class="px-5 py-4 text-neutral-200 lg:px-6">{{ number_format($row['memorized_pages']) }}</td>
                                        <td class="px-5 py-4 text-neutral-200 lg:px-6">{{ number_format($row['passed_final_tests']) }}</td>
                                        <td class="px-5 py-4 text-neutral-200 lg:px-6">{{ number_format($row['attended_days']) }}</td>
                                        <td class="px-5 py-4 text-neutral-200 lg:px-6">{{ number_format($row['points']) }}</td>
                                        <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['group'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($rows->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $rows->links() }}</div>@endif
                @endif
            </section>
        </div>
    </div>
</div>

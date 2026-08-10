<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Course;
use App\Models\Group;
use App\Services\ReportingService;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public mixed $course_id = null;
    public mixed $group_id = null;
    public string $date_from = '';
    public string $date_to = '';
    public string $sortField = 'student_name';
    public string $sortDirection = 'asc';

    protected array $sortableFields = ['student_name', 'partial_tests', 'final_tests', 'group'];

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
    }

    public function updatedCourseId(): void
    {
        $this->normalizeFilters();

        if ($this->group_id && ! $this->scopeGroupsQuery(Group::query())
            ->whereKey($this->group_id)
            ->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))
            ->exists()) {
            $this->group_id = null;
        }
    }

    public function clearFilters(): void
    {
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->group_id = null;
        $this->date_from = '';
        $this->date_to = '';
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableFields, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = in_array($field, ['student_name', 'group'], true) ? 'asc' : 'desc';
    }

    public function with(): array
    {
        $this->normalizeFilters();
        $rows = $this->sortedRows(app(ReportingService::class)->studentQuranTestSummary($this->filters()));

        return [
            'courses' => Course::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'groups' => $this->scopeGroupsQuery(
                Group::query()
                    ->with('course')
                    ->where('is_active', true)
                    ->whereHas('course', fn ($query) => $query->where('is_active', true))
                    ->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))
                    ->orderBy('name')
            )->get(),
            'rows' => $rows,
            'totals' => [
                'students' => count($rows),
                'partial_tests' => collect($rows)->sum('partial_tests'),
                'final_tests' => collect($rows)->sum('final_tests'),
            ],
        ];
    }

    protected function filters(): array
    {
        return ['course_id' => $this->course_id, 'group_id' => $this->group_id, 'date_from' => $this->date_from, 'date_to' => $this->date_to];
    }

    protected function sortedRows(array $rows): array
    {
        $field = in_array($this->sortField, $this->sortableFields, true) ? $this->sortField : 'student_name';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return collect($rows)->sort(function (array $left, array $right) use ($direction, $field): int {
            $comparison = in_array($field, ['partial_tests', 'final_tests'], true)
                ? ($left[$field] ?? 0) <=> ($right[$field] ?? 0)
                : strnatcasecmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''));

            if ($comparison === 0) {
                $comparison = strnatcasecmp((string) ($left['student_name'] ?? ''), (string) ($right['student_name'] ?? ''));
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        })->values()->all();
    }

    protected function sortIndicator(string $field): string
    {
        return $this->sortField !== $field ? '' : ($this->sortDirection === 'asc' ? '↑' : '↓');
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
            $value = collect($value)->filter(fn ($item) => $item !== null && $item !== '')->first();
        }

        return $value === null || $value === '' ? null : (int) $value;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_16rem] xl:items-end">
            <div>
                <div class="eyebrow">{{ __('reports.quran_tests.eyebrow') }}</div>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('reports.quran_tests.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('reports.quran_tests.subtitle') }}</p>
            </div>
            <div class="flex xl:justify-end"><a href="{{ route('reports.index') }}" class="pill-link">{{ __('reports.rankings.filters.back_to_reports') }}</a></div>
        </div>
    </section>

    <div class="grid gap-6">
        <section class="order-2 surface-panel report-panel min-w-0 p-5 lg:p-6">
            <div class="mb-5">
                <div class="eyebrow">{{ __('reports.filters.eyebrow') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.quran_tests.filters_title') }}</h2>
                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('reports.quran_tests.filters_subtitle') }}</p>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div><label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.course') }}</label><select wire:model.live="course_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"><option value="">{{ __('reports.filters.all_courses') }}</option>@foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></div>
                <div><label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.group') }}</label><select wire:model.live="group_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"><option value="">{{ __('reports.filters.all_groups') }}</option>@foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}{{ $group->course ? ' | '.$group->course->name : '' }}</option>@endforeach</select></div>
                <div><label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_from') }}</label><input wire:model.live="date_from" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"></div>
                <div><label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.date_to') }}</label><input wire:model.live="date_to" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"></div>
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-3">
                <a href="{{ route('reports.exports.student-quran-tests', ['course_id' => $course_id, 'group_id' => $group_id, 'date_from' => $date_from, 'date_to' => $date_to]) }}" class="pill-link pill-link--accent justify-center">{{ __('reports.quran_tests.export') }}</a>
                <button type="button" wire:click="clearFilters" class="pill-link justify-center">{{ __('reports.filters.clear') }}</button>
            </div>
        </section>

        <div class="contents">
            <section class="order-1 grid gap-4 md:grid-cols-3">
                @foreach (['students', 'partial_tests', 'final_tests'] as $metric)
                    <article class="surface-panel report-kpi-card p-5"><div class="kpi-label report-kpi-label">{{ __('reports.quran_tests.'.$metric) }}</div><div class="metric-value report-kpi-value mt-5">{{ number_format($totals[$metric]) }}</div></article>
                @endforeach
            </section>

            <section class="order-3 surface-table">
                <div class="soft-keyline border-b px-5 py-5 lg:px-6"><div class="eyebrow">{{ __('reports.quran_tests.table_eyebrow') }}</div><h2 class="font-display mt-3 text-2xl text-white">{{ __('reports.quran_tests.table_title') }}</h2></div>
                @if (empty($rows))
                    <div class="px-6 py-14 text-sm leading-7 text-neutral-400">{{ __('reports.quran_tests.empty') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="text-sm">
                            <thead><tr>
                                @foreach (['student_name' => 'student', 'partial_tests' => 'partial_tests', 'final_tests' => 'final_tests', 'group' => 'group'] as $field => $label)
                                    <th class="px-5 py-4 text-left lg:px-6"><button type="button" wire:click="sortBy('{{ $field }}')" class="inline-flex items-center gap-2 font-medium text-inherit"><span>{{ __('reports.quran_tests.headers.'.$label) }}</span>@if ($indicator = $this->sortIndicator($field))<span aria-hidden="true">{{ $indicator }}</span>@endif</button></th>
                                @endforeach
                            </tr></thead>
                            <tbody class="divide-y divide-white/6">
                                @foreach ($rows as $row)
                                    <tr><td class="px-5 py-4 font-medium text-white lg:px-6">{{ $row['student_name'] ?: __('reports.leaderboard.unknown_student') }}</td><td class="px-5 py-4 text-neutral-200 lg:px-6">{{ number_format($row['partial_tests']) }}</td><td class="px-5 py-4 text-neutral-200 lg:px-6">{{ number_format($row['final_tests']) }}</td><td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $row['group'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>

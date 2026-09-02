<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Course;
use App\Models\Group;
use App\Services\MemorizationRankingService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithPagination;

    public mixed $course_id = null;
    public mixed $group_id = null;
    public string $first_date_from = '';
    public string $first_date_to = '';
    public string $second_date_from = '';
    public string $second_date_to = '';

    public function mount(): void
    {
        $this->authorizePermission('reports.view');
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->setDefaultRanges();
    }

    public function updatedCourseId(): void
    {
        $this->resetPage('studentsPage');
        $this->normalizeFilters();
        if ($this->group_id && ! Group::query()->whereKey($this->group_id)->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))->exists()) {
            $this->group_id = null;
        }
    }

    public function updatedGroupId(): void
    {
        $this->resetPage('studentsPage');
        $this->normalizeFilters();
    }

    public function updatedFirstDateFrom(): void { $this->resetPage('studentsPage'); }
    public function updatedFirstDateTo(): void { $this->resetPage('studentsPage'); }
    public function updatedSecondDateFrom(): void { $this->resetPage('studentsPage'); }
    public function updatedSecondDateTo(): void { $this->resetPage('studentsPage'); }

    public function clearFilters(): void
    {
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->group_id = null;
        $this->setDefaultRanges();
        $this->resetPage('studentsPage');
    }

    public function with(): array
    {
        $filters = $this->filters();
        $service = app(MemorizationRankingService::class);
        $studentComparison = $service->compareStudents($filters);
        $studentRows = collect($studentComparison['rows']);
        $studentPage = max(1, $this->getPage('studentsPage'));
        $studentComparison['rows'] = new LengthAwarePaginator(
            $studentRows->forPage($studentPage, 15)->values(),
            $studentRows->count(),
            15,
            $studentPage,
            ['pageName' => 'studentsPage', 'path' => request()->url()]
        );

        return [
            'courses' => Course::query()->orderByDesc('is_active')->orderByDesc('is_default')->orderByDesc('starts_on')->orderBy('name')->get(['id', 'name']),
            'groups' => $this->scopeGroupsQuery(Group::query()->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))->orderByDesc('is_active')->orderBy('name'))->get(['id', 'name', 'course_id']),
            'groupComparison' => $service->compareGroups($filters),
            'studentComparison' => $studentComparison,
        ];
    }

    protected function filters(): array
    {
        $this->normalizeFilters();

        return [
            'academic_year_id' => null,
            'course_id' => $this->course_id,
            'group_id' => $this->group_id,
            'first_date_from' => $this->first_date_from,
            'first_date_to' => $this->first_date_to,
            'second_date_from' => $this->second_date_from,
            'second_date_to' => $this->second_date_to,
        ];
    }

    protected function normalizeFilters(): void
    {
        $this->course_id = filled($this->course_id) ? (int) $this->course_id : null;
        $this->group_id = filled($this->group_id) ? (int) $this->group_id : null;
        if ($this->first_date_from > $this->first_date_to) {
            [$this->first_date_from, $this->first_date_to] = [$this->first_date_to, $this->first_date_from];
        }
        if ($this->second_date_from > $this->second_date_to) {
            [$this->second_date_from, $this->second_date_to] = [$this->second_date_to, $this->second_date_from];
        }
    }

    protected function setDefaultRanges(): void
    {
        $secondTo = now()->startOfDay();
        $secondFrom = $secondTo->copy()->subDays(29);
        $firstTo = $secondFrom->copy()->subDay();
        $firstFrom = $firstTo->copy()->subDays(29);
        $this->first_date_from = $firstFrom->toDateString();
        $this->first_date_to = $firstTo->toDateString();
        $this->second_date_from = $secondFrom->toDateString();
        $this->second_date_to = $secondTo->toDateString();
    }
}; ?>

@php
    $movementMeta = [
        'up' => ['class' => 'ranking-movement-badge--up', 'icon' => '▲'],
        'down' => ['class' => 'ranking-movement-badge--down', 'icon' => '▼'],
        'same' => ['class' => 'ranking-movement-badge--same', 'icon' => '—'],
        'new' => ['class' => 'ranking-movement-badge--new', 'icon' => '✦'],
        'dropped' => ['class' => 'ranking-movement-badge--dropped', 'icon' => '•'],
    ];
@endphp

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('reports.rankings.combined_title') }}</h1>
    </section>

    <section class="surface-panel report-filter-grid p-5 lg:p-6">
        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center">
            <div class="admin-filter-field min-w-0">
                <select wire:model.live="course_id" aria-label="{{ __('reports.filters.course') }}">
                    <option value="">{{ __('reports.filters.all_courses') }}</option>
                    @foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach
                </select>
            </div>
            <div class="admin-filter-field min-w-0">
                <select wire:model.live="group_id" aria-label="{{ __('reports.filters.group') }}">
                    <option value="">{{ __('reports.filters.all_groups') }}</option>
                    @foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                </select>
            </div>
            <x-clear-filter-button wire:click="clearFilters" :label="__('reports.filters.clear')" />
        </div>
        <div class="mt-3 grid gap-3 xl:grid-cols-2">
            <div class="admin-filter-field grid min-w-0 gap-2 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
                <span class="whitespace-nowrap text-sm font-medium text-neutral-300">{{ __('reports.rankings.filters.first_range') }}</span>
                <div class="grid min-w-0 grid-cols-2 gap-2">
                    <input wire:model.live="first_date_from" type="date" aria-label="{{ __('reports.filters.date_from') }}" data-date-placeholder="{{ __('reports.filters.date_from') }}">
                    <input wire:model.live="first_date_to" type="date" aria-label="{{ __('reports.filters.date_to') }}" data-date-placeholder="{{ __('reports.filters.date_to') }}">
                </div>
            </div>
            <div class="admin-filter-field grid min-w-0 gap-2 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
                <span class="whitespace-nowrap text-sm font-medium text-neutral-300">{{ __('reports.rankings.filters.second_range') }}</span>
                <div class="grid min-w-0 grid-cols-2 gap-2">
                    <input wire:model.live="second_date_from" type="date" aria-label="{{ __('reports.filters.date_from') }}" data-date-placeholder="{{ __('reports.filters.date_from') }}">
                    <input wire:model.live="second_date_to" type="date" aria-label="{{ __('reports.filters.date_to') }}" data-date-placeholder="{{ __('reports.filters.date_to') }}">
                </div>
            </div>
        </div>
    </section>

    @foreach ([['comparison' => $groupComparison, 'title' => __('reports.rankings.groups.table_title'), 'paginated' => false], ['comparison' => $studentComparison, 'title' => __('reports.rankings.students.table_title'), 'paginated' => true]] as $ranking)
        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6"><h2 class="font-display text-2xl text-white">{{ $ranking['title'] }}</h2></div>
            @if (count($ranking['comparison']['rows']) === 0)
                <div class="px-6 py-14 text-sm text-neutral-400">{{ __('reports.rankings.table.empty') }}</div>
            @else
                <div class="overflow-x-auto"><table class="text-sm">
                    <thead><tr>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.current_rank') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.entity') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.first_range_pages') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.second_range_pages') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.movement') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-white/6">
                    @foreach ($ranking['comparison']['rows'] as $row)
                        @php($movement = $movementMeta[$row['movement_state']])
                        <tr>
                            <td class="px-5 py-4 font-semibold text-white">{{ $row['display_rank'] ? '#'.$row['display_rank'] : '—' }}</td>
                            <td class="px-5 py-4 text-white">{{ $row['entity_name'] }}</td>
                            <td class="px-5 py-4">{{ number_format($row['first_pages']) }}</td>
                            <td class="px-5 py-4">{{ number_format($row['second_pages']) }}</td>
                            <td class="px-5 py-4">
                                <span class="ranking-movement-badge {{ $movement['class'] }}">
                                    <span aria-hidden="true">{{ $movement['icon'] }}</span>
                                    @if ($row['movement_state'] === 'up')
                                        {{ __('reports.rankings.movement.up_steps', ['count' => number_format($row['movement_steps'])]) }}
                                    @elseif ($row['movement_state'] === 'down')
                                        {{ __('reports.rankings.movement.down_steps', ['count' => number_format($row['movement_steps'])]) }}
                                    @else
                                        {{ __('reports.rankings.movement.'.$row['movement_state']) }}
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @if ($ranking['paginated'] && $ranking['comparison']['rows']->hasPages())
                    <div class="border-t border-white/8 px-5 py-4 lg:px-6">{{ $ranking['comparison']['rows']->links() }}</div>
                @endif
            @endif
        </section>
    @endforeach
</div>

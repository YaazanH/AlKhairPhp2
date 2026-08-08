<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Course;
use App\Models\Group;
use App\Services\MemorizationRankingService;
use Carbon\Carbon;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

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
        $this->normalizeFilters();
        if ($this->group_id && ! Group::query()->whereKey($this->group_id)->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))->exists()) {
            $this->group_id = null;
        }
    }

    public function clearFilters(): void
    {
        $this->course_id = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $this->group_id = null;
        $this->setDefaultRanges();
    }

    public function with(): array
    {
        $filters = $this->filters();
        $service = app(MemorizationRankingService::class);

        return [
            'courses' => Course::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'groups' => $this->scopeGroupsQuery(Group::query()->where('is_active', true)->whereHas('course', fn ($query) => $query->where('is_active', true))->when($this->course_id, fn ($query) => $query->where('course_id', $this->course_id))->orderBy('name'))->get(['id', 'name', 'course_id']),
            'groupComparison' => $service->compareGroups($filters),
            'studentComparison' => $service->compareStudents($filters),
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
    $rangeOne = Carbon::parse($first_date_from)->format('d-m-Y').' → '.Carbon::parse($first_date_to)->format('d-m-Y');
    $rangeTwo = Carbon::parse($second_date_from)->format('d-m-Y').' → '.Carbon::parse($second_date_to)->format('d-m-Y');
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
        <div class="eyebrow">{{ __('reports.navigation.eyebrow') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('reports.rankings.combined_title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('reports.rankings.combined_subtitle') }}</p>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.course') }}</label>
                <select wire:model.live="course_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                    <option value="">{{ __('reports.filters.all_courses') }}</option>
                    @foreach ($courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.filters.group') }}</label>
                <select wire:model.live="group_id" class="report-control w-full rounded-xl px-3 py-2.5 text-sm">
                    <option value="">{{ __('reports.filters.all_groups') }}</option>
                    @foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.rankings.filters.first_range') }}</label><input wire:model.live="first_date_from" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"></div>
                <div><label class="report-field-label mb-2 block text-sm font-medium">&nbsp;</label><input wire:model.live="first_date_to" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"></div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="report-field-label mb-2 block text-sm font-medium">{{ __('reports.rankings.filters.second_range') }}</label><input wire:model.live="second_date_from" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"></div>
                <div><label class="report-field-label mb-2 block text-sm font-medium">&nbsp;</label><input wire:model.live="second_date_to" type="date" class="report-control w-full rounded-xl px-3 py-2.5 text-sm"></div>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-2"><span class="badge-soft">{{ $rangeOne }}</span><span class="badge-soft badge-soft--emerald">{{ $rangeTwo }}</span></div>
            <button type="button" wire:click="clearFilters" class="pill-link">{{ __('reports.filters.clear') }}</button>
        </div>
    </section>

    @foreach ([['comparison' => $groupComparison, 'title' => __('reports.rankings.groups.table_title')], ['comparison' => $studentComparison, 'title' => __('reports.rankings.students.table_title')]] as $ranking)
        <section class="surface-table">
            <div class="soft-keyline border-b px-5 py-5 lg:px-6"><h2 class="font-display text-2xl text-white">{{ $ranking['title'] }}</h2></div>
            @if (empty($ranking['comparison']['rows']))
                <div class="px-6 py-14 text-sm text-neutral-400">{{ __('reports.rankings.table.empty') }}</div>
            @else
                <div class="overflow-x-auto"><table class="text-sm">
                    <thead><tr>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.current_rank') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.entity') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.first_range') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.second_range') }}</th>
                        <th class="px-5 py-4 text-left">{{ __('reports.rankings.table.movement') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-white/6">
                    @foreach ($ranking['comparison']['rows'] as $row)
                        @php($movement = $movementMeta[$row['movement_state']])
                        <tr>
                            <td class="px-5 py-4 font-semibold text-white">{{ $row['display_rank'] ? '#'.$row['display_rank'] : '—' }}</td>
                            <td class="px-5 py-4 text-white">{{ $row['entity_name'] }}</td>
                            <td class="px-5 py-4">{{ number_format($row['first_pages']) }} / {{ number_format($row['first_sessions']) }}</td>
                            <td class="px-5 py-4">{{ number_format($row['second_pages']) }} / {{ number_format($row['second_sessions']) }}</td>
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
            @endif
        </section>
    @endforeach
</div>

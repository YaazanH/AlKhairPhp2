<?php

namespace App\Services;

use App\Models\MemorizationSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MemorizationRankingService
{
    public function compareGroups(array $filters = [], ?User $user = null): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->buildComparison(
            $this->groupRowsForRange($filters, 'first', $user),
            $this->groupRowsForRange($filters, 'second', $user),
            $filters,
        );
    }

    public function compareStudents(array $filters = [], ?User $user = null): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->buildComparison(
            $this->studentRowsForRange($filters, 'first', $user),
            $this->studentRowsForRange($filters, 'second', $user),
            $filters,
        );
    }

    protected function buildComparison(Collection $firstRangeRows, Collection $secondRangeRows, array $filters): array
    {
        $firstRanked = $this->assignRanks($firstRangeRows);
        $secondRanked = $this->assignRanks($secondRangeRows);

        $firstById = $firstRanked->keyBy('entity_id');
        $secondById = $secondRanked->keyBy('entity_id');

        $rows = $firstById->keys()
            ->merge($secondById->keys())
            ->unique()
            ->map(function (int|string $entityId) use ($firstById, $secondById) {
                $first = $firstById->get($entityId);
                $second = $secondById->get($entityId);

                [$movementState, $movementSteps] = $this->movementFor($first, $second);

                return [
                    'display_rank' => $second['rank'] ?? $first['rank'] ?? null,
                    'entity_id' => (int) $entityId,
                    'entity_name' => $second['entity_name'] ?? $first['entity_name'] ?? '',
                    'first_pages' => $first['pages'] ?? 0,
                    'first_rank' => $first['rank'] ?? null,
                    'first_sessions' => $first['sessions'] ?? 0,
                    'movement_state' => $movementState,
                    'movement_steps' => $movementSteps,
                    'second_pages' => $second['pages'] ?? 0,
                    'second_rank' => $second['rank'] ?? null,
                    'second_sessions' => $second['sessions'] ?? 0,
                ];
            })
            ->sort(function (array $left, array $right) {
                $leftCurrentRank = $left['second_rank'] ?? PHP_INT_MAX;
                $rightCurrentRank = $right['second_rank'] ?? PHP_INT_MAX;

                if ($leftCurrentRank !== $rightCurrentRank) {
                    return $leftCurrentRank <=> $rightCurrentRank;
                }

                $leftFallbackRank = $left['first_rank'] ?? PHP_INT_MAX;
                $rightFallbackRank = $right['first_rank'] ?? PHP_INT_MAX;

                if ($leftFallbackRank !== $rightFallbackRank) {
                    return $leftFallbackRank <=> $rightFallbackRank;
                }

                return mb_strtolower($left['entity_name']) <=> mb_strtolower($right['entity_name']);
            })
            ->values();

        return [
            'first_range' => [
                'date_from' => $filters['first_date_from'],
                'date_to' => $filters['first_date_to'],
                'leader' => $firstRanked->first(),
            ],
            'second_range' => [
                'date_from' => $filters['second_date_from'],
                'date_to' => $filters['second_date_to'],
                'leader' => $secondRanked->first(),
            ],
            'rows' => $rows->all(),
            'summary' => [
                'biggest_decline' => $rows
                    ->where('movement_state', 'down')
                    ->sortByDesc('movement_steps')
                    ->first(),
                'biggest_improver' => $rows
                    ->where('movement_state', 'up')
                    ->sortByDesc('movement_steps')
                    ->first(),
                'movement_counts' => [
                    'down' => $rows->where('movement_state', 'down')->count(),
                    'dropped' => $rows->where('movement_state', 'dropped')->count(),
                    'new' => $rows->where('movement_state', 'new')->count(),
                    'same' => $rows->where('movement_state', 'same')->count(),
                    'up' => $rows->where('movement_state', 'up')->count(),
                ],
            ],
        ];
    }

    protected function groupRowsForRange(array $filters, string $range, ?User $user): Collection
    {
        return $this->baseRangeQuery($filters, $range, $user)
            ->join('enrollments', 'enrollments.id', '=', 'memorization_sessions.enrollment_id')
            ->join('groups', 'groups.id', '=', 'enrollments.group_id')
            ->selectRaw('groups.id as entity_id, groups.name as entity_name, SUM(memorization_sessions.pages_count) as total_pages, COUNT(memorization_sessions.id) as sessions_count')
            ->groupBy('groups.id', 'groups.name')
            ->orderByDesc('total_pages')
            ->orderByDesc('sessions_count')
            ->orderBy('groups.name')
            ->get()
            ->map(fn (object $row) => [
                'entity_id' => (int) $row->entity_id,
                'entity_name' => (string) $row->entity_name,
                'pages' => (int) $row->total_pages,
                'sessions' => (int) $row->sessions_count,
            ]);
    }

    protected function studentRowsForRange(array $filters, string $range, ?User $user): Collection
    {
        return $this->baseRangeQuery($filters, $range, $user)
            ->join('students', 'students.id', '=', 'memorization_sessions.student_id')
            ->selectRaw('students.id as entity_id, students.first_name, students.last_name, SUM(memorization_sessions.pages_count) as total_pages, COUNT(memorization_sessions.id) as sessions_count')
            ->groupBy('students.id', 'students.first_name', 'students.last_name')
            ->orderByDesc('total_pages')
            ->orderByDesc('sessions_count')
            ->orderBy('students.first_name')
            ->orderBy('students.last_name')
            ->get()
            ->map(fn (object $row) => [
                'entity_id' => (int) $row->entity_id,
                'entity_name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
                'pages' => (int) $row->total_pages,
                'sessions' => (int) $row->sessions_count,
            ]);
    }

    protected function baseRangeQuery(array $filters, string $range, ?User $user): Builder
    {
        $query = app(AccessScopeService::class)->scopeMemorizationSessions(MemorizationSession::query(), $user ?? auth()->user());

        $fromColumn = $range.'_date_from';
        $toColumn = $range.'_date_to';

        return $query
            ->where('memorization_sessions.entry_type', 'new')
            ->whereDate('memorization_sessions.recorded_on', '>=', $filters[$fromColumn])
            ->whereDate('memorization_sessions.recorded_on', '<=', $filters[$toColumn])
            ->when(
                $filters['academic_year_id'],
                fn (Builder $builder) => $builder->whereHas('enrollment.group', fn (Builder $groupQuery) => $groupQuery->where('academic_year_id', $filters['academic_year_id']))
            )
            ->when(
                $filters['group_id'],
                fn (Builder $builder) => $builder->whereIn('memorization_sessions.enrollment_id', function ($subQuery) use ($filters) {
                    $subQuery
                        ->from('enrollments')
                        ->select('id')
                        ->where('group_id', $filters['group_id']);
                })
            );
    }

    protected function assignRanks(Collection $rows): Collection
    {
        return $rows->values()->map(function (array $row, int $index) {
            $row['rank'] = $index + 1;

            return $row;
        });
    }

    protected function movementFor(?array $first, ?array $second): array
    {
        if ($first && $second) {
            if ($second['rank'] < $first['rank']) {
                return ['up', $first['rank'] - $second['rank']];
            }

            if ($second['rank'] > $first['rank']) {
                return ['down', $second['rank'] - $first['rank']];
            }

            return ['same', 0];
        }

        if ($second) {
            return ['new', null];
        }

        return ['dropped', null];
    }

    protected function normalizeFilters(array $filters): array
    {
        return [
            'academic_year_id' => $this->normalizeNullableInteger($filters['academic_year_id'] ?? null),
            'first_date_from' => $this->normalizeDate($filters['first_date_from'] ?? null),
            'first_date_to' => $this->normalizeDate($filters['first_date_to'] ?? null),
            'group_id' => $this->normalizeNullableInteger($filters['group_id'] ?? null),
            'second_date_from' => $this->normalizeDate($filters['second_date_from'] ?? null),
            'second_date_to' => $this->normalizeDate($filters['second_date_to'] ?? null),
        ];
    }

    protected function normalizeDate(mixed $value): string
    {
        if (is_array($value)) {
            $value = collect($value)
                ->filter(fn (mixed $item) => $item !== null && $item !== '')
                ->first();
        }

        if ($value === null || $value === '') {
            return now()->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    protected function normalizeNullableInteger(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = collect($value)
                ->filter(fn (mixed $item) => $item !== null && $item !== '')
                ->first();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

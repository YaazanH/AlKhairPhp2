<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseCalendarEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CourseCalendarService
{
    /**
     * @return array{
     *     starts_on: CarbonImmutable,
     *     ends_on: CarbonImmutable,
     *     layout: 'large'|'compact'|'dense',
     *     columns: int,
     *     row_count: int,
     *     cell_height_mm: float,
     *     pages: array<int, array<int, array<string, mixed>>>
     * }
     */
    public function build(Course $course): array
    {
        if (! $course->starts_on || ! $course->ends_on) {
            throw new InvalidArgumentException('A course calendar requires both a start date and an end date.');
        }

        $startsOn = CarbonImmutable::parse($course->starts_on)->startOfDay();
        $endsOn = CarbonImmutable::parse($course->ends_on)->startOfDay();

        if ($endsOn->lt($startsOn)) {
            throw new InvalidArgumentException('The course end date must not be before its start date.');
        }

        $scheduledDays = $course->schedules
            ->pluck('day_of_week')
            ->map(fn (mixed $day): int => (int) $day)
            ->unique()
            ->values()
            ->all();
        $calendarEntries = $this->calendarEntries($course)
            ->groupBy(fn (CourseCalendarEntry $entry): string => $entry->date->toDateString());

        $months = [];
        $month = $startsOn->startOfMonth();
        $lastMonth = $endsOn->startOfMonth();

        while ($month->lte($lastMonth)) {
            $months[] = $this->buildMonth($month, $startsOn, $endsOn, $scheduledDays, $calendarEntries);
            $month = $month->addMonth();
        }

        $visibleMonths = array_values(array_filter(
            $months,
            fn (array $calendarMonth): bool => $this->scheduledDayCount($calendarMonth) !== 1
                || $this->commentCount($calendarMonth) > 0,
        ));

        if ($visibleMonths !== []) {
            $months = $visibleMonths;
        }

        $monthCount = count($months);
        $layout = match (true) {
            $monthCount <= 3 => 'large',
            $monthCount <= 8 => 'compact',
            default => 'dense',
        };
        $columns = match (true) {
            $layout === 'large' => 1,
            $monthCount <= 12 => 2,
            default => 3,
        };

        $rowCount = (int) ceil($monthCount / $columns);
        $cellHeight = match ($layout) {
            'large' => match ($monthCount) {
                1 => 47.0,
                2 => 20.5,
                default => 11.1,
            },
            'compact' => match ($rowCount) {
                2 => 17.0,
                3 => 11.5,
                default => 7.0,
            },
            default => match (true) {
                $rowCount <= 5 => 5.6,
                $rowCount === 6 => 5.1,
                default => 4.0,
            },
        };

        $sizedMonths = [];

        foreach (array_chunk($months, $columns) as $monthRow) {
            $targetWeekCount = max(array_map(
                fn (array $calendarMonth): int => count($calendarMonth['weeks']),
                $monthRow,
            ));

            foreach ($monthRow as $calendarMonth) {
                $weekCount = max(1, count($calendarMonth['weeks']));
                $calendarMonth['cell_height_mm'] = $layout === 'large'
                    ? $cellHeight
                    : round(($cellHeight * $targetWeekCount) / $weekCount, 4);
                $sizedMonths[] = $calendarMonth;
            }
        }

        $months = $sizedMonths;

        return [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'layout' => $layout,
            'columns' => $columns,
            'row_count' => $rowCount,
            'cell_height_mm' => $cellHeight,
            'pages' => [$months],
        ];
    }

    /**
     * @param  array<int, int>  $scheduledDays
     * @param  Collection<string, Collection<int, CourseCalendarEntry>>  $calendarEntries
     * @return array{name: string, year: int, weeks: array<int, array{number: int|null, days: array<int, array<string, mixed>>}>}
     */
    private function buildMonth(
        CarbonImmutable $month,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        array $scheduledDays,
        Collection $calendarEntries,
    ): array {
        $weeks = [];
        $cursor = $month->startOfWeek(CarbonInterface::SUNDAY);
        $lastDay = $month->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);
        $courseWeekStartsOn = $startsOn->startOfWeek(CarbonInterface::SUNDAY);

        while ($cursor->lte($lastDay)) {
            $days = [];
            $weekIntersectsCourse = false;

            for ($offset = 0; $offset < 7; $offset++) {
                $date = $cursor->addDays($offset);
                $inMonth = $date->month === $month->month && $date->year === $month->year;
                $inCourse = $date->betweenIncluded($startsOn, $endsOn);
                $weekIntersectsCourse = $weekIntersectsCourse || $inCourse;
                $dateEntries = $calendarEntries->get($date->toDateString(), collect());

                $days[] = [
                    'date' => $date,
                    'in_month' => $inMonth,
                    'in_course' => $inCourse,
                    'scheduled' => $inMonth && $inCourse && in_array($date->dayOfWeek, $scheduledDays, true),
                    'is_start' => $date->isSameDay($startsOn),
                    'is_end' => $date->isSameDay($endsOn),
                    'comments' => $inMonth
                        ? $dateEntries->map(fn (CourseCalendarEntry $entry): array => [
                            'name' => $entry->name,
                            'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $entry->color)
                                ? strtolower($entry->color)
                                : '#3f8067',
                        ])->values()->all()
                        : [],
                ];
            }

            $weeks[] = [
                'number' => $weekIntersectsCourse
                    ? $courseWeekStartsOn->diffInWeeks($cursor) + 1
                    : null,
                'days' => $days,
            ];

            $cursor = $cursor->addWeek();
        }

        return [
            'name' => strtolower($month->format('F')),
            'year' => $month->year,
            'weeks' => $weeks,
        ];
    }

    /**
     * @param  array{name: string, year: int, weeks: array<int, array{number: int|null, days: array<int, array<string, mixed>>}>}  $month
     */
    private function scheduledDayCount(array $month): int
    {
        return collect($month['weeks'])
            ->pluck('days')
            ->flatten(1)
            ->where('scheduled', true)
            ->count();
    }

    /**
     * @param  array{name: string, year: int, weeks: array<int, array{number: int|null, days: array<int, array<string, mixed>>}>}  $month
     */
    private function commentCount(array $month): int
    {
        return collect($month['weeks'])
            ->pluck('days')
            ->flatten(1)
            ->sum(fn (array $day): int => count($day['comments'] ?? []));
    }

    /** @return Collection<int, CourseCalendarEntry> */
    private function calendarEntries(Course $course): Collection
    {
        if ($course->relationLoaded('calendarEntries')) {
            return $course->calendarEntries;
        }

        if (! $course->exists) {
            return collect();
        }

        return $course->calendarEntries()->orderBy('date')->orderBy('id')->get();
    }
}

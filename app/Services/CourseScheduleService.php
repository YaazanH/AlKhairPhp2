<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Group;
use App\Support\ScheduleTimeSlots;
use Illuminate\Support\Facades\DB;

class CourseScheduleService
{
    public function inherit(Group $group): void
    {
        if ($group->schedules()->exists()) {
            return;
        }

        $course = $group->course()->with('schedules')->first();

        if (! $course) {
            return;
        }

        $this->writeGroupSchedules($group, $course->schedules->all());
    }

    public function replace(Course $course, array $rows, bool $syncGroups = false): void
    {
        DB::transaction(function () use ($course, $rows, $syncGroups): void {
            $course->schedules()->delete();

            foreach ($this->normalizeRows($rows) as $row) {
                $course->schedules()->create($row);
            }

            if (! $syncGroups) {
                return;
            }

            $course->load('schedules');
            $course->groups()->each(function (Group $group) use ($course): void {
                $group->schedules()->delete();
                $this->writeGroupSchedules($group, $course->schedules->all());
            });
        });
    }

    private function writeGroupSchedules(Group $group, array $rows): void
    {
        foreach ($rows as $row) {
            $slot = is_array($row) ? $row['time_slot'] : $row->time_slot;
            $day = is_array($row) ? $row['day_of_week'] : $row->day_of_week;
            [$startsAt, $endsAt] = ScheduleTimeSlots::times($slot);

            $group->schedules()->create([
                'day_of_week' => $day,
                'time_slot' => $slot,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => true,
            ]);
        }
    }

    private function normalizeRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => filled($row['day_of_week'] ?? null) && filled($row['time_slot'] ?? null))
            ->map(fn ($row): array => [
                'day_of_week' => (int) $row['day_of_week'],
                'time_slot' => (string) $row['time_slot'],
            ])
            ->unique(fn (array $row): string => $row['day_of_week'].'|'.$row['time_slot'])
            ->values()
            ->all();
    }
}

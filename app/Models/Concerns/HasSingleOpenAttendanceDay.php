<?php

namespace App\Models\Concerns;

use App\Models\GroupAttendanceDay;
use App\Models\StudentAttendanceDay;

trait HasSingleOpenAttendanceDay
{
    public function save(array $options = [])
    {
        if (! $this->exists && $this->status === null) {
            $this->status = 'open';
        }

        if ($this->status !== 'open' || ($this->exists && ! $this->isDirty('status'))) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options) {
            // A persistent row serializes openings, even when there are no days yet.
            // The lock remains held until the outer transaction commits.
            $this->getConnection()->table('attendance_day_locks')
                ->where('attendance_table', $this->getTable())
                ->update(['attendance_table' => $this->getTable()]);

            $otherDays = $this->newQuery()->where('status', 'open')
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()));

            if ($this instanceof StudentAttendanceDay) {
                GroupAttendanceDay::query()->whereIn('student_attendance_day_id', (clone $otherDays)->select('id'))
                    ->update(['status' => 'closed']);
            }

            $otherDays->update(['status' => 'closed']);

            return parent::save($options);
        }, 3);
    }
}

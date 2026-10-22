<?php

namespace App\Console\Commands;

use App\Models\TeacherAttendanceDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCourselessAttendanceCommand extends Command
{
    protected $signature = 'attendance:prune-courseless';

    protected $description = 'Delete teacher attendance days without a course and their attendance records';

    public function handle(): int
    {
        $deleted = 0;
        TeacherAttendanceDay::query()->whereNull('course_id')->select('id')
            ->chunkById(100, function ($days) use (&$deleted): void {
                foreach ($days as $candidate) {
                    DB::transaction(function () use ($candidate, &$deleted): void {
                        // Recheck under the lock in case the course was repaired
                        // after the cleanup batch was selected.
                        $day = TeacherAttendanceDay::query()->whereKey($candidate->id)
                            ->whereNull('course_id')->lockForUpdate()->first();
                        if (! $day) {
                            return;
                        }

                        $day->records()->delete();
                        $day->delete();
                        $deleted++;
                    });
                }
            });

        $this->info("Deleted {$deleted} course-less attendance day(s).");

        return self::SUCCESS;
    }
}

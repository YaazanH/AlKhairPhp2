<?php

namespace App\Console\Commands;

use App\Models\Landlord\Tenant;
use App\Models\TeacherAttendanceDay;
use App\Services\Landlord\TenantModuleAccess;
use App\Services\Landlord\TenantScopedContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneCourselessAttendanceCommand extends Command
{
    protected $signature = 'attendance:prune-courseless {--tenant= : Limit cleanup to one operational tenant slug}';

    protected $description = 'Delete teacher attendance days without a course and their attendance records';

    public function handle(TenantScopedContext $context, TenantModuleAccess $modules): int
    {
        $query = Tenant::query()
            ->whereIn('status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE])
            ->whereNotNull('database_name')->where('database_name', '!=', '');

        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        $tenants = $query->orderBy('id')->get();
        if ($slug && $tenants->isEmpty()) {
            $this->error('Tenant not found or does not have an operational, provisioned database.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($tenants as $tenant) {
            try {
                // Course-less attendance is valid when classes are disabled.
                if (! $modules->isEnabled($tenant, 'classes') || ! $modules->isEnabled($tenant, 'teacher_attendance')) {
                    continue;
                }

                $deleted = $context->run($tenant, fn (): int => $this->pruneCurrentTenant());
                $this->info("Deleted {$deleted} course-less attendance day(s) for [{$tenant->slug}].");
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Attendance cleanup failed for [{$tenant->slug}]: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function pruneCurrentTenant(): int
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

        return $deleted;
    }
}

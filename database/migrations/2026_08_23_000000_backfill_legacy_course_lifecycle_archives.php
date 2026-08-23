<?php

use App\Models\Course;
use App\Services\CourseLifecycleService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $lifecycle = app(CourseLifecycleService::class);

        Course::query()
            ->where('is_active', false)
            ->whereNull('finished_at')
            ->orderBy('id')
            ->chunkById(50, function ($courses) use ($lifecycle): void {
                $courses->each(fn (Course $course) => $lifecycle->adoptLegacyFinishedState($course));
            });
    }

    public function down(): void
    {
        // The legacy rows did not retain enough information to safely remove
        // only the lifecycle markers added by this data repair.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'starts_on')) {
                $table->date('starts_on')->nullable()->after('description');
            }

            if (! Schema::hasColumn('courses', 'ends_on')) {
                $table->date('ends_on')->nullable()->after('starts_on');
            }
        });

        DB::table('courses')
            ->orderBy('id')
            ->get(['id', 'starts_on', 'ends_on'])
            ->each(function (object $course): void {
                $groupDates = DB::table('groups')
                    ->where('course_id', $course->id)
                    ->selectRaw('min(starts_on) as starts_on, max(ends_on) as ends_on')
                    ->first();

                if (! $groupDates || (! $groupDates->starts_on && ! $groupDates->ends_on)) {
                    return;
                }

                DB::table('courses')
                    ->where('id', $course->id)
                    ->update([
                        'starts_on' => $course->starts_on ?: $groupDates->starts_on,
                        'ends_on' => $course->ends_on ?: $groupDates->ends_on,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'ends_on')) {
                $table->dropColumn('ends_on');
            }

            if (Schema::hasColumn('courses', 'starts_on')) {
                $table->dropColumn('starts_on');
            }
        });
    }
};

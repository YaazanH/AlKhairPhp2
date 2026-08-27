<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('groups', ['course_id'])) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->index('course_id', 'groups_course_id_index');
            });
        }

        // Completed groups must not reserve their old name. The active-only
        // uniqueness rule is enforced by the group form because MySQL does not
        // support a portable partial unique index for nullable completion data.
        if (Schema::hasIndex('groups', 'groups_course_id_name_unique')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->dropUnique('groups_course_id_name_unique');
            });
        }
    }

    public function down(): void
    {
        $hasDuplicates = DB::table('groups')
            ->select('course_id', 'name')
            ->groupBy('course_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDuplicates && ! Schema::hasIndex('groups', 'groups_course_id_name_unique')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->unique(['course_id', 'name'], 'groups_course_id_name_unique');
            });
        }
    }
};

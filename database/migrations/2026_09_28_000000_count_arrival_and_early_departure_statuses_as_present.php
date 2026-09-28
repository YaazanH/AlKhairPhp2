<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('attendance_statuses')
            ->whereIn('code', ['late', 'early', 'early-leave'])
            ->where('is_present', false)
            ->update(['is_present' => true]);
    }

    public function down(): void
    {
        // Keep corrected attendance classifications when rolling back schema changes.
    }
};

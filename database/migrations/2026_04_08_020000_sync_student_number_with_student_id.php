<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('students')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $student): void {
                DB::table('students')
                    ->where('id', $student->id)
                    ->update([
                        'student_number' => (string) $student->id,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Student numbers now mirror student ids; no rollback data transform is needed.
    }
};

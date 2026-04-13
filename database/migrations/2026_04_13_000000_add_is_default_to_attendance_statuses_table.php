<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_statuses', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_present');
        });

        $defaultId = DB::table('attendance_statuses')
            ->where('code', 'present')
            ->value('id') ?? DB::table('attendance_statuses')
                ->where('is_active', true)
                ->whereIn('scope', ['student', 'both'])
                ->orderByDesc('is_present')
                ->orderBy('name')
                ->value('id');

        if ($defaultId) {
            DB::table('attendance_statuses')->update(['is_default' => false]);
            DB::table('attendance_statuses')->where('id', $defaultId)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('attendance_statuses', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};

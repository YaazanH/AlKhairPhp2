<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schools')) {
            DB::table('schools')->update(['is_active' => true]);
        }

        if (Schema::hasTable('father_jobs')) {
            DB::table('father_jobs')->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        // Schools and father jobs are permanent active reference values.
    }
};

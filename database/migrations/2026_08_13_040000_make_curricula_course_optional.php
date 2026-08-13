<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->change();
        });

        DB::table('curricula')->update(['course_id' => null]);
    }

    public function down(): void
    {
        // The removed course assignments cannot be reconstructed safely.
    }
};

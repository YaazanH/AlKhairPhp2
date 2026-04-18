<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('student_genders', 'is_default')) {
            Schema::table('student_genders', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('is_active');
            });
        }

        $defaultGenderId = DB::table('student_genders')
            ->where('is_active', true)
            ->where('code', 'male')
            ->value('id')
            ?? DB::table('student_genders')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->value('id');

        if ($defaultGenderId) {
            DB::table('student_genders')->update(['is_default' => false]);
            DB::table('student_genders')->where('id', $defaultGenderId)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_genders', 'is_default')) {
            Schema::table('student_genders', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};

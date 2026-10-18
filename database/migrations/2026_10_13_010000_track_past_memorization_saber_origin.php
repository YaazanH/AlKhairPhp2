<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quran_partial_tests', function (Blueprint $table): void {
            $table->boolean('from_external_memorization')->default(false)->after('created_by')->index();
        });

        Schema::table('quran_final_tests', function (Blueprint $table): void {
            $table->boolean('from_external_memorization')->default(false)->after('created_by')->index();
        });

        Schema::table('quran_tests', function (Blueprint $table): void {
            $table->boolean('from_external_memorization')->default(false)->after('notes')->index();
        });

        DB::table('student_external_memorized_juz')
            ->select(['student_id', 'quran_juz_id'])
            ->orderBy('id')
            ->each(function (object $row): void {
                $matches = [
                    'student_id' => $row->student_id,
                    'juz_id' => $row->quran_juz_id,
                ];

                DB::table('quran_partial_tests')->where($matches)->update(['from_external_memorization' => true]);
                DB::table('quran_final_tests')->where($matches)->update(['from_external_memorization' => true]);
                DB::table('quran_tests')->where($matches)->update(['from_external_memorization' => true]);
            });
    }

    public function down(): void
    {
        Schema::table('quran_tests', function (Blueprint $table): void {
            $table->dropColumn('from_external_memorization');
        });

        Schema::table('quran_final_tests', function (Blueprint $table): void {
            $table->dropColumn('from_external_memorization');
        });

        Schema::table('quran_partial_tests', function (Blueprint $table): void {
            $table->dropColumn('from_external_memorization');
        });
    }
};

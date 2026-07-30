<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('is_active')->index();
        });

        $defaultCourseId = DB::table('courses')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');

        if ($defaultCourseId) {
            DB::table('courses')->where('id', $defaultCourseId)->update(['is_default' => true]);
        }

        Schema::create('student_external_memorized_juz', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quran_juz_id')->constrained('quran_juzs')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'quran_juz_id'], 'student_external_juz_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_external_memorized_juz');

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['is_default']);
            $table->dropColumn('is_default');
        });
    }
};

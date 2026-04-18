<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_job_titles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->foreignId('teacher_job_title_id')->nullable()->after('job_title')->constrained('teacher_job_titles')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->after('teacher_job_title_id')->constrained('courses')->nullOnDelete();
            $table->boolean('is_helping')->default(false)->after('status');
            $table->index(['is_helping', 'status']);
        });

        $now = now();
        $titles = DB::table('teachers')
            ->whereNotNull('job_title')
            ->where('job_title', '!=', '')
            ->select('job_title')
            ->distinct()
            ->pluck('job_title')
            ->values();

        if ($titles->isEmpty()) {
            $titles = collect([
                'Lead Quran Teacher',
                'Quran Teacher',
                'Tajweed Teacher',
                'Assistant Teacher',
                'Youth Mentor',
            ]);
        }

        foreach ($titles as $index => $title) {
            DB::table('teacher_job_titles')->updateOrInsert(
                ['name' => $title],
                [
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('teacher_job_titles')
            ->pluck('id', 'name')
            ->each(function (int $jobTitleId, string $title): void {
                DB::table('teachers')
                    ->where('job_title', $title)
                    ->update(['teacher_job_title_id' => $jobTitleId]);
            });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropIndex(['is_helping', 'status']);
            $table->dropConstrainedForeignId('course_id');
            $table->dropConstrainedForeignId('teacher_job_title_id');
            $table->dropColumn('is_helping');
        });

        Schema::dropIfExists('teacher_job_titles');
    }
};

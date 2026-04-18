<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'group_id']);
            $table->index('group_id');
        });

        DB::table('assessments')
            ->whereNotNull('group_id')
            ->orderBy('id')
            ->select(['id', 'group_id', 'created_at', 'updated_at'])
            ->chunkById(100, function ($assessments): void {
                foreach ($assessments as $assessment) {
                    DB::table('assessment_groups')->insertOrIgnore([
                        'assessment_id' => $assessment->id,
                        'group_id' => $assessment->group_id,
                        'created_at' => $assessment->created_at ?? now(),
                        'updated_at' => $assessment->updated_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_groups');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('audience_scope', 20)->default('all_groups')->after('activity_date');
        });

        Schema::create('activity_group_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['activity_id', 'group_id'],'activ_group_id');
        });

        DB::table('activities')
            ->orderBy('id')
            ->get(['id', 'group_id'])
            ->each(function (object $activity): void {
                if ($activity->group_id) {
                    DB::table('activities')
                        ->where('id', $activity->id)
                        ->update(['audience_scope' => 'single_group']);

                    DB::table('activity_group_targets')->insert([
                        'activity_id' => $activity->id,
                        'group_id' => $activity->group_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_group_targets');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('audience_scope');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings') || ! $this->hasExistingQuranProgress()) {
            return;
        }

        $now = now();
        $defaults = [
            'profile' => ['quran', 'string'],
            'configured' => ['1', 'boolean'],
            'partial_test_enabled' => ['1', 'boolean'],
            'partial_test_required_for_final' => ['1', 'boolean'],
            'final_test_enabled' => ['1', 'boolean'],
            'final_test_required_for_awqaf' => ['1', 'boolean'],
            'awqaf_test_enabled' => ['1', 'boolean'],
        ];

        foreach ($defaults as $key => [$value, $type]) {
            DB::table('app_settings')->insertOrIgnore([
                'group' => 'learning_progression',
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Compatibility data must remain because existing student records depend on it.
    }

    private function hasExistingQuranProgress(): bool
    {
        foreach (['student_page_achievements', 'quran_partial_tests', 'quran_final_tests', 'quran_tests'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }
};

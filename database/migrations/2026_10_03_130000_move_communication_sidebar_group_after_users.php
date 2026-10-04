<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceLegacyDefaultOrder(100, 135);
    }

    public function down(): void
    {
        $this->replaceLegacyDefaultOrder(135, 100);
    }

    private function replaceLegacyDefaultOrder(int $from, int $to): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $setting = DB::table('app_settings')
            ->where('group', 'sidebar_navigation')
            ->where('key', 'groups')
            ->first();

        if (! $setting || blank($setting->value)) {
            return;
        }

        $groups = json_decode($setting->value, true);

        if (! is_array($groups)
            || ! isset($groups['activities'])
            || (int) ($groups['activities']['sort_order'] ?? -1) !== $from
            || trim((string) ($groups['activities']['title'] ?? '')) !== ''
            || (bool) ($groups['activities']['is_custom'] ?? false)) {
            return;
        }

        $groups['activities']['sort_order'] = $to;

        DB::table('app_settings')
            ->where('id', $setting->id)
            ->update([
                'value' => json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parents', function (Blueprint $table): void {
            $table->string('parent_number')->nullable()->after('user_id');
            $table->unique('parent_number');
        });

        $settings = DB::table('app_settings')
            ->where('group', 'general')
            ->whereIn('key', ['parent_number_prefix', 'parent_number_length'])
            ->pluck('value', 'key');

        $prefix = trim((string) ($settings['parent_number_prefix'] ?? 'P'));
        $length = is_numeric($settings['parent_number_length'] ?? null)
            ? max(0, (int) $settings['parent_number_length'])
            : 6;

        DB::table('parents')
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->each(function (object $parent) use ($prefix, $length): void {
                $number = (string) $parent->id;

                if ($length > 0) {
                    $number = str_pad($number, $length, '0', STR_PAD_LEFT);
                }

                $parentNumber = $prefix.$number;

                DB::table('parents')
                    ->where('id', $parent->id)
                    ->update(['parent_number' => $parentNumber]);

                if (! $parent->user_id) {
                    return;
                }

                $username = $parentNumber;
                $counter = 2;

                while (
                    DB::table('users')
                        ->where('id', '!=', $parent->user_id)
                        ->where('username', $username)
                        ->exists()
                ) {
                    $username = $parentNumber.$counter;
                    $counter++;
                }

                DB::table('users')
                    ->where('id', $parent->user_id)
                    ->update(['username' => $username]);
            });
    }

    public function down(): void
    {
        Schema::table('parents', function (Blueprint $table): void {
            $table->dropUnique(['parent_number']);
            $table->dropColumn('parent_number');
        });
    }
};

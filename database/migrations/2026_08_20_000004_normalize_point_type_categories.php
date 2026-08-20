<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('point_types')->whereIn('category', ['assessment', 'Assessment'])->update(['category' => 'Assessment']);
        DB::table('point_types')->whereIn('category', ['manual', 'manual_entry', 'ManualEntry'])->update(['category' => 'ManualEntry', 'allow_manual_entry' => true]);
        DB::table('point_types')->whereNotIn('category', ['Assessment', 'ManualEntry'])->update(['category' => 'Automatic', 'allow_manual_entry' => false]);
        DB::table('point_types')->update(['allow_negative' => true]);
    }

    public function down(): void
    {
        DB::table('point_types')->where('category', 'Assessment')->update(['category' => 'assessment']);
        DB::table('point_types')->where('category', 'ManualEntry')->update(['category' => 'manual']);
        DB::table('point_types')->where('category', 'Automatic')->update(['category' => 'system']);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('finance_categories', fn (Blueprint $table) => $table->string('mode', 20)->nullable()->after('type'));
        DB::table('finance_categories')->orderBy('id')->get()->each(function (object $category): void {
            $mode = match ($category->type) {
                'management' => 'invoice', 'return' => 'return',
                'revenue' => $category->is_donation ? 'donation' : 'income', default => 'count',
            };
            DB::table('finance_categories')->where('id', $category->id)->update(['mode' => $mode]);
        });
    }

    public function down(): void
    {
        Schema::table('finance_categories', fn (Blueprint $table) => $table->dropColumn('mode'));
    }
};

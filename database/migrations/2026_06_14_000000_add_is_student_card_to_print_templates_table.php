<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_templates', function (Blueprint $table) {
            $table->boolean('is_student_card')->default(false)->after('is_active');
            $table->index('is_student_card');
        });

        $templateMapSetting = DB::table('app_settings')
            ->where('group', 'general')
            ->where('key', 'student_dashboard_card_templates')
            ->value('value');

        $templateIds = collect(json_decode((string) $templateMapSetting, true) ?: [])
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($templateIds->isNotEmpty()) {
            DB::table('print_templates')
                ->whereIn('id', $templateIds->all())
                ->update(['is_student_card' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('print_templates', function (Blueprint $table) {
            $table->dropIndex(['is_student_card']);
            $table->dropColumn('is_student_card');
        });
    }
};

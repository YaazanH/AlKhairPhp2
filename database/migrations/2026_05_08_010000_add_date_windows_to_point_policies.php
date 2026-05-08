<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_policies', function (Blueprint $table) {
            $table->string('period_type', 20)->default('global')->after('priority');
            $table->date('active_from')->nullable()->after('period_type');
            $table->date('active_until')->nullable()->after('active_from');
            $table->index(['source_type', 'trigger_key', 'period_type']);
            $table->index(['active_from', 'active_until']);
        });
    }

    public function down(): void
    {
        Schema::table('point_policies', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'trigger_key', 'period_type']);
            $table->dropIndex(['active_from', 'active_until']);
            $table->dropColumn(['period_type', 'active_from', 'active_until']);
        });
    }
};

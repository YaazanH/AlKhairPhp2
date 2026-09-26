<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('system_backups', 'scope')) {
            return;
        }

        Schema::table('system_backups', function (Blueprint $table): void {
            $table->string('scope', 20)->default('full')->index();
        });
    }

    public function down(): void
    {
        Schema::table('system_backups', fn (Blueprint $table) => $table->dropColumn('scope'));
    }
};

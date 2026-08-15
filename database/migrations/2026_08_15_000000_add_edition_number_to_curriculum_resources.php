<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('curriculum_resources', function (Blueprint $table): void {
            $table->string('edition_number', 100)->nullable()->after('publisher');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_resources', function (Blueprint $table): void {
            $table->dropColumn('edition_number');
        });
    }
};

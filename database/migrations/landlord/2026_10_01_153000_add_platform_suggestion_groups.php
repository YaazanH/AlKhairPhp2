<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        Schema::create('platform_suggestion_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->timestamps();
        });

        Schema::table('platform_support_cases', function (Blueprint $table): void {
            $table->foreignId('platform_suggestion_group_id')
                ->nullable()
                ->after('type')
                ->constrained('platform_suggestion_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platform_support_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_suggestion_group_id');
        });

        Schema::dropIfExists('platform_suggestion_groups');
    }
};

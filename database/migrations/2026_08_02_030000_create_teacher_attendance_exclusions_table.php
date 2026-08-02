<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_attendance_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->unique()->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('excluded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendance_exclusions');
    }
};

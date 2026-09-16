<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_calendar_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('color', 7)->default('#3f8067');
            $table->timestamps();

            $table->index(['course_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_calendar_entries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_card_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('print_template_id')->nullable()->constrained('print_templates')->nullOnDelete();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at');
            $table->timestamps();

            $table->index(['student_id', 'printed_at']);
            $table->index(['print_template_id', 'printed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_card_prints');
    }
};

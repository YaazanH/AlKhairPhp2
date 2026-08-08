<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_card_prints', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->after('student_id')->constrained()->nullOnDelete();
            $table->index(['student_id', 'course_id', 'printed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('student_card_prints', function (Blueprint $table): void {
            $table->dropForeign(['course_id']);
            $table->dropIndex(['student_id', 'course_id', 'printed_at']);
            $table->dropColumn('course_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('barcode_actions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type', 30);
            $table->foreignId('attendance_status_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('point_type_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('points')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('barcode_scan_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->longText('raw_dump');
            $table->string('status', 30)->default('processed');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'attendance_date']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('barcode_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barcode_scan_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->string('raw_value');
            $table->string('normalized_value')->nullable();
            $table->string('token_type', 30);
            $table->foreignId('barcode_action_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result', 30);
            $table->text('message')->nullable();
            $table->string('applied_model_type')->nullable();
            $table->unsignedBigInteger('applied_model_id')->nullable();
            $table->timestamps();

            $table->index(['barcode_scan_import_id', 'sequence_no'], 'bar_even_imp_seq_index');
            $table->index(['applied_model_type', 'applied_model_id'], 'bar_even_app_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('barcode_scan_events');
        Schema::dropIfExists('barcode_scan_imports');
        Schema::dropIfExists('barcode_actions');
    }
};

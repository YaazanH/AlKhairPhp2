<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_report_templates', function (Blueprint $table): void {
            $table->text('header_text')->nullable()->after('subtitle');
            $table->text('footer_text')->nullable()->after('header_text');
            $table->text('custom_text')->nullable()->after('footer_text');
            $table->string('date_mode', 20)->default('exported_at')->after('custom_text');
            $table->date('custom_date')->nullable()->after('date_mode');
            $table->boolean('show_issuer_name')->default(true)->after('custom_date');
            $table->boolean('show_page_numbers')->default(false)->after('show_issuer_name');
            $table->string('background_image')->nullable()->after('show_page_numbers');
            $table->string('logo_image')->nullable()->after('background_image');
            $table->string('shape_type', 20)->nullable()->after('logo_image');
            $table->string('shape_color', 20)->nullable()->after('shape_type');
            $table->decimal('shape_opacity', 4, 2)->nullable()->after('shape_color');
        });

        Schema::create('finance_generated_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_type', 40)->default('ledger');
            $table->json('filters');
            $table->json('report_data');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_generated_reports');

        Schema::table('finance_report_templates', function (Blueprint $table): void {
            $table->dropColumn([
                'header_text',
                'footer_text',
                'custom_text',
                'date_mode',
                'custom_date',
                'show_issuer_name',
                'show_page_numbers',
                'background_image',
                'logo_image',
                'shape_type',
                'shape_color',
                'shape_opacity',
            ]);
        });
    }
};

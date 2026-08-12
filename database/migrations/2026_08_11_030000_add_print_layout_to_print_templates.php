<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_templates', function (Blueprint $table) {
            $table->string('paper_size', 20)->default('a4')->after('height_mm');
            $table->string('orientation', 20)->default('portrait')->after('paper_size');
            $table->decimal('margin_top_mm', 6, 2)->default(10)->after('orientation');
            $table->decimal('margin_right_mm', 6, 2)->default(10)->after('margin_top_mm');
            $table->decimal('margin_bottom_mm', 6, 2)->default(10)->after('margin_right_mm');
            $table->decimal('margin_left_mm', 6, 2)->default(10)->after('margin_bottom_mm');
            $table->decimal('gap_x_mm', 6, 2)->default(6)->after('margin_left_mm');
            $table->decimal('gap_y_mm', 6, 2)->default(6)->after('gap_x_mm');
            $table->boolean('rounded_corners')->default(false)->after('gap_y_mm');
        });
    }

    public function down(): void
    {
        Schema::table('print_templates', function (Blueprint $table) {
            $table->dropColumn(['paper_size', 'orientation', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'gap_x_mm', 'gap_y_mm', 'rounded_corners']);
        });
    }
};
